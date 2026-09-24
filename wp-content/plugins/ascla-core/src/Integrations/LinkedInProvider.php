<?php
namespace ASCLA\Core\Integrations;
final class LinkedInProvider implements SocialProviderInterface
{
    public function posts(string $profile): array { throw new IntegrationException('LinkedIn requiere aprobación y permisos de API para el caso de uso. Adaptador pendiente de habilitación; no se realiza scraping.'); }
}
