<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Connections,Messaging};
use ASCLA\Core\Repositories\{Store,RepositoryException};

final class GroupAtomicityTest extends TestCase
{
    private array $users=[];
    protected function setUp(): void
    {
        foreach (range(1,3) as $number) {
            $login='group_atomic_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        }
        foreach (array_slice($this->users,1) as $target) {
            wp_set_current_user($this->users[0]);$request=Connections::request($target);
            wp_set_current_user($target);Connections::respond($request['request_id'],'accept');
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach (Store::rows('conversations','created_by=%d',[$this->users[0]],'ORDER BY id ASC') as $conversation) {
            foreach (['messages','participants'] as $table) { Store::delete($table,['conversation_id'=>$conversation['id']]); }
            Store::delete('conversations',['id'=>$conversation['id']]);
        }
        foreach ($this->users as $id) {
            foreach (['relations','notifications','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('relations',['target_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function failInsert(string $table,callable $operation,int $at=1): void
    {
        global $wpdb;$before=$wpdb->suppress_errors(true);$count=0;
        $fail=static function($sql) use($table,$at,&$count) {
            if (str_starts_with($sql,'INSERT INTO `'.Store::table($table).'`') && ++$count===$at) { return str_replace(Store::table($table),Store::table($table).'_missing',$sql); }
            return $sql;
        };
        add_filter('query',$fail);
        try { $operation();self::fail('Expected a real SQL write failure'); }
        catch (RepositoryException) { self::assertSame($at,$count); }
        finally { remove_filter('query',$fail);$wpdb->suppress_errors($before);$wpdb->last_error=''; }
    }
    public function testGroupCreationRollsBackParticipantsAndNotificationsOnAnyLateFailure(): void
    {
        $where='created_by=%d';$before=Store::count('conversations',$where,[$this->users[0]]);
        $notices=Store::count('notifications','user_id IN (%d,%d)',array_slice($this->users,1));
        foreach ([['participants',2],['notifications',2],['audit',1]] as [$table,$at]) {
            $this->failInsert($table,fn()=>Messaging::createGroup('Grupo atómico',array_slice($this->users,1)),$at);
            self::assertSame($before,Store::count('conversations',$where,[$this->users[0]]));
            self::assertSame($notices,Store::count('notifications','user_id IN (%d,%d)',array_slice($this->users,1)));
        }
        $group=Messaging::createGroup('Grupo válido',array_slice($this->users,1));
        self::assertSame(3,Store::count('participants','conversation_id=%d',[(int)$group['id']]));
        self::assertSame($notices+2,Store::count('notifications','user_id IN (%d,%d)',array_slice($this->users,1)));
    }
    public function testGroupEditAndMessageDeliveryAreAtomicAndHistoryRemainsImmutable(): void
    {
        $group=Messaging::createGroup('Título anterior',array_slice($this->users,1));$id=(int)$group['id'];
        $before=Store::one('conversations',$id);
        $this->failInsert('audit',fn()=>Messaging::updateGroup($id,'Título nuevo','Descripción nueva'));
        self::assertSame($before,Store::one('conversations',$id));
        $updated=Messaging::updateGroup($id,'Título nuevo','Descripción nueva');self::assertSame('Título nuevo',$updated['title']);
        $before=Store::one('conversations',$id);$notices=Store::count('notifications','user_id IN (%d,%d)',array_slice($this->users,1));
        $delivered=[];
        $capture=static function($result,$mail) use(&$delivered) {
            if ($result!==null) { return $result; }
            $delivered[]=$mail;return true;
        };
        add_filter('pre_wp_mail',$capture,PHP_INT_MAX,2);
        try {
            $this->failInsert('notifications',fn()=>Messaging::send($id,'Mensaje no confirmado'),2);
            self::assertSame(0,Store::count('messages','conversation_id=%d',[$id]));
            self::assertSame($before,Store::one('conversations',$id));self::assertSame($notices,Store::count('notifications','user_id IN (%d,%d)',array_slice($this->users,1)));
            self::assertSame([],$delivered,'No group mail may leave a rolled back send.');
            $message=Messaging::send($id,'Mensaje confirmado');self::assertSame(1,Store::count('messages','conversation_id=%d',[$id]));
            self::assertSame($notices+2,Store::count('notifications','user_id IN (%d,%d)',array_slice($this->users,1)));
            self::assertCount(2,$delivered,'Each recipient receives mail only after the group send commits.');
            try { Messaging::removeMessage($id,(int)$message['id']);self::fail('History must remain immutable'); }
            catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(403,$error->getCode()); }
            self::assertNotNull(Store::one('messages',(int)$message['id']));
        } finally { remove_filter('pre_wp_mail',$capture,PHP_INT_MAX); }
    }
}
