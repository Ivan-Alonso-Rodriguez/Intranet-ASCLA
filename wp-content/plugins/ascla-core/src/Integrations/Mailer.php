<?php
namespace ASCLA\Core\Integrations;
use ASCLA\Core\Services\{Access,Settings};

/** Use WordPress' native password-reset tokens and mail pipeline. */
final class Mailer
{
    private const HTML_CONTENT_TYPE='Content-Type: text/html; charset=UTF-8';
    public static function boot(): void
    {
        add_filter('wp_mail_from',[self::class,'from']);
        add_filter('wp_mail_from_name',[self::class,'fromName']);
        add_action('phpmailer_init',[self::class,'configure']);
        add_action('wp_mail_failed',static function () { self::record('failed'); });
        add_action('wp_mail_succeeded',static function () { self::record('accepted'); });
        add_filter('login_message',[self::class,'localNotice']);
    }
    public static function local(): bool
    {
        return wp_get_environment_type()==='local' && defined('ASCLA_LOCAL_MAIL_CAPTURE') && ASCLA_LOCAL_MAIL_CAPTURE===true;
    }
    public static function from(string $original): string
    {
        if (self::local()) { return 'ascla@example.test'; }
        $s=Settings::get(); return $s['mail_mode']==='smtp'?$s['smtp_from']:$original;
    }
    public static function fromName(string $original): string
    {
        if (self::local()) { return 'ASCLA · entorno local'; }
        $s=Settings::get(); return $s['mail_mode']==='smtp'?$s['smtp_name']:$original;
    }
    public static function configure(\PHPMailer\PHPMailer\PHPMailer $mailer): void
    {
        $s=Settings::get();
        if (!self::local() && $s['mail_mode']!=='smtp') { return; }
        $mailer->isSMTP(); $mailer->SMTPDebug=0; $mailer->Timeout=12; $mailer->SMTPKeepAlive=false;
        if (self::local()) {
            // Explicit local-only capture: no credentials or delivery to the Internet.
            $mailer->Host='mailpit'; $mailer->Port=1025; $mailer->SMTPAuth=false;
            $mailer->SMTPSecure=''; $mailer->SMTPAutoTLS=false;
            $mailer->Username=''; $mailer->Password='';
            $mailer->setFrom('ascla@example.test','ASCLA · entorno local',false);
            return;
        }
        $password=Secrets::get('smtp_password');
        if (!$s['smtp_host'] || !$s['smtp_user'] || !$password || !is_email($s['smtp_from'])) {
            throw new \PHPMailer\PHPMailer\Exception('Configure el servicio SMTP en Administración ASCLA.');
        }
        $mailer->Host=$s['smtp_host']; $mailer->Port=(int)$s['smtp_port'];
        $mailer->SMTPAuth=true; $mailer->Username=$s['smtp_user']; $mailer->Password=$password;
        $mailer->SMTPSecure=$s['smtp_security']; $mailer->SMTPAutoTLS=true;
        $mailer->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $mailer->setFrom($s['smtp_from'],$s['smtp_name'],false);
    }
    public static function validate(array $input,array $data): array
    {
        foreach (['mail_mode'=>['wordpress','smtp'],'smtp_security'=>['tls','ssl']] as $key=>$allowed) {
            if (isset($input[$key])) { Access::require(in_array($input[$key],$allowed,true),'Configuración de correo no válida.',400); $data[$key]=$input[$key]; }
        }
        foreach (['smtp_host','smtp_user','smtp_from','smtp_name'] as $key) {
            if (isset($input[$key])) {
                $value=trim(Access::text($input[$key],254));
                Access::require(!preg_match('/[\r\n]/',$value),'El correo no admite saltos de línea.',400);
                $data[$key]=$value;
            }
        }
        Access::require($data['smtp_host']==='' || (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',$data['smtp_host']),'Introduzca únicamente el nombre del servidor SMTP.',400);
        Access::require($data['smtp_from']==='' || (bool)is_email($data['smtp_from']),'El correo remitente no es válido.',400);
        if (isset($input['smtp_port'])) { Access::require(in_array(filter_var($input['smtp_port'],FILTER_VALIDATE_INT),[465,587,2525],true),'Use el puerto SMTP 465, 587 o 2525.',400); $data['smtp_port']=(int)$input['smtp_port']; }
        if (isset($input['smtp_password'])) { Access::require(is_string($input['smtp_password']) && strlen($input['smtp_password'])<=2000 && !preg_match('/[\r\n\x00]/',$input['smtp_password']),'Contraseña SMTP no válida.',400); }
        $password=!empty($input['smtp_password'])?$input['smtp_password']:Secrets::get('smtp_password');
        if (!empty($input['clear_smtp_password'])) { $password=''; }
        if ($data['mail_mode']==='smtp') {
            Access::require($data['smtp_host']!=='' && $data['smtp_user']!=='' && is_email($data['smtp_from']) && $password!=='','Complete servidor, usuario, contraseña y remitente antes de activar SMTP.',400);
            Access::require(($data['smtp_port']===465)===($data['smtp_security']==='ssl'),'El puerto 465 usa SSL/TLS; 587 y 2525 usan STARTTLS.',400);
        }
        return $data;
    }
    private static function record(string $status): void
    {
        // Never persist recipients, message contents, reset links or SMTP error strings.
        update_option('ascla_mail_result',['status'=>$status,'at'=>gmdate('c'),'transport'=>self::local()?'local':Settings::get()['mail_mode']],false);
    }
    public static function status(): array
    {
        return ['mail_local'=>self::local(),'has_smtp_password'=>Secrets::get('smtp_password')!=='','mail_last_result'=>get_option('ascla_mail_result',null)];
    }
    public static function test(): array
    {
        Access::require(current_user_can('ascla_manage')); Access::limit('mail_test',3,300);
        $email=wp_get_current_user()->user_email;
        Access::require((bool)is_email($email),'Su cuenta necesita un correo válido para recibir la prueba.',400);
        $subject='ASCLA · prueba de correo';
        $name=trim((string)wp_get_current_user()->display_name) ?: 'administrador';
        $body=EmailTemplate::render(
            $subject,
            $name,
            'El transporte de correo de ASCLA ha aceptado este mensaje de prueba. Si puedes verlo correctamente, la plantilla institucional HTML y el envío están funcionando.',
            'Ir a ASCLA',
            home_url('/'),
            'Este es un mensaje de prueba generado desde Administración ASCLA.'
        );
        $ok=wp_mail($email,$subject,$body,[self::HTML_CONTENT_TYPE]);
        Access::require($ok,'No se pudo enviar. Revise los datos SMTP, el remitente autorizado y la conexión del servidor.',502);
        return ['message'=>self::local()?'Correo recibido por el buzón local de pruebas.':'El servidor aceptó el correo. Revise su bandeja de entrada y spam.'];
    }
    public static function notification(int $user,string $category,string $message,string $url=''): bool
    {
        $account=get_userdata($user);
        if (!$account || !is_email($account->user_email)) { return false; }
        $subjects=[
            'connections'=>'ASCLA · Novedad en tus conexiones',
            'messages'=>'ASCLA · Novedad en mensajería',
            'events'=>'ASCLA · Novedad de eventos',
            'support'=>'ASCLA · Atención de tu solicitud',
        ];
        if (!isset($subjects[$category])) { return false; }
        $name=trim((string)$account->display_name) ?: 'asociado';
        $message=trim(wp_strip_all_tags($message));
        $safeUrl=$url!==''?esc_url_raw($url):'';
        $profileUrl=\ASCLA\Core\Domain\Catalog::url('perfil');
        $body=EmailTemplate::render($subjects[$category],$name,$message,'Ver en ASCLA',$safeUrl,'Puedes gestionar estos correos desde Mi perfil > Notificaciones por correo: '.$profileUrl);
        try {
            return (bool)wp_mail($account->user_email,$subjects[$category],$body,[self::HTML_CONTENT_TYPE]);
        } catch (\Throwable $error) {
            self::record('failed');
            return false;
        }
    }

    public static function birthday(int $user): bool
    {
        $account=get_userdata($user);
        if (!$account || !is_email($account->user_email) || !Access::member($user)) { return false; }
        $profile=(array)get_user_meta($user,'_ascla_profile',true);
        $name=trim((string)($profile['first_name']??''));
        if ($name==='') { $name=trim((string)$account->display_name)?:'asociado'; }
        $subject='ASCLA · ¡Feliz cumpleaños! 🎉';
        $body=EmailTemplate::render($subject,$name,"¡Feliz cumpleaños! 🎉\n\nEn ASCLA queremos acompañarte en este día y desearte un nuevo año lleno de buenas conexiones, aprendizajes, proyectos y motivos para celebrar.\n\nGracias por ser parte de nuestra comunidad.",'Visitar mi comunidad',\ASCLA\Core\Domain\Catalog::url('intranet'));
        try { return (bool)wp_mail($account->user_email,$subject,$body,[self::HTML_CONTENT_TYPE]); }
        catch (\Throwable $error) { self::record('failed'); return false; }
    }

    public static function localNotice(string $message): string
    {
        if (self::local() && (($_REQUEST['action']??'')==='lostpassword' || isset($_GET['checkemail']))) {
            $message.='<p class="message">Entorno local: el enlace de recuperación llegará al <a href="http://localhost:8025/" target="_blank" rel="noopener">buzón de pruebas</a>. No se enviará a una bandeja de correo externa.</p>';
        }
        return $message;
    }
}
