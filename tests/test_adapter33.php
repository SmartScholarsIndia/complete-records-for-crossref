<?php
/**
 * The OJS 3.3 plugin's own code, run against stand-ins for OJS (tests/ojs33_stubs.php): the hooks it adds, the article
 * it looks up for each DOI, the completed file it hands back, what it remembers after a deposit, and the read-back.
 * The plugin is first assembled exactly as it ships (tools/build.php), so the test runs the shipped files.
 */
require __DIR__ . '/_ck.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/build.php') . ' --quiet', $rc);
if ($rc !== 0) { ck('the plugin assembles', false, 'tools/build.php exit ' . $rc); done(); }
require __DIR__ . '/ojs33_stubs.php';
$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-' . bin2hex(random_bytes(4));   // OJS's files directory
require __DIR__ . '/../build/ojs-3.3/crossrefComplete/CrossrefCompletePlugin.inc.php';

$DOI_A = '10.5555/ejt.2025.v10.i12.pp35-41';
$DOI_B = '10.5555/ejt.2020.v5.i3.pp15-18';
$refs = json_decode(file_get_contents(__DIR__ . '/fixtures/references-a.json'), true)['references'];

// what OJS holds — affiliations are test values
$subDao = new StubSubmissionDAO();
$citDao = new StubCitationDAO();
$pubA = new StubPublication(11, array('locale' => 'en_US', 'authors' => array(
    new StubAuthor(array('given' => 'Mira', 'family' => 'Sample', 'affiliation' => array('en_US' => 'Test College of Food Technology (test value)'))),
)));
$citDao->byPub[11] = array_map(function ($t) { return new StubCitation($t); }, $refs);
$pubB = new StubPublication(12, array('locale' => 'en_US', 'citationsRaw' => "1. First reference line.\n\n2. Second reference, doi:10.1000/xyz123", 'authors' => array(
    new StubAuthor(array('given' => 'Leo K.', 'family' => 'Tester', 'affiliation' => array('*' => 'Test Institute (test value, only in another locale)'))),
    new StubAuthor(array('given' => 'Ana R.', 'family' => 'Example', 'affiliation' => array())),
    new StubAuthor(array('given' => 'Ravi T', 'family' => 'Example', 'affiliation' => array('en_US' => 'Second Test University (test value)'))),
)));
$subDao->byDoi[$DOI_A] = new StubSubmission(1, $pubA, $DOI_A);
$subDao->byDoi[$DOI_B] = new StubSubmission(1, $pubB, $DOI_B);
DAORegistry::$daos = array('SubmissionDAO' => $subDao, 'CitationDAO' => $citDao);

echo "--- registering ---\n";
$plugin = new CrossrefCompletePlugin();
ck('register() succeeds', $plugin->register('generic', 'plugins/generic/crossrefComplete', null) === true);
ck('the deposit-file hook is added, to run LAST (after the Funding plugin and any other)', isset(HookRegistry::$hooks['articlecrossrefxmlfilter::execute']) && HookRegistry::$hooks['articlecrossrefxmlfilter::execute'][0][0] === HOOK_SEQUENCE_LAST);
ck('the after-deposit hook is added', isset(HookRegistry::$hooks['crossrefexportplugin::deposited']));
ck('both are added even with no journal in the request (OJS\'s scheduled deposits)', count(HookRegistry::$hooks['articlecrossrefxmlfilter::execute']) === 1);

echo "\n--- a deposit file passing through the hook, as OJS's filter calls it ---\n";
$preliminaryOutput = fixture('ojs33-4.3.6.xml');
$original = $preliminaryOutput;
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$preliminaryOutput));
ck('OJS\'s variable now holds the completed file (the hook\'s by-reference hand-back works)', $preliminaryOutput !== $original && $preliminaryOutput instanceof DOMDocument);
$x = xp($preliminaryOutput);
ck('article A: the 11 references OJS split into citations', $x->evaluate('count((//cr:journal_article)[1]/cr:citation_list/cr:citation)') == 11);
ck('article B: no citation rows, so the raw block was split — numbering dropped, the one DOI kept', $x->evaluate('count((//cr:journal_article)[2]/cr:citation_list/cr:citation)') == 2
   && $x->evaluate('string((//cr:journal_article)[2]/cr:citation_list/cr:citation[1]/cr:unstructured_citation)') === 'First reference line.'
   && $x->evaluate('string((//cr:journal_article)[2]/cr:citation_list/cr:citation[2]/cr:doi)') === '10.1000/xyz123');
