<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Connections,Profiles,Matching,Messaging,Settings,IntroSuggestions,ConversationVisibility};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Rest\ApiException;

final class IntroSuggestionsTest extends TestCase
{
    private array $users=[],$chats=[],$settings=[];
    protected function setUp(): void
    {
        $this->settings=Settings::get();
        Settings::save(['ai_mode'=>'mock']);
        foreach (range(1,3) as $i) {
            $id=wp_insert_user(['user_login'=>'intro_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
            self::assertIsInt($id);$this->users[]=$id;wp_set_current_user($id);
            Profiles::save(ascla_test_profile(['first_name'=>'Persona '.$i,'networking'=>true,'directory'=>true]));
        }
        foreach ([1,2] as $index) {
            wp_set_current_user($this->users[0]);$request=Connections::request($this->users[$index]);
            wp_set_current_user($this->users[$index]);Connections::respond($request['request_id'],'accept');
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach ($this->chats as $id) { foreach (['messages','participants'] as $table) { Store::delete($table,['conversation_id'=>$id]); }Store::delete('conversations',['id'=>$id]); }
        foreach ($this->users as $id) { foreach (['relations','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); }wp_delete_user($id); }
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function direct(int $to): int
    {
        $id=(int)Messaging::start($to)['id'];$this->chats[]=$id;return $id;
    }
    private function denied(callable $call,int $code=409): void
    {
        try { $call();self::fail('The draft must not be offered.'); }
        catch (ApiException $error) { self::assertSame($code,$error->getCode()); }
    }
    public function testNewConnectionHasOneEditableDraftWithoutSendingAnything(): void
    {
        $other=$this->users[1];
        self::assertTrue(Connections::profile($other)['connection']['can_suggest_intro']);
        $before=Store::count('messages','1=1',[]);
        $draft=Matching::intro($other);
        self::assertFalse($draft['sent']);self::assertNotEmpty($draft['text']);
        self::assertSame($before,Store::count('messages','1=1',[]));
        self::assertFalse(Connections::profile($other)['connection']['can_suggest_intro']);
        $this->denied(fn()=>Matching::intro($other));
        // Canceling the draft still counts as using the control, across sessions.
        wp_set_current_user(0);wp_cache_delete($this->users[0],'user_meta');wp_set_current_user($this->users[0]);
        self::assertFalse(Connections::profile($other)['connection']['can_suggest_intro']);
        self::assertTrue(Connections::profile($this->users[2])['connection']['can_suggest_intro']);
    }
    public function testUseIsPerRequesterUntilEitherPersonSendsAMessage(): void
    {
        Matching::intro($this->users[1]);
        wp_set_current_user($this->users[1]);
        self::assertTrue(Connections::profile($this->users[0])['connection']['can_suggest_intro']);
        $id=$this->direct($this->users[0]);Messaging::send($id,'Mensaje anterior');
        wp_set_current_user($this->users[0]);
        self::assertFalse(Connections::profile($this->users[1])['connection']['can_suggest_intro']);
        self::assertFalse(IntroSuggestions::availableFor($this->users[1],[$this->users[0]])[$this->users[0]]);
    }
    public function testPreviousOutgoingAndIncomingMessagesHideTheDraftEvenIfChatIsHidden(): void
    {
        $id=$this->direct($this->users[1]);Messaging::send($id,'Ya conversamos');
        ConversationVisibility::hide($id);
        self::assertFalse(Connections::profile($this->users[1])['connection']['can_suggest_intro']);
        $this->denied(fn()=>Matching::intro($this->users[1]));
        wp_set_current_user($this->users[1]);
        self::assertFalse(Connections::profile($this->users[0])['connection']['can_suggest_intro']);
        $this->denied(fn()=>Matching::intro($this->users[0]));
    }
    public function testAnEmptyChatOrGroupMessagesDoNotCountAsDirectInteraction(): void
    {
        $this->direct($this->users[1]);
        self::assertTrue(Connections::profile($this->users[1])['connection']['can_suggest_intro']);
        $group=Messaging::createGroup('Grupo de prueba',[$this->users[1],$this->users[2]]);$id=(int)$group['id'];$this->chats[]=$id;
        Messaging::send($id,'Mensaje de grupo');
        self::assertTrue(Connections::profile($this->users[1])['connection']['can_suggest_intro']);
        self::assertTrue(Connections::profile($this->users[2])['connection']['can_suggest_intro']);
    }
    public function testBatchDecoratesProfilesWithoutChangingConnectionPermissions(): void
    {
        $id=$this->direct($this->users[1]);Messaging::send($id,'Antecedente');
        $profiles=Connections::attach(array_map([Profiles::class,'card'],array_slice($this->users,1)));
        self::assertFalse($profiles[0]['connection']['can_suggest_intro']);
        self::assertTrue($profiles[1]['connection']['can_suggest_intro']);
        foreach ($profiles as $profile) { self::assertTrue($profile['connection']['can_message']); }
    }
    public function testClaimsAreExclusiveAndAFailedGenerationCanReleaseItsClaim(): void
    {
        [$me,$target]=$this->users;
        $claim=IntroSuggestions::claim($me,$target);
        $this->denied(fn()=>IntroSuggestions::claim($me,$target));
        IntroSuggestions::release($claim,$me);
        self::assertTrue(IntroSuggestions::availableFor($me,[$target])[$target]);
        self::assertGreaterThan(0,IntroSuggestions::claim($me,$target));
    }
    public function testExistingSavedIntroductionsRemainUsedAfterTheirOldCacheIsEvicted(): void
    {
        [$me,$target]=$this->users;
        // Simulate a successful introduction saved before this update.
        delete_user_meta($me,'_ascla_intro_history_imported');
        update_user_meta($me,'_ascla_network_ai',['intro:'.$target=>['hash'=>'previous','result'=>['text'=>'Borrador anterior guardado.']]]);
        self::assertFalse(Connections::profile($target)['connection']['can_suggest_intro']);
        delete_user_meta($me,'_ascla_network_ai');
        self::assertFalse(Connections::profile($target)['connection']['can_suggest_intro']);
        $this->denied(fn()=>Matching::intro($target));
    }
    public function testReadOnlyMembersDoNotSeeTheSuggestionControl(): void
    {
        (new WP_User($this->users[0]))->add_cap('ascla_write',false);
        wp_set_current_user(0);wp_set_current_user($this->users[0]);
        self::assertFalse(Connections::profile($this->users[1])['connection']['can_suggest_intro']);
        $this->denied(fn()=>Matching::intro($this->users[1]),403);
    }
    public function testAnonymousAndBlockedAccountsCannotGenerateAnIntroduction(): void
    {
        $target=$this->users[1];Messaging::relation($target,'block',true);
        $this->denied(fn()=>Matching::intro($target),400);
        wp_set_current_user(0);
        $request=new WP_REST_Request('POST','/ascla/v1/matching/'.$target.'/intro');
        self::assertSame(401,rest_do_request($request)->get_status());
    }
}
