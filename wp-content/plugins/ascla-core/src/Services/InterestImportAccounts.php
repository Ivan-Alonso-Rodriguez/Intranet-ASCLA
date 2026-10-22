<?php
namespace ASCLA\Core\Services;

/** Resolve an import row and detect concurrent changes to its target profile. */
final class InterestImportAccounts
{
    public static function resolve(array $p): array
    {
        $email=trim($p['email']);$code=trim($p['code']);$byEmail=$email!=='' && is_email($email)?get_user_by('email',$email):false;$byCode=[];
        if($code!=='') {$byCode=array_unique(array_map('intval',get_users(['fields'=>'ID','meta_query'=>['relation'=>'OR',['key'=>'_ascla_member_code','value'=>$code],['key'=>'ascla_member_code','value'=>$code]]]))); }
        if(count($byCode)>1 || ($byCode && $byEmail && $byCode[0]!== (int)$byEmail->ID)) {return [0,'Código y correo ambiguos; revisión manual.']; }
        $id=0;
        if($byCode){$id=(int)$byCode[0];}
        elseif($byEmail){$id=(int)$byEmail->ID;}
        if(!$id || !user_can($id,'ascla_access') || !Access::canManageAccount('edit_user',$id)) {return [0,'Usuario no encontrado o sin permiso de edición.']; }
        return [$id,($code!=='' && !$byCode)?'Código no encontrado; coincidencia por correo.':''];
    }
    public static function profile(int $id): array { return (array)get_user_meta($id,'_ascla_profile',true); }
    public static function fingerprint(int $id): string { return hash('sha256',wp_json_encode(array_values((array)(self::profile($id)['interests']??[])))); }
}
