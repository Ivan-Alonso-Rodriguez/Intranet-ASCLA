<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Demo
{
    public static function seed(string $password): array
    {
        Access::require(Settings::get()['demo'],'Active modo demo antes de crear datos ficticios.',400);
        Access::require(strlen($password)>=12,'La contraseña demo necesita al menos 12 caracteres.',400);
        return Store::lock('demo-seed',static function () use($password) {
            $names=['Valentina Ríos','Mateo Salazar','Lucía Ferrer','Santiago Vidal','Camila Soler','Daniel Robles','Mariana Costa','Andrés Luna','Isabel Molina','Nicolás Vega','Elena Pardo','Gabriel Campos','Paula Méndez','Sebastián León','Renata Silva','Diego Mora','Clara Navarro','Tomás Duarte'];
            $companies=['Andina Horizonte','Nova Consejo','Grupo Brisa','Lumen Capital','Nexo Sur','Prisma Gestión','Altamar Energía','Cumbre Digital','Arco Consultores'];
            $countries=['Perú','Colombia','México','Chile','Argentina','Brasil']; $positions=['Secretaría corporativa','Dirección de gobernanza','Gerencia de cumplimiento'];
            $catalogs=Profiles::catalogs(); $users=[]; $original=get_current_user_id();
            foreach ($names as $i=>$name) {
                $login=$i===0?'demo.asociado':'demo.miembro.'.($i+1); $user=get_user_by('login',$login);
                if ($user && !get_user_meta($user->ID,'_ascla_demo',true)) { throw new \RuntimeException('Existe una cuenta ajena con un identificador demo. No se modificó.'); }
                if (!$user) {
                    $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','display_name'=>$name,'role'=>'ascla_member']);
                    if (is_wp_error($uid)) { throw new \RuntimeException('No se pudo crear un usuario demo.'); }
                    update_user_meta($uid,'_ascla_demo',true); $user=get_userdata($uid);
                    [$first,$last]=explode(' ',$name,2); wp_set_current_user($uid);
                    $fields=['first_name'=>$first,'last_name'=>$last,'position'=>$positions[$i%3],'company'=>$companies[$i%9].' (ficticia)','country'=>$countries[$i%6],'city'=>['Lima','Bogotá','Ciudad de México','Santiago','Buenos Aires','São Paulo'][$i%6],'member_type'=>'Asociado demo','bio'=>'Perfil ficticio para explorar la comunidad. Me interesa compartir experiencias y aprender sobre gobierno corporativo.','experience'=>'Experiencia ficticia en coordinación de juntas, seguimiento de acuerdos y gestión de riesgos.','directory'=>true,'networking'=>true,'microevents'=>true];
                    foreach (Profiles::TERMS as $key=>$tax) { $list=$catalogs[$tax]; $fields[$key]=[(int)$list[$i%count($list)]['id'],(int)$list[($i+1)%count($list)]['id']]; }
                    Profiles::save($fields,$uid);
                }
                $users[]=$user->ID;
            }
            wp_set_current_user($original);
            // Seed has its own marker; reruns preserve any edits and do not reset credentials.
            $make=static function ($key,$type,$title,$body,$meta=[],$author=0) use($users) {
                $existing=get_posts(['post_type'=>'ascla_'.$type,'post_status'=>'any','meta_key'=>'_ascla_demo_key','meta_value'=>$key,'numberposts'=>1]);
                if ($existing) { return $existing[0]->ID; }
                $id=wp_insert_post(wp_slash(['post_type'=>'ascla_'.$type,'post_title'=>$title,'post_content'=>$body,'post_status'=>'draft','post_author'=>$author?:$users[0],'comment_status'=>'open']));
                update_post_meta($id,'_ascla_demo_key',$key); update_post_meta($id,'_ascla',array_merge(['demo'=>true,'chatham'=>true],$meta)); wp_update_post(['ID'=>$id,'post_status'=>'publish']); return $id;
            };
            $topics=['Gobierno de inteligencia artificial','El rol de la secretaría corporativa','Juntas directivas que aprenden','Sostenibilidad en la agenda del directorio','Gestión de riesgos y nuevas tecnologías','Una comunidad que comparte conocimiento'];
            foreach ($topics as $i=>$title) {
                $hub=$make('hub-'.$i,'hub',$title,'Material ficticio de demostración. ¿Qué prácticas les han ayudado a mejorar la conversación en la junta? Compartamos experiencias sobre supervisión, responsabilidades y seguimiento de acuerdos.',['category'=>'Conversaciones'],$users[$i]);
                if (!get_comments(['post_id'=>$hub,'number'=>1])) { wp_insert_comment(['comment_post_ID'=>$hub,'comment_author'=>'Comunidad demo','comment_content'=>'Un buen punto de partida es documentar las preguntas clave y revisar los acuerdos en cada sesión.','user_id'=>$users[($i+1)%18],'comment_approved'=>1]); }
                $resource=$make('resource-'.$i,'resource',$title,'Material ficticio de demostración. La gobernanza corporativa requiere responsabilidades claras y seguimiento de los acuerdos. La junta directiva puede supervisar los riesgos de inteligencia artificial mediante un inventario de sistemas, responsables definidos y revisiones periódicas. La secretaría corporativa facilita la trazabilidad de decisiones y el aprendizaje entre pares.',['resource_type'=>$i===0?'Video':($i%2?'Nota técnica':'Artículo'),'source'=>'Biblioteca de demostración','copyright'=>Settings::get()['copyright'],'summary'=>'Una guía de conversación sobre responsabilidades, riesgos y supervisión de la junta.','transcript'=>(new \ASCLA\Core\Integrations\MockVideoProvider())->transcript('')['text']]);
                wp_set_object_terms($resource,[(int)$catalogs['interest'][$i%count($catalogs['interest'])]['id']],'ascla_interest');
            }
            for ($i=0;$i<4;$i++) {
                $start=(new \DateTimeImmutable(($i===3?'-14':'+'.(5+$i*7)).' days 17:00',wp_timezone()))->setTimezone(new \DateTimeZone('UTC'));
                $make('event-'.$i,'event',['Gobierno de IA: preguntas para el directorio','Círculo de secretarios corporativos','Gobernanza y sostenibilidad','Encuentro regional de aprendizaje'][$i],'Evento ficticio para demostrar inscripciones y calendario. Comparte experiencias con colegas de la región.',['start'=>$start->format('c'),'end'=>$start->modify('+60 minutes')->format('c'),'capacity'=>30,'modality'=>'Virtual','location'=>'Enlace por confirmar','agenda'=>'Bienvenida · Caso de discusión · Preguntas · Cierre']);
            }
            $forum=$make('forum-governance','forum','Gobierno corporativo','Un espacio para preguntas y experiencias de nuestra profesión.');
            foreach (array_slice($topics,0,3) as $i=>$topic) { $id=$make('topic-'.$i,'topic',$topic,'¿Cómo abordan este tema en sus organizaciones? Comparte una práctica o una pregunta para la comunidad.'); wp_update_post(['ID'=>$id,'post_parent'=>$forum]); }
            $make('gallery-1','gallery','Encuentros que nos conectan','Galería de demostración. Añade fotografías autorizadas desde Nueva galería.');
            foreach (['Instituto Horizonte','Red de Gobernanza Abierta','Centro Andino de Estudios'] as $i=>$name) { $make('ally-'.$i,'ally',$name.' · Demo','Organización ficticia para demostrar las alianzas profesionales.',['alliance_type'=>$i===1?'Convenio':'Socio estratégico','benefits'=>'Intercambio de conocimiento y participación en encuentros.','initiatives'=>'Conversaciones y recursos para la comunidad.']); }
            if (!get_option('ascla_demo_messages')) {
                wp_set_current_user($users[1]); $c=Messaging::start($users[0]); Messaging::send((int)$c['id'],'Hola, bienvenida a la comunidad de demostración. ¿Conversamos sobre gobierno de IA?');
                wp_set_current_user($users[0]); Notifications::send($users[0],'welcome','Tu comunidad ASCLA está lista para explorar.'); update_option('ascla_demo_messages',true,false);
            }
            wp_set_current_user($original); Audit::record('demo_seeded'); return ['users'=>18,'companies'=>9,'login'=>'demo.asociado','message'=>'Datos ficticios preparados. Una segunda ejecución conserva cambios y contraseñas existentes.'];
        });
    }
}
