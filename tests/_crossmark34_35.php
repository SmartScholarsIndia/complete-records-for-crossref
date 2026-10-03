<?php
/**
 * The OJS 3.4 / 3.5 plugin and Crossref's Crossmark button (release 1.0.0.4) against stand-ins for OJS
 * (tests/ojs34_stubs.php) — the same story as test_crossmark33.php. Included by test_crossmark34.php and
 * test_crossmark35.php with $LINE set. The plugin is assembled exactly as it ships and those files are run.
 */
require __DIR__ . '/_ck.php';
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

ck('release 1.0.0.5', (string) simplexml_load_file("$dir/version.xml")->release === '1.0.0.5');
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

function articlePage($plugin, $contextId, $publication): array {
    TemplateManager::$last = null;
    $request = new CmRequest(new StubContext($contextId)); $issue = null; $article = null;
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
done();
