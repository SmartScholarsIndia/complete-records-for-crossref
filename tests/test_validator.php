<?php
/**
 * core/CcrValidator.php — the plugin's own check of each completed file against Crossref's schema, before OJS sends it.
 *
 * Part A always runs: stand-in schemas answered by a fetch function prove the copy, the cache, the cross-host import,
 * the three answers (valid / invalid / unchecked), and that libxml's settings are given back exactly as found.
 * Part B runs when CR_SCHEMA_CACHE names a copy of Crossref's schema folders (tests/schema_copies.sh builds one): the
 * plugin's files for OJS 3.4 (5.3.1) and 3.5 (5.4.0) must be valid, and broken copies invalid. Without it: SKIPPED,
 * not counted as a pass.
 */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrValidator.php';

function tmpdir($p) { $d = sys_get_temp_dir() . '/' . $p . bin2hex(random_bytes(4)); mkdir($d, 0755, true); return $d; }
function rrm($d) { if (!is_dir($d)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() ? rmdir($f) : unlink($f); } rmdir($d); }
function docOf($xml) { $d = new DOMDocument(); $d->loadXML($xml); return $d; }

echo "--- Part A: stand-in schemas ---\n";
$web = tmpdir('ccrv-web-');
$S = "$web/www.crossref.org/schemas";
mkdir($S, 0755, true);
mkdir("$web/other.example/x", 0755, true);
$schema = function ($v, $body, $extra = '') {
    return "<?xml version=\"1.0\"?>\n<xsd:schema xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\" xmlns=\"http://www.crossref.org/schema/$v\" xmlns:o=\"urn:x-other\" targetNamespace=\"http://www.crossref.org/schema/$v\" elementFormDefault=\"qualified\">$extra$body</xsd:schema>\n";
};
$batch = '<xsd:element name="doi_batch"><xsd:complexType><xsd:sequence><xsd:element name="head" type="xsd:string"/><xsd:element name="body"><xsd:complexType><xsd:sequence><xsd:element ref="journal_article" maxOccurs="unbounded"/></xsd:sequence></xsd:complexType></xsd:element></xsd:sequence><xsd:attribute name="version" type="xsd:string"/></xsd:complexType></xsd:element>';
$article = function ($assert = '') { return '<xsd:element name="journal_article"><xsd:complexType><xsd:sequence><xsd:element name="titles" type="xsd:string"/><xsd:element ref="o:note" minOccurs="0"/><xsd:element name="doi_data" type="doi_t"/></xsd:sequence><xsd:attribute name="n" type="xsd:integer"/>' . $assert . '</xsd:complexType></xsd:element>'; };
$common = '<xsd:complexType name="doi_t"><xsd:sequence><xsd:element name="doi"><xsd:simpleType><xsd:restriction base="xsd:string"><xsd:pattern value="10\.[0-9]{4,9}/.+"/></xsd:restriction></xsd:simpleType></xsd:element></xsd:sequence></xsd:complexType>';
$importOther = '<xsd:import namespace="urn:x-other" schemaLocation="http://other.example/x/other.xsd"/>';
$inc = function ($v) { return "<xsd:include schemaLocation=\"common$v.xsd\"/>"; };
file_put_contents("$S/crossref9.9.9.xsd", $schema('9.9.9', $batch . $article(), $inc('9.9.9') . $importOther));
file_put_contents("$S/common9.9.9.xsd", $schema('9.9.9', $common));
file_put_contents("$S/crossref9.9.8.xsd", $schema('9.9.8', $batch . $article('<xsd:assert test="@n &gt; 0"/>'), $inc('9.9.8') . $importOther));
file_put_contents("$S/common9.9.8.xsd", $schema('9.9.8', $common));
file_put_contents("$S/crossref9.9.7.xsd", $schema('9.9.7', $batch . $article(), '<xsd:include schemaLocation="missing7.xsd"/>' . $importOther . $inc('9.9.7')));
file_put_contents("$S/common9.9.7.xsd", $schema('9.9.7', $common));
file_put_contents("$S/crossref9.9.6.xsd", $schema('9.9.6', $batch . $article(), '<xsd:include schemaLocation="page6.xsd"/>' . $importOther . $inc('9.9.6')));
file_put_contents("$S/page6.xsd", "<!DOCTYPE html><html><body>Service unavailable</body></html>\n");
file_put_contents("$web/other.example/x/other.xsd", "<?xml version=\"1.0\"?>\n<xsd:schema xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\" targetNamespace=\"urn:x-other\" elementFormDefault=\"qualified\"><xsd:element name=\"note\" type=\"xsd:string\"/></xsd:schema>\n");
$fetched = [];
$fetch = function ($url) use ($web, &$fetched) {
    $fetched[] = $url;
    $u = parse_url($url);
    $p = $web . '/' . $u['host'] . $u['path'];
    return is_file($p) ? file_get_contents($p) : null;
};
$doc = function ($v, $body) { return docOf("<?xml version=\"1.0\"?>\n<doi_batch xmlns=\"http://www.crossref.org/schema/$v\" version=\"$v\"><head>h</head><body>$body</body></doi_batch>"); };
$GOOD = '<journal_article n="1"><titles>T</titles><doi_data><doi>10.5555/abc</doi></doi_data></journal_article>';
$WRONG = '<journal_article n="1"><doi_data><doi>10.5555/abc</doi></doi_data><titles>T</titles></journal_article>';
$cache = tmpdir('ccrv-cache-');

