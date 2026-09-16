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
}
