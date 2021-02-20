<?php

/**
 * @file plugins/generic/algolia/classes/AlgoliaService.inc.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlgoliaService
 * @ingroup plugins_generic_algolia_classes
 *
 * @brief Indexes content into Algolia
 *
 * This class relies on Composer, the PHP curl and mbstring extensions. Please 
 * install Composer and activate the extension before trying to index content into Algolia
 */

// Flags used for index maintenance.
define('ALGOLIA_INDEXINGSTATE_DIRTY', true);
define('ALGOLIA_INDEXINGSTATE_CLEAN', false);

// The max. number of articles that can
// be indexed in a single batch.
define('ALGOLIA_INDEXING_MAX_BATCHSIZE', 2000);

// Number of words to split
define('ALGOLIA_WORDCOUNT_SPLIT', 250);

import('classes.search.ArticleSearch');
import('plugins.generic.algolia.classes.AlgoliaEngine');
import('lib.pkp.classes.config.Config');

class AlgoliaService {
    var $indexer = null;

    /**
     * [__construct description]
     * 
     * @param boolean $settingsArray [description]
     */
    function __construct($settingsArray = false) {
        if(!$settingsArray) {
            return false;
        }

        $this->indexer = new AlgoliaEngine($settingsArray);
    }

    //
    // Getters and Setters
    //
    /**
     * Retrieve a journal (possibly from the cache).
     * @param $journalId int
     * @return Journal
     */
    function _getJournal($journalId) {
        if (isset($this->_journalCache[$journalId])) {
            $journal = $this->_journalCache[$journalId];
        } else {
            $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
            $journal = $journalDao->getById($journalId);
            $this->_journalCache[$journalId] = $journal;
        }

        return $journal;
    }

    /**
     * Retrieve an issue (possibly from the cache).
     * @param $issueId int
     * @param $journalId int
     * @return Issue
     */
    function _getIssue($issueId, $journalId) {
        if (isset($this->_issueCache[$issueId])) {
            $issue = $this->_issueCache[$issueId];
        } else {
            $issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
            $issue = $issueDao->getById($issueId, $journalId, true);
            $this->_issueCache[$issueId] = $issue;
        }

        return $issue;
    }


    //
    // Public API
    //
    /**
     * Mark a single article "changed" so that the indexing
     * back-end will update it during the next batch update.
     * @param $articleId Integer
     */
    function markArticleChanged($articleId, $journalId = null) {
        if(!is_numeric($articleId)) {
            assert(false);
            return;
        }

        $articleDao = DAORegistry::getDAO('ArticleDAO'); /* @var $articleDao ArticleDAO */
        $articleDao->updateSetting(
            $articleId, 'algoliaIndexingState', ALGOLIA_INDEXINGSTATE_DIRTY, 'bool'
        );
    }

    /**
     * Mark the given journal for re-indexing.
     * @param $journalId integer The ID of the journal to be (re-)indexed.
     * @return integer The number of articles that have been marked.
     */
    function markJournalChanged($journalId) {
        if (!is_numeric($journalId)) {
            assert(false);
            return;
        }

        // Retrieve all articles of the journal.
        $articleDao = DAORegistry::getDAO('ArticleDAO'); /* @var $articleDao ArticleDAO */
        $articles = $articleDao->getByContextId($journalId);

        $publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO'); /* @var $publishedArticleDao PublishedArticleDAO */

        // Run through the articles and mark them "changed".
        while($article = $articles->next()) {
            $publishedArticle = $publishedArticleDao->getByArticleId($article->getId());
            if (is_a($publishedArticle, 'PublishedArticle')) {
                if($article->getStatusKey() == "submission.status.published"){
                    $this->markArticleChanged($publishedArticle->getId(), $journalId);
                }
            }
        }
    }

