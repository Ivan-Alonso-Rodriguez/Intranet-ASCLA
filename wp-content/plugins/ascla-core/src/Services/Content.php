<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Frontend\App;
use ASCLA\Core\Repositories\Store;

final class Content
{

    public static function indexMeta(int $metaId,int $postId,string $key,mixed $value): void
    {
        unset($metaId);
        if ($key!=='_ascla' || !is_array($value)) { return; }
        if(get_post_type($postId)==='ascla_contact') { update_post_meta($postId,'_ascla_request_status',$value['request_status']??'open'); }
        foreach (['resource_type','start','end','source'] as $field) { update_post_meta($postId,'_ascla_'.$field,$value[$field]??''); }
        update_post_meta($postId,'_ascla_micro',empty($value['micro'])?'0':'1');
        update_post_meta($postId,'_ascla_cancelled',empty($value['cancelled'])?'0':'1');
        delete_post_meta($postId,'_ascla_invitee');
        foreach (array_unique(array_map('absint',$value['invitees']??[])) as $uid) { add_post_meta($postId,'_ascla_invitee',$uid); }
    }
    private const EDITORIAL_TYPES=['gallery','resource','event'];
    private const COMMUNITY_TYPES=['hub','forum','topic'];

    public static function canCreate(string $type): bool
    {
        if(!Access::member() || !current_user_can('ascla_write') || !isset(Catalog::TYPES[$type])){return false;}
        if(in_array($type,self::EDITORIAL_TYPES,true)){return Access::canPublish();}
        return current_user_can('ascla_moderate') || in_array($type,array_merge(self::COMMUNITY_TYPES,['contact']),true);
    }
    public static function canEdit(\WP_Post $post): bool
    {
        $type=substr($post->post_type,6);
        return self::canRead($post) && self::canCreate($type)
            && (current_user_can('ascla_manage') || (int)$post->post_author===get_current_user_id()
                || (current_user_can('ascla_moderate') && !in_array($type,self::EDITORIAL_TYPES,true)));
    }
    public static function canModerate(\WP_Post $post): bool
    {
        if(!self::canRead($post) || $post->post_type==='ascla_contact'){return false;}
        if(in_array(substr($post->post_type,6),self::EDITORIAL_TYPES,true)){
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            return self::canEdit($post) && (empty($meta['micro']) || current_user_can('ascla_manage'));
        }
        return current_user_can('ascla_moderate');
    }
    public static function canDeleteComment(\WP_Comment $comment): bool
    {
        $post=get_post($comment->comment_post_ID);
        return $post && self::canRead($post)
            && (current_user_can('ascla_moderate') || current_user_can('ascla_manage') || (int)$comment->user_id===get_current_user_id());
    }
    public static function canRead(\WP_Post $post): bool
    {
        $type=str_starts_with($post->post_type,'ascla_')?substr($post->post_type,6):'';
        $valid=Access::member() && $post->post_status!=='trash' && $type!=='' && isset(Catalog::TYPES[$type]);
        if (!$valid) { return false; }
        $author=(int)$post->post_author;
        $me=get_current_user_id();
        if ($post->post_status==='draft') { return self::canReadDraft($type,$author,$me); }
        return self::canReadPublished($post,$type,$author,$me);
    }

    private static function canReadDraft(string $type,int $author,int $me): bool
    {
        if ($author===$me) { return true; }
        $authorUser=get_userdata($author);
        if ($authorUser && in_array('administrator',(array)$authorUser->roles,true)) { return false; }
        return match(true) {
            in_array($type,self::COMMUNITY_TYPES,true)=>current_user_can('ascla_moderate'),
            in_array($type,self::EDITORIAL_TYPES,true)=>current_user_can('ascla_manage'),
            default=>false,
        };
    }

    private static function canReadPublished(\WP_Post $post,string $type,int $author,int $me): bool
    {
        if (current_user_can('ascla_manage') || $author===$me || (current_user_can('ascla_moderate') && !in_array($type,self::EDITORIAL_TYPES,true))) { return true; }
        if ($post->post_type==='ascla_contact' || $post->post_status!=='publish') { return false; }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        return empty($meta['micro']) || in_array($me,array_map('intval',$meta['invitees']??[]),true);
    }
    public static function get(int $id): \WP_Post
    {
        $post=get_post($id); Access::require($post && self::canRead($post),'Contenido no encontrado.',404); return $post;
    }

    public static function page(string $type): string { return ['resource'=>'centro-conocimiento','event'=>'eventos','topic'=>'foros','forum'=>'foros','gallery'=>'galeria','ally'=>'aliados','contact'=>'contacto'][$type]??'hub'; }



    /**
     * RF-033: a new support request is relevant activity for both the requester
     * and the moderation team. Email is intentionally not forced here; support
     * notices remain available in-app even when optional email categories are off.
     */


    /** Trash the selected contribution; other authors' forum topics remain in the general list. */

















