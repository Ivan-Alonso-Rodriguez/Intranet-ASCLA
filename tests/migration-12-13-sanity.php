<?php
/**
 * Schema 12 -> 13 migration harness without WordPress/MySQL.
 * It executes Installer::migrateImports() itself against a tiny in-memory wpdb double.
 * Run: php tests/migration-12-13-sanity.php
 */
declare(strict_types=1);

final class MigrationWpdb
{
    public string $prefix='wp_';
    /** @var array<string,array{columns:array<string,mixed>,rows:array<int,array<string,mixed>>}> */
    public array $tables=[];
    public ?string $failOnColumn=null;

    public function __construct()
    {
        $this->tables['wp_ascla_attendance']=[
            'columns'=>array_fill_keys(['id','event_id','user_id','status','minutes','source','recorded_by','updated_at'],null),
            'rows'=>[[
                'id'=>7,'event_id'=>44,'user_id'=>12,'status'=>'present','minutes'=>48,
                'source'=>'zoom','recorded_by'=>1,'updated_at'=>'2026-09-17 12:00:00',
            ]],
        ];
    }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function esc_like(string $value): string { return $value; }
    public function prepare(string $sql,...$args): string
    {
        $i=0;
        return (string)preg_replace_callback('/%[sd]/',function(array $m) use (&$i,$args): string {
            $value=$args[$i++]??'';
            return $m[0]==='%d'?(string)(int)$value:"'".str_replace("'","''",(string)$value)."'";
        },$sql);
    }
    public function get_var(string $sql): mixed
    {
        if(preg_match("/SHOW TABLES LIKE '([^']+)'/i",$sql,$m))return isset($this->tables[$m[1]])?$m[1]:null;
        if(preg_match("/SHOW COLUMNS FROM `?([^` ]+)`? LIKE '([^']+)'/i",$sql,$m))return isset($this->tables[$m[1]]['columns'][$m[2]])?$m[2]:null;
        return null;
    }
    public function query(string $sql): int|false
    {
        if(preg_match('/ALTER TABLE `?([^` ]+)`? ADD ([a-zA-Z0-9_]+) (.+)$/is',trim($sql),$m)){
            $table=$m[1];$column=$m[2];$definition=trim($m[3]);
            if($this->failOnColumn===$column)return false;
            if(!isset($this->tables[$table]))return false;
            if(isset($this->tables[$table]['columns'][$column]))return 0;
            $default=str_contains(strtoupper($definition),"DEFAULT ''")?'':null;
            $this->tables[$table]['columns'][$column]=$default;
            foreach($this->tables[$table]['rows'] as &$row)$row[$column]=$default;
            unset($row);
            return 1;
        }
        return false;
    }
    public function dbDelta(string $sql): void
    {
        if(!preg_match('/CREATE TABLE\s+([^\s(]+)\s*\((.*)\)\s*ENGINE=/is',$sql,$m))throw new RuntimeException('Unsupported dbDelta SQL');
        $table=trim($m[1],'`');
        if(isset($this->tables[$table]))return;
        $columns=[];
        foreach(preg_split('/\R/',trim($m[2])) as $line){
            $line=trim($line," \t\n\r\0\x0B,");
            if($line===''||preg_match('/^(PRIMARY|UNIQUE|KEY)\b/i',$line))continue;
            if(preg_match('/^([a-zA-Z0-9_]+)\s+/', $line,$c))$columns[$c[1]]=null;
        }
        $this->tables[$table]=['columns'=>$columns,'rows'=>[]];
    }
}

$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ascla-fake-wp-'.bin2hex(random_bytes(4));
@mkdir($tmp.'/wp-admin/includes',0777,true);
file_put_contents($tmp.'/wp-admin/includes/upgrade.php',"<?php\n");
define('ABSPATH',$tmp.'/');

$GLOBALS['ascla_test_options']=['ascla_schema'=>12];
$GLOBALS['ascla_test_scheduled']=[];
$GLOBALS['wpdb']=new MigrationWpdb();
function get_option(string $key,mixed $default=false): mixed { return $GLOBALS['ascla_test_options'][$key]??$default; }
function update_option(string $key,mixed $value,bool $autoload=false): bool { $GLOBALS['ascla_test_options'][$key]=$value;return true; }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['ascla_test_scheduled'][$hook]??false; }
function wp_schedule_single_event(int $timestamp,string $hook): bool { $GLOBALS['ascla_test_scheduled'][$hook]=$timestamp;return true; }
function dbDelta(string $sql): void { $GLOBALS['wpdb']->dbDelta($sql); }

