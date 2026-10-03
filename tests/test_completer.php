<?php
/**
 * Completing OJS's own deposit file, for each OJS version: what is added, where, and what is never touched.
 */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrCompleter.php';

require __DIR__ . '/_example_data.php';   // $DOI_A, $DOI_B, $refsA, $ojs, $dataFor (3 Oct 2026: shared with emit_completed.php)
$all = ['references' => true, 'affiliations' => true, 'crossmarkPolicy' => '', 'crossmarkDomain' => 'journal.example.org'];

foreach ([['ojs33-4.3.6.xml', '4.3.6'], ['ojs34-5.3.1.xml', '5.3.1'], ['ojs35-5.4.0.xml', '5.4.0']] as $case) {
    list($file, $v) = $case;
    echo "\n--- OJS file in schema $v ---\n";
    $orig = fixture($file);
    $before = $orig->saveXML();
    list($out, $rep) = CcrCompleter::complete($orig, $dataFor, $all);
    ck("$v: completed, nothing refused", $rep['changed'] && !$rep['kept_original'] && !$rep['problems'], $rep);
    ck("$v: OJS's own file object is never changed", $orig->saveXML() === $before);
    $x = xp($out);
    $arts = CcrSchema::articles($out);
    $a = $arts[0]; $b = $arts[1];
    ck("$v: article A gets its 11 references, after doi_data", $rep['articles'][$DOI_A]['references'] === 11 && substr(childKeys($a), -strlen('doi_data,citation_list')) === 'doi_data,citation_list', childKeys($a));
    ck("$v: each reference: key, text, and the DOI the line names", $x->evaluate('count(//cr:journal_article[1]/cr:citation_list/cr:citation[@key="ref3"]/cr:doi)') == 1
        && $x->evaluate('string(//cr:journal_article[1]/cr:citation_list/cr:citation[@key="ref3"]/cr:doi)') === '10.1038/s41538-023-00182-6', $x->evaluate('string(//cr:journal_article[1]/cr:citation_list/cr:citation[@key="ref3"]/cr:doi)'));
    ck("$v: article B has no references in OJS: none added, and said so", $rep['articles'][$DOI_B]['references'] === 0 && in_array('references: none entered in OJS', $rep['articles'][$DOI_B]['notes'], true) && !CcrSchema::child($b, 'citation_list'));
    ck("$v: the finished file breaks no order rule", array_merge(CcrSchema::articleProblems($a), CcrSchema::articleProblems($b)) === []);
    $pA = CcrSchema::child(CcrSchema::child($a, 'contributors'), 'person_name');
    if ($v === '4.3.6') {
        ck('4.3.6: the plain <affiliation>, after surname and before ORCID', childKeys($pA) === 'given_name,surname,affiliation,ORCID', childKeys($pA));
        ck('4.3.6: no ROR ID (the schema has no place for one)', $x->evaluate('count(//cr:institution_id)') == 0 && $rep['articles'][$DOI_B]['ror'] === 0);
        ck('4.3.6: article B: authors 1 and 3 get theirs, author 2 has none in OJS', $rep['articles'][$DOI_B]['affiliations'] === 2, $rep['articles'][$DOI_B]);
    } elseif ($v === '5.3.1') {
        ck('5.3.1: <affiliations><institution><institution_name>, after surname and before ORCID', childKeys($pA) === 'given_name,surname,affiliations,ORCID', childKeys($pA));
        ck('5.3.1: ROR IDs in the one form Crossref takes, "ror.org/…" typed without https fixed', $x->evaluate('string((//cr:journal_article)[2]//cr:person_name[3]//cr:institution_id[@type="ror"])') === 'https://ror.org/0zyxwvu98' && $rep['articles'][$DOI_B]['ror'] === 2, $rep['articles'][$DOI_B]);
        ck('5.3.1: an author without a ROR ID gets the institution name alone', $x->evaluate('count((//cr:journal_article)[1]//cr:institution_id)') == 0 && $x->evaluate('string((//cr:journal_article)[1]//cr:institution_name)') === 'Test College of Food Technology (test value)');
    } else {
        ck('5.4.0 (OJS 3.5): author 1 of article B already has OJS\'s affiliation — left exactly as OJS wrote it', $x->evaluate('count((//cr:journal_article)[2]//cr:person_name[1]/cr:affiliations)') == 1 && $x->evaluate('string((//cr:journal_article)[2]//cr:person_name[1]//cr:institution_name)') === 'Test Institute of Technology (test value)');
        ck('5.4.0: …while author 3 (none from OJS in this file) gets the one OJS holds', $x->evaluate('count((//cr:journal_article)[2]//cr:person_name[3]/cr:affiliations)') == 1);
    }
    ck("$v: the licence program OJS wrote is still there, untouched", $x->evaluate('count(//cr:journal_article[1]/ai:program/ai:license_ref)') == 1);
    list($out2, $rep2) = CcrCompleter::complete($out, $dataFor, $all);
    ck("$v: completing a completed file adds nothing (no reference list or affiliation twice)", !$rep2['changed'] && $out2 === $out && !$rep2['problems'], $rep2);
}