    public static function __callStatic(string $name,array $arguments): mixed
    {
        if ($name==='tags') {
            ContentPublishing::tags(...$arguments);
            return null;
        }
        if ($name==='commentTransition') {
            ContentInteractions::commentTransition(...$arguments);
            return null;
        }
        return match($name) {
            'serialize'=>ContentPublishing::serialize(...$arguments),
            'listing'=>ContentPublishing::listing(...$arguments),
            'save'=>ContentPublishing::save(...$arguments),
            'canDelete'=>ContentPublishing::canDelete(...$arguments),
            'remove'=>ContentPublishing::remove(...$arguments),
            'guardPublication'=>ContentPublishing::guardPublication(...$arguments),
            'published'=>ContentPublishing::published(...$arguments),
            'reportLabel'=>ContentInteractions::reportLabel(...$arguments),
            'removeComment'=>ContentInteractions::removeComment(...$arguments),
            'comments'=>ContentInteractions::comments(...$arguments),
            'comment'=>ContentInteractions::comment(...$arguments),
            'reactComment'=>ContentInteractions::reactComment(...$arguments),
            'notifyComment'=>ContentInteractions::notifyComment(...$arguments),
            'reportComment'=>ContentInteractions::reportComment(...$arguments),
            'react'=>ContentInteractions::react(...$arguments),
            'report'=>ContentInteractions::report(...$arguments),
            'reviewReport'=>ContentInteractions::reviewReport(...$arguments),
            'moderate'=>ContentInteractions::moderate(...$arguments),
            default=>throw new \BadMethodCallException('Método Content no disponible: '.$name),
        };
    }

}

final class ContentPublishing
{
    private const EDITORIAL_TYPES=['gallery','resource','event'];

    public static function serialize(\WP_Post $post): array
    {
        $meta=self::serializedMeta($post);
        $title=self::legacyRedaction($post->post_title);
        $body=self::legacyRedaction($post->post_content);
        return self::serializedPayload($post,$meta,$title,$body);
    }

    public static function listing(string $type,array $filter=[]): array
    {
        [$args,$page,$recommended]=ContentListingQuery::build($type,$filter);
        if ($recommended) { return self::recommendedListing($args,$page,$filter); }
        return self::standardListing($args,$page,$filter);
    }

    public static function save(string $type,array $input,int $id=0): array
    {
        $context=self::saveBase($type,$input,$id);
        [$taxonomies,$tagNames]=self::saveTaxonomies($input);
        foreach ($context['meta']['media_ids']??[] as $media) {
            $file=Store::one('media',(int)$media);
            Access::require($file && ((int)$file['user_id']===get_current_user_id()||($id&&(int)$file['post_id']===$id)),'Archivo no autorizado.');
        }
        [$status,$directGeneratedPublish,$parent,$meta]=ContentSaveState::resolve($type,$input,$id,$context);
        $context=array_merge($context,['type'=>$type,'input'=>$input,'id'=>$id,'taxonomies'=>$taxonomies,'tag_names'=>$tagNames,'status'=>$status,'direct_generated_publish'=>$directGeneratedPublish,'parent'=>$parent,'meta'=>$meta]);
        $saved=self::persist($context);
        self::afterSave($saved,$context);
        return self::serialize(get_post($saved));
    }

    private static function legacyRedaction(mixed $value): mixed
    {
        if (is_array($value)) { return array_map([self::class,'legacyRedaction'],$value); }
        return is_string($value)?str_ireplace('[identidad reservada]','información reservada',$value):$value;
    }

    private static function serializedMeta(\WP_Post $post): array
    {
        $meta=self::legacyRedaction((array)get_post_meta($post->ID,'_ascla',true));
        foreach (['frameworks','norms','concepts','tags','conclusions'] as $field) {
            if (!isset($meta[$field]) || !is_array($meta[$field])) { continue; }
            $meta[$field]=array_values(array_filter($meta[$field],static function($value){
                if (!is_string($value)) { return true; }
                $plain=mb_strtolower(remove_accents(trim($value," \t\n\r\0\x0B.,;:–—-")));
                return !in_array($plain,['participante','participantes','dato reservado','identidad reservada','informacion reservada','una persona','persona'],true);
            }));
        }
        if ($post->post_type!=='ascla_resource' || empty($meta['video_id'])) { return $meta; }
        foreach (['summary','technical_note'] as $field) {
            if (!is_string($meta[$field]??null)) { continue; }
            $text=$meta[$field];
            $text=preg_replace('/(^|[.!?]\s+)La transcripci[oó]n\b/u','$1El video',$text)??$text;
            $text=preg_replace('/\b(?:la|esta) transcripci[oó]n\b/iu','el video',$text)??$text;
            $meta[$field]=trim(preg_replace('/\btranscripci[oó]n\b/iu','contenido del video',$text)??$text);
        }
        return $meta;
    }

