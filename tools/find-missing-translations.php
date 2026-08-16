<?php
// tools/find-missing-translations.php
// Scans modules/ and templates/ (including userpanel/*) for trans() / {trans("...")}
// and compares found keys with PL translation files (strings.php and lib/locale/pl*).
// Outputs lines in the form: <file>: <missing key>

set_time_limit(0);
$scanPaths = [
    'modules',
    'userpanel/modules',
    'templates',
    'userpanel/templates',
];
$exts = ['php', 'html', 'tpl'];

// collect files to scan
$filesToScan = [];
foreach ($scanPaths as $p) {
    if (!is_dir($p)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = pathinfo($f->getFilename(), PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), $exts)) continue;
        $filesToScan[] = $f->getPathname();
    }
}

// extract keys used in trans() / {trans(...)}
$used = []; // file => [keys]
foreach ($filesToScan as $file) {
    $content = @file_get_contents($file);
    if ($content === false) continue;

    $keys = [];

    // PHP: trans('...') or trans("...")
    if (preg_match_all('/trans\(\s*\'((?:\\\\'|[^'])*)\'/', $content, $m1)) {
        foreach ($m1[1] as $k) $keys[] = stripcslashes($k);
    }
    if (preg_match_all('/trans\(\s*"((?:\\\\"|[^"])*)"/', $content, $m2)) {
        foreach ($m2[1] as $k) $keys[] = stripcslashes($k);
    }

    // Smarty: {trans("...")} or {trans('...')}
    if (preg_match_all('/\{trans\(\s*"([^\"]+)"\s*\)\}/', $content, $m3)) {
        foreach ($m3[1] as $k) $keys[] = $k;
    }
    if (preg_match_all("/\{trans\(\\s*'([^']+)'\\s*\)\}/", $content, $m4)) {
        foreach ($m4[1] as $k) $keys[] = $k;
    }

    // dedupe
    $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));
    if (!empty($keys)) $used[$file] = $keys;
}

// collect PL translation keys from repository
$plKeys = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $path = $f->getPathname();

    // consider: files named strings.php and files under lib/locale/pl* or paths containing '/pl' and php files
    $basename = basename($path);
    $lower = strtolower($path);
    if ($basename === 'strings.php' || strpos($lower, '/lib/locale/pl') !== false || strpos($lower, '/locale/pl') !== false || strpos($lower, '/pl_') !== false) {
        $content = @file_get_contents($path);
        if ($content === false) continue;
        // match $_LANG['...'] and $_LANG["..."]
        if (preg_match_all('/\$_LANG\[\'((?:\\\\'|[^'])+)\'\]/', $content, $m)) {
            foreach ($m[1] as $k) $plKeys[stripcslashes($k)] = true;
        }
        if (preg_match_all('/\$_LANG\[\"((?:\\\\\"|[^\"])*)\"\]/', $content, $m2)) {
            foreach ($m2[1] as $k) $plKeys[stripcslashes($k)] = true;
        }
    }
}

// compare and collect missing: file => [keys]
$missing = [];
foreach ($used as $file => $keys) {
    foreach ($keys as $k) {
        if ($k === '') continue;
        if (!isset($plKeys[$k])) {
            $missing[] = $file . ': ' . $k;
        }
    }
}

sort($missing, SORT_STRING);
foreach ($missing as $line) echo $line . PHP_EOL;

// summary to stderr
$filesScanned = count($filesToScan);
$uniqueUsedKeys = array_reduce($used, function ($c, $a) { return $c + count($a); }, 0);
fwrite(STDERR, "\nDone. Files scanned: $filesScanned. Used keys found (counted): $uniqueUsedKeys. Missing entries: " . count($missing) . "\n");

return 0;
