<?php

/**
 * Scans all Filament PHP files for __('literal string') calls
 * and adds any missing entries to lang/en.json as identity mappings.
 */
$base = dirname(__DIR__);
$dirs = [$base.'/app/Filament', $base.'/app/Enums'];
$bladeDirs = [$base.'/resources/views/filament'];
$newKeys = [];

$scanFile = function (string $path) use (&$newKeys) {
    $content = file_get_contents($path);
    // Match __( 'literal' ) - single-quoted only, plain string literals
    preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*[,)]/", $content, $m);
    foreach ($m[1] as $s) {
        $s = stripslashes($s);
        if (strlen($s) > 1 && ! is_numeric($s) && ! str_starts_with($s, 'filament-')) {
            $newKeys[$s] = $s;
        }
    }
};

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $scanFile($file->getPathname());
    }
}

// Also scan Blade files for {{ __('...') }} calls
foreach ($bladeDirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $scanFile($file->getPathname());
    }
}

$enJsonPath = $base.'/lang/en.json';
$existing = json_decode(file_get_contents($enJsonPath), true) ?? [];
$before = count($existing);

// New keys get identity values; existing keys are preserved unchanged
$merged = array_merge($newKeys, $existing);
ksort($merged);

file_put_contents($enJsonPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$after = count($merged);
echo 'New keys added: '.($after - $before).PHP_EOL;
echo 'Total keys in en.json: '.$after.PHP_EOL;
