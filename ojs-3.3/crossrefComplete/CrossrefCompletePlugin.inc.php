<?php

/**
 * @file plugins/generic/crossrefComplete/CrossrefCompletePlugin.inc.php
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefCompletePlugin
 * @brief Complete Records for Crossref (OJS 3.3): completes every deposit OJS's own Crossref plugin makes with what the
 *  journal already holds in OJS — the reference list and each author's affiliation — and, when the journal has set its
 *  Crossmark policy DOI, Crossmark. After a deposit it reads the record back from Crossref and shows what landed.
 *
 *  It hooks into the deposit file the same way PKP's Funding plugin does (articlecrossrefxmlfilter::execute), runs after
 *  every other plugin, and never changes or removes what OJS wrote. When the completed file would break the order of
 *  Crossref's schema, OJS's own file is sent exactly as OJS made it.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
require_once(__DIR__ . '/core/CcrCompleter.php');
require_once(__DIR__ . '/core/CcrReadBack.php');
require_once(__DIR__ . '/core/CcrValidator.php');
require_once(__DIR__ . '/core/CcrCrossmark.php');

class CrossrefCompletePlugin extends GenericPlugin {

	/** Deposits remembered per journal for the read-back, newest first. */
	const MAX_DEPOSITS = 500;

	/** ...and never more than fits OJS 3.3's plugin_settings.setting_value (TEXT: 65,535 bytes), with room to spare. */
	const MAX_DEPOSITS_BYTES = 60000;

	/** Records read back from Crossref each time the status window opens. */
	const CHECKS_PER_OPEN = 10;

	/** At most this long spent fetching Crossref's schema files during one deposit. */
	const SCHEMA_FETCH_SECONDS = 20;

	/** @var int the budget in force (tests shorten it) */
	public $schemaFetchSeconds = self::SCHEMA_FETCH_SECONDS;

	/** @var int at most this long spent reading records back each time the status window opens (tests shorten it) */
	public $readBackSeconds = 25;

	/** @var array what the files completed in this request carried, by lower-case DOI, until the deposit is recorded */
	private $_sent = array();

	/** @var string Crossref's Crossmark button, waiting for the article page's output (set only on an article page that shows it) */
	private $_crossmarkButton = '';

	/** @var array the Crossmark policy DOI each file of this request carried, by lower-case DOI ('' = none), until the deposit */
	private $_crossmark = array();

	/**
	 * @copydoc Plugin::register()
	 *
	 * The hooks are added whenever the plugin is installed, not only when it is enabled for the current journal:
	 * OJS's scheduled Crossref deposits run without a journal in the request, and they must be completed too.
	 * Each hook then checks that the plugin is enabled for the journal the article belongs to.
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;
		if ($success) {
			HookRegistry::register('articlecrossrefxmlfilter::execute', array($this, 'completeDeposit'), HOOK_SEQUENCE_LAST);
			HookRegistry::register('crossrefexportplugin::deposited', array($this, 'noteDeposit'));
			HookRegistry::register('ArticleHandler::view', array($this, 'crossmarkOnArticlePage'));
		}
		return $success;
	}

	function getDisplayName() {
		return __('plugins.generic.crossrefComplete.displayName');
	}

	function getDescription() {
		return __('plugins.generic.crossrefComplete.description');
	}

	/**
	 * The plugin's choices for one journal.
	 * @param $contextId int
	 * @return array
	 */
	function options($contextId) {
		return array(
			// on unless the journal switched them off (a setting never saved reads as null)
			'references' => $this->getSetting($contextId, 'references') !== false,
			'affiliations' => $this->getSetting($contextId, 'affiliations') !== false,
			'crossmarkPolicy' => (string) $this->getSetting($contextId, 'crossmarkPolicy'),
			// '' = the host of each article's own landing page, read from the deposit file itself
			'crossmarkDomain' => '',
		);
	}

	/**
	 * The check against Crossref's schema before each deposit: on unless the journal switched it off.
	 * @param $contextId int
	 * @return bool
	 */
	function schemaCheckOn($contextId) {
		return $this->getSetting($contextId, 'schemaCheck') !== false;
	}

	/**
	 * Check a completed file against Crossref's published schema for its version (core/CcrValidator.php). The schema
	 * files are fetched once into OJS's files directory and read from there afterwards. Fetching has a budget of
	 * SCHEMA_FETCH_SECONDS per deposit, so a slow host can never hold OJS's request: what is not fetched in time makes
	 * this check "unchecked", and the next deposit carries on from the files already kept.
	 * @param $doc DOMDocument
	 * @return array
	 */
	function schemaCheck($doc) {
		$deadline = microtime(true) + $this->schemaFetchSeconds;
		return CcrValidator::check($doc, $this->schemaCacheDir(), function ($url) use ($deadline) {
			$left = $deadline - microtime(true);
			if ($left < 1) return null;
			try {
				$response = Application::get()->getHttpClient()->request('GET', $url, array('timeout' => min(10, $left), 'connect_timeout' => min(5, $left), 'http_errors' => false));
			} catch (Throwable $e) {
				return null;
			}
			return $response->getStatusCode() == 200 ? (string) $response->getBody() : null;
		});
	}

	/**
	 * Where Crossref's schema files are kept: inside OJS's files directory, which the web cannot reach.
	 * @return string
	 */
	function schemaCacheDir() {
		return rtrim((string) Config::getVar('files', 'files_dir'), '/') . '/crossrefComplete/schemas';
	}

	/**
	 * The last check, kept for the status window.
	 * @param $contextId int
	 * @param $check array
	 */
	function noteSchemaCheck($contextId, $check) {
		$this->updateSetting($contextId, 'lastSchemaCheck', json_encode(array(
			'at' => time(), 'state' => $check['state'], 'version' => $check['version'], 'why' => $check['why'],
			'errors' => array_slice($check['errors'], 0, 5), 'stoodIn' => array_keys(isset($check['stoodIn']) ? (array) $check['stoodIn'] : array()),
		)), 'string');
	}

	/**
	 * Whether OJS's Crossref plugin sends this journal's deposits to Crossref's test system ("Use the CrossRef test API",
	 * Tools → Import/Export → CrossRef XML Export Plugin → Settings). Deposits made then are not live, though OJS marks
	 * them Registered.
	 * @param $contextId int
	 * @return bool
	 */
	function crossrefTestMode($contextId) {
		$dao = DAORegistry::getDAO('PluginSettingsDAO'); /* @var $dao PluginSettingsDAO */
		return $dao ? (bool) $dao->getSetting($contextId, 'CrossRefExportPlugin', 'testMode') : false;
	}

	/**
	 * One sentence on the last check, for the status window.
	 * @param $contextId int
	 * @return string
	 */
	function schemaCheckLine($contextId) {
		if (!$this->schemaCheckOn($contextId)) return __('plugins.generic.crossrefComplete.status.schemaCheck.off');
		$c = json_decode((string) $this->getSetting($contextId, 'lastSchemaCheck'), true);
		if (!is_array($c) || !isset($c['state']) || !in_array($c['state'], array('valid', 'invalid', 'unchecked'), true)) {
			return __('plugins.generic.crossrefComplete.status.schemaCheck.none');
		}
		$line = __('plugins.generic.crossrefComplete.status.schemaCheck.' . $c['state'], array(
			'version' => (string) $c['version'], 'date' => date('Y-m-d H:i', (int) $c['at']),
			'why' => (string) $c['why'], 'errors' => implode('; ', (array) $c['errors']),
		));
		if (!empty($c['stoodIn']) && $c['state'] !== 'unchecked') {
			$line .= ' ' . __('plugins.generic.crossrefComplete.status.schemaCheck.standIn', array('files' => implode(', ', (array) $c['stoodIn'])));
		}
		return $line;
	}

	/**
	 * Hook articlecrossrefxmlfilter::execute — complete the deposit file OJS's Crossref plugin has just written.
	 * @param $hookName string
	 * @param $params array [DOMDocument &$preliminaryOutput]
	 * @return bool false, so every other plugin still runs
	 */
	function completeDeposit($hookName, $params) {
		$doc = $params[0];
		if (!($doc instanceof DOMDocument)) return false;
		$contextId = $this->contextIdForFile($doc);
		if (!$contextId || !$this->getEnabled($contextId)) return false;
		$plugin = $this;
		list($result, $report) = CcrCompleter::complete($doc, function ($doi) use ($plugin, $contextId) {
			return $plugin->articleData($doi, $contextId);
		}, $this->options($contextId));
		if ($report['problems']) {
			error_log('Complete Records for Crossref: OJS\'s own deposit file was sent unchanged — ' . implode('; ', $report['problems']));
		}
		if ($result !== $doc && $this->schemaCheckOn($contextId)) {
			// The completed file is checked against Crossref's own published schema before OJS sends it. If it does not
			// pass, OJS's own file goes exactly as OJS made it: the plugin never turns a deposit that would have been
			// accepted into one Crossref refuses. ("unchecked" — the schema could not be had — sends the completed file,
			// which has already passed the plugin's own order checks; the status window says so.)
			$check = $this->schemaCheck($result);
			$this->noteSchemaCheck($contextId, $check);
			if ($check['state'] === 'invalid') {
				error_log('Complete Records for Crossref: the completed file did not pass Crossref\'s schema ' . $check['version']
					. ', so OJS\'s own file was sent unchanged — ' . implode('; ', array_slice($check['errors'], 0, 3)));
				$this->noteFile($contextId, $report, 'invalid');
				$this->noteCrossmarkSent($report, '');   // OJS's own file goes: no Crossmark in it
				return false;
			}
		}
		$this->noteFile($contextId, $report, $result !== $doc ? 'completed' : ($report['problems'] ? 'kept' : 'nothing'));
		$this->noteCrossmarkSent($report, $result !== $doc ? CcrCompleter::bareDoi((string) $this->getSetting($contextId, 'crossmarkPolicy')) : '');
		foreach ($report['articles'] as $doi => $row) {
			// this file is what goes for these DOIs now: what an earlier file of the same DOI carried no longer counts
			unset($this->_sent[$doi]);
			if (isset($row['sent'])) $this->_sent[$doi] = $row;
		}
		if ($result !== $doc) $params[0] = $result;   // the hook passes the file by reference: this hands OJS the completed one
		return false;
	}

	/**
	 * What the plugin did with the last file OJS's Crossref plugin built for this journal — an Export or a deposit — kept
	 * for the status window, so that a file with nothing added still shows that the plugin ran, and why nothing was added.
	 * @param $contextId int
	 * @param $report array CcrCompleter's report
	 * @param $outcome string completed | nothing | kept | invalid
	 */
	function noteFile($contextId, $report, $outcome) {
		$articles = array();
		foreach (array_slice($report['articles'], 0, 20, true) as $doi => $row) {
			$articles[$doi] = array(
				'references' => (int) $row['references'], 'affiliations' => (int) $row['affiliations'],
				'crossmark' => (bool) $row['crossmark'], 'notes' => array_slice((array) $row['notes'], 0, 4),
			);
		}
		$this->updateSetting($contextId, 'lastFile', json_encode(array(
			'at' => time(), 'outcome' => $outcome, 'count' => count($report['articles']),
			'problems' => array_slice((array) $report['problems'], 0, 3), 'articles' => $articles,
		)), 'string');
	}

	/**
	 * The status window's account of the last file: one heading line, then one line per article.
	 * @param $contextId int
	 * @return array [string, string[]]
	 */
	function lastFileLines($contextId) {
		$f = json_decode((string) $this->getSetting($contextId, 'lastFile'), true);
		if (!is_array($f) || !isset($f['outcome']) || !in_array($f['outcome'], array('completed', 'nothing', 'kept', 'invalid'), true)) {
			return array(__('plugins.generic.crossrefComplete.status.lastFile.none'), array());
		}
		$head = __('plugins.generic.crossrefComplete.status.lastFile.head', array(
			'date' => date('Y-m-d H:i', (int) $f['at']), 'count' => (int) $f['count'],
			'outcome' => __('plugins.generic.crossrefComplete.status.lastFile.' . $f['outcome'], array('problems' => implode('; ', (array) $f['problems']))),
		));
		$rows = array();
		foreach ((array) $f['articles'] as $doi => $a) {
			if ($f['outcome'] === 'completed' && ($a['references'] || $a['affiliations'] || $a['crossmark'])) {
				$rows[] = __('plugins.generic.crossrefComplete.status.lastFile.added', array(
					'doi' => $doi, 'references' => (int) $a['references'], 'affiliations' => (int) $a['affiliations'],
					'crossmark' => $a['crossmark'] ? __('plugins.generic.crossrefComplete.status.lastFile.withCrossmark') : '',
					'notes' => $a['notes'] ? ' (' . implode('; ', (array) $a['notes']) . ')' : '',
				));
			} else {
				$notes = implode('; ', (array) $a['notes']);
				$rows[] = __('plugins.generic.crossrefComplete.status.lastFile.notAdded', array('doi' => $doi, 'notes' => $notes !== '' ? $notes : '—'));
			}
		}
		return array($head, $rows);
	}

	/**
	 * The journal a deposit file belongs to, from its first article OJS knows.
	 * @param $doc DOMDocument
	 * @return int|null
	 */
	function contextIdForFile($doc) {
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		foreach (CcrSchema::articles($doc) as $article) {
			$doi = CcrSchema::articleDoi($article);
			if ($doi === '') continue;
			$submission = $submissionDao->getByPubId('doi', $doi);
			if ($submission) return (int) $submission->getContextId();
		}
		return null;
	}

	/**
	 * What OJS holds for one article, in the order OJS's Crossref plugin writes it; null when this journal has no
	 * article with this DOI.
	 * @param $doi string
	 * @param $contextId int
	 * @return array|null
	 */
	function articleData($doi, $contextId) {
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$submission = $submissionDao->getByPubId('doi', $doi, $contextId);
		if (!$submission) return null;
		$publication = $submission->getCurrentPublication();   // the publication OJS's Crossref plugin deposits
		if (!$publication) return null;
		$locale = $publication->getData('locale');

		$references = array();
		$citationDao = DAORegistry::getDAO('CitationDAO'); /* @var $citationDao CitationDAO */
		foreach ($citationDao->getByPublicationId($publication->getId())->toArray() as $citation) {
			$references[] = $citation->getRawCitation();
		}
		if (!$references) $references = CcrCitations::splitRaw((string) $publication->getData('citationsRaw'));

		$authors = array();
		foreach ((array) $publication->getData('authors') as $author) { /* @var $author Author */
			$affiliation = (string) $author->getAffiliation($locale);
			if ($affiliation === '') $affiliation = (string) $author->getLocalizedAffiliation();
			$authors[] = array(
				'given' => (string) $author->getGivenName($locale),
				'family' => (string) $author->getFamilyName($locale),
				'affiliation' => $affiliation,
				'ror' => '',   // schema 4.3.6, which OJS 3.3 deposits with, has no place for a ROR ID
			);
		}
		return array('references' => $references, 'authors' => $authors);
	}

	/**
	 * Hook crossrefexportplugin::deposited — remember what each deposited record carried, for the read-back.
	 * @param $hookName string
	 * @param $params array [plugin, response body, objects]
	 * @return bool
	 */
	function noteDeposit($hookName, $params) {
		$exportPlugin = isset($params[0]) ? $params[0] : null;   // OJS's Crossref export plugin, which knows its own test setting
		$objects = isset($params[2]) ? $params[2] : array();
		if (!is_array($objects)) $objects = array($objects);
		$byContext = array();
		foreach ($objects as $object) {
			if (!is_object($object) || !method_exists($object, 'getStoredPubId')) continue;
			$doi = strtolower(trim((string) $object->getStoredPubId('doi')));
			if ($doi === '') continue;
			$contextId = method_exists($object, 'getContextId') ? (int) $object->getContextId() : (int) $object->getJournalId();
			$this->noteCrossmarkDeposit($exportPlugin, $contextId, $doi);
			if (!isset($this->_sent[$doi])) continue;
			$byContext[$contextId][$doi] = $this->_sent[$doi]['sent'];
		}
		foreach ($byContext as $contextId => $sent) {
			// OJS's Crossref plugin set to Crossref's test system: the deposit went to test.crossref.org and is not live, so
			// there is nothing to read back from the live Crossref — the record says so instead of "waiting" for ever
			$test = is_object($exportPlugin) && method_exists($exportPlugin, 'getSetting') && (bool) $exportPlugin->getSetting($contextId, 'testMode');
			$list = $this->deposits($contextId);
			foreach ($sent as $doi => $summary) {
				unset($list[$doi]);
				// 'cm': this deposit's Crossmark is in the plugin's own note (1.0.0.4 on) — the record is no longer read for it
				$list = array($doi => $test
					? array('at' => time(), 'sent' => $summary, 'checked' => time(), 'result' => array('record' => 'test'), 'test' => true, 'cm' => 1)
					: array('at' => time(), 'sent' => $summary, 'checked' => 0, 'result' => array(), 'cm' => 1)) + $list;
			}
			$this->saveDeposits($contextId, $list);
		}
		return false;
	}

	/** @return array the deposits remembered for this journal, by DOI, newest first */
	function deposits($contextId) {
		$v = json_decode((string) $this->getSetting($contextId, 'deposits'), true);
		return is_array($v) ? $v : array();
	}

	/**
	 * Keep the deposits for the read-back — newest first, at most MAX_DEPOSITS and never more than the column holds. This
	 * runs inside OJS's own deposit request: a database error here is written to the error log and never stops OJS from
	 * recording its deposit.
	 */
	function saveDeposits($contextId, $list) {
		try {
			$this->updateSetting($contextId, 'deposits', json_encode(CcrCrossmark::fitDeposits($list, self::MAX_DEPOSITS, self::MAX_DEPOSITS_BYTES)), 'string');
		} catch (Throwable $e) {
			error_log('Complete Records for Crossref: the deposits for the read-back could not be kept — ' . $e->getMessage());
		}
	}

	/**
	 * What each article of a file that leaves for Crossref carries of Crossmark: the policy DOI, or '' when the file that
	 * goes has none (Crossmark not set, or OJS's own file sent).
	 * @param $report array CcrCompleter's report
	 * @param $policy string the policy DOI in the file that goes, '' for none
	 */
	function noteCrossmarkSent($report, $policy) {
		foreach ((array) $report['articles'] as $doi => $row) {
			$this->_crossmark[strtolower($doi)] = ($policy !== '' && !empty($row['crossmark'])) ? $policy : '';
		}
	}

	/**
	 * After a deposit Crossref accepted: note a live deposit that carried Crossmark (its article page may show the button),
	 * or take the note off when this deposit went without it (Crossref's record no longer carries Crossmark). A deposit to
	 * Crossref's test system changes nothing.
	 * @param $exportPlugin object|null OJS's Crossref export plugin
	 * @param $contextId int
	 * @param $doi string lower-case
	 */
	function noteCrossmarkDeposit($exportPlugin, $contextId, $doi) {
		if (!isset($this->_crossmark[$doi])) return;
		if (is_object($exportPlugin) && method_exists($exportPlugin, 'getSetting') && (bool) $exportPlugin->getSetting($contextId, 'testMode')) return;
		if ($this->_crossmark[$doi] !== '') CcrCrossmark::mark(Config::getVar('files', 'files_dir'), $contextId, $doi, $this->_crossmark[$doi]);
		else CcrCrossmark::unmark(Config::getVar('files', 'files_dir'), $contextId, $doi);
	}

	/**
	 * Whether this article's DOI went to the live Crossref with Crossmark through this plugin: the note the deposit left
	 * (CcrCrossmark::mark), or — for deposits made before the notes existed — the read-back's own record of it.
	 * @param $contextId int
	 * @param $doi string
	 * @return bool
	 */
	function crossmarkDeposited($contextId, $doi) {
		$doi = strtolower(trim((string) $doi));
		if ($doi === '') return false;
		if (CcrCrossmark::marked(Config::getVar('files', 'files_dir'), $contextId, $doi)) return true;
		$list = $this->deposits($contextId);
		return isset($list[$doi]) && empty($list[$doi]['cm']) && empty($list[$doi]['test']) && !empty($list[$doi]['sent']['crossmark']);
	}

	/**
	 * Hook ArticleHandler::view — on an article's own page, Crossref's Crossmark button under the title and the article's
	 * DOI in the tag the button reads, when the journal has set its Crossmark policy DOI and this article went to Crossref
	 * with Crossmark. Nothing else on the page changes.
	 * @param $hookName string
	 * @param $params array [PKPRequest &$request, Issue &$issue, Submission &$article, Publication $publication]
	 * @return bool false, so OJS shows the page
	 */
	function crossmarkOnArticlePage($hookName, $params) {
		$request = $params[0];
		$context = $request ? $request->getContext() : null;
		if (!$context || !$this->getEnabled($context->getId())) return false;
		if (CcrCompleter::bareDoi((string) $this->getSetting($context->getId(), 'crossmarkPolicy')) === '') return false;
		$publication = isset($params[3]) && $params[3] ? $params[3] : (isset($params[2]) && $params[2] ? $params[2]->getCurrentPublication() : null);
		$doi = $publication ? trim((string) $publication->getStoredPubId('doi')) : '';
		if ($doi === '' || !$this->crossmarkDeposited($context->getId(), $doi)) return false;
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->addHeader('crossrefCompleteCrossmark', CcrCrossmark::metaTag($doi));
		$this->_crossmarkButton = CcrCrossmark::button();
		$templateMgr->registerFilter('output', array($this, 'crossmarkFilter'));
		return false;
	}

	/**
	 * Smarty output filter for the article page: the button just after the article's title, once (CcrCrossmark::insert).
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function crossmarkFilter($output, $templateMgr) {
		if ($this->_crossmarkButton === '') return $output;
		list($html, $inserted) = CcrCrossmark::insert($output, $this->_crossmarkButton);
		if ($inserted) {
			$this->_crossmarkButton = '';   // once per page: anything rendered after this is left alone
			if (is_object($templateMgr) && method_exists($templateMgr, 'unregisterFilter')) {
				try { $templateMgr->unregisterFilter('output', array($this, 'crossmarkFilter')); } catch (Throwable $e) { /* the guard above suffices */ }
			}
		}
		return $html;
	}

	/**
	 * Read up to CHECKS_PER_OPEN records back from Crossref — those not yet checked first, then those still waiting.
	 * @return string '' or a sentence saying why Crossref could not be reached
	 */
	function readBack($contextId, $mailto) {
		$list = $this->deposits($contextId);
		$todo = array();
		foreach ($list as $doi => $d) if (!$d['checked']) $todo[] = $doi;
		foreach ($list as $doi => $d) if ($d['checked'] && in_array('waiting', (array) $d['result'], true)) $todo[] = $doi;
		$todo = array_slice($todo, 0, self::CHECKS_PER_OPEN);
		$error = '';
		$httpClient = Application::get()->getHttpClient();
		$deadline = microtime(true) + $this->readBackSeconds;
		foreach ($todo as $doi) {
			$left = $deadline - microtime(true);
			if ($left < 1) {
				// the window must open even when Crossref is slow: the rest are read the next time it opens
				$error = __('plugins.generic.crossrefComplete.status.slow');
				break;
			}
			try {
				$response = $httpClient->request('GET', 'https://api.crossref.org/works/' . rawurlencode($doi) . ($mailto ? '?mailto=' . rawurlencode($mailto) : ''), array(
					'headers' => array('Accept' => 'application/json'),
					'timeout' => min(20, $left),
					'connect_timeout' => min(5, $left),
					'http_errors' => false,
				));
			} catch (Exception $e) {
				$error = __('plugins.generic.crossrefComplete.status.unreachable', array('reason' => $e->getMessage()));
				break;
			}
			if ($response->getStatusCode() == 404) {
				$list[$doi]['result'] = array('record' => 'waiting');
				$list[$doi]['checked'] = time();
				continue;
			}
			if ($response->getStatusCode() != 200) {
				$error = __('plugins.generic.crossrefComplete.status.unreachable', array('reason' => 'HTTP ' . $response->getStatusCode()));
				break;
			}
			$json = json_decode((string) $response->getBody(), true);
			if (!isset($json['message']) || !is_array($json['message'])) continue;
			$list[$doi]['result'] = CcrReadBack::compare($list[$doi]['sent'], $json['message'], $list[$doi]['at']);
			$list[$doi]['checked'] = time();
		}
		$this->saveDeposits($contextId, $list);
		return $error;
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
				new LinkAction(
					'status',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array('verb' => 'status', 'plugin' => $this->getName(), 'category' => 'generic')),
						__('plugins.generic.crossrefComplete.status.title')
					),
					__('plugins.generic.crossrefComplete.status.title'),
					null
				),
			) : array(),
			parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		$context = $request->getContext();
		if (!$context) return parent::manage($args, $request);
		switch ($request->getUserVar('verb')) {
			case 'settings':
				AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
				$this->import('CrossrefCompleteSettingsForm');
				$form = new CrossrefCompleteSettingsForm($this, $context->getId());
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
			case 'status':
				$error = $this->readBack($context->getId(), (string) $context->getData('contactEmail'));
				$rows = array();
				foreach ($this->deposits($context->getId()) as $doi => $d) {
					$rows[] = array(
						'doi' => $doi,
						'at' => $d['at'],
						'checked' => $d['checked'],
						'result' => (array) $d['result'],
						'sent' => (array) $d['sent'],
					);
				}
				list($lastFileHead, $lastFileRows) = $this->lastFileLines($context->getId());
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'lastFileHead' => $lastFileHead,
					'lastFileRows' => $lastFileRows,
					'rows' => $rows,
					'error' => $error,
					'labels' => CcrReadBack::LABELS,
					'schemaCheckLine' => $this->schemaCheckLine($context->getId()),
					'testModeLine' => $this->crossrefTestMode($context->getId()) ? __('plugins.generic.crossrefComplete.status.testMode') : '',
				));
				return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('status.tpl')));
		}
		return parent::manage($args, $request);
	}
}
