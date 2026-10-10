<?php

function ioaCheckpointSelection($value, array $checkpoints): string
{
    if ($value === 'latest') return 'latest';
    foreach ($checkpoints as $row) if (is_string($value) && $value === (string)$row['id']) return $value;
    return 'latest';
}

// All bounds originate from the database clock, in the existing event convention.
function ioaReportingRange(string $period, string $selection, array $checkpoints, ?array $statisticsStart, array $clock): array
{
    $checkpoint = null;
    foreach ($checkpoints as $row) {
        if ($selection === 'latest' || (string)$row['id'] === $selection) { $checkpoint = $row; break; }
    }
    $start = $period === 'checkpoint' ? ($checkpoint['created_at'] ?? null) : ($clock[$period] ?? null);
    $empty = $period === 'checkpoint' && $checkpoint === null;
    $constrained = $statisticsStart !== null && ($start === null || $statisticsStart['created_at'] > $start);
    if ($constrained) $start = $statisticsStart['created_at'];
    return ['start' => $start, 'end' => $clock['now'], 'empty' => $empty, 'constrained' => $constrained,
        'checkpoint' => $checkpoint, 'statistics_start' => $statisticsStart,
        'start_reason' => $constrained ? 'statistics start' : ($period === 'checkpoint' ? 'checkpoint' : 'selected period')];
}

function ioaReportingWhere(mysqli $db, array $range): string
{
    if ($range['empty']) return 'AND 1 = 0';
    $where = "AND created_at <= '" . $db->real_escape_string($range['end']) . "'";
    if ($range['start'] !== null) $where .= " AND created_at >= '" . $db->real_escape_string($range['start']) . "'";
    return $where;
}
