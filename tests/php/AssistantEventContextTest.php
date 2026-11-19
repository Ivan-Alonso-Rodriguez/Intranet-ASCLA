<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{AssistantContext,Content,Events,Knowledge,Settings};
use ASCLA\Core\Repositories\Store;

final class AssistantEventContextTest extends TestCase
{
    private array $users=[],$posts=[],$settings=[];
    protected function setUp(): void
    {
        $this->settings=Settings::get();
        foreach (['administrator','ascla_member','ascla_member','ascla_member'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'assistant_event_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);Settings::save(['ai_provider'=>'mock']);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) {
            wp_clear_scheduled_hook('ascla_event_offers',[$id]);
            ASCLA\Core\Services\EventReminders::cancel($id);
            Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true);
        }
        foreach ($this->users as $id) {
            foreach (['notifications','jobs','relations'] as $table) { Store::delete($table,['user_id'=>$id]); }
            wp_delete_user($id);
        }
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function event(string $title,int $capacity=4): int
    {
        wp_set_current_user($this->users[0]);
        $event=Content::save('event',['title'=>$title,'body'=>'Capacitación con casos prácticos y preguntas.','status'=>'publish','meta'=>[
            'start'=>gmdate('c',time()+DAY_IN_SECONDS),'end'=>gmdate('c',time()+DAY_IN_SECONDS+3600),
            'modality'=>'Virtual','url'=>'https://example.invalid/private-meeting','capacity'=>$capacity,
            'agenda'=>'Presentación del caso y preguntas.','chatham'=>false,
        ]]);
        $this->posts[]=$event['id'];return $event['id'];
    }
    public function testAssistantReceivesCompleteDetailsAndCountsConfirmedAndOfferedSeats(): void
    {
        $id=$this->event('Laboratorio de supervisión');
        foreach (['accepted','offered','invited'] as $index=>$status) {
            Store::insert('registrations',['event_id'=>$id,'user_id'=>$this->users[$index+1],'status'=>$status,'created_at'=>current_time('mysql',true)]);
        }
        wp_set_current_user($this->users[3]);
        $context=AssistantContext::build('¿Cuántos cupos quedan en el evento Laboratorio de supervisión?');
        $event=array_column($context['live']['upcoming_events'],null,'id')[$id];
        self::assertSame(4,$event['capacity']);self::assertSame(2,$event['remaining']);
        self::assertSame(2,$event['reserved']);self::assertSame(1,$event['attending']);
        self::assertSame('Presentación del caso y preguntas.',$event['agenda']);
        self::assertStringContainsString('casos prácticos',$event['description']);
        self::assertSame('',$event['meeting_url']);
        self::assertArrayNotHasKey('participants',$event);
        self::assertStringContainsString('Cupos disponibles: 2',array_column($context['sources'],'body','id')[$id]);

        wp_set_current_user($this->users[1]);
        self::assertSame('https://example.invalid/private-meeting',AssistantContext::build('Evento Laboratorio de supervisión')['live']['upcoming_events'][0]['meeting_url']);
    }
    public function testSpecificEventCanBeFoundAfterEightUpcomingEventsAndHistoricalDetailsStayAvailable(): void
    {
        foreach (range(1,10) as $i) { $this->event('Encuentro general '.$i); }
        $id=$this->event('Laboratorio Quásar');
        wp_set_current_user($this->users[1]);
        $answer=Knowledge::answer('¿Cuántos cupos quedan en el evento Laboratorio Quásar?');
        self::assertContains($id,array_column($answer['sources'],'id'));
        self::assertStringContainsString('Cupos disponibles: 4',$answer['answer']);

        $meta=get_post_meta($id,'_ascla',true);
        $meta['start']=gmdate('c',time()-7200);$meta['end']=gmdate('c',time()-3600);$meta['cancelled']=true;
        update_post_meta($id,'_ascla',$meta);
        $event=AssistantContext::build('Detalles del evento Laboratorio Quásar')['live']['upcoming_events'][0];
        self::assertSame($id,$event['id']);self::assertTrue($event['is_past']);self::assertTrue($event['cancelled']);
    }
    public function testCurrentAvailabilityReplacesEarlierCountsAndUnlimitedCapacityIsExplicit(): void
    {
        $id=$this->event('Taller Boreal',1);
        wp_set_current_user($this->users[1]);
        $first=Knowledge::answer('¿Cuántos cupos quedan para el evento Taller Boreal?');
        self::assertStringContainsString('Cupos disponibles: 1',$first['answer']);
        Events::register($id,'accepted');
        $next=Knowledge::answer('¿Y cuántos cupos quedan ahora?',[['question'=>'Cupos para el evento Taller Boreal','answer'=>$first['answer']]]);
        self::assertStringContainsString('Cupos disponibles: 0',$next['answer']);
        self::assertStringNotContainsString('Cupos disponibles: 1',Knowledge::storedAnswer($first)['answer']);

        $unlimited=$this->event('Encuentro Austral',0);wp_set_current_user($this->users[2]);
        $data=AssistantContext::build('Cupos del evento Encuentro Austral')['live']['upcoming_events'][0];
        self::assertSame($unlimited,$data['id']);self::assertTrue($data['unlimited']);self::assertNull($data['remaining']);
        self::assertStringContainsString('Sin límite de cupos',Knowledge::answer('Cupos del evento Encuentro Austral')['answer']);
    }
    public function testRestrictedMicroeventIsExcludedFromTheAssistant(): void
    {
        $id=$this->event('Taller Reservado');
        $meta=get_post_meta($id,'_ascla',true);$meta['micro']=true;$meta['invitees']=[$this->users[1]];$meta['public_at']=time()+DAY_IN_SECONDS;
        update_post_meta($id,'_ascla',$meta);
        wp_set_current_user($this->users[2]);
        self::assertNotContains($id,array_column(AssistantContext::build('Detalles del evento Taller Reservado')['sources'],'id'));
    }
}
