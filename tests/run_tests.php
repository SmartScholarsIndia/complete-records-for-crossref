<?php
/**
 * Complete Records for Crossref — the test suite.   php ojs-plugin/tests/run_tests.php
 *
 * Runs every tests/test_*.php in its own PHP process (one failure never hides another) and prints one line per file.
 * Exit 0 only when every check in every file passed.
 */
$here = __DIR__;
$files = glob($here . '/test_*.php');
sort($files);
$fail = 0; $total = 0;
foreach ($files as $f) {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $last = end($out);
    if (preg_match('/^(\d+) checks, (\d+) failed/', (string) $last, $m)) $total += (int) $m[1];
    if ($rc !== 0) {
        $fail++;
        echo " FAIL  " . basename($f) . "\n";
        foreach ($out as $line) if (strpos($line, 'FAIL') !== false || stripos($line, 'error') !== false) echo "        " . $line . "\n";
    } else {
        echo "  OK   " . basename($f) . "  (" . $last . ")\n";
    }
}
echo "\n" . count($files) . " files, $total checks — " . ($fail ? "$fail FILE(S) FAILED" : 'ALL OK') . "\n";
exit($fail ? 1 : 0);
