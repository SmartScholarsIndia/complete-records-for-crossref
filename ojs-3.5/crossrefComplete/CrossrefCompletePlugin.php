<?php

/**
 * @file plugins/generic/crossrefComplete/CrossrefCompletePlugin.php
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefCompletePlugin
 *
 * @brief Complete Records for Crossref (OJS 3.5): completes every deposit OJS's own Crossref plugin makes with what the
 *  journal already holds in OJS — the reference list, and an author's affiliation only where OJS 3.5 wrote none (it
 *  already sends affiliations with ROR IDs) — and, when the journal has set its Crossmark policy DOI, Crossmark. After a deposit it reads
 *  the record back from Crossref and shows what landed.
 *
 *  It hooks into the deposit file the same way PKP's Funding plugin does (articlecrossrefxmlfilter::execute), runs after
 *  every other plugin, and never changes or removes what OJS wrote. When the completed file would break the order of
 *  Crossref's schema, OJS's own file is sent exactly as OJS made it.
 */

namespace APP\plugins\generic\crossrefComplete;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

require_once __DIR__ . '/core/CcrCompleter.php';
require_once __DIR__ . '/core/CcrReadBack.php';
require_once __DIR__ . '/core/CcrValidator.php';
require_once __DIR__ . '/core/CcrCrossmark.php';

class CrossrefCompletePlugin extends GenericPlugin
{
    /** Deposits remembered per journal for the read-back, newest first. */
    public const MAX_DEPOSITS = 500;

    /** ...and never more than this many bytes of JSON (OJS 3.4/3.5 keep a plugin setting in a MEDIUMTEXT column). */
    public const MAX_DEPOSITS_BYTES = 4000000;

    /** Records read back from Crossref each time the status window opens. */
    public const CHECKS_PER_OPEN = 10;

    /** At most this long spent fetching Crossref's schema files during one deposit. */
    public const SCHEMA_FETCH_SECONDS = 20;

    /** The budget in force (tests shorten it). */
    public float $schemaFetchSeconds = self::SCHEMA_FETCH_SECONDS;

    /** At most this long spent reading records back each time the status window opens (tests shorten it). */
    public float $readBackSeconds = 25;

    /** A read of Crossref's record for an article page (1.0.0.6) waits at most this long, in seconds. */
    public const RECORD_READ_SECONDS = 3;

    /** The wait in force (tests shorten it). */
    public float $recordReadSeconds = self::RECORD_READ_SECONDS;

    /** The DOIs Crossref was asked about in this request, by lower-case DOI: never twice in one page view. */
    private array $recordAsked = [];

    /** What the files completed in this request carried, by lower-case DOI, until the deposit is recorded. */
    private array $sent = [];

    /** Crossref's Crossmark button, waiting for the article page's output (set only on an article page that shows it). */
    private string $crossmarkButton = '';

    /** The Crossmark policy DOI each file of this request carried, by lower-case DOI ('' = none), until the deposit. */
    private array $crossmark = [];

    /**
     * @copydoc Plugin::register()
     *
     * The hooks are added whenever the plugin is installed, not only when it is enabled for the journal in the
     * request: OJS 3.5 deposits DOIs from queued jobs, which may run in any request or none. Each hook then checks
     * that the plugin is enabled for the journal the article belongs to.
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || Application::isUnderMaintenance()) {
            return $success;
        }
        if ($success) {
            Hook::add('articlecrossrefxmlfilter::execute', [$this, 'completeDeposit'], Hook::SEQUENCE_LAST);
            Hook::add('crossrefexportplugin::deposited', [$this, 'noteDeposit']);
            Hook::add('ArticleHandler::view', [$this, 'crossmarkOnArticlePage']);
        }
        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.crossrefComplete.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.crossrefComplete.description');
    }

    /** The plugin's choices for one journal. */
    public function options(int $contextId): array
    {
        return [
            // on unless the journal switched them off (a setting never saved reads as null)
            'references' => $this->getSetting($contextId, 'references') !== false,
            'affiliations' => $this->getSetting($contextId, 'affiliations') !== false,
            'crossmarkPolicy' => (string) $this->getSetting($contextId, 'crossmarkPolicy'),
            // '' = the host of each article's own landing page, read from the deposit file itself
            'crossmarkDomain' => '',
        ];
    }

