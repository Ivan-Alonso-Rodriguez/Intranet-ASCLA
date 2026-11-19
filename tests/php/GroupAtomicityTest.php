<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Connections,ConversationVisibility,Messaging};
use ASCLA\Core\Repositories\{Store,RepositoryException};
use ASCLA\Core\Rest\ApiException;

final class GroupAtomicityTest extends TestCase
{
    private array $users=[];
    private array $transferredGroups=[];
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
        $owned=array_column(Store::rows('conversations','created_by=%d',[$this->users[0]],'ORDER BY id ASC'),'id');
        foreach (array_unique(array_merge($owned,$this->transferredGroups)) as $id) {
            foreach (['messages','participants'] as $table) { Store::delete($table,['conversation_id'=>$id]); }
            Store::delete('conversations',['id'=>$id]);
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
    private function assertRejected(int $status,callable $operation): void
    {
        try { $operation();self::fail('Expected the group operation to be rejected'); }
        catch (ApiException $error) { self::assertSame($status,$error->getCode()); }
    }
    public function testCreatorCanAddConfirmedConnectionsWithHistoryStartingAtJoin(): void
    {
        [$creator,$member,$other]=$this->users;
        $group=Messaging::createGroup('Grupo con nuevos integrantes',[$member,$other]);
        $id=(int)$group['id'];
        $old=Messaging::send($id,'Mensaje de los integrantes originales');
        $login='group_atomic_'.bin2hex(random_bytes(6));
        $new=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        self::assertIsInt($new);
        $this->users[]=$new;

        wp_set_current_user($member);
        self::assertFalse(Messaging::conversation($id)['can_add_group_members']);
        $this->assertRejected(403,fn()=>Messaging::addGroupMembers($id,[$new]));
        wp_set_current_user($new);
        $this->assertRejected(404,fn()=>Messaging::addGroupMembers($id,[$creator]));
        wp_set_current_user($creator);
        self::assertTrue(Messaging::conversation($id)['can_add_group_members']);
        $this->assertRejected(409,fn()=>Messaging::addGroupMembers($id,range(1000000,1000047)));
        $this->assertRejected(403,fn()=>Messaging::addGroupMembers($id,[$new]));

        $request=Connections::request($new);
        wp_set_current_user($new);
        Connections::respond($request['request_id'],'accept');
        wp_set_current_user($creator);
        $this->failInsert('audit',fn()=>Messaging::addGroupMembers($id,[$new]));
        self::assertSame(3,Messaging::conversation($id)['member_count']);
        $noticeFilter="user_id=%d AND kind='conversation_group'";
        self::assertSame(0,Store::count('notifications',$noticeFilter,[$new]));
        self::assertSame(4,Messaging::addGroupMembers($id,[$new,$new,$member])['member_count']);
        self::assertSame(4,Messaging::addGroupMembers($id,[$new])['member_count']);
        self::assertSame(1,Store::count('notifications',$noticeFilter,[$new]));
        self::assertSame(2,Messaging::messages($id)['items'][0]['read_total']);

        wp_set_current_user($new);
        $conversation=Messaging::conversation($id);
        self::assertSame('Conversación nueva',$conversation['preview']);
        self::assertSame(0,$conversation['unread']);
        self::assertSame([],Messaging::conversations($old['body']));
        self::assertSame([],Messaging::messages($id,0,0)['items']);
        self::assertSame([],Messaging::messages($id,(int)$old['id']+1)['items']);
        self::assertSame((int)$old['id'],Messaging::messages($id)['after']);
        $this->assertRejected(404,fn()=>ConversationVisibility::read($id,(int)$old['id']));
        $reply=Messaging::send($id,'Mensaje posterior a la incorporación');

        wp_set_current_user($creator);
        self::assertSame([(int)$old['id'],(int)$reply['id']],array_map('intval',array_column(Messaging::messages($id)['items'],'id')));
        $later=Messaging::send($id,'Mensaje para los cuatro integrantes');
        self::assertSame(3,Messaging::messages($id,0,(int)$reply['id'])['items'][0]['read_total']);
        wp_set_current_user($new);
        self::assertSame([(int)$reply['id'],(int)$later['id']],array_map('intval',array_column(Messaging::messages($id,0,0)['items'],'id')));
        self::assertSame(['read'=>true],ConversationVisibility::read($id,(int)$later['id']));
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
    public function testCreatorCanRemoveMembersWithoutDeletingOrClosingTheGroup(): void
    {
        [$creator,$removed,$remaining]=$this->users;
        $group=Messaging::createGroup('Grupo editable',[$removed,$remaining]);
        $id=(int)$group['id'];
        $this->assertRejected(400,fn()=>Messaging::removeGroupMember($id,$creator));
        wp_set_current_user($remaining);
        self::assertFalse(Messaging::conversation($id)['can_remove_group_members']);
        $this->assertRejected(403,fn()=>Messaging::removeGroupMember($id,$removed));
        wp_set_current_user($removed);
        $old=Messaging::send($id,'Mensaje que permanece en el historial');
        wp_set_current_user($creator);
        $this->failInsert('audit',fn()=>Messaging::removeGroupMember($id,$removed));
        self::assertSame(3,Messaging::conversation($id)['member_count']);
        self::assertTrue(Messaging::conversation($id)['can_remove_group_members']);
        self::assertSame(2,Messaging::removeGroupMember($id,$removed)['member_count']);
        $audit="action='group_conversation_updated' AND object_id=%d AND detail=%s";
        self::assertSame(2,Messaging::removeGroupMember($id,$removed)['member_count']);
        self::assertSame(1,Store::count('audit',$audit,[$id,'member_removed_'.$removed]));

        wp_set_current_user($removed);
        self::assertSame([],Messaging::conversations('Grupo editable'));
        $this->assertRejected(404,fn()=>Messaging::conversation($id));
        $this->assertRejected(404,fn()=>Messaging::messages($id));
        $this->assertRejected(404,fn()=>Messaging::send($id,'Mensaje sin acceso'));
        $this->assertRejected(404,fn()=>ConversationVisibility::read($id,(int)$old['id']));
        $this->assertRejected(404,fn()=>Messaging::removeGroupMember($id,$remaining));
        $noticeFilter="user_id=%d AND kind='message'";
        $notices=Store::count('notifications',$noticeFilter,[$removed]);

        wp_set_current_user($remaining);
        $history=Messaging::messages($id)['items'];
        self::assertSame($old['body'],$history[0]['body']);
        self::assertSame('member_removed',$history[1]['kind']);
        self::assertStringContainsString('expulsó',$history[1]['body']);
        self::assertArrayNotHasKey('read_total',$history[1]);
        $latest=Messaging::send($id,'Mensaje después de la expulsión');
        self::assertSame($notices,Store::count('notifications',$noticeFilter,[$removed]));
        wp_set_current_user($creator);
        $solo=Messaging::removeGroupMember($id,$remaining);
        self::assertSame(1,$solo['member_count']);
        self::assertFalse($solo['can_remove_group_members']);
        self::assertTrue($solo['can_add_group_members']);
        Messaging::addGroupMembers($id,[$removed]);
        wp_set_current_user($removed);
        $rejoined=Messaging::messages($id);
        self::assertSame([],$rejoined['items']);
        self::assertGreaterThan((int)$latest['id'],$rejoined['after']);
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

    public function testLeavingTransfersControlAndDeletingCleansUpTheWholeGroup(): void
    {
        [$creator,$first,$second]=$this->users;
        $photo=Store::insert('media',['user_id'=>$creator,'post_id'=>0,'name'=>'group-test.png','mime'=>'image/png',
            'bytes'=>'fixture photo','created_at'=>current_time('mysql',true)]);
        try {
            $group=Messaging::createGroup('Grupo con responsable nuevo',[$first,$second],$photo);
            $id=(int)$group['id'];
            $this->transferredGroups[]=$id;
            $message=Messaging::send($id,'Historial que conserva el nuevo responsable');
            $this->failInsert('audit',fn()=>Messaging::leaveGroup($id));
            self::assertSame($creator,Messaging::conversation($id)['created_by']);
            self::assertSame(3,Messaging::conversation($id)['member_count']);
            self::assertSame(['message'],array_column(Messaging::messages($id)['items'],'kind'));
            $noticeFilter="kind='conversation_group_owner' AND user_id IN (%d,%d)";
            self::assertSame(0,Store::count('notifications',$noticeFilter,[$first,$second]));

            self::assertTrue(Messaging::leaveGroup($id)['left']);
            self::assertSame(1,Store::count('notifications',$noticeFilter,[$first,$second]));
            $this->assertRejected(404,fn()=>Messaging::messages($id));
            $this->assertRejected(404,fn()=>Messaging::removeGroup($id));
            $successor=(int)Store::one('conversations',$id)['created_by'];
            self::assertContains($successor,[$first,$second]);
            $remaining=$successor===$first?$second:$first;
            wp_set_current_user($remaining);
            self::assertFalse(Messaging::conversation($id)['can_delete_group']);
            ConversationVisibility::hide($id);
            wp_set_current_user($successor);
            $managed=Messaging::conversation($id);
            foreach (['can_edit_group','can_add_group_members','can_remove_group_members','can_leave_group','can_delete_group'] as $permission) {
                self::assertTrue($managed[$permission],$permission);
            }
            self::assertSame($message['body'],Messaging::messages($id)['items'][0]['body']);
            self::assertSame($photo,Messaging::updateGroup($id,'Nuevo título','Nueva descripción',$photo)['photo_id']);
            self::assertSame(3,Messaging::addGroupMembers($id,[$creator])['member_count']);
            self::assertSame(2,Messaging::removeGroupMember($id,$creator)['member_count']);
            wp_set_current_user($remaining);
            ConversationVisibility::hide($id);
            wp_set_current_user($successor);

            $notices=Store::count('notifications','user_id IN (%d,%d)',[$first,$second]);
            $this->failInsert('audit',fn()=>Messaging::removeGroup($id));
            self::assertSame($notices,Store::count('notifications','user_id IN (%d,%d)',[$first,$second]));
            self::assertNotNull(Store::one('messages',(int)$message['id']));
            self::assertTrue(ConversationVisibility::hidden($id,$remaining));
            self::assertTrue(Messaging::removeGroup($id)['deleted']);
            self::assertSame(0,Store::count('participants','conversation_id=%d',[$id]));
            self::assertSame(0,Store::count('messages','conversation_id=%d',[$id]));
            self::assertSame(0,Store::count('notifications',$noticeFilter,[$first,$second]));
            self::assertFalse(ConversationVisibility::hidden($id,$remaining));
            self::assertNotNull(Store::one('media',$photo));
            wp_set_current_user($remaining);
            $this->assertRejected(404,fn()=>Messaging::messages($id));
        } finally { Store::delete('media',['id'=>$photo]); }
    }

    public function testRegularMemberCanLeaveAndOthersSeeOneActivityNotice(): void
    {
        [$creator,$first,$second]=$this->users;
        $id=(int)Messaging::createGroup('Grupo con salida voluntaria',[$first,$second])['id'];
        $old=Messaging::send($id,'Antes de la salida');
        wp_set_current_user($first);
        self::assertTrue(Messaging::conversation($id)['can_leave_group']);
        $this->failInsert('messages',fn()=>Messaging::leaveGroup($id));
        self::assertSame(3,Messaging::conversation($id)['member_count']);
        self::assertCount(1,Messaging::messages($id)['items']);
        self::assertTrue(Messaging::leaveGroup($id)['left']);
        $this->assertRejected(404,fn()=>Messaging::leaveGroup($id));
        $this->assertRejected(404,fn()=>Messaging::messages($id));
        wp_set_current_user($creator);
        $group=Messaging::conversation($id);
        self::assertSame($creator,$group['created_by']);
        self::assertSame(2,$group['member_count']);
        self::assertStringContainsString('salió del grupo',$group['preview']);
        $polled=Messaging::messages($id,0,(int)$old['id'])['items'];
        self::assertCount(1,$polled);
        self::assertSame('member_left',$polled[0]['kind']);
        self::assertArrayNotHasKey('read_total',$polled[0]);
    }

    public function testOwnerCanDeleteASoloGroup(): void
    {
        [$creator,$first,$second]=$this->users;
        $group=Messaging::createGroup('Grupo sin sucesor',[$first,$second]);
        $id=(int)$group['id'];
        Messaging::removeGroupMember($id,$first);
        Messaging::removeGroupMember($id,$second);
        self::assertFalse(Messaging::conversation($id)['can_leave_group']);
        $this->assertRejected(409,fn()=>Messaging::leaveGroup($id));
        self::assertSame($creator,Messaging::conversation($id)['created_by']);
        self::assertTrue(Messaging::removeGroup($id)['deleted']);
    }
}
