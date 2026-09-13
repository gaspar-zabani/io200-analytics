<?php
// Exercise the actual dashboard metric definitions without a database connection.
$source = file_get_contents(__DIR__ . '/../dashboard.php');
if (!preg_match('/\$headerMetrics = \[[\s\S]*?\];/', $source, $match)) {
    throw new RuntimeException('Visit header metric definitions not found');
}
function formatCountLabel($count, $one, $many, $singular, $plural): string
{
    return sprintf($count === 1 ? $singular : $plural, $count);
}
$cases = [
    [[578], 1],
    [[578, 578, 578, 578], 4],
    [[578, 579, 580], 3],
    [[578, 579, 580, 581, 581, 581], 6],
    [[], 0],
];
foreach ($cases as [$viewedIds, $expected]) {
    // photo_views is the existing per-event counter; deliberately supply the distinct count too.
    $visit = ['photo_views' => count($viewedIds), 'unique_photos_viewed' => count(array_unique($viewedIds)),
        'downloads' => 7, 'basket_actions' => 3];
    eval($match[0]);
    if (array_column($headerMetrics, 'value') !== [$expected, 7, 3]) {
        throw new RuntimeException('Incorrect header views or changed download/basket count');
    }
    if ($headerMetrics[0]['label'] !== $expected . ($expected === 1 ? ' view' : ' views')) {
        throw new RuntimeException('Incorrect view label');
    }
}
foreach (['Most actions', '$actionsIcon', 'ioaVisitActions', "\$visit['actions']", "\$group === 'actions'"] as $removed) {
    if (str_contains($source, $removed)) throw new RuntimeException('Actions POC remains: ' . $removed);
}
require_once __DIR__ . '/../visits.php';
if (function_exists('ioaVisitActions')) throw new RuntimeException('Unused Actions helper remains');
echo "5 Visit header cases and Actions removal checks passed\n";
