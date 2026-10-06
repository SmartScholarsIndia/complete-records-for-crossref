<?php
/**
 * The OJS 3.3 plugin and Crossref's Crossmark button (release 1.0.0.4), against stand-ins for OJS (tests/ojs33_stubs.php
 * plus the article page's template manager below): the button shows only for an article that went to the live Crossref
 * with Crossmark through the plugin, under its title, with its DOI in the tag the button reads — and nowhere else. Also:
 * the deposits kept for the read-back never outgrow OJS 3.3's column, and a database error there never reaches OJS's
 * deposit. The plugin is assembled exactly as it ships (tools/build.php) and those files are the ones run.
 */
require __DIR__ . '/_ck.php';
foreach (['http_proxy','https_proxy','HTTP_PROXY','HTTPS_PROXY','all_proxy','ALL_PROXY'] as $pv) putenv("$pv=http://127.0.0.1:9"); foreach (['no_proxy','NO_PROXY'] as $pv) putenv("$pv=127.0.0.1,localhost");   // nothing here may reach the network
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
class CmContext {
    private $id; private $mail;
    function __construct($id, $mail = '') { $this->id = $id; $this->mail = $mail; }
    function getId() { return $this->id; }
    function getData($k) { return $k === 'contactEmail' ? $this->mail : null; }   // the journal's contact e-mail, as Context::getData gives it
}
class CmRequest { private $c; function __construct($c) { $this->c = $c; } function getContext() { return $this->c; } }
class CmPublication extends StubPublication { function getStoredPubId($t) { return $t === 'doi' ? $this->getData('doi') : null; } }
class CmExport { private $t; function __construct($t) { $this->t = $t; } function getSetting($contextId, $name) { return $name === 'testMode' ? $this->t : null; } }

