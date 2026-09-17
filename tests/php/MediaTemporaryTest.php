<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Media;
use ASCLA\Core\Repositories\Store;

final class MediaTemporaryTest extends TestCase
{
    private int $user=0;
    private array $media=[];

    protected function setUp(): void
    {
        $id=wp_insert_user([
            'user_login'=>'media_temp_'.bin2hex(random_bytes(6)),
            'user_pass'=>wp_generate_password(30),
            'display_name'=>'Media Temporal',
            'role'=>'ascla_member',
        ]);
        self::assertIsInt($id);
        $this->user=$id;
        wp_set_current_user($id);
    }

    protected function tearDown(): void
    {
        foreach ($this->media as $id) { Store::delete('media',['id'=>$id]); }
        if ($this->user) wp_delete_user($this->user);
        wp_set_current_user(0);
    }

    private function row(int $postId): int
    {
        $id=Store::insert('media',[
            'user_id'=>$this->user,
            'post_id'=>$postId,
            'original_id'=>0,
            'name'=>'prueba.webp',
            'mime'=>'image/webp',
            'bytes'=>'fake-image-bytes',
            'created_at'=>current_time('mysql',true),
        ]);
        $this->media[]=$id;
        return $id;
    }

    public function testTemporaryUploadIsHiddenAndCanBeDiscardedWhenFormIsCancelled(): void
    {
        $id=$this->row(-1);
        $list=Media::listing();
        self::assertNotContains($id,array_column($list['items'],'id'));
        $result=Media::discard($id);
        self::assertTrue($result['discarded']);
        self::assertNull(Store::one('media',$id));
    }

    public function testCommittedUploadReturnsToLibraryAndCannotBeDiscardedAsTemporary(): void
    {
        $id=$this->row(-1);
        Media::commit($id,$this->user);
        $list=Media::listing();
        self::assertContains($id,array_column($list['items'],'id'));
        self::assertFalse(Media::discard($id)['discarded']);
        self::assertNotNull(Store::one('media',$id));
    }
    public function testAnotherMemberCannotDiscardOrListTheOwnersTemporaryFile(): void
    {
        $id=$this->row(-1);$other=wp_insert_user(['user_login'=>'media_other_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        try{wp_set_current_user($other);self::assertFalse(Media::discard($id)['discarded']);self::assertNotContains($id,array_column(Media::listing()['items'],'id'));self::assertNotNull(Store::one('media',$id));}
        finally{wp_delete_user($other);wp_set_current_user($this->user);}
    }
    public function testAbandonedCleanupPreservesFreshCommittedAndProfileReferencedFiles(): void
    {
        $expired=$this->row(-1);$fresh=$this->row(-1);$committed=$this->row(0);$profile=$this->row(-1);
        foreach([$expired,$committed,$profile] as $id){Store::update('media',['created_at'=>gmdate('Y-m-d H:i:s',time()-13*3600)],['id'=>$id]);}
        update_user_meta($this->user,'_ascla_profile',['photo_id'=>$profile]);Media::cleanupAbandoned();
        self::assertNull(Store::one('media',$expired));self::assertSame(-1,(int)Store::one('media',$fresh)['post_id']);
        self::assertNotNull(Store::one('media',$committed));self::assertSame(0,(int)Store::one('media',$profile)['post_id']);
    }
    public function testDiscardingACropRemovesItsUnusedTemporaryMaster(): void
    {
        $master=$this->row(-1);$crop=$this->row(-1);Store::update('media',['original_id'=>$master],['id'=>$crop]);
        self::assertTrue(Media::discard($crop)['discarded']);self::assertNull(Store::one('media',$crop));self::assertNull(Store::one('media',$master));
    }

}
