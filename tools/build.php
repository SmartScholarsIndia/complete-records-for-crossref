<?php
/**
 * Complete Records for Crossref — assemble the plugin for each OJS line, the way it ships.
 *
 *   php ojs-plugin/tools/build.php            assemble build/ojs-3.3|3.4|3.5/crossrefComplete
 *   php ojs-plugin/tools/build.php --tar      …and pack dist/crossrefComplete-<release>-ojs<line>.tar.gz
 *   php ojs-plugin/tools/build.php --quiet    no output unless something is wrong
 *
 * Each package is one folder named crossrefComplete (PKP's Plugin Gallery and "Upload a new plugin" both expect the
 * plugin's own folder at the top of the archive) holding that line's plugin files, the shared core/, LICENSE (GPL v3),
 * README.md and CHANGELOG.md. The release number is read from that line's version.xml, so the archive name can never
 * disagree with what OJS will show.
 */
$root = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);
$tar = in_array('--tar', $argv, true);
$say = function ($s) use ($quiet) { if (!$quiet) echo $s, "\n"; };
$fail = function ($s) { fwrite(STDERR, "build: $s\n"); exit(1); };

function rcopy($from, $to) {
    if (is_dir($from)) {
        if (!is_dir($to) && !mkdir($to, 0755, true)) return false;
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!rcopy("$from/$f", "$to/$f")) return false;
        }
        return true;
    }
    return copy($from, $to);
}
function rrm($p) {
    if (is_dir($p) && !is_link($p)) { foreach (scandir($p) as $f) if ($f !== '.' && $f !== '..') rrm("$p/$f"); return rmdir($p); }
    return !file_exists($p) || unlink($p);
}

$licence = $root . '/LICENSE';
foreach (['core', 'LICENSE', 'README.md', 'CHANGELOG.md'] as $need) if (!file_exists("$root/$need")) $fail("missing $need");
$built = 0;
foreach (['3.3', '3.4', '3.5'] as $line) {
    $src = "$root/ojs-$line/crossrefComplete";
    if (!is_dir($src)) { $say("ojs-$line: not written yet — skipped"); continue; }
    $vx = @simplexml_load_file("$src/version.xml");
    if (!$vx || (string) $vx->application !== 'crossrefComplete') $fail("ojs-$line/version.xml unreadable or not crossrefComplete");
    $release = (string) $vx->release;
    $dest = "$root/build/ojs-$line/crossrefComplete";
    rrm("$root/build/ojs-$line");
    if (!rcopy($src, $dest) || !rcopy("$root/core", "$dest/core")) $fail("copy failed for ojs-$line");
    foreach (['LICENSE', 'README.md', 'CHANGELOG.md'] as $f) copy("$root/$f", "$dest/$f");
    // every PHP file must parse
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (substr($file, -4) !== '.php') continue;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string) $file) . ' 2>&1', $out, $rc);
        if ($rc !== 0) $fail("does not parse: $file — " . implode(' ', $out));
    }
    $built++;
    $say("ojs-$line: assembled release $release in build/ojs-$line/crossrefComplete");
    if ($tar) {
        if (!is_dir("$root/dist")) mkdir("$root/dist", 0755, true);
        $name = "crossrefComplete-$release-ojs$line.tar.gz";
        @unlink("$root/dist/$name");
        // 3 Oct 2026: reproducible — the same tree always packs to the same bytes (files in name order, every time stamp
        // the release's own date from version.xml, owner 0, no name or time in the gzip header), so a package on a
        // release page can be checked against a build of the tagged source, and a second build is never "different"
        $date = (string) $vx->date;
        $mtime = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '2026-01-01') . ' 00:00:00 UTC';
        $tarFile = "$root/dist/" . substr($name, 0, -3);
        @unlink($tarFile);
        exec('tar --sort=name --mtime=' . escapeshellarg($mtime) . ' --owner=0 --group=0 --numeric-owner -cf ' . escapeshellarg($tarFile)
             . ' -C ' . escapeshellarg("$root/build/ojs-$line") . ' crossrefComplete 2>&1', $out, $rc);
        if ($rc !== 0) $fail("tar failed for $name: " . implode(' ', $out));
        exec('gzip -n -9 -f ' . escapeshellarg($tarFile) . ' 2>&1', $out, $rc);
        if ($rc !== 0 || !is_file("$root/dist/$name")) $fail("gzip failed for $name");
        $say("  packed dist/$name (" . filesize("$root/dist/$name") . " bytes)");
    }
}
if (!$built) $fail('nothing to build');
exit(0);
