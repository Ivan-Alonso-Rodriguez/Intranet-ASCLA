<?php
// No network call and no persistent configuration change: exercise the nonlocal SMTP branch.
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || ASCLA\Core\Integrations\Mailer::local()) exit(1);
require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';
require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';
use ASCLA\Core\Integrations\Mailer;
$checks=0;
$check=static function ($condition) use (&$checks) { if (!$condition) throw new RuntimeException('SMTP configuration assertion failed'); $checks++; };
define('ASCLA_SMTP_PASSWORD','synthetic-password-for-configuration-test');
$settings=['mail_mode'=>'smtp','smtp_host'=>'smtp.example.com','smtp_port'=>587,'smtp_security'=>'tls','smtp_user'=>'sender@example.com','smtp_from'=>'sender@example.com','smtp_name'=>'ASCLA'];
$filter=static function () use (&$settings) { return $settings; };
add_filter('pre_option_ascla_settings',$filter);
$mailer=new \PHPMailer\PHPMailer\PHPMailer(true); Mailer::configure($mailer);
$check($mailer->Mailer==='smtp' && $mailer->Host==='smtp.example.com' && $mailer->Port===587);
$check($mailer->SMTPAuth && $mailer->SMTPSecure==='tls' && $mailer->SMTPAutoTLS);
$check($mailer->Username==='sender@example.com' && $mailer->Password===ASCLA_SMTP_PASSWORD);
$check($mailer->SMTPOptions['ssl']===['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]);
$check($mailer->SMTPDebug===0 && $mailer->Timeout===12);
$check(Mailer::from('wordpress@localhost')==='sender@example.com' && Mailer::fromName('WordPress')==='ASCLA');
$settings['smtp_port']=465; $settings['smtp_security']='ssl'; Mailer::configure($mailer); $check($mailer->Port===465 && $mailer->SMTPSecure==='ssl');
$settings['smtp_from']='';
try { Mailer::configure($mailer); throw new RuntimeException('Invalid sender accepted'); } catch (\PHPMailer\PHPMailer\Exception $e) { $checks++; }
$settings['mail_mode']='wordpress'; $native=new \PHPMailer\PHPMailer\PHPMailer(true); Mailer::configure($native);
$check($native->Mailer==='mail' && Mailer::from('original@example.com')==='original@example.com');
$check(Mailer::localNotice('Original')==='Original');
remove_filter('pre_option_ascla_settings',$filter);
echo wp_json_encode(['status'=>'passed','checks'=>$checks,'network_calls'=>0,'scope'=>'SMTP configuration without external credentials']);
