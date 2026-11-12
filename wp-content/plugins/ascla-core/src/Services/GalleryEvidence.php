<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\{Store,KnowledgeSearch};

/** Gallery evidence is bound to readable publications, never arbitrary file IDs or URLs. */
final class GalleryEvidence
{
    public static function requested(string $question): bool
    {
        return (bool)preg_match('/\b(?:galerias?|fotos?|fotografias?|album(?:es)?|galler(?:y|ies)|photos?|pictures?|albums?)\b/u',mb_strtolower(remove_accents($question)));
    }

    public static function context(string $question,array $history): array
    {
        $plain=mb_strtolower(remove_accents($question));
        $generic=['galeria','galerias','foto','fotos','fotografia','fotografias','album','albumes','gallery','galleries','photo','photos','pictures','analiza','analizar','analisis','muestra','muestran','describe','descripcion','imagen','imagenes','contiene','contenido','puedes','disponibles','ver','ultimo','ultima','reciente','latest','analyze','describe','images','image'];
        $tokens=array_values(array_diff(KnowledgeSearch::tokens($question),$generic));
        $latest=(bool)preg_match('/\b(?:ultim[oa]|reciente|latest|newest)\b/u',$plain);
        $previous=end($history);$previousIds=(array)($previous['source_ids']??[]);
        $reference=count($previousIds)===1 && preg_match('/\b(?:esa galeria|ese album|esas fotos|that gallery|those photos)\b/u',$plain)?(int)$previousIds[0]:0;
        if ($previousIds && preg_match('/^\s*(?:(?:el|la|the) )?(primer[oa]?|segund[oa]|tercer[oa]?|first|second|third)\b/u',$plain,$position)) {
            $index=preg_match('/^(primer|first)/',$position[1])?0:(preg_match('/^(segund|second)/',$position[1])?1:2);
            $reference=(int)($previousIds[$index]??0);
        }
        $ranked=[];$protected=false;$identities=[];
        foreach (self::candidates($tokens,$latest,$reference) as $post) {
            if (!$post || $post->post_type!=='ascla_gallery' || $post->post_status!=='publish' || !Content::canRead($post)) { continue; }
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            if (!empty($meta['generated']) && empty($meta['reviewed'])) { continue; }
            [$title,$body]=AssistantContext::safeText($post,$meta);
            $files=Media::metadata($post->ID,(array)($meta['media_ids']??[]));
            $search=mb_strtolower(remove_accents($title.' '.$body.' '.implode(' ',array_column($files,'name'))));
            $score=KnowledgeSearch::score($title,$body,$tokens);
            foreach ($tokens as $token) { if (str_contains($search,$token)) { $score++; } }
            if ($tokens && !$score && !$latest && !$reference) { continue; }
            $protected=$protected || !empty($meta['chatham']);
            if (!empty($meta['chatham'])) { $identities=array_merge($identities,preg_split('/[\n,;]+/u',$meta['identities']??'')?:[]); }
            $ranked[]=['id'=>$post->ID,'title'=>$title,'body'=>$body,'kind'=>'gallery','url'=>Catalog::url('galeria',['item'=>$post->ID]),'score'=>$score,
                'files'=>array_map(static fn($file)=>['id'=>$file['id'],'name'=>$file['name'],'mime'=>$file['mime']],$files)];
        }
        if (!$latest && !$reference) { usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score']); }
        $specific=$reference || $latest || ($tokens && (!isset($ranked[1]) || $ranked[0]['score']>$ranked[1]['score']));
        $sources=array_slice($ranked,0,$specific?1:8);
        foreach ($sources as &$source) {
            $source['gallery_digest']=self::digest(get_post($source['id']));
            if ($protected) { $source['files']=\ASCLA\Core\Domain\EntityRedactor::tree($source['files'],$identities,array_column($sources,'title')); }
        }
        unset($source);
        return ['sources'=>$sources,'protected'=>$protected,'identities'=>$identities,'request'=>count($sources)>1?'choose':'answer'];
    }

    /** Rank before applying the bound so a specific older gallery remains discoverable. */
    private static function candidates(array $tokens,bool $latest,int $reference): array
    {
        if ($reference) { return [get_post($reference)]; }
        global $wpdb;$parts=[];$params=[];
        foreach ($tokens as $token) {
            $like='%'.$wpdb->esc_like($token).'%';
            $parts[]='(CASE WHEN p.post_title LIKE %s THEN 3 ELSE 0 END + CASE WHEN p.post_content LIKE %s OR EXISTS (SELECT 1 FROM '.Store::table('media').' m WHERE m.post_id=p.ID AND m.name LIKE %s) THEN 1 ELSE 0 END)';
            array_push($params,$like,$like,$like);
        }
        $score=$parts?implode(' + ',$parts):'1';
        $order=$latest?'p.post_date DESC, relevance DESC':'relevance DESC, p.post_date DESC';
        $sql="SELECT p.ID, ($score) AS relevance FROM {$wpdb->posts} p WHERE p.post_type='ascla_gallery' AND p.post_status='publish' HAVING relevance>0 ORDER BY $order, p.ID DESC LIMIT 100";
        $ids=$wpdb->get_col($params?$wpdb->prepare($sql,...$params):$sql);
        return array_map('get_post',$ids);
    }

    public static function digest(\WP_Post $post): string
    {
        if ($post->post_type!=='ascla_gallery' || $post->post_status!=='publish' || !Content::canRead($post)) { return ''; }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);$files=[];
        foreach (Media::metadata($post->ID,(array)($meta['media_ids']??[])) as $file) {
            $row=Store::one('media',$file['id']);
            if ($row && (int)$row['post_id']===$post->ID) { $files[]=[$file['id'],$file['name'],$file['mime'],hash('sha256',$row['bytes'])]; }
        }
        return hash('sha256',wp_json_encode([$post->post_title,$post->post_content,$meta,$files]));
    }

