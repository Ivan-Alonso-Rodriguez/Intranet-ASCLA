<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\Catalog;

final class Profiles
{
    public const TEXT=['first_name','last_name','position','company','country','city','member_type','bio','experience','linkedin','twitter','website'];
    public const TERMS=['interests'=>'interest','areas'=>'area','industries'=>'industry','goals'=>'goal','languages'=>'language','learn'=>'area','help'=>'area','connect_topics'=>'interest'];
    public static function privateName(int $id): bool
    {
        $data=(array)get_user_meta($id,'_ascla_profile',true);
        return (bool)array_intersect(['first_name','last_name'],(array)($data['hidden']??[]));
    }
    public static function publicName(int $id): string
    {
        $user=get_userdata($id);
        if (!$user) { return 'Asociado no disponible'; }
        return self::privateName($id)?'Asociado ASCLA '.$id:$user->display_name;
    }
    public static function matchingProfile(int $id): array
    {
        $data=self::raw($id);
        foreach((array)$data['hidden'] as $field){ unset($data[$field]); }
        unset($data['phone'],$data['phone_visibility'],$data['first_name'],$data['last_name'],$data['birth_date'],$data['bio'],$data['experience'],$data['company'],$data['linkedin'],$data['twitter'],$data['website']);
        $data['name']=self::publicName($id);
        return $data;
    }
    public static function networkingContext(int $id): array
    {
        $data=self::matchingProfile($id);$context=['name'=>self::publicName($id),'position'=>$data['position']??''];
        foreach(self::labels($data) as $field=>$labels){ $context[$field]=$labels; }
        return $context;
    }
    public static function searchableAuthors(string $query): array
    {
        $ids=[];
        foreach(get_users(['capability'=>'ascla_access','fields'=>'ID']) as $id) {
            if (mb_stripos(self::publicName((int)$id),$query)!==false) { $ids[]=(int)$id; }
        }
        return $ids;
    }
    public static function raw(int $id): array
    {
        $user=get_userdata($id); Access::require($user && Access::member($id),'Perfil no encontrado.',404);
        $data=(array)get_user_meta($id,'_ascla_profile',true);
        return array_merge(['id'=>$id,'name'=>$user->display_name,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'birth_date'=>'','phone'=>'','phone_visibility'=>'private','directory'=>true,'networking'=>true,'microevents'=>true,'hidden'=>[],'revision'=>0],$data,['id'=>$id,'name'=>$user->display_name]);
    }
    public static function visible(int $id): array
    {
        $data=self::raw($id);
        $own=$id===get_current_user_id();
        Access::require($own||current_user_can('ascla_moderate')||!empty($data['directory']),'Perfil no disponible.',404);
        $data=self::applyVisibility($data,$id,$own);
        unset($data['revision']);
        $data['photo_url']=!empty($data['photo_id'])?Media::profilePhotoUrl((int)$data['photo_id'],$id):'';
        if ($own) {
            $data['email']=wp_get_current_user()->user_email;
            $data['email_notifications']=Notifications::emailPreferences($id);
        }
        $data['terms']=self::labels($data);
        return $data;
    }

