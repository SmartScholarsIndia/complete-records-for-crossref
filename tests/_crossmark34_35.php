<?php
/**
 * The OJS 3.4 / 3.5 plugin and Crossref's Crossmark button (release 1.0.0.4) against stand-ins for OJS
 * (tests/ojs34_stubs.php) — the same story as test_crossmark33.php. Included by test_crossmark34.php and
 * test_crossmark35.php with $LINE set. The plugin is assembled exactly as it ships and those files are run.
 */
require __DIR__ . '/_ck.php';
foreach (['http_proxy','https_proxy','HTTP_PROXY','HTTPS_PROXY','all_proxy','ALL_PROXY'] as $pv) putenv("$pv=http://127.0.0.1:9"); foreach (['no_proxy','NO_PROXY'] as $pv) putenv("$pv=127.0.0.1,localhost");   // nothing here may reach the network
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/build.php') . ' --quiet', $rc);
if ($rc !== 0) { ck('the plugin assembles', false, 'tools/build.php exit ' . $rc); done(); }
$GLOBALS['OJS_LINE'] = $LINE;
require __DIR__ . '/ojs34_stubs.php';
$GLOBALS['OJS_FILES_DIR'] = sys_get_temp_dir() . '/ccr-files-cm' . str_replace('.', '', $LINE) . '-' . bin2hex(random_bytes(4));
$dir = __DIR__ . "/../build/ojs-$LINE/crossrefComplete";
require $dir . '/CrossrefCompletePlugin.php';
use APP\plugins\generic\crossrefComplete\CrossrefCompletePlugin;
use APP\template\TemplateManager;
use PKP\plugins\Hook;

class CmRequest { public function __construct(private $c) {} public function getContext() { return $this->c; } }
class CmExport { public function __construct(private $t) {} public function getSetting($c, $n) { return $n === 'testMode' ? $this->t : null; } }

ck('release 1.0.0.6', (string) simplexml_load_file("$dir/version.xml")->release === '1.0.0.6');
$DOI_A = '10.5555/ejt.2025.v10.i12.pp35-41';
$DOI_B = '10.5555/ejt.2020.v5.i3.pp15-18';
$POLICY = '10.5555/example.crossmark';
$fixture = $LINE === '3.4' ? 'ojs34-5.3.1.xml' : 'ojs35-5.4.0.xml';
$pubA = new StubPublication(21, ['locale' => 'en', 'doi' => $DOI_A, 'authors' => []]);
$pubB = new StubPublication(22, ['locale' => 'en', 'doi' => $DOI_B, 'authors' => []]);
$repo = new StubSubmissionRepo();
$repo->byDoi[$DOI_A] = new StubSubmission(2, $pubA, $DOI_A);
$repo->byDoi[$DOI_B] = new StubSubmission(2, $pubB, $DOI_B);
APP\facades\Repo::$submissions = $repo;
PKP\db\DAORegistry::$daos = ['CitationDAO' => new StubCitationDAO()];

$plugin = new CrossrefCompletePlugin();
$plugin->enabledContexts = [2];
$plugin->register('generic', 'plugins/generic/crossrefComplete', null);
ck('the article page hook is added', isset(Hook::$hooks['ArticleHandler::view']));
$plugin->updateSetting(2, 'schemaCheck', false, 'bool');
$plugin->updateSetting(2, 'crossmarkPolicy', $POLICY, 'string');

function articlePage($plugin, $contextId, $publication, $mail = ''): array {
    TemplateManager::$last = null;
    $request = new CmRequest(new StubContext($contextId, $mail)); $issue = null; $article = null;
    $shown = Hook::call('ArticleHandler::view', [&$request, &$issue, &$article, $publication]);
    $tm = TemplateManager::getManager(null);
    $html = '<!DOCTYPE html><html><head><title>t</title></head><body><header><div class="pkp_site_name">A journal</div></header>'
          . '<article class="obj_article_details"><h1 class="page_title">The article</h1><div class="row">…</div></article>'
          . '<div class="pkp_block"><h2>Information</h2></div></body></html>';
    foreach ($tm->filters as $f) $html = call_user_func($f[1], $html, $tm);
    return [$shown, $tm, $html];
}

