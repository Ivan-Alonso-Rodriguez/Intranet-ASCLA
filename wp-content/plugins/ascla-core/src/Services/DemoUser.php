<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Explicitly requested test identity plus two clearly fictional matching peers. */
final class DemoUser
{
    public const LOGIN='ivan.alonso2602';
    public const EMAIL='ivan.alonso2602@gmail.com';
    public static function ivan(string $fallbackPassword='',bool $reset=false): array
    {
        Access::require(Settings::get()['demo'],'Active el modo demo.',400);
        $configured=getenv('ASCLA_IVAN_DEMO_PASSWORD')?:'';
        Access::require(!$reset||$configured!=='','Para resetear configure ASCLA_IVAN_DEMO_PASSWORD.',400);
        $password=$configured?:($fallbackPassword?:wp_generate_password(32,true,true));
        Access::require(strlen($password)>=12,'La contraseña demo necesita al menos 12 caracteres.',400);
        return Store::lock('demo-ivan',static function()use($password,$fallbackPassword,$reset){
            $fields=self::fields();
            $ivan=self::upsert(self::LOGIN,self::EMAIL,'Ivan','Alonso',$fields,$password);
            if($reset){wp_set_password($password,$ivan['id']);}
            $peers=self::peers($fields,$fallbackPassword);
            Notifications::once($ivan['id'],'ivan-demo-welcome','welcome','Tu perfil de prueba ASCLA está preparado.');
            Audit::record($reset?'demo_password_reset':'demo_user_prepared',$ivan['id']);
            return ['id'=>$ivan['id'],'login'=>get_userdata($ivan['id'])->user_login,'email'=>self::EMAIL,'created'=>$ivan['created'],'password_reset'=>$reset,'peer_ids'=>$peers];
        });
    }
    private static function peers(array $fields,string $password): array
    {
        $peers=[];
        foreach([['Alicia','Núñez'],['Bruno','Vega']] as $index=>$name){
            $peerFields=$fields;$peerFields['company']='ASCLA Demo · organización ficticia';
            $peerFields['bio']='Persona ficticia para demostrar afinidad, conversación y colaboración.';
            if($index===1){$peerFields['interests']=array_slice($fields['interests'],0,3);}
            $login='demo.ivan.peer.'.($index+1);
            $peer=self::upsert($login,$login.'@example.invalid',$name[0],$name[1],$peerFields,$password?:wp_generate_password(32,true,true));
            $peers[]=$peer['id'];
        }
        return $peers;
    }
    private static function fields(): array
    {
        return ['position'=>'Profesional / Miembro ASCLA Demo','company'=>'ASCLA Demo','country'=>'Perú','city'=>'Lima','member_type'=>'Asociado demo','bio'=>'Perfil de prueba solicitado por Ivan. Cargo, empresa e intereses de demostración; no describen una trayectoria profesional real.','directory'=>true,'networking'=>true,'microevents'=>true,
            'interests'=>self::terms('interest',['Inteligencia artificial','Gobierno corporativo','Transformación digital','Gestión de riesgos']),
            'areas'=>self::terms('area',['Gobierno de IA','Estrategia','Tecnología']),
            'goals'=>self::terms('goal',['Conectar con profesionales afines','Aprender sobre gobierno de IA','Participar en microeventos'])];
    }
    private static function terms(string $taxonomy,array $names): array
    {
        $ids=[];
        foreach($names as $name){
            $term=term_exists($name,'ascla_'.$taxonomy);
            if(!$term){$term=wp_insert_term($name,'ascla_'.$taxonomy);}
            Access::require(!is_wp_error($term),'No se pudo preparar un tema demo.',500);
            $ids[]=(int)(is_array($term)?$term['term_id']:$term);
        }
        return $ids;
    }
    private static function upsert(string $login,string $email,string $first,string $last,array $fields,string $password): array
    {
        $byLogin=get_user_by('login',$login);$byEmail=get_user_by('email',$email);
        Access::require(!$byLogin||$byLogin->user_email===$email,'El identificador demo pertenece a otra cuenta. No se modificó.',409);
        Access::require(!$byEmail||!$byLogin||$byEmail->ID===$byLogin->ID,'Existe un conflicto entre el email y el usuario demo.',409);
        $user=$byEmail?:$byLogin;$created=!$user;
        if(!$user){
            $id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>$password,'display_name'=>$first.' '.$last,'role'=>'ascla_member']);
            Access::require(!is_wp_error($id),'No se pudo crear el usuario demo.',500);$user=get_userdata($id);
        }
        Access::require(in_array('ascla_member',$user->roles,true),'La cuenta existente tiene otro rol. No se modificó.',409);
        $fields=['first_name'=>$first,'last_name'=>$last]+$fields;
        $stored=(array)get_user_meta($user->ID,'_ascla_profile',true);$previous=(array)get_user_meta($user->ID,'_ascla_demo_defaults',true);$changes=[];
        foreach($fields as $key=>$value){
            $untouched=array_key_exists($key,$previous)&&($stored[$key]??null)===$previous[$key];
            if(($created||(!array_key_exists($key,$stored)&&empty($user->$key))||$untouched)&&($stored[$key]??null)!==$value){$changes[$key]=$value;}
        }
        $actor=get_current_user_id();
        try{wp_set_current_user($user->ID);if($changes){Profiles::save($changes,$user->ID);}}
        finally{wp_set_current_user($actor);}
        update_user_meta($user->ID,'_ascla_demo',true);update_user_meta($user->ID,'_ascla_demo_defaults',$fields);
        return ['id'=>(int)$user->ID,'created'=>$created];
    }
}
