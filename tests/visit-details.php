<?php

// Run from the repository root: php tests/visit-details.php
require_once __DIR__ . '/../visits.php';

function event(string $type, $photoId = 578, array $photoIds = []): array
{
    return ['event_type' => $type, 'photo_id' => $photoId, 'photo_ids' => $photoIds];
}

$view = event('photo_view');
$cases = [
    'one photo, one view' => [[$view], 1, false],
    'one photo, repeated views' => [array_fill(0, 20, $view), 1, false],
    'one photo, repeated views in multiple contexts' => [[$view, $view], 2, false],
    'one photo, download only' => [[event('photo_download')], 1, false],
    'one photo, multiple downloads' => [[event('photo_download'), event('photo_download')], 1, false],
    'one photo, basket add only' => [[event('basket_add')], 1, false],
    'one photo, basket remove only' => [[event('basket_remove')], 1, false],
    'one photo, basket add and remove' => [[event('basket_add'), event('basket_remove')], 1, false],
    'one photo, mixed activity' => [[
        $view,
        event('photo_download'),
        event('basket_add'),
        event('basket_remove')
    ], 1, false],
    'one photo, view and selection download' => [[$view, event('batch_download', null, [578])], 1, false],
    'one photo, single-photo selection only' => [[event('batch_download', null, [578])], 1, false],
    'two distinct viewed photos' => [[$view, event('photo_view', 579)], 1, true],
    'two distinct downloaded photos' => [[event('photo_download'), event('photo_download', 579)], 1, true],
    'basket activity involving two photos' => [[event('basket_add'), event('basket_remove', 579)], 1, true],
    'mixed activity involving two photos' => [[$view, event('photo_download'), event('basket_add', 579)], 1, true],
    'multi-photo selection download' => [[event('batch_download', null, [578, 579])], 1, true],
    'selection without usable photo IDs' => [[event('batch_download', null)], 0, true],
    'download without usable photo ID' => [[event('photo_download', null)], 0, true],
    'same numeric photo ID in different representations' => [[$view, event('photo_view', '578')], 1, false],
    'missing photo ID is not a second photo' => [[$view, event('photo_view', null)], 1, false],
    'repeated views without a recorded context' => [[$view, $view], 0, false],
    'no raw-event fallback for unrecognized events' => [[event('unknown'), event('unknown', 579)], 1, false],
    'empty episode' => [[], 0, false],
];

foreach ($cases as $name => [$events, $contextCount, $expected]) {
    $visit = ['events' => $events, 'context_count' => $contextCount,
        'unique_photos_viewed' => 1, 'downloads' => 0, 'basket_actions' => 0];
    $before = $visit;
    if (ioaVisitHasDetails($visit) !== $expected) {
        throw new RuntimeException('Unexpected disclosure result: ' . $name);
    }
    if ($visit !== $before) {
        throw new RuntimeException('Disclosure changed Visit data: ' . $name);
    }
}

echo count($cases) . " Visit disclosure cases passed\n";
