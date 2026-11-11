<?php
/**
 * core/CcrFiles.php (1.0.0.7) — the plugin keeps only its own files, in its own guarded folder, also where OJS's files
 * directory sits inside the website's folder. Proved here: a name the plugin did not build, a hidden name, or a name a web
 * server could run (any letter case, anywhere in the name) is refused with nothing written; folders are 0750, files 0640,
 * and the folder carries a deny-all .htaccess; a file someone else put there is left as it is (never the plugin's to
 * delete); the schema copy never fetches, reads or keeps a file a schema names unless it is a plain .xsd; and the normal
 * flows (the Crossmark note, the record kept, the schema copy) still work. Part B runs a real Apache 2.4 with mod_php over
 * the folder inside a website's folder: a script put there runs before, and is refused after (SKIPPED where Apache is
 * absent, never counted as a pass).
 */
require __DIR__ . '/_ck.php';
require __DIR__ . '/../core/CcrCrossmark.php';
require __DIR__ . '/../core/CcrValidator.php';
umask(022);   // the usual one; the modes below must come from the plugin, not from a strict umask

function tmpd($p) { $d = sys_get_temp_dir() . '/' . $p . bin2hex(random_bytes(4)); mkdir($d, 0755, true); return $d; }
function rrm($d) { if (!is_dir($d)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() && !$f->isLink() ? rmdir($f) : unlink($f); } rmdir($d); }
function mode($p) { clearstatcache(); return substr(sprintf('%o', fileperms($p)), -4); }
/** Every file under $d, relative, sorted (folders left out). */
function tree($d) { $o = []; if (is_dir($d)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) $o[] = substr((string) $f, strlen($d) + 1); sort($o); return $o; }

$DOI = '10.5555/ejt.2025.v10.i12.pp35-41';
$md5 = md5($DOI);

echo "--- names: only the plugin's own ---\n";
ck('a Crossmark note, crossmark/16/<md5>.txt; a record kept, crossmark-record/16/<md5>.json', CcrFiles::safe("crossmark/16/$md5.txt", ['txt']) && CcrFiles::safe("crossmark-record/16/$md5.json", ['json']));
ck('Crossref\'s schema files, in folders by host and path, and the stand-in note', CcrFiles::safe('www.crossref.org/schemas/crossref5.3.1.xsd', ['xsd'])
   && CcrFiles::safe('www.crossref.org/schemas/standard-modules/mathml3/mathml3-content.xsd', ['xsd']) && CcrFiles::safe('www.w3.org/2009/01/xml.xsd', ['xsd'])
   && CcrFiles::safe('www.crossref.org/schemas/JATS-journalpublishing1-3d2-mathml3-elements.xsd', ['xsd']) && CcrFiles::safe('www.crossref.org/schemas/xml.xsd.stand-in', ['xsd', 'stand-in']));
$runnable = ['x.php', 'x.PHP', 'x.PhP', 'x.php5', 'x.php7', 'x.pht', 'x.phtml', 'x.PHTML', 'x.phar', 'x.phps', 'x.shtml', 'x.shtm', 'x.cgi', 'x.CGI',
             'x.pl', 'x.py', 'x.sh', 'x.Sh', 'x.htaccess', 'x.HTACCESS', 'x.php.txt', 'x.pHp.json', 'x.cgi.xsd', 'x.phtml.xsd', 'shell.php.txt', 'x-api.cgi'];
