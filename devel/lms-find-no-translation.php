#!/usr/bin/env php
<?php

/**
 * Check untranslated trans() calls in LMS.
 *
 * The script searches the repository for:
 *
 *   PHP:
 *     trans('Some text')
 *     trans("Some text")
 *
 *   Smarty:
 *     {trans("Some text")}
 *     {trans('Some text')}
 *
 * For every literal string it checks lib/locale/pl_PL/strings.php.
 *
 * A string is reported only when trans() would return the original
 * parameter, i.e. when there is no translation for the given key.
 */

declare(strict_types=1);

const LOCALE_FILE = 'lib/locale/pl_PL/strings.php';

$repositoryRoot = realpath(__DIR__ . '/..');

if ($repositoryRoot === false) {
    fwrite(STDERR, "Unable to determine repository root.\n");
    exit(2);
}

$localeFile = $repositoryRoot . '/' . LOCALE_FILE;

if (!is_file($localeFile)) {
    fwrite(STDERR, "Translation file not found: {$localeFile}\n");
    exit(2);
}

/**
 * Load LMS translation file.
 *
 * LMS traditionally stores translations in $_LANG['source'] = 'translation'.
 * The loader also supports strings.php files returning an array.
 */
function loadTranslations(string $filename): array
{
    $_LANG = [];

    $result = include $filename;

    if (is_array($result)) {
        return $result;
    }

    if (is_array($_LANG)) {
        return $_LANG;
    }

    return [];
}

$translations = loadTranslations($localeFile);

/**
 * Return the result that trans() would produce for a given key.
 *
 * LMS translation mechanism falls back to the original string when
 * there is no translation.
 */
function translate(string $key, array $translations): string
{
    if (array_key_exists($key, $translations)) {
        return (string) $translations[$key];
    }

    return $key;
}

/**
 * Decode a PHP string token.
 */
function decodePhpString(string $token): ?string
{
    if (strlen($token) < 2) {
        return null;
    }

    $quote = $token[0];

    if (($quote !== "'" && $quote !== '"') || $token[strlen($token) - 1] !== $quote) {
        return null;
    }

    $value = substr($token, 1, -1);

    if ($quote === "'") {
        return str_replace(
            ["\\\\", "\\'"],
            ["\\", "'"],
            $value
        );
    }

    return stripcslashes($value);
}

/**
 * Find first meaningful token after $index.
 */
function nextMeaningfulToken(array $tokens, int $index): ?array
{
    $count = count($tokens);

    for ($i = $index + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if ($token[0] === T_WHITESPACE ||
                $token[0] === T_COMMENT ||
                $token[0] === T_DOC_COMMENT) {
                continue;
            }
        }

        return [
            'index' => $i,
            'token' => $token,
        ];
    }

    return null;
}

/**
 * Parse literal trans() calls from PHP source.
 *
 * Returns:
 *
 * [
 *   [
 *     'string' => 'Some text',
 *     'line'   => 123,
 *   ],
 * ]
 */
function findPhpTranslations(string $source): array
{
    $tokens = token_get_all($source);
    $results = [];

    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'trans') {
            continue;
        }

        $open = nextMeaningfulToken($tokens, $i);

        if ($open === null || $open['token'] !== '(') {
            continue;
        }

        $argument = nextMeaningfulToken($tokens, $open['index']);

        if ($argument === null || !is_array($argument['token'])) {
            continue;
        }

        if ($argument['token'][0] !== T_CONSTANT_ENCAPSED_STRING) {
            /*
             * Dynamic expressions such as:
             *
             *   trans($foo)
             *   trans('foo' . $bar)
             *
             * cannot be reliably checked statically.
             */
            continue;
        }

        $string = decodePhpString($argument['token'][1]);

        if ($string === null) {
            continue;
        }

        $results[] = [
            'string' => $string,
            'line'   => $argument['token'][2],
        ];
    }

    return $results;
}

/**
 * Find Smarty trans() calls.
 *
 * Supported:
 *
 *   {trans("Some text")}
 *   {trans('Some text')}
 *
 * Also supports:
 *
 *   {trans "Some text"}
 *   {trans 'Some text'}
 */
