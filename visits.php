<?php

const VISIT_INACTIVITY_SECONDS = 1800;

// Input is complete admin-filtered history ordered by session_id, created_at, id.
// Period qualification and photo filtering happen only after segmentation.
function ioaSegmentVisits(iterable $rows): Generator
{
    $visit = null;
    $previousTimestamp = null;
    foreach ($rows as $row) {
        $sessionId = (string)$row['session_id'];
        $timestamp = is_string($row['created_at']) ? strtotime($row['created_at']) : false;
        if ($visit === null || $sessionId !== $visit['session_id']
            || $timestamp === false || $previousTimestamp === null
            || ($timestamp - $previousTimestamp) > VISIT_INACTIVITY_SECONDS) {
            if ($visit !== null) yield $visit;
            $visit = ['session_id' => $sessionId, 'visit_id' => (int)$row['id'],
                'first_activity' => $row['created_at'], 'events' => []];
        }
        $visit['last_event_id'] = (int)$row['id'];
        $visit['latest_activity'] = $row['created_at'];
        $visit['events'][] = $row;
        $previousTimestamp = $timestamp !== false ? $timestamp : null;
    }
    if ($visit !== null) yield $visit;
}
