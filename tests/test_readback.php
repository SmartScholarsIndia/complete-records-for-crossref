<?php
/** After the deposit: Crossref's own record compared with what was sent, element by element. */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrReadBack.php';

$work = json_decode(file_get_contents(__DIR__ . '/fixtures/crossref-work-a.json'), true);   // the example record, as Crossref showed it
$have = CcrReadBack::summarizeWork($work);
echo "--- Crossref's record for 10.5555/ejt.2025.v10.i12.pp35-41, counted ---\n";
ck('11 references, 1 author with ORCID, abstract, licence, 2 links, no affiliation, no funding, no Crossmark',
   $have['references'] === 11 && $have['authors'] === 1 && $have['orcid'] === 1 && $have['abstract'] && $have['license'] && $have['links'] === 2
   && $have['affiliations'] === 0 && $have['funders'] === 0 && $have['crossmark'] === '', $have);

echo "\n--- compared with what was sent ---\n";
$sent = ['references' => 11, 'affiliations' => 1, 'ror' => 0, 'orcid' => 1, 'authors' => 1, 'abstract' => true, 'license' => true,
         'funders' => 0, 'links' => 2, 'crossmark' => '', 'relations' => 0];
$c = CcrReadBack::compare($sent, $work);
ck('references, ORCID, abstract, licence, links: landed', $c['references'] === 'landed' && $c['orcid'] === 'landed' && $c['abstract'] === 'landed' && $c['license'] === 'landed' && $c['links'] === 'landed', $c);
ck('the affiliation sent but not on the record: missing', $c['affiliations'] === 'missing', $c);
ck('elements not sent (funding, ROR, Crossmark, relations) are not listed at all', !isset($c['funders']) && !isset($c['ror']) && !isset($c['crossmark']) && !isset($c['relations']), $c);
$c2 = CcrReadBack::compare(['references' => 14] + $sent, $work);
ck('14 references sent, 11 on the record: partly', $c2['references'] === 'partly', $c2);

echo "\n--- before Crossref has processed the deposit ---\n";
$after = strtotime((string) $work['deposited']['date-time']) + 3600;
$c3 = CcrReadBack::compare($sent, $work, $after);
ck('a record last deposited BEFORE ours: every element "waiting", never "missing"', array_unique(array_values($c3)) === ['waiting'], $c3);
$c4 = CcrReadBack::compare($sent, $work, strtotime((string) $work['deposited']['date-time']) + 30);
ck('half a minute of clock difference does not count as waiting', $c4['references'] === 'landed', $c4);

echo "\n--- Crossmark and ROR on a record ---\n";
$w = $work;
$w['update-policy'] = 'https://doi.org/10.5555/EJT.CROSSMARK-POLICY';
$w['author'][0]['affiliation'] = [['name' => 'Test College (test value)', 'id' => [['id' => 'https://ror.org/0abcdef12', 'id-type' => 'ROR', 'asserted-by' => 'publisher']]]];
$c5 = CcrReadBack::compare(['crossmark' => '10.5555/ejt.crossmark-policy', 'ror' => 1, 'affiliations' => 1] + $sent, $w);
ck('Crossmark policy matched whatever its case and doi.org form; ROR ID and affiliation landed', $c5['crossmark'] === 'landed' && $c5['ror'] === 'landed' && $c5['affiliations'] === 'landed', $c5);
ck('one line a person can read', CcrReadBack::sentence(['references' => 'landed', 'ror' => 'missing']) === 'reference list landed · ROR IDs missing');
done();
