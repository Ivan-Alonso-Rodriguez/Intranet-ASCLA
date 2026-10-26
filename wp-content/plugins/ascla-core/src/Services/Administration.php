<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Repositories\WordPressWrites;

/** Administration reads and decisions reuse WordPress users, posts and existing metadata. */
final class Administration
{
    private const EMAIL_IN_USE='Ese correo ya pertenece a otra cuenta.';
    private const INVALID_STATUS='Estado no válido.';
    public static function contacts(array $filter): array { return SupportRequests::listing($filter); }
    public static function contactStatus(int $id,string $status,string $response=''): array { return SupportRequests::change($id,$status,$response); }
    public static function deleteContact(int $id): array { return SupportRequests::archive($id); }
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
        Access::require((bool)array_intersect($user->roles,self::COMMUNITY_ROLES),'La cuenta no pertenece a la comunidad ASCLA.',400);
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

    public static function permissions(int $id): array
    {
        $user=get_userdata($id);
        if (!$user || !current_user_can('ascla_manage')) { return []; }
        $technical=user_can($id,'manage_options');
        $community=(bool)array_intersect($user->roles,self::COMMUNITY_ROLES);
        return [
            'technical_admin'=>$technical,
            'can_edit'=>$community && !$technical && $id!==get_current_user_id() && Access::canManageAccount('edit_user',$id),
            'can_delete'=>$community && !$technical && $id!==get_current_user_id() && Access::canManageAccount('delete_user',$id),
            'can_suspend'=>$community && !$technical && $id!==get_current_user_id(),
            'can_record_role_approval'=>Access::canManageAccount('edit_user',$id),
        ];
    }

    public static function users(array $filter): array
    {
        Access::require(current_user_can('ascla_manage'));
        $q=Access::text($filter['q']??'',100);$role=Access::text($filter['role']??'',60);$state=Access::text($filter['state']??'',20);
        Access::require($role===''||isset(wp_roles()->roles[$role]),'Rol no válido.',400);
        Access::require(in_array($state,['','active','suspended','expired'],true),self::INVALID_STATUS,400);
        $page=max(1,min(10000,(int)($filter['page']??1)));$args=['number'=>20,'paged'=>$page,'orderby'=>'display_name','order'=>'ASC'];
        if($role!=='') {$args['role']=$role; }
        if($state!=='') { $args['meta_query']=Membership::filter($state); }
        // Filter the complete administrative scope before pagination, including
        // suspended accounts and profiles that opted out of the public directory.
        if($q!=='' || !empty($filter['country']) || !empty($filter['industries']) || !empty($filter['interests']) || !empty($filter['areas'])) {
            $ids=self::filteredUserIds($args,$filter,$q);
            $args['include']=$ids?:[0];
        }
        $query=new \WP_User_Query($args);$items=[];
        foreach($query->get_results() as $user) {
            $id=(int)$user->ID;$card=Profiles::card($id);
            $items[]=[
                'id'=>$id,
                'name'=>$user->display_name,
                'login'=>$user->user_login,
                'email'=>$user->user_email,
                'roles'=>array_values($user->roles),
                'registered'=>$user->user_registered,
                'suspended'=>(bool)get_user_meta($id,'_ascla_suspended',true),
                'access_enabled'=>user_can($id,'ascla_access'),
            ...Membership::view($id),
                'photo_url'=>$card['photo_url'],
                'profile_url'=>$card['profile_url'],
                ...self::permissions($id),
            ];
        }
        $roles=[];foreach(wp_roles()->roles as $id=>$roleData) {$roles[]=['id'=>$id,'name'=>self::roleLabel($id)]; }
        return [
            'items'=>$items,
            'page'=>$page,
            'total'=>$query->get_total(),
            'pages'=>(int)ceil($query->get_total()/20),
            'roles'=>$roles,
            'can_create'=>Access::canManageAccount('create_users'),
            'create_roles'=>self::communityRoles(),
        ];
    }

    private static function normalizeSearch(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',remove_accents($value))));
    }

    private static function filteredUserIds(array $args,array $filter,string $query): array
    {
        $query=self::normalizeSearch($query);$country=self::normalizeSearch(Access::text($filter['country']??'',120));
        $selected=[];
        foreach(['industries','interests','areas'] as $key) {
            $selected[$key]=array_values(array_unique(array_filter(array_map('absint',(array)($filter[$key]??[])))));
        }
        $args['number']=200;$args['count_total']=false;unset($args['paged']);$offset=0;$ids=[];
        do {
            $args['offset']=$offset;$users=(new \WP_User_Query($args))->get_results();$offset+=200;
            foreach($users as $user) {
                $profile=(array)get_user_meta($user->ID,'_ascla_profile',true);
                if(self::userMatchesFilters($user,$profile,$country,$selected,$query)) { $ids[]=(int)$user->ID; }
            }
        } while(count($users)===200);
        return $ids;
    }