    /**
     * (Re-)indexes all changed articles in Algolia.
     *
     * This is 'pushing' the content to Algolia.
     *
     * To control memory usage and response time we
     * index articles in batches. Batches should be as
     * large as possible to reduce index commit overhead.
     *
     * @param $batchSize integer The maximum number of articles
     *  to be indexed in this run.
     * @param $journalId integer If given, restrains index
     *  updates to the given journal.
     */
    function pushChangedArticles($batchSize = ALGOLIA_INDEXING_MAX_BATCHSIZE, $journalId = null) {
        // Retrieve a batch of "changed" articles.
        import('lib.pkp.classes.db.DBResultRange');
        $range = new DBResultRange($batchSize);
        $articleDao = DAORegistry::getDAO('ArticleDAO'); /* @var $articleDao ArticleDAO */
        $changedArticlesIterator = $articleDao->getBySetting(
            'algoliaIndexingState', ALGOLIA_INDEXINGSTATE_DIRTY, $journalId, $range
        );
        unset($range);

        // Retrieve articles and overall count from the result set.
        $changedArticles = $changedArticlesIterator->toArray();
        $batchCount = count($changedArticles);
        $totalCount = $changedArticlesIterator->getCount();
        unset($changedArticlesIterator);

        $toDelete = array();
        $toAdd = array();

        foreach($changedArticles as $indexedArticle) {
            $indexedArticle->setData('algoliaIndexingState', ALGOLIA_INDEXINGSTATE_CLEAN);
            $articleDao->updateLocaleFields($indexedArticle);
            
            $toDelete[] = $this->buildAlgoliaObjectDelete($indexedArticle);
            $toAdd[] = $this->buildAlgoliaObjectAdd($indexedArticle);
        }

        if($journalId){
            unset($toDelete);
            $this->indexer->clear_index();
        }else{
            foreach($toDelete as $delete){
                $this->indexer->deleteByDistinctId($delete['distinctId']);
            }
        }

        foreach($toAdd as $add){
            $this->indexer->index($add);
        }
    }

    /**
     * Deletes the given article from Algolia.
     *
     * @param $articleId integer The ID of the article to be deleted.
     *
     * @return boolean true if successful, otherwise false.
     */
    function deleteArticleFromIndex($articleId) {
        if(!is_numeric($articleId)) {
            assert(false);
            return;
        }

        $toDelete = array();
        $toDelete[] = $this->buildAlgoliaObjectDelete($articleId);
        foreach($toDelete as $delete){
            $this->indexer->deleteByDistinctId($delete['distinctId']);
        }
    }

    /**
     * Deletes all articles of a journal or of the
     * installation from Algolia.
     *
     * @param $journalId integer If given, only articles
     *  from this journal will be deleted.
     * @return boolean true if successful, otherwise false.
     */
    function deleteArticlesFromIndex($journalId = null) {
        // Delete only articles from one journal if a
        // journal ID is given.
        $journalQuery = '';
        if (is_numeric($journalId)) {
            $journalQuery = ' AND journal_id:' . $this->_instId . '-' . $journalId;
        }

        // Delete all articles of the installation (or journal).
        $xml = '<query>inst_id:' . $this->_instId . $journalQuery . '</query>';
        return $this->_deleteFromIndex($xml);
    }

    /**
     * Returns an array with all (dynamic) fields in the index.
     *
     * NB: This is cached data so after an index update we may
     * have to flush the index to re-read the current index state.
     *
     * @param $fieldType string Either 'search' or 'sort'.
     * @return array
     */
    function getAvailableFields($fieldType) {
        $cache = $this->_getCache();
        $fieldCache = $cache->get($fieldType);
        return $fieldCache;
    }

    /**
     * Return a list of all text fields that may occur in the
     * index.
     * @param $fieldType string "search", "sort" or "all"
     *
     * @return array
     */
    function _getFieldNames() {
        return array(
            'localized' => array(
                'title', 'abstract', 'discipline', 'subject',
                'type', 'coverage',
            ),
            'multiformat' => array(
                'galleyFullText'
            ),
            'static' => array(
                'authors' => 'authors_txt',
                'publicationDate' => 'publicationDate_dt'
            )
        );
    }

