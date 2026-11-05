<?php
namespace ASCLA\Core\Services;

/** Resolve an import row and detect concurrent changes to its target profile. */
final class InterestImportAccounts
{
    private AttendanceIdentity $identity;
    private array $codes=[];
    public function __construct() { $this->identity=new AttendanceIdentity(); }
    public static function resolve(array $p): array
    {
        return (new self())->match($p);
    }
    /** Email/code are authoritative. Names only fill missing identifiers, never override a conflict. */
    public function match(array $p): array
    {
        $email=trim($p['email']??'');$code=trim($p['code']??'');$byEmail=$email!=='' && is_email($email)?get_user_by('email',$email):false;$byCode=[];
        if($code!=='') {$byCode=$this->codes[$code]??=array_values(array_unique(array_map('intval',get_users(['fields'=>'ID','meta_query'=>['relation'=>'OR',['key'=>'_ascla_member_code','value'=>$code],['key'=>'ascla_member_code','value'=>$code]]])))); }
        if(count($byCode)>1 || ($byCode && $byEmail && $byCode[0]!== (int)$byEmail->ID)) {return [0,'Código y correo ambiguos; revisión manual.']; }
        $id=0;
        if($byCode){$id=(int)$byCode[0];}
        elseif($byEmail){$id=(int)$byEmail->ID;}
        $method=$byCode?'code':'email';$warning=($code!=='' && !$byCode)?'Código no encontrado; coincidencia por correo.':'';
        if(!$id){
            $name=implode(' ',array_filter([$p['person_name']??'',$p['name_extra']??'',$p['last_name']??'',$p['last_extra']??'']));
            $result=$this->identity->resolve(['email'=>$email,'name'=>$name,'error'=>'']);
            $id=$result['user']?(int)$result['user']->ID:0;$method=$result['method']??'';
            $warning=$id?'Coincidencia por nombre: verifica la identidad antes de aceptar.':($result['reason']??'Usuario no encontrado o sin permiso de edición.');
        }
        if(!$id || !user_can($id,'ascla_access') || !Access::canManageAccount('edit_user',$id)) {return [0,$warning?:'Usuario no encontrado o sin permiso de edición.','']; }
        return [$id,$warning,$method];
    }
    public static function profile(int $id): array { return (array)get_user_meta($id,'_ascla_profile',true); }
    public static function fingerprint(int $id): string { return hash('sha256',wp_json_encode(array_values((array)(self::profile($id)['interests']??[])))); }
}
