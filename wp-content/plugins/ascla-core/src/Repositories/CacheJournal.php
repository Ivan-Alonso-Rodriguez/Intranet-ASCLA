<?php
namespace ASCLA\Core\Repositories;

/** Defer cache-only values (including WordPress transients) without publishing SQL read caches. */
final class CacheJournal
{
    private array $writes=[],$cleared=[];
    private const DATABASE_GROUPS=['options','site-options','users','user_meta','useremail','userlogins','userslugs','user-queries',
        'posts','post_meta','post-queries','terms','term_meta','term-queries','comments','comment','comment_meta','comment-queries',
        'sites','site-details','site-queries','blog-details','blog-lookup','blog-id-cache','blog_meta','networks','network-queries','counts'];
    public function __construct(private object $original) {}
    public static function databaseGroup(string $group): bool { return in_array($group,self::DATABASE_GROUPS,true) || str_ends_with($group,'_relationships'); }
    public function read(string $key,int $blog,mixed $id,string $group,mixed &$found): mixed
    {
        $found=false;
        if (isset($this->writes[$key])) {
            $write=$this->writes[$key];
            if ($write['remove'] || $write['until']>0 && $write['until']<=time()) { return false; }
            $found=true;return $write['value'];
        }
        if (self::databaseGroup($group)) { return false; }
        if (isset($this->cleared['*']) || isset($this->cleared[$group])) { return false; }
        $this->original->switch_to_blog($blog);
        try { return $this->original->get($id,$group,false,$found); }
        finally { $this->original->switch_to_blog(get_current_blog_id()); }
    }
    public function write(string $key,int $blog,mixed $id,string $group,mixed $value,int $until,bool $remove=false): void
    {
        if (!self::databaseGroup($group)) { $this->writes[$key]=['blog'=>$blog,'id'=>$id,'group'=>$group,'value'=>$value,'until'=>$until,'remove'=>$remove]; }
    }
    public function checkpoint(): array { return [$this->writes,$this->cleared]; }
    public function rollback(array $state): void { [$this->writes,$this->cleared]=$state; }
    public function flush(?string $group=null): void
    {
        foreach ($this->writes as $key=>$write) { if ($group===null || $write['group']===$group) { unset($this->writes[$key]); } }
        $this->cleared[$group??'*']=true;
    }
    public function commit(): void
    {
        try {
            foreach ($this->writes as $write) {
                $this->original->switch_to_blog($write['blog']);
                if ($write['remove'] || $write['until']>0 && $write['until']<=time()) { $this->original->delete($write['id'],$write['group']); }
                elseif (!$this->original->set($write['id'],$write['value'],$write['group'],$write['until']?max(1,$write['until']-time()):0)) { throw new RepositoryException('No se pudo actualizar el caché confirmado.'); }
            }
        } finally { $this->original->switch_to_blog(get_current_blog_id()); }
    }
}