    /**
     * Check whether access to the given article
     * is authorized to the requesting party (i.e. the
     * Solr server).
     *
     * @param $article Article
     * @return boolean True if authorized, otherwise false.
     */
    function _isArticleAccessAuthorized($article) {
        // Did we get a published article?
        if (!is_a($article, 'PublishedArticle')) return false;

        // Get the article's journal.
        $journal = $this->_getJournal($article->getJournalId());
        if (!is_a($journal, 'Journal')) return false;

        // Get the article's issue.
        $issue = $this->_getIssue($article->getIssueId(), $journal->getId());
        if (!is_a($issue, 'Issue')) return false;

        // Only index published articles.
        if (!$issue->getPublished() || $article->getStatus() != STATUS_PUBLISHED) return false;

        // Make sure the requesting party is authorized to access the article/issue.
        import('classes.issue.IssueAction');
        $issueAction = new IssueAction();
        $subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
        if ($subscriptionRequired) {
            $isSubscribedDomain = $issueAction->subscribedDomain(Application::getRequest(), $journal, $issue->getId(), $article->getId());
            if (!$isSubscribedDomain) return false;
        }

        // All checks passed successfully - allow access.
        return true;
    }

    function buildAlgoliaObjectAdd($article){
        // mark the article as "clean"
        $articleDao = DAORegistry::getDAO('ArticleDAO'); /* @var $articleDao ArticleDAO */
        $articleDao->updateSetting(
            $article->getId(), 'algoliaIndexingState', ALGOLIA_INDEXINGSTATE_CLEAN, 'bool'
        );

        $baseData = array(
            "objectAction" => "addObject",
            "distinctId" => $article->getId(),
        );

        $objects = array();

        $articleData = $this->mapAlgoliaFieldsToIndex($article);
        foreach($articleData['body'] as $i => $chunks){
            if(trim($chunks)){
                $baseData['objectID'] = $baseData['distinctId'] . "_" . $i;
                $chunkedData = $articleData;
                $chunkedData['body'] = $chunks;
                $chunkedData['order'] = $i + 1;
                $objects[] = array_merge($baseData, $chunkedData);
            }
        }

        return $objects;
    }

    function buildAlgoliaObjectDelete($articleOrArticleId){
        if(!is_numeric($articleOrArticleId)) {
            return array(
                "objectAction" => "deleteObject",
                "distinctId" => $articleOrArticleId->getId(),
            );
        }

        return array(
            "objectAction" => "deleteObject",
            "distinctId" => $articleOrArticleId,
        );
    }

    function getAlgoliaFieldsToIndex(){
        $fieldsToIndex = array();

        $fields = $this->_getFieldNames();
        foreach(array('localized', 'multiformat', 'static') as $fieldSubType) {
            if ($fieldSubType == 'static') {
                foreach($fields[$fieldSubType] as $fieldName => $dummy) {
                    $fieldsToIndex[] = $fieldName;
                }
            } else {
                foreach($fields[$fieldSubType] as $fieldName) {
                    $fieldsToIndex[] = $fieldName;
                }
            }
        }

        return $fieldsToIndex;
    }

    function mapAlgoliaFieldsToIndex($article){
        $mappedFields = array();

        $fieldsToIndex = $this->getAlgoliaFieldsToIndex();
        foreach($fieldsToIndex as $field){
            switch($field){
                case "title":
                    $mappedFields[$field] = $this->formatTitle($article);
                    break;

                case "abstract":
                    $mappedFields[$field] = $this->formatAbstract($article);
                    break;

                case "discipline":
                    $mappedFields[$field] = (array) $article->getDiscipline(null);
                    break;

                case "subject":
                    $mappedFields[$field] = (array) $article->getSubject(null);
                    break;

                case "type":
                    $mappedFields[$field] = $article->getType(null);
                    break;

                case "coverage":
                    $mappedFields[$field] = (array) $article->getCoverage(null);
                    break;

                case "galleyFullText":
                    $mappedFields[$field] = $this->getGalleyHTML($article);
                    break;

                case "authors":
                    $mappedFields[$field] = $this->getAuthors($article);
                    break;

                case "publicationDate":
                    $mappedFields[$field] = strtotime($article->getDatePublished());
                    break;
            }
        }

        $mappedFields['section'] = $article->getSectionTitle();
        $mappedFields['url'] = $this->formatUrl($article);

        // combine abstract and galleyFullText into body and unset them
        $mappedFields['body'] = array_merge($mappedFields['abstract'], $mappedFields['galleyFullText']);
        unset($mappedFields['abstract']);
        unset($mappedFields['galleyFullText']);

        return $mappedFields;
    }

