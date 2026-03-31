<?php

$en = json_decode(file_get_contents(__DIR__.'/../lang/en.json'), true);

// Find keys that still have literal backslash+quote (these are broken)
$bad = array_filter(array_keys($en), fn ($k) => str_contains($k, '\\"'));
echo 'Bad (backslash-quote) keys: '.count($bad).PHP_EOL;
foreach ($bad as $k) {
    echo '  '.substr($k, 0, 100).PHP_EOL;
}

// Check the three specific corrected keys exist (plain double-quotes)
$expected = [
    'The "From" email address for outgoing emails. Defaults to no-reply@m3u-editor.dev.',
    'When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.',
    'When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).',
];

echo PHP_EOL.'Correct plain-quote keys:'.PHP_EOL;
foreach ($expected as $k) {
    echo '  ['.(isset($en[$k]) ? 'EXISTS' : 'MISSING').'] '.substr($k, 0, 80).'...'.PHP_EOL;
}

// Also check DE/FR/ES have these keys translated
echo PHP_EOL.'Translations for key 1:'.PHP_EOL;
foreach (['de', 'fr', 'es'] as $locale) {
    $data = json_decode(file_get_contents(__DIR__."/../lang/{$locale}.json"), true);
    $val = $data[$expected[0]] ?? 'MISSING';
    echo "  {$locale}: ".substr($val, 0, 80).PHP_EOL;
}
