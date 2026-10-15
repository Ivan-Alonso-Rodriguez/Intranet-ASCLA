<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Allies,Content};
use ASCLA\Core\Database\AllySearchMigration;
use ASCLA\Core\Repositories\Store;

final class AllySearchTest extends TestCase
{
    private array $users=[],$posts=[];
    private int $admin,$otherAdmin,$member;
    protected function setUp(): void
    {
        foreach(['administrator','administrator','ascla_member','ascla_executive'] as $role){$login='allysearch_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32),'user_email'=>$login.'@example.invalid','role'=>$role]);}
        [$this->admin,$this->otherAdmin,$this->member]=$this->users;wp_set_current_user($this->admin);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach($this->posts as $id){wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);}
        foreach($this->users as $id){Store::delete('audit',['actor_id'=>$id]);Store::delete('notifications',['user_id'=>$id]);wp_delete_user($id);}
        update_option(AllySearchMigration::OPTION,1,false);wp_set_current_user(0);
    }
    private function ally(string $type,array $extra=[]): int
    {
        $saved=Content::save('ally',$extra+['title'=>'Organización '.bin2hex(random_bytes(4)),'body'=>'Descripción institucional','status'=>'publish','meta'=>['alliance_type'=>$type,'contact_email'=>'equipo@example.invalid']]);
        $this->posts[]=(int)$saved['id'];return (int)$saved['id'];
    }
    private function ids(string $q,array $filter=[]): array {return array_column(Content::listing('ally',['q'=>$q]+$filter)['items'],'id');}
    public function testPublishedAgreementTypeIsSearchableWithoutAppearingInNameOrDescription(): void
    {
        $type='Convenio exclusivo '.bin2hex(random_bytes(5));$id=$this->ally($type);wp_set_current_user($this->member);
        self::assertSame([$id],$this->ids($type));self::assertSame([$id],$this->ids(mb_strtoupper($type)));
        self::assertContains($id,$this->ids('Organización'));self::assertContains($id,$this->ids('Descripción institucional'));
    }
    public function testSearchTreatsQuotesAndSqlWildcardsAsLiteralText(): void
    {
        $id=$this->ally("Educación 100% _ ' y cooperación");$other=$this->ally('Educacion 1000X otra cooperacion');wp_set_current_user($this->member);
        self::assertSame([$id],$this->ids('100% _'));self::assertSame([$id],$this->ids("% _ '"));
        self::assertContains($id,$this->ids('educacion'));self::assertNotContains($other,$this->ids("' OR 1=1 --"));
    }
    public function testCountsAndPaginationExcludeHiddenDraftAndInactiveAlliesBeforeLimiting(): void
    {
        $type='Búsqueda paginada '.bin2hex(random_bytes(4));$expected=[];
        foreach(range(1,21) as $i){$expected[]=$this->ally($type);}
        $inactive=$this->ally($type,['status'=>'inactive']);$draft=$this->ally($type,['status'=>'draft']);
        wp_set_current_user($this->member);$seen=[];
        foreach([1,2,3] as $page){$r=Content::listing('ally',['q'=>$type,'page'=>$page,'per_page'=>8]);self::assertSame(21,$r['total']);self::assertSame(3,$r['pages']);self::assertCount($page===3?5:8,$r['items']);$seen=array_merge($seen,array_column($r['items'],'id'));}
        sort($seen);sort($expected);self::assertSame($expected,$seen);self::assertNotContains($inactive,$seen);self::assertNotContains($draft,$seen);
        self::assertNotContains($inactive,$this->ids($type,['status'=>'ascla_hidden']));
    }
    public function testAdministrativeRegistryCombinesAgreementTextWithStateIncludingOtherAuthorsDrafts(): void
    {
        wp_set_current_user($this->otherAdmin);$type='Reserva exclusiva '.bin2hex(random_bytes(4));$draft=$this->ally($type,['status'=>'draft']);$inactive=$this->ally($type,['status'=>'inactive']);$active=$this->ally($type);
        wp_set_current_user($this->admin);
        foreach(['draft'=>$draft,'inactive'=>$inactive,'active'=>$active] as $state=>$id){$r=Allies::listing(['q'=>$type,'state'=>$state]);self::assertSame(1,$r['total']);self::assertSame([$id],array_column($r['items'],'id'));}
        self::assertSame(3,Allies::listing(['q'=>$type])['total']);
    }
    public function testTypeEditImmediatelyUpdatesSearchAndRetirementPreservesTypeForAdmin(): void
    {
        $old='Primero '.bin2hex(random_bytes(5));$new='Segundo '.bin2hex(random_bytes(5));$id=$this->ally($old);
        Content::save('ally',['title'=>'Nombre conservado','body'=>'Descripción conservada','status'=>'publish','meta'=>['alliance_type'=>$new]],$id);
        wp_set_current_user($this->member);self::assertSame([],$this->ids($old));self::assertSame([$id],$this->ids($new));
        wp_set_current_user($this->admin);Allies::changeStatus($id,'inactive');wp_set_current_user($this->member);self::assertSame([],$this->ids($new));
        wp_set_current_user($this->admin);self::assertSame([$id],array_column(Allies::listing(['q'=>$new,'state'=>'inactive'])['items'],'id'));
    }
    public function testTranslatedSystemTypesAndVisibleFallbackAreSearchableInEnglish(): void
    {
        $strategic=$this->ally('Socio estratégico');$agreement=$this->ally('Convenio');$fallback=$this->ally('');
        wp_set_current_user($this->member);update_user_meta($this->member,'locale','en_US');
        self::assertContains($strategic,$this->ids('Strategic partner'));self::assertContains($agreement,$this->ids('Agreement'));self::assertContains($fallback,$this->ids('Institutional partnership'));
        self::assertContains($strategic,$this->ids('socio estrategico'));
    }
    public function testGlobalSearchIncludesAllyTypesWithoutMatchingAnUnrelatedPostTypeIndex(): void
    {
        $type='Globaltipo '.bin2hex(random_bytes(6));$id=$this->ally($type);
        $resource=Content::save('resource',['title'=>'Documento sin tipo de alianza','body'=>'Contenido sin coincidencia','status'=>'publish','meta'=>['chatham'=>false]]);$this->posts[]=$resource['id'];update_post_meta($resource['id'],'_ascla_alliance_type',mb_strtolower($type));
        wp_set_current_user($this->member);$query=new WP_Query(['post_type'=>['ascla_hub','ascla_resource','ascla_ally'],'post_status'=>'publish','s'=>$type,'ascla_search'=>true]);$ids=array_map('intval',wp_list_pluck($query->posts,'ID'));self::assertContains($id,$ids);self::assertNotContains($resource['id'],$ids);
    }
    public function testMigrationRebuildsLegacyIndexesWithoutChangingOriginalDataAndIsIdempotent(): void
    {
        $type='Histórico '.bin2hex(random_bytes(5));$id=$this->ally($type);$before=get_post($id);$meta=get_post_meta($id,'_ascla',true);
        delete_post_meta($id,'_ascla_alliance_type');delete_option(AllySearchMigration::OPTION);AllySearchMigration::run();
        self::assertTrue(AllySearchMigration::ready());self::assertSame($meta,get_post_meta($id,'_ascla',true));self::assertSame($before->post_modified_gmt,get_post($id)->post_modified_gmt);self::assertSame($before->post_status,get_post_status($id));
        $index=get_post_meta($id,'_ascla_alliance_type',true);AllySearchMigration::run();self::assertSame($index,get_post_meta($id,'_ascla_alliance_type',true));
        wp_set_current_user($this->member);self::assertSame([$id],$this->ids($type));
    }
    public function testMigrationVisitsMoreThanOneBatchIncludingUnpublishedLegacyRecords(): void
    {
        $last=0;
        foreach(range(1,203) as $number){$last=$this->ally('Convenio histórico '.$number,['status'=>$number%2?'inactive':'draft']);delete_post_meta($last,'_ascla_alliance_type');}
        delete_option(AllySearchMigration::OPTION);AllySearchMigration::run();
        self::assertTrue(AllySearchMigration::ready());self::assertSame('convenio historico 203',get_post_meta($last,'_ascla_alliance_type',true));
        self::assertSame('ascla_hidden',get_post_status($last));
    }
    public function testFailedIndexWriteRollsBackEditAndFailedMigrationDoesNotClaimReadiness(): void
    {
        $old='Original '.bin2hex(random_bytes(5));$id=$this->ally($old);$before=get_post_meta($id,'_ascla',true);
        $reject=static fn($r,$post,$key)=>$key==='_ascla_alliance_type'?false:$r;add_filter('update_post_metadata',$reject,10,3);
        try{Content::save('ally',['title'=>'No debe cambiar','body'=>'No debe cambiar','status'=>'publish','meta'=>['alliance_type'=>'Cambio rechazado']],$id);self::fail('Index write must fail');}catch(ASCLA\Core\Repositories\RepositoryException $error){self::assertNotEmpty($error->getMessage());}finally{remove_filter('update_post_metadata',$reject,10);}
        self::assertSame($before,get_post_meta($id,'_ascla',true));self::assertSame([$id],$this->ids($old));
        delete_post_meta($id,'_ascla_alliance_type');delete_option(AllySearchMigration::OPTION);add_filter('update_post_metadata',$reject,10,3);
        try{AllySearchMigration::run();self::fail('Migration must fail');}catch(ASCLA\Core\Repositories\RepositoryException $error){self::assertFalse(AllySearchMigration::ready());}finally{remove_filter('update_post_metadata',$reject,10);}
        AllySearchMigration::run();self::assertTrue(AllySearchMigration::ready());
    }
    public function testRemovingCanonicalMetadataDoesNotLeaveTheFormerTypeSearchable(): void
    {
        $type='Borrado '.bin2hex(random_bytes(5));$id=$this->ally($type);delete_post_meta($id,'_ascla');
        wp_set_current_user($this->member);self::assertSame([],$this->ids($type));self::assertContains($id,$this->ids('Alianza institucional'));
    }
    public function testSearchShapeAndRegistryPermissionsAreValidatedOnTheServer(): void
    {
        wp_set_current_user($this->member);$r=new WP_REST_Request('GET','/ascla/v1/content/ally');$r->set_query_params(['type'=>'ally','q'=>['Convenio']]);self::assertSame(400,rest_do_request($r)->get_status());
        foreach([$this->member,$this->users[3],0] as $user){wp_set_current_user($user);$r=new WP_REST_Request('GET','/ascla/v1/admin/allies');$r->set_query_params(['q'=>'Convenio']);self::assertSame($user?403:401,rest_do_request($r)->get_status());}
    }
}