    private static function serializedPayload(\WP_Post $post,array $meta,mixed $title,mixed $body): array
    {
        if (in_array(substr($post->post_type,6),self::EDITORIAL_TYPES,true) && !Content::canEdit($post)) {
            $view=\ASCLA\Core\Domain\EditorialPrivacy::reader($title,$body,$meta);
            $title=$view['title'];$body=$view['body'];$meta=$view['meta'];
        }
        if (!current_user_can('ascla_moderate') && !Access::canPublish()) {
            unset($meta['transcript'],$meta['identities'],$meta['invitees'],$meta['moderation'],$meta['transcript_error'],$meta['transcript_mode'],$meta['transcript_checked_at']);
        }
        $author=get_userdata($post->post_author);
        $date=$post->post_date_gmt;
        if (!$date || str_starts_with($date,'0000-')) { $date=get_gmt_from_date($post->post_date); }
        $terms=wp_get_object_terms($post->ID,['ascla_interest','ascla_category','ascla_tag']);
        return ['id'=>$post->ID,'can_delete'=>self::canDelete($post),'can_edit'=>Content::canEdit($post),'can_moderate'=>Content::canModerate($post),'type'=>substr($post->post_type,6),'title'=>$title,'body'=>$body,'status'=>$post->post_status,'author'=>['id'=>(int)$post->post_author,'name'=>$author?Profiles::publicName((int)$author->ID):'ASCLA'],'date'=>$date,'parent'=>(int)$post->post_parent,'meta'=>$meta,'media'=>Media::metadata($post->ID,(array)($meta['media_ids']??[])),'tags'=>is_wp_error($terms)?[]:array_map(static fn($t)=>['id'=>$t->term_id,'name'=>$t->name,'taxonomy'=>$t->taxonomy],$terms),'reactions'=>Store::count('relations',"target_id=%d AND kind='like'",[$post->ID]),'liked'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='like'",[$post->ID,get_current_user_id()])>0,'following'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='follow'",[$post->ID,get_current_user_id()])>0,'comments'=>(int)$post->comment_count,'url'=>Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$post->ID])];
    }



    private static function recommendedListing(array $args,int $page,array $filter): array
    {
        $perPage=max(1,min(100,(int)($filter['per_page']??18)));
        $args['posts_per_page']=-1;$args['paged']=1;$args['no_found_rows']=true;
        $query=new \WP_Query($args);$ranked=[];
        foreach ($query->posts as $post) {
            if (!Content::canRead($post)) { continue; }
            $rank=KnowledgeRecommendations::score($post);
            if (($rank['relevance']??0)<=0) { continue; }
            $item=self::serialize($post);
            if (!empty($filter['resource_type']) && ($item['meta']['resource_type']??'')!==$filter['resource_type']) { continue; }
            $item['recommendation']=['score'=>(int)($rank['score']??0),'reasons'=>array_values((array)($rank['reasons']??[]))];
            $ranked[]=['item'=>$item,'score'=>(int)$rank['score'],'timestamp'=>strtotime($post->post_date_gmt.' UTC')?:0];
        }
        usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score'] ?: $b['timestamp']<=>$a['timestamp'] ?: $b['item']['id']<=>$a['item']['id']);
        $total=count($ranked);$offset=($page-1)*$perPage;
        return ['items'=>array_column(array_slice($ranked,$offset,$perPage),'item'),'page'=>$page,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage))];
    }

    private static function standardListing(array $args,int $page,array $filter): array
    {
        $query=new \WP_Query($args);$items=[];
        foreach ($query->posts as $post) {
            if (!Content::canRead($post)) { continue; }
            $item=self::serialize($post);
            if (!empty($filter['resource_type']) && ($item['meta']['resource_type']??'')!==$filter['resource_type']) { continue; }
            $items[]=$item;
        }
        return ['items'=>$items,'page'=>$page,'total'=>(int)$query->found_posts,'pages'=>(int)$query->max_num_pages];
    }

    private static function saveBase(string $type,array $input,int $id): array
    {
        Access::require(isset(Catalog::TYPES[$type]),'Tipo no válido.',400);
        $editor=current_user_can('ascla_moderate');$publisher=Access::canPublish();
        Access::require(!in_array($type,['gallery','resource','event'],true)||$publisher,'Solo un Ejecutivo o un administrador pueden crear o editar eventos, Galería y Centro de Conocimiento.',403);
        Access::require(Content::canCreate($type),'No puede crear contenido en esta sección.',403);
        $oldMeta=[];$oldTitle='';
        if ($id) {
            $post=Content::get($id);Access::require($post->post_type==='ascla_'.$type && Content::canEdit($post),'No puede editar este contenido.');
            if ($type==='event') { $oldMeta=(array)get_post_meta($id,'_ascla',true);$oldTitle=(string)$post->post_title; }
        }
        $title=trim(Access::text($input['title']??'',200));$body=trim(Access::text($input['body']??'',30000));
        Access::require($title!=='' && $body!=='','Complete título y contenido.',400);
        return ['editor'=>$editor,'publisher'=>$publisher,'old_meta'=>$oldMeta,'old_title'=>$oldTitle,'title'=>$title,'body'=>$body,'meta'=>ContentMeta::sanitize($type,(array)($input['meta']??[]),$id)];
    }

    private static function saveTaxonomies(array $input): array
    {
        $taxonomies=[];
        foreach (['interest','category','tag'] as $tax) {
            Access::require(!isset($input[$tax])||is_array($input[$tax]),'Categoría no válida.',400);
            $ids=array_values(array_unique(array_map('absint',$input[$tax]??[])));
            foreach ($ids as $tid) { Access::require((bool)term_exists($tid,'ascla_'.$tax),'Categoría no válida.',400); }
            $taxonomies[$tax]=$ids;
        }
        Access::require(!isset($input['tag_names'])||is_array($input['tag_names']),'Etiquetas no válidas.',400);
        $tagNames=array_map(static fn($name)=>trim(Access::text($name,60)),array_slice($input['tag_names']??[],0,20));
        return [$taxonomies,$tagNames];
    }



    private static function persist(array $context): int
    {
        $postData=['post_type'=>'ascla_'.$context['type'],'post_title'=>$context['title'],'post_content'=>$context['body'],'post_status'=>'draft','post_parent'=>$context['parent'],'comment_status'=>'open'];
        if ($context['id']) { $postData['ID']=$context['id']; } else { $postData['post_author']=get_current_user_id(); }
        $saved=$context['id']?wp_update_post(wp_slash($postData),true):wp_insert_post(wp_slash($postData),true);
        Access::require(!is_wp_error($saved),'No se pudo guardar el contenido.',500);
        update_post_meta($saved,'_ascla',$context['meta']);
        foreach ($context['taxonomies'] as $tax=>$ids) { wp_set_object_terms($saved,$ids,'ascla_'.$tax); }
        if ($context['tag_names']) { self::tags($saved,$context['tag_names']); }
        foreach ($context['meta']['media_ids']??[] as $media) { Media::attach($media,$saved); }
        wp_update_post(['ID'=>$saved,'post_status'=>$context['status']]);
        return (int)$saved;
    }

    private static function afterSave(int $saved,array $context): void
    {
        if ($context['type']==='event' && $context['id'] && $context['status']==='publish') {
            Events::notifyImportantChanges($saved,$context['old_meta']+['__title'=>$context['old_title']],$context['meta']+['__title'=>$context['title']]);
        }
        if ($context['type']==='contact' && !$context['id']) { self::notifySupportCreated($saved); }
        if ($context['type']==='resource' && !empty($context['meta']['video_id'])) { Knowledge::autoVideoMetadata($saved); }
        Audit::record('content_saved',$saved,$context['status']);
        if ($context['status']==='publish' && in_array($context['type'],['hub','topic','resource','gallery'],true)) {
            $savedPost=get_post($saved);
            if ($savedPost) { KnowledgeRecommendations::invalidateActivity((int)$savedPost->post_author); }
        }
    }

    private static function notifySupportCreated(int $postId): void
    {
        $post=get_post($postId);
        if (!$post || $post->post_type!=='ascla_contact') { return; }
        $author=(int)$post->post_author;
        if ($author>0 && Access::member($author)) {
            Notifications::send(
                $author,
                'support_received',
                'Recibimos tu solicitud #'.$postId.'.',
                Catalog::url('contacto'),
                ['type'=>'post','id'=>$postId]
            );
        }
        $moderators=get_users(['capability'=>'ascla_moderate','fields'=>'ids']);
        foreach (array_unique(array_map('absint',$moderators)) as $moderator) {
            if (!$moderator || $moderator===$author || !Access::member($moderator)) { continue; }
            Notifications::send(
                $moderator,
                'support_request',
                Profiles::publicName($author).' envió una nueva solicitud de soporte.',
                App::adminUrl(['page'=>'ascla-solicitudes']),
                ['type'=>'post','id'=>$postId,'actor'=>$author]
            );
        }
    }

    public static function canDelete(\WP_Post $post): bool
    {
        if($post->post_type==='ascla_contact') {
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            return Access::member() && current_user_can('ascla_moderate') && $post->post_status!=='trash' && ($meta['request_status']??'open')==='closed';
        }
        return Access::member() && str_starts_with($post->post_type,'ascla_') && isset(Catalog::TYPES[substr($post->post_type,6)]) && $post->post_status!=='trash'
            && (current_user_can('ascla_manage') || (int)$post->post_author===get_current_user_id()
                || (current_user_can('ascla_moderate') && in_array(substr($post->post_type,6),['hub','forum','topic'],true)));
    }

    public static function remove(int $id): array
    {
        $post=Content::get($id);
        if($post->post_type==='ascla_contact') { return Administration::deleteContact($id); }
        Access::require(self::canDelete($post),'No tienes permisos para eliminar este contenido.',403);
        return Store::lock('content:'.$id,static function()use($id,$post){
            $meta=(array)get_post_meta($id,'_ascla',true);
            Access::require((bool)wp_trash_post($id),'No se pudo eliminar el contenido.',500);
            if($post->post_type==='ascla_event' && !empty($meta['micro'])) {
                MicroEvents::forget($id);
                Store::delete('registrations',['event_id'=>$id]);
            }
            if($post->post_type==='ascla_forum') {
                foreach(get_posts(['post_type'=>'ascla_topic','post_parent'=>$id,'post_status'=>['publish','pending','draft','ascla_hidden','ascla_rejected'],'numberposts'=>-1]) as $child) { wp_update_post(['ID'=>$child->ID,'post_parent'=>0]); }
            }
            foreach(['like','follow','report'] as $kind) { Store::delete('relations',['target_id'=>$id,'kind'=>$kind]); }
            Audit::record('content_trashed',$id,$post->post_type);
            return ['id'=>$id,'deleted'=>true,'message'=>'Contenido enviado a la papelera.'];
        });
    }

    public static function tags(int $id,array $names): void
    {
        $ids=[];
        foreach (array_slice($names,0,20) as $name) {
            if (!is_string($name)) { continue; }
            $name=trim(Access::text($name,60)); if ($name==='') { continue; }
            $term=term_exists($name,'ascla_tag');
            if (!$term) { $term=wp_insert_term($name,'ascla_tag'); }
            if (!is_wp_error($term)) { $ids[]=(int)(is_array($term)?$term['term_id']:$term); }
        }
        wp_set_object_terms($id,$ids,'ascla_tag');
    }

    public static function guardPublication(array $data,array $postarr): array
    {
        if (($data['post_status']??'')==='publish' && in_array($data['post_type']??'',['ascla_gallery','ascla_resource','ascla_event'],true) && !Access::canPublish() && (empty($postarr['ID']) || get_post_status($postarr['ID'])!=='publish')) { $data['post_status']='pending'; }
        if (($data['post_status']??'')==='publish' && str_starts_with($data['post_type']??'','ascla_') && !empty($postarr['ID'])) {
            $meta=(array)get_post_meta($postarr['ID'],'_ascla',true);
            if (!empty($meta['generated']) && empty($meta['reviewed'])) { $data['post_status']='pending'; }
        }
        return $data;
    }

    public static function published(string $new,string $old,\WP_Post $post): void
    {
        if ($new!=='publish'||$old==='publish'||!str_starts_with($post->post_type,'ascla_')) { return; }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        if (!empty($meta['micro'])) { MicroEvents::invite($post->ID); }
        if ($post->post_type==='ascla_resource') {
            if (!empty($meta['generated']) && !empty($meta['reviewed'])) { self::tags($post->ID,(array)($meta['tags']??[])); }
            $topics=wp_get_object_terms($post->ID,'ascla_interest',['fields'=>'ids']);
            if (!is_wp_error($topics) && $topics) { \ASCLA\Core\Jobs\Queue::enqueue('resource_notifications',['resource_id'=>$post->ID],0); }
        }
        if ($post->post_type==='ascla_hub') {
            preg_match_all('/@\[(\d+)\]/',$post->post_content,$matches);
            foreach (array_unique($matches[1]) as $id) { Notifications::send((int)$id,'mention','Te mencionaron en el Hub ASCLA.',Catalog::url('hub',['item'=>$post->ID]),['type'=>'post','id'=>$post->ID,'actor'=>(int)$post->post_author]); }
        }
    }
}



