<?php
require_once __DIR__ . '/../activity-filter.php';
require_once __DIR__ . '/../visits.php';
foreach ([['visits', 'AND is_admin = 0'], ['all', ''], ['admin', 'AND is_admin = 1']] as [$mode, $sql]) {
    if (ioaActivityMode(['activity' => $mode]) !== $mode || ioaActivityWhere($mode) !== $sql) throw new RuntimeException('Wrong mode');
}
foreach ([[[], 'visits'], [['include_admin' => '1'], 'all'], [['activity' => 'admin', 'include_admin' => '1'], 'admin'], [['activity' => 'bad', 'include_admin' => '1'], 'visits'], [['activity' => []], 'visits']] as [$query, $mode]) {
    if (ioaActivityMode($query) !== $mode) throw new RuntimeException('Wrong URL compatibility');
}
$events = [
    ['id' => 1, 'session_id' => 'A', 'created_at' => '2026-09-01 10:00:00', 'is_admin' => 0],
    ['id' => 2, 'session_id' => 'A', 'created_at' => '2026-09-01 10:20:00', 'is_admin' => 1],
    ['id' => 3, 'session_id' => 'A', 'created_at' => '2026-09-01 10:40:00', 'is_admin' => 0],
    ['id' => 4, 'session_id' => 'A', 'created_at' => '2026-09-01 11:00:00', 'is_admin' => 1],
];
foreach (['visits' => [1, 3], 'all' => [1], 'admin' => [2, 4]] as $mode => $ids) {
    $filtered = array_values(array_filter($events, static fn($e) => $mode === 'all' || $e['is_admin'] === ($mode === 'admin' ? 1 : 0)));
    $visits = iterator_to_array(ioaSegmentVisits($filtered));
    if (array_column($visits, 'visit_id') !== $ids) throw new RuntimeException('Segmentation changed');
    foreach (array_values($visits) as $index => $visit) {
        if ($visit['is_revisit'] !== ($index > 0)) throw new RuntimeException('Revisit changed');
    }
}
// Missing images may be filled only from the selected activity dataset.
foreach (['visits' => '/images/visitor.jpg', 'all' => '/images/admin.jpg', 'admin' => '/images/admin.jpg'] as $mode => $expected) {
    $images = ioaEnrichVisitImages([42], [], static fn($url) => $url,
        static function ($ids) use ($mode) {
            $rows = [['photo_id' => 42, 'image_url' => '/images/visitor.jpg', 'is_admin' => 0],
                ['photo_id' => 42, 'image_url' => '/images/admin.jpg', 'is_admin' => 1]];
            return array_values(array_filter(array_reverse($rows), static fn($row) => $mode === 'all'
                || $row['is_admin'] === ($mode === 'admin' ? 1 : 0)));
        });
    if (($images[42] ?? null) !== $expected) throw new RuntimeException('Image enrichment crossed activity modes');
}
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
if (strpos($dashboard, "'include_admin' =>") !== false
    || substr_count($dashboard, "'activity' => $" . 'activity') < 4
    || strpos($dashboard, "searchParams.set('activity'") === false) throw new RuntimeException('Navigation lost activity');
foreach (['photo-inspector.php', 'photo-search.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../' . $file);
    if (strpos($source, '{$whereAdmin}') === false) throw new RuntimeException('Handler lost filter');
}
echo "Activity filter and segmentation regression cases passed\n";