$bad = [];
foreach ($runnable as $n) foreach ([['txt'], ['json'], ['xsd'], ['php', 'PHP', 'phtml', 'phar', 'shtml', 'cgi', 'pl', 'py', 'sh', 'htaccess', 'txt', 'json', 'xsd']] as $ext) {
    if (CcrFiles::safe("crossmark/16/$n", $ext)) $bad[] = "$n (" . implode(',', $ext) . ')';
}
ck('a name a web server could run is refused whatever ending the use allows — even a use that wrongly allowed .php (' . count($runnable) . ' names: any case, anywhere in the name)', $bad === [], $bad);
$hostile = ['.htaccess', '.HTACCESS', '.user.ini', '.x.txt', '../x.txt', 'crossmark/../../x.txt', 'crossmark/16/../x.txt', '/etc/x.txt', 'crossmark//x.txt', 'crossmark/16/',
            "x.txt\0.php", "x\n.txt", "x.txt\n", 'x .txt', 'x.txt ', 'x;.txt', '-x.txt', 'x', 'x.TXT', 'x.txt.part', '', 'crossmark/.hidden/x.txt', 'crossmark/16/x.txt/'];
$bad = [];
foreach ($hostile as $n) if (CcrFiles::safe($n, ['txt'])) $bad[] = json_encode($n);
ck('hidden names, "..", absolute or empty parts, spaces, control bytes, no ending or another (case too): refused (' . count($hostile) . ')', $bad === [], $bad);
ck('the DOI and the journal never reach a name: the note is named by the DOI\'s md5, under the journal\'s number',
   CcrCrossmark::markerPath('/f', '16/../../x', '../../shell.php') === '/f/crossrefComplete/crossmark/16/' . md5('../../shell.php') . '.txt'
   && CcrCrossmark::recordPath('/f', '7.php', '<?php system($_GET[1]);') === '/f/crossrefComplete/crossmark-record/7/' . md5('<?php system($_get[1]);') . '.json');

echo "\n--- write(): the file, its folders, the guard ---\n";
$base = tmpd('ccrf-');
$root = "$base/crossrefComplete";   // not there yet, as on a journal before its first note
$p = "$root/crossmark/16/$md5.txt";
ck('a note written, word for word', CcrFiles::write($root, $p, "data\n", ['txt']) && file_get_contents($p) === "data\n");
ck('…its folders made 0750 (no other account on the server writes, reads or enters them), the file 0640',
   mode($root) === '0750' && mode("$root/crossmark") === '0750' && mode("$root/crossmark/16") === '0750' && mode($p) === '0640', [mode($root), mode("$root/crossmark"), mode("$root/crossmark/16"), mode($p)]);
