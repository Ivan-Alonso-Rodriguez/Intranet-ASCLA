<?php
namespace ASCLA\Core\Repositories;

/** Partial meaningful terms, weighted title/body relevance, filtered before the limit. */
final class KnowledgeSearch
{
    public static function tokens(string $question): array
    {
        $stop=['como','para','sobre','puedo','quiero','tiene','donde','cuales','ascla','que','por','con','una','uno','unas','unos','los','las','del','desde','entre','hasta','este','esta','estos','estas','ese','esa','eso','esto','cual','cuando','quien','cuyo','muy','mas','sin','sus','son','hay','ser','estar','hacer','explica','explicar','explicame','resumen','resumir','podria','podrias','necesito','favor','informacion','tema','temas','completamente','acerca','tambien','debe','deben','puede','pueden','cómo'];
        $words=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower(remove_accents($question)))?:[];
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
        if(mb_strlen($body)<=5000) return $body;
        $hay=mb_strtolower(remove_accents($body));$first=null;
        foreach($tokens as $token) {$pos=mb_strpos($hay,$token);if($pos!==false) $first=$first===null?$pos:min($first,$pos);}
        return mb_substr($body,max(0,($first??0)-500),5000);
    }
    public static function candidates(array $tokens): array
    {
        if(!$tokens)return [];
        global $wpdb;$expressions=[];$parameters=[];
        foreach($tokens as $token) {
            $expressions[]='(CASE WHEN post_title LIKE %s THEN 3 ELSE 0 END + CASE WHEN post_content LIKE %s THEN 1 ELSE 0 END)';
            $like='%'.$wpdb->esc_like($token).'%';$parameters[]=$like;$parameters[]=$like;
        }
        $score=implode(' + ',$expressions);$parameters[]='ascla_resource';$parameters[]='publish';
        $sql="SELECT ID, ($score) AS relevance FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s HAVING relevance > 0 ORDER BY relevance DESC, post_date DESC, ID DESC LIMIT 100";
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare($sql,...$parameters)));
        if($ids)_prime_post_caches($ids,true,true);
        return array_values(array_filter(array_map('get_post',$ids)));
    }
}