$r = CcrValidator::check($doc('9.9.9', $GOOD), $cache, $fetch);
ck('a valid file: valid, 3 schema files (the root, its include, an import from another host)', $r['state'] === 'valid' && $r['files'] === 3 && $r['version'] === '9.9.9', $r);
ck('…all three were fetched once and kept in the copy, laid out by host and path', count($fetched) === 3 && is_file("$cache/www.crossref.org/schemas/common9.9.9.xsd") && is_file("$cache/other.example/x/other.xsd"), $fetched);
$fetched = [];
$r = CcrValidator::check($doc('9.9.9', str_replace('<titles>T</titles>', '<titles>T</titles><note xmlns="urn:x-other">n</note>', $GOOD)), $cache, null);
ck('offline (no fetch at all): checked from the copy, the imported schema\'s element too', $r['state'] === 'valid' && $fetched === [], $r);
$r = CcrValidator::check($doc('9.9.9', $WRONG), $cache, null);
ck('elements out of order: invalid, with the schema\'s own words', $r['state'] === 'invalid' && $r['errors'] && strpos(implode(' ', $r['errors']), 'doi_data') !== false, $r);
$r = CcrValidator::check($doc('9.9.9', str_replace('10.5555/abc', 'not-a-doi', $GOOD)), $cache, null);
ck('a value the schema\'s pattern refuses: invalid', $r['state'] === 'invalid' && strpos(implode(' ', $r['errors']), 'pattern') !== false, $r);
$r = CcrValidator::check($doc('9.9.8', $GOOD), $cache, $fetch);
ck('a schema with an XML Schema 1.1 assertion: unchecked, and it says why (never "valid")', $r['state'] === 'unchecked' && strpos($r['why'], '1.1 assertions') !== false, $r);
$r = CcrValidator::check($doc('9.9.7', $GOOD), $cache, $fetch);
ck('a schema file that cannot be had: unchecked, the missing file named', $r['state'] === 'unchecked' && strpos($r['why'], 'missing7.xsd') !== false && strpos($r['why'], 'incomplete') !== false, $r);
$r = CcrValidator::check($doc('9.9.6', $GOOD), $cache, $fetch);
ck('an HTML page where a schema should be: unchecked ("not a schema"), never kept', $r['state'] === 'unchecked' && strpos($r['why'], 'not a schema') !== false && !is_file("$cache/www.crossref.org/schemas/page6.xsd"), $r);
$r = CcrValidator::check(docOf('<record xmlns="urn:x-something"/>'), $cache, $fetch);
ck('not a Crossref deposit file: unchecked, said so', $r['state'] === 'unchecked' && $r['why'] === 'not a Crossref deposit file', $r);
$r = CcrValidator::check($doc('9.9.5', $GOOD), tmpdir('ccrv-empty-'), null);
ck('offline with no copy: unchecked, "not in the copy"', $r['state'] === 'unchecked' && strpos($r['why'], 'not in the copy') !== false, $r);