final class ContentListingQuery
{
    public static function build(string $type,array $filter): array
    {
        Access::require(isset(Catalog::TYPES[$type]),'Sección no válida.',400);
        $page=max(1,min(10000,(int)($filter['page']??1)));$mine=!empty($filter['mine']);
        $status=($mine||current_user_can('ascla_moderate'))?['publish','draft','pending','ascla_rejected','ascla_hidden','private']:['publish'];
        if (!empty($filter['status']) && in_array($filter['status'],$status,true)) { $status=[$filter['status']]; }
        $args=['post_type'=>'ascla_'.$type,'post_status'=>$status,'posts_per_page'=>18,'paged'=>$page,'s'=>Access::text($filter['q']??'',150),'orderby'=>$type==='topic'?'modified':'date','order'=>'DESC'];
        self::visibility($args,$type,$filter,$mine);
        self::optionalFilters($args,$type,$filter);
        $recommended=$type==='resource' && !empty($filter['recommended']);
        if ($recommended) { $args['post_status']=['publish']; }
        return [\ASCLA\Core\Repositories\ContentQuery::filters($args,$type,$filter),$page,$recommended];
    }

    private static function visibility(array &$args,string $type,array $filter,bool $mine): void
    {
        if ($mine || ($type==='contact'&&!current_user_can('ascla_moderate'))) { $args['author']=get_current_user_id(); }
        elseif (!current_user_can('ascla_moderate')) { $args['post_status']=['publish']; }
        if (!empty($filter['author']) && !$mine && ($type!=='contact'||current_user_can('ascla_moderate'))) { $args['author']=absint($filter['author']); }
    }