    private static function applyVisibility(array $data,int $id,bool $own): array
    {
        $moderate=current_user_can('ascla_moderate');
        $manage=current_user_can('ascla_manage');
        if (!$own && !$moderate) {
            foreach ((array)($data['hidden']??[]) as $field) { unset($data[$field]); }
            unset($data['learn'],$data['help'],$data['goals'],$data['connect_topics'],$data['microevents']);
        }
        if (!$own && self::privateName($id)) {
            unset($data['first_name'],$data['last_name'],$data['display_name']);
            $data['name']=self::publicName($id);
        }
        if (!$own && !$manage) {
            $phoneVisibility=$data['phone_visibility']??'private';
            unset($data['birth_date'],$data['phone_visibility']);
            if ($phoneVisibility!=='members' || !Access::member()) { unset($data['phone']); }
        }
        return $data;
    }
    public static function card(int $id): array
    {
        $card=['id'=>$id,'name'=>self::publicName($id),'photo_url'=>'','profile_url'=>Catalog::url('perfil',['member'=>$id])];
        try { $profile=self::visible($id); $card['photo_url']=$profile['photo_url']; }
        catch (\ASCLA\Core\Rest\ApiException $e) { $card['profile_url']=''; }
        return $card;
    }
    public static function labels(array $data): array
    {
        $labels=[];
        foreach (self::TERMS as $key=>$tax) {
            $labels[$key]=[];
            foreach ((array)($data[$key]??[]) as $id) { $term=get_term((int)$id,'ascla_'.$tax); if ($term && !is_wp_error($term)) { $labels[$key][]=$term->name; } }
        }
        return $labels;
    }
    public static function completion(int $id): array
    {
        $data=self::raw($id);
        $checks=[
            'name'=>trim((string)($data['first_name']??''))!=='' && trim((string)($data['last_name']??''))!=='',
            'position'=>trim((string)($data['position']??''))!=='',
            'company'=>trim((string)($data['company']??''))!=='',
            'location'=>trim((string)($data['country']??''))!=='' && trim((string)($data['city']??''))!=='',
            'bio'=>trim((string)($data['bio']??''))!=='',
            'experience'=>trim((string)($data['experience']??''))!=='',
            'photo'=>!empty($data['photo_id']),
            'interests'=>!empty($data['interests']),
            'areas'=>!empty($data['areas']),
            'industries'=>!empty($data['industries']),
        ];
        $completed=count(array_filter($checks));
        return ['percent'=>$completed*10,'completed'=>$completed,'total'=>10,'minimum'=>40,'checks'=>$checks];
    }

    public static function save(array $input,int $id=0): array
    {
        $id=$id?:get_current_user_id();
        return \ASCLA\Core\Repositories\Store::lock('profile-interests:'.$id,static fn()=>self::saveUnlocked($input,$id));
    }
    private static function saveUnlocked(array $input,int $id): array
    {
        $old=self::raw($id);
        $data=self::saveTextFields($old,$input);
        $data=self::savePhoneFields($data,$input,$id);
        if (array_key_exists('birth_date',$input)) { $data['birth_date']=Birthdays::normalize($input['birth_date']); }
        $data=self::saveTermFields($data,$input);
        $data=self::savePreferenceFields($data,$input,$id);
        $data['revision']=(int)($old['revision']??0)+1;
        unset($data['email'],$data['terms'],$data['photo_url']);
        update_user_meta($id,'_ascla_profile',$data);
        if (!empty($data['photo_id'])) { Media::commit((int)$data['photo_id'],$id); }
        wp_update_user(['ID'=>$id,'first_name'=>$data['first_name'],'last_name'=>$data['last_name'],'display_name'=>trim($data['first_name'].' '.$data['last_name'])?:$data['name']]);
        update_option('ascla_profile_revision',(int)get_option('ascla_profile_revision',0)+1,false);
        Birthdays::celebrate($id);
        return self::visible($id);
    }

    private static function saveTextFields(array $data,array $input): array
    {
        foreach (self::TEXT as $field) {
            if (!array_key_exists($field,$input)) { continue; }
            $value=Access::text($input[$field],in_array($field,['bio','experience'],true)?3000:200);
            if (in_array($field,['linkedin','twitter','website'],true) && $value!=='') {
                Access::require((bool)filter_var($value,FILTER_VALIDATE_URL) && in_array(wp_parse_url($value,PHP_URL_SCHEME),['https','http'],true),'URL no válida.',400);
                $value=esc_url_raw($value);
            }
            if ($field==='country' && $value!=='' && $value!==(string)($data['country']??'')) {
                $country=Locations::resolveCountry($value);
                Access::require((bool)$country,'Seleccione un país de la lista.',400);
                $value=$country['es'];
            }
            $data[$field]=$value;
        }
        return $data;
    }