    /** The check against Crossref's schema before each deposit: on unless the journal switched it off. */
    public function schemaCheckOn(int $contextId): bool
    {
        return $this->getSetting($contextId, 'schemaCheck') !== false;
    }

    /** The journal's choice "Our records reach Crossref another way" (1.0.0.6): off unless the journal switched it on. */
    public function crossmarkByRecordOn(int $contextId): bool
    {
        return (bool) $this->getSetting($contextId, 'crossmarkByRecord');
    }

    /**
     * Check a completed file against Crossref's published schema for its version (core/CcrValidator.php). The schema
     * files are fetched once into OJS's files directory and read from there afterwards. Fetching has a budget of
     * SCHEMA_FETCH_SECONDS per deposit, so a slow host can never hold OJS's request: what is not fetched in time makes
     * this check "unchecked", and the next deposit carries on from the files already kept.
     */
    public function schemaCheck(\DOMDocument $doc): array
    {
        $deadline = microtime(true) + $this->schemaFetchSeconds;
        return \CcrValidator::check($doc, $this->schemaCacheDir(), function ($url) use ($deadline) {
            $left = $deadline - microtime(true);
            if ($left < 1) {
                return null;
            }
            try {
                $response = Application::get()->getHttpClient()->request('GET', $url, ['timeout' => min(10, $left), 'connect_timeout' => min(5, $left), 'http_errors' => false]);
            } catch (\Throwable $e) {
                return null;
            }
            return $response->getStatusCode() == 200 ? (string) $response->getBody() : null;
        });
    }

    /** Where Crossref's schema files are kept: inside OJS's files directory, which the web cannot reach. */
    public function schemaCacheDir(): string
    {
        return rtrim((string) Config::getVar('files', 'files_dir'), '/') . '/crossrefComplete/schemas';
    }

    /** The last check, kept for the status window. */
    public function noteSchemaCheck(int $contextId, array $check): void
    {
        $this->updateSetting($contextId, 'lastSchemaCheck', json_encode([
            'at' => time(), 'state' => $check['state'], 'version' => $check['version'], 'why' => $check['why'],
            'errors' => array_slice($check['errors'], 0, 5), 'stoodIn' => array_keys((array) ($check['stoodIn'] ?? [])),
        ]), 'string');
    }

    /**
     * Whether OJS's Crossref plugin sends this journal's deposits to Crossref's test system (its "Testing" setting:
     * Settings → Distribution → DOIs → Registration). Deposits made then are not live, though OJS marks them registered.
     */
    public function crossrefTestMode(int $contextId): bool
    {
        $dao = DAORegistry::getDAO('PluginSettingsDAO'); /** @var \PKP\plugins\PluginSettingsDAO $dao */
        return $dao ? (bool) $dao->getSetting($contextId, 'crossrefplugin', 'testMode') : false;
    }

    /** One sentence on the last check, for the status window. */
    public function schemaCheckLine(int $contextId): string
    {
        if (!$this->schemaCheckOn($contextId)) {
            return __('plugins.generic.crossrefComplete.status.schemaCheck.off');
        }
        $c = json_decode((string) $this->getSetting($contextId, 'lastSchemaCheck'), true);
        if (!is_array($c) || !in_array($c['state'] ?? '', ['valid', 'invalid', 'unchecked'], true)) {
            return __('plugins.generic.crossrefComplete.status.schemaCheck.none');
        }
        $line = __('plugins.generic.crossrefComplete.status.schemaCheck.' . $c['state'], [
            'version' => (string) $c['version'], 'date' => date('Y-m-d H:i', (int) $c['at']),
            'why' => (string) $c['why'], 'errors' => implode('; ', (array) $c['errors']),
        ]);
        if (!empty($c['stoodIn']) && $c['state'] !== 'unchecked') {
            $line .= ' ' . __('plugins.generic.crossrefComplete.status.schemaCheck.standIn', ['files' => implode(', ', (array) $c['stoodIn'])]);
        }
        return $line;
    }

