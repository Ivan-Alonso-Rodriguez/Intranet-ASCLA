<?php
namespace ASCLA\Core\Repositories;

/** Partial meaningful terms, weighted title/body relevance, filtered before the limit. */
final class KnowledgeSearch
{
    public static function tokens(string $question): array
    {
        $stop=['como','para','sobre','puedo','quiero','tiene','donde','cuales','ascla','que','por','con','una','uno','unas','unos','los','las','del','desde','entre','hasta','este','esta','estos','estas','ese','esa','eso','esto','cual','cuando','quien','cuyo','muy','mas','sin','sus','son','hay','ser','estar','hacer','explica','explicar','explicame','resumen','resumir','podria','podrias','necesito','favor','informacion','tema','temas','completamente','acerca','tambien','debe','deben','puede','pueden','cómo'];
        $words=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower(remove_accents($question)))?:[];
        $stop=array_merge($stop,['cuales','trata','habla','dice','dime','hablame','resume','explican','explico','video','videos','grabacion','grabaciones','capsula','capsulas','subi','publique','subido','ultimo','ultima','reciente','mio','the','was','what','which','does','about','from','that','this','uploaded','latest','recording','summarize','summary','please']);
        $stop=array_merge($stop,['recurso','recursos','resource','resources']);
        return array_slice(array_values(array_unique(array_filter($words,static fn($w)=>(mb_strlen($w)>=3||$w==='ia')&&!in_array($w,$stop,true)))),0,16);
    }
    public static function score(string $title,string $body,array $tokens): int
    {
        $title=mb_strtolower(remove_accents($title));$body=mb_strtolower(remove_accents($body));$score=0;
        foreach($tokens as $token) {$score+=str_contains($title,$token)?3:0;$score+=str_contains($body,$token)?1:0;}
        return $score;
    }
    public static function excerpt(string $body,array $tokens): string
    {
        if (!$tokens) { return mb_substr($body,0,5000); }
        $passages=[];
        foreach (preg_split('/\R+|(?<=[.!?])\s+/u',$body,-1,PREG_SPLIT_NO_EMPTY)?:[] as $paragraph) {
            foreach (mb_str_split($paragraph,1000) as $text) {
                $passages[]=['text'=>$text,'score'=>self::score('',$text,$tokens)];
            }
        }
        // Stable sort keeps the original order when passages are equally relevant.
        usort($passages,static fn($a,$b)=>$b['score']<=>$a['score']);
        return mb_substr(implode("\n",array_column($passages,'text')),0,5000);
    }
    public static function candidates(array $tokens,bool $videos=false,int $author=0,bool $latest=false): array
    {
        if(!$tokens && !$videos) {return []; }
        global $wpdb;$expressions=[];$parameters=[];
        foreach($tokens as $token) {
            $expressions[]='(CASE WHEN p.post_title LIKE %s THEN 3 ELSE 0 END + CASE WHEN p.post_content LIKE %s OR evidence.meta_value LIKE %s THEN 1 ELSE 0 END)';
            $like='%'.$wpdb->esc_like($token).'%';
            array_push($parameters,$like,$like,$like);
        }
        $score=$expressions?implode(' + ',$expressions):'1';
        $parameters[]=\ASCLA\Core\Services\VideoEvidence::INDEX;
        $where="p.post_type='ascla_resource' AND p.post_status='publish'";
        if ($videos) {
            $where.=" AND (evidence.meta_id IS NOT NULL OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} vf WHERE vf.post_id=p.ID AND vf.meta_key='_ascla_resource_type' AND vf.meta_value='Video'))";
        }
        if ($author) { $where.=' AND p.post_author=%d';$parameters[]=$author; }
        $order=$latest?'p.post_date DESC, relevance DESC':'relevance DESC, p.post_date DESC';
        $sql="SELECT p.ID, ($score) AS relevance FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} evidence ON evidence.post_id=p.ID AND evidence.meta_key=%s WHERE $where HAVING relevance > 0 ORDER BY $order, p.ID DESC LIMIT 100";
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare($sql,...$parameters)));
        if($ids) {_prime_post_caches($ids,true,true); }
        return array_values(array_filter(array_map('get_post',$ids)));
    }
}
