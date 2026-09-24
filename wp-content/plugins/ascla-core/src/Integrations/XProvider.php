<?php
namespace ASCLA\Core\Integrations;
final class XProvider implements SocialProviderInterface
{
    public function posts(string $profile): array { throw new IntegrationException('X requiere plan y permisos oficiales. Adaptador pendiente de habilitación; no se realiza scraping.'); }
}
