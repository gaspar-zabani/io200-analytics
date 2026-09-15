<?php
require_once __DIR__ . '/../visits.php';
$source = file_get_contents(__DIR__ . '/../dashboard.php');
$start = strpos($source, '            if ($sessionVisitId > 0) {');
$end = strpos($source, "            if (\$currentVisit['qualifies_for_period'])", $start);
$selection = substr($source, $start, $end - $start);
$finish = 'if ($selectedSessionFound) $selectedSessionVisits = $sessionEpisodes;';
foreach ([1, 2, 4, 23] as $size) {
    $events = [];
    for ($i = 0; $i < $size; $i++) {
        $events[] = ['session_id' => 'A', 'id' => $i + 1, 'created_at' => sprintf('2026-09-01 %02d:00:00', $i)];
    }
    $events[] = ['session_id' => 'B', 'id' => 100, 'created_at' => '2026-09-01 23:00:00'];
    $sessionVisitId = $size;
    $sessionEpisodes = $selectedSessionVisits = [];
    $selectedSessionFound = false;
    foreach (ioaSegmentVisits($events) as $currentVisit) {
        // Earlier episodes need not qualify for the period; they must still be available.
        $currentVisit['qualifies_for_period'] = $currentVisit['visit_id'] === $size;
        eval($selection);
    }
    eval($finish);
    if (count($selectedSessionVisits) !== $size) throw new RuntimeException('Incomplete session');
    foreach ($selectedSessionVisits as $i => $visit) {
        if ($visit['session_id'] !== 'A' || $visit['is_revisit'] !== ($i > 0)) throw new RuntimeException('Wrong session/status');
    }
}
echo "4 complete-session selection cases passed (including outside period/Latest 20)\n";
