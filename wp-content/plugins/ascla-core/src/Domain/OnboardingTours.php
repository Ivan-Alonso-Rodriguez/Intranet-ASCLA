<?php
namespace ASCLA\Core\Domain;

use ASCLA\Core\Frontend\Language;

/** Add modules through ascla_onboarding_tours. Step IDs stay stable as copy changes. */
final class OnboardingTours
{
    private const ADMIN_FORM='#admin-panel form';
    private static function step(string $id,string|array $target,string $es,string $en,string $bodyEs,string $bodyEn,string $cap='ascla_access'): array
    {
        return ['id'=>$id,'target'=>$target,'title'=>Language::text($es,$en),'body'=>Language::text($bodyEs,$bodyEn),'cap'=>$cap];
    }

    private static function tour(string $page,array $steps,string $cap='ascla_access',string $section=''): array
    {
        $label=Catalog::PAGES[$page]??'Administración';
        return ['page'=>$page,'section'=>$section,'label'=>Language::label($label),'cap'=>$cap,'steps'=>$steps];
    }

    public static function all(): array
    {
        $step=[self::class,'step'];
        $tours=[
            'intranet'=>self::tour('intranet',[
                $step('welcome','.hero-copy','Tu comunidad empieza aquí','Your community starts here','Desde Inicio puedes consultar tu agenda y descubrir personas y materiales para ti.','Home brings together your agenda, people and suggested resources.'),
                $step('activity','.stat-grid','La actividad, a un vistazo','Activity at a glance','Estos accesos te llevan al Directorio, los próximos encuentros, el conocimiento y tus notificaciones.','Use these shortcuts to open the directory, upcoming events, resources and notifications.'),
                $step('agenda','.calendar-card','Tu agenda ASCLA','Your ASCLA agenda','Los días marcados tienen eventos. Abre un encuentro para conocer sus detalles e inscribirte.','Marked days have events. Open an event to view its details and register.'),
            ]),
            'perfil'=>self::tour('perfil',[
                $step('identity','#profile-tab-info','Presenta tu experiencia','Introduce yourself','Completa tus datos profesionales y tu fotografía para que otros asociados te conozcan.','Add your professional details and photo so other members can get to know you.'),
                $step('interests','#profile-tab-interests','Elige tus intereses','Choose your interests','Indica qué deseas aprender, en qué puedes ayudar y qué conexiones te interesan.','Tell the community what you want to learn, how you can help and the connections you are looking for.'),
                $step('privacy','#profile-tab-privacy','Decide qué compartir','Choose what to share','Aquí controlas la visibilidad de tus datos y tu participación en la comunidad.','Manage the visibility of your details and your participation in the community here.'),
                $step('security','#profile-tab-account','Cuida tu acceso','Manage your account','En Cuenta y seguridad encontrarás la contraseña y las integraciones disponibles.','Find password settings and available integrations in Account and security.'),
                $step('save','.profile-tab-savebar','Guarda tu perfil','Save your profile','Los cambios de Información, Intereses y Privacidad se guardan juntos con este botón.','Save changes to Information, Interests and Privacy together using this button.','ascla_write'),
            ]),
            'directorio'=>self::tour('directorio',[
                $step('search','.directory-search','Encuentra a otros asociados','Find other members','Busca por nombre, empresa o experiencia y combina los filtros que necesites.','Search by name, company or experience and combine the filters you need.'),
                $step('connections','#connections-panel','Tus conexiones','Your connections','Consulta las solicitudes recibidas y enviadas y las conexiones que ya confirmaste.','Review incoming and outgoing requests and your confirmed connections.'),
                $step('profiles','.cards.directory,.directory-visibility-note','Conoce antes de conectar','Get to know people','Abre un perfil para revisar su experiencia, intereses y las opciones de contacto que permite.','Open a profile to explore experience, interests and the contact options the member allows.'),
            ]),
            'mensajeria'=>self::tour('mensajeria',[
                $step('inbox','.chat-sidebar','Tus conversaciones','Your conversations','La bandeja separa los chats de las solicitudes de conversación pendientes.','Your inbox separates chats from pending conversation requests.'),
                $step('start','.chat-heading-actions','Conversa y crea grupos','Chat and create groups','Inicia una conversación desde el Directorio o reúne a tus conexiones en un grupo.','Start a conversation from the directory or bring your connections together in a group.','ascla_write'),
                $step('write','.chat-compose','Escribe aquí','Write here','Envía mensajes en la conversación abierta. En un grupo, abre su nombre para ver integrantes y opciones.','Send messages in the open conversation. In a group, open its name to view members and available actions.'),
            ]),
            'asistente'=>self::tour('asistente',[
                $step('suggestions','.ask-suggestions','Empieza con una pregunta','Start with a question','Puedes usar una sugerencia para consultar eventos, recursos o información de ASCLA.','Choose a suggestion to ask about ASCLA events, resources or information.'),
                $step('question','.assistant-compose','Pregunta con tus palabras','Ask in your own words','Describe lo que necesitas. El asistente consulta la información a la que tu cuenta tiene acceso.','Describe what you need. The assistant uses information your account can access.'),
                $step('history','.assistant-chat-head','Retoma tus consultas','Return to your questions','Usa Historial para revisar respuestas anteriores o Nueva conversación para comenzar otra consulta.','Use History to review previous answers or New conversation to start another question.'),
            ]),
            'contacto'=>self::tour('contacto',[
                $step('form','.contact-form','Contacta al equipo','Contact the team','Escribe un asunto, elige la categoría y explica qué necesitas antes de enviar la solicitud.','Enter a subject, choose a category and describe what you need before sending a request.'),
                $step('answers','.contact-faq','Resuelve dudas frecuentes','Find common answers','Estas respuestas pueden ayudarte con el perfil, las inscripciones y las normas de la comunidad.','These answers explain profile updates, registration and community guidelines.'),
                $step('requests','.contact-requests','Sigue tu solicitud','Track your request','Aquí aparecen el estado de tus consultas y las respuestas del equipo.','Check the status of your requests and replies from the team here.'),
            ]),
            'aliados'=>self::tour('aliados',[
                $step('intro','.ally-catalog-header','Conoce a los aliados','Meet our partners','Explora las organizaciones que colaboran con ASCLA y sus convenios.','Explore the organizations working with ASCLA and their agreements.'),
                $step('search','.ally-catalog-search','Busca un convenio','Find an agreement','Utiliza la búsqueda y los filtros para encontrar los convenios que te interesan.','Use search and filters to find agreements that interest you.'),
                $step('detail','.ally-card,.ally-grid,.empty','Consulta los detalles','View the details','Abre una ficha para conocer beneficios, condiciones y enlaces disponibles.','Open a partner to view available benefits, terms and links.'),
            ]),
        ];
        $tours+=self::contentTours();
        $tours+=self::adminTours();
        foreach (['intranet','directorio','mensajeria','contacto'] as $key) { $tours[$key]['community_only']=true; }
        return apply_filters('ascla_onboarding_tours',$tours);
    }

