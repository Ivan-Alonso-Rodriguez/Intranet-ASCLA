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
        unset($data['first_name'],$data['last_name'],$data['bio'],$data['experience'],$data['company'],$data['linkedin'],$data['twitter'],$data['website']);
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
        return array_merge(['id'=>$id,'name'=>$user->display_name,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'directory'=>true,'networking'=>false,'microevents'=>false,'hidden'=>[],'revision'=>0],$data,['id'=>$id,'name'=>$user->display_name]);
    }
    public static function visible(int $id): array
    {
        $data=self::raw($id); $own=$id===get_current_user_id();
        Access::require($own||current_user_can('ascla_moderate')||!empty($data['directory']),'Perfil no disponible.',404);
        if (!$own && !current_user_can('ascla_moderate')) {
            foreach ((array)($data['hidden']??[]) as $field) { unset($data[$field]); }
            unset($data['learn'],$data['help'],$data['goals'],$data['connect_topics'],$data['microevents']);
        }
        if (!$own && self::privateName($id)) {
            unset($data['first_name'],$data['last_name'],$data['display_name']);
            $data['name']=self::publicName($id);
        }
        unset($data['revision']);
        $data['photo_url']=!empty($data['photo_id'])?Media::profilePhotoUrl((int)$data['photo_id'],$id):'';
        if ($own) { $data['email']=wp_get_current_user()->user_email; }
        $data['terms']=self::labels($data);
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
    public static function save(array $input,int $id=0): array
    {
        $id=$id?:get_current_user_id(); $old=self::raw($id); $data=$old;
        foreach (self::TEXT as $field) {
            if (!array_key_exists($field,$input)) { continue; }
            $value=Access::text($input[$field],in_array($field,['bio','experience'],true)?3000:200);
            if (in_array($field,['linkedin','twitter','website'],true) && $value!=='') {
                Access::require((bool)filter_var($value,FILTER_VALIDATE_URL) && in_array(wp_parse_url($value,PHP_URL_SCHEME),['https','http'],true),'URL no válida.',400);
                $value=esc_url_raw($value);
            }
            $data[$field]=$value;
        }
        foreach (self::TERMS as $field=>$tax) {
            if (!array_key_exists($field,$input)) { continue; }
            Access::require(is_array($input[$field]) && count($input[$field])<=20,'Selección no válida.',400);
            $ids=array_values(array_unique(array_map('absint',$input[$field])));
            foreach ($ids as $tid) { Access::require((bool)term_exists($tid,'ascla_'.$tax),'Tema no válido.',400); }
            $data[$field]=$ids;
        }
        foreach (['directory','networking','microevents'] as $flag) { if (isset($input[$flag])) { $data[$flag]=rest_sanitize_boolean($input[$flag]); } }
        if (isset($input['hidden'])) { $data['hidden']=array_values(array_intersect((array)$input['hidden'],array_merge(self::TEXT,array_keys(self::TERMS),['photo_id']))); }
        if (isset($input['photo_id'])) {
            $photo=absint($input['photo_id']);
            if ($photo) { Media::requireOwned($photo,$id,true); }
            $data['photo_id']=$photo;
        }
        $data['revision']=(int)($old['revision']??0)+1;
        unset($data['email'],$data['terms'],$data['photo_url']);
        update_user_meta($id,'_ascla_profile',$data);
        wp_update_user(['ID'=>$id,'first_name'=>$data['first_name'],'last_name'=>$data['last_name'],'display_name'=>trim($data['first_name'].' '.$data['last_name'])?:$data['name']]);
        update_option('ascla_profile_revision',(int)get_option('ascla_profile_revision',0)+1,false);
        return self::visible($id);
    }
    public static function directory(array $filter=[]): array
    {
        $page=max(1,(int)($filter['page']??1)); $q=mb_strtolower(Access::text($filter['q']??'',120));
        $results=[]; $offset=0;
        // Batch scan supports custom member roles via capabilities, avoids relying on role names.
        do {
            $users=get_users(['number'=>200,'offset'=>$offset,'orderby'=>'display_name','order'=>'ASC','capability'=>'ascla_access']); $offset+=200;
            foreach ($users as $user) {
                if (!Access::member($user->ID)) { continue; }
                $raw=self::raw($user->ID);
                if (empty($raw['directory']) && $user->ID!==get_current_user_id()) { continue; }
                $data=self::visible($user->ID);
                $haystack=mb_strtolower(implode(' ',array_map(static fn($v)=>is_scalar($v)?(string)$v:'', $data)).' '.wp_json_encode($data['terms'],JSON_UNESCAPED_UNICODE));
                if ($q && !str_contains($haystack,$q)) { continue; }
                if (!empty($filter['country']) && ($data['country']??'')!==$filter['country']) { continue; }
                $match=true;
                foreach (['industries','interests','areas'] as $key) { if (!empty($filter[$key])&&!in_array((int)$filter[$key],$data[$key]??[],true)) { $match=false; } }
                if ($match) { $results[]=$data; }
            }
        } while (count($users)===200);
        return ['items'=>Connections::attach(array_slice($results,($page-1)*18,18)),'total'=>count($results),'page'=>$page,'pages'=>max(1,(int)ceil(count($results)/18))];
    }
    public static function catalogs(): array
    {
        $out=[];
        foreach (Catalog::TAXONOMIES as $key=>$label) { $out[$key]=array_values(array_map(static fn($t)=>['id'=>$t->term_id,'name'=>$t->name],get_terms(['taxonomy'=>'ascla_'.$key,'hide_empty'=>$key==='tag'&&!current_user_can('ascla_moderate')]))); }
        return $out;
    }
}