echo "\n--- OJS 3.3.0.21 (the release a member journal runs) writes names through ucfirst() ---\n";
// 3.3.0.21's ArticleCrossrefXmlFilter wrote ucfirst($familyName); 3.3.0.23 stopped (read in pkp/ojs, 3 Oct 2026). A surname OJS
// holds as "van der Berg" reaches the file as "Van der Berg": the author must still be matched, and given an affiliation.
$d = fixture('ojs33-4.3.6.xml');
$sn = $d->getElementsByTagNameNS($d->documentElement->namespaceURI, 'surname')->item(0);
$sn->textContent = 'Van der Berg';
$lower = $ojs; $lower[$DOI_A]['authors'][0]['family'] = 'van der Berg';
list($o, $r) = CcrCompleter::complete($d, function ($doi) use ($lower) { return isset($lower[$doi]) ? $lower[$doi] : null; }, $all);
ck('a surname capitalised by OJS 3.3.0.21 still matches the author OJS holds, and gets the affiliation', $r['articles'][$DOI_A]['affiliations'] === 1, $r['articles'][$DOI_A]);

echo "\n--- when the file does not match OJS's records ---\n";
$d = fixture('ojs34-5.3.1.xml');
$wrong = $ojs; $wrong[$DOI_B]['authors'][0]['family'] = 'Someone Else';
list($o, $r) = CcrCompleter::complete($d, function ($doi) use ($wrong) { return isset($wrong[$doi]) ? $wrong[$doi] : null; }, $all);
ck('an author whose surname differs is left alone, and the note says which', $r['articles'][$DOI_B]['affiliations'] === 1 && (bool) preg_grep('/author 1 in the file is not author 1 in OJS/', $r['articles'][$DOI_B]['notes']), $r['articles'][$DOI_B]);
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), function ($doi) { return null; }, $all);
ck('an article this journal does not have: left as OJS wrote it, file unchanged', !$r['changed'] && (bool) preg_grep('/not found in this journal/', $r['articles'][$DOI_A]['notes']));
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), $dataFor, ['references' => false, 'affiliations' => false, 'crossmarkPolicy' => '']);
ck('every addition switched off: the file goes as OJS made it', !$r['changed']);

echo "\n--- Crossmark ---\n";
$cmOpt = ['references' => true, 'affiliations' => true, 'crossmarkPolicy' => 'https://doi.org/10.5555/ejt.crossmark-policy', 'crossmarkDomain' => 'https://journal.example.org/index.php/pub'];
foreach (['ojs33-4.3.6.xml', 'ojs34-5.3.1.xml', 'ojs35-5.4.0.xml'] as $f) {
    list($o, $r) = CcrCompleter::complete(fixture($f), $dataFor, $cmOpt);
    $a = CcrSchema::articles($o)[0]; $x = xp($o);
    ck("$f: Crossmark added with the policy DOI written bare and the journal's host as its domain", $r['changed'] && $x->evaluate('string(//cr:journal_article[1]/cr:crossmark/cr:crossmark_policy)') === '10.5555/ejt.crossmark-policy' && $x->evaluate('string(//cr:journal_article[1]/cr:crossmark//cr:domain)') === 'journal.example.org', $r);
    ck("$f: the licence program moved INSIDE crossmark/custom_metadata (the schema's choice)", $x->evaluate('count(//cr:journal_article[1]/ai:program)') == 0 && $x->evaluate('count(//cr:journal_article[1]/cr:crossmark/cr:custom_metadata/ai:program)') == 1);
    ck("$f: Crossmark sits after pages and before doi_data; no order rule broken", strpos(childKeys($a), 'pages,crossmark,doi_data') !== false && CcrSchema::articleProblems($a) === [], childKeys($a));
}
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), $dataFor, ['crossmarkPolicy' => 'not a doi', 'crossmarkDomain' => 'journal.example.org']);
ck('a policy that is not a DOI: no Crossmark, and the note says why', !$r['changed'] && (bool) preg_grep('/not a DOI/', $r['articles'][$DOI_A]['notes']));
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), $dataFor, ['crossmarkPolicy' => '10.5555/ejt.crossmark-policy', 'crossmarkDomain' => '']);
ck('no domain set: the host of the article\'s own landing page (doi_data/resource) — works in scheduled deposits too', $r['changed'] && xp($o)->evaluate('string(//cr:journal_article[1]/cr:crossmark//cr:domain)') === 'journal.example.org', $r);
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), $dataFor, ['crossmarkPolicy' => '10.5555/ejt.crossmark-policy', 'crossmarkDomain' => 'not a host']);
ck('a domain that is not a web address: no Crossmark, and the note says why', !$r['changed'] && (bool) preg_grep('/no web address/', $r['articles'][$DOI_A]['notes']));