ck('…the deny-all .htaccess in the plugin\'s folder, word for word, 0640', file_get_contents("$root/.htaccess") === CcrFiles::HTACCESS && mode("$root/.htaccess") === '0640');
ck('…it denies everything on Apache 2.4 (Require all denied) and 2.2 (Deny from all)', strpos(CcrFiles::HTACCESS, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>") !== false
   && strpos(CcrFiles::HTACCESS, "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>") !== false);
ck('…and nothing else: no part file left behind', tree($root) === ['.htaccess', "crossmark/16/$md5.txt"], tree($root));
ck('written again: replaced in place, still 0640', CcrFiles::write($root, $p, "again\n", ['txt']) && file_get_contents($p) === "again\n" && mode($p) === '0640' && tree($root) === ['.htaccess', "crossmark/16/$md5.txt"]);
$before = tree($root);
$refused = [];
foreach (["$root/crossmark/16/shell.php", "$root/crossmark/16/x.php.txt", "$root/crossmark/16/X.PhTmL", "$root/crossmark/16/.htaccess", "$root/.htaccess",
          "$root/crossmark/16/up.cgi", "$root/crossmark/16/a.pl", "$root/crossmark/16/a.py", "$root/crossmark/16/a.sh", "$root/crossmark/16/a.shtml", "$root/crossmark/16/a.phar"] as $h) {
    if (CcrFiles::write($root, $h, '<?php echo 1;', ['txt'])) $refused[] = $h;
}
ck('hostile names: every one refused, nothing written, the guard untouched', $refused === [] && tree($root) === $before && file_get_contents("$root/.htaccess") === CcrFiles::HTACCESS, [$refused, tree($root)]);
ck('a hostile folder: refused before any folder is made', !CcrFiles::write($root, "$root/cgi-bin/../../x.txt", 'x', ['txt']) && !CcrFiles::write($root, "$root/.well-known/x.txt", 'x', ['txt'])
   && !is_dir("$root/cgi-bin") && !is_dir("$root/.well-known") && tree($root) === $before);
ck('a path outside the plugin\'s folder: refused, nothing there', !CcrFiles::write($root, "$base/x.txt", 'x', ['txt']) && !CcrFiles::write($root, "{$root}X/x.txt", 'x', ['txt'])
   && !CcrFiles::write('', '/tmp/x.txt', 'x', ['txt']) && !file_exists("$base/x.txt") && !file_exists("{$root}X"));

echo "\n--- guard(): a folder with a script someone else put there ---\n";
$r2 = tmpd('ccrf-') . '/crossrefComplete';
mkdir("$r2/crossmark/16", 0755, true);   // as 1.0.0.6 made it
chmod($r2, 0755);
$kit = "<?php /* a script the plugin did not write */ echo 'RAN';";
file_put_contents("$r2/crossmark/16/shell.php", $kit);
$theirs = "<FilesMatch \".*\\.(php|PHP)$\">\nOrder Allow,Deny\nAllow from all\n</FilesMatch>\n";
file_put_contents("$r2/.htaccess", $theirs);   // an .htaccess the plugin did not write
ck('the guard is put in place over an .htaccess the plugin did not write', CcrFiles::guard($r2) && file_get_contents("$r2/.htaccess") === CcrFiles::HTACCESS && mode("$r2/.htaccess") === '0640');
$was = glob("$r2/.htaccess.was-*");
ck('…what stood there is kept beside it, word for word, never deleted (a web server serves no name starting ".ht")', count($was) === 1 && file_get_contents($was[0]) === $theirs, $was);
ck('…the folder closed to other accounts (0755 → 0750)', mode($r2) === '0750');
ck('…the other file is left exactly as it was, for the site\'s administrator (the plugin deletes nothing it did not write)', file_get_contents("$r2/crossmark/16/shell.php") === $kit);
ck('a second time: nothing changes, no second copy', CcrFiles::guard($r2) && count(glob("$r2/.htaccess.was-*")) === 1 && file_get_contents("$r2/.htaccess") === CcrFiles::HTACCESS);
ck('no folder there (a journal that never used the plugin\'s files): nothing made', !CcrFiles::guard("$r2/none") && !file_exists("$r2/none") && !CcrFiles::guard(''));

echo "\n--- the plugin's own flows ---\n";
$fd = tmpd('ccrf-files-');
ck('the Crossmark note: written and read', CcrCrossmark::mark($fd, 16, strtoupper($DOI), '10.5555/example.crossmark') && CcrCrossmark::marked($fd, 16, $DOI));
ck('…in the guarded folder, 0640 in 0750 folders', file_get_contents("$fd/crossrefComplete/.htaccess") === CcrFiles::HTACCESS && mode(CcrCrossmark::markerPath($fd, 16, $DOI)) === '0640'
   && mode("$fd/crossrefComplete") === '0750' && mode("$fd/crossrefComplete/crossmark/16") === '0750');
$rp = CcrCrossmark::recordPath($fd, 16, $DOI);
ck('the record of what Crossref said: written and read', CcrCrossmark::writeRecord($rp, $DOI, '10.1/p', '10.1/p', 5) && CcrCrossmark::readRecord($rp) === ['policy' => '10.1/p', 'found' => '10.1/p', 'at' => 5] && mode($rp) === '0640');
ck('…a record path that is not a .json name the plugin built: refused, nothing written', !CcrCrossmark::writeRecord("$fd/crossrefComplete/crossmark-record/16/x.php", $DOI, 'p', 'p')
   && !CcrCrossmark::writeRecord("$fd/crossrefComplete/crossmark-record/16/x.php.json", $DOI, 'p', 'p') && !file_exists("$fd/crossrefComplete/crossmark-record/16/x.php"));
ck('…and taken off again (a later deposit without Crossmark)', CcrCrossmark::unmark($fd, 16, $DOI) && !CcrCrossmark::marked($fd, 16, $DOI));
ck('nothing but the plugin\'s own files in its folder', tree("$fd/crossrefComplete") === ['.htaccess', "crossmark-record/16/$md5.json"], tree("$fd/crossrefComplete"));

echo "\n--- the schema copy: .xsd only, whatever a schema names ---\n";
if (!class_exists('DOMDocument')) {
    echo "  SKIPPED — this PHP has no DOM extension (the workspace's extra PHP 7.4, 8.1 and 8.4 builds); the suite's own PHP runs it. Not counted as a pass.\n";
} else {
    $web = tmpd('ccrf-web-');
    $S = "$web/www.crossref.org/schemas";
    mkdir($S, 0755, true);
    $xs = 'http://www.w3.org/2001/XMLSchema';
    $schema = function ($v, $extra, $body) use ($xs) {
        return "<?xml version=\"1.0\"?>\n<xsd:schema xmlns:xsd=\"$xs\" xmlns=\"http://www.crossref.org/schema/$v\" targetNamespace=\"http://www.crossref.org/schema/$v\" elementFormDefault=\"qualified\">$extra$body</xsd:schema>\n";
    };
    $batch = '<xsd:element name="doi_batch"><xsd:complexType><xsd:sequence><xsd:element name="head" type="xsd:string"/><xsd:element name="body" type="t"/></xsd:sequence><xsd:attribute name="version" type="xsd:string"/></xsd:complexType></xsd:element>';
    file_put_contents("$S/crossref9.9.9.xsd", $schema('9.9.9', '<xsd:include schemaLocation="common9.9.9.xsd"/>', $batch));
    file_put_contents("$S/common9.9.9.xsd", $schema('9.9.9', '', '<xsd:simpleType name="t"><xsd:restriction base="xsd:string"/></xsd:simpleType>'));
    // a set naming files a web server would run; each body even carries the XML Schema namespace, so before 1.0.0.7 it was kept
    $shell = "<?php /* $xs */ system(\$_GET['c']);";
    file_put_contents("$S/crossref9.9.8.xsd", $schema('9.9.8', '<xsd:include schemaLocation="common9.9.9.xsd"/>'
        . '<xsd:import namespace="urn:a" schemaLocation="https://evil.example/x/shell.php"/><xsd:import namespace="urn:b" schemaLocation="https://evil.example/x/up.php.xsd"/>'
        . '<xsd:import namespace="urn:c" schemaLocation="https://evil.example/.htaccess"/><xsd:import namespace="urn:d" schemaLocation="https://evil.example/x/run.CGI"/>', str_replace('9.9.9', '9.9.8', $batch)));
    $fetched = [];
    $fetch = function ($url) use ($web, $shell, &$fetched) {
        $fetched[] = $url;
        $u = parse_url($url);
        if ($u['host'] === 'evil.example') return $shell;
        $p = $web . '/' . $u['host'] . $u['path'];
        return is_file($p) ? file_get_contents($p) : null;
    };
    $doc = function ($v) { $d = new DOMDocument(); $d->loadXML("<?xml version=\"1.0\"?>\n<doi_batch xmlns=\"http://www.crossref.org/schema/$v\" version=\"$v\"><head>h</head><body>b</body></doi_batch>"); return $d; };
    $cache = tmpd('ccrf-cache-');
    $r = CcrValidator::check($doc('9.9.9'), $cache, $fetch);
    ck('a plain set: checked as before — valid, its two files fetched once and kept', $r['state'] === 'valid' && $r['files'] === 2 && count($fetched) === 2, $r);
    ck('…kept 0640 in 0750 folders, under the deny-all guard (the WordPress plugin keeps this copy in its public uploads folder)',
       mode("$cache/www.crossref.org/schemas/common9.9.9.xsd") === '0640' && mode("$cache/www.crossref.org") === '0750' && file_get_contents("$cache/.htaccess") === CcrFiles::HTACCESS);
    $fetched = [];
    $r = CcrValidator::check($doc('9.9.8'), $cache, $fetch);
    ck('a set naming shell.php, up.php.xsd, .htaccess and run.CGI: not checked, each one named and refused', $r['state'] === 'unchecked' && strpos($r['why'], 'refused') !== false
       && strpos($r['why'], 'https://evil.example/x/shell.php') !== false && strpos($r['why'], 'https://evil.example/x/up.php.xsd') !== false && strpos($r['why'], 'https://evil.example/.htaccess') !== false, $r['why']);
    ck('…none of them was even fetched', preg_grep('~evil\.example~', $fetched) === [], $fetched);
    $kept = tree($cache);
    ck('…and the copy holds nothing but .xsd files and its guard', $kept === ['.htaccess', 'www.crossref.org/schemas/common9.9.9.xsd', 'www.crossref.org/schemas/crossref9.9.8.xsd', 'www.crossref.org/schemas/crossref9.9.9.xsd'], $kept);
    mkdir("$cache/evil.example/x", 0755, true);
    file_put_contents("$cache/evil.example/x/shell.php", $shell);   // put in the copy by someone else
    $r = CcrValidator::check($doc('9.9.8'), $cache, null);
    ck('a .php put in the copy is never read as a schema either: still refused', $r['state'] === 'unchecked' && strpos($r['why'], 'https://evil.example/x/shell.php — refused') !== false, $r['why']);
    rrm($web); rrm($cache);
}
rrm($base); rrm(dirname($r2)); rrm($fd);

echo "\n--- Part B: a real Apache 2.4 with mod_php, OJS's files directory inside the website's folder ---\n";
$apache = '/usr/sbin/apache2';
$mods = '/usr/lib/apache2/modules';
if (!is_executable($apache) || !is_file("$mods/libphp8.3.so") || !is_file("$mods/mod_mpm_prefork.so") || !function_exists('posix_getpwnam') || posix_getuid() !== 0 || !posix_getpwnam('www-data')) {
    echo "  SKIPPED — needs Apache 2.4 with mod_php 8.3, root and the www-data account (Ubuntu's packages). Not counted as a pass.\n";
} else {
    $site = '/tmp/ccrf-site-' . bin2hex(random_bytes(4));   // /tmp itself: the web server's account must reach it, whatever TMPDIR says
    mkdir($site, 0755);
    $files = "$site/public_html/ojs-files";   // files_dir inside the website's folder
    mkdir("$files/crossrefComplete/crossmark/16", 0755, true);
    chmod("$files/crossrefComplete", 0755);
    file_put_contents("$files/crossrefComplete/crossmark/16/shell.php", "<?php echo 'RAN-' . (6 * 7);");
    chmod("$files/crossrefComplete/crossmark/16/shell.php", 0644);
    $port = function () { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s); return $n; };
    $start = function ($name, $override) use ($site, $apache, $mods, $port) {
        $n = $port();
        $conf = "ServerRoot \"$site\"\nDefaultRuntimeDir \"$site\"\nPidFile \"$site/$name.pid\"\nListen 127.0.0.1:$n\nServerName 127.0.0.1\nUser www-data\nGroup www-data\nErrorLog \"$site/$name-error.log\"\n"
              . "LoadModule mpm_prefork_module $mods/mod_mpm_prefork.so\nLoadModule authz_core_module $mods/mod_authz_core.so\nLoadModule php_module $mods/libphp8.3.so\n"
              . "StartServers 1\nMinSpareServers 1\nMaxSpareServers 2\nDocumentRoot \"$site/public_html\"\n<Directory \"$site/public_html\">\n    AllowOverride $override\n    Require all granted\n</Directory>\n"
              . "<FilesMatch \"\\.php$\">\n    SetHandler application/x-httpd-php\n</FilesMatch>\n";
        file_put_contents("$site/$name.conf", $conf);
        exec(escapeshellarg($apache) . ' -f ' . escapeshellarg("$site/$name.conf") . ' -k start 2>&1', $out, $rc);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $n, $e1, $e2, 0.1); $i++) usleep(100000);
        return [$n, $rc, implode(' ', $out)];
    };
    $get = function ($n, $path) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents("http://127.0.0.1:$n/$path", false, $ctx);
        $h = function_exists('http_get_last_response_headers') ? (array) http_get_last_response_headers() : (isset($http_response_header) ? $http_response_header : []);
        $code = isset($h[0]) && preg_match('~ (\d{3}) ~', $h[0], $m) ? (int) $m[1] : 0;
        return [$code, (string) $body];
    };
    [$all, $rc1, $o1] = $start('all', 'All');      // a site whose .htaccess files are read (cPanel's usual)
    [$none, $rc2, $o2] = $start('none', 'None');   // a site that reads none
    $kitUrl = 'ojs-files/crossrefComplete/crossmark/16/shell.php';
    [$c, $b] = $get($all, $kitUrl);
    ck("before 1.0.0.7: a script put in the folder RUNS from the web (HTTP $c, \"$b\") — the hazard, reproduced", $rc1 === 0 && $rc2 === 0 && $c === 200 && $b === 'RAN-42', [$o1, $o2, $c, $b]);
    CcrFiles::guard("$files/crossrefComplete");   // what the plugin does on the first request after the upgrade
    chmod("$files/crossrefComplete", 0755);        // the .htaccess alone first, readable by the web server, so its WORDS decide
    chmod("$files/crossrefComplete/.htaccess", 0644);
    [$c, $b] = $get($all, $kitUrl);
    $log = (string) @file_get_contents("$site/all-error.log");
    ck("the deny-all .htaccess alone (folder 0755, the file readable): HTTP $c, nothing run — Apache's log: \"client denied by server configuration\"",
       $c === 403 && strpos($b, 'RAN') === false && strpos($log, 'client denied by server configuration') !== false && strpos($log, 'shell.php') !== false, [$c, $b, $log]);
    chmod("$files/crossrefComplete/.htaccess", 0640);
    chmod("$files/crossrefComplete", 0750);
    [$c, $b] = $get($none, $kitUrl);
    ck("the 0750 folder alone (a site that reads no .htaccess; the web server runs as another account): HTTP $c, nothing run", $c === 403 && strpos($b, 'RAN') === false, [$c, $b]);
    CcrCrossmark::mark($files, 16, $DOI, '10.5555/example.crossmark');
    [$c, $b] = $get($all, "ojs-files/crossrefComplete/crossmark/16/$md5.txt");
    [$c2, $b2] = $get($all, $kitUrl);
    ck("as shipped (both): the plugin's own note (HTTP $c) and the script put there (HTTP $c2) — neither served, nothing run", $c === 403 && $c2 === 403 && strpos($b . $b2, 'RAN') === false && strpos($b, 'example') === false, [$c, $c2]);
    [$c, $b] = $get($all, 'ojs-files/crossrefComplete/.htaccess');
    ck("…the .htaccess itself not served either (HTTP $c)", $c === 403);
    foreach (['all', 'none'] as $name) exec(escapeshellarg($apache) . ' -f ' . escapeshellarg("$site/$name.conf") . ' -k stop 2>&1');
    for ($i = 0; $i < 50 && (is_file("$site/all.pid") || is_file("$site/none.pid")); $i++) usleep(100000);
    rrm($site);
}
done();
