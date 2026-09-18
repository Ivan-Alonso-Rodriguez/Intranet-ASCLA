<?php
/**
 * Static sanity checks for the interest-index cron lifecycle.
 * Run with: php tests/cron-lifecycle-sanity.php
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static fn(string $path): string => (string)file_get_contents($root.'/'.$path);
$checks=0;
$assert=static function(bool $condition,string $message) use (&$checks): void {
    $checks++;
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
};

$plugin=$read('wp-content/plugins/ascla-core/ascla-core.php');
$uninstall=$read('wp-content/plugins/ascla-core/uninstall.php');
$index=$read('wp-content/plugins/ascla-core/src/Services/InterestIndex.php');

$assert(str_contains($plugin,"wp_clear_scheduled_hook('ascla_interest_index');"),'deactivation clears interest-index cron');
$assert(str_contains($uninstall,"wp_clear_scheduled_hook('ascla_interest_index');"),'uninstall clears interest-index cron');
$assert(str_contains($index,'self::resumePending();'),'interest index checks pending work on boot');
$assert(str_contains($index,"get_option('ascla_interest_index_cursor',false)!==false"),'resume requires a pending cursor');
$assert(str_contains($index,"!wp_next_scheduled('ascla_interest_index')"),'resume does not duplicate scheduled events');
$assert(str_contains($index,"wp_schedule_single_event(time()+1,'ascla_interest_index');"),'pending rebuild is rescheduled after reactivation');

fwrite(STDOUT,"Cron lifecycle sanity: {$checks} checks passed.\n");