ck('affiliations: the publication-locale one; else the localized one; none for an author without', $x->evaluate('string((//cr:journal_article)[2]//cr:person_name[1]/cr:affiliation)') === 'Test Institute (test value, only in another locale)'
   && $x->evaluate('count((//cr:journal_article)[2]//cr:person_name[2]/cr:affiliation)') == 0
   && $x->evaluate('string((//cr:journal_article)[1]//cr:person_name[1]/cr:affiliation)') === 'Test College of Food Technology (test value)');
ck('every lookup asked OJS within this journal (context 1) with the DOI as OJS wrote it', (bool) array_filter($subDao->calls, function ($c) use ($DOI_A) { return $c === array('doi', $DOI_A, 1); }));

echo "\n--- the status window's account of the last file OJS built (1.0.0.2) ---\n";
$lf = json_decode((string) $plugin->getSetting(1, 'lastFile'), true);
ck('the file just completed is remembered: used, 2 articles, what each got', is_array($lf) && $lf['outcome'] === 'completed' && $lf['count'] === 2 && $lf['articles'][$DOI_A]['references'] === 11 && $lf['articles'][$DOI_A]['affiliations'] === 1, $lf);
list($head, $rows) = $plugin->lastFileLines(1);
ck('…the window: a heading line, then one line per article saying what was added', strpos($head, 'plugins.generic.crossrefComplete.status.lastFile.head') === 0 && strpos($head, 'status.lastFile.completed') !== false && count($rows) === 2 && strpos($rows[0], 'plugins.generic.crossrefComplete.status.lastFile.added') === 0, array($head, $rows));

echo "\n--- the check against Crossref's schema before the file goes ---\n";
$last = json_decode((string) $plugin->getSetting(1, 'lastSchemaCheck'), true);
ck('no copy of the schema and none to be had: "unchecked", and why — the completed file still went (it passed the order rules)',
   is_array($last) && $last['state'] === 'unchecked' && strpos($last['why'], 'incomplete') !== false && $preliminaryOutput !== $original, $last);
ck('…the status window says it in one sentence', strpos($plugin->schemaCheckLine(1), 'plugins.generic.crossrefComplete.status.schemaCheck.unchecked') === 0, $plugin->schemaCheckLine(1));
ck('…the copy of the schema is kept inside OJS\'s files directory', $plugin->schemaCacheDir() === $GLOBALS['OJS_FILES_DIR'] . '/crossrefComplete/schemas');
class FakeCheckPlugin extends CrossrefCompletePlugin {
    public $answer; public $asked = 0;
    function schemaCheck($doc) { $this->asked++; return $this->answer; }
}
$fake = new FakeCheckPlugin();
$log = tempnam(sys_get_temp_dir(), 'ccrlog'); $oldLog = ini_set('error_log', $log);
$fake->answer = array('state' => 'invalid', 'version' => '4.3.6', 'errors' => array('line 1: Element \'x\': This element is not expected.'), 'why' => '', 'files' => 9);
$f = fixture('ojs33-4.3.6.xml'); $f0 = $f; $params = array(&$f);
$fake->completeDeposit('articlecrossrefxmlfilter::execute', $params);
ck('a completed file that does NOT pass Crossref\'s schema: OJS\'s own file is sent, exactly as OJS made it', $f === $f0 && $fake->asked === 1);
ck('…the reason goes to the error log', strpos((string) file_get_contents($log), 'did not pass Crossref\'s schema 4.3.6') !== false, file_get_contents($log));
$fake->noteDeposit('crossrefexportplugin::deposited', array(null, '<r/>', array($subDao->byDoi[$DOI_A])));
ck('…and the deposit is not recorded as carrying additions it did not carry', !array_key_exists($DOI_A, $fake->deposits(1)));
ck('…the status window says what happened', strpos($fake->schemaCheckLine(1), 'schemaCheck.invalid') !== false && strpos($fake->schemaCheckLine(1), 'not expected') !== false, $fake->schemaCheckLine(1));
$fake->answer['state'] = 'valid'; $fake->answer['errors'] = array();
$f = fixture('ojs33-4.3.6.xml'); $f0 = $f; $params = array(&$f);
$fake->completeDeposit('articlecrossrefxmlfilter::execute', $params);
$fake->noteDeposit('crossrefexportplugin::deposited', array(null, '<r/>', array($subDao->byDoi[$DOI_A])));
ck('a completed file that passes: the completed file is sent, and recorded for the read-back', $f !== $f0 && $fake->asked === 2 && array_key_exists($DOI_A, $fake->deposits(1)));
$fake->updateSetting(1, 'schemaCheck', false, 'bool');
$f = fixture('ojs33-4.3.6.xml'); $f0 = $f; $params = array(&$f);
$fake->completeDeposit('articlecrossrefxmlfilter::execute', $params);
ck('the check switched off by the journal: not run, the completed file sent', $f !== $f0 && $fake->asked === 2);
ck('…and the status window says it is off', strpos($fake->schemaCheckLine(1), 'schemaCheck.off') !== false);
ini_set('error_log', $oldLog === false ? '' : $oldLog); @unlink($log);