echo "\n--- the DOI as OJS wrote it ---\n";
$mixed = fixture('ojs34-5.3.1.xml');
$dn = $mixed->getElementsByTagNameNS($mixed->documentElement->namespaceURI, 'doi')->item(0);
$dn->nodeValue = '10.5555/EJT.2025.v10.i12.pp35-41';
$asked = [];
list($o, $r) = CcrCompleter::complete($mixed, function ($doi) use (&$asked, $dataFor) { $asked[] = $doi; return $dataFor(strtolower($doi)); }, $all);
ck('OJS is asked with the DOI exactly as written (PostgreSQL compares case-sensitively); the report key is lower case', $asked[0] === '10.5555/EJT.2025.v10.i12.pp35-41' && isset($r['articles'][$DOI_A]) && $r['articles'][$DOI_A]['references'] === 11, [$asked, array_keys($r['articles'])]);

echo "\n--- the safety net ---\n";
$bad = fixture('ojs34-5.3.1.xml');
$ba = CcrSchema::articles($bad)[0];
$ba->appendChild($bad->createElementNS($bad->documentElement->namespaceURI, 'titles'));   // a file OJS would never write: titles at the end
$before = $bad->saveXML();
list($o, $r) = CcrCompleter::complete($bad, $dataFor, $all);
ck('a finished copy that would break the order is thrown away: OJS\'s own file goes', $o === $bad && $r['kept_original'] && !$r['changed'] && $bad->saveXML() === $before, $r);
ck('…and every article\'s note says nothing was added', (bool) preg_grep('/nothing added/', $r['articles'][$DOI_A]['notes']));
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), function ($doi) { throw new RuntimeException('database gone'); }, $all);
ck('an error while reading OJS\'s data: OJS\'s own file goes, the reason is kept', $r['kept_original'] && (bool) preg_grep('/database gone/', $r['problems']), $r);
$nx = new DOMDocument(); $nx->loadXML('<other/>');
list($o, $r) = CcrCompleter::complete($nx, $dataFor, $all);
ck('a file that is not a Crossref deposit is passed through untouched', $o === $nx && !$r['changed']);

