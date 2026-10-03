<?php
/**
 * The OJS 3.4 / 3.5 plugin's own code against stand-ins for OJS (tests/ojs34_stubs.php) — the same story as the 3.3
 * test: hooks added, the right journal found for a file deposited from a queued job (no journal in the request), the
 * completed file handed back through the hook's reference, the deposit remembered, the records read back.
 * Included by test_adapter34.php and test_adapter35.php with $LINE set.
 */
require __DIR__ . '/_ck.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/build.php') . ' --quiet', $rc);
if ($rc !== 0) { ck('the plugin assembles', false, 'tools/build.php exit ' . $rc); done(); }
$GLOBALS['OJS_LINE'] = $LINE;
require __DIR__ . '/ojs34_stubs.php';
$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-' . bin2hex(random_bytes(4));   // OJS's files directory
$dir = __DIR__ . "/../build/ojs-$LINE/crossrefComplete";
require $dir . '/CrossrefCompletePlugin.php';
use APP\plugins\generic\crossrefComplete\CrossrefCompletePlugin;
use PKP\plugins\Hook;

$DOI_A = '10.5555/ejt.2025.v10.i12.pp35-41';
$DOI_B = '10.5555/ejt.2020.v5.i3.pp15-18';
$refs = json_decode(file_get_contents(__DIR__ . '/fixtures/references-a.json'), true)['references'];
$fixture = $LINE === '3.4' ? 'ojs34-5.3.1.xml' : 'ojs35-5.4.0.xml';

// what OJS holds, in this line's shapes — the affiliations and ROR IDs are test values
if ($LINE === '3.4') {
    $authorsA = [new StubAuthor(['given' => 'Mira', 'family' => 'Sample', 'affiliation' => 'Test College of Food Technology (test value)', 'rorId' => 'https://ror.org/0abcdef12'])];
    $authorsB = [
        new StubAuthor(['given' => 'Leo K.', 'family' => 'Tester', 'affiliation' => 'Test Institute (test value)']),
        new StubAuthor(['given' => 'Ana R.', 'family' => 'Example']),
        new StubAuthor(['given' => 'Ravi T', 'family' => 'Example', 'affiliation' => 'Second Test University (test value)', 'rorId' => '0zyxwvu98']),
    ];
} else {
    $authorsA = [new StubAuthor(['given' => 'Mira', 'family' => 'Sample', 'affiliations' => [new StubAffiliation('Test College of Food Technology (test value)', 'https://ror.org/0abcdef12')]])];
    $authorsB = [
        new StubAuthor(['given' => 'Leo K.', 'family' => 'Tester', 'affiliations' => [new StubAffiliation('Test Institute of Technology (test value)', 'https://ror.org/0abcdef12')]]),
        new StubAuthor(['given' => 'Ana R.', 'family' => 'Example']),
        new StubAuthor(['given' => 'Ravi T', 'family' => 'Example', 'affiliations' => [new StubAffiliation('Second Test University (test value)', null)]]),
    ];
}
$repo = new StubSubmissionRepo();
$repo->byDoi[$DOI_A] = new StubSubmission(2, new StubPublication(21, ['locale' => 'en', 'authors' => $authorsA]), $DOI_A);
$repo->byDoi[$DOI_B] = new StubSubmission(2, new StubPublication(22, ['locale' => 'en', 'authors' => $authorsB, 'citationsRaw' => "[1] Only line, https://doi.org/10.1000/ABC-1."]), $DOI_B);
APP\facades\Repo::$submissions = $repo;
$cit = new StubCitationDAO(); $cit->byPub[21] = $refs;
PKP\db\DAORegistry::$daos = ['CitationDAO' => $cit];

echo "--- registering (OJS $LINE) ---\n";
$plugin = new CrossrefCompletePlugin();
$plugin->enabledContexts = [2];
ck('register() succeeds', $plugin->register('generic', 'plugins/generic/crossrefComplete', null) === true);
ck('the deposit-file hook runs LAST, after the Funding plugin', isset(Hook::$hooks['articlecrossrefxmlfilter::execute'][Hook::SEQUENCE_LAST]));
ck('the after-deposit hook is added', isset(Hook::$hooks['crossrefexportplugin::deposited']));

