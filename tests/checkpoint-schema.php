<?php
require_once __DIR__ . '/../checkpoints.php';
class CheckpointSchemaRows extends mysqli_result {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|false|null { return array_shift($this->rows); }
    public function fetch_all(int $mode = MYSQLI_NUM): array { return $this->rows; }
    public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
}
class CheckpointSchemaDatabase extends mysqli {
    public array $queries = [];
    public bool $exists = false;
    public bool $compatible = true;
    public function __construct() {}
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool {
        $this->queries[] = $query;
        if (str_contains($query, 'information_schema.TABLES')) return new CheckpointSchemaRows($this->exists ? [['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB']] : []);
        if (str_contains($query, 'SHOW FULL COLUMNS')) {
            $checkpoint = str_contains($query, 'ioa_checkpoints');
            $rows = [['Field' => 'id', 'Key' => 'PRI', 'Null' => 'NO', 'Type' => $checkpoint ? 'bigint(20) unsigned' : 'tinyint(3) unsigned', 'Extra' => $checkpoint ? 'auto_increment' : '', 'Default' => null]];
            if ($checkpoint) {
                $rows[] = ['Field' => 'name', 'Type' => 'varchar(200)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''];
                $rows[] = ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'NO', 'Default' => 'current_timestamp()', 'Extra' => $this->compatible ? '' : 'on update current_timestamp()'];
            } else $rows[] = ['Field' => 'checkpoint_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];
            return new CheckpointSchemaRows($rows);
        }
        if (str_contains($query, 'SHOW INDEX')) return new CheckpointSchemaRows([['Key_name' => 'PRIMARY', 'Column_name' => 'id']]);
        if (str_contains($query, 'REFERENTIAL_CONSTRAINTS')) return new CheckpointSchemaRows([['DELETE_RULE' => 'SET NULL']]);
        if (str_contains($query, 'ORDER BY created_at DESC, id DESC')) return new CheckpointSchemaRows([
            ['id' => '2', 'name' => 'Duplicate', 'created_at' => '2026-10-10 14:00:00'],
            ['id' => '1', 'name' => 'Duplicate', 'created_at' => '2026-10-10 14:00:00']]);
        if (str_contains($query, 'WHERE s.id = 1')) return new CheckpointSchemaRows([]);
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}
$db = new CheckpointSchemaDatabase;
$schema = ioaInspectCheckpointSchema($db);
if (count($schema['operations']) !== 2 || $schema['issues']) throw new RuntimeException('Missing tables');
foreach ($db->queries as $query) if (!str_starts_with($query, 'SELECT')) throw new RuntimeException('Inspection performed writes');
foreach ($schema['operations'] as $sql) if (!str_starts_with($sql, 'CREATE TABLE') || str_contains($sql, 'ioa_events')) throw new RuntimeException('Unsafe upgrade');
$db->exists = true;
$schema = ioaInspectCheckpointSchema($db);
if ($schema['operations'] || $schema['issues']) throw new RuntimeException('Repeat upgrade not harmless');
$db->compatible = false;
if (!ioaInspectCheckpointSchema($db)['issues']) throw new RuntimeException('Mutable timestamp accepted');
[$list, $start] = ioaCheckpointState($db);
if (array_column($list, 'id') !== ['2', '1'] || $start !== null) throw new RuntimeException('List/default preference');
$uninstall = file_get_contents(__DIR__ . '/../uninstall.php');
if (!str_contains($uninstall, 'DROP TABLE IF EXISTS `ioa_statistics_start`')
    || !str_contains($uninstall, 'DROP TABLE IF EXISTS `ioa_checkpoints`')) throw new RuntimeException('Uninstall ownership');
echo "Checkpoint schema inspection and state-query cases passed (database spy)\n";