require $root.'/wp-content/plugins/ascla-core/src/Database/MigrationException.php';
require $root.'/wp-content/plugins/ascla-core/src/Database/Installer.php';

$checks=0;
$assert=function(bool $ok,string $message) use (&$checks): void {
    $checks++;if(!$ok)throw new RuntimeException('FAIL: '.$message);
};
$method=(new ReflectionClass(\ASCLA\Core\Database\Installer::class))->getMethod('migrateImports');
$method->setAccessible(true);

// Representative pre-existing data outside the custom migration tables.
$unrelated=[
    'user'=>['ID'=>12,'email'=>'member@example.test'],
    'profile'=>['first_name'=>'Conservado','interests'=>[5,8]],
    'event'=>['ID'=>44,'title'=>'Evento existente'],
    'registration'=>['event_id'=>44,'user_id'=>12,'status'=>'confirmed'],
];
$unrelatedBefore=serialize($unrelated);
$attendanceBefore=$GLOBALS['wpdb']->tables['wp_ascla_attendance']['rows'][0];

$method->invoke(null);
$assert(get_option('ascla_schema')===13,'schema reaches 13 only after migration');
foreach(['wp_ascla_imports','wp_ascla_import_rows','wp_ascla_user_interests'] as $table)$assert(isset($GLOBALS['wpdb']->tables[$table]),'creates '.$table);
foreach(['seconds','sessions','review_reason'] as $column)$assert(array_key_exists($column,$GLOBALS['wpdb']->tables['wp_ascla_attendance']['columns']),'adds attendance '.$column);
$after=$GLOBALS['wpdb']->tables['wp_ascla_attendance']['rows'][0];
foreach(array_keys($attendanceBefore) as $key)$assert($after[$key]===$attendanceBefore[$key],'preserves attendance '.$key);
$assert($after['seconds']===null && $after['sessions']===null && $after['review_reason']==='','new attendance defaults are non-destructive');
$assert(serialize($unrelated)===$unrelatedBefore,'unrelated user/profile/event/registration data untouched');
$assert(get_option('ascla_interest_index_cursor')===0,'interest rebuild cursor initialized');
$assert((bool)wp_next_scheduled('ascla_interest_index'),'interest rebuild scheduled');

// Idempotence: a second run at schema 13 is a no-op.
$snapshot=serialize($GLOBALS['wpdb']->tables);
$method->invoke(null);
$assert(serialize($GLOBALS['wpdb']->tables)===$snapshot,'migration is idempotent at schema 13');

// Failure safety: an ALTER failure must not record schema 13; retry can finish.
$GLOBALS['wpdb']=new MigrationWpdb();
$GLOBALS['ascla_test_options']=['ascla_schema'=>12];
$GLOBALS['ascla_test_scheduled']=[];
$GLOBALS['wpdb']->failOnColumn='sessions';
$failed=false;
try{$method->invoke(null);}catch(\ASCLA\Core\Database\MigrationException){$failed=true;}
$assert($failed,'ALTER failure raises MigrationException');
$assert(get_option('ascla_schema')===12,'failed migration does not mark schema 13');
$assert(isset($GLOBALS['wpdb']->tables['wp_ascla_imports']),'partial retry-safe table creation may persist');
$GLOBALS['wpdb']->failOnColumn=null;
$method->invoke(null);
$assert(get_option('ascla_schema')===13,'retry completes after transient failure');
foreach(['seconds','sessions','review_reason'] as $column)$assert(array_key_exists($column,$GLOBALS['wpdb']->tables['wp_ascla_attendance']['columns']),'retry has '.$column);

fwrite(STDOUT,"Schema 12->13 sanity: {$checks} checks passed.\n");
@unlink($tmp.'/wp-admin/includes/upgrade.php');@rmdir($tmp.'/wp-admin/includes');@rmdir($tmp.'/wp-admin');@rmdir($tmp);