echo "\n--- a file deposited from a queued job: no journal in the request ---\n";
$preliminaryOutput = fixture($fixture);
$orig = $preliminaryOutput;
Hook::call('articlecrossrefxmlfilter::execute', [&$preliminaryOutput]);
ck('journal 2 found by asking each journal on this OJS for the DOI (journal 1 first, then 2)', in_array([$DOI_A, 1], $repo->calls, true) && in_array([$DOI_A, 2], $repo->calls, true), $repo->calls);
ck('OJS\'s variable holds the completed file (by-reference hand-back)', $preliminaryOutput !== $orig);
$x = xp($preliminaryOutput);
ck('article A: 11 references', $x->evaluate('count((//cr:journal_article)[1]/cr:citation_list/cr:citation)') == 11);
ck('article B: the raw block used — numbering dropped, the doi.org link\'s DOI asserted', $x->evaluate('string((//cr:journal_article)[2]/cr:citation_list/cr:citation[1]/cr:doi)') === '10.1000/abc-1'
   && $x->evaluate('string((//cr:journal_article)[2]/cr:citation_list/cr:citation[1]/cr:unstructured_citation)') === 'Only line, https://doi.org/10.1000/ABC-1.');
if ($LINE === '3.4') {
    ck('3.4: article A\'s author gets <affiliations> with the ROR ID the ROR plugin stored', $x->evaluate('string((//cr:journal_article)[1]//cr:person_name[1]//cr:institution_id[@type="ror"])') === 'https://ror.org/0abcdef12');
    ck('3.4: a bare ROR ID ("0zyxwvu98") is written in Crossref\'s form', $x->evaluate('string((//cr:journal_article)[2]//cr:person_name[3]//cr:institution_id)') === 'https://ror.org/0zyxwvu98');
    ck('3.4: an author with no affiliation in OJS gets none', $x->evaluate('count((//cr:journal_article)[2]//cr:person_name[2]/cr:affiliations)') == 0);
} else {
    ck('3.5: OJS already wrote author 1\'s affiliation — exactly one, OJS\'s own', $x->evaluate('count((//cr:journal_article)[2]//cr:person_name[1]/cr:affiliations)') == 1);
    ck('3.5: authors OJS wrote without one get the one OJS holds (A\'s author with ROR, B\'s third without)', $x->evaluate('string((//cr:journal_article)[1]//cr:person_name[1]//cr:institution_id)') === 'https://ror.org/0abcdef12'
       && $x->evaluate('string((//cr:journal_article)[2]//cr:person_name[3]//cr:institution_name)') === 'Second Test University (test value)'
       && $x->evaluate('count((//cr:journal_article)[2]//cr:person_name[3]//cr:institution_id)') == 0);
}
ck('the finished file breaks no order rule', array_merge(...array_map(fn ($a) => CcrSchema::articleProblems($a), CcrSchema::articles($preliminaryOutput))) === []);

echo "\n--- the status window's account of the last file OJS built (1.0.0.2) ---\n";
$lf = json_decode((string) $plugin->getSetting(2, 'lastFile'), true);
ck('the file just completed is remembered: used, 2 articles, what each got', is_array($lf) && $lf['outcome'] === 'completed' && $lf['count'] === 2 && $lf['articles'][$DOI_A]['references'] === 11, $lf);
[$head, $rows] = $plugin->lastFileLines(2);
ck('…the window: a heading line, then one line per article', str_contains($head, 'status.lastFile.completed') && count($rows) === 2 && str_starts_with($rows[0], 'plugins.generic.crossrefComplete.status.lastFile.added'), [$head, $rows]);

echo "\n--- the check against Crossref's schema before the file goes ---\n";
$last = json_decode((string) $plugin->getSetting(2, 'lastSchemaCheck'), true);
ck('no copy of the schema and none to be had: "unchecked", and why — the completed file still went (it passed the order rules)',
   is_array($last) && $last['state'] === 'unchecked' && str_contains($last['why'], 'incomplete') && $preliminaryOutput !== $orig, $last);
