<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Database\Installer;
use ASCLA\Core\Repositories\Store;

final class MediaMigrationTest extends TestCase
{
    public function testUpgradeFromUnsignedSchemaPreservesFilesAndAcceptsTemporaryMarker(): void
    {
        global $wpdb;$table=Store::table('media');$schema=get_option('ascla_schema');$version=get_option('ascla_version');
        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");$created=false;
        try{
            // A connection-local shadow table isolates the old-schema migration from all saved files.
            $ddl=$wpdb->get_row("SHOW CREATE TABLE $table",ARRAY_N)[1];
            self::assertNotFalse($wpdb->query(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl)));$created=true;
            self::assertNotFalse($wpdb->query("ALTER TABLE $table MODIFY post_id bigint(20) unsigned NOT NULL DEFAULT 0"));
            $rows=[];
            foreach([0,42] as $post){$id=Store::insert('media',['user_id'=>1,'post_id'=>$post,'name'=>'preserved.pdf','mime'=>'application/pdf','bytes'=>'original bytes '.$post,'created_at'=>current_time('mysql',true)]);$rows[$id]=Store::one('media',$id);}
            update_option('ascla_schema',10);update_option('ascla_version',ASCLA_VERSION);
            Installer::upgrade();
            self::assertSame(11,(int)get_option('ascla_schema'));
            foreach($rows as $id=>$row){self::assertSame($row,Store::one('media',$id));}
            $id=Store::insert('media',['user_id'=>1,'post_id'=>-1,'name'=>'temporary.pdf','mime'=>'application/pdf','bytes'=>'temporary','created_at'=>current_time('mysql',true)]);
            self::assertSame(-1,(int)Store::one('media',$id)['post_id']);
            Installer::upgrade();self::assertSame(-1,(int)Store::one('media',$id)['post_id']);
        }finally{
            if($created){$wpdb->query("DROP TEMPORARY TABLE $table");}
            update_option('ascla_schema',$schema);update_option('ascla_version',$version);
        }
        self::assertSame($count,(int)$wpdb->get_var("SELECT COUNT(*) FROM $table"));
    }
}
