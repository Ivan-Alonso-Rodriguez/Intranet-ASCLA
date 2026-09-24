<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Services\Access;
final class ImportRecords
{
    public static function create(string $kind,string $filename,array $config,array $rows,int $event=0): int
    {
        global $wpdb;$wpdb->query('START TRANSACTION');
        try {
            $id=Store::insert('imports',['kind'=>$kind,'actor_id'=>get_current_user_id(),'event_id'=>$event,'filename'=>sanitize_file_name($filename)?:'importacion.csv','status'=>'processing','total'=>count($rows),'config'=>wp_json_encode($config),'created_at'=>current_time('mysql',true)]);
            foreach(array_chunk($rows,100,true) as $chunk){$values=[];$args=[];foreach($chunk as $position=>$payload){$values[]='(%d,%d,%s,%s)';array_push($args,$id,$position+1,'pending',wp_json_encode($payload));}$sql='INSERT INTO '.Store::table('import_rows').' (import_id,position,state,payload) VALUES '.implode(',',$values);if($wpdb->query($wpdb->prepare($sql,...$args))===false) {throw new \RuntimeException('No se pudo guardar la importación.'); }}
            $wpdb->query('COMMIT');return $id;
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }
    public static function get(int $id,string $kind): array
    {
        $i=Store::one('imports',$id);Access::require($i && $i['kind']===$kind,'Importación no encontrada.',404);$i['config']=json_decode($i['config'],true)?:[];return $i;
    }
    public static function rows(int $id,string $state='',int $limit=50,int $offset=0): array
    {
        $rows=Store::rows('import_rows','import_id=%d'.($state!==''?' AND state=%s':''),$state!==''?[$id,$state]:[$id],'ORDER BY id ASC LIMIT '.max(1,min(100,$limit)).' OFFSET '.max(0,$offset));
        foreach($rows as &$r){$r['payload']=json_decode($r['payload'],true)?:[];$r['id']=(int)$r['id'];$r['user_id']=(int)$r['user_id'];}unset($r);return $rows;
    }
    public static function save(array $row,string $state,array $payload,int $user=0): void
    {
        Store::update('import_rows',['state'=>$state,'payload'=>wp_json_encode($payload),'user_id'=>$user],['id'=>(int)$row['id']]);
    }
    public static function counts(int $id): array
    {
        global $wpdb;$counts=[];foreach($wpdb->get_results($wpdb->prepare('SELECT state,COUNT(*) n FROM '.Store::table('import_rows').' WHERE import_id=%d GROUP BY state',$id),ARRAY_A) as $r) {$counts[$r['state']]=(int)$r['n']; }return $counts;
    }
    public static function summary(int $id): array
    {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) total,COALESCE(SUM(state<>'pending'),0) processed,COALESCE(SUM(user_id>0),0) found,COALESCE(SUM(user_id=0 AND state<>'pending'),0) not_found,COALESCE(SUM(state='updated'),0) updated,COALESCE(SUM(state='unchanged'),0) unchanged,COALESCE(SUM(state='conflict'),0) errors FROM ".Store::table('import_rows').' WHERE import_id=%d',$id),ARRAY_A);return array_map('intval',$row);
    }
    public static function history(string $kind,int $page,int $event=0): array
    {
        $page=max(1,$page);$where='kind=%s'.($event?' AND event_id=%d':'');$args=$event?[$kind,$event]:[$kind];$items=Store::rows('imports',$where,$args,'ORDER BY id DESC LIMIT 20 OFFSET '.(($page-1)*20));
        foreach($items as &$i){unset($i['config']);$i['counts']=self::counts((int)$i['id']);$i['summary']=self::summary((int)$i['id']);$i['actor_name']=\ASCLA\Core\Services\Profiles::publicName((int)$i['actor_id']);}unset($i);
        return ['items'=>$items,'page'=>$page,'total'=>Store::count('imports',$where,$args)];
    }
}
