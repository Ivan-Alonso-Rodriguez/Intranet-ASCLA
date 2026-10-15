<?php
/** A proposal without an external AI call, owned by the active disposable acceptance run. */
if (!defined('ABSPATH')) { require '/var/www/html/wp-load.php'; }
if (PHP_SAPI!=='cli' || getenv('ASCLA_ACCEPTANCE_ENV')!=='qa') { throw new RuntimeException('Explicit QA environment required.'); }
$fixture=get_option('ascla_acceptance_fixture');
if (!$fixture || ($fixture['run']??'')!==getenv('ASCLA_ACCEPTANCE_RUN')) { throw new RuntimeException('Acceptance run does not own fixtures.'); }
$author=(int)($fixture['actors']['admin']['id']??0);$user=get_userdata($author);
if (!$user || !str_starts_with($user->user_login,$fixture['prefix'])) { throw new RuntimeException('Synthetic fixture author required.'); }
$id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'draft','post_author'=>$author,'post_title'=>$fixture['prefix'].' Microevento en preparación','post_content'=>''],true);
if (is_wp_error($id)) { throw new RuntimeException('Could not create proposal fixture.'); }
update_post_meta($id,'_ascla',['micro'=>true,'capacity'=>0,'chatham'=>false]);
echo wp_json_encode(['id'=>$id]).PHP_EOL;