// a schema in the copy that names a file outside it: the loader refuses, and that is "could not be read", not "invalid"
$outside = tmpdir('ccrv-outside-');
file_put_contents("$outside/common9.9.4.xsd", $schema('9.9.4', $common));
file_put_contents("$cache/www.crossref.org/schemas/crossref9.9.4.xsd", $schema('9.9.4', $batch . str_replace('<xsd:element ref="o:note" minOccurs="0"/>', '', $article()), '<xsd:include schemaLocation="file://' . $outside . '/common9.9.4.xsd"/>'));
$r = CcrValidator::check($doc('9.9.4', $GOOD), $cache, null);
ck('a schema naming a file outside the copy: that file is never read — unchecked ("could not be read"), not invalid', $r['state'] === 'unchecked' && strpos($r['why'], 'could not be read') !== false, $r);
// the control: the same schema naming that file INSIDE the copy is read, and the file is valid — so the refusal above is the loader's
copy("$outside/common9.9.4.xsd", "$cache/www.crossref.org/schemas/inside9.9.4.xsd");
file_put_contents("$cache/www.crossref.org/schemas/crossref9.9.4.xsd", str_replace('file://' . $outside . '/common9.9.4.xsd', 'file://' . realpath($cache) . '/www.crossref.org/schemas/inside9.9.4.xsd', file_get_contents("$cache/www.crossref.org/schemas/crossref9.9.4.xsd")));
$r = CcrValidator::check($doc('9.9.4', $GOOD), $cache, null);
ck('…while the same schema naming a file inside the copy is read, and the file is valid (the control)', $r['state'] === 'valid', $r);

// Crossref's 4.3.6 set names xml.xsd beside its own files, where crossref.org answers 404 (3 Oct 2026, seen on a member journal's
// OJS): W3C's copy of that standard file stands in, the result says so, and nothing else is ever stood in for.
mkdir("$web/www.w3.org/2009/01", 0755, true);
$xmlAttr = '<xsd:import namespace="http://www.w3.org/XML/1998/namespace" schemaLocation="xml.xsd"/>';
$langArticle = str_replace('<xsd:attribute name="n" type="xsd:integer"/>', '<xsd:attribute name="n" type="xsd:integer"/><xsd:attribute ref="xml:lang"/>', $article());
foreach (['9.9.3', '9.9.2', '9.9.1'] as $v) {
    file_put_contents("$S/crossref$v.xsd", $schema($v, $batch . $langArticle, $inc($v) . $importOther . $xmlAttr));
    file_put_contents("$S/common$v.xsd", $schema($v, $common));
}
$w3cXml = "<?xml version=\"1.0\"?>\n<xs:schema xmlns:xs=\"http://www.w3.org/2001/XMLSchema\" targetNamespace=\"http://www.w3.org/XML/1998/namespace\" xml:lang=\"en\"><xs:attribute name=\"lang\"><xs:simpleType><xs:union memberTypes=\"xs:language\"><xs:simpleType><xs:restriction base=\"xs:string\"><xs:enumeration value=\"\"/></xs:restriction></xs:simpleType></xs:union></xs:simpleType></xs:attribute></xs:schema>\n";
$web2 = tmpdir('ccrv-web2-');   // a web where W3C's address answers with something that is not the XML namespace's schema
$fetchW3C = function ($url) use ($web, $w3cXml, &$fetched) {
    $fetched[] = $url;
    if ($url === CcrValidator::XML_XSD) return $w3cXml;
    $u = parse_url($url); $p = $web . '/' . $u['host'] . $u['path'];
    return is_file($p) ? file_get_contents($p) : null;   // https://www.crossref.org/schemas/xml.xsd: null, as crossref.org's 404
};
$cacheX = tmpdir('ccrv-cachex-');
$fetched = [];
$langDoc = str_replace('<journal_article n="1">', '<journal_article n="1" xml:lang="en">', $GOOD);
$r = CcrValidator::check($doc('9.9.3', $langDoc), $cacheX, $fetchW3C);
ck('a set whose xml.xsd crossref.org does not serve: W3C\'s copy stands in, and the file is checked — valid', $r['state'] === 'valid' && $r['stoodIn'] === ['https://www.crossref.org/schemas/xml.xsd' => CcrValidator::XML_XSD], $r);
ck('…the crossref.org address was tried first, then W3C\'s; both kept in the copy, with a note of the stand-in', in_array('https://www.crossref.org/schemas/xml.xsd', $fetched, true) && in_array(CcrValidator::XML_XSD, $fetched, true)
   && is_file("$cacheX/www.crossref.org/schemas/xml.xsd") && is_file("$cacheX/www.crossref.org/schemas/xml.xsd.stand-in"), $fetched);
