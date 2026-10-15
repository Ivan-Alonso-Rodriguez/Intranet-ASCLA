<?php
/** File-backed provider for disposable cross-process tests, not a production drop-in. */
final class AsclaSharedCacheFixture
{
    private WP_Object_Cache $inner;
    public bool $failDeletes=false;
    public bool $throwDeletes=false,$failWrites=false;
    public function __construct(private string $path) { $this->inner=new WP_Object_Cache(); }
    public function __get(string $name): mixed { return $this->inner->$name; }
    private function access(callable $callback): mixed
    {
        $file=fopen($this->path,'c+');if (!$file || !flock($file,LOCK_EX)) { throw new RuntimeException('Fixture cache unavailable'); }
        try {
            $raw=stream_get_contents($file);$state=$raw!==''?unserialize($raw):['values'=>[],'flushes'=>0];
            $property=new ReflectionProperty(WP_Object_Cache::class,'cache');$property->setValue($this->inner,$state['values']);
            $result=$callback($state);$state['values']=$property->getValue($this->inner);
            rewind($file);ftruncate($file,0);fwrite($file,serialize($state));fflush($file);return $result;
        } finally { flock($file,LOCK_UN);fclose($file); }
    }
    public function get($key,$group='default',$force=false,&$found=null): mixed
    {
        return $this->access(function() use($key,$group,$force,&$found) { return $this->inner->get($key,$group,$force,$found); });
    }
    public function __call(string $method,array $args): mixed
    {
        if ($this->throwDeletes && in_array($method,['delete','flush'],true)) { throw new RuntimeException('Fixture cache unavailable'); }
        if ($this->failDeletes && in_array($method,['delete','flush'],true)) { return false; }
        if ($this->failWrites && $method==='set') { return false; }
        return $this->access(function(&$state) use($method,$args) {
            if ($method==='flush') { ++$state['flushes']; }
            return $this->inner->$method(...$args);
        });
    }
    public function flushes(): int { return $this->access(static fn($state)=>(int)$state['flushes']); }
}
