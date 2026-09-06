<?php
namespace ASCLA\Core\Integrations;
interface SocialProviderInterface { public function posts(string $profile): array; }
