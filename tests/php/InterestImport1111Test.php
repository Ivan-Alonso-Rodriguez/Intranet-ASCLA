<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{InterestImports,InterestImportAccounts,Profiles};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Integrations\AIProviderInterface;
use ASCLA\Core\Rest\ApiException;

final class InterestImport1111Test extends TestCase
{
    private array $users=[];
    private int $topic;
    private string $last;
    protected function setUp(): void
    {
        $this->last='Contreras'.bin2hex(random_bytes(5));
        foreach(['administrator','ascla_member','ascla_member','subscriber'] as $i=>$role){
            $login='import1111_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role,'first_name'=>$i===0?'Admin Original':($i===2?'Pedro Enrique':'Maria Alejandra'),'last_name'=>$this->last,'display_name'=>($i===0?'Admin Original':($i===2?'Pedro Enrique':'Maria Alejandra')).' '.$this->last]);
        }
        wp_set_current_user($this->users[0]);$this->topic=(int)wp_insert_term('Tema1111 '.bin2hex(random_bytes(5)),'ascla_interest')['term_id'];
        update_user_meta($this->users[1],'_ascla_profile',['bio'=>'Keep biography','interests'=>[]]);
    }
    protected function tearDown(): void
    {
        foreach(Store::rows('imports','actor_id=%d',[$this->users[0]],'ORDER BY id') as $i){Store::delete('import_rows',['import_id'=>$i['id']]);Store::delete('imports',['id'=>$i['id']]);}
        foreach($this->users as $id){Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}wp_delete_term($this->topic,'ascla_interest');wp_set_current_user(0);
    }
    private function input(array $rows): array
    {
        $f=fopen('php://temp','r+');fputcsv($f,['Nombre','Apellido','Correo','Empresa','Evento'],',','"','');foreach($rows as $r)fputcsv($f,$r,',','"','');rewind($f);$csv=stream_get_contents($f);fclose($f);
        return ['csv'=>$csv,'filename'=>'response.csv','source_filename'=>'response.pdf','source_type'=>'pdf','columns'=>['person_name'=>0,'last_name'=>1,'email'=>2,'event_text'=>4],'context'=>'Laboratorio de gobierno corporativo','context_topics'=>[$this->topic],'only_existing'=>true];
    }
    private function reject(callable $fn): void {try{$fn();self::fail('Expected rejection');}catch(ApiException $e){self::assertSame(400,$e->getCode());}}
    public function testExistingAccountsComeFirstAndUnknownPeopleAreNotInterpreted(): void
    {
        $body=$this->input([['Unknown','Unrelated','','Company','Board'],['Maria Alejandra',$this->last,'','Company','Board']]);$i=InterestImports::start($body);self::assertSame(3,$i['rows'][0]['payload']['line']);self::assertSame('response.pdf',$i['config']['source_filename']);
        $i=InterestImports::process((int)$i['id']);self::assertSame($this->users[1],(int)$i['rows'][0]['user_id']);self::assertSame('name',$i['rows'][0]['payload']['identity_method']);self::assertSame('ignored',$i['rows'][1]['state']);self::assertSame([],$i['rows'][1]['payload']['topics']);self::assertSame([],Profiles::raw($this->users[1])['interests']);
    }
    public function testContextIsOnlyAReviewedProposalAndApplicationPreservesOtherFields(): void
    {
        $i=InterestImports::start($this->input([['Maria Alejandra',$this->last,'','Company','Board']]));$i=InterestImports::process((int)$i['id']);$id=(int)$i['id'];$r=$i['rows'][0];
        self::assertSame('baja',$r['payload']['confidence']);self::assertSame([$this->topic],$r['payload']['topics']);$this->reject(fn()=>InterestImports::review($id,$r['id'],['decision'=>'accept','topics'=>[$this->topic]]));
        InterestImports::review($id,$r['id'],['decision'=>'accept','topics'=>[$this->topic],'acknowledge'=>true]);self::assertSame([],Profiles::raw($this->users[1])['interests']);InterestImports::confirm($id);$done=InterestImports::apply($id);
        self::assertSame('complete',$done['status']);self::assertSame([$this->topic],Profiles::raw($this->users[1])['interests']);self::assertSame('Keep biography',Profiles::raw($this->users[1])['bio']);
    }
    public function testEmploymentAndMembershipNeverBecomeInterestEvidence(): void
    {
        $body=$this->input([['Maria Alejandra',$this->last,'','Inteligencia artificial Compliance ESG','']]);$body['context']='';$body['context_topics']=[];$i=InterestImports::start($body);$i=InterestImports::process((int)$i['id']);self::assertSame([],$i['rows'][0]['payload']['topics']);self::assertSame('',$i['rows'][0]['payload']['free']);self::assertSame([],Profiles::raw($this->users[1])['interests']);
    }
    public function testConflictingCodeAndEmailAndIneligibleEmailCannotFallBackToNames(): void
    {
        update_user_meta($this->users[2],'_ascla_member_code','CODE1111');$p=['person_name'=>'Maria Alejandra','last_name'=>$this->last,'email'=>get_userdata($this->users[1])->user_email,'code'=>'CODE1111'];self::assertSame(0,InterestImportAccounts::resolve($p)[0]);
        $p['code']='';$p['email']=get_userdata($this->users[3])->user_email;self::assertSame(0,InterestImportAccounts::resolve($p)[0]);
        $p['email']=get_userdata($this->users[2])->user_email;self::assertSame($this->users[2],InterestImportAccounts::resolve($p)[0]);
    }
    public function testAmbiguousNamesNeverAutoAssociateButClearTyposRequireReview(): void
    {
        $p=['person_name'=>'Maria Alejandra','last_name'=>$this->last.'z','email'=>'','code'=>''];self::assertSame($this->users[1],InterestImportAccounts::resolve($p)[0]);self::assertNotSame('',InterestImportAccounts::resolve($p)[1]);
        wp_update_user(['ID'=>$this->users[2],'first_name'=>'Maria Alejandra','last_name'=>$this->last,'display_name'=>'Maria Alejandra '.$this->last]);$p['last_name']=$this->last;self::assertSame(0,InterestImportAccounts::resolve($p)[0]);
    }
    public function testAiRejectsUnassociatedPeopleBeforeCallingProvider(): void
    {
        $input=$this->input([['Unknown','Unrelated','','Company','Board']]);$input['only_existing']=false;$i=InterestImports::start($input);$i=InterestImports::process((int)$i['id']);
        $provider=new class implements AIProviderInterface{public bool $called=false;public function mode():string{return 'test';}public function generate(string $task,array $context):array{$this->called=true;throw new RuntimeException('Must not be called');}};
        $this->reject(fn()=>InterestImports::classify((int)$i['id'],$i['rows'][0]['id'],$provider));self::assertFalse($provider->called);
    }
    public function testContextAiReceivesNoIdentityOrEmploymentAndStaysLowConfidence(): void
    {
        $i=InterestImports::start($this->input([['Maria Alejandra',$this->last,get_userdata($this->users[1])->user_email,'PRIVATECOMPANY','Board']]));$i=InterestImports::process((int)$i['id']);
        $provider=new class($this->topic) implements AIProviderInterface{public array $context=[];public function __construct(private int $id){}public function mode():string{return 'test';}public function generate(string $task,array $context):array{$this->context=$context;return ['intereses'=>[$this->id],'confianza'=>'alta'];}};
        $r=InterestImports::classify((int)$i['id'],$i['rows'][0]['id'],$provider);self::assertSame('baja',$r['confidence']);self::assertSame(['text','catalog'],array_keys($provider->context));foreach(['PRIVATECOMPANY',$this->last,get_userdata($this->users[1])->user_email] as $private)self::assertStringNotContainsString($private,wp_json_encode($provider->context));self::assertSame([],Profiles::raw($this->users[1])['interests']);
    }
}
