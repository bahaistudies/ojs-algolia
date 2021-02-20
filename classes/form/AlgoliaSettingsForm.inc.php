<?php

/**
 * @file plugins/generic/algolia/classes/form/AlgoliaSettingsForm.inc.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlgoliaSettingsForm
 * @ingroup plugins_generic_algolia_classes_form
 *
 * @brief Form to configure the Algolia index.
 */

import('lib.pkp.classes.form.Form');

class AlgoliaSettingsForm extends Form {

	/** @var AlgoliaPlugin */
	var $_plugin;

	/**
	 * Constructor
	 * @param $plugin AlgoliaPlugin
	 */
	function __construct($plugin) {
        $this->_plugin = $plugin;
        
        parent::__construct($plugin->getTemplatePath() . 'settingsForm.tpl');

        // Server configuration.
        $this->addCheck(new FormValidator($this, 'adminKey', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.algolia.settings.adminKeyRequired'));
        $this->addCheck(new FormValidator($this, 'searchOnlyKey', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.algolia.settings.searchOnlyKeyRequired'));
        $this->addCheck(new FormValidator($this, 'appId', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.algolia.settings.appIdRequired'));
        $this->addCheck(new FormValidator($this, 'index', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.algolia.settings.indexRequired'));

        $journalsToReindex = array_keys($this->_getJournalsToReindex());
        $this->addCheck(new FormValidatorInSet($this, 'journalToReindex', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.algolia.settings.internalError', $journalsToReindex));
    }


    //
    // Implement template methods from Form.
    //
    /**
     * @see Form::initData()
     */
    function initData() {
        $plugin = $this->_plugin;
        foreach ($this->_getFormFields() as $fieldName) {
            $this->setData($fieldName, $plugin->getSetting(CONTEXT_SITE, $fieldName));
        }
    }

    /**
     * @see Form::readInputData()
     */
    function readInputData() {
        // Read regular form data.
        $this->readUserVars($this->_getFormFields());
        $request = Application::getRequest();
    }

    /**
     * @see Form::fetch()
     */
    function fetch($request, $template = null, $display = false) {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign(array(
            'pluginName' => $this->_plugin->getName(),
            'journalsToReindex' => $this->_getJournalsToReindex(),
        ));
        return parent::fetch($request, $template, $display);
    }

    /**
     * Execute the form.
     */
    function execute() {
        $plugin = $this->_plugin;
        $formFields = $this->_getFormFields();
        foreach($formFields as $formField) {
            $plugin->updateSetting(CONTEXT_SITE, $formField, $this->getData($formField), 'string');
        }
    }


    //
    // Private helper methods
    //
    /**
     * Return the field names of this form.
     * @param $booleanOnly boolean Return only binary
     *  switches.
     * @return array
     */
    function _getFormFields($booleanOnly = false) {
        return array(
            "appId",
            "searchOnlyKey",
            "adminKey",
            "index",
        );
    }

    /**
     * Return a list of journals that can be re-indexed
     * with a default option "all journals".
     * @return array An associative array of journal IDs and names.
     */
    function _getJournalsToReindex() {
        static $journalsToReindex;

        if (is_null($journalsToReindex)) {
            $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
            $journalsToReindex = array(
                '' => __('plugins.generic.algolia.settings.indexRebuildAllJournals')
            );
            foreach($journalDao->getTitles(true) as $journalId => $journalName) {
                $journalsToReindex[$journalId] = __('plugins.generic.algolia.settings.indexRebuildJournal', array('journalName' => $journalName));
            }
        }

        return $journalsToReindex;
    }
}