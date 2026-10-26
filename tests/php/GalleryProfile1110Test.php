<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Profiles};
use ASCLA\Core\Repositories\{Store,RepositoryException};

final class GalleryProfile1110Test extends TestCase
{
    private array $users=[],$posts=[],$files=[];
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $id=wp_insert_user(['user_login'=>'gallery_profile_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(30),'role'=>$role]);$this->users[]=$id;
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);foreach ($this->posts as $id) wp_delete_post($id,true);
        foreach ($this->files as $id) Store::delete('media',['id'=>$id]);
        foreach ($this->users as $id) { Store::delete('notifications',['user_id'=>$id]);Store::delete('deliveries',['actor_id'=>$id]);wp_delete_user($id); }
        wp_set_current_user(0);
    }
    private function file(int $original=0,string $mime='image/webp'): int
    {
        $id=Store::insert('media',['user_id'=>$this->users[0],'post_id'=>-1,'original_id'=>$original,'name'=>$mime==='application/pdf'?'programa.pdf':'foto.webp','mime'=>$mime,'bytes'=>'local-fixture-bytes','created_at'=>current_time('mysql',true)]);$this->files[]=$id;return $id;
    }
    private function gallery(array $files): int
    {
        $id=Content::save('gallery',['title'=>'Encuentro de la comunidad','body'=>'Imágenes y documentos del encuentro','status'=>'publish','meta'=>['media_ids'=>$files,'chatham'=>false]])['id'];$this->posts[]=$id;return $id;
    }
    public function testRemovingGalleryDeletesImagesPdfAndUnusedMasterButPreservesOtherFiles(): void
    {
        $master=$this->file();$photo=$this->file($master);$pdf=$this->file(0,'application/pdf');$other=$this->file();$otherGallery=$this->gallery([$other]);$gallery=$this->gallery([$photo,$pdf]);
        self::assertTrue(Content::remove($gallery)['deleted']);
        foreach ([$photo,$pdf,$master] as $id) self::assertNull(Store::one('media',$id));
        self::assertNotNull(Store::one('media',$other));self::assertSame('publish',get_post_status($otherGallery));self::assertSame('trash',get_post_status($gallery));
        self::assertSame([],(get_post_meta($gallery,'_ascla',true)['media_ids']??[]));
    }
    public function testSharedMasterSurvivesUntilItsLastGalleryDerivativeIsRemoved(): void
    {
        $master=$this->file();$a=$this->file($master);$b=$this->file($master);$first=$this->gallery([$a]);$second=$this->gallery([$b]);
        Content::remove($first);self::assertNull(Store::one('media',$a));self::assertNotNull(Store::one('media',$master));self::assertNotNull(Store::one('media',$b));
        Content::remove($second);self::assertNull(Store::one('media',$b));self::assertNull(Store::one('media',$master));
    }
    public function testUnauthorizedGalleryDeletionPreservesThePostAndItsBytes(): void
    {
        $file=$this->file();$id=$this->gallery([$file]);wp_set_current_user($this->users[1]);
        try { Content::remove($id);self::fail('Expected refusal'); }
        catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(403,$error->getCode()); }
        self::assertSame('publish',get_post_status($id));self::assertNotNull(Store::one('media',$file));
    }
    public function testDatabaseFailureRollsBackGalleryAndFileDeletionTogether(): void
    {
        global $wpdb;$file=$this->file();$id=$this->gallery([$file]);$previous=$wpdb->suppress_errors(true);
        $fail=static fn($sql)=>str_starts_with($sql,'DELETE FROM `'.Store::table('media').'`')?'DELETE FROM ascla_nonexistent_gallery_test':$sql;
        add_filter('query',$fail);
        try { Content::remove($id);self::fail('Expected a failed write'); }
        catch (RepositoryException) { self::assertTrue(true); }
        finally { remove_filter('query',$fail);$wpdb->suppress_errors($previous);$wpdb->last_error=''; }
        clean_post_cache($id);self::assertSame('publish',get_post_status($id));self::assertNotNull(Store::one('media',$file));self::assertSame([$file],get_post_meta($id,'_ascla',true)['media_ids']);
    }
    public function testUsernameIsAvailableOnlyOnOwnProfile(): void
    {
        self::assertSame(wp_get_current_user()->user_login,Profiles::visible($this->users[0])['username']);
        self::assertArrayNotHasKey('username',Profiles::visible($this->users[1]));
        wp_set_current_user($this->users[1]);self::assertSame(wp_get_current_user()->user_login,Profiles::visible($this->users[1])['username']);
        self::assertArrayNotHasKey('username',Profiles::visible($this->users[2]));
    }
}
