<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{Store,InterestSignals,WordPressWrites};

/** Automatic topic learning, with persistent precedence for manually removed interests. */
final class LearnedInterests
{
    private const WEIGHTS=['read'=>1,'comment'=>2,'registration'=>3,'like'=>4,'attendance'=>5];
    private static array $cache=[];

    public static function boot(): void
    {
        add_action('ascla_jobs',[self::class,'cleanup'],25);
        add_action('deleted_user',static fn($id)=>Store::delete('relations',['user_id'=>(int)$id,'kind'=>'interest_read']));
    }

    public static function cleanup(): void
    {
        $rows=Store::rows('relations',"kind='interest_read' AND created_at<%s",[gmdate('Y-m-d H:i:s',time()-90*DAY_IN_SECONDS)],'ORDER BY id LIMIT 500');
        foreach ($rows as $row) { Store::delete('relations',['id'=>(int)$row['id']]); }
    }

    public static function invalidate(int $user): void { unset(self::$cache[$user]); }

    public static function eligible(int $id,array $visited=[]): bool
    {
        if (isset($visited[$id])) { return false; }
        $visited[$id]=true;$post=get_post($id);
        if (!$post || $post->post_status!=='publish' || !in_array(substr($post->post_type,6),['hub','forum','topic','resource','gallery','event','ally'],true)) { return false; }
        $meta=(array)get_post_meta($id,'_ascla',true);
        $unprotected=empty($meta['chatham']) && empty($meta['micro']) && (empty($meta['generated']) || !empty($meta['reviewed']));
        return $unprotected && (!$post->post_parent || self::eligible((int)$post->post_parent,$visited));
    }

    public static function get(int $user=0): array
    {
        $user=$user?:get_current_user_id();
        Access::require(Access::member() && ($user===get_current_user_id() || current_user_can('ascla_manage')));
        $result=self::estimate($user);
        try { WordPressWrites::meta('user',$user,'_ascla_learned_topics',$result['topics']); }
        catch (\Throwable $error) { self::invalidate($user);throw $error; }
        return $result;
    }

    /** Internal ranking also runs for discovery jobs, without an HTTP user's session. */
    public static function estimate(int $user): array
    {
        if (isset(self::$cache[$user])) { return self::$cache[$user]; }
        $prefs=(array)get_user_meta($user,'_ascla_personalization',true);
        $enabled=Access::member($user);
        $topics=$enabled?self::topics($user,(array)($prefs['baseline']??[])):[];
        return self::$cache[$user]=['enabled'=>(bool)$enabled,'period_days'=>90,'topics'=>$topics,'reset_at'=>$prefs['reset_at']??null];
    }

    private static function topics(int $user,array $baseline): array
    {
        $topics=[];$seen=[];
        $rows=InterestSignals::recent($user,$baseline);
        _prime_post_caches(array_values(array_unique(array_map('intval',array_column($rows,'post_id')))),true,true);
        foreach ($rows as $row) {
            $id=(int)$row['post_id'];$kind=$row['kind'];$post=get_post($id);
            if (!$post || (int)$post->post_author===$user || !self::eligible($id)) { continue; }
            // One strongest action per content: repeated comments and repeated opens do not multiply interest.
            if (isset($seen[$id]) && $seen[$id]>=self::WEIGHTS[$kind]) { continue; }
            $terms=wp_get_object_terms($id,['ascla_interest','ascla_category','ascla_tag']);
            if (is_wp_error($terms)) { continue; }
            self::addTopics($topics,$terms,self::WEIGHTS[$kind],$seen[$id]??null);
            $seen[$id]=self::WEIGHTS[$kind];
        }
        $topics=array_values($topics);
        $names=self::excludedNames($user);
        $topics=array_values(array_filter($topics,static fn($topic)=>!in_array(mb_strtolower(remove_accents($topic['name'])),$names,true)));
        usort($topics,static fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['name'],$b['name']));
        return array_slice($topics,0,40);
    }
    private static function addTopics(array &$topics,array $terms,int $weight,?int $previous): void
    {
        foreach ($terms as $term) {
            $key=$term->taxonomy.':'.$term->term_id;
            $topics[$key]??=['id'=>(int)$term->term_id,'taxonomy'=>substr($term->taxonomy,6),'name'=>$term->name,'score'=>0,'contents'=>0];
            $topics[$key]['score']+=$weight-($previous??0);
            if ($previous===null) { $topics[$key]['contents']++; }
        }
    }
    private static function excludedNames(int $user): array
    {
        $names=[];
        foreach (AutomaticInterests::excluded($user) as $id) {
            $term=get_term($id,'ascla_interest');
            if ($term && !is_wp_error($term)) { $names[]=mb_strtolower(remove_accents($term->name)); }
        }
        return $names;
    }

    /** Start only when a readable detail is open. Server time prevents instant fabricated readings. */
    public static function start(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_write'));
        $post=Content::get($id);$prefs=(array)get_user_meta(get_current_user_id(),'_ascla_personalization',true);
        if ((int)$post->post_author===get_current_user_id() || !self::eligible($id)) { return ['ticket'=>'']; }
        $ticket=bin2hex(random_bytes(20));
        set_transient('ascla_read_'.get_current_user_id().'_'.$id,['ticket'=>$ticket,'started'=>time(),'revision'=>$prefs['revision']??null],10*MINUTE_IN_SECONDS);
        return ['ticket'=>$ticket,'seconds'=>15];
    }

    public static function read(int $id,string $ticket): array
    {
        Access::require(Access::member() && current_user_can('ascla_write'));
        $user=get_current_user_id();Content::get($id);
        return Store::atomic('learned-interests:'.$user,static function()use($id,$ticket,$user){
            $key='ascla_read_'.$user.'_'.$id;$start=get_transient($key);$prefs=(array)get_user_meta($user,'_ascla_personalization',true);
            Access::require(is_array($start) && strlen($ticket)===40 && hash_equals($start['ticket'],$ticket) && time()-$start['started']>=15 && time()-$start['started']<=600,'Lectura no válida.',400);
            delete_transient($key);
            if (($prefs['revision']??null)!==($start['revision']??null) || !self::eligible($id) || (int)get_post_field('post_author',$id)===$user) { return ['recorded'=>false]; }
            $where=['user_id'=>$user,'target_id'=>$id,'kind'=>'interest_read'];
            $existing=Store::rows('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where),'LIMIT 1')[0]??null;
            $now=current_time('mysql',true);
            if (!$existing) { Store::insert('relations',$where+['created_at'=>$now]); }
            elseif (strtotime($existing['created_at'].' UTC')<time()-DAY_IN_SECONDS) { Store::update('relations',['created_at'=>$now],['id'=>(int)$existing['id']]); }
            InterestSignals::pruneReads($user);KnowledgeRecommendations::invalidateActivity($user);self::get($user);
            return ['recorded'=>true];
        });
    }
}
