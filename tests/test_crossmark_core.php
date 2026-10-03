<?php
/**
 * CcrCrossmark — Crossref's Crossmark button: the tag and the button as Crossref publishes them, where the button goes in
 * a page (and where it never goes), the note kept per DOI, and the read-back's deposits cut to fit OJS 3.3's column.
 */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrCrossmark.php';

echo "--- the tag and the button ---\n";
ck('the tag the widget reads the DOI from: dc.identifier, "doi:" before the DOI', CcrCrossmark::metaTag('10.5555/EHJ.11.S1.32') === '<meta name="dc.identifier" content="doi:10.5555/EHJ.11.S1.32" />');
ck('…a DOI is escaped in the tag', strpos(CcrCrossmark::metaTag('10.1/a"b<c'), 'content="doi:10.1/a&quot;b&lt;c"') !== false, CcrCrossmark::metaTag('10.1/a"b<c'));
$b = CcrCrossmark::button();
ck("Crossref's widget, from Crossref's own address (v2.0)", strpos($b, '<script src="https://crossmark-cdn.crossref.org/widget/v2.0/widget.js"></script>') !== false, $b);
ck('the anchor the widget binds to, with the colour button and Crossref\'s width', strpos($b, '<a data-target="crossmark"><img src="https://crossmark-cdn.crossref.org/widget/v2.0/logos/CROSSMARK_Color_horizontal.svg" width="150"') !== false, $b);
ck('one button, one script', substr_count($b, 'data-target="crossmark"') === 1 && substr_count($b, '<script') === 1);
// 1.0.0.5: a journal's theme stretched the button across the whole column (3 Oct 2026) — the image now holds its own size
ck('1.0.0.5: the image is held at 150 px by a style on the image itself, marked important (outranks any theme rule)',
   strpos($b, 'width="150" style="width:150px !important;min-width:0 !important;max-width:100% !important;height:auto !important"') !== false, $b);
ck('…and still shrinks inside a column narrower than 150 px (max-width 100%), keeping its proportions (height auto)',
   strpos($b, 'max-width:100% !important') !== false && strpos($b, 'height:auto !important') !== false);
ck('…the logo and the anchor themselves unchanged: Crossref\'s address, data-target, alt words',
   strpos($b, '<a data-target="crossmark"><img src="https://crossmark-cdn.crossref.org/widget/v2.0/logos/CROSSMARK_Color_horizontal.svg"') !== false
   && strpos($b, 'alt="Crossmark: check for updates" /></a>') !== false);

echo "\n--- placed just after the article's title ---\n";
$page = '<!DOCTYPE html><html lang="en"><head><title>t</title></head><body class="pkp_page_article pkp_op_view"><header class="pkp_structure_head">'
      . '<div class="pkp_site_name"><a href="/index.php/ehj/index">Example Health Journal</a></div></header><main class="pkp_structure_main">'
      . '<article class="obj_article_details"><h1 class="page_title">An Example Article Title</h1><div class="row"><section class="item abstract">…</section></div>'
      . '</article></main></body></html>';
list($out, $ins) = CcrCrossmark::insert($page, '<B/>');
ck('OJS\'s default theme: right under the title, before the article\'s first row', $ins && strpos($out, '<h1 class="page_title">An Example Article Title</h1><B/><div class="row">') !== false, $out);
list($out) = CcrCrossmark::insert('<html><body><h1 class="pkp_site_name">Journal</h1><article><h1>Title</h1></article></body></html>', '<B/>');
ck('a page whose header holds an <h1>: under the ARTICLE\'s title, not the header\'s', strpos($out, '<article><h1>Title</h1><B/>') !== false && substr_count($out, '<B/>') === 1, $out);
list($out, $ins) = CcrCrossmark::insert('<html><body><div><h1>Title</h1></div></body></html>', '<B/>');
ck('a theme with no <article>: after the first <h1>', $ins && strpos($out, '<h1>Title</h1><B/>') !== false, $out);
list($out, $ins) = CcrCrossmark::insert('<div class="pkp_block"><h1>x</h1></div>', '<B/>');
ck('a fragment (no </body> — a block, a form fetched on its own): unchanged', !$ins && $out === '<div class="pkp_block"><h1>x</h1></div>');
list($out, $ins) = CcrCrossmark::insert('<html><body><p>no title</p></body></html>', '<B/>');
ck('a page with no <h1>: unchanged', !$ins && $out === '<html><body><p>no title</p></body></html>');
$already = '<html><body><article><h1>T</h1><a data-target="crossmark"><img/></a></article></body></html>';
list($out, $ins) = CcrCrossmark::insert($already, '<B/>');
ck('a page already showing a Crossmark button (a theme\'s own): not put twice', !$ins && $out === $already);
list($once) = CcrCrossmark::insert($page, CcrCrossmark::button());
list($twice, $ins2) = CcrCrossmark::insert($once, CcrCrossmark::button());
ck('run twice over the same page: one button', !$ins2 && substr_count($twice, 'data-target="crossmark"') === 1);