    private static function optionalFilters(array &$args,string $type,array $filter): void
    {
        if (isset($filter['parent']) && $filter['parent']!=='') { $args['post_parent']=absint($filter['parent']); }
        if (!empty($filter['after']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$filter['after'])) { $args['date_query']=[['after'=>$filter['after'],'inclusive'=>true]]; }
        if ($type==='resource' && !empty($filter['resource_type'])) { $args['meta_query'][]=['key'=>'_ascla_resource_type','value'=>Access::text($filter['resource_type'],30)]; }
        if ($type!=='event') { return; }
        if (!current_user_can('ascla_moderate') && empty($filter['mine'])) {
            $args['meta_query'][]=['relation'=>'OR',['key'=>'_ascla_micro','value'=>'0'],['key'=>'_ascla_micro','compare'=>'NOT EXISTS'],['key'=>'_ascla_invitee','value'=>get_current_user_id()]];
        }
        if (array_key_exists('past',$filter)) { $args['meta_query'][]=['key'=>'_ascla_end','value'=>gmdate('c'),'compare'=>!empty($filter['past'])?'<':'>=']; }
    }
}

final class ContentSaveState
{
    public static function resolve(string $type,array $input,int $id,array $context): array
    {
        $meta=$context['meta'];$requested=$input['status']??'pending';
        if (!empty($meta['generated']) && isset($input['tag_names'])) {
            $meta['tags']=array_map(static fn($name)=>Access::text($name,60),array_slice((array)$input['tag_names'],0,20));
        }
        Access::require(in_array($requested,['draft','pending','publish'],true),'Estado no válido.',400);
        $direct=$type==='resource' && $context['publisher'] && $requested==='publish' && !empty($meta['generated']);
        $status=self::status($type,$requested,$meta,$context['editor'],$context['publisher'],$direct);
        $parent=self::parent($type,$input);
        if ($id && !empty($meta['generated']) && !$direct) { $meta['reviewed']=false; }
        if ($direct) { $meta['reviewed']=true; }
        return [$status,$direct,$parent,$meta];
    }