    private static function userMatchesFilters(\WP_User $user,array $profile,string $country,array $selected,string $query): bool
    {
        if($country!=='' && self::normalizeSearch((string)($profile['country']??''))!==$country) { return false; }
        foreach($selected as $key=>$values) {
            if($values && !array_intersect($values,(array)($profile[$key]??[]))) { return false; }
        }
        return $query==='' || str_contains(self::userSearchText($user,$profile),$query);
    }

    private static function userSearchText(\WP_User $user,array $profile): string
    {
        $values=[$user->display_name,$user->user_login,$user->user_email,$user->first_name,$user->last_name];
        foreach(['first_name','last_name','position','company','bio','experience'] as $key) { $values[]=$profile[$key]??''; }
        $values[]=wp_json_encode(Profiles::labels($profile),JSON_UNESCAPED_UNICODE);
        return self::normalizeSearch(implode(' ',$values));
    }

    private static function createUserLocked(array $account): array
    {
        Access::require(!username_exists($account['login']),'Ese nombre de usuario ya está registrado.',409);
        Access::require(!email_exists($account['email']),self::EMAIL_IN_USE,409);
        $id=wp_insert_user([
            'user_login'=>$account['login'],
            'user_email'=>$account['email'],
            'user_pass'=>wp_generate_password(32,true,true),
            'display_name'=>trim($account['first'].' '.$account['last'])?:$account['login'],
            'first_name'=>$account['first'],
            'last_name'=>$account['last'],
            'role'=>$account['role'],
        ]);
        Access::require(!is_wp_error($id),is_wp_error($id)?$id->get_error_message():'No se pudo crear la cuenta.',400);$id=(int)$id;
        WordPressWrites::meta('user',$id,'_ascla_profile',[
            'first_name'=>$account['first'],'last_name'=>$account['last'],'position'=>$account['position'],'company'=>$account['company'],'member_type'=>$account['member_type'],
            'birth_date'=>$account['birth_date'],'phone'=>$account['phone'],'phone_visibility'=>$account['phone_visibility'],'directory'=>true,'networking'=>true,'microevents'=>true,'hidden'=>[],'revision'=>1,
        ]);
        WordPressWrites::bumpProfileRevision();
        Membership::apply($id,$account['membership']);
        Audit::changes('member_created',$id,[],['roles'=>[$account['role']]]);
        if($account['send_invite']) {
            try { wp_new_user_notification($id,null,'user'); }
            catch (\Throwable $error) { /* La cuenta no depende del transporte de correo. */ }
        }
        Audit::record('member_created',$id,'role='.$account['role'].'; invite='.($account['send_invite']?'requested':'disabled'));
        Birthdays::celebrate($id);$user=self::user($id);$user['created']=true;$user['invite_requested']=$account['send_invite'];
        $user['message']=$account['send_invite']?'Usuario creado. Se solicitó el correo para que configure su contraseña.':'Usuario creado. Puede usar “¿Olvidaste tu contraseña?” en /login/ para establecer su acceso.';
        return $user;
    }

    public static function createUser(array $input): array
    {
        Access::require(Access::canManageAccount('create_users'),'No puedes crear cuentas.',403);Access::limit('admin_user_create',12,300);
        $loginInput=trim(Access::text($input['login']??'',60));$login=sanitize_user($loginInput,true);
        Access::require($login!==''&&$login===$loginInput&&validate_username($login),'Use un nombre de usuario válido, sin espacios ni caracteres especiales no permitidos.',400);
        Access::require(!username_exists($login),'Ese nombre de usuario ya está registrado.',409);
        $email=sanitize_email(Access::text($input['email']??'',100));
        Access::require($email!==''&&is_email($email),'Correo electrónico no válido.',400);Access::require(!email_exists($email),self::EMAIL_IN_USE,409);
        $role=Access::text($input['role']??'ascla_member',60);Access::require(in_array($role,self::COMMUNITY_ROLES,true),'Rol no válido para la comunidad.',400);
        $first=Access::text($input['first_name']??'',100);$last=Access::text($input['last_name']??'',100);Access::require(Access::hasText($first.$last),'Indica al menos un nombre o apellido.',400);
        $account=[
            'login'=>$login,'email'=>$email,'role'=>$role,'first'=>$first,'last'=>$last,
            'position'=>Access::text($input['position']??'',200),'company'=>Access::text($input['company']??'',200),'member_type'=>MemberTypes::selected($input['member_type']??'independent'),
            'birth_date'=>Birthdays::normalize($input['birth_date']??''),'phone'=>\ASCLA\Core\Domain\Phone::normalize($input['phone']??''),'phone_visibility'=>\ASCLA\Core\Domain\Phone::visibility($input['phone_visibility']??'private'),
            'membership'=>Membership::validate($input),
            'send_invite'=>!array_key_exists('send_invite',$input)||rest_sanitize_boolean($input['send_invite']),
        ];
        return Store::atomic('admin-create-user',static fn()=>self::createUserLocked($account));
    }