ck('…the status window says it in one sentence', str_starts_with($plugin->schemaCheckLine(2), 'plugins.generic.crossrefComplete.status.schemaCheck.unchecked'), $plugin->schemaCheckLine(2));
ck('…the copy of the schema is kept inside OJS\'s files directory', $plugin->schemaCacheDir() === $GLOBALS['OJS_FILES_DIR'] . '/crossrefComplete/schemas');
$fake = new class extends CrossrefCompletePlugin {
    public $answer; public int $asked = 0;
    public function schemaCheck(\DOMDocument $doc): array { $this->asked++; return $this->answer; }
};
$fake->enabledContexts = [2];
$log = tempnam(sys_get_temp_dir(), 'ccrlog'); $oldLog = ini_set('error_log', $log);
$fake->answer = ['state' => 'invalid', 'version' => '5.3.1', 'errors' => ["line 1: Element 'x': This element is not expected."], 'why' => '', 'files' => 21];
$f = fixture($fixture); $f0 = $f; $params = [&$f];
$fake->completeDeposit('articlecrossrefxmlfilter::execute', $params);
ck('a completed file that does NOT pass Crossref\'s schema: OJS\'s own file is sent, exactly as OJS made it', $f === $f0 && $fake->asked === 1);
ck('…the reason goes to the error log', str_contains((string) file_get_contents($log), "did not pass Crossref's schema 5.3.1"), file_get_contents($log));
$fake->noteDeposit('crossrefexportplugin::deposited', [null, '<r/>', [$repo->byDoi[$DOI_A]]]);
ck('…and the deposit is not recorded as carrying additions it did not carry', !array_key_exists($DOI_A, $fake->deposits(2)));
$fake->answer = ['state' => 'valid', 'version' => '5.3.1', 'errors' => [], 'why' => '', 'files' => 21];
$f = fixture($fixture); $f0 = $f; $params = [&$f];
$fake->completeDeposit('articlecrossrefxmlfilter::execute', $params);
$fake->noteDeposit('crossrefexportplugin::deposited', [null, '<r/>', [$repo->byDoi[$DOI_A]]]);
ck('a completed file that passes: the completed file is sent, and recorded for the read-back', $f !== $f0 && $fake->asked === 2 && array_key_exists($DOI_A, $fake->deposits(2)));
$fake->updateSetting(2, 'schemaCheck', false, 'bool');
$f = fixture($fixture); $f0 = $f; $params = [&$f];
$fake->completeDeposit('articlecrossrefxmlfilter::execute', $params);
ck('the check switched off by the journal: not run, the completed file sent; the status window says it is off', $f !== $f0 && $fake->asked === 2 && str_contains($fake->schemaCheckLine(2), 'schemaCheck.off'));
ini_set('error_log', $oldLog === false ? '' : $oldLog); @unlink($log);
$copy = (string) getenv('CR_SCHEMA_CACHE');
if ($copy !== '' && is_file("$copy/www.crossref.org/schemas/crossref5.3.1.xsd")) {
    // the plugin's own fetch, through OJS's HTTP client, answered from a copy of crossref.org and w3.org
    APP\core\Application::$http = new StubHttp();
    APP\core\Application::$http->handler = function ($url) use ($copy) {
        $p = \CcrValidator::localPath($copy, $url);
        return is_file($p) ? new StubResponse(200, file_get_contents($p)) : new StubResponse(404, '');
    };
    $f = fixture($fixture); $f0 = $f;
    Hook::call('articlecrossrefxmlfilter::execute', [&$f]);
    $last = json_decode((string) $plugin->getSetting(2, 'lastSchemaCheck'), true);
    $v = $LINE === '3.4' ? '5.3.1' : '5.4.0';
    ck("with Crossref's schema reachable: the completed file passes $v and is sent (" . count(APP\core\Application::$http->asked) . ' schema files fetched once into OJS\'s files directory)',
       $f !== $f0 && $last['state'] === 'valid' && $last['version'] === $v && is_file($plugin->schemaCacheDir() . "/www.crossref.org/schemas/crossref$v.xsd"), $last);
    APP\core\Application::$http->asked = [];
    $g = fixture($fixture);
    Hook::call('articlecrossrefxmlfilter::execute', [&$g]);
    ck('…the next deposit reads the kept copy: nothing fetched again', APP\core\Application::$http->asked === [] && json_decode((string) $plugin->getSetting(2, 'lastSchemaCheck'), true)['state'] === 'valid');
    // exactly as OJS's exportXML runs the filter: libxml's internal errors on, then any ERROR left in the buffer fails the export
    libxml_use_internal_errors(true); libxml_clear_errors();
    $g = fixture($fixture);
    Hook::call('articlecrossrefxmlfilter::execute', [&$g]);
    ck('inside OJS\'s exportXML: a completed file that passes leaves no XML error behind for OJS to fail the export on', libxml_get_errors() === [] && json_decode((string) $plugin->getSetting(2, 'lastSchemaCheck'), true)['state'] === 'valid');
    $h = fixture($fixture);
    $bogus = $h->createElementNS($h->documentElement->namespaceURI, 'not_in_the_schema');
    $art = $h->getElementsByTagNameNS($h->documentElement->namespaceURI, 'journal_article')->item(0);
    $art->insertBefore($bogus, $art->firstChild);   // OJS's own file, with an element Crossref's schema does not know
    $h0 = $h;
    Hook::call('articlecrossrefxmlfilter::execute', [&$h]);
    ck('…one that does NOT pass: OJS\'s own file goes back, and the buffer is left empty — what OJS then finds is about its own file only',
       $h === $h0 && libxml_get_errors() === [] && json_decode((string) $plugin->getSetting(2, 'lastSchemaCheck'), true)['state'] === 'invalid', json_decode((string) $plugin->getSetting(2, 'lastSchemaCheck'), true));
    libxml_use_internal_errors(false);
    APP\core\Application::$http = null;
} else {
    echo "  SKIPPED — the check with Crossref's schema reachable (set CR_SCHEMA_CACHE; tests/schema_copies.sh). Not counted as a pass.\n";
}