    private static function status(string $type,string $requested,array $meta,bool $editor,bool $publisher,bool $direct): string
    {
        $status='pending';
        $autoEvent=$type==='event' && empty($meta['micro']) && empty($meta['generated']);
        if ($type==='contact') { $status='private'; }
        elseif ($requested==='draft') { $status='draft'; }
        elseif ($autoEvent || in_array($type,['topic','forum'],true)) { $status='publish'; }
        elseif (($editor || ($publisher && in_array($type,['gallery','resource'],true))) && $requested==='publish' && (empty($meta['generated']) || $direct)) { $status='publish'; }
        elseif (!$editor && !Settings::get()['moderation_required'] && $type==='hub') { $status='publish'; }
        return $status;
    }

    private static function parent(string $type,array $input): int
    {
        $parent=absint($input['parent']??0);
        if (!$parent) { return 0; }
        $post=Content::get($parent);
        Access::require($type==='topic' && $post->post_type==='ascla_forum','Foro no válido.',400);
        return $parent;
    }
}

final class ContentInteractions
{
    private const COMMENT_NOT_FOUND='Comentario no encontrado.';
    private const RELATION_FILTER='user_id=%d AND target_id=%d AND kind=%s';
    private const UNPUBLISHED_CONTENT='Contenido no publicado.';
    private const REPORT_REASONS=[
        'harassment'=>'Acoso','fraud'=>'Fraude o estafa','spam'=>'Mensaje no deseado (spam)','false_information'=>'Información falsa',
        'hate'=>'Incitación al odio','violence'=>'Amenazas o violencia','self_harm'=>'Autolesiones','explicit'=>'Contenido explícito',
        'extremism'=>'Organizaciones extremistas o peligrosas','sexual'=>'Contenido sexual','fake_account'=>'Cuenta falsa',
        'child_exploitation'=>'Explotación infantil','restricted_goods'=>'Bienes y servicios restringidos',
        'intimate_images'=>'Difusión de imágenes íntimas sin consentimiento','other'=>'Otro motivo',
    ];

    public static function reportLabel(string $reason): string
    {
        return self::REPORT_REASONS[$reason]??($reason!==''?$reason:'Sin motivo registrado');
    }

    public static function removeComment(int $id): array
    {
        $comment=get_comment($id);Access::require($comment && !in_array((string)$comment->comment_approved,['trash','post-trashed'],true),self::COMMENT_NOT_FOUND,404);
        Content::get((int)$comment->comment_post_ID);
        Access::require(Content::canDeleteComment($comment),'No tienes permisos para eliminar este comentario.',403);
        Access::require((bool)wp_trash_comment($id),'No se pudo eliminar el comentario.',500);
        Store::delete('relations',['target_id'=>$id,'kind'=>'comment_like']);
        Store::delete('relations',['target_id'=>$id,'kind'=>'comment_report']);
        Audit::record('comment_trashed',$id);
        return ['id'=>$id,'deleted'=>true];
    }

    public static function comments(int $id): array
    {
        Content::get($id); $comments=get_comments(['post_id'=>$id,'status'=>'approve','include_unapproved'=>[get_current_user_id()],'number'=>100,'order'=>'ASC']);
        $me=get_current_user_id();
        return array_map(static function($c) use($me) {
            $cid=(int)$c->comment_ID;
            return [
                'id'=>$cid,
                'parent'=>(int)$c->comment_parent,
                'author_id'=>(int)$c->user_id,
                'can_delete'=>Content::canDeleteComment($c),
                'can_report'=>(string)$c->comment_approved==='1' && (int)$c->user_id!==$me,
                'status'=>(string)$c->comment_approved==='1'?'publish':'pending',
                'author'=>$c->user_id?Profiles::publicName((int)$c->user_id):'Comunidad ASCLA',
                'body'=>$c->comment_content,
                'date'=>$c->comment_date_gmt,
                'likes'=>Store::count('relations',"target_id=%d AND kind='comment_like'",[$cid]),
                'liked'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='comment_like'",[$cid,$me])>0,
            ];
        },$comments);
    }

