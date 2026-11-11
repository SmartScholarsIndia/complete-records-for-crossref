<?php
/**
 * Complete Records for Crossref — the plugin's own files, and nothing else, in its own folder.
 *
 * 1.0.0.7 (7 Nov 2026): OJS's files directory belongs outside the website's folder, but on a server where it sits inside
 * it, whatever is put in the plugin's folders can be opened, and run, from the web. The plugin only ever wrote small
 * "<md5 of a DOI>.txt" notes, ".json" records and ".xsd" schema copies there — but the schema copies took their names from
 * the addresses the schema files themselves name, so not every name was the plugin's own. So every file the plugin
 * keeps is now written by write() here, and nothing else writes:
 *  - the name is one the plugin built: letters, digits, "_", "-" and dots in every part of the path, no hidden name, no
 *    "..", and it ends in the one ending its use allows (.txt notes, .json records, .xsd schema copies);
 *  - a name a web server could run is refused whatever the use: .php (and .php5 …), .pht, .phtml, .phar, .phps, .shtml,
 *    .shtm, .cgi, .pl, .py, .sh, .htaccess, in any letter case and anywhere in the name (Apache runs "x.php.txt" too);
 *  - folders are made 0750 and files 0640: no other account on the server writes or reads them, and a web server that runs
 *    as another user cannot even enter them;
 *  - the folder carries a deny-all .htaccess (guard()), so where it is inside the website's folder a server that reads
 *    Apache's rules serves and runs nothing in it. OJS's own advice stands: the files directory belongs outside the
 *    website's folder.
 * The plugin serves no file to a browser: what it keeps is read by the plugin alone.
 *
 * PHP 7.3 syntax on purpose: OJS 3.3 still runs there.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */
final class CcrFiles
{
    /** Endings a web server can run, as the last part of a name or before another dot, in any letter case. */
    const RUNNABLE = '~\.(php\d*|pht|phtml|phar|phps|shtml?|cgi|pl|py|sh|htaccess)(\.|$)~i';

    /**
     * The guard: nothing in the folder is served or run, for Apache 2.4 and 2.2 and the servers that read Apache's rules.
     * A server that reads no .htaccess (OpenLiteSpeed reads only rewrite rules there) is held off by the 0750 folder.
     */
    const HTACCESS = "# Complete Records for Crossref: nothing in this folder is ever served or run.\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    /**
     * Whether $rel ("crossmark/16/<md5>.txt") is a path the plugin may write inside its folder: every part plain and not
     * hidden, the last one ending in one of $ext (exactly, lower case) and nothing in it a web server could run.
     */
    public static function safe($rel, array $ext)
    {
        $parts = explode('/', (string) $rel);
        foreach ($parts as $p) {
            if (!preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*$~D', $p)) return false;
        }
        $name = end($parts);
        return !preg_match(self::RUNNABLE, $name) && in_array(pathinfo($name, PATHINFO_EXTENSION), $ext, true);
    }

    /**
     * Write $data at $path inside the folder $root, after the guard: atomically (a part file, then a rename), new folders
     * 0750, the file 0640. False, with nothing written or made, for a path outside $root or one safe() refuses.
     */
    public static function write($root, $path, $data, array $ext)
    {
        $root = rtrim((string) $root, '/');
        $path = (string) $path;
        if ($root === '' || strpos($path, $root . '/') !== 0 || !self::safe(substr($path, strlen($root) + 1), $ext)) return false;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
        self::guard($root);
        return self::put($path, $data);
    }

    /**
     * The deny-all .htaccess in $root, when it is not there word for word; whatever stood there before is kept beside it
     * as .htaccess.was-<time>-<process> (never deleted; a web server serves no name that starts ".ht"), and the folder is
     * closed to other accounts (0750). Nothing is made when $root is not a folder.
     * @return bool whether the guard is in place
     */
    public static function guard($root)
    {
        $root = rtrim((string) $root, '/');
        if ($root === '' || !is_dir($root)) return false;
        $f = $root . '/.htaccess';
        if (@file_get_contents($f) === self::HTACCESS) return true;
        @chmod($root, 0750);
        return self::put($f, self::HTACCESS, $f . '.was-' . gmdate('Ymd-His') . '-' . getmypid());
    }

    /** A part file first, then (what stood at $path moved to $keep, when given and there) the rename; 0640. */
    private static function put($path, $data, $keep = '')
    {
        $tmp = $path . '.part' . getmypid();
        if (@file_put_contents($tmp, $data) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        if ($keep !== '' && file_exists($path)) @rename($path, $keep);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
