<?php
/**
 * The OJS 3.3 plugin and Crossref's Crossmark button (release 1.0.0.4), against stand-ins for OJS (tests/ojs33_stubs.php
 * plus the article page's template manager below): the button shows only for an article that went to the live Crossref
 * with Crossmark through the plugin, under its title, with its DOI in the tag the button reads — and nowhere else. Also:
 * the deposits kept for the read-back never outgrow OJS 3.3's column, and a database error there never reaches OJS's
 * deposit. The plugin is assembled exactly as it ships (tools/build.php) and those files are the ones run.
 */
require __DIR__ . '/_ck.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/build.php') . ' --quiet', $rc);
if ($rc !== 0) { ck('the plugin assembles', false, 'tools/build.php exit ' . $rc); done(); }
require __DIR__ . '/ojs33_stubs.php';

// OJS 3.3's TemplateManager for one page (signatures as in lib/pkp/classes/template/PKPTemplateManager.inc.php / Smarty 3.1)
class TemplateManager {
    public static $last = null; public $headers = array(); public $filters = array();
    static function getManager($request = null) { if (!self::$last) self::$last = new self(); return self::$last; }
    function addHeader($name, $header, $args = array()) { $this->headers[$name] = $header; }
    function registerFilter($type, $callback, $name = null) { $this->filters[] = array($type, $callback); }
    function unregisterFilter($type, $callback) { foreach ($this->filters as $i => $f) if ($f[0] === $type && $f[1] === $callback) unset($this->filters[$i]); $this->filters = array_values($this->filters); }
}
class CmContext { private $id; function __construct($id) { $this->id = $id; } function getId() { return $this->id; } }
class CmRequest { private $c; function __construct($c) { $this->c = $c; } function getContext() { return $this->c; } }
class CmPublication extends StubPublication { function getStoredPubId($t) { return $t === 'doi' ? $this->getData('doi') : null; } }
class CmExport { private $t; function __construct($t) { $this->t = $t; } function getSetting($contextId, $name) { return $name === 'testMode' ? $this->t : null; } }

$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-cm33-' . bin2hex(random_bytes(4));
require __DIR__ . '/../build/ojs-3.3/crossrefComplete/CrossrefCompletePlugin.inc.php';
$v = simplexml_load_file(__DIR__ . '/../build/ojs-3.3/crossrefComplete/version.xml');
ck('release 1.0.0.5', (string) $v->release === '1.0.0.5', (string) $v->release);

$DOI_A = '10.5555/ejt.2025.v10.i12.pp35-41';   // the two DOIs of the deposit-file fixture
$DOI_B = '10.5555/ejt.2020.v5.i3.pp15-18';
$POLICY = '10.5555/example.crossmark';
$subDao = new StubSubmissionDAO();
$pubA = new CmPublication(11, array('locale' => 'en_US', 'doi' => $DOI_A, 'authors' => array(new StubAuthor(array('given' => 'Mira', 'family' => 'Sample', 'affiliation' => array('en_US' => 'Test College (test value)'))))));
$pubB = new CmPublication(12, array('locale' => 'en_US', 'doi' => $DOI_B, 'authors' => array()));
$subDao->byDoi[$DOI_A] = new StubSubmission(1, $pubA, $DOI_A);
$subDao->byDoi[$DOI_B] = new StubSubmission(1, $pubB, $DOI_B);
DAORegistry::$daos = array('SubmissionDAO' => $subDao, 'CitationDAO' => new StubCitationDAO());

$plugin = new CrossrefCompletePlugin();
$plugin->enabledContexts = array(1);
$plugin->register('generic', 'plugins/generic/crossrefComplete', null);
ck('the article page hook is added', isset(HookRegistry::$hooks['ArticleHandler::view']));
$plugin->updateSetting(1, 'schemaCheck', false, 'bool');   // no network here; the schema check is tested elsewhere
$plugin->updateSetting(1, 'crossmarkPolicy', $POLICY, 'string');

/** The article page as OJS 3.3 shows it: the hook, then the page rendered through the filters the hook registered. */
function articlePage($plugin, $contextId, $publication) {
    TemplateManager::$last = null;
    $request = new CmRequest(new CmContext($contextId)); $issue = null; $article = null;
    $shown = HookRegistry::call('ArticleHandler::view', array(&$request, &$issue, &$article, $publication));
    $tm = TemplateManager::getManager();
    $html = '<!DOCTYPE html><html><head><title>t</title></head><body><header><div class="pkp_site_name">Example Health Journal</div></header>'
          . '<article class="obj_article_details"><h1 class="page_title">The article</h1><div class="row">…</div></article>'
          . '<div class="pkp_block"><h2>Information</h2></div></body></html>';
    foreach ($tm->filters as $f) $html = call_user_func($f[1], $html, $tm);
    return array($shown, $tm, $html);
}

echo "--- before any deposit with Crossmark ---\n";
list($shown, $tm, $html) = articlePage($plugin, 1, $pubA);
ck('OJS still shows its page (the hook returns false)', $shown === false);
ck('no button, no tag: Crossref holds no Crossmark data for it yet', !$tm->headers && strpos($html, 'crossmark') === false, array($tm->headers, $html));

