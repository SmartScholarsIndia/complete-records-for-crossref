<?php
/**
 * Write OJS's own deposit files (the fixtures) and the completed files the plugin makes from them into a folder, for
 * checking against Crossref's published schema with tools/crossref_validate.py (tests/test_validate.py does both).
 *
 *   php ojs-plugin/tests/emit_completed.php OUTDIR
 *
 * For each OJS version: in-<fixture> (as OJS wrote it), done-plain-<fixture> (references and affiliations added) and
 * done-crossmark-<fixture> (the same, with Crossmark — the licence program moved into custom_metadata).
 */
require __DIR__ . '/../core/CcrCompleter.php';
require __DIR__ . '/_example_data.php';
$out = isset($argv[1]) ? rtrim($argv[1], '/') : '';
if ($out === '' || !is_dir($out)) { fwrite(STDERR, "usage: php emit_completed.php OUTDIR (an existing folder)\n"); exit(2); }
$bad = 0;
foreach (['ojs33-4.3.6.xml', 'ojs34-5.3.1.xml', 'ojs35-5.4.0.xml'] as $f) {
    $doc = new DOMDocument();
    if (!$doc->load(__DIR__ . '/fixtures/' . $f)) { fwrite(STDERR, "cannot read $f\n"); exit(2); }
    copy(__DIR__ . '/fixtures/' . $f, "$out/in-$f");
    foreach (['plain' => '', 'crossmark' => 'https://doi.org/10.5555/ejt.crossmark-policy'] as $tag => $policy) {
        list($done, $rep) = CcrCompleter::complete($doc, $dataFor, ['references' => true, 'affiliations' => true, 'crossmarkPolicy' => $policy, 'crossmarkDomain' => 'journal.example.org']);
        file_put_contents("$out/done-$tag-$f", $done->saveXML());
        $ok = $rep['changed'] && !$rep['kept_original'] && !$rep['problems'];
        if (!$ok) $bad++;
        echo ($ok ? 'written ' : 'NOT COMPLETED ') . "done-$tag-$f\n";
    }
}
exit($bad ? 1 : 0);
