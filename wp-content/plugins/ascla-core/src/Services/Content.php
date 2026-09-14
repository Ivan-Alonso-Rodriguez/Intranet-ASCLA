<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\Store;

final class Content
{
    private const REPORT_REASONS=[
        'harassment'=>'Acoso',
        'fraud'=>'Fraude o estafa',
        'spam'=>'Mensaje no deseado (spam)',
        'false_information'=>'Información falsa',
        'hate'=>'Incitación al odio',
        'violence'=>'Amenazas o violencia',
        'self_harm'=>'Autolesiones',
        'explicit'=>'Contenido explícito',
        'extremism'=>'Organizaciones extremistas o peligrosas',
        'sexual'=>'Contenido sexual',
        'fake_account'=>'Cuenta falsa',
        'child_exploitation'=>'Explotación infantil',
        'restricted_goods'=>'Bienes y servicios restringidos',
        'intimate_images'=>'Difusión de imágenes íntimas sin consentimiento',
        'other'=>'Otro motivo',
    ];
    public static function reportLabel(string $reason): string
    {
        return self::REPORT_REASONS[$reason]??($reason!==''?$reason:'Sin motivo registrado');
    }
    public static function indexMeta(int $metaId,int $postId,string $key,mixed $value): void
    {
        if ($key!=='_ascla' || !is_array($value)) { return; }
        if(get_post_type($postId)==='ascla_contact') update_post_meta($postId,'_ascla_request_status',$value['request_status']??'open');
        foreach (['resource_type','start','end','source'] as $field) { update_post_meta($postId,'_ascla_'.$field,$value[$field]??''); }
        update_post_meta($postId,'_ascla_micro',empty($value['micro'])?'0':'1');
        delete_post_meta($postId,'_ascla_invitee');
        foreach (array_unique(array_map('absint',$value['invitees']??[])) as $uid) { add_post_meta($postId,'_ascla_invitee',$uid); }
    }
    public static function canRead(\WP_Post $post): bool
    {
        if (!Access::member() || $post->post_status==='trash' || !str_starts_with($post->post_type,'ascla_') || !isset(Catalog::TYPES[substr($post->post_type,6)])) { return false; }
        if (current_user_can('ascla_moderate') || (int)$post->post_author===get_current_user_id()) { return true; }
        if ($post->post_type==='ascla_contact' || $post->post_status!=='publish') { return false; }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        if (!empty($meta['micro'])) { return in_array(get_current_user_id(),array_map('intval',$meta['invitees']??[]),true); }
        return true;
    }
    public static function get(int $id): \WP_Post
    {
        $post=get_post($id); Access::require($post && self::canRead($post),'Contenido no encontrado.',404); return $post;
    }
    public static function serialize(\WP_Post $post): array
    {
        $legacyRedaction=static function(mixed $value) use (&$legacyRedaction): mixed {
            if (is_array($value)) { return array_map($legacyRedaction,$value); }
            return is_string($value)?preg_replace('/\[identidad reservada\]/iu','información reservada',$value):$value;
        };
        $meta=$legacyRedaction((array)get_post_meta($post->ID,'_ascla',true));
        // Old generated data may contain an anonymization placeholder as a whole list item.
        // Such entries are not meaningful concepts/frameworks and should simply disappear.
        foreach (['frameworks','norms','concepts','tags','conclusions'] as $field) {
            if (!isset($meta[$field]) || !is_array($meta[$field])) { continue; }
            $meta[$field]=array_values(array_filter($meta[$field],static function($value){
                if (!is_string($value)) { return true; }
                $plain=mb_strtolower(remove_accents(trim($value," \t\n\r\0\x0B.,;:–—-")));
                return !in_array($plain,['participante','participantes','dato reservado','identidad reservada','informacion reservada','una persona','persona'],true);
            }));
        }
        if ($post->post_type==='ascla_resource' && !empty($meta['video_id'])) {
            foreach (['summary','technical_note'] as $field) {
                if (!is_string($meta[$field]??null)) { continue; }
                $text=$meta[$field];
                $text=preg_replace('/(^|[.!?]\s+)La transcripci[oó]n\b/u','$1El video',$text)??$text;
                $text=preg_replace('/\b(?:la|esta) transcripci[oó]n\b/iu','el video',$text)??$text;
                $text=preg_replace('/\btranscripci[oó]n\b/iu','contenido del video',$text)??$text;
                $meta[$field]=trim($text);
            }
        }
        if (!current_user_can('ascla_moderate') && !Access::canPublish()) { unset($meta['transcript'],$meta['identities'],$meta['invitees'],$meta['moderation'],$meta['transcript_error'],$meta['transcript_mode'],$meta['transcript_checked_at']); }
        $author=get_userdata($post->post_author);
        $date=$post->post_date_gmt;
        if (!$date || str_starts_with($date,'0000-')) { $date=get_gmt_from_date($post->post_date); }
        $terms=wp_get_object_terms($post->ID,['ascla_interest','ascla_category','ascla_tag']);
        return ['id'=>$post->ID,'can_delete'=>self::canDelete($post),'type'=>substr($post->post_type,6),'title'=>$legacyRedaction($post->post_title),'body'=>$legacyRedaction($post->post_content),'status'=>$post->post_status,'author'=>['id'=>(int)$post->post_author,'name'=>$author?Profiles::publicName((int)$author->ID):'ASCLA'],'date'=>$date,'parent'=>(int)$post->post_parent,'meta'=>$meta,'media'=>Media::metadata($post->ID,(array)($meta['media_ids']??[])),'tags'=>is_wp_error($terms)?[]:array_map(static fn($t)=>['id'=>$t->term_id,'name'=>$t->name,'taxonomy'=>$t->taxonomy],$terms),'reactions'=>Store::count('relations',"target_id=%d AND kind='like'",[$post->ID]),'liked'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='like'",[$post->ID,get_current_user_id()])>0,'following'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='follow'",[$post->ID,get_current_user_id()])>0,'comments'=>(int)$post->comment_count,'url'=>Catalog::url(self::page(substr($post->post_type,6)),['item'=>$post->ID])];
    }
    public static function page(string $type): string { return ['resource'=>'centro-conocimiento','event'=>'eventos','topic'=>'foros','forum'=>'foros','gallery'=>'galeria','ally'=>'aliados','contact'=>'contacto'][$type]??'hub'; }
    public static function listing(string $type,array $filter=[]): array
    {
        Access::require(isset(Catalog::TYPES[$type]),'Sección no válida.',400);
        $page=max(1,min(10000,(int)($filter['page']??1))); $mine=!empty($filter['mine']);
        $status=($mine||current_user_can('ascla_moderate'))?['publish','draft','pending','ascla_rejected','ascla_hidden','private']:['publish'];
        if (!empty($filter['status']) && in_array($filter['status'],$status,true)) { $status=[$filter['status']]; }
        $args=['post_type'=>'ascla_'.$type,'post_status'=>$status,'posts_per_page'=>18,'paged'=>$page,'s'=>Access::text($filter['q']??'',150),'orderby'=>$type==='topic'?'modified':'date','order'=>'DESC'];
        if ($mine || ($type==='contact'&&!current_user_can('ascla_moderate'))) { $args['author']=get_current_user_id(); }
        elseif (!current_user_can('ascla_moderate')) { $args['post_status']=['publish']; }
        if (!empty($filter['author']) && !$mine && ($type!=='contact'||current_user_can('ascla_moderate'))) { $args['author']=absint($filter['author']); }
        if (isset($filter['parent']) && $filter['parent']!=='') { $args['post_parent']=absint($filter['parent']); }
        if (!empty($filter['after']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$filter['after'])) { $args['date_query']=[['after'=>$filter['after'],'inclusive'=>true]]; }
        $recommended=$type==='resource' && !empty($filter['recommended']);
        if ($recommended) { $args['post_status']=['publish']; }
        if ($type==='resource' && !empty($filter['resource_type'])) { $args['meta_query'][]=['key'=>'_ascla_resource_type','value'=>Access::text($filter['resource_type'],30)]; }
        if ($type==='event') {
            if (!current_user_can('ascla_moderate') && !$mine) {
                $args['meta_query'][]=['relation'=>'OR',['key'=>'_ascla_micro','value'=>'0'],['key'=>'_ascla_micro','compare'=>'NOT EXISTS'],['key'=>'_ascla_invitee','value'=>get_current_user_id()]];
            }
            if (array_key_exists('past',$filter)) { $args['meta_query'][]=['key'=>'_ascla_end','value'=>gmdate('c'),'compare'=>!empty($filter['past'])?'<':'>=']; }
        }
        $args=\ASCLA\Core\Repositories\ContentQuery::filters($args,$type,$filter);
        if ($recommended) {
            $perPage=max(1,min(100,(int)($filter['per_page']??18)));
            $args['posts_per_page']=-1;$args['paged']=1;$args['no_found_rows']=true;
            $query=new \WP_Query($args);$ranked=[];
            foreach ($query->posts as $post) {
                if (!self::canRead($post)) { continue; }
                $rank=KnowledgeRecommendations::score($post);
                if (($rank['relevance']??0)<=0) { continue; }
                $item=self::serialize($post);
                if (!empty($filter['resource_type']) && ($item['meta']['resource_type']??'')!==$filter['resource_type']) { continue; }
                $ranked[]=['item'=>$item,'score'=>(int)$rank['score'],'timestamp'=>strtotime($post->post_date_gmt.' UTC')?:0];
            }
            usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score'] ?: $b['timestamp']<=>$a['timestamp'] ?: $b['item']['id']<=>$a['item']['id']);
            $total=count($ranked);$offset=($page-1)*$perPage;
            return ['items'=>array_column(array_slice($ranked,$offset,$perPage),'item'),'page'=>$page,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage))];
        }
        // Private editorial content is additionally checked through canRead.
        $query=new \WP_Query($args); $items=[];
        foreach ($query->posts as $post) {
            if (!self::canRead($post)) { continue; }
            $item=self::serialize($post);
            if (!empty($filter['resource_type']) && ($item['meta']['resource_type']??'')!==$filter['resource_type']) { continue; }
            $items[]=$item;
        }
        return ['items'=>$items,'page'=>$page,'total'=>(int)$query->found_posts,'pages'=>(int)$query->max_num_pages];
    }
    public static function save(string $type,array $input,int $id=0): array
    {
        Access::require(isset(Catalog::TYPES[$type]),'Tipo no válido.',400);
        $editor=current_user_can('ascla_moderate');
        $publisher=Access::canPublish();
        Access::require(!in_array($type,['gallery','resource','event'],true)||$publisher,'Solo un Ejecutivo o un administrador pueden crear o editar eventos, Galería y Centro de Conocimiento.',403);
        Access::require($editor||in_array($type,['hub','topic','gallery','contact'],true),'Se requiere moderación.',403);
        if ($id) {
            $post=self::get($id); Access::require($post->post_type==='ascla_'.$type && ($editor||(int)$post->post_author===get_current_user_id()),'No puede editar este contenido.');
        }
        $title=trim(Access::text($input['title']??'',200)); $body=trim(Access::text($input['body']??'',30000));
        Access::require($title!=='' && $body!=='','Complete título y contenido.',400);
        $meta=ContentMeta::sanitize($type,(array)($input['meta']??[]),$id);
        if (!empty($meta['generated']) && isset($input['tag_names'])) { $meta['tags']=array_map(static fn($name)=>Access::text($name,60),array_slice((array)$input['tag_names'],0,20)); }
        $taxonomies=[];
        foreach (['interest','category','tag'] as $tax) {
            Access::require(!isset($input[$tax])||is_array($input[$tax]),'Categoría no válida.',400);
            $ids=array_values(array_unique(array_map('absint',$input[$tax]??[])));
            foreach ($ids as $tid) { Access::require((bool)term_exists($tid,'ascla_'.$tax),'Categoría no válida.',400); }
            $taxonomies[$tax]=$ids;
        }
        Access::require(!isset($input['tag_names'])||is_array($input['tag_names']),'Etiquetas no válidas.',400);
        $tagNames=array_map(static fn($name)=>trim(Access::text($name,60)),array_slice($input['tag_names']??[],0,20));
        foreach ($meta['media_ids']??[] as $media) {
            $file=Store::one('media',(int)$media);
            Access::require($file && ((int)$file['user_id']===get_current_user_id()||($id&&(int)$file['post_id']===$id)),'Archivo no autorizado.');
        }
        $requested=$input['status']??'pending';
        Access::require(in_array($requested,['draft','pending','publish'],true),'Estado no válido.',400); $status=$requested==='draft'?'draft':'pending';
        $directGeneratedPublish=$type==='resource' && $publisher && $requested==='publish' && !empty($meta['generated']);
        if ($type==='contact') { $status='private'; }
        elseif (($type==='event' && empty($meta['micro']) && empty($meta['generated']) || in_array($type,['topic','forum'],true)) && $requested!=='draft') { $status='publish'; }
        elseif (($editor || ($publisher && in_array($type,['gallery','resource'],true))) && $requested==='publish' && (empty($meta['generated']) || $directGeneratedPublish)) { $status='publish'; }
        elseif (!$editor && !Settings::get()['moderation_required'] && $requested!=='draft' && $type==='hub') { $status='publish'; }
        $parent=absint($input['parent']??0);
        if ($parent) { $p=self::get($parent); Access::require($type==='topic' && $p->post_type==='ascla_forum','Foro no válido.',400); }
        if ($id && !empty($meta['generated']) && !$directGeneratedPublish) { $meta['reviewed']=false; }
        if ($directGeneratedPublish) { $meta['reviewed']=true; }
        $postData=['post_type'=>'ascla_'.$type,'post_title'=>$title,'post_content'=>$body,'post_status'=>$status,'post_parent'=>$parent,'comment_status'=>'open'];
        if ($id) { $postData['ID']=$id; } else { $postData['post_author']=get_current_user_id(); }
        // Store as draft first so publication hooks see validated metadata.
        $postData['post_status']='draft';
        $saved=$id?wp_update_post(wp_slash($postData),true):wp_insert_post(wp_slash($postData),true); Access::require(!is_wp_error($saved),'No se pudo guardar el contenido.',500);
        update_post_meta($saved,'_ascla',$meta);
        foreach ($taxonomies as $tax=>$ids) { wp_set_object_terms($saved,$ids,'ascla_'.$tax); }
        if ($tagNames) { self::tags($saved,$tagNames); }
        foreach ($meta['media_ids']??[] as $media) { Media::attach($media,$saved); }
        wp_update_post(['ID'=>$saved,'post_status'=>$status]);
        if ($type==='resource' && !empty($meta['video_id'])) {
            // Best-effort automatic duration/thumbnail refresh. Saving must still succeed if YouTube is unavailable.
            Knowledge::autoVideoMetadata((int)$saved);
        }
        Audit::record('content_saved',$saved,$status);
        return self::serialize(get_post($saved));
    }
    public static function canDelete(\WP_Post $post): bool
    {
        return Access::member() && str_starts_with($post->post_type,'ascla_') && isset(Catalog::TYPES[substr($post->post_type,6)]) && $post->post_status!=='trash'
            && (current_user_can('ascla_manage') || (int)$post->post_author===get_current_user_id());
    }
    /** Trash the selected contribution; other authors' forum topics remain in the general list. */
    public static function remove(int $id): array
    {
        $post=self::get($id); Access::require(self::canDelete($post),'Solo el autor o un administrador puede eliminar este contenido.',403);
        return Store::lock('content:'.$id,static function()use($id,$post){
            $meta=(array)get_post_meta($id,'_ascla',true);
            Access::require((bool)wp_trash_post($id),'No se pudo eliminar el contenido.',500);
            if($post->post_type==='ascla_event' && !empty($meta['micro'])) {
                MicroEvents::forget($id);
                Store::delete('registrations',['event_id'=>$id]);
            }
            if($post->post_type==='ascla_forum') {
                foreach(get_posts(['post_type'=>'ascla_topic','post_parent'=>$id,'post_status'=>['publish','pending','draft','ascla_hidden','ascla_rejected'],'numberposts'=>-1]) as $child) wp_update_post(['ID'=>$child->ID,'post_parent'=>0]);
            }
            foreach(['like','follow','report'] as $kind) Store::delete('relations',['target_id'=>$id,'kind'=>$kind]);
            Audit::record('content_trashed',$id,$post->post_type);
            return ['id'=>$id,'deleted'=>true,'message'=>'Contenido enviado a la papelera.'];
        });
    }
    public static function removeComment(int $id): array
    {
        $comment=get_comment($id);Access::require($comment && !in_array((string)$comment->comment_approved,['trash','post-trashed'],true),'Comentario no encontrado.',404);
        self::get((int)$comment->comment_post_ID);
        Access::require(current_user_can('ascla_manage') || (int)$comment->user_id===get_current_user_id(),'Solo el autor o un administrador puede eliminar este comentario.',403);
        Access::require((bool)wp_trash_comment($id),'No se pudo eliminar el comentario.',500);
        Store::delete('relations',['target_id'=>$id,'kind'=>'comment_like']);
        Store::delete('relations',['target_id'=>$id,'kind'=>'comment_report']);
        Audit::record('comment_trashed',$id);
        return ['id'=>$id,'deleted'=>true];
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
    public static function comments(int $id): array
    {
        self::get($id); $comments=get_comments(['post_id'=>$id,'status'=>'approve','include_unapproved'=>[get_current_user_id()],'number'=>100,'order'=>'ASC']);
        $me=get_current_user_id();
        return array_map(static function($c) use($me) {
            $cid=(int)$c->comment_ID;
            return [
                'id'=>$cid,
                'parent'=>(int)$c->comment_parent,
                'author_id'=>(int)$c->user_id,
                'can_delete'=>current_user_can('ascla_manage')||(int)$c->user_id===$me,
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
        Access::limit('comment',15); $post=self::get($id); Access::require($post->post_status==='publish','El contenido aún no está publicado.',400);
        $body=trim(Access::text($body,5000)); Access::require($body!=='','Escriba un comentario.',400);
        if ($parent>0) {
            $parentComment=get_comment($parent);
            Access::require($parentComment && (int)$parentComment->comment_post_ID===$id && (string)$parentComment->comment_approved==='1','El comentario al que intentas responder ya no está disponible.',404);
        }
        $approved=in_array($post->post_type,['ascla_topic','ascla_forum'],true)||current_user_can('ascla_moderate')||!Settings::get()['moderate_comments'];
        $user=wp_get_current_user();
        $cid=wp_insert_comment(wp_slash(['comment_post_ID'=>$id,'comment_parent'=>$parent,'user_id'=>$user->ID,'comment_author'=>$user->display_name,'comment_content'=>$body,'comment_approved'=>$approved?1:0,'comment_type'=>'comment']));
        Access::require((int)$cid>0,'No se pudo guardar el comentario.',500);
        if ($approved) { self::notifyComment((int)$cid); }
        wp_update_post(['ID'=>$id,'post_modified'=>current_time('mysql')]);
        return ['id'=>(int)$cid,'status'=>$approved?'publish':'pending'];
    }
    public static function reactComment(int $id,bool $active): array
    {
        $comment=get_comment($id);
        Access::require($comment && (string)$comment->comment_approved==='1','Comentario no encontrado.',404);
        self::get((int)$comment->comment_post_ID);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>'comment_like'];
        Store::lock('comment-reaction:'.get_current_user_id().':'.$id,static function()use($where,$active){
            if ($active && !Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) {
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
        $url=Catalog::url(self::page(substr($post->post_type,6)),['item'=>$post->ID]);
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
        $postAuthor=(int)$post->post_author;
        if ($postAuthor>0 && $postAuthor!==$actor && $postAuthor!==$parentAuthor) {
            $actorName=$actor>0?Profiles::publicName($actor):'Un asociado';
            Notifications::once($postAuthor,'comment:'.$id,'comment',$actorName.' comentó tu publicación.',$url,['type'=>'post','id'=>$post->ID,'actor'=>$actor]);
        }
    }
    public static function reportComment(int $id,string $reason,string $detail=''): array
    {
        Access::limit('comment_report',8,300);
        $comment=get_comment($id);
        Access::require($comment && (string)$comment->comment_approved==='1','Comentario no encontrado.',404);
        $post=self::get((int)$comment->comment_post_ID);
        Access::require($post->post_status==='publish','Contenido no publicado.',400);
        Access::require((int)$comment->user_id!==get_current_user_id(),'No puedes reportar tu propio comentario.',400);
        $reason=trim(Access::text($reason,64));
        Access::require(isset(self::REPORT_REASONS[$reason]),'Selecciona un motivo de reporte válido.',400);
        $detail=trim(Access::text($detail,1000));
        Access::require($reason!=='other' || $detail!=='','Describe brevemente el motivo del reporte cuando selecciones “Otro motivo”.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>'comment_report'];
        Store::lock('comment-report:'.implode(':',$where),static function () use($where,$reason,$detail,$comment) {
            $existing=Store::rows('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where),'LIMIT 1')[0]??null;
            $data=['reason'=>$reason,'detail'=>$detail,'reviewed_at'=>null,'reviewed_by'=>0,'created_at'=>current_time('mysql',true)];
            if ($existing) Store::update('relations',$data,['id'=>(int)$existing['id']]);
            else Store::insert('relations',$where+$data);
            Audit::record('comment_reported',(int)$comment->comment_ID,self::reportLabel($reason));
        });
        return ['reported'=>true,'reason'=>$reason,'reason_label'=>self::reportLabel($reason)];
    }
    public static function react(int $id,string $kind,bool $active): array
    {
        Access::require(in_array($kind,['like','follow'],true),'Acción no válida.',400);
        $post=self::get($id); Access::require($post->post_status==='publish','Contenido no publicado.',400);
        if ($kind==='follow' && $active && $post->post_type==='ascla_event') {
            $meta=(array)get_post_meta($id,'_ascla',true); $end=strtotime((string)($meta['end']??''));
            Access::require($end===false || $end>time(),'No puedes seguir un evento que ya finalizó.',400);
        }
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>$kind];
        Store::lock('reaction:'.implode(':',$where),static function () use($where,$active,$post,$kind) {
            if ($active && !Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) {
                Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);
                if ($kind==='like') { Notifications::send((int)$post->post_author,'reaction','Tu publicación recibió una reacción.',Catalog::url(self::page(substr($post->post_type,6)),['item'=>$post->ID]),['type'=>'post','id'=>$post->ID,'actor'=>get_current_user_id()]); }
            } elseif (!$active) { Store::delete('relations',$where); }
        });
        return ['active'=>$active];
    }
    public static function report(int $id,string $reason,string $detail=''): array
    {
        Access::limit('content_report',8,300);
        $post=self::get($id);
        Access::require($post->post_status==='publish','Contenido no publicado.',400);
        Access::require((int)$post->post_author!==get_current_user_id(),'No puedes reportar tu propia publicación.',400);
        $reason=trim(Access::text($reason,64));
        Access::require(isset(self::REPORT_REASONS[$reason]),'Selecciona un motivo de reporte válido.',400);
        $detail=trim(Access::text($detail,1000));
        Access::require($reason!=='other' || $detail!=='','Describe brevemente el motivo del reporte cuando selecciones “Otro motivo”.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>'report'];
        Store::lock('report:'.implode(':',$where),static function () use($where,$reason,$detail,$post) {
            $existing=Store::rows('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where),'LIMIT 1')[0]??null;
            $data=['reason'=>$reason,'detail'=>$detail,'reviewed_at'=>null,'reviewed_by'=>0,'created_at'=>current_time('mysql',true)];
            if ($existing) Store::update('relations',$data,['id'=>(int)$existing['id']]);
            else Store::insert('relations',$where+$data);
            Audit::record('content_reported',$post->ID,self::reportLabel($reason));
        });
        return ['reported'=>true,'reason'=>$reason,'reason_label'=>self::reportLabel($reason)];
    }
    public static function reviewReport(int $id): array
    {
        Access::require(current_user_can('ascla_moderate'),'No tienes permisos para revisar reportes.',403);
        $report=Store::one('relations',$id);
        Access::require($report && in_array((string)$report['kind'],['report','comment_report'],true),'Reporte no encontrado.',404);
        $when=current_time('mysql',true);
        Store::update('relations',['reviewed_at'=>$when,'reviewed_by'=>get_current_user_id()],['id'=>$id]);
        Audit::record('report_reviewed',$id,(string)$report['kind']);
        return ['id'=>$id,'reviewed'=>true,'reviewed_at'=>$when];
    }
    public static function moderate(int $id,string $decision,string $reason,bool $reviewed=false): array
    {
        Access::require(current_user_can('ascla_moderate'));
        $post=self::get($id); $statuses=['approve'=>'publish','reject'=>'ascla_rejected','hide'=>'ascla_hidden','suspend'=>'ascla_hidden'];
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
        Notifications::send((int)$post->post_author,'moderation','Tu publicación fue '.($decision==='approve'?'aprobada':'revisada').'.',Catalog::url(self::page(substr($post->post_type,6)),['item'=>$id]),['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]);
        return self::serialize(get_post($id));
    }
    public static function guardPublication(array $data,array $postarr): array
    {
        if (($data['post_status']??'')==='publish' && in_array($data['post_type']??'',['ascla_gallery','ascla_resource','ascla_event'],true) && !Access::canPublish() && (empty($postarr['ID']) || get_post_status($postarr['ID'])!=='publish')) $data['post_status']='pending';
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
