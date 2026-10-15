<?php
namespace ASCLA\Core\Repositories;

/** Core Object Cache API, isolated from the shared provider until the SQL commit. */
final class TransactionCache
{
    private array $values=[],$touched=[],$globals=[];
    private bool $flushAll=false;
    private int $blog;
    private CacheJournal $journal;
    public int $cache_hits=0,$cache_misses=0;
    public function __construct(private object $original)
    {
        $this->blog=get_current_blog_id();
        $this->journal=new CacheJournal($original);
        $groups=$original->global_groups??[];
        $groups=is_array($groups)?$groups:[];
        $this->globals=array_fill_keys(array_is_list($groups)?$groups:array_keys($groups),true);
        foreach (['users','user_meta','useremail','userlogins','userslugs','site-options','site-transient'] as $group) { $this->globals[$group]=true; }
    }
    private function key(mixed $key,string $group): string|false
    {
        if (!is_int($key) && (!is_string($key) || trim($key)==='')) { return false; }
        return wp_json_encode([isset($this->globals[$group])?0:$this->blog,$group,(string)$key]);
    }
    private function touch(string $key,mixed $id,string $group): void { $this->touched[$key]=[$this->blog,$id,$group]; }
    public function get(mixed $id,string $group='default',bool $force=false,mixed &$found=null): mixed
    {
        unset($force);$group=$group?:'default';$key=$this->key($id,$group);$found=false;
        if ($key===false) { return false; }
        $this->touch($key,$id,$group);$entry=$this->values[$key]??null;
        if (!$entry || $entry['until']>0 && $entry['until']<=time()) {
            unset($this->values[$key]);$value=$this->journal->read($key,$this->blog,$id,$group,$found);
            if (!$found) { ++$this->cache_misses;return false; }
            $entry=['value'=>$value,'until'=>0];$this->values[$key]=$entry;
        }
        $found=true;++$this->cache_hits;return is_object($entry['value'])?clone $entry['value']:$entry['value'];
    }
    public function set(mixed $id,mixed $value,string $group='default',int $expire=0): bool
    {
        $group=$group?:'default';$key=$this->key($id,$group);
        if ($key===false) { return false; }
        $this->touch($key,$id,$group);
        $entry=['value'=>is_object($value)?clone $value:$value,'until'=>$expire>0?time()+$expire:0];$this->values[$key]=$entry;
        $this->journal->write($key,$this->blog,$id,$group,$entry['value'],$entry['until']);return true;
    }
    public function add(mixed $id,mixed $value,string $group='default',int $expire=0): bool
    {
        $this->get($id,$group,false,$found);
        return !$found && $this->set($id,$value,$group,$expire);
    }
    public function replace(mixed $id,mixed $value,string $group='default',int $expire=0): bool
    {
        $this->get($id,$group,false,$found);
        return $found && $this->set($id,$value,$group,$expire);
    }
    public function delete(mixed $id,string $group='default'): bool
    {
        $group=$group?:'default';$key=$this->key($id,$group);
        if ($key===false) { return false; }
        $this->touch($key,$id,$group);$found=isset($this->values[$key]);unset($this->values[$key]);
        $this->journal->write($key,$this->blog,$id,$group,null,0,true);return $found;
    }
    private function adjust(mixed $id,int $offset,string $group): int|false
    {
        $value=$this->get($id,$group,false,$found);
        if (!$found || !is_numeric($value)) { return false; }
        $next=max(0,(int)$value+$offset);$key=$this->key($id,$group?:'default');$this->values[$key]['value']=$next;
        $this->journal->write($key,$this->blog,$id,$group?:'default',$next,$this->values[$key]['until']);return $next;
    }
    private function multiple(string $method,array $items,string $group,int $expire=0): array
    {
        $results=[];
        foreach ($items as $key=>$value) {
            if (in_array($method,['get','delete'],true)) { $results[$value]=$this->$method($value,$group); }
            else { $results[$key]=$this->$method($key,$value,$group,$expire); }
        }
        return $results;
    }
    public function clear(): bool { $this->values=[];return true; }
    public function checkpoint(): array { return [$this->flushAll,$this->globals,$this->journal->checkpoint()]; }
    public function rollback(array $checkpoint): void { $this->clear();[$this->flushAll,$this->globals,$writes]=$checkpoint;$this->journal->rollback($writes); }
    public function flush(): bool { $this->flushAll=true;$this->journal->flush();return $this->clear(); }
    private function flushGroup(string $group): bool
    {
        $this->flushAll=true;
        $this->journal->flush($group);
        foreach ($this->values as $key=>$entry) { if (($this->touched[$key][2]??'')===$group) { unset($this->values[$key]); } }
        return true;
    }
    public function __call(string $method,array $args): mixed
    {
        return match($method) {
            'get_multiple','delete_multiple'=>$this->multiple(str_replace('_multiple','',$method),$args[0],$args[1]??'default'),
            'set_multiple','add_multiple'=>$this->multiple(str_replace('_multiple','',$method),$args[0],$args[1]??'default',$args[2]??0),
            'incr'=>$this->adjust($args[0],(int)($args[1]??1),$args[2]??'default'),
            'decr'=>$this->adjust($args[0],-(int)($args[1]??1),$args[2]??'default'),
            'flush_runtime','reset'=>$this->clear(),
            'flush_group'=>$this->flushGroup($args[0]),
            'add_global_groups'=>$this->addGroups((array)$args[0]),
            'add_non_persistent_groups','close','stats'=>true,
            'switch_to_blog'=>$this->switchBlog((int)$args[0]),
            // Group flushing cannot reliably invalidate an unknown shared provider on commit.
            'supports'=>in_array($args[0],['add_multiple','set_multiple','get_multiple','delete_multiple','flush_runtime'],true),
            default=>throw new RepositoryException('Operación de caché no compatible con la transacción.'),
        };
    }
    private function addGroups(array $groups): void { foreach ($groups as $group) { $this->globals[$group]=true; } }
    private function switchBlog(int $id): void { $this->blog=$id; }
    public function recovery(): array
    {
        return ['flush'=>$this->flushAll,'entries'=>array_values(array_filter($this->touched,static fn($entry)=>CacheJournal::databaseGroup($entry[2]))),'groups'=>array_keys($this->globals)];
    }
    public function finish(bool $committed): void
    {
        if (!$committed) { return; }
        CacheRecovery::apply($this->recovery(),$this->original);
        $this->journal->commit();
    }
}
