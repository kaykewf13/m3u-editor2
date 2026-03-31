<?php
/**
 * Replace dot-notation navigation keys with natural language strings.
 * __('navigation.groups.epg') -> __('EPG') etc.
 */

$replacements = [
    'navigation.groups.playlist'      => 'Playlist',
    'navigation.groups.integrations'  => 'Integrations',
    'navigation.groups.live_channels' => 'Live Channels',
    'navigation.groups.vod_channels'  => 'VOD Channels',
    'navigation.groups.series'        => 'Series',
    'navigation.groups.epg'           => 'EPG',
    'navigation.groups.proxy'         => 'Proxy',
    'navigation.groups.tools'         => 'Tools',
    'navigation.labels.api_docs'      => 'API Docs',
    'navigation.labels.queue_manager' => 'Queue Manager',
];

$dirs = [
    __DIR__ . '/../app/Filament',
    __DIR__ . '/../app/Providers',
];

$changed = [];

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path     = $file->getPathname();
        $content  = file_get_contents($path);
        $original = $content;

        foreach ($replacements as $key => $natural) {
            $content = str_replace("__('{$key}')", "__('{$natural}')", $content);
            $content = str_replace("__(\"{$key}\")", "__('{$natural}')", $content);
        }

        if ($content !== $original) {
            file_put_contents($path, $content);
            $changed[] = str_replace(__DIR__ . '/../', '', $path);
        }
    }
}

echo 'Changed ' . count($changed) . " files:\n";
foreach ($changed as $f) {
    echo "  $f\n";
}
