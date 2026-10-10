<?php
require_once __DIR__ . '/../checkpoints.php';
// Prepared-statement spy: validates the production mutation contract without a live database.
class CheckpointStatement extends mysqli_stmt {
    public array $values = [];
    public function __construct(private CheckpointDatabase $db, private string $sql) {}
    public function bind_param(string $types, mixed &...$vars): bool {
        foreach ($vars as &$value) $this->values[] =& $value;
        return true;
    }
    public function execute(?array $params = null): bool {
        $this->db->executed[] = [$this->sql, array_values($this->values)];
        return true;
    }
    public function close(): true { return true; }
}
class CheckpointDatabase extends mysqli {
    public array $executed = [];
    public function __construct() {}
    public function prepare(string $query): mysqli_stmt|false { return new CheckpointStatement($this, $query); }
    public function real_escape_string(string $string): string { return addslashes($string); }
}
$db = new CheckpointDatabase;
ioaCheckpointMutation($db, ['checkpoint_action' => 'create', 'name' => 'Gallery', 'created_at' => '1900-01-01']);
ioaCheckpointMutation($db, ['checkpoint_action' => 'rename', 'checkpoint_id' => '1', 'name' => 'Renamed', 'created_at' => '1900-01-01']);
ioaCheckpointMutation($db, ['checkpoint_action' => 'statistics_start', 'checkpoint_id' => '1']);
ioaCheckpointMutation($db, ['checkpoint_action' => 'statistics_start', 'checkpoint_id' => '']);
ioaCheckpointMutation($db, ['checkpoint_action' => 'delete', 'checkpoint_id' => '1', 'confirm_delete' => 'yes']);
$expected = [
    ['INSERT INTO ioa_checkpoints (name, created_at) VALUES (?, CURRENT_TIMESTAMP)', ['Gallery']],
    ['UPDATE ioa_checkpoints SET name = ? WHERE id = ?', ['Renamed', 1]],
    ['INSERT INTO ioa_statistics_start (id, checkpoint_id) VALUES (1, ?) ON DUPLICATE KEY UPDATE checkpoint_id = VALUES(checkpoint_id)', [1]],
    ['INSERT INTO ioa_statistics_start (id, checkpoint_id) VALUES (1, ?) ON DUPLICATE KEY UPDATE checkpoint_id = VALUES(checkpoint_id)', [null]],
    ['DELETE FROM ioa_checkpoints WHERE id = ?', [1]],
];
if ($db->executed !== $expected) throw new RuntimeException('Incorrect prepared mutation SQL/parameters');
foreach ([['checkpoint_action' => 'unknown'], ['checkpoint_action' => 'delete', 'checkpoint_id' => '1'],
    ['checkpoint_action' => 'create', 'name' => ''], ['checkpoint_action' => 'statistics_start', 'checkpoint_id' => '-1']] as $invalid) {
    try { ioaCheckpointMutation($db, $invalid); throw new RuntimeException('Invalid mutation accepted'); }
    catch (InvalidArgumentException $e) {}
}
if (count($db->executed) !== 5) throw new RuntimeException('Invalid mutation performed a write');
require_once __DIR__ . '/../reporting-range.php';
$where = ioaReportingWhere($db, ['start' => '2026-10-10 12:00:00', 'end' => '2026-10-10 14:00:00', 'empty' => false]);
if ($where !== "AND created_at <= '2026-10-10 14:00:00' AND created_at >= '2026-10-10 12:00:00'") throw new RuntimeException('Incorrect inclusive SQL bounds');
if (ioaReportingWhere($db, ['empty' => true]) !== 'AND 1 = 0') throw new RuntimeException('Empty range fallback');
echo "Checkpoint prepared mutations and SQL range cases passed (database spy)\n";