echo "--- before any deposit with Crossmark (OJS $LINE) ---\n";
[$shown, $tm, $html] = articlePage($plugin, 2, $pubA);
ck('OJS still shows its page', $shown === Hook::CONTINUE);
ck('no button, no tag', !$tm->headers && !str_contains($html, 'crossmark'));

echo "\n--- a live deposit that carried Crossmark ---\n";
$f = fixture($fixture);
Hook::call('articlecrossrefxmlfilter::execute', [&$f]);
ck('the completed file carries Crossmark with the policy DOI', xp($f)->evaluate('count(//cr:crossmark_policy[.="' . $POLICY . '"])') == 2);
Hook::call('crossrefexportplugin::deposited', [new CmExport(0), '<response/>', [$repo->byDoi[$DOI_A]]]);
ck('the deposit leaves its note in OJS\'s files directory', is_file(\CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 2, $DOI_A)));
[$shown, $tm, $html] = articlePage($plugin, 2, $pubA);
ck('the <head> gets the tag the widget reads', ($tm->headers['crossrefCompleteCrossmark'] ?? '') === \CcrCrossmark::metaTag($DOI_A), $tm->headers);
ck('the button sits right under the article\'s title, once', str_contains($html, '<h1 class="page_title">The article</h1>' . \CcrCrossmark::button() . '<div class="row">') && substr_count($html, 'data-target="crossmark"') === 1, $html);
ck('…and the filter took itself off', $tm->filters === []);

echo "\n--- where it never shows ---\n";
Hook::call('articlecrossrefxmlfilter::execute', [&$f]);
Hook::call('crossrefexportplugin::deposited', [new CmExport(1), '<response/>', [$repo->byDoi[$DOI_B]]]);
[, $tm, $html] = articlePage($plugin, 2, $pubB);
ck('a deposit to Crossref\'s test system: no note, no button', !is_file(\CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 2, $DOI_B)) && !$tm->headers && !str_contains($html, 'crossmark'));
[, $tm, $html] = articlePage($plugin, 2, new StubPublication(23, ['doi' => null]));
ck('an article with no DOI: nothing', !$tm->headers && !str_contains($html, 'crossmark'));
[, $tm, $html] = articlePage($plugin, 1, $pubA);
ck('a journal where the plugin is not enabled: nothing', !$tm->headers && !str_contains($html, 'crossmark'));
$plugin->updateSetting(2, 'crossmarkPolicy', '', 'string');
[, $tm, $html] = articlePage($plugin, 2, $pubA);
ck('Crossmark policy DOI emptied: nothing', !$tm->headers && !str_contains($html, 'crossmark'));
$g = fixture($fixture);
Hook::call('articlecrossrefxmlfilter::execute', [&$g]);
Hook::call('crossrefexportplugin::deposited', [new CmExport(0), '<response/>', [$repo->byDoi[$DOI_A]]]);
ck('a later live deposit without Crossmark: the note goes', !is_file(\CcrCrossmark::markerPath($GLOBALS['OJS_FILES_DIR'], 2, $DOI_A)));
$plugin->updateSetting(2, 'crossmarkPolicy', $POLICY, 'string');
[, $tm, $html] = articlePage($plugin, 2, $pubA);
ck('…so with the policy set again it shows no button until it goes to Crossref with Crossmark', !$tm->headers && !str_contains($html, 'crossmark'));

echo "\n--- the deposits kept, and a database error ---\n";
$big = [];
for ($i = 0; $i < 600; $i++) $big['10.1/x.' . $i] = ['at' => $i, 'checked' => 0, 'result' => [], 'sent' => ['references' => 53, 'crossmark' => $POLICY]];
$plugin->saveDeposits(2, $big);
ck('never more than ' . CrossrefCompletePlugin::MAX_DEPOSITS . ' kept, newest first', count($plugin->deposits(2)) === CrossrefCompletePlugin::MAX_DEPOSITS && array_key_first($plugin->deposits(2)) === '10.1/x.0');
$failing = new class extends CrossrefCompletePlugin { public function updateSetting($c, $n, $v, $t = null) { throw new \Exception('Data too long'); } };
$log = tempnam(sys_get_temp_dir(), 'ccrlog'); $old = ini_set('error_log', $log);
$threw = false;
try { $failing->saveDeposits(2, ['10.1/x' => ['at' => 1]]); } catch (\Throwable $e) { $threw = true; }
ck('a database error never reaches OJS\'s deposit; it goes to the error log', !$threw && str_contains((string) file_get_contents($log), 'could not be kept'));
ini_set('error_log', $old === false ? '' : $old); @unlink($log);