    public static function comment(int $id,string $body,int $parent=0): array
    {
        Access::limit('comment',15); $post=Content::get($id); Access::require($post->post_status==='publish','El contenido aún no está publicado.',400);
        $body=trim(Access::text($body,5000)); Access::require($body!=='','Escriba un comentario.',400);
        if ($parent>0) {
            $parentComment=get_comment($parent);
            Access::require($parentComment && (int)$parentComment->comment_post_ID===$id && (string)$parentComment->comment_approved==='1','El comentario al que intentas responder ya no está disponible.',404);
        }
        $approved=in_array($post->post_type,['ascla_topic','ascla_forum'],true)||current_user_can('ascla_moderate')||!Settings::get()['moderate_comments'];
        $user=wp_get_current_user();
        $cid=wp_insert_comment(wp_slash(['comment_post_ID'=>$id,'comment_parent'=>$parent,'user_id'=>$user->ID,'comment_author'=>$user->display_name,'comment_content'=>$body,'comment_approved'=>$approved?1:0,'comment_type'=>'comment']));
        Access::require((int)$cid>0,'No se pudo guardar el comentario.',500);
        KnowledgeRecommendations::invalidateActivity(get_current_user_id());
        if ($approved) { self::notifyComment((int)$cid); }
        wp_update_post(['ID'=>$id,'post_modified'=>current_time('mysql')]);
        return ['id'=>(int)$cid,'status'=>$approved?'publish':'pending'];
    }