echo "\n--- a file built the way OJS builds it (3 Oct 2026: found inside a real OJS 3.3.0.23) ---\n";
// OJS's Crossref filter builds the file node by node and sets each abstract's xml:lang while its article is not yet in the
// document. On PHP 8.4.21 / libxml 2.9.14, DOMDocument::cloneNode() then moved xml:lang into Crossref's own namespace
// (written lang="en"), a file Crossref refuses. The completer works on a copy read back from OJS's text instead.
function ojsBuilt($withAffiliationFor = true) {
    $CR = 'http://www.crossref.org/schema/4.3.6'; $J = 'http://www.ncbi.nlm.nih.gov/JATS1'; $XNS = 'http://www.w3.org/2000/xmlns/';
    $d = new DOMDocument('1.0', 'utf-8');
    $d->preserveWhiteSpace = false;
    $d->formatOutput = true;
    $root = $d->createElementNS($CR, 'doi_batch');
    $root->setAttributeNS($XNS, 'xmlns:jats', $J);
    $root->setAttributeNS($XNS, 'xmlns:ai', CcrSchema::NS_AI);
    $root->setAttribute('version', '4.3.6');
    $d->appendChild($root);
    $body = $d->createElementNS($CR, 'body');
    $root->appendChild($body);
    $journal = $d->createElementNS($CR, 'journal');
    $art = $d->createElementNS($CR, 'journal_article');                       // filled while detached, as OJS does
    $art->setAttribute('publication_type', 'full_text');
    $titles = $d->createElementNS($CR, 'titles');
    $titles->appendChild($d->createElementNS($CR, 'title', 'An OJS-built test article'));
    $art->appendChild($titles);
    $contribs = $d->createElementNS($CR, 'contributors');
    $p = $d->createElementNS($CR, 'person_name');
    $p->setAttribute('contributor_role', 'author');
    $p->setAttribute('sequence', 'first');
    $p->appendChild($d->createElementNS($CR, 'surname', 'MAHESH, C. M.'));   // OJS 3.3: whole name in the given-name box
    $contribs->appendChild($p);
    $art->appendChild($contribs);
    $abs = $d->createElementNS($J, 'jats:abstract');
    $abs->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en');
    $abs->appendChild($d->createElementNS($J, 'jats:p', 'Test abstract.'));
    $art->appendChild($abs);
    $ai = $d->createElementNS(CcrSchema::NS_AI, 'ai:program');
    $ai->setAttribute('name', 'AccessIndicators');
    $ai->appendChild($d->createElementNS(CcrSchema::NS_AI, 'ai:license_ref', 'https://creativecommons.org/licenses/by/4.0'));
    $art->appendChild($ai);
    $dd = $d->createElementNS($CR, 'doi_data');
    $dd->appendChild($d->createElementNS($CR, 'doi', '10.5555/EHJ.11.1.102'));
    $dd->appendChild($d->createElementNS($CR, 'resource', 'https://journal.example/index.php/ehj/article/view/3'));
    $art->appendChild($dd);
    $journal->appendChild($art);
    $body->appendChild($journal);
    return $d;
}
$ojsData = function ($doi) { return ['references' => ['A test reference. 2020.'], 'authors' => [['given' => 'MAHESH, C. M.', 'family' => '', 'affiliation' => 'Test Sericulture Centre (test value)', 'ror' => '']]]; };
$built = ojsBuilt();
$clone = $built->cloneNode(true);
$ca = $clone->getElementsByTagNameNS('http://www.ncbi.nlm.nih.gov/JATS1', 'abstract')->item(0)->attributes->item(0);
echo '  note: on PHP ' . PHP_VERSION . ' / libxml ' . LIBXML_DOTTED_VERSION . ', cloneNode() writes the abstract\'s language as "' . $ca->nodeName . '" {' . $ca->namespaceURI . "}\n";
$before = $built->saveXML();
libxml_use_internal_errors(true);
libxml_clear_errors();
list($o, $r) = CcrCompleter::complete($built, $ojsData, $all);
$xa = $o->getElementsByTagNameNS('http://www.ncbi.nlm.nih.gov/JATS1', 'abstract')->item(0)->attributes->item(0);
ck('OJS-built file: completed (one reference, the institution of an author whose whole name is in the given-name box)', $r['changed'] && $r['articles']['10.5555/ehj.11.1.102']['references'] === 1 && $r['articles']['10.5555/ehj.11.1.102']['affiliations'] === 1, $r);
ck('…the abstract keeps xml:lang in the XML namespace', $xa->nodeName === 'xml:lang' && $xa->namespaceURI === 'http://www.w3.org/XML/1998/namespace' && strpos($o->saveXML(), ' xml:lang="en"') !== false && !preg_match('/ (jats:)?lang="/', $o->saveXML()), $xa->nodeName . ' {' . $xa->namespaceURI . '}');
ck('…OJS\'s own object is untouched', $built->saveXML() === $before);
ck('…and libxml\'s error list is left empty, as OJS\'s export needs it', libxml_get_errors() === []);
ck('…and the finished file is exactly OJS\'s own plus the additions', CcrCompleter::onlyAdded($built, $o));

echo "\n--- the check that nothing OJS wrote was changed ---\n";
$t = CcrCompleter::copyOf($o);
$t->getElementsByTagNameNS($t->documentElement->namespaceURI, 'title')->item(0)->firstChild->data = 'A changed title';
ck('a finished file whose title differs from OJS\'s is caught', !CcrCompleter::onlyAdded($built, $t));
$t = CcrCompleter::copyOf($o);
$an = $t->getElementsByTagNameNS('http://www.ncbi.nlm.nih.gov/JATS1', 'abstract')->item(0);
$an->removeAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang');
$an->setAttribute('lang', 'en');
ck('a finished file whose xml:lang became a plain "lang" (the 3 Oct fault) is caught', !CcrCompleter::onlyAdded($built, $t));
$t = CcrCompleter::copyOf($o);
$t->getElementsByTagNameNS(CcrSchema::NS_AI, 'program')->item(0)->setAttribute('name', 'Other');
ck('a changed attribute OJS wrote is caught', !CcrCompleter::onlyAdded($built, $t));
foreach (['ojs33-4.3.6.xml', 'ojs34-5.3.1.xml', 'ojs35-5.4.0.xml'] as $f) {
    list($o2, $r2) = CcrCompleter::complete(fixture($f), $dataFor, $cmOpt);
    ck("$f with Crossmark (programs moved inside it): passes the check", $r2['changed'] && !$r2['problems'], $r2['problems']);
}