$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-cm33-' . bin2hex(random_bytes(4));
require __DIR__ . '/../build/ojs-3.3/crossrefComplete/CrossrefCompletePlugin.inc.php';
$v = simplexml_load_file(__DIR__ . '/../build/ojs-3.3/crossrefComplete/version.xml');
ck('release 1.0.0.6', (string) $v->release === '1.0.0.6', (string) $v->release);

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
function articlePage($plugin, $contextId, $publication, $mail = '') {
    TemplateManager::$last = null;
    $request = new CmRequest(new CmContext($contextId, $mail)); $issue = null; $article = null;
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

echo "\n--- 1.0.0.6: a journal whose records reach Crossref another way (a sponsor registers its DOIs, another service deposits the metadata) ---\n";
// Article C never went through the plugin: no note, nothing in the read-back's record. Crossref's own record of it is the
// stand-in below; the journal's contact e-mail puts the read in Crossref's polite pool, as the read-back's reads are.
$DOI_C = '10.5555/ejt.2019.v4.i1.pp1-7';
$MAIL = 'editor@journal.example.org';
$pubC = new CmPublication(16, array('locale' => 'en_US', 'doi' => $DOI_C, 'authors' => array()));
$record = function ($policy) use ($DOI_C) {
    $m = array('DOI' => $DOI_C, 'type' => 'journal-article', 'title' => array('An article registered by the sponsor'));
    if ($policy !== null) $m['update-policy'] = $policy;
    return new StubResponse(200, json_encode(array('status' => 'ok', 'message-type' => 'work', 'message' => $m)));
};
$recordPath = function ($doi) { return CcrCrossmark::recordPath($GLOBALS['OJS_FILES_DIR'], 1, $doi); };
/** A new request to OJS: a new plugin instance with the journal's settings, the only one hooked in. */
function fresh(&$plugin) {
    $next = new CrossrefCompletePlugin();
    $next->settings = $plugin->settings; $next->enabledContexts = $plugin->enabledContexts;
    HookRegistry::$hooks = array();
    $next->register('generic', 'plugins/generic/crossrefComplete', null);
    $plugin = $next;
}
fresh($plugin);
$plugin->updateSetting(1, 'crossmarkPolicy', $POLICY, 'string');
Application::$http = new StubHttp();
Application::$http->answers[rawurlencode($DOI_C)] = $record('http://dx.doi.org/' . strtoupper($POLICY));   // as Crossref's API gives it: a doi.org address, its own case

ck('the choice is off unless the journal switched it on', $plugin->crossmarkByRecordOn(1) === false);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('off (as before 1.0.0.6): an article the plugin never deposited shows no button, and Crossref is not asked', !$tm->headers && strpos($html, 'crossmark') === false && Application::$http->asked === array(), Application::$http->asked);

$plugin->updateSetting(1, 'crossmarkByRecord', true, 'bool');
fresh($plugin);
list($shown, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('on, and Crossref\'s record names the journal\'s policy (given as dx.doi.org, upper case): the button, right under the title, once — exactly as 1.0.0.4 places it',
   strpos($html, '<h1 class="page_title">The article</h1>' . CcrCrossmark::button() . '<div class="row">') !== false && substr_count($html, 'data-target="crossmark"') === 1, $html);
ck('…the tag the widget reads, in <head>', isset($tm->headers['crossrefCompleteCrossmark']) && $tm->headers['crossrefCompleteCrossmark'] === CcrCrossmark::metaTag($DOI_C), $tm->headers);
ck('…OJS still shows its page; the filter took itself off', $shown === false && $tm->filters === array());
$asked = Application::$http->asked; $opts = Application::$http->options;
ck('Crossref asked once, for this DOI, in the polite pool (mailto), as the read-back asks', count($asked) === 1 && $asked[0] === 'https://api.crossref.org/works/' . rawurlencode($DOI_C) . '?mailto=' . rawurlencode($MAIL), $asked);
ck('…naming the plugin and its release in the User-Agent, with the journal\'s contact (Crossref\'s etiquette)',
   isset($opts[0]['headers']['User-Agent']) && $opts[0]['headers']['User-Agent'] === 'crossrefComplete/' . (string) $v->release . ' (https://github.com/SmartScholarsIndia/complete-records-for-crossref; mailto:' . $MAIL . ')'
   && $opts[0]['headers']['Accept'] === 'application/json', $opts[0]);
ck('…with a short wait: timeout ' . CrossrefCompletePlugin::RECORD_READ_SECONDS . ' s, connect_timeout 2 s, no exception on an HTTP error',
   $opts[0]['timeout'] === CrossrefCompletePlugin::RECORD_READ_SECONDS && $opts[0]['connect_timeout'] === 2 && $opts[0]['http_errors'] === false && CrossrefCompletePlugin::RECORD_READ_SECONDS <= 5, $opts[0]);
ck('…the answer is kept in OJS\'s files directory, beside the notes', is_file($recordPath($DOI_C)) && CcrCrossmark::readRecord($recordPath($DOI_C))['found'] === strtolower($POLICY), @file_get_contents($recordPath($DOI_C)));
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('the next view of the article (a new request): the button again, and Crossref NOT asked again (the answer kept is used)', substr_count($html, 'data-target="crossmark"') === 1 && count(Application::$http->asked) === 1, Application::$http->asked);
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, '');
ck('a journal with no contact e-mail set: the kept answer still serves (no read, so no mailto question arises)', substr_count($html, 'data-target="crossmark"') === 1 && count(Application::$http->asked) === 1);
$plugin->updateSetting(1, 'crossmarkByRecord', false, 'bool');
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('the choice switched off again: no button, even with Crossref\'s answer kept — the journal\'s choice governs', !$tm->headers && strpos($html, 'crossmark') === false);
$plugin->updateSetting(1, 'crossmarkByRecord', true, 'bool');
$plugin->updateSetting(1, 'crossmarkPolicy', '', 'string');
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('no Crossmark policy DOI set: no button, nothing asked', !$tm->headers && strpos($html, 'crossmark') === false && count(Application::$http->asked) === 1);
$plugin->updateSetting(1, 'crossmarkPolicy', $POLICY, 'string');
$plugin->enabledContexts = array(2);
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('the plugin not enabled for the journal: nothing, nothing asked', !$tm->headers && strpos($html, 'crossmark') === false && count(Application::$http->asked) === 1);
$plugin->enabledContexts = array(1);
fresh($plugin);

echo "\n--- …where it never shows: a different policy, none, no record, an error, a slow answer ---\n";
$cases = array(
    'a different policy' => array('10.5555/ejt.2019.v4.i2.pp8-9', $record('https://doi.org/10.5555/ejt.another-policy')),
    'no policy on the record' => array('10.5555/ejt.2019.v4.i2.pp10-11', $record(null)),
    'no record at Crossref (404)' => array('10.5555/ejt.2019.v4.i2.pp12-13', new StubResponse(404, '{"status":"error","message-type":"route-not-found"}')),
    'Crossref answers HTTP 500' => array('10.5555/ejt.2019.v4.i2.pp14-15', new StubResponse(500, '')),
    'Crossref answers something that is not a record' => array('10.5555/ejt.2019.v4.i2.pp16-17', new StubResponse(200, '<html>maintenance</html>')),
);
foreach ($cases as $what => $case) {
    list($doi, $answer) = $case;
    Application::$http = new StubHttp();
    Application::$http->answers[rawurlencode($doi)] = $answer;
    $pub = new CmPublication(17, array('locale' => 'en_US', 'doi' => $doi, 'authors' => array()));
    fresh($plugin);
    list(, $tm, $html) = articlePage($plugin, 1, $pub, $MAIL);
    $n = count(Application::$http->asked);
    fresh($plugin);
    list(, $tm2, $html2) = articlePage($plugin, 1, $pub, $MAIL);
    ck($what . ': no button, no tag; asked once and not again on the next view', !$tm->headers && strpos($html, 'crossmark') === false && $n === 1 && !$tm2->headers && strpos($html2, 'crossmark') === false && count(Application::$http->asked) === 1, array($html, Application::$http->asked));
}
Application::$http = new StubHttp();
Application::$http->fail = 'Could not resolve host: api.crossref.org';
$DOI_F = '10.5555/ejt.2019.v4.i3.pp1-2';
$pubF = new CmPublication(18, array('locale' => 'en_US', 'doi' => $DOI_F, 'authors' => array()));
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubF, $MAIL);
ck('Crossref unreachable: no button; the failure is kept', !$tm->headers && strpos($html, 'crossmark') === false && CcrCrossmark::readRecord($recordPath($DOI_F))['found'] === null);
Application::$http->fail = null;
Application::$http->answers[rawurlencode($DOI_F)] = $record('https://doi.org/' . $POLICY);
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubF, $MAIL);
ck('…an hour later (Crossref fine now): still not asked — a failed read is tried again only after a day', strpos($html, 'crossmark') === false && count(Application::$http->asked) === 1);
$kept = CcrCrossmark::readRecord($recordPath($DOI_F));
CcrCrossmark::writeRecord($recordPath($DOI_F), $DOI_F, $kept['policy'], $kept['found'], $kept['at'] - 86400);   // a day has passed
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubF, $MAIL);
ck('…a day later: asked again, and now that the record names the policy, the button', count(Application::$http->asked) === 2 && substr_count($html, 'data-target="crossmark"') === 1 && isset($tm->headers['crossrefCompleteCrossmark']), Application::$http->asked);
$DOI_G = '10.5555/ejt.2019.v4.i3.pp3-4';
$pubG = new CmPublication(19, array('locale' => 'en_US', 'doi' => $DOI_G, 'authors' => array()));
Application::$http = new StubHttp();
Application::$http->answers[rawurlencode($DOI_G)] = $record('https://doi.org/' . $POLICY);
Application::$http->delay = 0.6;
fresh($plugin);
$plugin->recordReadSeconds = 0.25;
$t = microtime(true);
list(, $tm, $html) = articlePage($plugin, 1, $pubG, $MAIL);
$took = microtime(true) - $t;
ck(sprintf('Crossref slow (0.6 s; the wait 0.25 s): the page goes on after %.2f s, without the button, and the failure is kept', $took),
   $took < 0.5 && !$tm->headers && strpos($html, 'crossmark') === false && count(Application::$http->asked) === 1 && Application::$http->options[0]['timeout'] === 0.25 && CcrCrossmark::readRecord($recordPath($DOI_G))['found'] === null, array($took, Application::$http->options));
