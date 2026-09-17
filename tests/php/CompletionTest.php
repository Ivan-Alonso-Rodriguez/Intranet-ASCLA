<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Profiles,Events,Discovery,Notifications,Knowledge,Settings,Media};
use ASCLA\Core\Repositories\{Store,ContentQuery};
use ASCLA\Core\Domain\Transcript;

final class CompletionTest extends TestCase
{
    private array $users=[]; private array $posts=[]; private array $terms=[]; private array $settings=[];
    protected function setUp(): void
    {
        $this->settings=Settings::get();
        foreach (['administrator','ascla_member','ascla_member'] as $i=>$role) {
            $id=wp_insert_user(['user_login'=>'completion_'.bin2hex(random_bytes(5)), 'user_pass'=>wp_generate_password(30),'display_name'=>'Autor QZCompleto '.$i,'role'=>$role]);
            self::assertIsInt($id); $this->users[]=$id;
        }
        wp_set_current_user($this->users[0]); Settings::save(['ai_mode'=>'mock','youtube_mode'=>'mock']);
        foreach (['interest','category','area'] as $tax) { $term=wp_insert_term('Cierre '.bin2hex(random_bytes(4)),'ascla_'.$tax); $this->terms[$tax]=(int)$term['term_id']; }
        foreach (array_slice($this->users,1) as $id) { wp_set_current_user($id); Profiles::save(['networking'=>true,'interests'=>[$this->terms['interest']],'areas'=>[$this->terms['area']]]); }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) { wp_delete_post($id,true); Store::delete('registrations',['event_id'=>$id]); Store::delete('media',['post_id'=>$id]); }
        foreach ($this->users as $id) { Store::delete('notifications',['user_id'=>$id]); wp_delete_user($id); }
        foreach ($this->terms as $tax=>$id) { wp_delete_term($id,'ascla_'.$tax); }
        update_option('ascla_settings',$this->settings,false); wp_set_current_user(0);
    }
    private function create(string $type,array $data=[]): array
    {
        $p=Content::save($type,array_replace_recursive(['title'=>'QZCierre '.$type,'body'=>'Gobierno corporativo y responsabilidades.','status'=>'publish'],$data));
        $this->posts[]=$p['id']; return $p;
    }
    public function testSearchFindsAuthorsSourceTopicsAndCombinedFiltersBeforePagination(): void
    {
        $p=$this->create('resource',['interest'=>[$this->terms['interest']],'category'=>[$this->terms['category']],'tag_names'=>['Clave '.bin2hex(random_bytes(3))],'meta'=>['source'=>'Fuente QZSOURCE987']]);
        $keyword=wp_get_object_terms($p['id'],'ascla_tag')[0]; $this->terms['tag']=$keyword->term_id;
        wp_set_current_user($this->users[1]);
        self::assertTrue(array_is_list(Profiles::catalogs()['tag']), 'Filtered catalogs must encode as JSON lists.');
        foreach (['QZCompleto','QZSOURCE987',get_term($this->terms['interest'])->name,$keyword->name] as $q) { self::assertContains($p['id'],array_column(Content::listing('resource',['q'=>$q])['items'],'id')); }
        $filters=['author'=>$this->users[0],'category'=>$this->terms['category'],'tag'=>$this->terms['interest'],'keyword'=>$keyword->term_id];
        self::assertSame([$p['id']],array_column(Content::listing('resource',$filters)['items'],'id'));
        $filters['keyword']=999999; self::assertSame(0,Content::listing('resource',$filters)['total']);
        self::assertContains((string)$this->users[0],array_column(ContentQuery::authors(),'id'));
    }
    public function testEventOrderAndMonthQueriesWorkBeyondFirstPage(): void
    {
        $ids=[];
        for ($i=0;$i<23;$i++) {
            $start=strtotime('2033-05-01T12:00:00Z')+$i*86400;
            $p=$this->create('event',['title'=>'Orden QZC '.$i,'meta'=>['start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600)]]); $ids[]=$p['id'];
        }
        wp_set_current_user($this->users[1]);
        $one=Content::listing('event',['q'=>'Orden QZC','past'=>0]); $two=Content::listing('event',['q'=>'Orden QZC','past'=>0,'page'=>2]);
        self::assertSame($ids,array_column(array_merge($one['items'],$two['items']),'id'));
        $month=Content::listing('event',['month'=>'2033-05','per_page'=>100,'tz_offset'=>300]);
        self::assertSame($ids,array_column($month['items'],'id'));
        self::assertSame([],Content::listing('event',['month'=>'2033-06'])['items']);
        $this->expectException(ASCLA\Core\Rest\ApiException::class); Content::listing('event',['month'=>'2033-99']);
    }
    public function testMonthBoundsPreserveLastDayEastOfUtc(): void
    {
        $included=$this->create('event',['meta'=>['start'=>'2033-05-31T12:00:00Z','end'=>'2033-05-31T13:00:00Z']]);
        $excluded=$this->create('event',['meta'=>['start'=>'2033-05-31T16:00:00Z','end'=>'2033-05-31T17:00:00Z']]);
        wp_set_current_user($this->users[1]);
        $ids=array_column(Content::listing('event',['month'=>'2033-05','tz_offset'=>-540,'per_page'=>100])['items'],'id');
        self::assertContains($included['id'],$ids);
        self::assertNotContains($excluded['id'],$ids);
    }
    public function testWorkerSchedulesContinuationWhenBacklogRemains(): void
    {
        $jobs=[];
        try {
            for($i=0;$i<5;$i++) { $jobs[]=ASCLA\Core\Jobs\Queue::enqueue('answer',['question'=>'QZsinfuente89764'])['id']; }
            wp_clear_scheduled_hook('ascla_jobs_continue');
            ASCLA\Core\Jobs\Queue::run();
            self::assertNotFalse(wp_next_scheduled('ascla_jobs_continue'));
        } finally { foreach($jobs as $id) { Store::delete('jobs',['id'=>$id]); } }
    }
    public function testForumFilterDistinguishesGeneralAndCategories(): void
    {
        $forum=$this->create('forum');
        $topic=$this->create('topic',['parent'=>$forum['id'],'category'=>[$this->terms['category']]]);
        $general=$this->create('topic',['category'=>[$this->terms['category']]]);
        wp_set_current_user($this->users[1]);
        self::assertSame([$topic['id']],array_column(Content::listing('topic',['parent'=>$forum['id']])['items'],'id'));
        self::assertSame([$general['id']],array_column(Content::listing('topic',['parent'=>0,'category'=>$this->terms['category']])['items'],'id'));
    }
    public function testInvitationsAreAuthorizedDeduplicatedAndDoNotReserveCapacity(): void
    {
        $p=$this->create('event',['meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200),'capacity'=>1]]);
        self::assertSame(2,Events::invite($p['id'],[$this->users[1],$this->users[2]])['sent']);
        self::assertSame(0,Events::invite($p['id'],[$this->users[1]])['sent']);
        self::assertSame(0,Events::detail($p['id'])['attending']);
        wp_set_current_user($this->users[1]); self::assertSame('invited',Events::detail($p['id'])['registered']);
        self::assertSame('event',Notifications::list()[0]['kind']);
        Events::register($p['id'],'accepted');
        $this->expectException(ASCLA\Core\Rest\ApiException::class); Events::invite($p['id'],[$this->users[2]]);
    }
    public function testKnowledgeRecommendationsUseKeywordsAsSecondarySignal(): void
    {
        $areaName=get_term($this->terms['area'],'ascla_area')->name;
        $direct=$this->create('resource',['title'=>'QZ Interés directo','interest'=>[$this->terms['interest']]]);
        $keyword=$this->create('resource',['title'=>'QZ Coincidencia por palabra clave','tag_names'=>[$areaName]]);
        $unrelated=$this->create('resource',['title'=>'QZ Sin relación','tag_names'=>['Tema completamente ajeno']]);
        wp_set_current_user($this->users[1]);
        $recommended=Content::listing('resource',['recommended'=>1,'per_page'=>100]);
        $ids=array_column($recommended['items'],'id');
        self::assertContains($direct['id'],$ids);
        self::assertContains($keyword['id'],$ids,'A keyword matching a profile knowledge area should reinforce recommendations.');
        self::assertNotContains($unrelated['id'],$ids,'Recency alone must not recommend unrelated content.');
        self::assertLessThan(array_search($keyword['id'],$ids,true),array_search($direct['id'],$ids,true),'Explicit interests must rank above keyword-only matches.');
        self::assertArrayHasKey('recommendation',$recommended['items'][0],'Recommended resources should explain their safe ranking signals to the UI.');

        // RF-041: recent positive activity should help discover related content
        // even when the activity topic is not part of the explicit profile.
        $activityTag='Actividad '.bin2hex(random_bytes(4));
        wp_set_current_user($this->users[0]);
        $activitySource=$this->create('resource',['title'=>'QZ Fuente de actividad','tag_names'=>[$activityTag]]);
        $activityTarget=$this->create('resource',['title'=>'QZ Recomendación por actividad','tag_names'=>[$activityTag]]);
        wp_set_current_user($this->users[1]);
        Content::react($activitySource['id'],'like',true);
        $activityRecommendations=Content::listing('resource',['recommended'=>1,'per_page'=>100]);
        $activityIds=array_column($activityRecommendations['items'],'id');
        self::assertContains($activityTarget['id'],$activityIds,'Relevant recent activity should participate in RF-041 content recommendations.');
        $activityItem=$activityRecommendations['items'][array_search($activityTarget['id'],$activityIds,true)];
        self::assertContains('activity_match',$activityItem['recommendation']['reasons']);

        wp_set_current_user($this->users[0]);
        self::assertGreaterThanOrEqual(2,Discovery::resource($keyword['id'])['sent'],'Keyword relevance should also participate in resource discovery notifications.');
    }

    public function testRelevantResourcesAndNetworkingNotifyOnceAndRespectOptOut(): void
    {
        $p=$this->create('resource',['interest'=>[$this->terms['interest']]]);
        self::assertGreaterThanOrEqual(2,Discovery::resource($p['id'])['sent']); self::assertSame(0,Discovery::resource($p['id'])['sent']);
        wp_set_current_user($this->users[1]); self::assertSame('resource',Notifications::list()[0]['kind']);
        wp_set_current_user($this->users[0]); Discovery::networking(); $count=Store::count('notifications',"user_id=%d AND kind='networking'",[$this->users[1]]); self::assertSame(1,$count);
        Discovery::networking(); self::assertSame($count,Store::count('notifications',"user_id=%d AND kind='networking'",[$this->users[1]]));
        wp_set_current_user($this->users[2]); Profiles::save(['networking'=>false]); Store::delete('notifications',['user_id'=>$this->users[2],'kind'=>'networking']);
        Discovery::networking(); self::assertSame(0,Store::count('notifications',"user_id=%d AND kind='networking'",[$this->users[2]]));
        wp_set_current_user($this->users[0]); wp_update_post(['ID'=>$p['id'],'post_status'=>'draft']); self::assertSame(0,Discovery::resource($p['id'])['sent']);
    }
    public function testMediaMetadataDoesNotExposeBytesOrUnselectedFiles(): void
    {
        $p=$this->create('hub'); $ids=[];
        foreach (['image/png','application/pdf'] as $mime) { $ids[]=Store::insert('media',['user_id'=>$this->users[0],'post_id'=>$p['id'],'name'=>'test','mime'=>$mime,'bytes'=>'secret bytes','created_at'=>gmdate('Y-m-d H:i:s')]); }
        self::assertCount(1,Media::metadata($p['id'],[$ids[0]]));
        self::assertArrayNotHasKey('bytes',Media::metadata($p['id'],$ids)[0]); self::assertSame([],Media::metadata($p['id'],[]));
    }
    public function testDenseCaptionsCreateGroundedNonOverlappingClipsAndRejectInventedTimes(): void
    {
        $srt='';
        for ($i=0;$i<60;$i++) { $srt.=($i+1)."\n".gmdate('H:i:s',$i*3).",000 --> ".gmdate('H:i:s',($i+1)*3).",000\nResponsabilidades del directorio y riesgos.\n\n"; }
        self::assertCount(60,Transcript::segments($srt));
        $clips=Transcript::moments($srt,[['start'=>999,'end'=>1100],['start'=>0,'end'=>90]]);
        self::assertNotEmpty($clips); self::assertSame(0,$clips[0]['start']); self::assertSame(90,$clips[0]['end']);
        foreach ($clips as $clip) { self::assertGreaterThanOrEqual(60,$clip['end']-$clip['start']); self::assertLessThanOrEqual(180,$clip['end']); }
        self::assertSame([],Transcript::moments("[00:00] Texto sin intervalo suficiente\n[00:20] Final"));
        self::assertSame([],Transcript::segments("[00:99] Invalido\n[01:30] Final"));
    }
    public function testVideoTopicsAreActivatedAndGeneratedNoteStaysInOriginalResource(): void
    {
        $p=$this->create('resource',['interest'=>[$this->terms['interest']],'meta'=>['youtube_url'=>'https://youtu.be/abcdefghijk','resource_type'=>'Video','chatham'=>false,'duration_seconds'=>210,'transcript'=>"[00:00] La inteligencia artificial requiere gestión de riesgos y supervisión del directorio.
[01:10] La transformación digital exige responsabilidades claras.
[03:30] Cierre del contenido."]]);
        $result=Knowledge::multimedia($p['id']);self::assertSame($p['id'],$result['resource_id']);self::assertSame(0,$result['hub_id']);self::assertSame([],$result['capsule_ids']);
        self::assertGreaterThan(0,strtotime(Content::serialize(get_post($p['id']))['date']));
        $meta=get_post_meta($p['id'],'_ascla',true); self::assertSame(210,(int)$meta['duration_seconds']);self::assertNotEmpty($meta['technical_note']);self::assertTrue((bool)$meta['ai_enriched']);
        self::assertLessThanOrEqual(1,count($meta['moments']));
        self::assertNotEmpty(wp_get_object_terms($p['id'],'ascla_tag'));
        $topicNames=wp_get_object_terms($p['id'],'ascla_interest',['fields'=>'names']);
        self::assertContains(get_term($this->terms['interest'])->name,$topicNames,'Los temas seleccionados manualmente deben conservarse.');
        self::assertContains('Gestión de riesgos',$topicNames);
        self::assertContains('Inteligencia artificial',$topicNames);
        self::assertContains('Juntas directivas',$topicNames);
        self::assertContains('Transformación digital',$topicNames);
    }
    public function testAIDoesNotGenerateWithoutVerifiedTranscript(): void
    {
        $p=$this->create('resource',['meta'=>['resource_type'=>'Video','chatham'=>false]]);
        $meta=(array)get_post_meta($p['id'],'_ascla',true);$meta['video_id']='abcdefghijk';update_post_meta($p['id'],'_ascla',$meta);
        try {
            Knowledge::multimedia($p['id']);
            self::fail('La generación debe detenerse si no existe una transcripción verificable.');
        } catch (ASCLA\Core\Rest\ApiException $e) {
            self::assertSame(409,$e->getCode());
            self::assertStringContainsString('transcripción verificable',$e->getMessage());
        }
        $saved=(array)get_post_meta($p['id'],'_ascla',true);
        self::assertEmpty($saved['ai_enriched']??false);
        self::assertSame('unavailable',$saved['transcript_status']??'');
    }
    public function testBrowserPlayerDurationCanBePersistedWithoutTranscriptInference(): void
    {
        $p=$this->create('resource',['meta'=>['resource_type'=>'Video','chatham'=>false]]);
        $meta=(array)get_post_meta($p['id'],'_ascla',true);
        $meta['video_id']='abcdefghijk';
        unset($meta['duration_seconds']);
        update_post_meta($p['id'],'_ascla',$meta);
        $result=Knowledge::browserVideoDuration($p['id'],367);
        self::assertSame(367,$result['duration_seconds']);
        $saved=(array)get_post_meta($p['id'],'_ascla',true);
        self::assertSame(367,(int)$saved['duration_seconds']);
        self::assertSame('YouTube Player API (navegador)',$saved['video_metadata_mode']);
    }
}
