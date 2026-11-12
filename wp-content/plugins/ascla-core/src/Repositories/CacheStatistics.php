<?php
namespace ASCLA\Core\Repositories;

/** Counter names exposed by WordPress remain available to cache diagnostics. */
trait CacheStatistics
{
    public int $cacheHits=0;
    public int $cacheMisses=0;
    private function counter(string $name): string
    {
        return match($name) {
            'cache_hits'=>'cacheHits',
            'cache_misses'=>'cacheMisses',
            default=>throw new RepositoryException('Contador de caché no válido.'),
        };
    }
    public function __get(string $name): int { return $this->{$this->counter($name)}; }
    public function __set(string $name,mixed $value): void { $this->{$this->counter($name)}=$value; }
    public function __isset(string $name): bool { return in_array($name,['cache_hits','cache_misses'],true); }
}