    private static function contentTours(): array
    {
        $step=[self::class,'step'];$tours=[];
        $pages=[
            'eventos'=>['event','Encuentros y capacitaciones','Events and training','Consulta los próximos encuentros y vuelve a los anteriores desde sus pestañas.','Browse upcoming events and use the tabs to return to previous ones.','Abre un encuentro para revisar fecha, modalidad, cupos y las opciones de inscripción.','Open an event to view its date, format, availability and registration options.','ascla_publish'],
            'hub'=>['hub','Comparte con tu comunidad','Share with your community','Aquí se reúnen las publicaciones y conversaciones de los asociados.','Find posts and conversations from members here.','Abre una publicación para leerla, comentar o responder a una conversación.','Open a post to read it, comment or reply to a conversation.','ascla_write'],
            'galeria'=>['gallery','Los momentos de ASCLA','ASCLA moments','Explora las galerías de la comunidad y sus fotografías.','Explore community galleries and their photos.','Abre una galería para recorrer sus imágenes y consultar los documentos adjuntos.','Open a gallery to browse its images and attached documents.','ascla_publish'],
            'foros'=>['forum','Conversaciones por tema','Conversations by topic','Explora los foros para intercambiar preguntas, experiencias e ideas.','Explore forums to exchange questions, experience and ideas.','Abre un foro y elige un tema para leer o participar con una respuesta.','Open a forum and choose a topic to read or participate in the conversation.','ascla_write'],
            'centro-conocimiento'=>['resource','Conocimiento a tu alcance','Knowledge within reach','Encuentra artículos, videos y otros recursos de la comunidad.','Find community articles, videos and other resources.','Pulsa la portada o Explorar para abrir un recurso. Allí puedes leer, ver documentos o reproducir videos.','Select a cover or Explore to open a resource, read documents or watch videos.','ascla_publish'],
        ];
        foreach ($pages as $page=>[$type,$title,$titleEn,$body,$bodyEn,$detail,$detailEn,$cap]) {
            $tours[$page]=self::tour($page,[
                $step('overview','#page-content h1',$title,$titleEn,$body,$bodyEn),
                $step('search','.content-list-filters','Busca lo que te interesa','Find what interests you','Escribe una búsqueda y usa Más filtros para acotar los resultados.','Enter a search and use More filters to narrow the results.'),
                $step('browse','.listing-tabs','Elige qué consultar','Choose what to view','Las pestañas reúnen las vistas disponibles para tu cuenta, incluidas tus publicaciones y borradores cuando corresponda.','Tabs group the views available to your account, including your posts and drafts when available.'),
                $step('detail','.event-card,.gallery-card,.knowledge-card,.feed-card,.forum-card,.empty','Abre el contenido','Open the content',$detail,$detailEn),
                $step('create','[data-action="editor"][data-type="'.$type.'"]','Publica desde aquí','Create content here','Este botón abre el formulario de creación. Revisa los campos y el estado antes de guardar.','This button opens the creation form. Review its fields and publication status before saving.',$cap),
            ]);
        }
        return $tours;
    }

