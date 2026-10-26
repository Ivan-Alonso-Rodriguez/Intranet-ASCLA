<?php

use PHPUnit\Framework\TestCase;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{Matching,Profiles,Settings};

final class RecommendationsTest extends TestCase
{
    private array $users=[];
    private array $settings=[];

    protected function setUp(): void
    {
        $this->settings=Settings::get();$nonce=bin2hex(random_bytes(4));
        foreach(range(1,7) as $number){
            $id=wp_insert_user(['user_login'=>'recommendation_'.$nonce.'_'.$number,'user_pass'=>wp_generate_password(30),'user_email'=>'recommendation_'.$nonce.'_'.$number.'@example.invalid','role'=>'ascla_member']);
            self::assertIsInt($id);$this->users[]=$id;
        }
        $catalogs=Profiles::catalogs();
        foreach($this->users as $index=>$id){
            wp_set_current_user($id);
            Profiles::save([
                'first_name'=>'Persona','last_name'=>'Recomendación '.($index+1),'position'=>'Secretaría corporativa','company'=>'Organización de prueba',
                'country'=>'Perú','city'=>'Lima','bio'=>'Perfil profesional completo para verificar el caso de uso de recomendaciones.',
                'experience'=>'Experiencia complementaria en gobierno corporativo y gestión de riesgos.','directory'=>true,'networking'=>true,
                'interests'=>[(int)$catalogs['interest'][0]['id']],'industries'=>[(int)$catalogs['industry'][0]['id']],
                'goals'=>[(int)$catalogs['goal'][0]['id']],'areas'=>[(int)$catalogs['area'][0]['id']],
            ]);
        }
        wp_set_current_user($this->users[0]);Settings::save(['matching_min_affinity'=>0,'matching_max_suggestions'=>5,'ai_provider'=>'mock']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]??0);update_option('ascla_settings',$this->settings,false);
        Store::delete('jobs',['kind'=>'recommendations']);
        foreach($this->users as $id){Store::delete('relations',['user_id'=>$id]);Store::delete('relations',['target_id'=>$id]);wp_delete_user($id);}
        wp_set_current_user(0);
    }

    public function testWeeklyBatchIsLimitedAndDismissalIsPrivateAndReversible(): void
    {
        $overview=Matching::overview();
        self::assertTrue($overview['eligible']);self::assertCount(5,$overview['people']);self::assertFalse($overview['low_match']);
        self::assertNotEmpty($overview['generated_at']);self::assertNotEmpty($overview['next_update']);

        $target=(int)$overview['people'][0]['id'];Matching::dismiss($target);
        $after=Matching::overview();self::assertCount(4,$after['people']);self::assertTrue($after['low_match']);
        self::assertSame($target,(int)Matching::dismissed()['items'][0]['id']);

        Matching::restore($target);self::assertSame([],Matching::dismissed()['items']);
        self::assertCount(4,Matching::overview()['people'],'Restoring eligibility does not manually refresh the current weekly batch.');
        Matching::refreshBatch($this->users[0]);self::assertCount(5,Matching::overview()['people']);
    }

    public function testIncompleteProfilesCannotEnterRecommendationBatches(): void
    {
        $candidate=$this->users[1];wp_set_current_user($candidate);
        $legacy=Profiles::raw($candidate);$legacy['bio']='';$legacy['goals']=[];update_user_meta($candidate,'_ascla_profile',$legacy);
        wp_set_current_user($this->users[0]);Matching::refreshBatch($this->users[0]);
        self::assertNotContains($candidate,array_column(Matching::overview()['people'],'id'));
    }

    public function testHiddenTaxonomiesAreNotUsedAsSharedRecommendationCriteria(): void
    {
        $candidate=$this->users[1];wp_set_current_user($candidate);
        Profiles::save(['hidden'=>['interests','industries','goals']]);
        wp_set_current_user($this->users[0]);Matching::refreshBatch($this->users[0]);
        self::assertNotContains($candidate,array_column(Matching::overview()['people'],'id'));
    }

    public function testParticipationIsARecommendationPrecondition(): void
    {
        Profiles::save(['networking'=>false],$this->users[0]);
        $overview=Matching::overview();self::assertFalse($overview['eligible']);self::assertSame('disabled',$overview['reason']);self::assertSame([],$overview['people']);
    }

    public function testWeeklyRefreshIsRecordedInTheAsclaJobsQueue(): void
    {
        $before=Store::count('jobs','kind=%s',['recommendations']);
        do_action('ascla_recommendations');
        self::assertSame($before+1,Store::count('jobs','kind=%s',['recommendations']));
        $rows=Store::rows('jobs','kind=%s',['recommendations'],'ORDER BY id DESC LIMIT 1');
        self::assertSame('pending',$rows[0]['status']);self::assertSame(0,(int)$rows[0]['user_id']);
    }
}