    public static function reactComment(int $id,bool $active): array
    {
        $comment=get_comment($id);
        Access::require($comment && (string)$comment->comment_approved==='1',self::COMMENT_NOT_FOUND,404);
        Content::get((int)$comment->comment_post_ID);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>'comment_like'];
        Store::lock('comment-reaction:'.get_current_user_id().':'.$id,static function()use($where,$active){
            if ($active && !Store::count('relations',self::RELATION_FILTER,array_values($where))) {
                Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);
            } elseif (!$active) { Store::delete('relations',$where); }
        });
        return ['active'=>$active,'likes'=>Store::count('relations',"target_id=%d AND kind='comment_like'",[$id])];
    }

    public static function commentTransition(string $new,string $old,\WP_Comment $comment): void
    {
        if ($new==='approved' && $old!=='approved') { self::notifyComment((int)$comment->comment_ID); }
    }

    public static function notifyComment(int $id): void
    {
        $comment=get_comment($id);
        if (!$comment || (string)$comment->comment_approved!=='1') { return; }
        $post=get_post((int)$comment->comment_post_ID);
        if (!$post || !str_starts_with($post->post_type,'ascla_')) { return; }
        $actor=(int)$comment->user_id;
        $url=Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$post->ID]);
        $parentAuthor=self::notifyCommentParent($comment,$post,$actor,$id,$url);
        $postAuthor=(int)$post->post_author;
        if ($postAuthor>0 && $postAuthor!==$actor && $postAuthor!==$parentAuthor) {
            $actorName=$actor>0?Profiles::publicName($actor):'Un asociado';
            Notifications::once($postAuthor,'comment:'.$id,'comment',$actorName.' comentó tu publicación.',$url,['type'=>'post','id'=>$post->ID,'actor'=>$actor]);
        }
    }

    private static function notifyCommentParent(\WP_Comment $comment,\WP_Post $post,int $actor,int $id,string $url): int
    {
        $parentAuthor=0;
        if ((int)$comment->comment_parent>0) {
            $parent=get_comment((int)$comment->comment_parent);
            if ($parent && (int)$parent->comment_post_ID===(int)$post->ID) {
                $parentAuthor=(int)$parent->user_id;
                if ($parentAuthor>0 && $parentAuthor!==$actor) {
                    $actorName=$actor>0?Profiles::publicName($actor):'Un asociado';
                    Notifications::once($parentAuthor,'comment-reply:'.$id,'comment_reply',$actorName.' respondió a tu comentario.',$url,['type'=>'post','id'=>$post->ID,'actor'=>$actor]);
                }
            }
        }
        return $parentAuthor;
    }

    public static function reportComment(int $id,string $reason,string $detail=''): array
    {
        Access::limit('comment_report',8,300);
        $comment=get_comment($id);
        Access::require($comment && (string)$comment->comment_approved==='1',self::COMMENT_NOT_FOUND,404);
        $post=Content::get((int)$comment->comment_post_ID);
        Access::require($post->post_status==='publish',self::UNPUBLISHED_CONTENT,400);
        Access::require((int)$comment->user_id!==get_current_user_id(),'No puedes reportar tu propio comentario.',400);
        $reason=trim(Access::text($reason,64));
        Access::require(isset(self::REPORT_REASONS[$reason]),'Selecciona un motivo de reporte válido.',400);
        $detail=trim(Access::text($detail,1000));
        Access::require($reason!=='other' || $detail!=='','Describe brevemente el motivo del reporte cuando selecciones “Otro motivo”.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>'comment_report'];
        Store::lock('comment-report:'.implode(':',$where),static function () use($where,$reason,$detail,$comment) {
            $existing=Store::rows('relations',self::RELATION_FILTER,array_values($where),'LIMIT 1')[0]??null;
            $data=['reason'=>$reason,'detail'=>$detail,'reviewed_at'=>null,'reviewed_by'=>0,'created_at'=>current_time('mysql',true)];
            if ($existing) { Store::update('relations',$data,['id'=>(int)$existing['id']]); }
            else { Store::insert('relations',$where+$data); }
            Audit::record('comment_reported',(int)$comment->comment_ID,self::reportLabel($reason));
        });
        return ['reported'=>true,'reason'=>$reason,'reason_label'=>self::reportLabel($reason)];
    }

    public static function react(int $id,string $kind,bool $active): array
    {
        Access::require(in_array($kind,['like','follow'],true),'Acción no válida.',400);
        $post=Content::get($id); Access::require($post->post_status==='publish',self::UNPUBLISHED_CONTENT,400);
        if ($kind==='follow' && $active && $post->post_type==='ascla_event') {
            $meta=(array)get_post_meta($id,'_ascla',true); $end=strtotime((string)($meta['end']??''));
            Access::require(empty($meta['cancelled']),'No puedes seguir un evento cancelado.',409);
            Access::require($end===false || $end>time(),'No puedes seguir un evento que ya finalizó.',400);
        }
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>$kind];
        Store::lock('reaction:'.implode(':',$where),static function () use($where,$active,$post,$kind) {
            if ($active && !Store::count('relations',self::RELATION_FILTER,array_values($where))) {
                Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);
                if ($kind==='like') { Notifications::send((int)$post->post_author,'reaction','Tu publicación recibió una reacción.',Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$post->ID]),['type'=>'post','id'=>$post->ID,'actor'=>get_current_user_id()]); }
            } elseif (!$active) { Store::delete('relations',$where); }
        });
        KnowledgeRecommendations::invalidateActivity(get_current_user_id());
        return ['active'=>$active];
    }

    public static function report(int $id,string $reason,string $detail=''): array
    {
        Access::limit('content_report',8,300);
        $post=Content::get($id);
        Access::require($post->post_status==='publish',self::UNPUBLISHED_CONTENT,400);
        Access::require((int)$post->post_author!==get_current_user_id(),'No puedes reportar tu propia publicación.',400);
        $reason=trim(Access::text($reason,64));
        Access::require(isset(self::REPORT_REASONS[$reason]),'Selecciona un motivo de reporte válido.',400);
        $detail=trim(Access::text($detail,1000));
        Access::require($reason!=='other' || $detail!=='','Describe brevemente el motivo del reporte cuando selecciones “Otro motivo”.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>'report'];
        Store::lock('report:'.implode(':',$where),static function () use($where,$reason,$detail,$post) {
            $existing=Store::rows('relations',self::RELATION_FILTER,array_values($where),'LIMIT 1')[0]??null;
            $data=['reason'=>$reason,'detail'=>$detail,'reviewed_at'=>null,'reviewed_by'=>0,'created_at'=>current_time('mysql',true)];
            if ($existing) { Store::update('relations',$data,['id'=>(int)$existing['id']]); }
            else { Store::insert('relations',$where+$data); }
            Audit::record('content_reported',$post->ID,self::reportLabel($reason));
        });
        return ['reported'=>true,'reason'=>$reason,'reason_label'=>self::reportLabel($reason)];
    }

    public static function reviewReport(int $id): array
    {
        return Reports::review($id);
    }

    public static function moderate(int $id,string $decision,string $reason,bool $reviewed=false): array
    {
        $post=Content::get($id);
        Access::require(Content::canModerate($post),'No tienes permisos para moderar este contenido.',403);
        $statuses=['approve'=>'publish','reject'=>'ascla_rejected','hide'=>'ascla_hidden','suspend'=>'ascla_hidden'];
        Access::require(isset($statuses[$decision])&&$post->post_type!=='ascla_contact','Decisión no válida.',400);
        Access::require(!in_array($post->post_type,['ascla_gallery','ascla_resource','ascla_event'],true)||Access::canPublish(),'Solo un Ejecutivo o un administrador pueden gestionar estas publicaciones.',403);
        $reason=Access::text($reason,1000); Access::require(trim($reason)!=='','Indique un motivo de moderación.',400);
        $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($decision!=='approve'||empty($meta['generated'])||$reviewed,'Debe confirmar revisión de fuentes, identidades y derechos.',400);
        if($decision==='approve'&&!empty($meta['micro'])){ MicroEvents::validateInvitees($meta); }
        $meta['moderation']=['moderator'=>get_current_user_id(),'date'=>gmdate('c'),'decision'=>$decision,'reason'=>$reason];
        if ($reviewed) { $meta['reviewed']=true; }
        update_post_meta($id,'_ascla',$meta); wp_update_post(['ID'=>$id,'post_status'=>$statuses[$decision]]);
        Audit::record('moderation',$id,$decision);
        Notifications::send((int)$post->post_author,'moderation','Tu publicación fue '.($decision==='approve'?'aprobada':'revisada').'.',Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$id]),['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]);
        return Content::serialize(get_post($id));
    }
}

