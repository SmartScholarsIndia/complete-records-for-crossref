<?php
/** The reference list: lines cleaned, a DOI asserted only when exactly one full DOI is written. */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrCitations.php';

echo "--- one reference, cleaned ---\n";
ck('list numbering "1." is the journal\'s, not the reference\'s', CcrCitations::clean('1. Smith J. A study. 2020.') === 'Smith J. A study. 2020.', CcrCitations::clean('1. Smith J. A study. 2020.'));
ck('"[12]" and "(3)" numbering dropped too', CcrCitations::clean('[12] Smith J.') === 'Smith J.' && CcrCitations::clean('(3) Smith J.') === 'Smith J.');
ck('a year in brackets at the start is NOT numbering when no space-led text follows a list mark', CcrCitations::clean('(2020) Annual report') === '(2020) Annual report', CcrCitations::clean('(2020) Annual report'));
ck('tags removed and entities decoded', CcrCitations::clean('<i>Nature</i> &amp; <b>Science</b>') === 'Nature & Science');
ck('runs of spaces, tabs and no-break spaces become one space', CcrCitations::clean("Smith\t J.\xC2\xA0\xC2\xA0 2020") === 'Smith J. 2020');

echo "\n--- the DOI a reference names ---\n";
ck('a DOI written out', CcrCitations::singleDoi('Smith J. (2020) X. Nature 5, 1-2. 10.1038/s41586-020-1234-5') === '10.1038/s41586-020-1234-5');
ck('a doi.org link is the same DOI', CcrCitations::singleDoi('… https://doi.org/10.1016/j.lssr.2023.08.002') === '10.1016/j.lssr.2023.08.002');
ck('a "doi:" prefix is the same DOI', CcrCitations::singleDoi('… doi: 10.3390/foods12173287.') === '10.3390/foods12173287');
ck('the full stop ending the sentence is not part of the DOI', CcrCitations::singleDoi('… 10.1108/bfj-05-2020-0441.') === '10.1108/bfj-05-2020-0441');
ck('upper case is folded (DOIs are case-insensitive)', CcrCitations::singleDoi('10.1017/S0029665123003002') === '10.1017/s0029665123003002');
ck('the same DOI twice (text and link) is still one DOI', CcrCitations::singleDoi('10.1080/x.1 https://doi.org/10.1080/X.1') === '10.1080/x.1');
ck('two different DOIs: none asserted', CcrCitations::singleDoi('10.1000/a and 10.1000/b') === '');
ck('no DOI: none', CcrCitations::singleDoi('Smith J. A book. Oxford, 1999.') === '');
ck('a DOI that opens a bracket keeps its closing one: 10.1002/(SICI)…', CcrCitations::singleDoi('x 10.1002/(SICI)1097-4571(199806)49:8 y') === '10.1002/(sici)1097-4571(199806)49:8');
ck('a closing bracket around the DOI is dropped: (10.1000/abc)', CcrCitations::singleDoi('(10.1000/abc)') === '10.1000/abc');
ck('an old DOI with angle brackets would be cut short: none asserted', CcrCitations::singleDoi('10.1002/(SICI)1097-4571(199806)49:8<693::AID-ASI4>3.0.CO;2-0') === '');
ck('a DOI with characters outside Crossref\'s modern pattern: none asserted', CcrCitations::singleDoi('10.1000/abc{def}') === '');

echo "\n--- the list, ready for Crossref ---\n";
$refs = json_decode(file_get_contents(__DIR__ . '/fixtures/references-a.json'), true)['references'];
$p = CcrCitations::prepare($refs);
ck('article A\'s 11 references (as Crossref shows them) prepared as 11, keyed ref1…ref11', count($p) === 11 && $p[0]['key'] === 'ref1' && $p[10]['key'] === 'ref11', count($p));
ck('a reference whose text is only its DOI keeps the DOI', $p[1]['doi'] === '10.1016/j.promfg.2015.09.057', $p[1]);
ck('empty lines are skipped and keys stay continuous', array_column(CcrCitations::prepare(['', 'A one.', '   ', 'B two.']), 'key') === ['ref1', 'ref2']);
ck('a 3,000-character line is cut to 1,000 characters', mb_strlen(CcrCitations::prepare([str_repeat('é', 3000)])[0]['text'], 'UTF-8') === 1000);
ck('a raw block splits one reference per line, Windows line ends too', CcrCitations::splitRaw("A one.\r\n\r\nB two.\rC three.") === ['A one.', 'B two.', 'C three.']);
done();
