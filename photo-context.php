<?php

// Inspector-only routing. Never use photo membership or URL-prefix album inference.
function ioaInspectorContextResolver(array $links, array $collections, callable $photoExists): Closure
{
    $byId = [];
    $bySlug = [];
    foreach ($collections as $collection) {
        $byId[(string)$collection['id']] = $collection;
        $bySlug[(string)$collection['slug']][] = $collection;
    }
    $bases = [];
    foreach ($links as $link) {
        $raw = $link['path'];
        if (!is_string($raw) || !str_starts_with($raw, '/') || str_starts_with($raw, '//')
            || strpbrk($raw, '?#') !== false) continue;
        $path = normalizedDashboardPath($raw);
        // Front-page routing and translated/custom aliases need their own verified mapping.
        if ($path === null || $path === '/') continue;
        $bases[$path][] = $link;
    }
    $known = static function ($kind, $title, $id = null) {
        return ['context_type' => $kind, 'album_id' => $id, 'title' => $title];
    };
    $album = static function ($collection) use ($known) {
        return $known('album', trim((string)$collection['title']) ?: 'Album ' . $collection['id'], (string)$collection['id']);
    };
    return static function ($raw) use ($bases, $byId, $bySlug, $photoExists, $known, $album) {
        $unknown = $known('unknown', 'Unknown context');
        if (!is_string($raw) || !str_starts_with($raw, '/') || str_starts_with($raw, '//')
            || preg_match('/[\x00-\x20\\\\]/', $raw) || strpbrk($raw, '?#') !== false) return $unknown;
        $path = normalizedDashboardPath($raw);
        if ($path === null || $path === '/') return $unknown;
        $matches = [];
        foreach ($bases as $base => $routes) {
            if ($path !== $base && !str_starts_with($path, $base . '/')) continue;
            // Any overlapping configured route blocks a speculative parent interpretation.
            if (count($routes) !== 1) return $unknown;
            $link = $routes[0];
            $suffix = substr($path, strlen($base));
            $template = $link['template'];
            $collection = $byId[(string)$link['reference_id']] ?? null;
            $result = null;
            if ($template === 'album' && $link['reference_type'] === 'album' && ($collection['type'] ?? null) === 'album') {
                if ($suffix === '' || preg_match('~^/[1-9][0-9]{0,3}$~', $suffix)) $result = $album($collection);
                elseif (preg_match('~^/([a-z0-9_-]+)$~', $suffix, $m) && $photoExists($m[1])) $result = $known('photo', 'Photo page');
            } elseif ($template === 'albums' || ($template === 'set' && $link['reference_type'] === 'set' && ($collection['type'] ?? null) === 'set')) {
                if ($suffix === '') $result = $known($template, $template === 'set' ? 'Set' : 'Albums');
                elseif (preg_match('~^/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$~', $suffix, $m)) {
                    $candidates = $bySlug[$m[1]] ?? [];
                    if (count($candidates) === 1) {
                        $child = $candidates[0];
                        $valid = (bool)$child['published'] && ($template === 'albums'
                            ? $child['type'] === 'album' && (bool)$child['listed']
                            : (int)$collection['left_id'] < (int)$child['left_id'] && (int)$collection['right_id'] > (int)$child['right_id']);
                        if ($valid) {
                            $tail = $m[2] ?? '';
                            if ($tail === '' || preg_match('~^[1-9][0-9]{0,3}$~', $tail)) {
                                if ($child['type'] === 'album') $result = $album($child);
                                elseif ($child['type'] === 'set') $result = $known('set', 'Set');
                            } elseif ($child['type'] === 'album' && $photoExists($tail)) $result = $known('photo', 'Photo page');
                        }
                    }
                }
            } elseif ($suffix === '') {
                $labels = ['page' => 'Page', 'basket' => 'Basket', 'photos' => 'Photos', 'blog' => 'Blog / article',
                    'tags' => 'Photo tags', 'timeline' => 'Timeline', 'contact' => 'Contact', 'clientlogin' => 'Client login'];
                if (isset($labels[$template])) $result = $known($template, $labels[$template]);
            } elseif (in_array($template, ['photos', 'blog'], true) && preg_match('~^/[1-9][0-9]{0,3}$~', $suffix)) {
                $result = $known($template, $template === 'blog' ? 'Blog / article' : 'Photos');
            }
            // Unknown child routes cannot inherit a recognized parent context.
            $matches[] = $result ?? $unknown;
        }
        if (!$matches) return $unknown;
        foreach ($matches as $match) if ($match !== $matches[0]) return $unknown;
        return $matches[0];
    };
}
