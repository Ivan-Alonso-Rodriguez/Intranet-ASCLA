<?php
namespace ASCLA\Core\Integrations;
interface VideoProviderInterface { public function transcript(string $videoId): array; public function metadata(string $videoId): array; }