// The real check, through OJS's HTTP client, against a STAND-IN for crossref4.3.6.xsd (no public copy of 4.3.6 was found):
// first one that takes any doi_batch, then one that refuses everything — each run exactly as OJS's exportXML runs the filter
// (libxml's internal errors on; any ERROR left in the buffer fails OJS's export).
foreach (array('takes any doi_batch' => '<xsd:any processContents="skip" minOccurs="0" maxOccurs="unbounded"/>',
               'refuses everything' => '<xsd:element name="nothing_matches" type="xsd:string"/>') as $what => $content) {
    $GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-433-' . bin2hex(random_bytes(4));
    Application::$http = new StubHttp();
    Application::$http->handler = function ($url) use ($content) {
        if (substr($url, -strlen('crossref4.3.6.xsd')) !== 'crossref4.3.6.xsd') return new StubResponse(404, '');
        return new StubResponse(200, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.crossref.org/schema/4.3.6" elementFormDefault="qualified">'
            . '<xsd:element name="doi_batch"><xsd:complexType><xsd:sequence>' . $content . '</xsd:sequence><xsd:anyAttribute processContents="skip"/></xsd:complexType></xsd:element></xsd:schema>');
    };
    libxml_use_internal_errors(true); libxml_clear_errors();
    $f = fixture('ojs33-4.3.6.xml'); $f0 = $f;
    HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));
    $last = json_decode((string) $plugin->getSetting(1, 'lastSchemaCheck'), true);
    if ($what === 'takes any doi_batch') {
        ck('inside OJS\'s exportXML, a stand-in schema that ' . $what . ': "valid", the completed file sent, no XML error left for OJS', $last['state'] === 'valid' && $f !== $f0 && libxml_get_errors() === array(), $last);
    } else {
        ck('…a stand-in schema that ' . $what . ': "invalid", OJS\'s own file sent, the buffer left empty', $last['state'] === 'invalid' && $f === $f0 && libxml_get_errors() === array(), $last);
    }
    libxml_use_internal_errors(false);
}
Application::$http = null;

// a slow host: every schema file takes 0.7 s; with a 2-second budget the fetching stops and OJS's request goes on
$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-slow-' . bin2hex(random_bytes(4));
Application::$http = new StubHttp();
Application::$http->handler = function ($url) {
    usleep(700000);
    if (!preg_match('~/(crossref4\.\d\.\d|n(\d+))\.xsd$~', $url, $m)) return new StubResponse(404, '');
    $next = isset($m[2]) && $m[2] !== '' ? (int) $m[2] + 1 : 1;
    return new StubResponse(200, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:include schemaLocation="n' . $next . '.xsd"/></xsd:schema>');
};
$plugin->schemaFetchSeconds = 2;
$t = microtime(true);
$r = $plugin->schemaCheck(fixture('ojs33-4.3.6.xml'));
$took = microtime(true) - $t;
ck(sprintf('a slow host (0.7 s a file, a 2 s budget): fetching stops in %.1f s, "unchecked", and the next deposit carries on from the files kept', $took),
   $took < 3 && $r['state'] === 'unchecked' && strpos($r['why'], 'incomplete') !== false && count(Application::$http->asked) <= 3, array($took, $r, Application::$http->asked));
$plugin->schemaFetchSeconds = CrossrefCompletePlugin::SCHEMA_FETCH_SECONDS;
Application::$http = null;

echo "\n--- switched off, or another journal ---\n";
$plugin->updateSetting(1, 'references', false, 'bool');
$plugin->updateSetting(1, 'affiliations', false, 'bool');
$f = fixture('ojs33-4.3.6.xml'); $f0 = $f;
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));
ck('with both additions switched off the file is OJS\'s own, untouched', $f === $f0);
$plugin->updateSetting(1, 'references', true, 'bool');
$plugin->updateSetting(1, 'affiliations', true, 'bool');
$plugin->enabledContexts = array(2);
$g = fixture('ojs33-4.3.6.xml'); $g0 = $g;
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$g));
ck('a journal where the plugin is not enabled: untouched', $g === $g0);
$plugin->enabledContexts = array(1);

