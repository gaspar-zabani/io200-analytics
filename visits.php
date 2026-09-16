<?php

const VISIT_INACTIVITY_SECONDS = 1800;

// Presentation enrichment only: ask for external image data only when the
// currently rendered Visits cannot already provide a usable thumbnail.
function ioaEnrichVisitImages(
    array $photoIds,
    array $visitImages,
    callable $safeUrl,
    callable $lookup
): array {
    $unresolved = [];
    foreach ($photoIds as $rawId) {
        $photoId = (int)$rawId;
        if ($photoId <= 0) continue;

        $existing = $safeUrl($visitImages[$photoId] ?? null);
        if ($existing !== null) {
            $visitImages[$photoId] = $existing;
            continue;
        }

        unset($visitImages[$photoId]);
        $unresolved[$photoId] = $photoId;
    }

    if (!$unresolved) return $visitImages;

    foreach ($lookup(array_values($unresolved)) as $row) {
        $photoId = (int)($row['photo_id'] ?? 0);
        if (!isset($unresolved[$photoId]) || isset($visitImages[$photoId])) continue;

        $image = $safeUrl($row['image_url'] ?? null);
        if ($image !== null) $visitImages[$photoId] = $image;
    }

    return $visitImages;
}

// Presentation only: use the completed episode and its existing resolved context count.
function ioaVisitHasDetails(array $visit): bool
{
    $photoIds = [];
    $hasDownloadOrBasket = false;
    foreach ($visit['events'] as $event) {
        $type = $event['event_type'];
        if (in_array($type, ['photo_download', 'batch_download', 'basket_add', 'basket_remove'], true)) {
            $hasDownloadOrBasket = true;
        }
        $ids = $type === 'batch_download'
            ? ($event['photo_ids'] ?? [])
            : (in_array($type, ['photo_view', 'photo_download', 'basket_add', 'basket_remove'], true)
                ? [$event['photo_id'] ?? null] : []);
        foreach ($ids as $rawId) {
            $photoId = (int)$rawId;
            if ($photoId > 0) $photoIds[$photoId] = true;
        }
    }

    return count($photoIds) > 1 || $hasDownloadOrBasket || $visit['context_count'] > 1;
}

// Input is complete admin-filtered history ordered by session_id, created_at, id.
// Period qualification and photo filtering happen only after segmentation.
function ioaSegmentVisits(iterable $rows): Generator
{
    $visit = null;
    $previousTimestamp = null;
    $episodeOrdinal = 0;
    foreach ($rows as $row) {
        $sessionId = (string)$row['session_id'];
        $timestamp = is_string($row['created_at']) ? strtotime($row['created_at']) : false;
        if ($visit === null || $sessionId !== $visit['session_id']
            || $timestamp === false || $previousTimestamp === null
            || ($timestamp - $previousTimestamp) > VISIT_INACTIVITY_SECONDS) {
            $episodeOrdinal = $visit !== null && $sessionId === $visit['session_id']
                ? $episodeOrdinal + 1 : 1;
            if ($visit !== null) yield $visit;
            $visit = ['session_id' => $sessionId, 'visit_id' => (int)$row['id'],
                'first_activity' => $row['created_at'], 'is_revisit' => $episodeOrdinal > 1, 'events' => []];
        }
        $visit['last_event_id'] = (int)$row['id'];
        $visit['latest_activity'] = $row['created_at'];
        $visit['events'][] = $row;
        $previousTimestamp = $timestamp !== false ? $timestamp : null;
    }
    if ($visit !== null) yield $visit;
}