$plugin->recordReadSeconds = CrossrefCompletePlugin::RECORD_READ_SECONDS;
@unlink($recordPath($DOI_G));
list(, $tm, $html) = articlePage($plugin, 1, $pubG, $MAIL);
ck('…the same request again with nothing kept (the file could not be written, say): still not asked twice in one page view', count(Application::$http->asked) === 1 && strpos($html, 'crossmark') === false);

echo "\n--- …and what comes first ---\n";
Application::$http = new StubHttp();   // answers 404 to everything
CcrCrossmark::mark($GLOBALS['OJS_FILES_DIR'], 1, $DOI_C, $POLICY);
@unlink($recordPath($DOI_C));
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubC, $MAIL);
ck('an article that went through the plugin with Crossmark (its note): the button, and Crossref not asked', substr_count($html, 'data-target="crossmark"') === 1 && Application::$http->asked === array());
CcrCrossmark::unmark($GLOBALS['OJS_FILES_DIR'], 1, $DOI_C);
$plugin->updateSetting(1, 'crossmarkPolicy', '10.5555/ejt.policy-2', 'string');
Application::$http->answers[rawurlencode($DOI_F)] = $record('https://doi.org/' . $POLICY);
fresh($plugin);
list(, $tm, $html) = articlePage($plugin, 1, $pubF, $MAIL);
ck('the journal changed its policy DOI: Crossref asked again at once; its record still names the old one, so no button', count(Application::$http->asked) === 1 && strpos($html, 'crossmark') === false, Application::$http->asked);
done();
