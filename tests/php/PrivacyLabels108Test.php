<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Content,Events,Knowledge,NetworkingAI};
use ASCLA\Core\Domain\{EntityRedactor,MicroeventTitle};

final class PrivacyLabels108Test extends TestCase
{
    private array $users=[];
    private int $post;
    private string $legacy,$visible;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_executive','ascla_moderator','ascla_member'] as $role){$name='privacylabels_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);}
        wp_set_current_user($this->users[0]);add_filter('pre_wp_mail','__return_true');
        $suffix=bin2hex(random_bytes(5));$this->legacy='participante · gobierno corporativo '.$suffix;$this->visible='Círculo ASCLA · gobierno corporativo '.$suffix;
        $this->post=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$this->users[0],'post_title'=>$this->legacy,'post_content'=>'Juan Pérez compartió sus acuerdos.']);
        update_post_meta($this->post,'_ascla',['micro'=>true,'chatham'=>true,'public_at'=>time()-10,'start'=>gmdate('c',time()+DAY_IN_SECONDS),'end'=>gmdate('c',time()+DAY_IN_SECONDS+3600),'location'=>'Sala Juan Pérez','identities'=>'Juan Pérez','agenda_ai'=>['title'=>$this->legacy,'objective'=>'Juan Pérez explicó una idea.']]);
    }
    protected function tearDown(): void
    {
        wp_delete_post($this->post,true);foreach($this->users as $id)wp_delete_user($id);remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    public static function roles(): array{return [[0],[1],[2],[3]];}
    #[DataProvider('roles')]
    public function testLegacyLabelsAreNaturalForAllReadersWithoutOverwritingTheSource(int $index): void
    {
        $original=get_post_meta($this->post,'_ascla',true);wp_set_current_user($this->users[$index]);$view=Content::serialize(Content::get($this->post));
        self::assertSame($this->visible,$view['title']);self::assertSame($this->visible,$view['meta']['agenda_ai']['title']);
        if($index>1)self::assertStringNotContainsString('Juan Pérez',wp_json_encode($view));
        $detail=Events::detail($this->post);parse_str(parse_url($detail['google_url'],PHP_URL_QUERY),$calendar);self::assertSame($this->visible,$calendar['text']);
        if($index>1)self::assertStringNotContainsString('Juan Pérez',$calendar['location']);
        self::assertSame($this->legacy,get_post($this->post)->post_title);self::assertSame($original,get_post_meta($this->post,'_ascla',true));
    }
    public function testAuthoredTitlesAndLiteralUsesOfTheWordAreNotRewritten(): void
    {
        $meta=get_post_meta($this->post,'_ascla',true);$literal='Un participante explicó el acuerdo';
        self::assertSame($literal,MicroeventTitle::display($literal,$meta));
        foreach([['micro'=>false],['chatham'=>false],['agenda_ai'=>[]],['agenda_ai'=>['title'=>'Another title']],['agenda_ai'=>'invalid']] as $change)self::assertSame($this->legacy,MicroeventTitle::display($this->legacy,array_replace($meta,$change)));
        $redacted=EntityRedactor::redact('Círculo ASCLA · gobierno corporativo. Juan Pérez habló.',['Juan Pérez']);self::assertStringContainsString('Círculo ASCLA',$redacted);self::assertStringNotContainsString('Juan Pérez',$redacted);
        self::assertStringNotContainsString('Círculo ASCLA',EntityRedactor::redact('Círculo ASCLA',['Círculo ASCLA']));
    }
    public function testNewProposalsKeepTheInstitutionalLabel(): void
    {
        $agenda=NetworkingAI::agenda([],true);self::assertStringStartsWith('Círculo ASCLA ·',$agenda['title']);self::assertStringNotContainsString('participante',$agenda['title']);
    }
    public function testGeneratedLegacySuffixIsRemovedWithoutRewritingAuthoredTitlesOrStoredSources(): void
    {
        $title='Sinergias en Gobierno Corporativo y participante';$meta=get_post_meta($this->post,'_ascla',true);$meta['agenda_ai']['title']=$title;
        self::assertSame('Sinergias en Gobierno Corporativo',MicroeventTitle::display($title,$meta));
        self::assertSame($title,$meta['agenda_ai']['title']);$meta['agenda_ai']['title']='Otro título generado';
        self::assertSame($title,MicroeventTitle::display($title,$meta));
    }
    public function testVisibleLegacyTitleIsSearchableAndHiddenIdentitiesRemainExcluded(): void
    {
        wp_set_current_user($this->users[3]);$list=Content::listing('event',['q'=>$this->visible,'past'=>0]);self::assertSame([$this->post],array_column($list['items'],'id'));self::assertSame(1,$list['total']);
        self::assertSame(0,Content::listing('event',['q'=>'Juan Pérez'])['total']);
        self::assertNotContains($this->post,array_column(Content::listing('event',['q'=>'participante'])['items'],'id'));
        self::assertContains($this->post,array_column(Content::listing('event',['q'=>'circulo ascla'])['items'],'id'));
        foreach (["Círculo ASCLA %s", "Círculo ASCLA ' %d "] as $text)self::assertSame(0,Content::listing('event',['q'=>$text])['total']);
    }
    public function testSavedAssistantReferencesUseTheCurrentLabelAndStillHideIdentity(): void
    {
        wp_set_current_user($this->users[3]);$saved=Knowledge::storedAnswer(['answer'=>'Consulta la actividad. Juan Pérez habló.','sources'=>[['id'=>$this->post,'title'=>$this->legacy]],'mode'=>'DEMO MODE']);
        self::assertSame($this->visible,$saved['sources'][0]['title']);self::assertStringNotContainsString('Juan Pérez',$saved['answer']);
    }
}
