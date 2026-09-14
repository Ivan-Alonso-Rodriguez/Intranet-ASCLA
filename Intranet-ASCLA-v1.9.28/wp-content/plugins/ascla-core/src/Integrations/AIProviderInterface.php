<?php
namespace ASCLA\Core\Integrations;
interface AIProviderInterface
{
    public function generate(string $task,array $context): array;
    public function mode(): string;
}
