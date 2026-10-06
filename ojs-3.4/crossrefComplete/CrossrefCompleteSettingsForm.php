<?php

/**
 * @file plugins/generic/crossrefComplete/CrossrefCompleteSettingsForm.php
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefCompleteSettingsForm
 *
 * @brief The journal's choices: send the reference list, send affiliations, and (only with Crossmark turned on at
 *  Crossref) the Crossmark policy DOI — and, for a journal whose records reach Crossref another way, whether the
 *  Crossmark button follows Crossref's own record of each article (1.0.0.6).
 */

namespace APP\plugins\generic\crossrefComplete;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class CrossrefCompleteSettingsForm extends Form
{
    public int $contextId;

    public CrossrefCompletePlugin $plugin;

    public function __construct(CrossrefCompletePlugin $plugin, int $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorCustom($this, 'crossmarkPolicy', 'optional', 'plugins.generic.crossrefComplete.settings.crossmarkPolicy.invalid', fn ($v) => \CcrCompleter::bareDoi($v) !== ''));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $o = $this->plugin->options($this->contextId);
        $this->_data = [
            'references' => $o['references'],
            'affiliations' => $o['affiliations'],
            'crossmarkPolicy' => $o['crossmarkPolicy'],
            'schemaCheck' => $this->plugin->schemaCheckOn($this->contextId),
            'crossmarkByRecord' => $this->plugin->crossmarkByRecordOn($this->contextId),
        ];
    }

    public function readInputData()
    {
        $this->readUserVars(['references', 'affiliations', 'crossmarkPolicy', 'schemaCheck', 'crossmarkByRecord']);
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, 'references', (bool) $this->getData('references'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'affiliations', (bool) $this->getData('affiliations'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'schemaCheck', (bool) $this->getData('schemaCheck'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'crossmarkPolicy', \CcrCompleter::bareDoi((string) $this->getData('crossmarkPolicy')), 'string');
        $this->plugin->updateSetting($this->contextId, 'crossmarkByRecord', (bool) $this->getData('crossmarkByRecord'), 'bool');
        parent::execute(...$functionArgs);
    }
}