echo "\n--- a live deposit that carried Crossmark ---\n";
$f = fixture('ojs33-4.3.6.xml');
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));
ck('the completed file carries Crossmark with the policy DOI', xp($f)->evaluate('count(//cr:crossmark_policy[.="' . $POLICY . '"])') == 2);
HookRegistry::call('crossrefexportplugin::deposited', array(new CmExport(0), '<response/>', array($subDao->byDoi[$DOI_A])));
ck('the deposit leaves its note in OJS\'s files directory', is_file(CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 1, $DOI_A)));
list($shown, $tm, $html) = articlePage($plugin, 1, $pubA);
ck('the page\'s <head> gets the tag the widget reads: dc.identifier = doi:<the article\'s DOI>', isset($tm->headers['crossrefCompleteCrossmark']) && $tm->headers['crossrefCompleteCrossmark'] === CcrCrossmark::metaTag($DOI_A), $tm->headers);
ck('the button sits right under the article\'s title', strpos($html, '<h1 class="page_title">The article</h1>' . CcrCrossmark::button() . '<div class="row">') !== false, $html);
ck('…once', substr_count($html, 'data-target="crossmark"') === 1);
ck('…and the filter took itself off after the page', $tm->filters === array(), $tm->filters);
ck('OJS still shows its page', $shown === false);

echo "\n--- where it never shows ---\n";
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));
HookRegistry::call('crossrefexportplugin::deposited', array(new CmExport(1), '<response/>', array($subDao->byDoi[$DOI_B])));
ck('a deposit to Crossref\'s TEST system: no note', !is_file(CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 1, $DOI_B)));
list(, $tm, $html) = articlePage($plugin, 1, $pubB);
ck('…and no button on that article', !$tm->headers && strpos($html, 'crossmark') === false);
list(, $tm, $html) = articlePage($plugin, 1, new CmPublication(13, array('doi' => '')));
ck('an article with no DOI: nothing', !$tm->headers && strpos($html, 'crossmark') === false);
list(, $tm, $html) = articlePage($plugin, 2, $pubA);
ck('a journal where the plugin is not enabled: nothing', !$tm->headers && strpos($html, 'crossmark') === false);
$plugin->updateSetting(1, 'crossmarkPolicy', '', 'string');
list(, $tm, $html) = articlePage($plugin, 1, $pubA);
ck('the journal has emptied its Crossmark policy DOI: nothing (Crossmark is off)', !$tm->headers && strpos($html, 'crossmark') === false);
$plugin->updateSetting(1, 'crossmarkPolicy', $POLICY, 'string');
$plugin->updateSetting(1, 'crossmarkPolicy', '', 'string');
$g = fixture('ojs33-4.3.6.xml');
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$g));
ck('(article A still carries its note from the deposit with Crossmark)', is_file(CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 1, $DOI_A)));
HookRegistry::call('crossrefexportplugin::deposited', array(new CmExport(0), '<response/>', array($subDao->byDoi[$DOI_A])));
ck('a later live deposit WITHOUT Crossmark (policy emptied): Crossref\'s record no longer carries it, so the note goes', !is_file(CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 1, $DOI_A)));
$plugin->updateSetting(1, 'crossmarkPolicy', $POLICY, 'string');
list(, $tm, $html) = articlePage($plugin, 1, $pubA);
ck('…so even with the policy set again, that article shows no button until it goes to Crossref with Crossmark', !$tm->headers && strpos($html, 'crossmark') === false, $tm->headers);

echo "\n--- a deposit made with 1.0.0.3, before the notes existed (a member journal's three, 3 Oct 2026) ---\n";
$list = $plugin->deposits(1);
$list['10.5555/ehj.v10.i01.2025.pp01-08'] = array('at' => time(), 'checked' => 0, 'result' => array(), 'sent' => array('references' => 53, 'affiliations' => 3, 'crossmark' => $POLICY));
$list['10.5555/ehj.11.s1.47'] = array('at' => time(), 'checked' => time(), 'result' => array('record' => 'test'), 'test' => true, 'sent' => array('crossmark' => $POLICY));
$plugin->saveDeposits(1, $list);
list(, $tm, $html) = articlePage($plugin, 1, new CmPublication(14, array('doi' => '10.5555/EHJ.v10.i01.2025.pp01-08')));
ck('the read-back\'s own record of a live deposit with Crossmark counts: the button shows', strpos($html, 'data-target="crossmark"') !== false && isset($tm->headers['crossrefCompleteCrossmark']));
list(, $tm, $html) = articlePage($plugin, 1, new CmPublication(15, array('doi' => '10.5555/EHJ.11.S1.47')));
ck('…a test deposit in that record does not', strpos($html, 'crossmark') === false);

echo "\n--- the deposits kept for the read-back (OJS 3.3: a TEXT column of 65,535 bytes) ---\n";
$big = array();
for ($i = 0; $i < 500; $i++) $big['10.5555/x.' . $i] = array('at' => $i, 'checked' => 0, 'result' => array(), 'sent' => array('references' => 53, 'affiliations' => 3, 'ror' => 0, 'orcid' => 0, 'authors' => 3, 'abstract' => true, 'license' => true, 'funders' => 0, 'links' => 2, 'crossmark' => $POLICY, 'relations' => 0));
$plugin->saveDeposits(1, $big);
ck('500 deposits: what is kept fits the column (' . strlen((string) $plugin->getSetting(1, 'deposits')) . ' bytes), newest first', strlen((string) $plugin->getSetting(1, 'deposits')) <= 60000 && key($plugin->deposits(1)) === '10.5555/x.0');
class FailingPlugin extends CrossrefCompletePlugin { function updateSetting($c, $n, $v, $t = null) { throw new Exception('Data too long for column setting_value'); } }
$failing = new FailingPlugin();
$log = tempnam(sys_get_temp_dir(), 'ccrlog'); $old = ini_set('error_log', $log);
$threw = false;
try { $failing->saveDeposits(1, array('10.1/x' => array('at' => 1))); } catch (Throwable $e) { $threw = true; }
ck('a database error while keeping them never reaches OJS\'s deposit; it goes to the error log', !$threw && strpos((string) file_get_contents($log), 'could not be kept — Data too long') !== false, file_get_contents($log));
ini_set('error_log', $old === false ? '' : $old); @unlink($log);
done();