echo "\n--- the note kept per DOI ---\n";
$dir = sys_get_temp_dir() . '/ccr-cm-' . bin2hex(random_bytes(4));
$p = CcrCrossmark::markerPath($dir, 7, '10.5555/EHJ.11.S1.32');
ck('one small file per DOI, under its journal, named by the DOI\'s md5, case-blind', $p === $dir . '/crossrefComplete/crossmark/7/' . md5('10.5555/ehj.11.s1.32') . '.txt'
   && $p === CcrCrossmark::markerPath($dir . '/', 7, ' 10.5555/ehj.11.s1.32 '), $p);
ck('not noted yet', !CcrCrossmark::marked($dir, 7, '10.5555/EHJ.11.S1.32'));
ck('noted', CcrCrossmark::mark($dir, 7, '10.5555/EHJ.11.S1.32', '10.5555/example.crossmark') && CcrCrossmark::marked($dir, 7, '10.5555/ehj.11.s1.32'));
ck('…the note says which DOI and which policy', strpos((string) file_get_contents($p), "10.5555/ehj.11.s1.32\t10.5555/example.crossmark\t") === 0, file_get_contents($p));
ck('another journal: not noted', !CcrCrossmark::marked($dir, 8, '10.5555/EHJ.11.S1.32'));
ck('taken off again (a later deposit without Crossmark), and taking off what is not there is fine', CcrCrossmark::unmark($dir, 7, '10.5555/EHJ.11.S1.32') && !CcrCrossmark::marked($dir, 7, '10.5555/EHJ.11.S1.32') && CcrCrossmark::unmark($dir, 7, '10.5555/EHJ.11.S1.32'));
CcrCrossmark::mark($dir, 7, '10.5555/EHJ.11.S1.32', '10.5555/example.crossmark');
ck('no files directory set: nothing written, never noted', CcrCrossmark::markerPath('', 7, '10.1/x') === '' && !CcrCrossmark::mark('', 7, '10.1/x', 'p') && !CcrCrossmark::marked('', 7, '10.1/x'));
array_map('unlink', glob($dir . '/crossrefComplete/crossmark/7/*')); @rmdir($dir . '/crossrefComplete/crossmark/7'); @rmdir($dir . '/crossrefComplete/crossmark'); @rmdir($dir . '/crossrefComplete'); @rmdir($dir);

echo "\n--- the deposits kept for the read-back fit OJS 3.3's column (TEXT, 65,535 bytes) ---\n";
$list = [];
for ($i = 0; $i < 500; $i++) {
    $list['10.5555/ehj.v10.i01.2025.pp' . $i] = ['at' => 1791000000 + $i, 'checked' => 1791000100,
        'sent' => ['references' => 53, 'affiliations' => 3, 'ror' => 0, 'orcid' => 0, 'authors' => 3, 'abstract' => true, 'license' => true, 'funders' => 0, 'links' => 2, 'crossmark' => '10.5555/example.crossmark', 'relations' => 0],
        'result' => ['references' => 'landed', 'affiliations' => 'landed', 'abstract' => 'landed', 'license' => 'landed', 'links' => 'landed', 'crossmark' => 'landed']];
}
ck('500 deposits of a real record\'s size are far more than the column holds', strlen(json_encode($list)) > 65535, strlen(json_encode($list)));
$fit = CcrCrossmark::fitDeposits($list, 500, 60000);
ck('cut to 60,000 bytes', strlen(json_encode($fit)) <= 60000 && count($fit) > 50, [count($fit), strlen(json_encode($fit))]);
ck('…keeping the newest (the first ones, as kept)', array_keys($fit) === array_slice(array_keys($list), 0, count($fit)));
ck('a short list is kept whole', CcrCrossmark::fitDeposits(array_slice($list, 0, 3, true), 500, 60000) === array_slice($list, 0, 3, true));
ck('never more than the number allowed', count(CcrCrossmark::fitDeposits($list, 10, 4000000)) === 10);
ck('an empty list stays empty', CcrCrossmark::fitDeposits([], 500, 60000) === []);
done();
