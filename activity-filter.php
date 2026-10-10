<?php

function ioaActivityMode(array $query): string
{
    if (array_key_exists('activity', $query)) {
        return in_array($query['activity'], ['visits', 'all', 'admin'], true)
            ? $query['activity'] : 'visits';
    }
    return ($query['include_admin'] ?? '') === '1' ? 'all' : 'visits';
}

function ioaActivityWhere(string $mode): string
{
    return $mode === 'all' ? '' : ($mode === 'admin' ? 'AND is_admin = 1' : 'AND is_admin = 0');
}