echo "\n--- 1.0.0.6: a journal whose records reach Crossref another way (a sponsor registers its DOIs, another service deposits the metadata) ---\n";
// Article C never went through the plugin: no note, nothing in the read-back's record. Crossref's own record of it is the
// stand-in below; the journal's contact e-mail puts the read in Crossref's polite pool, as the read-back's reads are.
$DOI_C = '10.5555/ejt.2019.v4.i1.pp1-7';
$MAIL = 'editor@journal.example.org';
$pubC = new StubPublication(26, ['locale' => 'en', 'doi' => $DOI_C, 'authors' => []]);
$record = function ($policy) use ($DOI_C) {
    $m = ['DOI' => $DOI_C, 'type' => 'journal-article', 'title' => ['An article registered by the sponsor']];
    if ($policy !== null) $m['update-policy'] = $policy;
    return new StubResponse(200, json_encode(['status' => 'ok', 'message-type' => 'work', 'message' => $m]));
};
$recordPath = fn ($doi) => \CcrCrossmark::recordPath($GLOBALS['OJS_FILES_DIR'], 2, $doi);
/** A new request to OJS: a new plugin instance with the journal's settings, the only one hooked in. */
function fresh(&$plugin): void {
    $next = new CrossrefCompletePlugin();
    $next->settings = $plugin->settings; $next->enabledContexts = $plugin->enabledContexts;
    Hook::$hooks = [];
    $next->register('generic', 'plugins/generic/crossrefComplete', null);
    $plugin = $next;
}
fresh($plugin);
$plugin->updateSetting(2, 'crossmarkPolicy', $POLICY, 'string');
APP\core\Application::$http = new StubHttp();
APP\core\Application::$http->answers[rawurlencode($DOI_C)] = $record('http://dx.doi.org/' . strtoupper($POLICY));   // as Crossref's API gives it: a doi.org address, its own case
$http = fn () => APP\core\Application::$http;

