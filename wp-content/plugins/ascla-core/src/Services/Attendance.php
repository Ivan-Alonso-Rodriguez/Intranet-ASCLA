<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\{ZoomCsv,AttendanceSessions};
use ASCLA\Core\Repositories\ImportRecords;
use ASCLA\Core\Repositories\Store;

/** Attendance is independent of RSVP. Only explicit, completed rosters support attendance rates. */
final class Attendance
{
    public static function authorize(): void
    {
        Access::require(Access::member() && current_user_can('ascla_publish'));
    }
    public static function event(int $id): \WP_Post
    {
        self::authorize();$post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_event' && $post->post_status==='publish','Evento no disponible.',404);
        $end=strtotime((string)($meta['end']??''));$start=strtotime((string)($meta['start']??''));
        Access::require($end!==false && $start!==false && $end>$start && $end<=time() && empty($meta['cancelled']),'La asistencia se registra en eventos finalizados y no cancelados.',409);
        return $post;
    }
    private static function maxMinutes(int $id): int
    {
        $m=(array)get_post_meta($id,'_ascla',true);
        return max(1,(int)ceil((strtotime($m['end'])-strtotime($m['start']))/60));
    }
    public static function roster(int $id,int $page=1): array
    {
        $post=self::event($id);$complete=(bool)get_post_meta($id,'_ascla_attendance_complete',true);
        return ['id'=>$id,'title'=>$post->post_title,'complete'=>$complete,'max_minutes'=>self::maxMinutes($id),'threshold'=>self::threshold($id)]+\ASCLA\Core\Repositories\AttendanceRecords::roster($id,$page,self::duration($id),$complete);
    }
    public static function save(int $id,array $input): array
    {
        self::event($id);
        $status=Access::text($input['status']??'',16);
        Access::require(in_array($status,['present','partial','absent','review','unknown'],true),'Estado de asistencia no válido.',400);
        $email=trim(Access::text($input['email']??'',100));$uid=absint($input['user_id']??0);
        if(!$uid) {$user=get_user_by('email',$email);$uid=$user?(int)$user->ID:0;}
        Access::require($uid>0 && user_can($uid,'ascla_access'),'No se encontró un usuario ASCLA con ese correo.',400);
        $value=$input['minutes']??null;$minutes=($value===null || $value==='')?null:filter_var($value,FILTER_VALIDATE_INT);
        Access::require($minutes===null || ($minutes!==false && $minutes>=0 && $minutes<=self::maxMinutes($id)),'Duración no válida para este evento.',400);
        if($status==='absent')$minutes=0;
        return Store::lock('attendance:'.$id,static function()use($id,$uid,$status,$minutes){
            clean_post_cache($id);self::event($id);
            if($status==='unknown')Store::delete('attendance',['event_id'=>$id,'user_id'=>$uid]);
            else self::upsert($id,$uid,$status,$minutes,'manual');
            delete_post_meta($id,'_ascla_attendance_complete');
            Audit::record('attendance_saved',$id,$status.'; member='.$uid);
            return self::roster($id);
        });
    }
    private static function upsert(int $event,int $user,string $status,?int $minutes,string $source,array $evidence=[]): void
    {
        $old=Store::rows('attendance','event_id=%d AND user_id=%d',[$event,$user],'LIMIT 1')[0]??null;
        $data=['status'=>$status,'minutes'=>$minutes,'source'=>$source,'recorded_by'=>get_current_user_id(),'updated_at'=>current_time('mysql',true),'seconds'=>$evidence['seconds']??($minutes===null?null:$minutes*60),'sessions'=>empty($evidence['sessions'])?null:wp_json_encode($evidence['sessions']),'review_reason'=>$evidence['review_reason']??''];
        if($old)Store::update('attendance',$data,['id'=>(int)$old['id']]);
        else Store::insert('attendance',['event_id'=>$event,'user_id'=>$user]+$data);
    }
    public static function complete(int $id,bool $complete): array
    {
        self::event($id);
        return Store::lock('attendance:'.$id,static function()use($id,$complete){
            clean_post_cache($id);self::event($id);
            // Completing a roster explicitly confirms that unmarked registered users did not attend.
            if($complete) update_post_meta($id,'_ascla_attendance_complete',['by'=>get_current_user_id(),'at'=>gmdate('c')]);
            else delete_post_meta($id,'_ascla_attendance_complete');
            Audit::record('attendance_roster',$id,$complete?'complete':'reopened');
            return self::roster($id);
        });
    }
    private static function duration(int $id): int
    {
        $m=(array)get_post_meta($id,'_ascla',true);return strtotime($m['end'])-strtotime($m['start']);
    }
    private static function threshold(int $id): int{return (int)(get_post_meta($id,'_ascla_attendance_threshold',true)?:70);}
    public static function configure(int $id,array $input): array
    {
        self::event($id);$threshold=filter_var($input['threshold']??null,FILTER_VALIDATE_INT);Access::require($threshold!==false && $threshold>=1 && $threshold<=100,'El umbral debe estar entre 1 y 100%.',400);
        return Store::lock('attendance:'.$id,static function()use($id,$threshold){
            self::event($id);update_post_meta($id,'_ascla_attendance_threshold',$threshold);
            global $wpdb;$table=Store::table('attendance');$duration=self::duration($id);
            $wpdb->query($wpdb->prepare("UPDATE $table SET status=CASE WHEN seconds=0 THEN 'absent' WHEN seconds*100 >= %d THEN 'present' ELSE 'partial' END WHERE event_id=%d AND source='zoom' AND seconds IS NOT NULL AND review_reason=''",$duration*$threshold,$id));
            delete_post_meta($id,'_ascla_attendance_complete');Audit::record('attendance_threshold',$id,(string)$threshold);return self::roster($id);
        });
    }
    private static function plan(int $id,string $csv,array $options): array
    {
        $options=AttendanceSessions::options($options);$rows=ZoomCsv::parse($csv);$matched=[];$unmatched=[];$duplicates=0;$seen=[];$lookup=[];
        global $wpdb;$emails=array_values(array_unique(array_filter(array_column($rows,'email'),'is_email')));
        foreach(array_chunk($emails,200) as $chunk){$ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email IN (".implode(',',array_fill(0,count($chunk),'%s')).')',...$chunk));if($ids)cache_users($ids);}
        foreach($rows as $row){
            if(isset($seen[$row['signature']])){++$duplicates;continue;}$seen[$row['signature']]=true;
            if(!array_key_exists($row['email'],$lookup))$lookup[$row['email']]=$row['error']===''?get_user_by('email',$row['email']):false;
            $user=$lookup[$row['email']];
            if($row['error']!=='' || !$user || !user_can($user,'ascla_access')){$unmatched[]=['line'=>$row['line'],'email'=>Access::excerpt($row['email'],100),'name'=>$row['name'],'reason'=>$row['error']?:'Correo sin cuenta ASCLA','sessions'=>[$row]];continue;}
            $uid=(int)$user->ID;if(!isset($matched[$uid]))$matched[$uid]=['id'=>$uid,'name'=>Profiles::publicName($uid),'email'=>$user->user_email,'rows'=>[]];$matched[$uid]['rows'][]=$row;
        }
        $existing=[];foreach(Store::rows('attendance','event_id=%d',[$id],'ORDER BY id ASC') as $r)$existing[(int)$r['user_id']]=$r;
        $meta=(array)get_post_meta($id,'_ascla',true);$protected=0;
        foreach($matched as $uid=>&$m){$evidence=AttendanceSessions::calculate($m['rows'],strtotime($meta['start']),strtotime($meta['end']),self::threshold($id),$options);unset($m['rows']);$m+=$evidence;$m['protected']=($existing[$uid]['source']??'')==='manual';$m['snapshot']=hash('sha256',wp_json_encode($existing[$uid]??null));if($m['protected'])++$protected;}unset($m);
        $plan=['matched'=>array_values($matched),'unmatched'=>$unmatched,'duplicates'=>$duplicates,'protected'=>$protected,'importable'=>count($matched)-$protected,'options'=>$options,'threshold'=>self::threshold($id)];
        $plan['token']=hash_hmac('sha256',wp_json_encode([get_current_user_id(),$id,hash('sha256',$csv),$plan,$existing,get_post_meta($id,'_ascla_attendance_complete',true)]),wp_salt('nonce'));return $plan;
    }
    public static function preview(int $id,string $csv,array $options=[]): array
    {
        self::event($id);$plan=self::plan($id,$csv,$options);$page=max(1,(int)($options['page']??1));$plan['matched_total']=count($plan['matched']);$plan['unmatched_total']=count($plan['unmatched']);$plan['pages']=max(1,(int)ceil(max($plan['matched_total'],$plan['unmatched_total'])/50));$plan['page']=$page;
        $plan['matched']=array_slice($plan['matched'],($page-1)*50,50);$plan['unmatched']=array_slice($plan['unmatched'],($page-1)*50,50);return $plan;
    }
    public static function import(int $id,string $csv,string $token,array $options=[]): array
    {
        self::event($id);$importId=Store::lock('attendance:'.$id,static function()use($id,$csv,$token,$options){
            clean_post_cache($id);self::event($id);$plan=self::plan($id,$csv,$options);Access::require($token!=='' && hash_equals($plan['token'],$token),'El registro cambió. Revisa nuevamente la vista previa del CSV.',409);Access::require($plan['importable']>0,'No hay participantes nuevos o actualizables en este CSV.',400);
            $rows=[];foreach($plan['matched'] as $m){$m['kind']='matched';$rows[]=$m;}foreach($plan['unmatched'] as $u){$u['kind']='unidentified';$rows[]=$u;}
            $importId=ImportRecords::create('attendance',Access::text($options['filename']??'zoom.csv',255),['options'=>$plan['options'],'threshold'=>$plan['threshold'],'window'=>get_post_meta($id,'_ascla_end',true).'|'.get_post_meta($id,'_ascla_start',true),'duplicates'=>$plan['duplicates']],$rows,$id);
            Store::update('imports',['status'=>'applying'],['id'=>$importId]);return $importId;
        });return self::apply($id,$importId);
    }
    public static function apply(int $id,int $importId): array
    {
        self::event($id);return Store::lock('attendance:'.$id,static function()use($id,$importId){
            self::event($id);$import=ImportRecords::get($importId,'attendance');Access::require((int)$import['event_id']===$id,'Importación no disponible.',404);Access::require(in_array($import['status'],['applying','complete'],true),'Importación no confirmada.',409);
            if($import['status']==='applying'){
                Access::require((int)$import['config']['threshold']===self::threshold($id) && $import['config']['window']===get_post_meta($id,'_ascla_end',true).'|'.get_post_meta($id,'_ascla_start',true),'El evento o el umbral cambió. Genera una nueva vista previa.',409);
                global $wpdb;$wpdb->query('START TRANSACTION');
                try{foreach(ImportRecords::rows($importId,'pending',50) as $row){$p=$row['payload'];$uid=(int)($p['id']??0);
                    if($p['kind']==='unidentified'){ImportRecords::save($row,'unidentified',$p);continue;}
                    $old=Store::rows('attendance','event_id=%d AND user_id=%d',[$id,$uid],'LIMIT 1')[0]??null;
                    if($p['protected'] || ($old['source']??'')==='manual'){ImportRecords::save($row,'protected',$p,$uid);continue;}
                    if(!user_can($uid,'ascla_access') || !hash_equals($p['snapshot'],hash('sha256',wp_json_encode($old)))){ImportRecords::save($row,'conflict',$p,$uid);continue;}
                    self::upsert($id,$uid,$p['status'],$p['minutes'],'zoom',$p);ImportRecords::save($row,'updated',$p,$uid);
                }if($wpdb->query('COMMIT')===false)throw new \RuntimeException('No se pudo confirmar el lote.');}catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
                delete_post_meta($id,'_ascla_attendance_complete');$counts=ImportRecords::counts($importId);if(empty($counts['pending'])){Store::update('imports',['status'=>'complete','completed_at'=>current_time('mysql',true)],['id'=>$importId]);Audit::record('attendance_imported',$id,'import='.$importId.'; updated='.($counts['updated']??0));}
            }
            $counts=ImportRecords::counts($importId);$done=empty($counts['pending']);return ['import_id'=>$importId,'complete'=>$done,'total'=>(int)$import['total'],'processed'=>(int)$import['total']-($counts['pending']??0),'counts'=>$counts,'imported'=>$counts['updated']??0,'unmatched'=>$counts['unidentified']??0,'roster'=>$done?self::roster($id):null];
        });
    }
    public static function history(int $id,int $page=1): array
    {
        self::event($id);return ImportRecords::history('attendance',$page,$id);
    }
    public static function importDetail(int $id,int $importId,int $page=1): array
    {
        self::event($id);$import=ImportRecords::get($importId,'attendance');Access::require((int)$import['event_id']===$id,'Importación no disponible.',404);$import['rows']=ImportRecords::rows($importId,'',50,(max(1,$page)-1)*50);$import['counts']=ImportRecords::counts($importId);$import['page']=max(1,$page);$import['pages']=max(1,(int)ceil($import['total']/50));return $import;
    }
}