    /**
     * Hook articlecrossrefxmlfilter::execute — complete the deposit file OJS's Crossref plugin has just written.
     *
     * @param string $hookName
     * @param array $params [DOMDocument &$preliminaryOutput]
     */
    public function completeDeposit($hookName, $params): bool
    {
        $doc = $params[0];
        if (!($doc instanceof \DOMDocument)) {
            return Hook::CONTINUE;
        }
        $contextId = $this->contextIdForFile($doc);
        if (!$contextId || !$this->getEnabled($contextId)) {
            return Hook::CONTINUE;
        }
        [$result, $report] = \CcrCompleter::complete($doc, fn ($doi) => $this->articleData($doi, $contextId), $this->options($contextId));
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
                return Hook::CONTINUE;
            }
        }
        $this->noteFile($contextId, $report, $result !== $doc ? 'completed' : ($report['problems'] ? 'kept' : 'nothing'));
        $this->noteCrossmarkSent($report, $result !== $doc ? \CcrCompleter::bareDoi((string) $this->getSetting($contextId, 'crossmarkPolicy')) : '');
        foreach ($report['articles'] as $doi => $row) {
            // this file is what goes for these DOIs now: what an earlier file of the same DOI carried no longer counts
            unset($this->sent[$doi]);
            if (isset($row['sent'])) {
                $this->sent[$doi] = $row;
            }
        }
        if ($result !== $doc) {
            $params[0] = $result;   // the hook passes the file by reference: this hands OJS the completed one
        }
        return Hook::CONTINUE;
    }

    /**
     * What the plugin did with the last file OJS's Crossref plugin built for this journal — an Export or a deposit — kept
     * for the status window, so that a file with nothing added still shows that the plugin ran, and why nothing was added.
     *
     * @param array $report CcrCompleter's report
     * @param string $outcome completed | nothing | kept | invalid
     */
    public function noteFile(int $contextId, array $report, string $outcome): void
    {
        $articles = [];
        foreach (array_slice($report['articles'], 0, 20, true) as $doi => $row) {
            $articles[$doi] = [
                'references' => (int) $row['references'], 'affiliations' => (int) $row['affiliations'],
                'crossmark' => (bool) $row['crossmark'], 'notes' => array_slice((array) $row['notes'], 0, 4),
            ];
        }
        $this->updateSetting($contextId, 'lastFile', json_encode([
            'at' => time(), 'outcome' => $outcome, 'count' => count($report['articles']),
            'problems' => array_slice((array) $report['problems'], 0, 3), 'articles' => $articles,
        ]), 'string');
    }

    /**
     * The status window's account of the last file: one heading line, then one line per article.
     *
     * @return array{0: string, 1: string[]}
     */
    public function lastFileLines(int $contextId): array
    {
        $f = json_decode((string) $this->getSetting($contextId, 'lastFile'), true);
        if (!is_array($f) || !isset($f['outcome']) || !in_array($f['outcome'], ['completed', 'nothing', 'kept', 'invalid'], true)) {
            return [__('plugins.generic.crossrefComplete.status.lastFile.none'), []];
        }
        $head = __('plugins.generic.crossrefComplete.status.lastFile.head', [
            'date' => date('Y-m-d H:i', (int) $f['at']), 'count' => (int) $f['count'],
            'outcome' => __('plugins.generic.crossrefComplete.status.lastFile.' . $f['outcome'], ['problems' => implode('; ', (array) $f['problems'])]),
        ]);
        $rows = [];
        foreach ((array) $f['articles'] as $doi => $a) {
            if ($f['outcome'] === 'completed' && ($a['references'] || $a['affiliations'] || $a['crossmark'])) {
                $rows[] = __('plugins.generic.crossrefComplete.status.lastFile.added', [
                    'doi' => $doi, 'references' => (int) $a['references'], 'affiliations' => (int) $a['affiliations'],
                    'crossmark' => $a['crossmark'] ? __('plugins.generic.crossrefComplete.status.lastFile.withCrossmark') : '',
                    'notes' => $a['notes'] ? ' (' . implode('; ', (array) $a['notes']) . ')' : '',
                ]);
            } else {
                $notes = implode('; ', (array) $a['notes']);
                $rows[] = __('plugins.generic.crossrefComplete.status.lastFile.notAdded', ['doi' => $doi, 'notes' => $notes !== '' ? $notes : '—']);
            }
        }
        return [$head, $rows];
    }

    /**
     * The journal a deposit file belongs to: the journal of the request first, then every other journal on this OJS,
     * asked for the file's first DOI it knows.
     */
    public function contextIdForFile(\DOMDocument $doc): ?int
    {
        $ids = [];
        $request = Application::get()->getRequest();
        $context = $request ? $request->getContext() : null;
        if ($context) {
            $ids[] = (int) $context->getId();
        }
        $contexts = Application::getContextDAO()->getAll();
        while ($c = $contexts->next()) {
            if (!in_array((int) $c->getId(), $ids, true)) {
                $ids[] = (int) $c->getId();
            }
        }
        foreach (\CcrSchema::articles($doc) as $article) {
            $doi = \CcrSchema::articleDoi($article);
            if ($doi === '') {
                continue;
            }
            foreach ($ids as $id) {
                if (Repo::submission()->getByDoi($doi, $id)) {
                    return $id;
                }
            }
        }
        return null;
    }

    /**
     * What OJS holds for one article, in the order OJS's Crossref plugin writes it; null when this journal has no
     * article with this DOI.
     */
    public function articleData(string $doi, int $contextId): ?array
    {
        $submission = Repo::submission()->getByDoi($doi, $contextId);
        if (!$submission) {
            return null;
        }
        $publication = $submission->getCurrentPublication();   // the publication OJS's Crossref plugin deposits
        if (!$publication) {
            return null;
        }
        $locale = $publication->getData('locale');

        $citationDao = DAORegistry::getDAO('CitationDAO'); /** @var \PKP\citation\CitationDAO $citationDao */
        $references = array_values(array_map('strval', $citationDao->getRawCitationsByPublicationId((int) $publication->getId())->all()));
        if (!$references) {
            $references = \CcrCitations::splitRaw((string) $publication->getData('citationsRaw'));
        }

        $authors = [];
        foreach ($publication->getData('authors') as $author) { /** @var \APP\author\Author $author */
            // OJS 3.5 keeps affiliations as records with their own ROR IDs and already deposits them; this is only used
            // for an author whose affiliation has no name in the publication's language, which OJS 3.5 then leaves out
            $first = $author->getAffiliations()[0] ?? null;
            $authors[] = [
                'given' => (string) $author->getGivenName($locale),
                'family' => (string) $author->getFamilyName($locale),
                'affiliation' => $first ? (string) ($first->getLocalizedName($locale) ?? '') : '',
                'ror' => $first ? (string) ($first->getRor() ?? '') : '',
            ];
        }
        return ['references' => $references, 'authors' => $authors];
    }

    /**
     * Hook crossrefexportplugin::deposited — remember what each deposited record carried, for the read-back.
     *
     * @param string $hookName
     * @param array $params [plugin, response body, objects]
     */
    public function noteDeposit($hookName, $params): bool
    {
        $exportPlugin = $params[0] ?? null;   // OJS's Crossref export plugin, which knows its own test setting
        $objects = $params[2] ?? [];
        if (!is_array($objects)) {
            $objects = [$objects];
        }
        $byContext = [];
        foreach ($objects as $object) {
            if (!is_object($object) || !method_exists($object, 'getStoredPubId')) {
                continue;
            }
            $doi = strtolower(trim((string) $object->getStoredPubId('doi')));
            if ($doi === '') {
                continue;
            }
            $contextId = (int) $object->getData('contextId');
            $this->noteCrossmarkDeposit($exportPlugin, $contextId, $doi);
            if (!isset($this->sent[$doi])) {
                continue;
            }
            $byContext[$contextId][$doi] = $this->sent[$doi]['sent'];
        }
        foreach ($byContext as $contextId => $sent) {
            // OJS's Crossref plugin set to Crossref's test system: the deposit went to test.crossref.org and is not live, so
            // there is nothing to read back from the live Crossref — the record says so instead of "waiting" for ever
            $test = is_object($exportPlugin) && method_exists($exportPlugin, 'getSetting') && (bool) $exportPlugin->getSetting($contextId, 'testMode');
            $list = $this->deposits($contextId);
            foreach ($sent as $doi => $summary) {
                unset($list[$doi]);
                // 'cm': this deposit's Crossmark is in the plugin's own note (1.0.0.4 on) — the record is no longer read for it
                $list = [$doi => $test
                    ? ['at' => time(), 'sent' => $summary, 'checked' => time(), 'result' => ['record' => 'test'], 'test' => true, 'cm' => 1]
                    : ['at' => time(), 'sent' => $summary, 'checked' => 0, 'result' => [], 'cm' => 1]] + $list;
            }
            $this->saveDeposits($contextId, $list);
        }
        return Hook::CONTINUE;
    }

    /** The deposits remembered for this journal, by DOI, newest first. */
    public function deposits(int $contextId): array
    {
        $v = json_decode((string) $this->getSetting($contextId, 'deposits'), true);
        return is_array($v) ? $v : [];
    }

    /**
     * Keep the deposits for the read-back — newest first, at most MAX_DEPOSITS and MAX_DEPOSITS_BYTES. This runs inside
     * OJS's own deposit: a database error here is written to the error log and never stops OJS recording its deposit.
     */
    public function saveDeposits(int $contextId, array $list): void
    {
        try {
            $this->updateSetting($contextId, 'deposits', json_encode(\CcrCrossmark::fitDeposits($list, self::MAX_DEPOSITS, self::MAX_DEPOSITS_BYTES)), 'string');
        } catch (\Throwable $e) {
            error_log('Complete Records for Crossref: the deposits for the read-back could not be kept — ' . $e->getMessage());
        }
    }

    /**
     * What each article of a file that leaves for Crossref carries of Crossmark: the policy DOI, or '' when the file that
     * goes has none (Crossmark not set, or OJS's own file sent).
     */
    public function noteCrossmarkSent(array $report, string $policy): void
    {
        foreach ((array) $report['articles'] as $doi => $row) {
            $this->crossmark[strtolower($doi)] = ($policy !== '' && !empty($row['crossmark'])) ? $policy : '';
        }
    }

    /**
     * After a deposit Crossref accepted: note a live deposit that carried Crossmark (its article page may show the button),
     * or take the note off when this deposit went without it (Crossref's record no longer carries Crossmark). A deposit to
     * Crossref's test system changes nothing.
     */
    public function noteCrossmarkDeposit($exportPlugin, int $contextId, string $doi): void
    {
        if (!isset($this->crossmark[$doi])) {
            return;
        }
        if (is_object($exportPlugin) && method_exists($exportPlugin, 'getSetting') && (bool) $exportPlugin->getSetting($contextId, 'testMode')) {
            return;
        }
        if ($this->crossmark[$doi] !== '') {
            \CcrCrossmark::mark(Config::getVar('files', 'files_dir'), $contextId, $doi, $this->crossmark[$doi]);
        } else {
            \CcrCrossmark::unmark(Config::getVar('files', 'files_dir'), $contextId, $doi);
        }
    }

    /**
     * Whether this article's DOI went to the live Crossref with Crossmark through this plugin: the note the deposit left
     * (CcrCrossmark::mark), or — for deposits made before the notes existed — the read-back's own record of it.
     */
    public function crossmarkDeposited(int $contextId, string $doi): bool
    {
        $doi = strtolower(trim($doi));
        if ($doi === '') {
            return false;
        }
        if (\CcrCrossmark::marked(Config::getVar('files', 'files_dir'), $contextId, $doi)) {
            return true;
        }
        $list = $this->deposits($contextId);
        return isset($list[$doi]) && empty($list[$doi]['cm']) && empty($list[$doi]['test']) && !empty($list[$doi]['sent']['crossmark']);
    }

    /**
     * Whether Crossref's own public record of this DOI names the journal's Crossmark policy (1.0.0.6, for a journal whose
     * records reach Crossref another way). Crossref is asked at most once per DOI per page view, waits at most
     * RECORD_READ_SECONDS, and what it said is kept beside the plugin's notes (CcrCrossmark::recordDecision says when it
     * is asked again). No answer in time, no record, or another policy: no button.
     *
     * @param string $policy the journal's policy DOI, bare
     * @param string $mailto the journal's contact e-mail, for Crossref's polite pool ('' for none)
     */
    public function crossmarkAtCrossref(int $contextId, string $doi, string $policy, string $mailto): bool
    {
        $doi = strtolower(trim($doi));
        $policy = strtolower($policy);
        $path = \CcrCrossmark::recordPath(Config::getVar('files', 'files_dir'), $contextId, $doi);
        if ($path === '') {
            return false;
        }
        $what = \CcrCrossmark::recordDecision(\CcrCrossmark::readRecord($path), $policy, time());
        if ($what === 'read' && !isset($this->recordAsked[$doi])) {
            $this->recordAsked[$doi] = true;
            $found = $this->readRecordPolicy($doi, $mailto);
            \CcrCrossmark::writeRecord($path, $doi, $policy, $found);
            $what = ($found !== null && $found !== '' && $found === $policy) ? 'show' : 'hide';
        }
        return $what === 'show';
    }

    /**
     * The Crossmark policy Crossref's record of this DOI names, read from Crossref's public REST API
     * (api.crossref.org/works/<doi>, as the read-back reads it): '' when the record names none or there is no record,
     * null when Crossref could not be read in time.
     */
    public function readRecordPolicy(string $doi, string $mailto): ?string
    {
        try {
            $response = Application::get()->getHttpClient()->request('GET', 'https://api.crossref.org/works/' . rawurlencode($doi) . ($mailto ? '?mailto=' . rawurlencode($mailto) : ''), [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => $this->userAgent($mailto)],
                'timeout' => $this->recordReadSeconds,
                'connect_timeout' => min(2, $this->recordReadSeconds),
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if ($response->getStatusCode() == 404) {
            return '';
        }
        if ($response->getStatusCode() != 200) {
            return null;
        }
        $json = json_decode((string) $response->getBody(), true);
        if (!isset($json['message']) || !is_array($json['message'])) {
            return null;
        }
        return \CcrCrossmark::recordPolicy($json['message']);
    }

    /**
     * How the plugin names itself to Crossref's API — its etiquette asks for a User-Agent that identifies the script,
     * and a mailto (crossref.org/documentation/retrieve-metadata/rest-api/access-and-authentication/).
     */
    public function userAgent(string $mailto): string
    {
        $mailto = preg_replace('/[^\x21-\x7e]/', '', $mailto);   // one header line: printable characters only
        return 'crossrefComplete/' . $this->release() . ' (https://github.com/SmartScholarsIndia/complete-records-for-crossref' . ($mailto !== '' ? '; mailto:' . $mailto : '') . ')';
    }

    /** The plugin's release, as its own version.xml says. */
    public function release(): string
    {
        return preg_match('~<release>\s*([^<\s]+)\s*</release>~', (string) @file_get_contents(__DIR__ . '/version.xml'), $m) ? $m[1] : '?';
    }

    /**
     * Hook ArticleHandler::view — on an article's own page, Crossref's Crossmark button under the title and the article's
     * DOI in the tag the button reads, when the journal has set its Crossmark policy DOI and this article went to Crossref
     * with Crossmark — through this plugin or, when the journal's records reach Crossref another way and the journal has
     * said so, as Crossref's own record of it shows. Nothing else on the page changes.
     *
     * @param string $hookName
     * @param array $params [PKPRequest &$request, Issue &$issue, Submission &$article, Publication $publication]
     */
    public function crossmarkOnArticlePage($hookName, $params): bool
    {
        $request = $params[0] ?? null;
        $context = $request ? $request->getContext() : null;
        if (!$context || !$this->getEnabled($context->getId())) {
            return Hook::CONTINUE;
        }
        $policy = \CcrCompleter::bareDoi((string) $this->getSetting($context->getId(), 'crossmarkPolicy'));
        if ($policy === '') {
            return Hook::CONTINUE;
        }
        $publication = ($params[3] ?? null) ?: (($params[2] ?? null) ? $params[2]->getCurrentPublication() : null);
        $doi = $publication ? trim((string) $publication->getStoredPubId('doi')) : '';
        if ($doi === '') {
            return Hook::CONTINUE;
        }
        if (!$this->crossmarkDeposited((int) $context->getId(), $doi)
            && !($this->crossmarkByRecordOn((int) $context->getId()) && $this->crossmarkAtCrossref((int) $context->getId(), $doi, $policy, (string) $context->getData('contactEmail')))) {
            return Hook::CONTINUE;
        }
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('crossrefCompleteCrossmark', \CcrCrossmark::metaTag($doi));
        $this->crossmarkButton = \CcrCrossmark::button();
        $templateMgr->registerFilter('output', [$this, 'crossmarkFilter']);
        return Hook::CONTINUE;
    }

    /** Smarty output filter for the article page: the button just after the article's title, once (CcrCrossmark::insert). */
    public function crossmarkFilter($output, $templateMgr)
    {
        if ($this->crossmarkButton === '') {
            return $output;
        }
        [$html, $inserted] = \CcrCrossmark::insert($output, $this->crossmarkButton);
        if ($inserted) {
            $this->crossmarkButton = '';   // once per page: anything rendered after this is left alone
            if (is_object($templateMgr) && method_exists($templateMgr, 'unregisterFilter')) {
                try {
                    $templateMgr->unregisterFilter('output', [$this, 'crossmarkFilter']);
                } catch (\Throwable $e) {
                    // the guard above suffices
                }
            }
        }
        return $html;
    }

    /**
     * Read up to CHECKS_PER_OPEN records back from Crossref — those not yet checked first, then those still waiting.
     *
     * @return string '' or a sentence saying why Crossref could not be reached
     */
    public function readBack(int $contextId, string $mailto): string
    {
        $list = $this->deposits($contextId);
        $todo = [];
        foreach ($list as $doi => $d) {
            if (!$d['checked']) {
                $todo[] = $doi;
            }
        }
        foreach ($list as $doi => $d) {
            if ($d['checked'] && in_array('waiting', (array) $d['result'], true)) {
                $todo[] = $doi;
            }
        }
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
                $response = $httpClient->request('GET', 'https://api.crossref.org/works/' . rawurlencode($doi) . ($mailto ? '?mailto=' . rawurlencode($mailto) : ''), [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => min(20, $left),
                    'connect_timeout' => min(5, $left),
                    'http_errors' => false,
                ]);
            } catch (\Throwable $e) {
                $error = __('plugins.generic.crossrefComplete.status.unreachable', ['reason' => $e->getMessage()]);
                break;
            }
            if ($response->getStatusCode() == 404) {
                $list[$doi]['result'] = ['record' => 'waiting'];
                $list[$doi]['checked'] = time();
                continue;
            }
            if ($response->getStatusCode() != 200) {
                $error = __('plugins.generic.crossrefComplete.status.unreachable', ['reason' => 'HTTP ' . $response->getStatusCode()]);
                break;
            }
            $json = json_decode((string) $response->getBody(), true);
            if (!isset($json['message']) || !is_array($json['message'])) {
                continue;
            }
            $list[$doi]['result'] = \CcrReadBack::compare($list[$doi]['sent'], $json['message'], $list[$doi]['at']);
            $list[$doi]['checked'] = time();
        }
        $this->saveDeposits($contextId, $list);
        return $error;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
                new LinkAction(
                    'status',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'status', 'plugin' => $this->getName(), 'category' => 'generic']),
                        __('plugins.generic.crossrefComplete.status.title')
                    ),
                    __('plugins.generic.crossrefComplete.status.title'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            return parent::manage($args, $request);
        }
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new CrossrefCompleteSettingsForm($this, (int) $context->getId());
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
                $error = $this->readBack((int) $context->getId(), (string) $context->getData('contactEmail'));
                $rows = [];
                foreach ($this->deposits((int) $context->getId()) as $doi => $d) {
                    $rows[] = ['doi' => $doi, 'at' => $d['at'], 'checked' => $d['checked'], 'result' => (array) $d['result'], 'sent' => (array) $d['sent']];
                }
                $templateMgr = TemplateManager::getManager($request);
                [$lastFileHead, $lastFileRows] = $this->lastFileLines((int) $context->getId());
                $templateMgr->assign(['lastFileHead' => $lastFileHead, 'lastFileRows' => $lastFileRows, 'rows' => $rows, 'error' => $error, 'labels' => \CcrReadBack::LABELS, 'schemaCheckLine' => $this->schemaCheckLine((int) $context->getId()), 'testModeLine' => $this->crossrefTestMode((int) $context->getId()) ? __('plugins.generic.crossrefComplete.status.testMode') : '']);
                return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('status.tpl')));
        }
        return parent::manage($args, $request);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\crossrefComplete\CrossrefCompletePlugin', '\CrossrefCompletePlugin');
}