    public static function user(int $id): array
    {
        $user=self::editableMember($id);
        $profile=(array)get_user_meta($id,'_ascla_profile',true);
        $role=AccountPermissions::roles($id)[0]??'ascla_member';
        return [
            'id'=>$id,
            'login'=>$user->user_login,
            'email'=>$user->user_email,
            'registered'=>$user->user_registered,
            'role'=>$role,
            'roles'=>self::communityRoles(),
            'access_enabled'=>user_can($id,'ascla_access'),
            ...AccountPermissions::view($id),
            'first_name'=>$profile['first_name']??$user->first_name,
            'last_name'=>$profile['last_name']??$user->last_name,
            'professional_requests'=>ProfessionalChanges::pending($id),
            ...array_intersect_key($profile,array_flip(array_merge(Profiles::TEXT,array_keys(Profiles::TERMS)))),
            'profile_revision'=>(int)($profile['revision']??0),
            'position'=>$profile['position']??'',
            'company'=>$profile['company']??'',
            'member_type'=>$profile['member_type']??'',
            'member_type_code'=>MemberTypes::code((string)($profile['member_type']??'')),
            ...self::permissions($id),
            'birth_date'=>$profile['birth_date']??'',
            'phone'=>$profile['phone']??'','phone_visibility'=>$profile['phone_visibility']??'private',
            'photo_url'=>Profiles::card($id)['photo_url'],
            'suspended'=>(bool)get_user_meta($id,'_ascla_suspended',true),
            ...Membership::view($id),
        ];
    }

    public static function updateUser(int $id,array $input): array
    {
        return Store::atomic('profile-interests:'.$id,static fn()=>self::updateUserUnlocked($id,$input));
    }

    private static function updateUserUnlocked(int $id,array $input): array
    {
        clean_user_cache($id);
        $user=self::editableMember($id);
        Access::require(Access::canManageAccount('edit_user',$id),'No puedes editar esta cuenta.',403);
        $email=sanitize_email(Access::text($input['email']??'',100));
        Access::require($email!==''&&is_email($email),'Correo electrónico no válido.',400);
        $existing=email_exists($email);
        Access::require(!$existing||(int)$existing===$id,self::EMAIL_IN_USE,409);
        $role=Access::text($input['role']??'',60);
        Access::require(in_array($role,self::COMMUNITY_ROLES,true),'Rol no válido para la comunidad.',400);
        $permissionPlan=AccountPermissions::plan($id,$role,$input);
        $first=Access::text($input['first_name']??'',100);
        $last=Access::text($input['last_name']??'',100);
        Access::require(Access::hasText($first.$last),'Indica al menos un nombre o apellido.',400);
        $membership=Membership::validate($input,$id);
        $previousProfile=(array)get_user_meta($id,'_ascla_profile',true)+['first_name'=>$user->first_name,'last_name'=>$user->last_name,'phone_visibility'=>'private'];
        if (array_key_exists('profile_revision',$input)) {
            $revision=FormRules::bounded($input['profile_revision'],0,PHP_INT_MAX);
            Access::require($revision===(int)($previousProfile['revision']??0),'El perfil cambió mientras lo editabas. Cierra y vuelve a abrir Editar usuario para cargar los datos actuales.',409);
        }
        $phone=\ASCLA\Core\Domain\Phone::normalize($input['phone']??($previousProfile['phone']??''));
        $phoneVisibility=\ASCLA\Core\Domain\Phone::visibility($input['phone_visibility']??($previousProfile['phone_visibility']??'private'));
        $profile=Profiles::details($previousProfile,$input);
        $profile['first_name']=$first;$profile['last_name']=$last;
        $profile['phone']=$phone;$profile['phone_visibility']=$phoneVisibility;
        $profile=self::professionalFields($profile,$previousProfile,$input);
        $profile=MemberTypes::profile($profile,$input,$id);
        $profile['birth_date']=Birthdays::normalize($input['birth_date']??($previousProfile['birth_date']??''));
        $changed=ProfessionalChanges::changed($previousProfile,$profile);
        if ($email!==$user->user_email) { $changed[]='email'; }
        if ($changed) { ProfileValidation::validateRequired(array_intersect_key($profile,array_flip($changed))); }
        $profile['revision']=(int)($profile['revision']??0)+1;
        $save=static function()use($id,$user,$email,$first,$last,$role,$profile,$previousProfile,$input,$membership,$permissionPlan): void {
            $display=trim($first.' '.$last)?:$user->display_name;
            WordPressWrites::user(['ID'=>$id,'user_email'=>$email,'first_name'=>$first,'last_name'=>$last,'display_name'=>$display]);
            AccountPermissions::apply($id,$permissionPlan);
            Membership::apply($id,$membership);
            if (array_key_exists('interests',$input)) { AutomaticInterests::manual($id,(array)($previousProfile['interests']??[]),(array)$profile['interests']); }
            WordPressWrites::meta('user',$id,'_ascla_profile',$profile);
            WordPressWrites::bumpProfileRevision();
            Audit::record('member_updated',$id,'role='.$role);
        };
        if ($changed) { ProfessionalChanges::apply($id,absint($input['professional_request_id']??0),$changed,$save); }
        else { $save(); }
        \ASCLA\Core\Repositories\Transaction::afterCommit(static fn()=>Access::member($id)?Matching::refreshBatch($id):null);
        Birthdays::celebrate($id);
        return self::user($id);
    }

