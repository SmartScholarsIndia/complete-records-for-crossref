<?php

/**
 * @file plugins/generic/crossrefComplete/CrossrefCompleteSettingsForm.inc.php
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefCompleteSettingsForm
 * @brief The journal's choices: send the reference list, send affiliations, and (only with Crossmark turned on at
 *  Crossref) the Crossmark policy DOI — and, for a journal whose records reach Crossref another way, whether the
 *  Crossmark button follows Crossref's own record of each article (1.0.0.6).
 */

import('lib.pkp.classes.form.Form');

class CrossrefCompleteSettingsForm extends Form {

	/** @var int */
	var $_contextId;

	/** @var CrossrefCompletePlugin */
	var $_plugin;

	function __construct($plugin, $contextId) {
		$this->_contextId = $contextId;
		$this->_plugin = $plugin;
		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
		$this->addCheck(new FormValidatorCustom($this, 'crossmarkPolicy', 'optional', 'plugins.generic.crossrefComplete.settings.crossmarkPolicy.invalid', function ($v) {
			return CcrCompleter::bareDoi($v) !== '';
		}));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	function initData() {
		$o = $this->_plugin->options($this->_contextId);
		$this->_data = array(
			'references' => $o['references'],
			'affiliations' => $o['affiliations'],
			'crossmarkPolicy' => $o['crossmarkPolicy'],
			'schemaCheck' => $this->_plugin->schemaCheckOn($this->_contextId),
			'crossmarkByRecord' => $this->_plugin->crossmarkByRecordOn($this->_contextId),
		);
	}

	function readInputData() {
		$this->readUserVars(array('references', 'affiliations', 'crossmarkPolicy', 'schemaCheck', 'crossmarkByRecord'));
	}

	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	function execute(...$functionArgs) {
		$this->_plugin->updateSetting($this->_contextId, 'references', (bool) $this->getData('references'), 'bool');
		$this->_plugin->updateSetting($this->_contextId, 'affiliations', (bool) $this->getData('affiliations'), 'bool');
		$this->_plugin->updateSetting($this->_contextId, 'schemaCheck', (bool) $this->getData('schemaCheck'), 'bool');
		$this->_plugin->updateSetting($this->_contextId, 'crossmarkPolicy', CcrCompleter::bareDoi((string) $this->getData('crossmarkPolicy')), 'string');
		$this->_plugin->updateSetting($this->_contextId, 'crossmarkByRecord', (bool) $this->getData('crossmarkByRecord'), 'bool');
		parent::execute(...$functionArgs);
	}
}
