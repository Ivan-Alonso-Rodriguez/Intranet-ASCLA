<?php
namespace ASCLA\Core\Integrations;
final class MockSocialProvider implements SocialProviderInterface
{
    public function posts(string $profile): array
    {
        return [['id'=>'demo-social-1','text'=>'¿Cómo puede la junta directiva supervisar los riesgos de inteligencia artificial? Compartir responsabilidades es un primer paso para una gobernanza informada.','url'=>'','mode'=>'DEMO MODE'],['id'=>'demo-social-2','text'=>'Compra nuestro curso con descuento. Oferta limitada.','url'=>'','mode'=>'DEMO MODE']];
    }
}