ck('the choice is off unless the journal switched it on', $plugin->crossmarkByRecordOn(2) === false);
[, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('off (as before 1.0.0.6): an article the plugin never deposited shows no button, and Crossref is not asked', !$tm->headers && !str_contains($html, 'crossmark') && $http()->asked === [], $http()->asked);

$plugin->updateSetting(2, 'crossmarkByRecord', true, 'bool');
fresh($plugin);
[$shown, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('on, and Crossref\'s record names the journal\'s policy (given as dx.doi.org, upper case): the button, right under the title, once — exactly as 1.0.0.4 places it',
   str_contains($html, '<h1 class="page_title">The article</h1>' . \CcrCrossmark::button() . '<div class="row">') && substr_count($html, 'data-target="crossmark"') === 1, $html);
ck('…the tag the widget reads, in <head>', ($tm->headers['crossrefCompleteCrossmark'] ?? '') === \CcrCrossmark::metaTag($DOI_C), $tm->headers);
ck('…OJS still shows its page; the filter took itself off', $shown === Hook::CONTINUE && $tm->filters === []);
$asked = $http()->asked; $opts = $http()->options;
ck('Crossref asked once, for this DOI, in the polite pool (mailto), as the read-back asks', count($asked) === 1 && $asked[0] === 'https://api.crossref.org/works/' . rawurlencode($DOI_C) . '?mailto=' . rawurlencode($MAIL), $asked);
$release = (string) simplexml_load_file("$dir/version.xml")->release;
ck('…naming the plugin and its release in the User-Agent, with the journal\'s contact (Crossref\'s etiquette)',
   ($opts[0]['headers']['User-Agent'] ?? '') === 'crossrefComplete/' . $release . ' (https://github.com/SmartScholarsIndia/complete-records-for-crossref; mailto:' . $MAIL . ')'
   && ($opts[0]['headers']['Accept'] ?? '') === 'application/json', $opts[0] ?? null);
ck('…with a short wait: timeout ' . CrossrefCompletePlugin::RECORD_READ_SECONDS . ' s, connect_timeout 2 s, no exception on an HTTP error',
   ($opts[0]['timeout'] ?? 0) == CrossrefCompletePlugin::RECORD_READ_SECONDS && ($opts[0]['connect_timeout'] ?? 0) == 2 && ($opts[0]['http_errors'] ?? true) === false && CrossrefCompletePlugin::RECORD_READ_SECONDS <= 5, $opts[0] ?? null);
ck('…the answer is kept in OJS\'s files directory, beside the notes', is_file($recordPath($DOI_C)) && \CcrCrossmark::readRecord($recordPath($DOI_C))['found'] === strtolower($POLICY), @file_get_contents($recordPath($DOI_C)));
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('the next view of the article (a new request): the button again, and Crossref NOT asked again (the answer kept is used)', substr_count($html, 'data-target="crossmark"') === 1 && count($http()->asked) === 1, $http()->asked);
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubC, '');
ck('a journal with no contact e-mail set: the kept answer still serves', substr_count($html, 'data-target="crossmark"') === 1 && count($http()->asked) === 1);
$plugin->updateSetting(2, 'crossmarkByRecord', false, 'bool');
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('the choice switched off again: no button, even with Crossref\'s answer kept — the journal\'s choice governs', !$tm->headers && !str_contains($html, 'crossmark'));
$plugin->updateSetting(2, 'crossmarkByRecord', true, 'bool');
$plugin->updateSetting(2, 'crossmarkPolicy', '', 'string');
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('no Crossmark policy DOI set: no button, nothing asked', !$tm->headers && !str_contains($html, 'crossmark') && count($http()->asked) === 1);
$plugin->updateSetting(2, 'crossmarkPolicy', $POLICY, 'string');
$plugin->enabledContexts = [1];
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('the plugin not enabled for the journal: nothing, nothing asked', !$tm->headers && !str_contains($html, 'crossmark') && count($http()->asked) === 1);
$plugin->enabledContexts = [2];
fresh($plugin);

echo "\n--- …where it never shows: a different policy, none, no record, an error, a slow answer ---\n";
$cases = [
    'a different policy' => ['10.5555/ejt.2019.v4.i2.pp8-9', $record('https://doi.org/10.5555/ejt.another-policy')],
    'no policy on the record' => ['10.5555/ejt.2019.v4.i2.pp10-11', $record(null)],
    'no record at Crossref (404)' => ['10.5555/ejt.2019.v4.i2.pp12-13', new StubResponse(404, '{"status":"error","message-type":"route-not-found"}')],
    'Crossref answers HTTP 500' => ['10.5555/ejt.2019.v4.i2.pp14-15', new StubResponse(500, '')],
    'Crossref answers something that is not a record' => ['10.5555/ejt.2019.v4.i2.pp16-17', new StubResponse(200, '<html>maintenance</html>')],
];
foreach ($cases as $what => [$doi, $answer]) {
    APP\core\Application::$http = new StubHttp();
    APP\core\Application::$http->answers[rawurlencode($doi)] = $answer;
    $pub = new StubPublication(27, ['locale' => 'en', 'doi' => $doi, 'authors' => []]);
    fresh($plugin);
    [, $tm, $html] = articlePage($plugin, 2, $pub, $MAIL);
    $n = count($http()->asked);
    fresh($plugin);
    [, $tm2, $html2] = articlePage($plugin, 2, $pub, $MAIL);
    ck($what . ': no button, no tag; asked once and not again on the next view', !$tm->headers && !str_contains($html, 'crossmark') && $n === 1 && !$tm2->headers && !str_contains($html2, 'crossmark') && count($http()->asked) === 1, [$html, $http()->asked]);
}
APP\core\Application::$http = new StubHttp();
APP\core\Application::$http->fail = 'Could not resolve host: api.crossref.org';
$DOI_F = '10.5555/ejt.2019.v4.i3.pp1-2';
$pubF = new StubPublication(28, ['locale' => 'en', 'doi' => $DOI_F, 'authors' => []]);
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubF, $MAIL);
ck('Crossref unreachable: no button; the failure is kept', !$tm->headers && !str_contains($html, 'crossmark') && \CcrCrossmark::readRecord($recordPath($DOI_F))['found'] === null);
APP\core\Application::$http->fail = null;
APP\core\Application::$http->answers[rawurlencode($DOI_F)] = $record('https://doi.org/' . $POLICY);
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubF, $MAIL);
ck('…an hour later (Crossref fine now): still not asked — a failed read is tried again only after a day', !str_contains($html, 'crossmark') && count($http()->asked) === 1);
$kept = \CcrCrossmark::readRecord($recordPath($DOI_F));
\CcrCrossmark::writeRecord($recordPath($DOI_F), $DOI_F, $kept['policy'], $kept['found'], $kept['at'] - 86400);   // a day has passed
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubF, $MAIL);
ck('…a day later: asked again, and now that the record names the policy, the button', count($http()->asked) === 2 && substr_count($html, 'data-target="crossmark"') === 1 && isset($tm->headers['crossrefCompleteCrossmark']), $http()->asked);
$DOI_G = '10.5555/ejt.2019.v4.i3.pp3-4';
$pubG = new StubPublication(29, ['locale' => 'en', 'doi' => $DOI_G, 'authors' => []]);
APP\core\Application::$http = new StubHttp();
APP\core\Application::$http->answers[rawurlencode($DOI_G)] = $record('https://doi.org/' . $POLICY);
APP\core\Application::$http->delay = 0.6;
fresh($plugin);
$plugin->recordReadSeconds = 0.25;
$t = microtime(true);
[, $tm, $html] = articlePage($plugin, 2, $pubG, $MAIL);
$took = microtime(true) - $t;
ck(sprintf('Crossref slow (0.6 s; the wait 0.25 s): the page goes on after %.2f s, without the button, and the failure is kept', $took),
   $took < 0.5 && !$tm->headers && !str_contains($html, 'crossmark') && count($http()->asked) === 1 && $http()->options[0]['timeout'] == 0.25 && \CcrCrossmark::readRecord($recordPath($DOI_G))['found'] === null, [$took, $http()->options]);