echo "\n--- OJS's indentation, kept ---\n";
$text = $built->saveXML();
$ind = new DOMDocument(); $ind->preserveWhiteSpace = true; $ind->loadXML($text); $ind->formatOutput = true;
list($o, $r) = CcrCompleter::complete($ind, $ojsData, $all);
$s = $o->saveXML();
preg_match('/\n( *)<surname>/', $s, $m1); preg_match('/\n( *)<affiliation>/', $s, $m2);
preg_match('/\n( *)<doi_data>/', $s, $m3); preg_match('/\n( *)<citation_list>/', $s, $m4); preg_match('/\n( *)<citation key/', $s, $m5);
ck('the institution is indented like the surname, the reference list like doi_data, each reference one step in', $m1 && $m2 && $m1[1] === $m2[1] && $m3 && $m4 && $m3[1] === $m4[1] && $m5 && strlen($m5[1]) === strlen($m4[1]) + 2, substr($s, strpos($s, '<contributors'), 900));
ck('…and no empty line is left anywhere', !preg_match('/\n\s*\n/', $s));

echo "\n--- text inside a title is never touched (5.x titles may carry <i>, <b> …) ---\n";
$mx = fixture('ojs35-5.4.0.xml');
$tt = $mx->getElementsByTagNameNS($mx->documentElement->namespaceURI, 'title')->item(0);
while ($tt->firstChild) $tt->removeChild($tt->firstChild);
$tt->appendChild($mx->createElementNS($mx->documentElement->namespaceURI, 'i', 'E. coli'));
$tt->appendChild($mx->createTextNode(' '));
$tt->appendChild($mx->createElementNS($mx->documentElement->namespaceURI, 'b', 'growth'));
list($o, $r) = CcrCompleter::complete($mx, $dataFor, $all);
$ot = $o->getElementsByTagNameNS($o->documentElement->namespaceURI, 'title')->item(0);
ck('the space between two marked-up words survives completion ("E. coli growth", not "E. coligrowth")', $r['changed'] && $ot->textContent === 'E. coli growth', $ot->textContent);

echo "\n--- libxml's error list, as OJS's export reads it ---\n";
libxml_clear_errors();
$junk = new DOMDocument(); @$junk->loadXML('<a><b></a>');                     // an error OJS's own work might leave
$n0 = count(libxml_get_errors());
list($o, $r) = CcrCompleter::complete(ojsBuilt(), $ojsData, $all);
ck('an XML error already pending (OJS\'s): nothing is done, OJS\'s file goes, the error is left for OJS to report', !$r['changed'] && $r['kept_original'] === true && (bool) preg_grep('/could not be copied/', $r['problems']) && count(libxml_get_errors()) === $n0 && $n0 > 0, [$r['problems'], $n0, count(libxml_get_errors())]);
libxml_clear_errors();

echo "\n--- what OJS holds, said even when nothing is added ---\n";
list($o, $r) = CcrCompleter::complete(ojsBuilt(), function ($doi) { return ['references' => [], 'authors' => [['given' => 'MAHESH, C. M.', 'family' => '', 'affiliation' => '', 'ror' => '']]]; }, $all);
ck('an article with no references and no institutions in OJS: nothing added, and both said', !$r['changed'] && $r['articles']['10.5555/ehj.11.1.102']['notes'] === ['references: none entered in OJS', 'affiliations: none entered in OJS'], $r['articles']);

echo "\n--- what was sent, counted for the read-back ---\n";
list($o, $r) = CcrCompleter::complete(fixture('ojs34-5.3.1.xml'), $dataFor, $all);
ck('article A: 11 references, 1 affiliation, 1 ORCID, licence, 2 links counted as sent', $r['articles'][$DOI_A]['sent']['references'] === 11 && $r['articles'][$DOI_A]['sent']['affiliations'] === 1 && $r['articles'][$DOI_A]['sent']['orcid'] === 1 && $r['articles'][$DOI_A]['sent']['license'] && $r['articles'][$DOI_A]['sent']['links'] === 2, $r['articles'][$DOI_A]['sent']);
done();
