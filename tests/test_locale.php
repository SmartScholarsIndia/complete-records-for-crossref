<?php
/**
 * Every message key the plugin uses exists in its locale file, for each OJS line — and the locale files are sound
 * (each msgid has one msgstr, no key twice, every placeholder a message is given is in its text). A missing key shows
 * the raw key to the journal; OJS does not warn. 3 Oct 2026.
 */
require __DIR__ . '/_ck.php';

$lines = ['3.3' => ['dir' => 'ojs-3.3/crossrefComplete', 'po' => 'locale/en_US/locale.po'],
          '3.4' => ['dir' => 'ojs-3.4/crossrefComplete', 'po' => 'locale/en/locale.po'],
          '3.5' => ['dir' => 'ojs-3.5/crossrefComplete', 'po' => 'locale/en/locale.po']];
// keys built at run time from a fixed set of endings
$dynamic = [];
foreach (['valid', 'invalid', 'unchecked', 'none', 'off'] as $s) $dynamic[] = 'plugins.generic.crossrefComplete.status.schemaCheck.' . $s;
foreach (['landed', 'partly', 'missing', 'waiting', 'test'] as $s) $dynamic[] = 'plugins.generic.crossrefComplete.state.' . $s;
foreach (['completed', 'nothing', 'kept', 'invalid'] as $s) $dynamic[] = 'plugins.generic.crossrefComplete.status.lastFile.' . $s;
$placeholders = [
    'plugins.generic.crossrefComplete.status.schemaCheck.valid' => ['date', 'version'],
    'plugins.generic.crossrefComplete.status.schemaCheck.invalid' => ['date', 'version', 'errors'],
    'plugins.generic.crossrefComplete.status.schemaCheck.unchecked' => ['date', 'why'],
    'plugins.generic.crossrefComplete.status.unreachable' => ['reason'],
    'plugins.generic.crossrefComplete.status.schemaCheck.standIn' => ['files'],
    'plugins.generic.crossrefComplete.status.lastFile.head' => ['date', 'count', 'outcome'],
    'plugins.generic.crossrefComplete.status.lastFile.kept' => ['problems'],
    'plugins.generic.crossrefComplete.status.lastFile.added' => ['doi', 'references', 'affiliations', 'crossmark', 'notes'],
    'plugins.generic.crossrefComplete.status.lastFile.notAdded' => ['doi', 'notes'],
];

foreach ($lines as $v => $l) {
    $base = __DIR__ . '/../' . $l['dir'];
    $po = file_get_contents("$base/{$l['po']}");
    preg_match_all('/^msgid "(.*)"\s*\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', $po, $m, PREG_SET_ORDER);
    $msgs = [];
    $dupes = [];
    foreach ($m as $row) {
        if ($row[1] === '') continue;   // the header
        if (isset($msgs[$row[1]])) $dupes[] = $row[1];
        $msgs[$row[1]] = $row[2];
    }
    $ids = preg_match_all('/^msgid "/m', $po) - 1;
    ck("$v: every msgid has its msgstr on the next line (" . count($msgs) . " messages)", $ids === count($m) - 1 && $ids === count($msgs) + count($dupes), "$ids msgids, " . count($m) . ' pairs');
    ck("$v: no key twice", !$dupes, $dupes);
    $used = $dynamic;
    foreach (glob("$base/*.php") as $f) {
        // a literal key only; a prefix joined to a value at run time ends in "." and is listed in $dynamic
        preg_match_all("/__\\('(plugins\\.generic\\.crossrefComplete\\.[A-Za-z0-9_.]*[A-Za-z0-9_])'/", file_get_contents($f), $k);
        $used = array_merge($used, $k[1]);
    }
    foreach (glob("$base/templates/*.tpl") as $f) {
        preg_match_all('/(?:key|label|title|description)="(plugins\.generic\.crossrefComplete\.[A-Za-z0-9_.]+)"/', file_get_contents($f), $k);
        $used = array_merge($used, $k[1]);
    }
    $used = array_values(array_unique($used));
    $missing = array_values(array_filter($used, function ($k) use ($msgs) { return !isset($msgs[$k]); }));
    ck("$v: all " . count($used) . " keys the plugin uses are in the locale file", !$missing, $missing);
    $bad = [];
    foreach ($placeholders as $key => $want) {
        foreach ($want as $p) if (isset($msgs[$key]) && strpos($msgs[$key], '{$' . $p . '}') === false) $bad[] = "$key lacks {\$$p}";
    }
    ck("$v: the messages that take values have a place for each", !$bad, $bad);
}
done();
