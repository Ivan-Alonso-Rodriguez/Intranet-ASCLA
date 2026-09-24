<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Administration reads and decisions reuse WordPress users, posts and existing metadata. */
final class Administration
{
    private const EMAIL_IN_USE='Ese correo ya pertenece a otra cuenta.';
    private const INVALID_STATUS='Estado no válido.';
    private const REQUEST_STATES=['open','progress','closed'];
    private static function requestStateLabel(string $state): string
    {
        return ['open'=>'Recibida','progress'=>'En atención','closed'=>'Resuelta'][$state]??$state;
    }
    private static function contactArgs(string $state,string $q): array
    {
        $args=['post_type'=>'ascla_contact','post_status'=>'private','s'=>$q,'orderby'=>['date'=>'DESC','ID'=>'DESC'],'posts_per_page'=>20];
        if($state==='open') { $args['meta_query']=[['relation'=>'OR',['key'=>'_ascla_request_status','compare'=>'NOT EXISTS'],['key'=>'_ascla_request_status','value'=>'open']]]; }
        elseif(in_array($state,self::REQUEST_STATES,true)) { $args['meta_query']=[['key'=>'_ascla_request_status','value'=>$state]]; }
        return $args;
    }
    public static function contacts(array $filter): array
    {
        Access::require(current_user_can('ascla_moderate'));
        // Backfill only the search index of older contact metadata; no schema migration.
        if(!get_option('ascla_contacts_indexed_v1')) {
            foreach(get_posts(['post_type'=>'ascla_contact','post_status'=>'private','numberposts'=>-1,'fields'=>'ids']) as $id) { $meta=(array)get_post_meta($id,'_ascla',true);update_post_meta($id,'_ascla_request_status',$meta['request_status']??'open'); }
            update_option('ascla_contacts_indexed_v1',true,false);
        }
        $state=Access::text($filter['state']??'',20);Access::require($state===''||in_array($state,self::REQUEST_STATES,true),self::INVALID_STATUS,400);
        $q=Access::text($filter['q']??'',120);$page=max(1,min(10000,(int)($filter['page']??1)));
        $query=new \WP_Query(self::contactArgs($state,$q)+['paged'=>$page]);$counts=[];
        foreach(self::REQUEST_STATES as $key) {$args=self::contactArgs($key,'');$args['posts_per_page']=1;$args['fields']='ids';$counts[$key]=(int)(new \WP_Query($args))->found_posts;}
        return ['items'=>array_map([Content::class,'serialize'],$query->posts),'page'=>$page,'pages'=>(int)$query->max_num_pages,'total'=>(int)$query->found_posts,'counts'=>$counts];
    }
    public static function contactStatus(int $id,string $status): array
    {
        Access::require(current_user_can('ascla_moderate'));$post=Content::get($id);
        Access::require($post->post_type==='ascla_contact','Solicitud no válida.',400);
        Access::require(in_array($status,self::REQUEST_STATES,true),self::INVALID_STATUS,400);
        return Store::lock('contact:'.$id,static function()use($id,$status,$post){
            clean_post_cache($id);$post=Content::get($id); // Recheck after acquiring the same lock used by deletion.
            $meta=(array)get_post_meta($id,'_ascla',true);$previous=$meta['request_status']??'open';
            if($previous!==$status) {
                $meta['request_status']=$status;$meta['request_updated_at']=gmdate('c');
                $history=(array)($meta['request_history']??[]);$history[]=['from'=>$previous,'to'=>$status,'at'=>$meta['request_updated_at'],'actor'=>Profiles::publicName(get_current_user_id())];
                $meta['request_history']=array_slice($history,-20);update_post_meta($id,'_ascla',$meta);
                Audit::record('contact_status',$id,$status);
                $author=(int)$post->post_author;
                if($author>0 && Access::member($author)) {
                    Notifications::send(
                        $author,
                        'support_update',
                        'Tu solicitud #'.$id.' ahora está '.self::requestStateLabel($status).'.',
                        \ASCLA\Core\Domain\Catalog::url('contacto'),
                        ['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]
                    );
                }
            }
            update_post_meta($id,'_ascla_request_status',$status);
            return Content::serialize($post);
        });
    }
    public static function deleteContact(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_moderate'));
        return Store::lock('contact:'.$id,static function()use($id){
            $post=Content::get($id);
            Access::require($post->post_type==='ascla_contact','Solicitud no válida.',400);
            $meta=(array)get_post_meta($id,'_ascla',true);
            Access::require(($meta['request_status']??'open')==='closed','Resuelve la solicitud antes de eliminarla.',409);
            Access::require((bool)wp_trash_post($id),'No se pudo eliminar la solicitud.',500);
            Audit::record('contact_deleted',$id,'closed');
            return ['id'=>$id,'deleted'=>true,'message'=>'Solicitud enviada a la papelera.'];
        });
    }
    private const COMMUNITY_ROLES=['ascla_member','ascla_executive','ascla_moderator'];

    private static function roleLabel(string $role): string
    {
        $labels=[
            'administrator'=>'Administrador',
            'ascla_member'=>'Asociado ASCLA',
            'ascla_executive'=>'Ejecutivo ASCLA',
            'ascla_moderator'=>'Moderador ASCLA',
        ];
        if(isset($labels[$role])) { return $labels[$role]; }
        $data=wp_roles()->roles[$role]??null;
        return $data?translate_user_role($data['name']):$role;
    }

    private static function editableMember(int $id): \WP_User
    {
        Access::require(current_user_can('ascla_manage'),'Acceso no autorizado.',403);
        $user=get_userdata($id);
        Access::require($user instanceof \WP_User,'Usuario no encontrado.',404);
        Access::require($id!==get_current_user_id(),'Tu cuenta administrativa se gestiona desde WordPress.',400);
        Access::require(!user_can($id,'manage_options'),'Los administradores técnicos se gestionan desde WordPress.',403);
        Access::require(user_can($id,'ascla_access'),'La cuenta no pertenece a la comunidad ASCLA.',400);
        return $user;
    }

    private static function communityRoles(): array
    {
        $roles=[];
        foreach(self::COMMUNITY_ROLES as $id) {
            $roleData=wp_roles()->roles[$id]??null;
            if($roleData) { $roles[]=['id'=>$id,'name'=>self::roleLabel($id)]; }
        }
        return $roles;
    }

    public static function users(array $filter): array
    {
        Access::require(current_user_can('ascla_manage'));
        $q=Access::text($filter['q']??'',100);$role=Access::text($filter['role']??'',60);$state=Access::text($filter['state']??'',20);
        Access::require($role===''||isset(wp_roles()->roles[$role]),'Rol no válido.',400);
        Access::require(in_array($state,['','active','suspended'],true),self::INVALID_STATUS,400);
        $page=max(1,min(10000,(int)($filter['page']??1)));$args=['number'=>20,'paged'=>$page,'orderby'=>'display_name','order'=>'ASC'];
        if($q!=='') {$args['search']='*'.str_replace('*','',$q).'*';$args['search_columns']=['user_login','user_email','display_name'];}
        if($role!=='') {$args['role']=$role; }
        if($state==='suspended') {$args['meta_query']=[['key'=>'_ascla_suspended','value'=>'1']]; }
        elseif($state==='active') {$args['meta_query']=[['relation'=>'OR',['key'=>'_ascla_suspended','compare'=>'NOT EXISTS'],['key'=>'_ascla_suspended','value'=>'1','compare'=>'!=']]]; }
        $query=new \WP_User_Query($args);$items=[];
        foreach($query->get_results() as $user) {
            $id=(int)$user->ID;$card=Profiles::card($id);
            $technical=user_can($id,'manage_options');
            $community=user_can($id,'ascla_access');
            $items[]=[
                'id'=>$id,
                'name'=>$user->display_name,
                'login'=>$user->user_login,
                'email'=>$user->user_email,
                'roles'=>array_values($user->roles),
                'registered'=>$user->user_registered,
                'suspended'=>(bool)get_user_meta($id,'_ascla_suspended',true),
                'photo_url'=>$card['photo_url'],
                'profile_url'=>$card['profile_url'],
                'technical_admin'=>$technical,
                'can_edit'=>$community&&!$technical&&$id!==get_current_user_id()&&current_user_can('edit_user',$id),
                'can_delete'=>$community&&!$technical&&$id!==get_current_user_id()&&current_user_can('delete_user',$id),
                'can_suspend'=>$id!==get_current_user_id()&&!$technical&&$community,
            ];
        }
        $roles=[];foreach(wp_roles()->roles as $id=>$roleData) {$roles[]=['id'=>$id,'name'=>self::roleLabel($id)]; }
        return [
            'items'=>$items,
            'page'=>$page,
            'total'=>$query->get_total(),
            'pages'=>(int)ceil($query->get_total()/20),
            'roles'=>$roles,
            'can_create'=>current_user_can('create_users'),
            'create_roles'=>self::communityRoles(),
        ];
    }

    public static function createUser(array $input): array
    {
        Access::require(current_user_can('ascla_manage')&&current_user_can('create_users'),'No puedes crear cuentas.',403);
        Access::limit('admin_user_create',12,300);

        $loginInput=trim(Access::text($input['login']??'',60));
        $login=sanitize_user($loginInput,true);
        Access::require($login!==''&&$login===$loginInput&&validate_username($login),'Use un nombre de usuario válido, sin espacios ni caracteres especiales no permitidos.',400);
        Access::require(!username_exists($login),'Ese nombre de usuario ya está registrado.',409);

        $email=sanitize_email(Access::text($input['email']??'',100));
        Access::require($email!==''&&is_email($email),'Correo electrónico no válido.',400);
        Access::require(!email_exists($email),self::EMAIL_IN_USE,409);

        $role=Access::text($input['role']??'ascla_member',60);
        Access::require(in_array($role,self::COMMUNITY_ROLES,true),'Rol no válido para la comunidad.',400);
        $first=Access::text($input['first_name']??'',100);
        $last=Access::text($input['last_name']??'',100);
        Access::require(trim($first.$last)!=='','Indica al menos un nombre o apellido.',400);
        $position=Access::text($input['position']??'',200);
        $company=Access::text($input['company']??'',200);
        $memberType=Access::text($input['member_type']??'',200);
        $birthDate=Birthdays::normalize($input['birth_date']??'');
        $phone=\ASCLA\Core\Domain\Phone::normalize($input['phone']??'');
        $phoneVisibility=\ASCLA\Core\Domain\Phone::visibility($input['phone_visibility']??'private');
        $sendInvite=!array_key_exists('send_invite',$input)||rest_sanitize_boolean($input['send_invite']);

        return Store::lock('admin-create-user:'.hash('sha256',$login.'|'.$email),static function()use($login,$email,$role,$first,$last,$position,$company,$memberType,$birthDate,$sendInvite,$phone,$phoneVisibility){
            Access::require(!username_exists($login),'Ese nombre de usuario ya está registrado.',409);
            Access::require(!email_exists($email),self::EMAIL_IN_USE,409);
            $password=wp_generate_password(32,true,true);
            $id=wp_insert_user([
                'user_login'=>$login,
                'user_email'=>$email,
                'user_pass'=>$password,
                'display_name'=>trim($first.' '.$last)?:$login,
                'first_name'=>$first,
                'last_name'=>$last,
                'role'=>$role,
            ]);
            Access::require(!is_wp_error($id),is_wp_error($id)?$id->get_error_message():'No se pudo crear la cuenta.',400);
            $id=(int)$id;
            update_user_meta($id,'_ascla_profile',[
                'first_name'=>$first,
                'last_name'=>$last,
                'position'=>$position,
                'company'=>$company,
                'member_type'=>$memberType,
                'birth_date'=>$birthDate,
                'phone'=>$phone,'phone_visibility'=>$phoneVisibility,
                'directory'=>true,
                'networking'=>true,
                'microevents'=>true,
                'hidden'=>[],
                'revision'=>1,
            ]);
            update_option('ascla_profile_revision',(int)get_option('ascla_profile_revision',0)+1,false);

            if($sendInvite) {
                try { wp_new_user_notification($id,null,'user'); }
                catch (\Throwable $error) { /* La cuenta no depende del transporte de correo. */ }
            }
            Audit::record('member_created',$id,'role='.$role.'; invite='.($sendInvite?'requested':'disabled'));
            Birthdays::celebrate($id);
            $user=self::user($id);
            $user['created']=true;
            $user['invite_requested']=$sendInvite;
            $user['message']=$sendInvite
                ?'Usuario creado. Se solicitó el correo para que configure su contraseña.'
                :'Usuario creado. Puede usar “¿Olvidaste tu contraseña?” en /login/ para establecer su acceso.';
            return $user;
        });
    }

    public static function user(int $id): array
    {
        $user=self::editableMember($id);
        $profile=(array)get_user_meta($id,'_ascla_profile',true);
        $role='ascla_member';
        foreach(self::COMMUNITY_ROLES as $candidate) { if(in_array($candidate,$user->roles,true)) {$role=$candidate;break;} }
        return [
            'id'=>$id,
            'login'=>$user->user_login,
            'email'=>$user->user_email,
            'registered'=>$user->user_registered,
            'role'=>$role,
            'roles'=>self::communityRoles(),
            'first_name'=>$profile['first_name']??$user->first_name,
            'last_name'=>$profile['last_name']??$user->last_name,
            'position'=>$profile['position']??'',
            'company'=>$profile['company']??'',
            'member_type'=>$profile['member_type']??'',
            'birth_date'=>$profile['birth_date']??'',
            'phone'=>$profile['phone']??'','phone_visibility'=>$profile['phone_visibility']??'private',
            'photo_url'=>Profiles::card($id)['photo_url'],
            'suspended'=>(bool)get_user_meta($id,'_ascla_suspended',true),
        ];
    }

    public static function updateUser(int $id,array $input): array
    {
        $user=self::editableMember($id);
        Access::require(current_user_can('edit_user',$id),'No puedes editar esta cuenta.',403);
        $email=sanitize_email(Access::text($input['email']??'',100));
        Access::require($email!==''&&is_email($email),'Correo electrónico no válido.',400);
        $existing=email_exists($email);
        Access::require(!$existing||(int)$existing===$id,self::EMAIL_IN_USE,409);
        $role=Access::text($input['role']??'',60);
        Access::require(in_array($role,self::COMMUNITY_ROLES,true),'Rol no válido para la comunidad.',400);
        $first=Access::text($input['first_name']??'',100);
        $last=Access::text($input['last_name']??'',100);
        Access::require(trim($first.$last)!=='','Indica al menos un nombre o apellido.',400);
        $previousProfile=(array)get_user_meta($id,'_ascla_profile',true);
        $phone=\ASCLA\Core\Domain\Phone::normalize($input['phone']??($previousProfile['phone']??''));
        $phoneVisibility=\ASCLA\Core\Domain\Phone::visibility($input['phone_visibility']??($previousProfile['phone_visibility']??'private'));
        $display=trim($first.' '.$last)?:$user->display_name;
        $result=wp_update_user(['ID'=>$id,'user_email'=>$email,'first_name'=>$first,'last_name'=>$last,'display_name'=>$display]);
        Access::require(!is_wp_error($result),is_wp_error($result)?$result->get_error_message():'No se pudo actualizar la cuenta.',400);
        $user->set_role($role);
        $profile=(array)get_user_meta($id,'_ascla_profile',true);
        $profile['first_name']=$first;$profile['last_name']=$last;
        $profile['phone']=$phone;$profile['phone_visibility']=$phoneVisibility;
        $profile['position']=Access::text($input['position']??'',200);
        $profile['company']=Access::text($input['company']??'',200);
        $profile['member_type']=Access::text($input['member_type']??'',200);
        $profile['birth_date']=Birthdays::normalize($input['birth_date']??'');
        $profile['revision']=(int)($profile['revision']??0)+1;
        update_user_meta($id,'_ascla_profile',$profile);
        update_option('ascla_profile_revision',(int)get_option('ascla_profile_revision',0)+1,false);
        Audit::record('member_updated',$id,'role='.$role);
        Birthdays::celebrate($id);
        return self::user($id);
    }

    public static function deleteUser(int $id): array
    {
        $target=self::editableMember($id);
        Access::require(current_user_can('delete_user',$id),'No puedes eliminar esta cuenta.',403);
        $actor=get_current_user_id();
        $login=$target->user_login;
        return Store::lock('admin-delete-user:'.$id,static function()use($id,$actor,$login){
            require_once ABSPATH.'wp-admin/includes/user.php';
            $deleted=wp_delete_user($id,$actor);
            Access::require($deleted===true,'No se pudo eliminar la cuenta.',500);
            Store::delete('relations',['user_id'=>$id]);
            Store::delete('relations',['target_id'=>$id]);
            Events::removeMemberRegistrations($id);
            Store::delete('notifications',['user_id'=>$id]);
            Store::delete('jobs',['user_id'=>$id]);
            Store::update('media',['user_id'=>$actor],['user_id'=>$id]);
            \ASCLA\Core\Integrations\Secrets::remove('google_calendar_'.$id);
            Audit::record('member_deleted',$id,$login);
            update_option('ascla_profile_revision',(int)get_option('ascla_profile_revision',0)+1,false);
            return ['id'=>$id,'deleted'=>true,'message'=>'Usuario eliminado. El contenido publicado se conservó bajo administración de ASCLA.'];
        });
    }

    public static function suspend(int $id,bool $suspended): array
    {
        Access::require(current_user_can('ascla_manage'));
        Access::require($id>0 && $id!==get_current_user_id()&&!user_can($id,'manage_options')&&user_can($id,'ascla_access'),'Cuenta no disponible.',400);
        update_user_meta($id,'_ascla_suspended',$suspended);Audit::record($suspended?'member_suspended':'member_reactivated',$id);
        return ['id'=>$id,'suspended'=>$suspended];
    }
}