$r = CcrValidator::check($doc('9.9.3', str_replace('xml:lang="en"', 'lang="en"', $langDoc)), $cacheX, null);
ck('…offline afterwards: a plain lang="en" (the fault 1.0.0.2 fixed) is refused by the schema — invalid, and the stand-in still named', $r['state'] === 'invalid' && strpos(implode(' ', $r['errors']), "'lang'") !== false && isset($r['stoodIn']['https://www.crossref.org/schemas/xml.xsd']), $r);
$fetchNone = function ($url) use ($web) { $u = parse_url($url); $p = $web . '/' . $u['host'] . $u['path']; return is_file($p) ? file_get_contents($p) : null; };
$r = CcrValidator::check($doc('9.9.2', $langDoc), tmpdir('ccrv-cachey-'), $fetchNone);
ck('W3C\'s copy not to be had either: unchecked, xml.xsd named (never "valid")', $r['state'] === 'unchecked' && strpos($r['why'], 'https://www.crossref.org/schemas/xml.xsd') !== false, $r);
$fetchWrong = function ($url) use ($web) {
    if ($url === CcrValidator::XML_XSD) return "<?xml version=\"1.0\"?>\n<xs:schema xmlns:xs=\"http://www.w3.org/2001/XMLSchema\" targetNamespace=\"urn:x-not-xml\"/>\n";
    $u = parse_url($url); $p = $web . '/' . $u['host'] . $u['path']; return is_file($p) ? file_get_contents($p) : null;
};
$r = CcrValidator::check($doc('9.9.1', $langDoc), tmpdir('ccrv-cachez-'), $fetchWrong);
ck('a "stand-in" that is not the XML namespace\'s schema is refused: unchecked', $r['state'] === 'unchecked' && strpos($r['why'], 'xml.xsd') !== false, $r);
$r = CcrValidator::check($doc('9.9.7', $GOOD), tmpdir('ccrv-cachew-'), $fetchW3C);
ck('only xml.xsd is ever stood in for: another missing file still makes the check unchecked', $r['state'] === 'unchecked' && strpos($r['why'], 'missing7.xsd') !== false && $r['stoodIn'] === [], $r);

$before = libxml_use_internal_errors(false);
CcrValidator::check($doc('9.9.9', $GOOD), $cache, null);
ck('libxml\'s error setting is given back as it was found (off)', libxml_use_internal_errors(false) === false);
libxml_use_internal_errors(true);
CcrValidator::check($doc('9.9.9', $GOOD), $cache, null);
ck('…and as it was found (on)', libxml_use_internal_errors($before) === true);
if (function_exists('libxml_get_external_entity_loader')) {
    ck('no entity loader is left behind for the rest of OJS', libxml_get_external_entity_loader() === null);
} else {
    ck('no entity loader is left behind (PHP < 8.2: the default is restored)', true);
}
$d = new DOMDocument();
ck('…OJS\'s own XML loading still works afterwards', $d->loadXML('<a><b/></a>') && $d->documentElement->localName === 'a');