$plugin->recordReadSeconds = CrossrefCompletePlugin::RECORD_READ_SECONDS;
@unlink($recordPath($DOI_G));
[, $tm, $html] = articlePage($plugin, 2, $pubG, $MAIL);
ck('…the same request again with nothing kept (the file could not be written, say): still not asked twice in one page view', count($http()->asked) === 1 && !str_contains($html, 'crossmark'));

echo "\n--- …and what comes first ---\n";
APP\core\Application::$http = new StubHttp();   // answers 404 to everything
\CcrCrossmark::mark($GLOBALS['OJS_FILES_DIR'], 2, $DOI_C, $POLICY);
@unlink($recordPath($DOI_C));
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubC, $MAIL);
ck('an article that went through the plugin with Crossmark (its note): the button, and Crossref not asked', substr_count($html, 'data-target="crossmark"') === 1 && $http()->asked === []);
\CcrCrossmark::unmark($GLOBALS['OJS_FILES_DIR'], 2, $DOI_C);
$plugin->updateSetting(2, 'crossmarkPolicy', '10.5555/ejt.policy-2', 'string');
APP\core\Application::$http->answers[rawurlencode($DOI_F)] = $record('https://doi.org/' . $POLICY);
fresh($plugin);
[, $tm, $html] = articlePage($plugin, 2, $pubF, $MAIL);
ck('the journal changed its policy DOI: Crossref asked again at once; its record still names the old one, so no button', count($http()->asked) === 1 && !str_contains($html, 'crossmark'), $http()->asked);
done();
