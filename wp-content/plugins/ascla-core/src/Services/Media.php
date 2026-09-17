<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

final class Media
{
    private const TEMP_POST_ID=-1;
    private const TEMP_TTL=43200; // 12 h: abandoned uploads are never kept indefinitely.

    public static function boot(): void { add_action('admin_post_ascla_media',[self::class,'serve']); add_action('ascla_jobs',[self::class,'cleanupAbandoned'],30); }
    public static function url(int $id): string { return add_query_arg(['action'=>'ascla_media','id'=>$id],admin_url('admin-post.php')); }


    /** Hourly safety net for tabs/browsers that disappeared before explicit cancellation. */
    public static function cleanupAbandoned(): void
    {
        global $wpdb;
        $table=Store::table('media');
        $cutoff=gmdate('Y-m-d H:i:s',time()-self::TEMP_TTL);
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT m.id,m.user_id FROM $table AS m WHERE m.post_id=%d AND m.created_at<%s AND (m.original_id>0 OR NOT EXISTS (SELECT 1 FROM $table AS derivative WHERE derivative.original_id=m.id)) ORDER BY (m.original_id>0) DESC,m.id ASC LIMIT 500",
            self::TEMP_POST_ID,$cutoff
        ),ARRAY_A) ?: [];
        foreach ($rows as $row) { self::discardTemporary((int)$row['id'],(int)$row['user_id']); }
    }

    /** Remove old, never-committed uploads for the current owner. */
    private static function cleanupTemporary(int $user): void
    {
        if ($user<=0) return;
        global $wpdb;
        $table=Store::table('media');
        $cutoff=gmdate('Y-m-d H:i:s',time()-self::TEMP_TTL);
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare(
            "SELECT m.id FROM $table AS m WHERE m.user_id=%d AND m.post_id=%d AND m.created_at<%s AND (m.original_id>0 OR NOT EXISTS (SELECT 1 FROM $table AS derivative WHERE derivative.original_id=m.id)) ORDER BY (m.original_id>0) DESC,m.id ASC LIMIT 100",
            $user,self::TEMP_POST_ID,$cutoff
        )));
        foreach ($ids as $id) { self::discardTemporary($id,$user); }
    }

    private static function discardTemporary(int $id,int $user): bool
    {
        $file=Store::one('media',$id);
        if (!$file || (int)$file['user_id']!==$user || (int)$file['post_id']!==self::TEMP_POST_ID) return false;
        // Never remove something that has already been referenced despite a stale temporary flag.
        $profile=(array)get_user_meta($user,'_ascla_profile',true);
        if ((int)($profile['photo_id']??0)===$id) { self::commit($id,$user); return false; }
        global $wpdb;
        $conversations=Store::table('conversations');
        if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $conversations WHERE photo_id=%d",$id))>0) { self::commit($id,$user); return false; }
        $originalId=(int)($file['original_id']??0);
        Store::delete('media',['id'=>$id,'user_id'=>$user,'post_id'=>self::TEMP_POST_ID]);
        if ($originalId>0 && Store::count('media','original_id=%d',[$originalId])===0) {
            $source=Store::one('media',$originalId);
            if ($source && (int)$source['user_id']===$user && (int)$source['post_id']===self::TEMP_POST_ID) {
                Store::delete('media',['id'=>$originalId,'user_id'=>$user,'post_id'=>self::TEMP_POST_ID]);
            }
        }
        return true;
    }

    /** Explicitly discard an upload that the user cancelled before saving its form. */
    public static function discard(int $id): array
    {
        Access::require(Access::member());
        $user=get_current_user_id();
        return ['id'=>$id,'discarded'=>self::discardTemporary($id,$user)];
    }

    /** Promote a temporary private upload once a profile/group reference is successfully saved. */
    public static function commit(int $id,int $user=0): void
    {
        if ($id<=0) return;
        $user=$user?:get_current_user_id();
        $file=Store::one('media',$id);
        Access::require($file && (int)$file['user_id']===$user,'Archivo no autorizado.');
        if ((int)$file['post_id']===self::TEMP_POST_ID) { Store::update('media',['post_id'=>0],['id'=>$id]); }
        $originalId=(int)($file['original_id']??0);
        if ($originalId>0) {
            $source=Store::one('media',$originalId);
            if ($source && (int)$source['user_id']===$user && (int)$source['post_id']===self::TEMP_POST_ID) {
                Store::update('media',['post_id'=>0],['id'=>$originalId]);
            }
        }
    }

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
        $originalId=(int)($file['original_id']??0);
        if ($originalId>0) {
            $source=Store::one('media',$originalId);
            if ($source && (int)$source['user_id']===(int)$file['user_id'] && (int)$source['post_id']===self::TEMP_POST_ID) { Store::update('media',['post_id'=>0],['id'=>$originalId]); }
        }
    }

    public static function metadata(int $post,array $ids): array
    {
        global $wpdb;
        $ids=array_values(array_filter(array_map('absint',array_slice($ids,0,12))));
        if (!$ids) { return []; }
        $in=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,name,mime,user_id,original_id FROM '.Store::table('media')." WHERE post_id=%d AND id IN ($in) ORDER BY id LIMIT 12",$post,...$ids),ARRAY_A);
        return array_map(static fn($row)=>[
            'id'=>(int)$row['id'],
            'name'=>$row['name'],
            'mime'=>$row['mime'],
            'original_id'=>(int)($row['original_id']??0),
            'can_delete'=>current_user_can('ascla_manage')||(int)$row['user_id']===get_current_user_id(),
            'url'=>self::url((int)$row['id'])
        ],$rows);
    }

    /**
     * The library exposes the display-ready file and hides its preserved uncropped master.
     * The master still counts toward storage and is removed together with the display derivative.
     */
    public static function listing(array $filter=[]): array
    {
        Access::require(Access::member());
        global $wpdb;
        $page=max(1,min(10000,(int)($filter['page']??1)));
        $query=Access::text($filter['q']??'',120);
        $table=Store::table('media');
        self::cleanupTemporary(get_current_user_id());
        $scope=Access::text($filter['scope']??'mine',20);
        $all=$scope==='all' && current_user_can('ascla_manage');
        $owner=$all?absint($filter['owner']??0):get_current_user_id();
        $where=($all?'1=1':$wpdb->prepare('m.user_id=%d',get_current_user_id())).$wpdb->prepare(' AND m.post_id<>%d',self::TEMP_POST_ID);
        if($all && $owner>0)$where.=$wpdb->prepare(' AND m.user_id=%d',$owner);
        if($query!=='')$where.=$wpdb->prepare(' AND m.name LIKE %s','%'.$wpdb->esc_like($query).'%');
        $where.=" AND NOT EXISTS (SELECT 1 FROM $table AS derivative WHERE derivative.original_id=m.id)";
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table AS m WHERE $where");
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.user_id,m.post_id,m.name,m.mime,m.original_id,m.created_at,OCTET_LENGTH(m.bytes) AS size,(SELECT OCTET_LENGTH(source.bytes) FROM $table AS source WHERE source.id=m.original_id) AS original_size FROM $table AS m WHERE $where ORDER BY m.id DESC LIMIT 20 OFFSET %d",($page-1)*20),ARRAY_A);
        return [
            'items'=>array_map(static fn($row)=>[
                'id'=>(int)$row['id'],
                'name'=>$row['name'],
                'mime'=>$row['mime'],
                'size'=>(int)$row['size'],
                'original_size'=>(int)($row['original_size']??0),
                'stored_size'=>(int)$row['size']+(int)($row['original_size']??0),
                'has_master'=>(int)($row['original_id']??0)>0,
                'author'=>Profiles::publicName((int)$row['user_id']),
                'post_id'=>(int)$row['post_id'],
                'date'=>$row['created_at'],
                'url'=>self::url((int)$row['id'])
            ],$rows),
            'total'=>$total,
            'page'=>$page,
            'pages'=>max(1,(int)ceil($total/20)),
            'owner'=>(int)$owner,
            'owners'=>$all?array_values(array_filter(array_map(static function($uid){
                $user=get_userdata((int)$uid);
                return $user?['id'=>(int)$uid,'name'=>Profiles::publicName((int)$uid),'email'=>$user->user_email]:null;
            },array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT m.user_id FROM $table AS m WHERE m.post_id<>%d AND NOT EXISTS (SELECT 1 FROM $table AS derivative WHERE derivative.original_id=m.id) ORDER BY m.user_id",self::TEMP_POST_ID)))))):[]
        ];
    }

    /** The private blob has a single owner. Remove all references before deleting it. */
    public static function remove(int $id): array
    {
        Access::require(Access::member());
        $file=Store::one('media',$id);
        Access::require((bool)$file,'Archivo no encontrado.',404);
        Access::require(current_user_can('ascla_manage') || (int)$file['user_id']===get_current_user_id(),'Solo el propietario o un administrador puede eliminar este archivo.',403);
        Access::require(Store::count('media','original_id=%d',[$id])===0,'Esta imagen es la copia maestra de una versión recortada. Elimina primero la imagen visible.',409);
        return Store::lock('media:'.$id,static function()use($id,$file){
            global $wpdb;
            // Metadata is serialized; inspect candidate rows rather than doing an unsafe string replacement.
            $posts=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value LIKE %s",'_ascla','%media_ids%'));
            foreach($posts as $postId){
                $meta=(array)get_post_meta($postId,'_ascla',true);
                $ids=array_map('intval',(array)($meta['media_ids']??[]));
                if(in_array($id,$ids,true)){$meta['media_ids']=array_values(array_diff($ids,[$id]));update_post_meta($postId,'_ascla',$meta);}
            }
            $profile=(array)get_user_meta($file['user_id'],'_ascla_profile',true);
            if((int)($profile['photo_id']??0)===$id){$profile['photo_id']=0;$profile['revision']=(int)($profile['revision']??0)+1;update_user_meta($file['user_id'],'_ascla_profile',$profile);}
            $conversations=Store::table('conversations');
            $wpdb->query($wpdb->prepare("UPDATE $conversations SET photo_id=0 WHERE photo_id=%d",$id));
            $originalId=(int)($file['original_id']??0);
            Store::delete('media',['id'=>$id]);
            $deletedSource=false;
            if($originalId>0 && Store::count('media','original_id=%d',[$originalId])===0){
                $source=Store::one('media',$originalId);
                if($source && (int)$source['user_id']===(int)$file['user_id'] && (int)$source['post_id']<=0){
                    Store::delete('media',['id'=>$originalId]);
                    $deletedSource=true;
                }
            }
            Audit::record('media_deleted',$id,$deletedSource?'master_removed':'');
            return ['id'=>$id,'deleted'=>true,'master_deleted'=>$deletedSource];
        });
    }

    /**
     * Stores already optimized browser output. If original_id is present, this row is a display
     * derivative and the referenced private image is its uncropped master copy.
     */
    public static function upload(array $file,array $options=[]): array
    {
        Access::limit('upload',24,300);
        Access::require(isset($file['tmp_name']) && ($file['error']??1)===UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']),'Carga incompleta.',400);
        $size=filesize($file['tmp_name']);
        Access::require($size>0&&$size<=5*1024*1024,'Límite de 5 MB por archivo.',400);
        $allowed=['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],$allowed);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        Access::require($check['ext']&&$check['type']===$mime,'Sólo JPG, PNG, WebP y PDF verificados.',400);
        $originalId=absint($options['original_id']??0);
        if ($originalId) {
            self::requireOwned($originalId,get_current_user_id(),true);
            Access::require(str_starts_with($mime,'image/'),'La copia maestra sólo puede asociarse a una imagen.',400);
            $source=Store::one('media',$originalId);
            Access::require($source && (int)$source['post_id']<=0 && (int)($source['original_id']??0)===0,'Copia maestra no válida.',400);
        }
        if (str_starts_with($mime,'image/')) {
            $dim=getimagesize($file['tmp_name']);
            Access::require($dim && $dim[0]*$dim[1]<=20000000 && $size<=3*1024*1024,'Imagen demasiado grande después de optimizar (3 MB / 20 MP máximo).',400);
        } else {
            Access::require($originalId===0,'Un PDF no puede usar una copia maestra de imagen.',400);
        }
        self::cleanupTemporary(get_current_user_id());
        $name=sanitize_file_name($file['name']);
        $id=Store::insert('media',[
            'user_id'=>get_current_user_id(),
            'post_id'=>self::TEMP_POST_ID,
            'original_id'=>$originalId,
            'name'=>$name,
            'mime'=>$mime,
            'bytes'=>file_get_contents($file['tmp_name']),
            'created_at'=>current_time('mysql',true)
        ]);
        return ['id'=>$id,'url'=>self::url($id),'name'=>$name,'mime'=>$mime,'size'=>$size,'original_id'=>$originalId];
    }

    public static function serve(): void
    {
        try {
            Access::require(Access::member());
            $id=absint($_GET['id']??0);
            $row=Store::one('media',$id);
            Access::require((bool)$row,'Archivo no encontrado.',404);
            $allowed=(int)$row['user_id']===get_current_user_id()||current_user_can('ascla_manage');
            if (!$allowed && $row['post_id']) {
                $post=get_post($row['post_id']);
                $allowed=$post && Content::canRead($post);
            }
            if (!$allowed && str_starts_with($row['mime'],'image/')) {
                try { $profile=Profiles::visible((int)$row['user_id']); $allowed=(int)($profile['photo_id']??0)===$id; } catch (\Throwable $e) { $allowed=false; }
            }
            if (!$allowed && str_starts_with($row['mime'],'image/')) {
                global $wpdb;
                $c=Store::table('conversations'); $p=Store::table('participants');
                $allowed=(bool)$wpdb->get_var($wpdb->prepare(
                    "SELECT c.id FROM $c c INNER JOIN $p p ON p.conversation_id=c.id WHERE c.kind='group' AND c.photo_id=%d AND p.user_id=%d LIMIT 1",
                    $id,get_current_user_id()
                ));
            }
            Access::require($allowed,'Archivo no encontrado.',404);
            nocache_headers();
            header('Content-Type: '.$row['mime']);
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Content-Disposition: '.($row['mime']==='application/pdf'?'attachment':'inline').'; filename="'.sanitize_file_name($row['name']).'"');
            echo $row['bytes'];
            exit;
        } catch (\Throwable $e) { wp_die('Archivo no disponible.','ASCLA',['response'=>404]); }
    }
}
