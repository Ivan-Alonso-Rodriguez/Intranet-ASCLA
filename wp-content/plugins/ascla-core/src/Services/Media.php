<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Media
{
    public static function boot(): void { add_action('admin_post_ascla_media',[self::class,'serve']); }
    public static function url(int $id): string { return add_query_arg(['action'=>'ascla_media','id'=>$id],admin_url('admin-post.php')); }
    public static function profilePhotoUrl(int $id,int $owner): string
    {
        global $wpdb;
        $valid=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Store::table('media')." WHERE id=%d AND user_id=%d AND mime IN ('image/jpeg','image/png','image/webp')",$id,$owner));
        return $valid?self::url($id):'';
    }
    public static function requireOwned(int $id,int $user,bool $image=false): void
    {
        $row=Store::one('media',$id);
        Access::require($row && (int)$row['user_id']===$user && (!$image||str_starts_with($row['mime'],'image/')),'Archivo no autorizado.');
    }
    public static function attach(int $id,int $post): void
    {
        $file=Store::one('media',$id);
        Access::require($file && ((int)$file['user_id']===get_current_user_id()||(int)$file['post_id']===$post),'Archivo no autorizado.');
        Store::update('media',['post_id'=>$post],['id'=>$id]);
    }
    public static function metadata(int $post,array $ids): array
    {
        global $wpdb;
        $ids=array_values(array_filter(array_map('absint',array_slice($ids,0,12))));
        if (!$ids) { return []; }
        $in=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,name,mime,user_id FROM '.Store::table('media')." WHERE post_id=%d AND id IN ($in) ORDER BY id LIMIT 12",$post,...$ids),ARRAY_A);
        return array_map(static fn($row)=>['id'=>(int)$row['id'],'name'=>$row['name'],'mime'=>$row['mime'],'can_delete'=>current_user_can('ascla_manage')||(int)$row['user_id']===get_current_user_id(),'url'=>self::url((int)$row['id'])],$rows);
    }
    public static function listing(array $filter=[]): array
    {
        Access::require(Access::member());global $wpdb;
        $page=max(1,min(10000,(int)($filter['page']??1)));$query=Access::text($filter['q']??'',120);
        $where=current_user_can('ascla_manage')?'1=1':$wpdb->prepare('user_id=%d',get_current_user_id());
        if($query!=='')$where.=$wpdb->prepare(' AND name LIKE %s','%'.$wpdb->esc_like($query).'%');
        $table=Store::table('media');$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,user_id,post_id,name,mime,created_at,OCTET_LENGTH(bytes) AS size FROM $table WHERE $where ORDER BY id DESC LIMIT 20 OFFSET %d",($page-1)*20),ARRAY_A);
        return ['items'=>array_map(static fn($row)=>['id'=>(int)$row['id'],'name'=>$row['name'],'mime'=>$row['mime'],'size'=>(int)$row['size'],'author'=>Profiles::publicName((int)$row['user_id']),'post_id'=>(int)$row['post_id'],'date'=>$row['created_at'],'url'=>self::url((int)$row['id'])],$rows),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/20))];
    }
    /** The private blob has a single owner. Remove all references before deleting it. */
    public static function remove(int $id): array
    {
        Access::require(Access::member());$file=Store::one('media',$id);Access::require((bool)$file,'Archivo no encontrado.',404);
        Access::require(current_user_can('ascla_manage') || (int)$file['user_id']===get_current_user_id(),'Solo el propietario o un administrador puede eliminar este archivo.',403);
        return Store::lock('media:'.$id,static function()use($id,$file){
            global $wpdb;
            // Metadata is serialized; inspect candidate rows rather than doing an unsafe string replacement.
            $posts=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value LIKE %s",'_ascla','%media_ids%'));
            foreach($posts as $postId){
                $meta=(array)get_post_meta($postId,'_ascla',true);$ids=array_map('intval',(array)($meta['media_ids']??[]));
                if(in_array($id,$ids,true)){$meta['media_ids']=array_values(array_diff($ids,[$id]));update_post_meta($postId,'_ascla',$meta);}
            }
            $profile=(array)get_user_meta($file['user_id'],'_ascla_profile',true);
            if((int)($profile['photo_id']??0)===$id){$profile['photo_id']=0;$profile['revision']=(int)($profile['revision']??0)+1;update_user_meta($file['user_id'],'_ascla_profile',$profile);}
            Store::delete('media',['id'=>$id]);Audit::record('media_deleted',$id);
            return ['id'=>$id,'deleted'=>true];
        });
    }
    public static function upload(array $file): array
    {
        Access::limit('upload',10,300);
        Access::require(isset($file['tmp_name']) && ($file['error']??1)===UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']),'Carga incompleta.',400);
        $size=filesize($file['tmp_name']); Access::require($size>0&&$size<=5*1024*1024,'Límite de 5 MB por archivo.',400);
        $allowed=['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],$allowed);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        Access::require($check['ext']&&$check['type']===$mime,'Sólo JPG, PNG, WebP y PDF verificados.',400);
        if (str_starts_with($mime,'image/')) {
            $dim=getimagesize($file['tmp_name']); Access::require($dim && $dim[0]*$dim[1]<=20000000 && $size<=3*1024*1024,'Imagen demasiado grande (3 MB / 20 MP máximo).',400);
        }
        $id=Store::insert('media',['user_id'=>get_current_user_id(),'post_id'=>0,'name'=>sanitize_file_name($file['name']),'mime'=>$mime,'bytes'=>file_get_contents($file['tmp_name']),'created_at'=>current_time('mysql',true)]);
        return ['id'=>$id,'url'=>self::url($id),'name'=>sanitize_file_name($file['name']),'mime'=>$mime];
    }
    public static function serve(): void
    {
        try {
            Access::require(Access::member()); $id=absint($_GET['id']??0); $row=Store::one('media',$id); Access::require((bool)$row,'Archivo no encontrado.',404);
            $allowed=(int)$row['user_id']===get_current_user_id()||current_user_can('ascla_moderate');
            if (!$allowed && $row['post_id']) { $post=get_post($row['post_id']); $allowed=$post && Content::canRead($post); }
            if (!$allowed && str_starts_with($row['mime'],'image/')) {
                try { $profile=Profiles::visible((int)$row['user_id']); $allowed=(int)($profile['photo_id']??0)===$id; } catch (\Throwable $e) { $allowed=false; }
            }
            Access::require($allowed,'Archivo no encontrado.',404); nocache_headers();
            header('Content-Type: '.$row['mime']); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Content-Disposition: '.($row['mime']==='application/pdf'?'attachment':'inline').'; filename="'.sanitize_file_name($row['name']).'"');
            echo $row['bytes']; exit;
        } catch (\Throwable $e) { wp_die('Archivo no disponible.','ASCLA',['response'=>404]); }
    }
}
