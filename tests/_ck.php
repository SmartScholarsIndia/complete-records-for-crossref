<?php
/** Shared by the test files: ck() prints one line per check; done() prints the count and exits 1 on any failure. */
$GLOBALS['__n'] = 0; $GLOBALS['__f'] = [];
function ck($name, $ok, $detail = '') {
    $GLOBALS['__n']++;
    echo ($ok ? '  OK   ' : ' FAIL  ') . $name . ($ok ? '' : '  <- ' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600)) . "\n";
    if (!$ok) $GLOBALS['__f'][] = $name;
}
function done() {
    $n = $GLOBALS['__n']; $f = $GLOBALS['__f'];
    echo "\n$n checks, " . count($f) . " failed" . ($f ? ' — ' . implode('; ', $f) : ' — ALL OK') . "\n";
    exit($f ? 1 : 0);
}
function fixture($name) {
    $d = new DOMDocument();
    $d->preserveWhiteSpace = false;
    if (!$d->load(__DIR__ . '/fixtures/' . $name)) throw new RuntimeException('cannot read fixture ' . $name);
    return $d;
}
function xp(DOMDocument $d) {
    $x = new DOMXPath($d);
    $x->registerNamespace('cr', $d->documentElement->namespaceURI);
    $x->registerNamespace('ai', 'http://www.crossref.org/AccessIndicators.xsd');
    $x->registerNamespace('fr', 'http://www.crossref.org/fundref.xsd');
    $x->registerNamespace('jats', 'http://www.ncbi.nlm.nih.gov/JATS1');
    return $x;
}
/** The element names under one journal_article, in order, as a readable list. */
function childKeys(DOMElement $e) {
    $o = [];
    foreach (CcrSchema::children($e) as $c) $o[] = CcrSchema::key($c);
    return implode(',', $o);
}
