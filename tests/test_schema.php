<?php
/** The order rules: version read from the file, elements placed where the schema wants them, mistakes named. */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrSchema.php';

echo "--- the schema version of a file ---\n";
ck('OJS 3.3 file: 4.3.6', CcrSchema::version(fixture('ojs33-4.3.6.xml')) === '4.3.6');
ck('OJS 3.4 file: 5.3.1', CcrSchema::version(fixture('ojs34-5.3.1.xml')) === '5.3.1');
ck('OJS 3.5 file: 5.4.0', CcrSchema::version(fixture('ojs35-5.4.0.xml')) === '5.4.0');
$n = new DOMDocument(); $n->loadXML('<doi_batch xmlns="http://example.org/x"/>');
ck('another namespace: not a Crossref file', CcrSchema::version($n) === '');
ck('structured affiliations (ROR) from 5.3.0 on, not in 4.3.6', CcrSchema::hasStructuredAffiliations('5.3.1') && CcrSchema::hasStructuredAffiliations('5.4.0') && !CcrSchema::hasStructuredAffiliations('4.3.6'));

echo "\n--- placing an element ---\n";
$d = fixture('ojs34-5.3.1.xml');
$a = CcrSchema::articles($d)[0];
ck('the article\'s DOI is read from doi_data', CcrSchema::articleDoi($a) === '10.5555/ejt.2025.v10.i12.pp35-41');
ck('OJS\'s own file is in order', CcrSchema::articleProblems($a) === [], CcrSchema::articleProblems($a));
$ns = $d->documentElement->namespaceURI;
CcrSchema::insertInOrder($a, $d->createElementNS($ns, 'citation_list'), CcrSchema::ARTICLE_ORDER);
ck('citation_list goes after doi_data (the end)', substr(childKeys($a), -strlen('doi_data,citation_list')) === 'doi_data,citation_list', childKeys($a));
CcrSchema::insertInOrder($a, $d->createElementNS($ns, 'component_list'), CcrSchema::ARTICLE_ORDER);
$cl = CcrSchema::child($a, 'citation_list'); $a->removeChild($cl);
CcrSchema::insertInOrder($a, $cl, CcrSchema::ARTICLE_ORDER);
ck('…and before component_list when there is one', substr(childKeys($a), -strlen('doi_data,citation_list,component_list')) === 'doi_data,citation_list,component_list', childKeys($a));
CcrSchema::insertInOrder($a, $d->createElementNS(CcrSchema::NS_FR, 'fr:program'), CcrSchema::ARTICLE_ORDER);
ck('fr:program goes before ai:program', strpos(childKeys($a), 'pages,fr:program,ai:program,doi_data') !== false, childKeys($a));
$threw = false; try { CcrSchema::insertInOrder($a, $d->createElementNS($ns, 'banana'), CcrSchema::ARTICLE_ORDER); } catch (RuntimeException $e) { $threw = true; }
ck('an element with no place in the order is refused, never guessed', $threw);

echo "\n--- naming mistakes ---\n";
$d2 = fixture('ojs34-5.3.1.xml'); $a2 = CcrSchema::articles($d2)[0];
$a2->insertBefore($d2->createElementNS($d2->documentElement->namespaceURI, 'citation_list'), CcrSchema::child($a2, 'titles'));
ck('a reference list before the titles is named', (bool) preg_grep('/citation_list|titles comes after/', CcrSchema::articleProblems($a2)), CcrSchema::articleProblems($a2));
$d3 = fixture('ojs34-5.3.1.xml'); $a3 = CcrSchema::articles($d3)[0]; $ns3 = $d3->documentElement->namespaceURI;
CcrSchema::insertInOrder($a3, $d3->createElementNS($ns3, 'crossmark'), CcrSchema::ARTICLE_ORDER);
ck('Crossmark beside a bare licence program is named (the schema\'s choice)', (bool) preg_grep('/crossmark and a bare/', CcrSchema::articleProblems($a3)), CcrSchema::articleProblems($a3));
$d4 = fixture('ojs34-5.3.1.xml'); $a4 = CcrSchema::articles($d4)[0];
$a4->appendChild($d4->createElementNS($d4->documentElement->namespaceURI, 'citation_list'));
$a4->appendChild($d4->createElementNS($d4->documentElement->namespaceURI, 'citation_list'));
ck('a reference list written twice is named', (bool) preg_grep('/citation_list written twice/', CcrSchema::articleProblems($a4)), CcrSchema::articleProblems($a4));

echo "\n--- counting what a file carries ---\n";
$s = CcrSchema::summarize(CcrSchema::articles(fixture('ojs34-5.3.1.xml'))[0]);
ck('Example article A as OJS writes it: 1 author with ORCID, abstract, licence, 2 links, no references', $s['authors'] === 1 && $s['orcid'] === 1 && $s['abstract'] && $s['license'] && $s['links'] === 2 && $s['references'] === 0, $s);
$s35 = CcrSchema::summarize(CcrSchema::articles(fixture('ojs35-5.4.0.xml'))[1]);
ck('OJS 3.5 article B: 3 authors, the first with an affiliation and a ROR ID', $s35['authors'] === 3 && $s35['affiliations'] === 1 && $s35['ror'] === 1, $s35);
done();
