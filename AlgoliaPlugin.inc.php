<?php

/**
 * @file plugins/generic/algolia/AlgoliaPlugin.inc.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlgoliaPlugin
 * @ingroup plugins_generic_algolia
 *
 * @brief Algolia plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('plugins.generic.algolia.classes.AlgoliaService');

class AlgoliaPlugin extends GenericPlugin {
    var $_algoliaService;

    //
    // Getters and Setters
    //
    /**
     * Get the Algolia service.
     * @return AlgoliaService
     */
    function getAlgoliaService() {
        return $this->_algoliaService;
    }

    //
    // Implement template methods from Plugin.
    //
    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null) {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;

        if ($success && $this->getEnabled($mainContextId)) {
            $this->_registerTemplateResource();

            // Register callbacks (controller-level).
            HookRegistry::register('ArticleSearchIndex::articleMetadataChanged', array($this, 'callbackArticleMetadataChanged'));
            HookRegistry::register('ArticleSearchIndex::articleDeleted', array($this, 'callbackArticleDeleted'));
            HookRegistry::register('ArticleSearchIndex::articleChangesFinished', array($this, 'callbackArticleChangesFinished'));
            HookRegistry::register('ArticleSearchIndex::submissionFileDeleted', array($this, 'callbackSubmissionFileDeleted'));

            HookRegistry::register('ArticleSearchIndex::rebuildIndex', array($this, 'callbackRebuildIndex'));

            // for the front end
            $searchOnlyKey = $this->getSetting(CONTEXT_SITE, 'searchOnlyKey');

            // call the algolia service
            $this->_algoliaService = new AlgoliaService(
                array(
                    "api_key" => $this->getSetting(CONTEXT_SITE, 'adminKey'),
                    "app_id" => $this->getSetting(CONTEXT_SITE, 'appId'),
                    "index" => $this->getSetting(CONTEXT_SITE, 'index'),
                )
            );
        }

        return $success;
    }

    /**
     * @see Plugin::getDisplayName()
     */
    function getDisplayName() {
        return __('plugins.generic.algolia.displayName');
    }

    /**
     * @see Plugin::getDescription()
     */
    function getDescription() {
        return __('plugins.generic.algolia.description');
    }

    /**
     * @see Plugin::getInstallSitePluginSettingsFile()
     */
    function getInstallSitePluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @see Plugin::isSitePlugin()
     */
    function isSitePlugin() {
        return true;
    }

    //
    // Implement template methods from GenericPlugin.
    //
    /**
     * @copydoc Plugin::getActions()
     */
    function getActions($request, $actionArgs) {
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        return array_merge(
            $this->getEnabled()?array(
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, array_merge($actionArgs, array('verb' => 'settings'))),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ):array(),
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                // Instantiate the settings form.
                $this->import('classes.form.AlgoliaSettingsForm');
                $form = new AlgoliaSettingsForm($this);

                // Handle request to save configuration data.
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();

                        // Index rebuild.
                        if ($request->getUserVar('rebuildIndex')) {
                            // Check whether we got valid index rebuild options.
                            if ($form->validate()) {
                                // Check whether a journal was selected.
                                $journal = null;
                                $journalId = $request->getUserVar('journalToReindex');
                                if (!empty($journalId)) {
                                    $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
                                    $journal = $journalDao->getById($journalId);
                                    if (!is_a($journal, 'Journal')) $journal = null;
                                }
                                if (empty($journalId) || (!empty($journalId) && is_a($journal, 'Journal'))) {
                                    // Rebuild index.
                                    $messages = null;
                                    $this->_rebuildIndex(false, $journal, true, $messages);

                                    // Transfer indexing output to the form template.
                                    $form->setData('rebuildIndexMessages', $messages);
                                }
                            }
                        }

                        return new JSONMessage(true);
                    }
                } else {
                    // Re-init data. It should be visible to users
                    // that whatever data they may have entered into
                    // the form was not saved.
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    //
    // Data-access level hook implementations.
    //
    /**
     * @see ArticleSearchIndex::articleMetadataChanged()
     *
     * Published articles where the metadata is changed will fire this hook.
     * We need to immediately call pushChangedArticles because the change are 
     * already live.
     */
    function callbackArticleMetadataChanged($hookName, $params) {
        assert($hookName == 'ArticleSearchIndex::articleMetadataChanged');
        list($submission) = $params; /* @var $submission Article */

        $publishedArticleDao =  $publishedSubmissions = Services::get('submission')
                                ->getMany(['status' => STATUS_PUBLISHED]); /* @var $publishedArticleDao PublishedArticleDAO */
        $publishedArticle =     if ($submission->getData('status') === STATUS_PUBLISHED) {
                                // submission is published
                                }
        if (is_a($publishedArticle, 'PublishedArticle')) {
            $this->_algoliaService->markArticleChanged($submission->getId());
            $this->_algoliaService->pushChangedArticles(5);
        }

        return true;
    }

    /**
     * @see ArticleSearchIndex::articleDeleted()
     */
    function callbackArticleDeleted($hookName, $params) {
        assert($hookName == 'ArticleSearchIndex::articleDeleted');
        list($articleId) = $params;
        // Deleting an submission$submission must always be done synchronously
        // (even in pull-mode) as we'll no longer have an object
        // to keep our change information.
        $this->_algoliaService->deleteArticleFromIndex($articleId);
        return true;
    }

    /**
     * @see ArticleSearchIndex::articleChangesFinished()
     *
     * This fires after an submission$submission has been published
     */
    function callbackArticleChangesFinished($hookName, $params) {
        // In the case of pull-indexing we ignore this call
        // and let the Solr server initiate indexing.
        // if ($this->getSetting(CONTEXT_SITE, 'pullIndexing')) return true;

        // If the plugin is configured to push changes to the
        // server then we'll now batch-update all articles that
        // changed since the last update. We use a batch size of 5
        // for online index updates to limit the time a request may be
        // locked in case a race condition with a large index update
        // occurs.
        $algoliaService = $this->getAlgoliaService();
        $result = $algoliaService->pushChangedArticles(5);

        return true;
    }

    /**
     * @see ArticleSearchIndex::rebuildIndex()
     */
    function callbackRebuildIndex($hookName, $params) {
        assert($hookName == 'ArticleSearchIndex::rebuildIndex');

        // Unpack the parameters.
        list($log, $journal, $switches) = $params;

        // Check switches.
        $rebuildIndex = true;

        if (is_array($switches)) {
            if (in_array('-n', $switches)) {
                $rebuildIndex = false;
            }
        }

        // Rebuild the index.
        $messages = null;
        $this->_rebuildIndex($log, $journal, $rebuildIndex, $messages);
        return true;
    }

    /**
     * @see ArticleSearchIndex::submissionFileDeleted()
     */
    function callbackSubmissionFileDeleted($hookName, $params) {
        assert($hookName == 'ArticleSearchIndex::submissionFileDeleted');
        list($articleId, $type, $assocId) = $params;
        $this->_algoliaService->deleteArticleFromIndex($articleId);
        return true;
    }

    //
    // Private helper methods
    //
    /**
     * Rebuild the index for all journals or a single journal
     * @param $log boolean Whether to write the log to standard output.
     * @param $journal Journal If given, only re-index this journal.
     * @param $buildIndex boolean Whether to rebuild the journal index.
     * @param $messages string Return parameter for log message output.
     * @return boolean True on success, otherwise false.
     */
    function _rebuildIndex($log, $journal, $buildIndex, &$messages) {
        // Rebuilding the index can take a long time.
        @set_time_limit(0);
        $algoliaService = $this->getAlgoliaService();

        if ($buildIndex) {
            // If we got a journal instance then only re-index
            // articles from that journal.
            $journalIdOrNull = (is_a($journal, 'Journal') ? $journal->getId() : null);

            // Clear index (if the journal id is null then
            // all journals will be deleted from the index).
            $this->_indexingMessage($log, 'AlgoliaPlugin: ' . __('search.cli.rebuildIndex.clearingIndex') . ' ... ', $messages);

            $this->_indexingMessage($log, __('search.cli.rebuildIndex.done') . PHP_EOL, $messages);

            // Re-build index, either of a single journal...
            if (is_a($journal, 'Journal')) {
                $journals = array($journal);
                unset($journal);
            // ...or for all journals.
            } else {
                $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
                $journalIterator = $journalDao->getAll();
                $journals = $journalIterator->toArray();
            }

            // We re-index journal by journal to partition the task a bit
            // and provide better progress information to the user.
            foreach($journals as $journal) {
                $this->_indexingMessage($log, 'AlgoliaPlugin: ' . __('search.cli.rebuildIndex.indexing', array('journalName' => $journal->getLocalizedName())) . ' ', $messages);

                // Mark all articles in the journal for re-indexing.
                $numMarked = $this->_algoliaService->markJournalChanged($journal->getId());
                $algoliaService->pushChangedArticles(ALGOLIA_INDEXING_MAX_BATCHSIZE, $journal->getId());

                $this->_indexingMessage($log, ' ' . __('search.cli.rebuildIndex.result', array('numIndexed' => $numIndexed)) . PHP_EOL, $messages);
            }
        }

        $this->_indexingMessage($log, __('search.cli.rebuildIndex.done') . PHP_EOL, $messages);

        return true;
    }

    /**
     * Output an indexing message.
     * @param $log boolean Whether to write the log to standard output.
     * @param $message string The message to display/add.
     * @param $messages string Return parameter for log message output.
     */
    function _indexingMessage($log, $message, &$messages) {
        if ($log) echo $message;
        $messages .= $message;
    }
}

