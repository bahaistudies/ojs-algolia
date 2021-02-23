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

// The max. number of Submissions that can
// be indexed in a single batch.
define('ALGOLIA_INDEXING_MAX_BATCHSIZE', 2000);

// Number of words to split
define('ALGOLIA_WORDCOUNT_SPLIT', 250);

import('classes.search.SubmissionSearch');
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
     * Mark a single Submission "changed" so that the indexing
     * back-end will update it during the next batch update.
     * @param $submissionId Integer
     */
    function markSubmissionChanged($submissionId, $journalId = null) {
        if(!is_numeric($submissionId)) {
            assert(false);
            return;
        }

        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
        $submissionDao->updateSetting(
            $submissionId, 'algoliaIndexingState', ALGOLIA_INDEXINGSTATE_DIRTY, 'bool'
        );
    }

    /**
     * Mark the given journal for re-indexing.
     * @param $journalId integer The ID of the journal to be (re-)indexed.
     * @return integer The number of Submissions that have been marked.
     */
    function markJournalChanged($journalId) {
        if (!is_numeric($journalId)) {
            assert(false);
            return;
        }

        // Retrieve all Submissions of the journal.
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
        $Submissions = $submissionDao->getByContextId($journalId);

        $publishedSubmissionDao = DAORegistry::getDAO('PublishedSubmissionDAO'); /* @var $publishedSubmissionDao PublishedSubmissionDAO */

        // Run through the Submissions and mark them "changed".
        while($Submission = $Submissions->next()) {
            $publishedSubmission = $publishedSubmissionDao->getBySubmissionId($Submission->getId());
            if (is_a($publishedSubmission, 'PublishedSubmission')) {
                if($Submission->getStatusKey() == "submission.status.published"){
                    $this->markSubmissionChanged($publishedSubmission->getId(), $journalId);
                }
            }
        }
    }

    /**
     * (Re-)indexes all changed Submissions in Algolia.
     *
     * This is 'pushing' the content to Algolia.
     *
     * To control memory usage and response time we
     * index Submissions in batches. Batches should be as
     * large as possible to reduce index commit overhead.
     *
     * @param $batchSize integer The maximum number of Submissions
     *  to be indexed in this run.
     * @param $journalId integer If given, restrains index
     *  updates to the given journal.
     */
    function pushChangedSubmissions($batchSize = ALGOLIA_INDEXING_MAX_BATCHSIZE, $journalId = null) {
        // Retrieve a batch of "changed" Submissions.
        import('lib.pkp.classes.db.DBResultRange');
        $range = new DBResultRange($batchSize);
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
        $changedSubmissionsIterator = $submissionDao->getBySetting(
            'algoliaIndexingState', ALGOLIA_INDEXINGSTATE_DIRTY, $journalId, $range
        );
        unset($range);

        // Retrieve Submissions and overall count from the result set.
        $changedSubmissions = $changedSubmissionsIterator->toArray();
        $batchCount = count($changedSubmissions);
        $totalCount = $changedSubmissionsIterator->getCount();
        unset($changedSubmissionsIterator);

        $toDelete = array();
        $toAdd = array();

        foreach($changedSubmissions as $indexedSubmission) {
            $indexedSubmission->setData('algoliaIndexingState', ALGOLIA_INDEXINGSTATE_CLEAN);
            $submissionDao->updateLocaleFields($indexedSubmission);
            
            $toDelete[] = $this->buildAlgoliaObjectDelete($indexedSubmission);
            $toAdd[] = $this->buildAlgoliaObjectAdd($indexedSubmission);
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
     * Deletes the given Submission from Algolia.
     *
     * @param $submissionId integer The ID of the Submission to be deleted.
     *
     * @return boolean true if successful, otherwise false.
     */
    function deleteSubmissionFromIndex($submissionId) {
        if(!is_numeric($submissionId)) {
            assert(false);
            return;
        }

        $toDelete = array();
        $toDelete[] = $this->buildAlgoliaObjectDelete($submissionId);
        foreach($toDelete as $delete){
            $this->indexer->deleteByDistinctId($delete['distinctId']);
        }
    }

    /**
     * Deletes all Submissions of a journal or of the
     * installation from Algolia.
     *
     * @param $journalId integer If given, only Submissions
     *  from this journal will be deleted.
     * @return boolean true if successful, otherwise false.
     */
    function deleteSubmissionsFromIndex($journalId = null) {
        // Delete only Submissions from one journal if a
        // journal ID is given.
        $journalQuery = '';
        if (is_numeric($journalId)) {
            $journalQuery = ' AND journal_id:' . $this->_instId . '-' . $journalId;
        }

        // Delete all Submissions of the installation (or journal).
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
     * Check whether access to the given Submission
     * is authorized to the requesting party (i.e. the
     * Solr server).
     *
     * @param $Submission Submission
     * @return boolean True if authorized, otherwise false.
     */
    function _isSubmissionAccessAuthorized($Submission) {
        // Did we get a published Submission?
        if (!is_a($Submission, 'PublishedSubmission')) return false;

        // Get the Submission's journal.
        $journal = $this->_getJournal($Submission->getJournalId());
        if (!is_a($journal, 'Journal')) return false;

        // Get the Submission's issue.
        $issue = $this->_getIssue($Submission->getIssueId(), $journal->getId());
        if (!is_a($issue, 'Issue')) return false;

        // Only index published Submissions.
        if (!$issue->getPublished() || $Submission->getStatus() != STATUS_PUBLISHED) return false;

        // Make sure the requesting party is authorized to access the Submission/issue.
        import('classes.issue.IssueAction');
        $issueAction = new IssueAction();
        $subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
        if ($subscriptionRequired) {
            $isSubscribedDomain = $issueAction->subscribedDomain(Application::getRequest(), $journal, $issue->getId(), $Submission->getId());
            if (!$isSubscribedDomain) return false;
        }

        // All checks passed successfully - allow access.
        return true;
    }

    function buildAlgoliaObjectAdd($Submission){
        // mark the Submission as "clean"
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
        $submissionDao->updateSetting(
            $Submission->getId(), 'algoliaIndexingState', ALGOLIA_INDEXINGSTATE_CLEAN, 'bool'
        );

        $baseData = array(
            "objectAction" => "addObject",
            "distinctId" => $Submission->getId(),
        );

        $objects = array();

        $SubmissionData = $this->mapAlgoliaFieldsToIndex($Submission);
        foreach($SubmissionData['body'] as $i => $chunks){
            if(trim($chunks)){
                $baseData['objectID'] = $baseData['distinctId'] . "_" . $i;
                $chunkedData = $SubmissionData;
                $chunkedData['body'] = $chunks;
                $chunkedData['order'] = $i + 1;
                $objects[] = array_merge($baseData, $chunkedData);
            }
        }

        return $objects;
    }

    function buildAlgoliaObjectDelete($SubmissionOrSubmissionId){
        if(!is_numeric($SubmissionOrSubmissionId)) {
            return array(
                "objectAction" => "deleteObject",
                "distinctId" => $SubmissionOrSubmissionId->getId(),
            );
        }

        return array(
            "objectAction" => "deleteObject",
            "distinctId" => $SubmissionOrSubmissionId,
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

    function mapAlgoliaFieldsToIndex($Submission){
        $mappedFields = array();

        $fieldsToIndex = $this->getAlgoliaFieldsToIndex();
        foreach($fieldsToIndex as $field){
            switch($field){
                case "title":
                    $mappedFields[$field] = $this->formatTitle($Submission);
                    break;

                case "abstract":
                    $mappedFields[$field] = $this->formatAbstract($Submission);
                    break;

                case "discipline":
                    $mappedFields[$field] = (array) $Submission->getDiscipline(null);
                    break;

                case "subject":
                    $mappedFields[$field] = (array) $Submission->getSubject(null);
                    break;

                case "type":
                    $mappedFields[$field] = $Submission->getType(null);
                    break;

                case "coverage":
                    $mappedFields[$field] = (array) $Submission->getCoverage(null);
                    break;

                case "galleyFullText":
                    $mappedFields[$field] = $this->getGalleyHTML($Submission);
                    break;

                case "authors":
                    $mappedFields[$field] = $this->getAuthors($Submission);
                    break;

                case "publicationDate":
                    $mappedFields[$field] = strtotime($Submission->getDatePublished());
                    break;
            }
        }

        $mappedFields['section'] = $Submission->getSectionTitle();
        $mappedFields['url'] = $this->formatUrl($Submission);

        // combine abstract and galleyFullText into body and unset them
        $mappedFields['body'] = array_merge($mappedFields['abstract'], $mappedFields['galleyFullText']);
        unset($mappedFields['abstract']);
        unset($mappedFields['galleyFullText']);

        return $mappedFields;
    }

    function formatPublicationDate($Submission, $custom = false){
        if(!$custom){
            return $Submission->getDatePublished();
        }else{
            // for example:
            $publishedDate = date_create($Submission->getDatePublished());
            return date_format($publishedDate, "F Y");
        }
    }

    function formatUrl($Submission, $custom = false){
        $baseUrl = Config::getVar('general', 'base_url');

        if(!preg_match("#/index\.php#", $baseUrl)){
            $baseUrl .= "/index.php";
        }

        $publishedSubmissionDao = DAORegistry::getDAO('PublishedSubmissionDAO');
        $publishedSubmission = $publishedSubmissionDao->getBySubmissionId($Submission->getId());
        $sequence = $publishedSubmission->getSequence();

        $issueDao = DAORegistry::getDAO('IssueDAO');
        $issue = $issueDao->getById($publishedSubmission->getIssueId());
        $volume = $issue->getData("volume");
        $number = $issue->getData("number");

        $journalDao = DAORegistry::getDAO('JournalDAO');
        $journal = $journalDao->getById($Submission->getJournalId());
        $acronym = $journal->getLocalizedAcronym();

        if(!$custom){
            return $baseUrl . "/" . strtolower($acronym) . "/Submission/view/" . $Submission->getId();
        }else{
            // as an example...format your custom url how you'd like
            return $baseUrl . "/" . strtolower($acronym) . "/Submission/view/" . $acronym . $volume . "." . $number . "." . str_pad($number, 2, "0", STR_PAD_LEFT);
        }
    }

    function getAuthors($Submission){
        $authorText = array();
        $authors = $Submission->getAuthors();
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

    function formatAbstract($Submission){
        return $this->chunkContent($Submission->getAbstract($Submission->getLocale()));
    }

    function getGalleyHTML($Submission){
        $publishedSubmissionDao = DAORegistry::getDAO('PublishedSubmissionDAO');
        $publishedSubmission = $publishedSubmissionDao->getBySubmissionId($Submission->getId());

        $contents = "";

        $galleys = $publishedSubmission->getGalleys();
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

    function formatTitle($Submission){
        $title = $Submission->getTitle(null);

        return preg_replace("/<.*?>/", "", $title);
    }
}