// a slow host: every schema file takes 0.7 s; with a 2-second budget the fetching stops and OJS's request goes on
$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-slow-' . bin2hex(random_bytes(4));
APP\core\Application::$http = new StubHttp();
APP\core\Application::$http->handler = function ($url) {
    usleep(700000);
    if (!preg_match('~/(crossref5\.\d\.\d|n(\d+))\.xsd$~', $url, $m)) return new StubResponse(404, '');
    $next = isset($m[2]) && $m[2] !== '' ? (int) $m[2] + 1 : 1;
    return new StubResponse(200, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:include schemaLocation="n' . $next . '.xsd"/></xsd:schema>');
};
$plugin->schemaFetchSeconds = 2;
$t = microtime(true);
$r = $plugin->schemaCheck(fixture($fixture));
$took = microtime(true) - $t;
ck(sprintf('a slow host (0.7 s a file, a 2 s budget): fetching stops in %.1f s, "unchecked", and the next deposit carries on from the %d files kept', $took, count(glob($plugin->schemaCacheDir() . '/www.crossref.org/schemas/*.xsd'))),
   $took < 3 && $r['state'] === 'unchecked' && str_contains($r['why'], 'incomplete') && count(APP\core\Application::$http->asked) <= 3, [$took, $r, APP\core\Application::$http->asked]);
$plugin->schemaFetchSeconds = CrossrefCompletePlugin::SCHEMA_FETCH_SECONDS;
APP\core\Application::$http = null;

echo "\n--- not enabled for that journal ---\n";
$plugin->enabledContexts = [1];
$f = fixture($fixture); $f0 = $f;
Hook::call('articlecrossrefxmlfilter::execute', [&$f]);
ck('untouched', $f === $f0);
$plugin->enabledContexts = [2];

echo "\n--- after the deposit ---\n";
Hook::run('crossrefexportplugin::deposited', [[null, '<r/>', [$repo->byDoi[$DOI_A], $repo->byDoi[$DOI_B]]]]);
$dep = $plugin->deposits(2);
ck('both records remembered under journal 2 with what each carried', isset($dep[$DOI_A], $dep[$DOI_B]) && $dep[$DOI_A]['sent']['references'] === 11, $dep);

echo "\n--- read-back ---\n";
$work = json_decode(file_get_contents(__DIR__ . '/fixtures/crossref-work-a.json'), true);
$work['deposited']['date-time'] = gmdate('Y-m-d\TH:i:s\Z', time() + 120);
APP\core\Application::$http = new StubHttp();
APP\core\Application::$http->answers[rawurlencode($DOI_A)] = new StubResponse(200, json_encode(['message' => $work]));
$err = $plugin->readBack(2, 'editor@journal.example.org');
$dep = $plugin->deposits(2);
ck('references landed; the affiliation/ROR sent but not on the record: missing; B waiting', $dep[$DOI_A]['result']['references'] === 'landed' && $dep[$DOI_A]['result']['affiliations'] === 'missing' && $dep[$DOI_A]['result']['ror'] === 'missing' && $dep[$DOI_B]['result'] === ['record' => 'waiting'] && $err === '', $dep);
// Crossref slow (0.7 s an answer) with a 1.5-second budget: the window still opens, one record read, the rest next time
$list = $plugin->deposits(2);
foreach (['10.5555/slow-1', '10.5555/slow-2', '10.5555/slow-3'] as $d) $list[$d] = ['at' => time(), 'sent' => ['references' => 1], 'checked' => 0, 'result' => []];
$plugin->saveDeposits(2, $list);
APP\core\Application::$http = new StubHttp();
APP\core\Application::$http->handler = function ($url) { usleep(700000); return new StubResponse(404, '{}'); };
$plugin->readBackSeconds = 1.5;
$t = microtime(true);
$err = $plugin->readBack(2, '');
$took = microtime(true) - $t;
ck(sprintf('Crossref slow: the read-back stops at its budget (%.1f s, %d record read) and says the rest come next time', $took, count(APP\core\Application::$http->asked)),
   $took < 1.6 && count(APP\core\Application::$http->asked) === 1 && str_starts_with($err, 'plugins.generic.crossrefComplete.status.slow'), [$took, $err]);
$plugin->readBackSeconds = 25;

echo "\n--- OJS's Crossref plugin set to Crossref's test system ---\n";
// what OJS hands the after-deposit hook: its Crossref export plugin, whose getSetting reads the agency plugin's "Testing"
$testPlugin = new class { public $test = 1; public function getSetting($contextId, $name) { return $name === 'testMode' ? $this->test : null; } };
$settingsDao = new class { public array $s = []; public function getSetting($contextId, $pluginName, $name) { return $this->s[$contextId][strtolower($pluginName)][$name] ?? null; } };
APP\core\Application::$http = null;
$plugin->readBackSeconds = 25;
$f = fixture($fixture);
Hook::call('articlecrossrefxmlfilter::execute', [&$f]);
Hook::run('crossrefexportplugin::deposited', [[$testPlugin, '<r/>', [$repo->byDoi[$DOI_B]]]]);
$dep = $plugin->deposits(2);
ck('a deposit made while "Testing" is on: listed as a test deposit ("not live"), never left "waiting"', $dep[$DOI_B]['result'] === ['record' => 'test'] && !empty($dep[$DOI_B]['test']) && $dep[$DOI_B]['checked'] > 0, $dep[$DOI_B]);
APP\core\Application::$http = new StubHttp();
$plugin->readBack(2, '');
ck('…and it is never read back from the live Crossref', !preg_grep('~' . preg_quote(rawurlencode($DOI_B), '~') . '~', APP\core\Application::$http->asked), APP\core\Application::$http->asked);
$testPlugin->test = 0;
Hook::run('crossrefexportplugin::deposited', [[$testPlugin, '<r/>', [$repo->byDoi[$DOI_B]]]]);
ck('with "Testing" off, the same deposit is read back as before', $plugin->deposits(2)[$DOI_B]['checked'] === 0 && empty($plugin->deposits(2)[$DOI_B]['test']));
$settingsDao->s = [2 => ['crossrefplugin' => ['testMode' => true]]];
PKP\db\DAORegistry::$daos['PluginSettingsDAO'] = $settingsDao;
ck('the status window knows this journal\'s Crossref plugin is on "Testing" (and another journal\'s is not)', $plugin->crossrefTestMode(2) === true && $plugin->crossrefTestMode(1) === false);
unset(PKP\db\DAORegistry::$daos['PluginSettingsDAO']);
ck('no plugin settings to read: not called test', $plugin->crossrefTestMode(2) === false);
APP\core\Application::$http = null;

echo "\n--- a file with nothing in OJS to add (a member journal's articles, 3 Oct 2026) ---\n";
$emptyPub = new StubPublication(23, ['locale' => 'en', 'authors' => [new StubAuthor(['given' => 'Kai L. Smith', 'family' => ''])]]);
$repo->byDoi[$DOI_A] = new StubSubmission(2, $emptyPub, $DOI_A);
$repo->byDoi[$DOI_B] = new StubSubmission(2, $emptyPub, $DOI_B);
$f = fixture($fixture); $f0 = $f;
Hook::call('articlecrossrefxmlfilter::execute', [&$f]);
$lf = json_decode((string) $plugin->getSetting(2, 'lastFile'), true);
[$head, $rows] = $plugin->lastFileLines(2);
ck('nothing added: OJS\'s own file goes, and the window still shows the plugin ran, and why', $f === $f0 && $lf['outcome'] === 'nothing' && str_contains($head, 'status.lastFile.nothing')
   && str_contains($rows[0], 'references: none entered in OJS; affiliations: none entered in OJS'), [$lf, $head, $rows]);
[$head, $rows] = $plugin->lastFileLines(7);
ck('a journal with no file yet: the window says how to get one', $head === 'plugins.generic.crossrefComplete.status.lastFile.none' && $rows === []);
done();
