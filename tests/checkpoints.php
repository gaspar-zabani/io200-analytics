<?php
require_once __DIR__ . '/../checkpoints.php';
require_once __DIR__ . '/../reporting-range.php';
require_once __DIR__ . '/../visits.php';
function checkpointAssert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$clock = ['now' => '2026-10-10 14:00:00', 'today' => '2026-10-10 00:00:00',
    '7' => '2026-10-03 14:00:00', '30' => '2026-09-10 14:00:00', '90' => '2026-07-12 14:00:00'];
$rows = [['id' => '3', 'name' => 'Duplicate', 'created_at' => '2026-10-10 12:00:00'],
    ['id' => '2', 'name' => 'Duplicate', 'created_at' => '2026-10-10 12:00:00'],
    ['id' => '1', 'name' => 'Earlier', 'created_at' => '2026-10-01 12:00:00']];
checkpointAssert(ioaCheckpointName(' Gallery delivered ') === 'Gallery delivered', 'Name trimming');
foreach (['', ' ', str_repeat('a', 201), "bad\nname", [], "\xff"] as $invalid) {
    try { ioaCheckpointName($invalid); throw new RuntimeException('Invalid name accepted'); }
    catch (InvalidArgumentException $e) {}
}
foreach (['0', '-1', '1 OR 1', [], null] as $invalid) {
    try { ioaCheckpointId($invalid); throw new RuntimeException('Invalid ID accepted'); }
    catch (InvalidArgumentException $e) {}
}
foreach (['today', '7', '30', '90', 'all'] as $period) {
    $range = ioaReportingRange($period, 'latest', $rows, null, $clock);
    checkpointAssert($range['start'] === ($clock[$period] ?? null) && $range['end'] === $clock['now'] && !$range['empty'], 'Ordinary period');
}
checkpointAssert(ioaCheckpointSelection('missing', $rows) === 'latest', 'Deleted fallback');
checkpointAssert(ioaCheckpointSelection('2', $rows) === '2', 'Pinned selection');
$range = ioaReportingRange('checkpoint', 'latest', $rows, null, $clock);
checkpointAssert($range['checkpoint']['id'] === '3', 'Latest same-second ID order');
checkpointAssert(ioaReportingRange('checkpoint', 'latest', [], null, $clock)['empty'], 'Empty must not be All time');
foreach (['all', '30', 'checkpoint'] as $period) {
    $range = ioaReportingRange($period, '1', $rows, $rows[0], $clock);
    checkpointAssert($range['start'] === $rows[0]['created_at'] && $range['constrained'], 'Statistics start wins');
}
$range = ioaReportingRange('checkpoint', '3', $rows, $rows[2], $clock);
checkpointAssert($range['start'] === $rows[0]['created_at'] && !$range['constrained'], 'Checkpoint wins');
$range = ioaReportingRange('checkpoint', '1', $rows, null, $clock);
$events = [
    ['id' => 1, 'session_id' => 'A', 'created_at' => '2026-10-01 10:00:00'],
    ['id' => 2, 'session_id' => 'A', 'created_at' => '2026-10-01 11:50:00'],
    ['id' => 3, 'session_id' => 'A', 'created_at' => '2026-10-01 12:00:00'],
    ['id' => 4, 'session_id' => 'A', 'created_at' => '2026-10-01 12:20:00'],
];
$visits = array_values(iterator_to_array(ioaSegmentVisits($events)));
$qualified = array_values(array_filter($visits, static fn($v) => count(array_filter($v['events'], static fn($e) => $e['created_at'] >= $range['start'] && $e['created_at'] <= $range['end'])) > 0));
checkpointAssert(count($visits) === 2 && count($qualified) === 1 && $qualified[0]['visit_id'] === 2
    && count($qualified[0]['events']) === 3 && $qualified[0]['is_revisit'], 'Full spanning Visit and historical sibling');
checkpointAssert(count(array_filter($events, static fn($e) => $e['created_at'] >= $range['start'])) === 2, 'Inclusive aggregates');
require_once __DIR__ . '/../activity-filter.php';
foreach (['visits' => 1, 'all' => 2, 'admin' => 1] as $mode => $expected) {
    $activityEvents = array_map(static fn($e) => $e + ['is_admin' => $e['id'] % 2], $events);
    $count = count(array_filter($activityEvents, static fn($e) => $e['created_at'] >= $range['start']
        && $e['created_at'] <= $range['end'] && ($mode === 'all' || $e['is_admin'] === ($mode === 'admin' ? 1 : 0))));
    checkpointAssert($count === $expected, 'Independent activity and checkpoint range');
}
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
checkpointAssert(str_contains($dashboard, "hash_equals($" . 'checkpointCsrf, $csrf)') && str_contains($dashboard, "REQUEST_METHOD'] === 'POST'"), 'CSRF and POST guard');
checkpointAssert(str_contains($dashboard, 'before_reporting_start') && str_contains($dashboard, 'Context only'), 'Context indication');
$latest = substr($dashboard, strpos($dashboard, '// Latest viewed photo'), strpos($dashboard, '// Most viewed photos') ?: 1800);
checkpointAssert(!str_contains(substr($latest, 0, strpos($latest, 'LIMIT 1')), '{$whereDate}'), 'Latest remains independent');
$search = file_get_contents(__DIR__ . '/../photo-search.php');
checkpointAssert(!str_contains($search, '$whereDate'), 'Search retains historical eligibility');
$inspector = file_get_contents(__DIR__ . '/../photo-inspector.php');
checkpointAssert(str_contains($inspector, '(1 {$whereDate}) AS in_period') && str_contains($inspector, 'full_visit'), 'Inspector scopes');
checkpointAssert(str_contains($inspector, "context['outside_reporting_range']") && str_contains($inspector, "context['status'] = 'available'"), 'Historical Session Inspector remains available');
$storage = file_get_contents(__DIR__ . '/../checkpoints.php');
checkpointAssert(str_contains($storage, 'ON DELETE SET NULL') && str_contains($storage, 'SET name = ? WHERE id = ?')
    && !str_contains($storage, 'UPDATE ioa_events') && !str_contains($storage, 'DELETE FROM ioa_events'), 'Reversible preference and immutable timestamps');
echo "Checkpoint range, validation, context and security-source cases passed\n";
