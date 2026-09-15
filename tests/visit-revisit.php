<?php
require_once __DIR__ . '/../visits.php';
function revisitEvent(int $id, string $time, string $session = 'ABC', int $admin = 0): array
{
    return ['id' => $id, 'created_at' => $time, 'session_id' => $session, 'is_admin' => $admin];
}
function revisitCheck(array $events, array $expected): array
{
    $visits = iterator_to_array(ioaSegmentVisits($events));
    $actual = array_map(static fn($v) => [$v['visit_id'], $v['is_revisit'], count($v['events'])], $visits);
    if ($actual !== $expected) throw new RuntimeException(json_encode($actual));
    return $visits;
}
$a = revisitEvent(100, '2026-09-01 10:00:00');
$b = revisitEvent(150, '2026-09-01 11:00:00');
$c = revisitEvent(200, '2026-09-01 12:00:00');
revisitCheck([$a], [[100, false, 1]]);
revisitCheck([$a, $b], [[100, false, 1], [150, true, 1]]);
revisitCheck([$a, $b, $c], [[100, false, 1], [150, true, 1], [200, true, 1]]);
revisitCheck([$a, revisitEvent(101, '2026-09-01 10:30:00')], [[100, false, 2]]);
revisitCheck([$a, revisitEvent(101, '2026-09-01 10:30:01')], [[100, false, 1], [101, true, 1]]);
revisitCheck([$a, revisitEvent(201, '2026-09-01 12:00:00', 'DEF')], [[100, false, 1], [201, false, 1]]);
// Match dashboard order: complete history segmented before period qualification/truncation.
$visits = revisitCheck([$a, revisitEvent(300, '2026-09-04 10:00:00')], [[100, false, 1], [300, true, 1]]);
$visible = array_values(array_filter($visits, static fn($v) => $v['first_activity'] >= '2026-09-03'));
if (count($visible) !== 1 || !$visible[0]['is_revisit']) throw new RuntimeException('Period lost predecessor');
// The database removes admin events before calling the segmenter.
$events = [revisitEvent(100, '2026-09-01 10:00:00', 'ABC', 1), $b];
revisitCheck(array_values(array_filter($events, static fn($e) => $e['is_admin'] === 0)), [[150, false, 1]]);
revisitCheck($events, [[100, false, 1], [150, true, 1]]);
echo "9 Revisit cases passed\n";
