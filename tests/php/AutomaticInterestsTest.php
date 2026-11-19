<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{AutomaticInterests,LearnedInterests,Profiles,Content};
use ASCLA\Core\Repositories\Store;

final class AutomaticInterestsTest extends TestCase
{
    private array $users=[],$posts=[],$terms=[];
    private int $admin,$member,$manual,$automatic;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_member'] as $role){$login='automatic_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32),'user_email'=>$login.'@example.invalid','role'=>$role]);}
        [$this->admin,$this->member]=$this->users;wp_set_current_user($this->member);
        $term=wp_insert_term('Tema automático '.bin2hex(random_bytes(5)),'ascla_interest');$this->automatic=(int)$term['term_id'];$this->terms[]=$this->automatic;
        $this->manual=(int)Profiles::catalogs()['interest'][0]['id'];
        update_user_meta($this->member,'_ascla_profile',ascla_test_profile(['interests'=>[$this->manual],'networking'=>false]));
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);foreach($this->posts as $id){wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);}
        foreach($this->terms as $id){wp_delete_term($id,'ascla_interest');}
        foreach($this->users as $id){foreach(['relations','notifications','user_interests'] as $table){Store::delete($table,['user_id'=>$id]);}Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);LearnedInterests::invalidate($id);}
        wp_set_current_user(0);
    }
    private function post(string $taxonomy='ascla_interest'): int
    {
        wp_set_current_user($this->admin);$id=wp_insert_post(['post_type'=>'ascla_hub','post_status'=>'publish','post_title'=>'Señal automática','post_content'=>'Contenido de prueba','post_author'=>$this->admin]);
        $this->posts[]=$id;update_post_meta($id,'_ascla',['chatham'=>false]);
        if($taxonomy==='ascla_interest'){wp_set_object_terms($id,[$this->automatic],$taxonomy);}
        else{wp_set_object_terms($id,get_term($this->automatic)->name,$taxonomy);}
        wp_set_current_user($this->member);return $id;
    }
    private function learn(): int {$post=$this->post();Content::react($post,'like',true);AutomaticInterests::synchronize($this->member);return $post;}
    public function testLikeAutomaticallySelectsTheCanonicalInterestAndPreservesOtherProfileData(): void
    {
        $before=Profiles::raw($this->member);$this->learn();$after=Profiles::raw($this->member);
        self::assertContains($this->automatic,$after['interests']);self::assertContains($this->manual,$after['interests']);
        unset($before['interests'],$before['revision'],$after['interests'],$after['revision']);self::assertSame($before,$after);
        self::assertSame([$this->automatic],get_user_meta($this->member,'_ascla_interest_choices',true)['automatic']);
        self::assertSame(1,Store::count('user_interests',"user_id=%d AND term_id=%d AND source='profile'",[$this->member,$this->automatic]));
    }
    public function testAValidManualRemovalPersistsAndFurtherSignalsCannotReactivateIt(): void
    {
        $this->learn();Profiles::save(['interests'=>[$this->manual]]);
        self::assertContains($this->automatic,AutomaticInterests::excluded($this->member));
        foreach(range(1,3) as $i){Content::react($this->post(),'like',true);}
        LearnedInterests::invalidate($this->member);AutomaticInterests::synchronize($this->member);
        self::assertNotContains($this->automatic,Profiles::visible($this->member)['interests']);
        self::assertNotContains($this->automatic,array_column(LearnedInterests::get()['topics'],'id'));
        self::assertSame(4,Store::count('relations',"user_id=%d AND kind='like'",[$this->member]),'Removing an interest preserves actual likes.');
    }
    public function testExplicitReselectionIsTheOnlyWayToLiftTheManualExclusion(): void
    {
        $this->learn();Profiles::save(['interests'=>[$this->manual]]);
        Profiles::save(['interests'=>[$this->manual,$this->automatic]]);
        self::assertNotContains($this->automatic,AutomaticInterests::excluded($this->member));self::assertContains($this->automatic,Profiles::raw($this->member)['interests']);
        Profiles::save(['interests'=>[$this->manual]]);self::assertContains($this->automatic,AutomaticInterests::excluded($this->member));
    }
    public function testPartialUpdatesAndReloadsDoNotForgetTheManualDecision(): void
    {
        $this->learn();Profiles::save(['interests'=>[$this->manual]]);Profiles::save(['bio'=>'Biografía actualizada']);
        self::assertContains($this->automatic,AutomaticInterests::excluded($this->member));
        LearnedInterests::invalidate($this->member);self::assertNotContains($this->automatic,Profiles::visible($this->member)['interests']);
        self::assertSame('Biografía actualizada',Profiles::raw($this->member)['bio']);
    }
    public function testStaleReadTicketsAndCachedLearningCannotOverrideAManualRemoval(): void
    {
        $post=$this->learn();$ticket=LearnedInterests::start($post)['ticket'];$key='ascla_read_'.$this->member.'_'.$post;$value=get_transient($key);$value['started']=time()-16;set_transient($key,$value,600);
        self::assertNotEmpty(LearnedInterests::get()['topics']);Profiles::save(['interests'=>[$this->manual]]);
        LearnedInterests::read($post,$ticket);AutomaticInterests::synchronize($this->member);
        self::assertNotContains($this->automatic,Profiles::raw($this->member)['interests']);self::assertContains($this->automatic,AutomaticInterests::excluded($this->member));
    }
    public function testFailedManualSaveRollsBackBothProfileAndExclusions(): void
    {
        $this->learn();$reject=static fn($r,$id,$key)=>$key==='_ascla_profile'?false:$r;add_filter('update_user_metadata',$reject,10,3);
        try{Profiles::save(['interests'=>[$this->manual]]);self::fail('Write must fail');}catch(ASCLA\Core\Repositories\RepositoryException $error){self::assertNotEmpty($error->getMessage());}finally{remove_filter('update_user_metadata',$reject,10);}
        self::assertContains($this->automatic,Profiles::raw($this->member)['interests']);self::assertNotContains($this->automatic,AutomaticInterests::excluded($this->member));
    }
    public function testFailedAutomaticSelectionRollsBackTheOriginAndCanBeRetried(): void
    {
        Content::react($this->post(),'like',true);$reject=static fn($r,$id,$key)=>$key==='_ascla_profile'?false:$r;add_filter('update_user_metadata',$reject,10,3);
        try{AutomaticInterests::synchronize($this->member);self::fail('Write must fail');}catch(ASCLA\Core\Repositories\RepositoryException $error){self::assertNotEmpty($error->getMessage());}finally{remove_filter('update_user_metadata',$reject,10);}
        self::assertNotContains($this->automatic,Profiles::raw($this->member)['interests']);self::assertSame('',get_user_meta($this->member,'_ascla_interest_choices',true));
        AutomaticInterests::synchronize($this->member);self::assertContains($this->automatic,Profiles::raw($this->member)['interests']);
    }
    public function testASingleReadingAndNonCanonicalTagsDoNotCheckProfileInterests(): void
    {
        $post=$this->post();Store::insert('relations',['user_id'=>$this->member,'target_id'=>$post,'kind'=>'interest_read','created_at'=>current_time('mysql',true)]);
        AutomaticInterests::synchronize($this->member);self::assertNotContains($this->automatic,Profiles::raw($this->member)['interests']);
        Content::react($this->post('ascla_tag'),'like',true);AutomaticInterests::synchronize($this->member);
        self::assertNotContains($this->automatic,Profiles::raw($this->member)['interests']);
    }
}