echo "\n--- after the deposit ---\n";
// 1.0.0.4: the last file built for these DOIs was OJS's own (both additions switched off, above), so a deposit now carries
// none of an earlier file's additions — it is not recorded as if it did (before, the earlier file's summary was used)
HookRegistry::call('crossrefexportplugin::deposited', array(null, '<response/>', array($subDao->byDoi[$DOI_A], $subDao->byDoi[$DOI_B])));
ck('a deposit whose file went as OJS\'s own is not recorded with an EARLIER file\'s additions', $plugin->deposits(1) === array(), $plugin->deposits(1));
$f = fixture('ojs33-4.3.6.xml');
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));   // the file OJS builds for the deposit, completed
HookRegistry::call('crossrefexportplugin::deposited', array(null, '<response/>', array($subDao->byDoi[$DOI_A], $subDao->byDoi[$DOI_B])));
$dep = $plugin->deposits(1);
ck('both deposited records remembered, with what each carried', isset($dep[$DOI_A]) && isset($dep[$DOI_B]) && $dep[$DOI_A]['sent']['references'] === 11 && $dep[$DOI_B]['sent']['affiliations'] === 2, $dep);
ck('…not yet checked', $dep[$DOI_A]['checked'] === 0);

echo "\n--- reading the records back ---\n";
$work = json_decode(file_get_contents(__DIR__ . '/fixtures/crossref-work-a.json'), true);
$work['deposited']['date-time'] = gmdate('Y-m-d\TH:i:s\Z', time() + 120);   // Crossref has processed our deposit
Application::$http = new StubHttp();
Application::$http->answers[rawurlencode($DOI_A)] = new StubResponse(200, json_encode(array('status' => 'ok', 'message' => $work)));
$err = $plugin->readBack(1, 'editor@journal.example.org');
$dep = $plugin->deposits(1);
ck('Crossref asked once per record, in the polite pool (mailto)', count(Application::$http->asked) === 2 && strpos(Application::$http->asked[0], 'mailto=editor%40journal.example.org') !== false, Application::$http->asked);
ck('article A: references landed; the affiliation sent is not on the record yet: missing', $dep[$DOI_A]['result']['references'] === 'landed' && $dep[$DOI_A]['result']['affiliations'] === 'missing', $dep[$DOI_A]['result']);
ck('article B (not found at Crossref yet): waiting, never missing', $dep[$DOI_B]['result'] === array('record' => 'waiting'), $dep[$DOI_B]['result']);
ck('no error to show', $err === '');
Application::$http->fail = 'Could not resolve host';
$err = $plugin->readBack(1, '');
ck('Crossref unreachable: one sentence with the reason, and nothing already learnt is lost', strpos($err, 'Could not resolve host') !== false && $plugin->deposits(1)[$DOI_A]['result']['references'] === 'landed', $err);
// Crossref slow (0.7 s an answer) with a 1.5-second budget: the window still opens, one record read, the rest next time
$list = $plugin->deposits(1);
foreach (array('10.5555/slow-1', '10.5555/slow-2', '10.5555/slow-3') as $d) $list[$d] = array('at' => time(), 'sent' => array('references' => 1), 'checked' => 0, 'result' => array());
$plugin->saveDeposits(1, $list);
Application::$http = new StubHttp();
Application::$http->handler = function ($url) { usleep(700000); return new StubResponse(404, '{}'); };
$plugin->readBackSeconds = 1.5;
$t = microtime(true);
$err = $plugin->readBack(1, '');
$took = microtime(true) - $t;
ck(sprintf('Crossref slow: the read-back stops at its budget (%.1f s, %d record read) and says the rest come next time', $took, count(Application::$http->asked)),
   $took < 1.6 && count(Application::$http->asked) === 1 && strpos($err, 'plugins.generic.crossrefComplete.status.slow') === 0, array($took, $err));
