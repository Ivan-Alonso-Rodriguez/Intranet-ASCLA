<?php
require '/var/www/html/wp-load.php';
if(PHP_SAPI!=='cli' || wp_get_environment_type()!=='local'){exit(1);}
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\{Content,Profiles};
use ASCLA\Core\Repositories\Store;
$in=json_decode(stream_get_contents(STDIN),true);
if(($in['action']??'')==='setup'){
    $users=[];
    foreach(['ascla_member','ascla_moderator','ascla_executive','administrator'] as $role){
        $login='journey_roles_'.bin2hex(random_bytes(6));$password=wp_generate_password(32);
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role,'display_name'=>'Prueba '.$role]);
        if(is_wp_error($id)){exit(2);}
        wp_set_current_user($id);Profiles::save(['first_name'=>'Prueba','last_name'=>$role]);
        $users[]=['id'=>$id,'login'=>$login,'password'=>$password,'role'=>$role];
    }
    wp_set_current_user($users[3]['id']);
    $post=Content::save('hub',['title'=>'Aporte de otra persona para probar roles','body'=>'Contenido público de prueba.','status'=>'publish']);
    $private=Store::insert('media',['user_id'=>$users[0]['id'],'post_id'=>0,'name'=>'privado.pdf','mime'=>'application/pdf','bytes'=>'fixture private file','created_at'=>current_time('mysql',true)]);
    $attached=Store::insert('media',['user_id'=>$users[0]['id'],'post_id'=>$post['id'],'name'=>'comunidad.pdf','mime'=>'application/pdf','bytes'=>'fixture public community file','created_at'=>current_time('mysql',true)]);
    echo wp_json_encode(['users'=>$users,'post'=>$post['id'],'private'=>$private,'attached'=>$attached]);
}elseif(($in['action']??'')==='cleanup'){
    $ids=array_map('intval',$in['users']??[]);if(!$ids){exit(3);}
    foreach($ids as $id){$user=get_userdata($id);if(!$user || !str_starts_with($user->user_login,'journey_roles_')){exit(4);}}
    foreach($ids as $id){
        $posts=get_posts(['author'=>$id,'post_type'=>array_map(static fn($type)=>'ascla_'.$type,array_keys(ASCLA\Core\Domain\Catalog::TYPES)),'post_status'=>array_keys(get_post_stati()),'numberposts'=>-1]);
        foreach($posts as $post){wp_delete_post($post->ID,true);}
        foreach(['notifications','jobs','relations','registrations','media'] as $table){Store::delete($table,['user_id'=>$id]);}
        Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
    }
    echo '{}';
}