function findSmartyTranslations(string $source): array
{
    $results = [];

    /*
     * {trans("Some text")}
     * {trans('Some text')}
     */
    $patterns = [
        '~\{trans\s*\(\s*(["\'])(.*?)\1\s*\)\s*\}~s',
        '~\{trans\s+((["\']))(.*?)\2\s*\}~s',
    ];

    foreach ($patterns as $patternIndex => $pattern) {
        if (!preg_match_all(
            $pattern,
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            continue;
        }

        foreach ($matches[0] as $matchIndex => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];

            if ($patternIndex === 0) {
                $string = $matches[2][$matchIndex][0];
            } else {
                $string = $matches[3][$matchIndex][0];
            }

            /*
             * Convert the Smarty escaped quotes.
             */
            $string = str_replace(
                ['\\"', "\\'"],
                ['"', "'"],
                $string
            );

            $line = substr_count(
                substr($source, 0, $offset),
                "\n"
            ) + 1;

            $results[] = [
                'string' => $string,
                'line'   => $line,
            ];
        }
    }

    return $results;
}

/**
 * Determine whether a file is likely text.
 */
function isTextFile(string $filename): bool
{
    $contents = @file_get_contents($filename);

    if ($contents === false || $contents === '') {
        return false;
    }

    /*
     * Detect NUL bytes.
     */
    if (strpos($contents, "\0") !== false) {
        return false;
    }

    return true;
}

/**
 * Files which should not be scanned.
 */
function shouldSkipDirectory(string $name): bool
{
    return in_array(
        $name,
        [
            '.git',
            'vendor',
            'node_modules',
            '.idea',
            '.vscode',
            'cache',
            'tmp',
        ],
        true
    );
}

$missing = [];
$filesScanned = 0;
$transFound = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $repositoryRoot,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $path = $fileInfo->getPathname();

    /*
     * Skip directories we don't want to scan.
     */
    $relativePath = substr(
        $path,
        strlen($repositoryRoot) + 1
    );

    $parts = explode(DIRECTORY_SEPARATOR, $relativePath);

    $skip = false;

    foreach ($parts as $part) {
        if (shouldSkipDirectory($part)) {
            $skip = true;
            break;
        }
    }

    if ($skip) {
        continue;
    }

    /*
     * Do not scan the translation database itself.
     */
    if ($relativePath === LOCALE_FILE) {
        continue;
    }

    /*
     * We only need source/template files.
     */
    $extension = strtolower(
        pathinfo($path, PATHINFO_EXTENSION)
    );

    $allowedExtensions = [
        'php',
        'inc',
        'html',
        'tpl',
        'smarty',
    ];

    if (!in_array($extension, $allowedExtensions, true)) {
        continue;
    }

    if (!isTextFile($path)) {
        continue;
    }

    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    $filesScanned++;

    $entries = [];

    /*
     * PHP files can contain Smarty as well, therefore run both
     * parsers on every supported source file.
     */
    if (
        $extension === 'php' ||
        $extension === 'inc'
    ) {
        foreach (findPhpTranslations($source) as $entry) {
            $entries[] = $entry;
        }
    }

    foreach (findSmartyTranslations($source) as $entry) {
        $entries[] = $entry;
    }

    foreach ($entries as $entry) {
        $transFound++;

        $key = $entry['string'];

        /*
         * This is the important condition:
         *
         * Only inspect strings.php when trans() returns exactly
         * the same string as its parameter.
         */
        $result = translate($key, $translations);

        if ($result !== $key) {
            continue;
        }

        /*
         * trans() returned its original parameter.
         *
         * If the key exists in strings.php with the same value,
         * it is still a valid translation entry, so don't report it.
         *
         * Only a completely missing key is an error.
         */
        if (array_key_exists($key, $translations)) {
            continue;
        }

        $missing[$key][] = [
            'file' => $relativePath,
            'line' => $entry['line'],
        ];
    }
}

/*
 * Sort results alphabetically by translation key.
 */
ksort($missing, SORT_STRING);

echo "LMS translation check\n";
echo "=====================\n";
echo "Translation file: " . LOCALE_FILE . "\n";
echo "Files scanned:    " . $filesScanned . "\n";
echo "trans() found:    " . $transFound . "\n";
echo "Missing strings:  " . count($missing) . "\n\n";

if (!$missing) {
    echo "OK - no missing translations found.\n";
    exit(0);
}

foreach ($missing as $key => $locations) {
    echo "MISSING: " . $key . "\n";

    foreach ($locations as $location) {
        echo "  - {$location['file']}:{$location['line']}\n";
    }

    echo "\n";
}

exit(1);
