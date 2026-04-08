<?php

/**
 * Unit tests for the collision-relative source_id hashing logic
 * implemented in ProcessM3uImportChunk.
 *
 * These tests verify the algorithm in isolation without requiring
 * a database or actual Job records.
 */
it('assigns the base md5 hash to the first occurrence of a source_key', function () {
    $sourceKey = 'CCTV-1CCTV1CCTV';

    $seen = [];
    $item = applyCollisionHash(['source_key' => $sourceKey, 'playlist_id' => 1], $seen);

    expect($item['source_id'])->toBe(md5($sourceKey));
});

it('assigns :dup:N suffixes to subsequent duplicate source_keys in the same payload', function () {
    $sourceKey = 'CCTV-1CCTV1CCTV';
    $channels = [
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'https://example.com?streamid=aaa'],
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'https://example.com?streamid=bbb'],
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'https://example.com?streamid=ccc'],
    ];

    $seen = [];
    $result = array_map(function ($item) use (&$seen) {
        return applyCollisionHash($item, $seen);
    }, $channels);

    expect($result[0]['source_id'])->toBe(md5($sourceKey))
        ->and($result[1]['source_id'])->toBe(md5($sourceKey.':dup:1'))
        ->and($result[2]['source_id'])->toBe(md5($sourceKey.':dup:2'));

    // All three source_ids must be distinct
    $ids = array_column($result, 'source_id');
    expect(count(array_unique($ids)))->toBe(3);
});

it('is backwards-compatible — existing channels keep their source_id on re-import', function () {
    // The pre-existing source_id for a channel is md5(title+name+group)
    $sourceKey = 'BBC OneBBC OneNews';
    $existingHash = md5($sourceKey);

    $seen = [];
    $item = applyCollisionHash(['source_key' => $sourceKey, 'playlist_id' => 2], $seen);

    expect($item['source_id'])->toBe($existingHash);
});

it('tracks duplicates per playlist_id independently', function () {
    $sourceKey = 'CCTV-1CCTV1CCTV';
    $channels = [
        ['source_key' => $sourceKey, 'playlist_id' => 1],
        ['source_key' => $sourceKey, 'playlist_id' => 2], // different playlist — separate counter
        ['source_key' => $sourceKey, 'playlist_id' => 1], // same playlist as first — dup:1
    ];

    $seen = [];
    $result = array_map(function ($item) use (&$seen) {
        return applyCollisionHash($item, $seen);
    }, $channels);

    expect($result[0]['source_id'])->toBe(md5($sourceKey))            // playlist 1, count 0
        ->and($result[1]['source_id'])->toBe(md5($sourceKey))          // playlist 2, count 0 (own counter)
        ->and($result[2]['source_id'])->toBe(md5($sourceKey.':dup:1')); // playlist 1, count 1
});

it('removes source_key from the channel array after hashing', function () {
    $seen = [];
    $item = applyCollisionHash(['source_key' => 'some key', 'playlist_id' => 1], $seen);

    expect($item)->not->toHaveKey('source_key');
});

it('passes through Xtream channels that have no source_key unchanged', function () {
    $xtreamChannel = ['source_id' => 'stream-12345', 'playlist_id' => 1, 'url' => 'https://provider.com/12345'];

    $seen = [];
    $result = applyCollisionHash($xtreamChannel, $seen);

    expect($result['source_id'])->toBe('stream-12345')
        ->and($result)->not->toHaveKey('source_key');
});

// ---------------------------------------------------------------------------
// Helper — mirrors the map() closure in ProcessM3uImportChunk::handle()
// ---------------------------------------------------------------------------
function applyCollisionHash(array $item, array &$seen): array
{
    if (! empty($item['source_key'])) {
        $key = $item['source_key'].($item['playlist_id'] ?? '');
        $count = $seen[$key] ?? 0;
        $item['source_id'] = $count === 0
            ? md5($item['source_key'])
            : md5($item['source_key'].':dup:'.$count);
        $seen[$key] = $count + 1;
        unset($item['source_key']);
    }

    return $item;
}
