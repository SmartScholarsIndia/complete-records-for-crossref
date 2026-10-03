<?php
/**
 * What OJS holds for the two example articles in the fixtures — shared by test_completer.php and emit_completed.php, so the
 * file that is checked against Crossref's schema is completed from exactly the data the completer tests use.
 * The references are the journal's own list as Crossref shows it; the affiliations and ROR IDs are test values, not
 * anyone's real institution.
 */
$DOI_A = '10.5555/ejt.2025.v10.i12.pp35-41';
$DOI_B = '10.5555/ejt.2020.v5.i3.pp15-18';
$refsA = json_decode(file_get_contents(__DIR__ . '/fixtures/references-a.json'), true)['references'];
$ojs = [
    $DOI_A => ['references' => $refsA, 'authors' => [['given' => 'Mira', 'family' => 'Sample', 'affiliation' => 'Test College of Food Technology (test value)', 'ror' => '']]],
    $DOI_B => ['references' => [], 'authors' => [
        ['given' => 'Leo K.', 'family' => 'Tester', 'affiliation' => 'Test Institute of Technology (test value)', 'ror' => 'https://ror.org/0abcdef12'],
        ['given' => 'Ana R.', 'family' => 'Example', 'affiliation' => '', 'ror' => ''],
        ['given' => 'Ravi T', 'family' => 'Example', 'affiliation' => 'Second Test University (test value)', 'ror' => 'ror.org/0zyxwvu98'],
    ]],
];
$dataFor = function ($doi) use ($ojs) { return isset($ojs[$doi]) ? $ojs[$doi] : null; };
