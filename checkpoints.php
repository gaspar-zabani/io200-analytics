<?php

// Storage and validation only. The dashboard authenticates before calling mutations.
function ioaCheckpointName($value): string
{
    if (!is_string($value) || !preg_match('//u', $value)) throw new InvalidArgumentException('Enter a valid checkpoint name.');
    $name = trim($value);
    if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name) || preg_match_all('/./us', $name) > 200) {
        throw new InvalidArgumentException('Use a name containing 1–200 characters.');
    }
    return $name;
}

function ioaCheckpointId($value): int
{
    $id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($id === false) throw new InvalidArgumentException('Choose a saved checkpoint.');
    return $id;
}

function ioaCheckpointTables(): array
{
    return [
        'ioa_checkpoints' => "CREATE TABLE `ioa_checkpoints` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_checkpoint_created (created_at, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'ioa_statistics_start' => "CREATE TABLE `ioa_statistics_start` (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, checkpoint_id BIGINT UNSIGNED DEFAULT NULL, FOREIGN KEY (checkpoint_id) REFERENCES ioa_checkpoints(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function ioaInspectCheckpointSchema(mysqli $db): array
{
    $operations = [];
    $issues = [];
    foreach (ioaCheckpointTables() as $table => $create) {
        $row = $db->query("SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'")->fetch_assoc();
        if (!$row) { $operations[] = $create; continue; }
        if ($row['TABLE_TYPE'] !== 'BASE TABLE') { $issues[] = "$table must be a base table."; continue; }
        if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') $issues[] = "$table must use InnoDB.";
        $columns = [];
        foreach ($db->query("SHOW FULL COLUMNS FROM `$table`") as $column) $columns[$column['Field']] = $column;
        $required = $table === 'ioa_checkpoints' ? ['id', 'name', 'created_at'] : ['id', 'checkpoint_id'];
        foreach ($required as $name) if (!isset($columns[$name])) $issues[] = "$table is missing $name; no automatic conversion attempted.";
        if (array_diff($required, array_keys($columns))) continue;
        foreach ($columns as $name => $column) {
            if (!in_array($name, $required, true) && $column['Null'] === 'NO' && $column['Default'] === null && stripos($column['Extra'], 'auto_increment') === false) $issues[] = "$table has an extra required column: $name.";
        }
        $primary = [];
        foreach ($db->query("SHOW INDEX FROM `$table`") as $index) {
            if ($index['Key_name'] === 'PRIMARY') $primary[] = $index['Column_name'];
            elseif ((int)($index['Non_unique'] ?? 1) === 0) $issues[] = "$table has an unexpected unique index; no automatic conversion attempted.";
        }
        if ($primary !== ['id']) $issues[] = "$table has an incompatible primary key.";
        $id = $columns['id'];
        if ($id['Key'] !== 'PRI' || $id['Null'] !== 'NO'
            || !preg_match($table === 'ioa_checkpoints' ? '/^bigint(?:\(\d+\))? unsigned$/i' : '/^tinyint(?:\(\d+\))? unsigned$/i', $id['Type'])) $issues[] = "$table has an incompatible ID.";
        if ($table === 'ioa_checkpoints') {
            if (stripos($id['Extra'], 'auto_increment') === false
                || $columns['name']['Type'] !== 'varchar(200)' || $columns['name']['Null'] !== 'NO' || $columns['name']['Extra'] !== ''
                || strtolower($columns['created_at']['Type']) !== 'datetime' || $columns['created_at']['Null'] !== 'NO'
                || !preg_match('/^current_timestamp(?:\(\))?$/i', (string)$columns['created_at']['Default'])
                || !in_array(strtoupper($columns['created_at']['Extra']), ['', 'DEFAULT_GENERATED'], true)) $issues[] = 'ioa_checkpoints has incompatible column definitions.';
        } else {
            if (!preg_match('/^bigint(?:\(\d+\))? unsigned$/i', $columns['checkpoint_id']['Type']) || $columns['checkpoint_id']['Null'] !== 'YES' || $columns['checkpoint_id']['Extra'] !== '' || $id['Extra'] !== '') $issues[] = 'Incompatible statistics-start reference.';
            $fk = $db->query("SELECT r.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.TABLE_NAME = 'ioa_statistics_start' AND r.REFERENCED_TABLE_NAME = 'ioa_checkpoints' AND k.COLUMN_NAME = 'checkpoint_id' AND k.REFERENCED_COLUMN_NAME = 'id'")->fetch_assoc();
            if (!$fk || $fk['DELETE_RULE'] !== 'SET NULL') $issues[] = 'Statistics start requires ON DELETE SET NULL.';
        }
    }
    return ['operations' => $operations, 'issues' => $issues];
}

function ioaCheckpointState(mysqli $db): array
{
    $rows = $db->query('SELECT id, name, created_at FROM ioa_checkpoints ORDER BY created_at DESC, id DESC')->fetch_all(MYSQLI_ASSOC);
    $start = $db->query('SELECT c.id, c.name, c.created_at FROM ioa_statistics_start s INNER JOIN ioa_checkpoints c ON c.id = s.checkpoint_id WHERE s.id = 1')->fetch_assoc();
    return [$rows, $start ?: null];
}

function ioaCheckpointMutation(mysqli $db, array $post): void
{
    $action = $post['checkpoint_action'] ?? null;
    if (!in_array($action, ['create', 'rename', 'delete', 'statistics_start'], true)) throw new InvalidArgumentException('Invalid checkpoint action.');
    if ($action === 'create') {
        $name = ioaCheckpointName($post['name'] ?? null);
        $stmt = $db->prepare('INSERT INTO ioa_checkpoints (name, created_at) VALUES (?, CURRENT_TIMESTAMP)');
        $stmt->bind_param('s', $name);
    } elseif ($action === 'statistics_start') {
        $id = ($post['checkpoint_id'] ?? '') === '' ? null : ioaCheckpointId($post['checkpoint_id']);
        $stmt = $db->prepare('INSERT INTO ioa_statistics_start (id, checkpoint_id) VALUES (1, ?) ON DUPLICATE KEY UPDATE checkpoint_id = VALUES(checkpoint_id)');
        $stmt->bind_param('i', $id);
    } else {
        $id = ioaCheckpointId($post['checkpoint_id'] ?? null);
        if ($action === 'rename') {
            $name = ioaCheckpointName($post['name'] ?? null);
            $stmt = $db->prepare('UPDATE ioa_checkpoints SET name = ? WHERE id = ?');
            $stmt->bind_param('si', $name, $id);
        } else {
            if (($post['confirm_delete'] ?? '') !== 'yes') throw new InvalidArgumentException('Confirm checkpoint deletion.');
            $stmt = $db->prepare('DELETE FROM ioa_checkpoints WHERE id = ?');
            $stmt->bind_param('i', $id);
        }
    }
    $stmt->execute();
    $stmt->close();
}