    private static function adminTours(): array
    {
        $step=[self::class,'step'];$tours=[];
        $sections=[
            'resumen'=>['Resumen','Overview','ascla_manage','.admin-overview','Consulta la actividad y los accesos para atender las tareas del día.','View activity and shortcuts for your daily tasks.'],
            'estadisticas'=>['Estadísticas','Statistics','ascla_publish',self::ADMIN_FORM,'Ajusta el período y los filtros para consultar inscripciones, asistencias y participación.','Choose a period and filters to review registrations, attendance and participation.'],
            'moderacion'=>['Revisar contenido','Review content','ascla_admin_area','.moderation-choices','Elige publicaciones, comentarios o reportes. Abre cada caso para revisar las acciones que permite tu rol.','Choose posts, comments or reports, then open a case to review actions available to your role.'],
            'solicitudes'=>['Consultas de Contacto','Contact requests','ascla_publish','.support-toolbar','Filtra las solicitudes y abre una consulta para responder o actualizar su estado.','Filter requests and open one to respond or update its status.'],
            'contenidos'=>['Galerías y recursos','Galleries and resources','ascla_publish','.editorial-registry-filters','Elige galerías o recursos y filtra por título o estado para gestionar el catálogo.','Choose galleries or resources and filter by title or status to manage the catalog.'],
            'importar'=>['Importar intereses','Import interests','ascla_manage','#interest-upload-title','Carga un archivo, analiza las columnas y revisa las propuestas antes de aplicarlas a perfiles.','Upload a file, analyze its columns and review proposals before applying them to profiles.'],
            'usuarios'=>['Usuarios e intereses','Members and interests','ascla_manage','.admin-filters','Busca cuentas y abre un perfil para consultar intereses y las opciones de gestión disponibles.','Find accounts and open a profile to view interests and available management options.'],
            'aliados'=>['Aliados','Partners','ascla_manage',self::ADMIN_FORM,'Filtra los convenios y utiliza las acciones de cada ficha para gestionar sus datos.','Filter agreements and use each entry’s actions to manage its details.'],
            'microeventos'=>['Encuentros','Meetings','ascla_publish','.micro-planning','Genera propuestas, revisa candidatos y ajusta los datos antes de aprobar y publicar un encuentro.','Generate proposals, review candidates and adjust details before approving and publishing a meeting.'],
            'logs'=>['Historial de cambios','Change history','ascla_manage',self::ADMIN_FORM,'Usa los filtros para consultar las acciones registradas y su contexto.','Use filters to explore recorded actions and their context.'],
            'configuracion'=>['Configuración','Settings','ascla_configure','.settings-group','Abre un apartado para revisar sus opciones y guarda los cambios cuando termines.','Open a section to review its options and save your changes when finished.'],
        ];
        foreach ($sections as $key=>[$title,$titleEn,$cap,$target,$body,$bodyEn]) {
            $tour=self::tour('admin',[
                $step('navigation','.admin-tabs','Tu espacio de gestión','Your management area','Las secciones disponibles dependen de tu rol. Puedes cambiar de apartado desde esta navegación.','The available sections depend on your role. Use this navigation to switch between them.'),
                $step('tools',[$target,'#admin-panel h2'],$title,$titleEn,$body,$bodyEn),
            ],$cap,$key);
            $tour['label']=Language::text($title,$titleEn);
            $tours['admin-'.$key]=$tour;
        }
        return $tours;
    }
}
