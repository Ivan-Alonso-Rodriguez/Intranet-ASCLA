<?php
namespace ASCLA\Core\Repositories;

/** While recovery is pending, SQL read caches remain private; cache-only locks stay atomic. */
final class DatabaseCache
{
    private array $values=[];
    private array $globals=[];
    private int $blog;
    public function __construct(public readonly object $provider)
    {
        $this->blog=get_current_blog_id();$groups=$provider->global_groups??[];
        $this->globals=array_fill_keys(array_is_list((array)$groups)?(array)$groups:array_keys((array)$groups),true);
        foreach (['users','user_meta','useremail','userlogins','userslugs','site-options'] as $group) { $this->globals[$group]=true; }
    }
    private function key(mixed $id,string $group): string|false
    {
        if (!is_int($id) && (!is_string($id) || trim($id)==='')) { return false; }
        return wp_json_encode([isset($this->globals[$group])?0:$this->blog,$group,(string)$id]);
    }
    public function get(mixed $id,string $group='default',bool $force=false,mixed &$found=null): mixed
    {
        $group=$group?:'default';
        if (!CacheJournal::databaseGroup($group)) { return $this->provider->get($id,$group,$force,$found); }
        $key=$this->key($id,$group);$found=$key!==false && !$force && array_key_exists($key,$this->values);
        if (!$found) { return false; }
        $value=$this->values[$key];return is_object($value)?clone $value:$value;
    }
    public function set(mixed $id,mixed $value,string $group='default',int $expire=0): bool
    {
        $group=$group?:'default';
        if (!CacheJournal::databaseGroup($group)) { return $this->provider->set($id,$value,$group,$expire); }
        $key=$this->key($id,$group);if ($key===false) { return false; }
        $this->values[$key]=is_object($value)?clone $value:$value;return true;
    }
    public function add(mixed $id,mixed $value,string $group='default',int $expire=0): bool
    {
        if (!CacheJournal::databaseGroup($group?:'default')) { return $this->provider->add($id,$value,$group,$expire); }
        $this->get($id,$group,false,$found);return !$found && $this->set($id,$value,$group,$expire);
    }
    public function replace(mixed $id,mixed $value,string $group='default',int $expire=0): bool
    {
        if (!CacheJournal::databaseGroup($group?:'default')) { return $this->provider->replace($id,$value,$group,$expire); }
        $this->get($id,$group,false,$found);return $found && $this->set($id,$value,$group,$expire);
    }
    public function delete(mixed $id,string $group='default'): bool
    {
        $group=$group?:'default';
        if (!CacheJournal::databaseGroup($group)) { return $this->provider->delete($id,$group); }
        $key=$this->key($id,$group);if ($key===false) { return false; }
        $found=array_key_exists($key,$this->values);unset($this->values[$key]);
        // Native WordPress writes during degraded operation must also leave a recovery record.
        CacheRecovery::stage(['flush'=>false,'entries'=>[[$this->blog,$id,$group]],'groups'=>array_keys($this->globals)]);
        return $found;
    }
    public function clear(): bool { $this->values=[];return true; }
    private function multiple(string $method,array $items,string $group,int $expire=0,bool $force=false): array
    {
        $result=[];
        foreach ($items as $key=>$value) {
            if ($method==='get') { $result[$value]=$this->get($value,$group,$force); }
            elseif ($method==='delete') { $result[$value]=$this->delete($value,$group); }
            else { $result[$key]=$this->$method($key,$value,$group,$expire); }
        }
        return $result;
    }
    private function adjust(string $method,mixed $id,int $offset,string $group): int|false
    {
        if (!CacheJournal::databaseGroup($group)) { return $this->provider->$method($id,$offset,$group); }
        $value=$this->get($id,$group,false,$found);if (!$found || !is_numeric($value)) { return false; }
        $next=max(0,(int)$value+($method==='incr'?$offset:-$offset));$this->set($id,$next,$group);return $next;
    }
    public function __call(string $method,array $args): mixed
    {
        if ($method==='switch_to_blog') { $this->blog=(int)$args[0];return $this->provider->switch_to_blog($this->blog); }
        if ($method==='add_global_groups') {
            foreach ((array)$args[0] as $group) { $this->globals[$group]=true; }
            return $this->provider->add_global_groups($args[0]);
        }
        return match($method) {
            'get_multiple'=>$this->multiple('get',$args[0],$args[1]??'default',0,(bool)($args[2]??false)),
            'delete_multiple'=>$this->multiple('delete',$args[0],$args[1]??'default'),
            'set_multiple','add_multiple'=>$this->multiple(str_replace('_multiple','',$method),$args[0],$args[1]??'default',$args[2]??0),
            'incr','decr'=>$this->adjust($method,$args[0],(int)($args[1]??1),$args[2]??'default'),
            'flush_runtime','reset'=>$this->clear(),
            'flush'=>CacheRecovery::flush($this),
            'flush_group'=>CacheJournal::databaseGroup($args[0])?CacheRecovery::flush($this):$this->provider->flush_group($args[0]),
            'supports'=>$args[0]!=='flush_group' && $this->provider->supports($args[0]),
            default=>$this->provider->$method(...$args),
        };
    }
}
