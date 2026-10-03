<?php
/**
 * Complete Records for Crossref — OJS 3.3 still runs on PHP 7.3, so the shared core and the OJS 3.3 plugin must not use
 * anything newer. There is no PHP 7.3 here to try them on, so this reads every file with PHP's own tokenizer and names
 * each construct that PHP 7.3 would refuse (or, for functions, not have).
 *
 *   php ojs-plugin/tools/check_php73.php      exit 0 when the core, ojs-3.3 and fill33 (where present) are clean
 */
$root = dirname(__DIR__);
$files = [];
foreach (['core', 'ojs-3.3', 'fill33'] as $d) {   // fill33: the OJS 3.3 fill tool runs on the same servers (3 Oct 2026)
    // fill33 is checked where it is kept; a copy of the plugin without it (the public repository) checks the other two
    if ($d === 'fill33' && !is_dir("$root/$d")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if (substr((string) $f, -4) === '.php') $files[] = (string) $f;
}
sort($files);
$newFunctions = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'fdiv', 'get_resource_id',
                 'mb_str_split', 'array_key_first', 'array_key_last' /* 7.3 has these two — kept out of the list below */];
$newFunctions = array_diff($newFunctions, ['array_key_first', 'array_key_last']);
$byName = [
    'T_FN' => 'arrow function fn() => (PHP 7.4)', 'T_MATCH' => 'match (PHP 8.0)', 'T_NULLSAFE_OBJECT_OPERATOR' => '?-> (PHP 8.0)',
    'T_ATTRIBUTE' => 'attribute #[...] (PHP 8.0)', 'T_COALESCE_EQUAL' => '??= (PHP 7.4)', 'T_READONLY' => 'readonly (PHP 8.1)',
    'T_ENUM' => 'enum (PHP 8.1)', 'T_NAME_QUALIFIED' => null, 'T_NAME_FULLY_QUALIFIED' => null,
];
$problems = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    $toks = token_get_all($src);
    $n = count($toks);
    $inClass = 0; $depth = 0; $classDepth = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t)) {
            if ($t === '{') $depth++;
            if ($t === '}') { $depth--; if ($classDepth && end($classDepth) === $depth) array_pop($classDepth); }
            // first-class callable syntax: name(...)
            if ($t === '(' && isset($toks[$i + 1]) && is_array($toks[$i + 1]) && $toks[$i + 1][0] === T_ELLIPSIS && isset($toks[$i + 2]) && $toks[$i + 2] === ')') {
                $problems[] = "$f: first-class callable name(...) (PHP 8.1)";
            }
            continue;
        }
        $name = token_name($t[0]);
        $line = $t[2];
        if (isset($byName[$name]) && $byName[$name]) $problems[] = "$f:$line: " . $byName[$name];
        if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) $depth++;
        if ($t[0] === T_CLASS) {
            // remember the depth at which this class body opens
            for ($j = $i + 1; $j < $n; $j++) if ($toks[$j] === '{') { $classDepth[] = $depth; break; }
        }
        // typed property: (public|private|protected|var|static) TYPE $name — at class-body depth
        if (in_array($t[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_VAR], true) && $classDepth && $depth === end($classDepth) + 1) {
            $j = $i + 1;
            while ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], [T_WHITESPACE, T_STATIC], true)) $j++;
            if ($j < $n && (is_array($toks[$j]) && in_array($toks[$j][0], [T_STRING, T_ARRAY, T_CALLABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || $toks[$j] === '?')) {
                $k = $j + 1;
                while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) $k++;
                if ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_VARIABLE) $problems[] = "$f:$line: typed property (PHP 7.4)";
            }
        }
        // a call to a function PHP 7.3 does not have
        if ($t[0] === T_STRING && in_array(strtolower($t[1]), $newFunctions, true)) {
            $j = $i + 1;
            while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
            $prev = $i - 1;
            while ($prev >= 0 && is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) $prev--;
            $isMethod = $prev >= 0 && is_array($toks[$prev]) && in_array($toks[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if ($j < $n && $toks[$j] === '(' && !$isMethod) $problems[] = "$f:$line: {$t[1]}() does not exist in PHP 7.3";
        }
        // numeric literal separator 1_000 (PHP 7.4)
        if (in_array($t[0], [T_LNUMBER, T_DNUMBER], true) && strpos($t[1], '_') !== false) $problems[] = "$f:$line: numeric separator (PHP 7.4)";
        // union / mixed / static return types and constructor promotion are caught by the parser of PHP 7.3 only — flag the
        // ones that show up as text
        if ($t[0] === T_STRING && in_array(strtolower($t[1]), ['mixed', 'never'], true)) {
            $prev = $i - 1;
            while ($prev >= 0 && is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) $prev--;
            if ($prev >= 0 && ($toks[$prev] === ':' || $toks[$prev] === '(' || $toks[$prev] === ',')) $problems[] = "$f:$line: type '{$t[1]}' (PHP 8)";
        }
    }
    if (preg_match('/function\s+__construct\s*\([^)]*\b(public|private|protected)\s+[$?\w]/', $src)) $problems[] = "$f: constructor property promotion (PHP 8.0)";
    if (preg_match('/\)\s*:\s*static\b/', $src)) $problems[] = "$f: return type static (PHP 8.0)";
    if (preg_match('/catch\s*\(\s*[\w\\\\|]+\s*\)/', $src)) $problems[] = "$f: catch without a variable (PHP 8.0)";
}
foreach ($problems as $p) echo " FAIL  ", str_replace($root . '/', '', $p), "\n";
echo count($files) . " files read — " . ($problems ? count($problems) . " construct(s) PHP 7.3 does not have" : 'nothing PHP 7.3 lacks') . "\n";
exit($problems ? 1 : 0);