// OJS's exportXML: internal errors on, the filter runs (and this check inside its hook), then ANY error in libxml's buffer
// fails the export. The check must leave the buffer exactly as found.
libxml_use_internal_errors(true); libxml_clear_errors();
$r = CcrValidator::check($doc('9.9.9', $WRONG), $cache, null);
ck('as inside OJS\'s export: an INVALID file\'s messages are reported here and cleared from libxml\'s buffer (OJS would fail the export on them)', $r['state'] === 'invalid' && libxml_get_errors() === [], libxml_get_errors());
$r = CcrValidator::check($doc('9.9.9', $GOOD), $cache, null);
ck('…a valid file leaves the buffer empty too (the "Skipping import" warnings included)', $r['state'] === 'valid' && libxml_get_errors() === []);
@(new DOMDocument())->loadXML('<broken>');   // an error OJS's own work left in the buffer
$before = count(libxml_get_errors());
$r = CcrValidator::check($doc('9.9.9', $GOOD), $cache, null);
ck('an error already in the buffer from OJS\'s own work: left exactly as it was (never cleared), and the file not checked', $before > 0 && count(libxml_get_errors()) === $before && $r['state'] === 'unchecked' && strpos($r['why'], 'already holds an error') !== false, [$before, count(libxml_get_errors()), $r]);
libxml_clear_errors(); libxml_use_internal_errors(false);
ck('a schema location can never name a path outside the copy', strpos(CcrValidator::localPath('/c', 'http://h.example/../../etc/passwd'), '/c/h.example/') === 0 && strpos(CcrValidator::localPath('/c', 'http://h.example/../../etc/passwd'), '..') === false);
ck('relative schema locations resolve as on the web', CcrValidator::resolve('https://www.crossref.org/schemas/crossref5.3.1.xsd', 'standard-modules/xlink.xsd') === 'https://www.crossref.org/schemas/standard-modules/xlink.xsd'
    && CcrValidator::resolve('http://www.w3.org/Math/XMLSchema/mathml3/mathml3.xsd', '../x.xsd') === 'http://www.w3.org/Math/XMLSchema/x.xsd'
    && CcrValidator::resolve('https://a.example/s/b.xsd', 'http://w.example/c.xsd') === 'http://w.example/c.xsd');
rrm($web); rrm($cache); rrm($outside);

echo "\n--- Part B: the plugin's files against Crossref's schemas ---\n";
$copy = (string) getenv('CR_SCHEMA_CACHE');
if ($copy === '' || !is_file("$copy/www.crossref.org/schemas/crossref5.3.1.xsd")) {
    echo "  SKIPPED — no copy of Crossref's schemas here (set CR_SCHEMA_CACHE; tests/schema_copies.sh builds one). Not counted as a pass.\n";
} else {
    $out = tmpdir('ccrv-b-');
    exec('php ' . escapeshellarg(__DIR__ . '/emit_completed.php') . ' ' . escapeshellarg($out) . ' 2>&1', $lines, $rc);
    ck('the plugin completed all six files', $rc === 0, implode("\n", $lines));
    foreach (['5.3.1' => 'ojs34-5.3.1.xml', '5.4.0' => 'ojs35-5.4.0.xml'] as $v => $fx) {
        foreach (['in', 'done-plain', 'done-crossmark'] as $k) {
            $d = new DOMDocument(); $d->load("$out/$k-$fx");
            $t = microtime(true);
            $r = CcrValidator::check($d, $copy, null);
            ck(sprintf('%s %s: valid against Crossref\'s schema (%d files, %.1f s)', $v, $k, $r['files'], microtime(true) - $t), $r['state'] === 'valid', $r);
        }
        $done = file_get_contents("$out/done-crossmark-$fx");
        $broken = [
            'the reference list moved before doi_data' => preg_replace('~(<doi_data>.*?</doi_data>)(<citation_list>.*?</citation_list>)~s', '$2$1', $done, 1),
            'a ROR ID given an unknown type' => preg_replace('~type="ror"~', 'type="rorr"', $done, 1),
            'the licence program left beside Crossmark' => preg_replace('~<crossmark>~', '<ai:program xmlns:ai="http://www.crossref.org/AccessIndicators.xsd" name="AccessIndicators"><ai:license_ref>https://creativecommons.org/licenses/by/4.0</ai:license_ref></ai:program><crossmark>', $done, 1),
        ];
        foreach ($broken as $what => $xml) {
            $r = CcrValidator::check(docOf($xml), $copy, null);
            ck("$v: $what → invalid", $xml !== $done && $r['state'] === 'invalid' && $r['errors'], $r);
        }
    }
    rrm($out);
}
done();
