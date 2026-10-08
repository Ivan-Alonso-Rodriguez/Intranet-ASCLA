<?php
namespace ASCLA\Core\Services;

/** Reject invalid native REST edits before the users controller performs any writes. */
final class NativeAccountPolicy
{
    public static function validate(mixed $response,array $handler,\WP_REST_Request $request): mixed
    {
        unset($handler);
        if ($response!==null || !in_array($request->get_method(),['POST','PUT','PATCH'],true)
            || !preg_match('#^/wp/v2/users(?:/(\d+|me))?/?$#',$request->get_route(),$match)) { return $response; }
        $id=($match[1]??'')==='me'?get_current_user_id():(int)($match[1]??0);
        $input=$request->get_params();
        foreach (['name'=>'display_name','url'=>'user_url','username'=>'user_login','email'=>'user_email'] as $source=>$target) {
            if (array_key_exists($source,$input)) { $input[$target]=$input[$source]; }
        }
        if (ProfessionalChanges::nativeChange($id,$input)) {
            return new \WP_Error('ascla_profile_request','Usa una solicitud de Contacto para modificar datos del asociado.',['status'=>403]);
        }
        $request['id']=$id;
        $checked=CredentialPolicy::restValidation((object)$input,$request);
        return is_wp_error($checked)?$checked:$response;
    }
}
