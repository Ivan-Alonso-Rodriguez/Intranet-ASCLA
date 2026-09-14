<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Messaging,Settings};
use ASCLA\Core\Integrations\{Mailer,Secrets};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Rest\ApiException;

final class MessagingMailTest extends TestCase
{
    private array $users=[],$settings=[]; private string $secret=''; private int $conversation=0;
    protected function setUp(): void
    {
        $this->settings=Settings::get(); $this->secret=Secrets::get('smtp_password');
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'mailmsg_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        Store::insert('relations',['user_id'=>$this->users[0],'target_id'=>$this->users[1],'kind'=>'connected','created_at'=>current_time('mysql',true)]);
        $this->conversation=(int)Messaging::start($this->users[1])['id'];
    }
    protected function tearDown(): void
    {
        foreach (['messages','participants'] as $t) Store::delete($t,['conversation_id'=>$this->conversation]);
        Store::delete('conversations',['id'=>$this->conversation]);
        foreach($this->users as $id) { Store::delete('notifications',['user_id'=>$id]); Store::delete('relations',['user_id'=>$id]); wp_delete_user($id); }
        update_option('ascla_settings',$this->settings,false); Secrets::remove('smtp_password');
        if ($this->secret!=='') Secrets::set('smtp_password',$this->secret);
        wp_set_current_user(0);
    }
    public function testIncrementalMessagesNeverSkipBatchesAndKeepReadCursorMonotonic(): void
    {
        $ids=[];
        foreach(range(1,135) as $i) $ids[]=Store::insert('messages',['conversation_id'=>$this->conversation,'sender_id'=>$this->users[0],'body'=>'Mensaje '.$i,'created_at'=>current_time('mysql',true)]);
        wp_set_current_user($this->users[1]);
        self::assertSame(135,Messaging::conversation($this->conversation)['unread']);
        $first=Messaging::messages($this->conversation,0,0);
        self::assertCount(60,$first['items']); self::assertTrue($first['has_more']);
        self::assertSame(array_slice($ids,0,60),array_map('intval',array_column($first['items'],'id')));
        self::assertSame(75,Messaging::conversation($this->conversation)['unread']);
        $next=Messaging::messages($this->conversation,0,$first['after']);
        self::assertCount(60,$next['items']);self::assertTrue($next['has_more']);
        $last=Messaging::messages($this->conversation,0,$next['after']);
        self::assertCount(15,$last['items']);self::assertFalse($last['has_more']);
        self::assertSame(0,Messaging::conversation($this->conversation)['unread']);
        Messaging::messages($this->conversation,0,0);
        self::assertSame(0,Messaging::conversation($this->conversation)['unread']);
        self::assertSame([],Messaging::messages($this->conversation,0,$last['after'])['items']);
        $recent=Messaging::messages($this->conversation);
        self::assertSame(array_slice($ids,-60),array_map('intval',array_column($recent['items'],'id')));
        $older=Messaging::messages($this->conversation,$recent['before']);
        self::assertCount(60,$older['items']);self::assertTrue($older['has_more']);
        self::assertCount(15,Messaging::messages($this->conversation,$older['before'])['items']);
        self::assertSame(0,Messaging::conversation($this->conversation)['unread']);
    }
    public function testIncrementalEndpointsRetainParticipantChecksAndRejectMixedCursors(): void
    {
        wp_set_current_user($this->users[2]);
        $request=new WP_REST_Request('GET','/ascla/v1/conversations/'.$this->conversation.'/messages'); $request->set_param('after',0);
        self::assertSame(404,rest_do_request($request)->get_status());
        wp_set_current_user($this->users[1]);$request->set_param('before',1);
        self::assertSame(400,rest_do_request($request)->get_status());
        $request=new WP_REST_Request('POST','/ascla/v1/mail/test');
        self::assertSame(403,rest_do_request($request)->get_status());
        wp_set_current_user(0);self::assertSame(401,rest_do_request($request)->get_status());
    }
    public function testSmtpSecretIsEncryptedAndNeverReturnedOrDestroyedByBlankForm(): void
    {
        $secret='smtp-test-&<>-'.bin2hex(random_bytes(12));
        $s=Settings::save(['mail_mode'=>'smtp','smtp_host'=>'smtp.example.com','smtp_port'=>587,'smtp_security'=>'tls','smtp_user'=>'sender@example.com','smtp_from'=>'sender@example.com','smtp_password'=>$secret]);
        self::assertSame($secret,Secrets::get('smtp_password'));
        self::assertStringNotContainsString($secret,wp_json_encode($s));
        self::assertStringNotContainsString($secret,(string)get_option('ascla_secret_smtp_password'));
        self::assertTrue($s['has_smtp_password']);
        Settings::save(['smtp_password'=>'']);self::assertSame($secret,Secrets::get('smtp_password'));
        Settings::save(['mail_mode'=>'wordpress','clear_smtp_password'=>true]); self::assertSame('',Secrets::get('smtp_password'));
    }
    public function testInvalidSmtpSettingsAreRejectedBeforePersisting(): void
    {
        $before=Settings::get();
        foreach ([['mail_mode'=>'smtp'],['smtp_host'=>'smtp.example.com;evil'],['smtp_from'=>"sender@example.com\nBcc: victim@example.com"],['smtp_security'=>'none'],['smtp_port'=>80],['smtp_password'=>"bad\nvalue"]] as $bad) {
            try { Settings::save($bad); self::fail('Invalid SMTP accepted'); } catch (ApiException $e) { self::assertSame(400,$e->getCode()); }
            self::assertSame($before,Settings::get());
        }
    }
    public function testMailResultContainsOnlyStatusTimeAndTransport(): void
    {
        $saved=get_option('ascla_mail_result',null);
        do_action('wp_mail_failed',new WP_Error('smtp','Password secret',[ 'message'=>'reset token private', 'to'=>'private@example.com']));
        $result=Mailer::status()['mail_last_result'];
        self::assertSame(['status','at','transport'],array_keys($result)); self::assertSame('failed',$result['status']);
        self::assertStringNotContainsString('secret',wp_json_encode($result));
        if ($saved===null) delete_option('ascla_mail_result'); else update_option('ascla_mail_result',$saved,false);
    }
}
