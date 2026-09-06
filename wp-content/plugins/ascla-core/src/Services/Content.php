<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\Store;

final class Content
{
    public static function indexMeta(int $metaId,int $postId,string $key,mixed $value): void
    {
        if ($key!=='_ascla' || !is_array($value)) { return; }
        foreach (['resource_type','start','end','source'] as $field) { update_post_meta($postId,'_ascla_'.$field,$value[$field]??''); }
        update_post_meta($postId,'_ascla_micro',empty($value['micro'])?'0':'1');
        delete_post_meta($postId,'_ascla_invitee');
        foreach (array_unique(array_map('absint',$value['invitees']??[])) as $uid) { add_post_meta($postId,'_ascla_invitee',$uid); }
    }
    public static function canRead(\WP_Post $post): bool
    {
        if (!Access::member() || !str_starts_with($post->post_type,'ascla_')) { return false; }
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
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        if (!current_user_can('ascla_moderate')) { unset($meta['transcript'],$meta['identities'],$meta['invitees'],$meta['moderation']); }
        $author=get_userdata($post->post_author);
        $date=$post->post_date_gmt;
        if (!$date || str_starts_with($date,'0000-')) { $date=get_gmt_from_date($post->post_date); }
        $terms=wp_get_object_terms($post->ID,['ascla_interest','ascla_category','ascla_tag']);
        return ['id'=>$post->ID,'type'=>substr($post->post_type,6),'title'=>$post->post_title,'body'=>$post->post_content,'status'=>$post->post_status,'author'=>['id'=>(int)$post->post_author,'name'=>$author?$author->display_name:'ASCLA'],'date'=>$date,'parent'=>(int)$post->post_parent,'meta'=>$meta,'media'=>Media::metadata($post->ID,(array)($meta['media_ids']??[])),'tags'=>is_wp_error($terms)?[]:array_map(static fn($t)=>['id'=>$t->term_id,'name'=>$t->name,'taxonomy'=>$t->taxonomy],$terms),'reactions'=>Store::count('relations',"target_id=%d AND kind='like'",[$post->ID]),'liked'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='like'",[$post->ID,get_current_user_id()])>0,'following'=>Store::count('relations',"target_id=%d AND user_id=%d AND kind='follow'",[$post->ID,get_current_user_id()])>0,'comments'=>(int)$post->comment_count,'url'=>Catalog::url(self::page(substr($post->post_type,6)),['item'=>$post->ID])];
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
        if ($type==='resource' && !empty($filter['recommended'])) {
            $profile=Profiles::raw(get_current_user_id());
            if (!empty($profile['interests'])) { $args['tax_query'][]=['taxonomy'=>'ascla_interest','field'=>'term_id','terms'=>$profile['interests']]; }
        }
        if ($type==='resource' && !empty($filter['resource_type'])) { $args['meta_query'][]=['key'=>'_ascla_resource_type','value'=>Access::text($filter['resource_type'],30)]; }
        if ($type==='event') {
            if (!current_user_can('ascla_moderate') && !$mine) {
                $args['meta_query'][]=['relation'=>'OR',['key'=>'_ascla_micro','value'=>'0'],['key'=>'_ascla_micro','compare'=>'NOT EXISTS'],['key'=>'_ascla_invitee','value'=>get_current_user_id()]];
            }
            if (array_key_exists('past',$filter)) { $args['meta_query'][]=['key'=>'_ascla_end','value'=>gmdate('c'),'compare'=>!empty($filter['past'])?'<':'>=']; }
        }
        $args=\ASCLA\Core\Repositories\ContentQuery::filters($args,$type,$filter);
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
        Access::require($editor||in_array($type,['hub','topic','gallery','contact'],true),'Se requiere moderación.',403);
        if ($id) {
            $post=self::get($id); Access::require($post->post_type==='ascla_'.$type && ($editor||(int)$post->post_author===get_current_user_id()),'No puede editar este contenido.');
        }
        $title=trim(Access::text($input['title']??'',200)); $body=trim(Access::text($input['body']??'',30000));
        Access::require($title!=='' && $body!=='','Complete título y contenido.',400);
        $meta=ContentMeta::sanitize($type,(array)($input['meta']??[]),$id);
        if (!empty($meta['generated']) && isset($input['tag_names'])) { $meta['tags']=array_map(static fn($name)=>Access::text($name,60),array_slice((array)$input['tag_names'],0,20)); }
        $requested=$input['status']??'pending'; $status=$requested==='draft'?'draft':'pending';
        if ($type==='contact') { $status='private'; }
        elseif ($editor && $requested==='publish' && empty($meta['generated'])) { $status='publish'; }
        elseif (!$editor && !Settings::get()['moderation_required'] && $requested!=='draft' && $type==='hub') { $status='publish'; }
        $parent=absint($input['parent']??0);
        if ($parent) { $p=self::get($parent); Access::require($type==='topic' && $p->post_type==='ascla_forum','Foro no válido.',400); }
        if ($id && !empty($meta['generated'])) { $meta['reviewed']=false; }
        $postData=['post_type'=>'ascla_'.$type,'post_title'=>$title,'post_content'=>$body,'post_status'=>$status,'post_parent'=>$parent,'comment_status'=>'open'];
        if ($id) { $postData['ID']=$id; } else { $postData['post_author']=get_current_user_id(); }
        // Store as draft first so publication hooks see validated metadata.
        $postData['post_status']='draft';
        $saved=wp_insert_post(wp_slash($postData),true); Access::require(!is_wp_error($saved),'No se pudo guardar el contenido.',500);
        update_post_meta($saved,'_ascla',$meta);
        foreach (['interest','category','tag'] as $tax) {
            $ids=array_values(array_unique(array_map('absint',(array)($input[$tax]??[]))));
            foreach ($ids as $tid) { Access::require((bool)term_exists($tid,'ascla_'.$tax),'Categoría no válida.',400); }
            wp_set_object_terms($saved,$ids,'ascla_'.$tax);
        }
        if (!empty($input['tag_names'])) { self::tags($saved,(array)$input['tag_names']); }
        foreach ($meta['media_ids']??[] as $media) { Media::attach($media,$saved); }
        wp_update_post(['ID'=>$saved,'post_status'=>$status]);
        Audit::record('content_saved',$saved,$status);
        return self::serialize(get_post($saved));
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
        self::get($id); $comments=get_comments(['post_id'=>$id,'status'=>'approve','number'=>100,'order'=>'ASC']);
        return array_map(static fn($c)=>['id'=>(int)$c->comment_ID,'author'=>$c->comment_author,'body'=>$c->comment_content,'date'=>$c->comment_date_gmt],$comments);
    }
    public static function comment(int $id,string $body): array
    {
        Access::limit('comment',15); $post=self::get($id); Access::require($post->post_status==='publish','El contenido aún no está publicado.',400);
        $body=trim(Access::text($body,5000)); Access::require($body!=='','Escriba un comentario.',400);
        $approved=current_user_can('ascla_moderate')||!Settings::get()['moderate_comments'];
        $user=wp_get_current_user();
        $cid=wp_insert_comment(wp_slash(['comment_post_ID'=>$id,'user_id'=>$user->ID,'comment_author'=>$user->display_name,'comment_content'=>$body,'comment_approved'=>$approved?1:0,'comment_type'=>'comment']));
        if ($approved) { Notifications::send((int)$post->post_author,'comment','Nuevo comentario en tu publicación.',Catalog::url(self::page(substr($post->post_type,6)),['item'=>$id])); }
        wp_update_post(['ID'=>$id,'post_modified'=>current_time('mysql')]);
        return ['id'=>$cid,'status'=>$approved?'publish':'pending'];
    }
    public static function react(int $id,string $kind,bool $active): array
    {
        Access::require(in_array($kind,['like','follow','report'],true),'Acción no válida.',400);
        $post=self::get($id); Access::require($post->post_status==='publish','Contenido no publicado.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>$kind];
        Store::lock('reaction:'.implode(':',$where),static function () use($where,$active,$post,$kind) {
            if ($active && !Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) {
                Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);
                if ($kind==='like') { Notifications::send((int)$post->post_author,'reaction','Tu publicación recibió una reacción.'); }
                if ($kind==='report') { Audit::record('content_reported',$post->ID); }
            } elseif (!$active) { Store::delete('relations',$where); }
        });
        return ['active'=>$active];
    }
    public static function moderate(int $id,string $decision,string $reason,bool $reviewed=false): array
    {
        Access::require(current_user_can('ascla_moderate'));
        $post=self::get($id); $statuses=['approve'=>'publish','reject'=>'ascla_rejected','hide'=>'ascla_hidden','suspend'=>'ascla_hidden'];
        Access::require(isset($statuses[$decision])&&$post->post_type!=='ascla_contact','Decisión no válida.',400);
        $reason=Access::text($reason,1000); Access::require(trim($reason)!=='','Indique un motivo de moderación.',400);
        $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($decision!=='approve'||empty($meta['generated'])||$reviewed,'Debe confirmar revisión de fuentes, identidades y derechos.',400);
        $meta['moderation']=['moderator'=>get_current_user_id(),'date'=>gmdate('c'),'decision'=>$decision,'reason'=>$reason];
        if ($reviewed) { $meta['reviewed']=true; }
        update_post_meta($id,'_ascla',$meta); wp_update_post(['ID'=>$id,'post_status'=>$statuses[$decision]]);
        Audit::record('moderation',$id,$decision);
        Notifications::send((int)$post->post_author,'moderation','Tu publicación fue '.($decision==='approve'?'aprobada':'revisada').'.',Catalog::url(self::page(substr($post->post_type,6)),['item'=>$id]));
        return self::serialize(get_post($id));
    }
    public static function guardPublication(array $data,array $postarr): array
    {
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
            foreach (array_unique($matches[1]) as $id) { Notifications::send((int)$id,'mention','Te mencionaron en el Hub ASCLA.',Catalog::url('hub',['item'=>$post->ID])); }
        }
    }
}