    private static function savePhoneFields(array $data,array $input,int $id): array
    {
        if (!array_key_exists('phone',$input) && !array_key_exists('phone_visibility',$input)) { return $data; }
        Access::require(Access::member() && ($id===get_current_user_id() || (current_user_can('ascla_manage') && current_user_can('edit_user',$id))));
        if (array_key_exists('phone',$input)) { $data['phone']=\ASCLA\Core\Domain\Phone::normalize($input['phone']); }
        if (array_key_exists('phone_visibility',$input)) { $data['phone_visibility']=\ASCLA\Core\Domain\Phone::visibility($input['phone_visibility']); }
        return $data;
    }

    private static function saveTermFields(array $data,array $input): array
    {
        foreach (self::TERMS as $field=>$tax) {
            if (!array_key_exists($field,$input)) { continue; }
            Access::require(is_array($input[$field]) && count($input[$field])<=20,'Selección no válida.',400);
            $ids=array_values(array_unique(array_map('absint',$input[$field])));
            foreach ($ids as $tid) { Access::require((bool)term_exists($tid,'ascla_'.$tax),'Tema no válido.',400); }
            $data[$field]=$ids;
        }
        return $data;
    }

    private static function savePreferenceFields(array $data,array $input,int $id): array
    {
        foreach (['directory','networking','microevents'] as $flag) {
            if (isset($input[$flag])) { $data[$flag]=rest_sanitize_boolean($input[$flag]); }
        }
        if (isset($input['hidden'])) { $data['hidden']=array_values(array_intersect((array)$input['hidden'],array_merge(self::TEXT,array_keys(self::TERMS),['photo_id']))); }
        if (isset($input['photo_id'])) {
            $photo=absint($input['photo_id']);
            if ($photo) { Media::requireOwned($photo,$id,true); }
            $data['photo_id']=$photo;
        }
        if (isset($input['email_notifications'])) {
            Access::require(is_array($input['email_notifications']),'Preferencias de correo no válidas.',400);
            Notifications::saveEmailPreferences($id,$input['email_notifications']);
        }
        return $data;
    }

    public static function directory(array $filter=[]): array
    {
        $page=max(1,(int)($filter['page']??1));
        $query=mb_strtolower(Access::text($filter['q']??'',120));
        $results=[];$offset=0;
        do {
            $users=get_users(['number'=>200,'offset'=>$offset,'orderby'=>'display_name','order'=>'ASC','capability'=>'ascla_access']);
            $offset+=200;
            foreach ($users as $user) {
                $data=self::directoryCandidate($user,$filter,$query);
                if ($data!==null) { $results[]=$data; }
            }
        } while (count($users)===200);
        return ['items'=>Connections::attach(array_slice($results,($page-1)*18,18)),'total'=>count($results),'page'=>$page,'pages'=>max(1,(int)ceil(count($results)/18))];
    }

    private static function directoryCandidate(\WP_User $user,array $filter,string $query): ?array
    {
        if (!Access::member($user->ID)) { return null; }
        $raw=self::raw($user->ID);
        if (empty($raw['directory']) && $user->ID!==get_current_user_id()) { return null; }
        $data=self::visible($user->ID);
        $haystack=mb_strtolower(implode(' ',array_map(static fn($value)=>is_scalar($value)?(string)$value:'',$data)).' '.wp_json_encode($data['terms'],JSON_UNESCAPED_UNICODE));
        $matches=!($query && !str_contains($haystack,$query)) && (empty($filter['country']) || ($data['country']??'')===$filter['country']);
        foreach (['industries','interests','areas'] as $key) {
            if (!empty($filter[$key]) && !in_array((int)$filter[$key],$data[$key]??[],true)) { $matches=false;break; }
        }
        return $matches?$data:null;
    }
    public static function catalogs(): array
    {
        $out=[];
        foreach (Catalog::TAXONOMIES as $key=>$label) { $out[$key]=array_values(array_map(static fn($t)=>['id'=>$t->term_id,'name'=>$t->name],get_terms(['taxonomy'=>'ascla_'.$key,'hide_empty'=>$key==='tag'&&!current_user_can('ascla_moderate')]))); }
        return $out;
    }
}