    function formatPublicationDate($article, $custom = false){
        if(!$custom){
            return $article->getDatePublished();
        }else{
            // for example:
            $publishedDate = date_create($article->getDatePublished());
            return date_format($publishedDate, "F Y");
        }
    }

    function formatUrl($article, $custom = false){
        $baseUrl = Config::getVar('general', 'base_url');

        if(!preg_match("#/index\.php#", $baseUrl)){
            $baseUrl .= "/index.php";
        }

        $publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
        $publishedArticle = $publishedArticleDao->getByArticleId($article->getId());
        $sequence = $publishedArticle->getSequence();

        $issueDao = DAORegistry::getDAO('IssueDAO');
        $issue = $issueDao->getById($publishedArticle->getIssueId());
        $volume = $issue->getData("volume");
        $number = $issue->getData("number");

        $journalDao = DAORegistry::getDAO('JournalDAO');
        $journal = $journalDao->getById($article->getJournalId());
        $acronym = $journal->getLocalizedAcronym();

        if(!$custom){
            return $baseUrl . "/" . strtolower($acronym) . "/article/view/" . $article->getId();
        }else{
            // as an example...format your custom url how you'd like
            return $baseUrl . "/" . strtolower($acronym) . "/article/view/" . $acronym . $volume . "." . $number . "." . str_pad($number, 2, "0", STR_PAD_LEFT);
        }
    }

    function getAuthors($article){
        $authorText = array();
        $authors = $article->getAuthors();
        $authorCount = count($authors);
        for ($i = 0, $count = $authorCount; $i < $count; $i++) {
            //
            // do we need all this? aff and bio?
            //
            // $affiliations = $author->getAffiliation(null);
            // if (is_array($affiliations)) foreach ($affiliations as $affiliation) { // Localized
            //     array_push($authorText, $affiliation);
            // }
            // $bios = $author->getBiography(null);
            // if (is_array($bios)) foreach ($bios as $bio) { // Localized
            //     array_push($authorText, strip_tags($bio));
            // }

            $authorName = "";

            $author = $authors[$i];

            $authorName .= $author->getFirstName();

            if($author->getMiddleName()){
                $authorName .= " " . $author->getMiddleName();
            }

            $authorName .= " " . $author->getLastName();

            $authorText[] = $authorName;
        }

        return implode(", ", $authorText);
    }

    function formatAbstract($article){
        return $this->chunkContent($article->getAbstract($article->getLocale()));
    }

    function getGalleyHTML($article){
        $publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
        $publishedArticle = $publishedArticleDao->getByArticleId($article->getId());

        $contents = "";

        $galleys = $publishedArticle->getGalleys();
        foreach($galleys as $galley){
            if($galley->getFileType() == "text/html"){
                $submissionFile = $galley->getFile();
                $contents .= file_get_contents($submissionFile->getFilePath());
            }
        }

        return $this->chunkContent($contents);
    }

    function chunkContent($content){
        $data = array();
        $updated_content = html_entity_decode($content);

        if($updated_content){
            $temp_content = str_replace("</p>", "", $updated_content);
            $chunked_content = preg_split("/<p[^>]*?(\/?)>/i", $temp_content);

            foreach($chunked_content as $chunked){
                if($chunked){
                    $tagless_content = strip_tags($chunked);
                    $data[] = trim(wordwrap($tagless_content, ALGOLIA_WORDCOUNT_SPLIT));
                }
            }
        }else{
            $data[] = trim(strip_tags($updated_content));
        }

        return $data;
    }

    function formatTitle($article){
        $title = $article->getTitle(null);

        return preg_replace("/<.*?>/", "", $title);
    }
}
