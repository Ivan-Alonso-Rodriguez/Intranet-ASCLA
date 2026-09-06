<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Media
{
    public static function boot(): void { add_action('admin_post_ascla_media',[self::class,'serve']); }
    public static function url(int $id): string { return add_query_arg(['action'=>'ascla_media','id'=>$id],admin_url('admin-post.php')); }
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
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,name,mime FROM '.Store::table('media')." WHERE post_id=%d AND id IN ($in) ORDER BY id LIMIT 12",$post,...$ids),ARRAY_A);
        return array_map(static fn($row)=>['id'=>(int)$row['id'],'name'=>$row['name'],'mime'=>$row['mime'],'url'=>self::url((int)$row['id'])],$rows);
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