    private static function professionalFields(array $profile,array $previousProfile,array $input): array
    {
        foreach (['position','company'] as $field) {
            $profile[$field]=Access::text($input[$field]??($previousProfile[$field]??''),200);
            if ($profile[$field]!==($previousProfile[$field]??'')) { ProfileValidation::validateRequired([$field=>$profile[$field]]); }
        }
        return $profile;
    }

    public static function deleteUser(int $id): array
    {
        $target=self::editableMember($id);
        Access::require(Access::canManageAccount('delete_user',$id),'No puedes eliminar esta cuenta.',403);
        $actor=get_current_user_id();
        $login=$target->user_login;
        return Store::atomic('profile-interests:'.$id,static function()use($id,$actor,$login){
            clean_user_cache($id);self::editableMember($id);
            Access::require(Access::canManageAccount('delete_user',$id),'No puedes eliminar esta cuenta.',403);
            require_once ABSPATH.'wp-admin/includes/user.php';
            $deleted=wp_delete_user($id,$actor);
            Access::require($deleted===true,'No se pudo eliminar la cuenta.',500);
            Store::delete('relations',['user_id'=>$id]);
            // target_id also identifies posts/comments; only these kinds point to a person.
            foreach (['connect','connected','block','conversation_request','conversation_allowed','connection_cooldown','connection_history','history_revoked'] as $kind) {
                Store::delete('relations',['target_id'=>$id,'kind'=>$kind]);
            }
            Events::removeMemberRegistrations($id);
            Store::delete('notifications',['user_id'=>$id]);
            Store::delete('jobs',['user_id'=>$id]);
            Store::update('media',['user_id'=>$actor],['user_id'=>$id]);
            \ASCLA\Core\Integrations\Secrets::remove('google_calendar_'.$id);
            Audit::record('member_deleted',$id,$login);
            WordPressWrites::bumpProfileRevision();
            return ['id'=>$id,'deleted'=>true,'message'=>'Usuario eliminado. El contenido publicado se conservó bajo administración de ASCLA.'];
        });
    }

    public static function suspend(int $id,bool $suspended): array
    {
        Access::require(current_user_can('ascla_manage'));
        Access::require($id>0 && $id!==get_current_user_id()&&!user_can($id,'manage_options')&&(bool)array_intersect(AccountPermissions::roles($id),self::COMMUNITY_ROLES),'Cuenta no disponible.',400);
        Store::atomic('profile-interests:'.$id,static function()use($id,$suspended){
            clean_user_cache($id);
            $input=['membership_status'=>$suspended?'suspended':'active'];
            Membership::apply($id,Membership::validate($input,$id));
        });
        return ['id'=>$id,'suspended'=>$suspended];
    }
}