$plugin->readBackSeconds = 25;

echo "\n--- OJS's Crossref plugin set to Crossref's test system (as on a member journal's OJS, 3 Oct 2026) ---\n";
// what OJS 3.3.0.21 hands the after-deposit hook: its CrossRef export plugin, which answers getSetting(context, 'testMode')
class StubCrossrefExportPlugin { public $test; function __construct($t) { $this->test = $t; } function getSetting($contextId, $name) { return $name === 'testMode' ? $this->test : null; } }
class StubPluginSettingsDAO {
    public $s; function __construct($s) { $this->s = $s; }
    function getSetting($contextId, $pluginName, $name) { $p = strtolower($pluginName); return isset($this->s[$contextId][$p][$name]) ? $this->s[$contextId][$p][$name] : null; }   // lower-cased, as OJS's own DAO does
}
Application::$http = null;
$plugin->readBackSeconds = 25;
$f = fixture('ojs33-4.3.6.xml');
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));
HookRegistry::call('crossrefexportplugin::deposited', array(new StubCrossrefExportPlugin(1), '<response/>', array($subDao->byDoi[$DOI_B])));
$dep = $plugin->deposits(1);
ck('a deposit made while the test setting is on: listed as a test deposit ("not live"), never left "waiting"', $dep[$DOI_B]['result'] === array('record' => 'test') && !empty($dep[$DOI_B]['test']) && $dep[$DOI_B]['checked'] > 0, $dep[$DOI_B]);
Application::$http = new StubHttp();
$plugin->readBack(1, '');
ck('…and it is never read back from the live Crossref', !preg_grep('~' . preg_quote(rawurlencode($DOI_B), '~') . '~', Application::$http->asked), Application::$http->asked);
HookRegistry::call('crossrefexportplugin::deposited', array(new StubCrossrefExportPlugin(0), '<response/>', array($subDao->byDoi[$DOI_B])));
$dep = $plugin->deposits(1);
ck('with the test setting off, the same deposit is read back as before', $dep[$DOI_B]['checked'] === 0 && empty($dep[$DOI_B]['test']), $dep[$DOI_B]);
DAORegistry::$daos['PluginSettingsDAO'] = new StubPluginSettingsDAO(array(1 => array('crossrefexportplugin' => array('testMode' => 1))));
ck('the status window knows the journal\'s Crossref plugin is on its test setting (and another journal\'s is not)', $plugin->crossrefTestMode(1) === true && $plugin->crossrefTestMode(2) === false);
unset(DAORegistry::$daos['PluginSettingsDAO']);
ck('no plugin settings to read: not called test', $plugin->crossrefTestMode(1) === false);
Application::$http = null;

echo "\n--- a file with nothing in OJS to add (a member journal's articles, 3 Oct 2026) ---\n";
$empty = new StubPublication(13, array('locale' => 'en_US', 'authors' => array(new StubAuthor(array('given' => 'Kai L. Smith', 'family' => '', 'affiliation' => array())))));
$subDao->byDoi[$DOI_A] = new StubSubmission(1, $empty, $DOI_A);
$subDao->byDoi[$DOI_B] = new StubSubmission(1, $empty, $DOI_B);
$f = fixture('ojs33-4.3.6.xml'); $f0 = $f;
HookRegistry::call('articlecrossrefxmlfilter::execute', array(&$f));
$lf = json_decode((string) $plugin->getSetting(1, 'lastFile'), true);
list($head, $rows) = $plugin->lastFileLines(1);
ck('nothing added: OJS\'s own file goes, and the window still shows the plugin ran, and why nothing was added', $f === $f0 && $lf['outcome'] === 'nothing' && strpos($head, 'status.lastFile.nothing') !== false
   && strpos($rows[0], 'plugins.generic.crossrefComplete.status.lastFile.notAdded') === 0 && strpos($rows[0], 'references: none entered in OJS; affiliations: none entered in OJS') !== false, array($lf, $head, $rows));
list($head, $rows) = $plugin->lastFileLines(7);
ck('a journal with no file yet: the window says how to get one', $head === 'plugins.generic.crossrefComplete.status.lastFile.none' && $rows === array(), array($head, $rows));
done();
