<?php
namespace ASCLA\Core\Jobs;
use ASCLA\Core\Repositories\{Store,Transaction,RepositoryException};
use ASCLA\Core\Services\{Access,Audit,TechnicalErrors};

/** Transactional outbox. A recurring worker also recovers a commit followed by process death. */
final class Deliveries
{
    private static bool $running=false;
    private const MAX_ATTEMPTS=8;
    public static function boot(): void
    {
        add_filter('cron_schedules',static function($schedules) { $schedules['ascla_minute']=['interval'=>60,'display'=>'Cada minuto (ASCLA)'];return $schedules; });
        add_action('init',[self::class,'schedule'],25);
        add_action('ascla_deliveries',static fn()=>self::run(),10,0);
    }
    public static function schedule(): void
    {
        if (!wp_next_scheduled('ascla_deliveries')) { wp_schedule_event(time()+60,'ascla_minute','ascla_deliveries'); }
    }
    public static function enqueue(string $kind,array $payload,string $key=''): int
    {
        if (!in_array($kind,['notification','mail','calendar'],true)) { throw new \InvalidArgumentException('Entrega no válida'); }
        $id=Store::insert('deliveries',['kind'=>$kind,'delivery_key'=>$key!==''?$key:bin2hex(random_bytes(16)),
            'actor_id'=>get_current_user_id(),'payload'=>DeliveryPayload::seal($kind,$payload),'status'=>'pending',
            'available_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s')]);
        Transaction::afterCommit(static fn()=>self::run($id));
        return $id;
    }
    public static function run(int $only=0): void
    {
        if (self::$running) { return; }
        self::$running=true;
        try { Store::lock('deliveries',static fn()=>self::drain($only),0); }
        catch (RepositoryException $error) { TechnicalErrors::record('delivery','delivery_failed',$error); }
        finally { self::$running=false; }
    }
    private static function drain(int $only): void
    {
        global $wpdb;$table=Store::table('deliveries');
        if ($wpdb->query("UPDATE $table SET status='pending',locked_at=NULL WHERE status='processing' AND locked_at<UTC_TIMESTAMP()-INTERVAL 5 MINUTE")===false) { throw new RepositoryException('No se pudo recuperar la entrega.'); }
        if ($wpdb->query("UPDATE $table SET status='expired',payload='',locked_at=NULL WHERE kind<>'cache' AND status IN ('pending','failed') AND created_at<UTC_TIMESTAMP()-INTERVAL 7 DAY")===false
            || $wpdb->query("DELETE FROM $table WHERE status IN ('sent','skipped','expired') AND created_at<UTC_TIMESTAMP()-INTERVAL 14 DAY")===false) { throw new RepositoryException('No se pudo conservar el estado de entrega.'); }
        $where="status='pending' AND available_at<=UTC_TIMESTAMP()";$args=[];
        if ($only>0) { $where.=' AND id=%d';$args[]=$only; }
        $started=microtime(true);
        $sql="SELECT id FROM $table WHERE $where ORDER BY (kind='cache') DESC,id ASC LIMIT 10";
        $ids=$wpdb->get_col($args?$wpdb->prepare($sql,...$args):$sql);
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo consultar la cola de entregas.'); }
        foreach ($ids as $id) {
            if (microtime(true)-$started>20) { break; }
            $row=Store::one('deliveries',(int)$id);
            if ($row) { self::process($row); }
            unset($row);
        }
    }
    private static function process(array $row): void
    {
        global $wpdb;$table=Store::table('deliveries');$original=get_current_user_id();
        $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',attempts=attempts+1,locked_at=UTC_TIMESTAMP() WHERE id=%d AND status='pending'",$row['id']));
        if ($claimed===false) { throw new RepositoryException('No se pudo iniciar la entrega.'); }
        if ($claimed!==1) { return; }
        try {
            wp_set_current_user((int)$row['actor_id']);
            $payload=DeliveryPayload::open($row['kind'],$row['payload']);
            $sent=DeliveryHandlers::send($row,$payload);
            Store::update('deliveries',['status'=>$sent?'sent':'skipped','payload'=>'','locked_at'=>null,'completed_at'=>gmdate('Y-m-d H:i:s')],['id'=>$row['id']]);
        } catch (\Throwable $error) {
            $attempts=(int)$row['attempts']+1;$cache=$row['kind']==='cache';$failed=!$cache && $attempts>=self::MAX_ATTEMPTS;
            Store::update('deliveries',['status'=>$failed?'failed':'pending','locked_at'=>null,
                'available_at'=>gmdate('Y-m-d H:i:s',time()+($cache?60:min(21600,60*3**min(7,$attempts-1))))],['id'=>$row['id']]);
            TechnicalErrors::record('delivery','delivery_failed',$error,(int)$row['id']);
        } finally { wp_set_current_user($original); }
    }
    public static function overview(): array
    {
        global $wpdb;
        $counts=[];foreach (['pending','processing','failed'] as $status) { $counts[$status]=Store::count('deliveries','status=%s',[$status]); }
        $items=$wpdb->get_results("SELECT id,kind,status,attempts,available_at,created_at FROM ".Store::table('deliveries')." WHERE status IN ('pending','processing','failed') ORDER BY id DESC LIMIT 30",ARRAY_A);
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo consultar el estado de entregas.'); }
        return ['counts'=>$counts,'items'=>$items];
    }
    public static function retry(int $id): array
    {
        Access::require(Access::member()&&current_user_can('ascla_manage'));
        Store::atomic('delivery:'.$id,static function() use($id) {
            $row=Store::one('deliveries',$id);Access::require($row && $row['status']==='failed','Entrega no disponible para reintentar.',409);
            Store::update('deliveries',['status'=>'pending','attempts'=>0,'available_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
            Audit::record('delivery_retry',$id,$row['kind']);
        });
        self::run($id);return self::overview();
    }
}