    /** Private bytes are used only for the current provider call, never saved in answers or logs. */
    public static function images(array $sources): array
    {
        $images=[];$size=0;
        foreach ($sources as $source) {
            if (($source['kind']??'')!=='gallery') { continue; }
            $post=get_post($source['id']);
            $digest=$post?self::digest($post):'';
            if ($digest==='' || $digest!==($source['gallery_digest']??'')) { continue; }
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            foreach (Media::metadata($post->ID,(array)($meta['media_ids']??[])) as $file) {
                if (!in_array($file['mime'],['image/jpeg','image/png','image/webp'],true)) { continue; }
                $row=Store::one('media',$file['id']);
                if (!$row || (int)$row['post_id']!==$post->ID) { continue; }
                $bytes=self::thumbnail($row['bytes']);
                if ($bytes==='' || $size+strlen($bytes)>8*1024*1024) { continue; }
                $size+=strlen($bytes);
                $images[]=['source_id'=>$post->ID,'media_id'=>$file['id'],'mime'=>'image/jpeg','data'=>base64_encode($bytes)];
                if (count($images)>=12) { return $images; }
            }
        }
        return $images;
    }

    private static function thumbnail(string $bytes): string
    {
        if (!function_exists('imagecreatefromstring')) { return ''; }
        $dimensions=@getimagesizefromstring($bytes);
        if (!$dimensions || $dimensions[0]*$dimensions[1]>40000000) { return ''; }
        $image=@imagecreatefromstring($bytes);
        if (!$image) { return ''; }
        $ratio=min(1,1280/max($dimensions[0],$dimensions[1]));
        $canvas=imagecreatetruecolor(max(1,(int)round($dimensions[0]*$ratio)),max(1,(int)round($dimensions[1]*$ratio)));
        imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));
        imagecopyresampled($canvas,$image,0,0,0,0,imagesx($canvas),imagesy($canvas),$dimensions[0],$dimensions[1]);
        ob_start();imagejpeg($canvas,null,80);$result=(string)ob_get_clean();
        imagedestroy($image);imagedestroy($canvas);
        return strlen($result)<=786432?$result:'';
    }
}
