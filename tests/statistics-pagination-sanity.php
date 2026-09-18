<?php
$root=dirname(__DIR__);
$files=[
    'service'=>file_get_contents($root.'/wp-content/plugins/ascla-core/src/Services/Statistics.php'),
    'repo'=>file_get_contents($root.'/wp-content/plugins/ascla-core/src/Repositories/StatisticsRepository.php'),
    'queries'=>file_get_contents($root.'/wp-content/plugins/ascla-core/src/Repositories/StatisticsQueries.php'),
    'insights'=>file_get_contents($root.'/wp-content/plugins/ascla-core/src/Services/TopicInsights.php'),
    'content'=>file_get_contents($root.'/wp-content/plugins/ascla-core/src/Services/Content.php'),
];
$checks=[];
$expect=function(bool $ok,string $message)use(&$checks){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} $checks[]=$message;};
$expect(!str_contains($files['service'],'Data::events('),'dashboard no longer materializes the complete event collection');
$expect(!str_contains($files['service'],'array_slice($current'),'event table is not paginated after an in-memory full collection');
$expect(str_contains($files['service'],'Data::eventPage('),'attendance event list uses repository server pagination');
$expect(str_contains($files['service'],'Queries::cohort($c[\'currentScope\']'),'current aggregates use an SQL event scope');
$expect(str_contains($files['repo'],'function eventScopeSql'),'repository exposes reusable SQL event scopes');
$expect(str_contains($files['repo'],'LIMIT %d OFFSET %d'),'interactive event pages are bounded at SQL level');
$expect(str_contains($files['repo'],'WHERE id>%d ORDER BY id ASC LIMIT %d'),'large AI/topic scans use bounded keyset batches');
$expect(str_contains($files['queries'],'JOIN ($scope) e ON e.id=reg.event_id'),'registrations aggregate against the SQL event scope');
$expect(str_contains($files['queries'],'JOIN ($scope) e ON e.id=att.event_id'),'attendance aggregates against the SQL event scope');
$expect(str_contains($files['insights'],'Data::eventBatches('),'topic insights stream events in bounded batches');
$expect(str_contains($files['content'],"'_ascla_cancelled'"),'event cancellation has a queryable index for statistics');
$expect(str_contains($files['repo'],'legacy_cancelled'),'pre-index cancelled events remain excluded');
echo 'Statistics pagination sanity: '.count($checks)." checks passed.\n";
