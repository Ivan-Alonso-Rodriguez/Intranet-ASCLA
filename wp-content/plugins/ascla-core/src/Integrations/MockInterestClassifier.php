<?php
namespace ASCLA\Core\Integrations;

final class MockInterestClassifier
{
    public static function formInterests(array $context): array
    {
        $terms=$context['catalog']??[];
        $text=mb_strtolower(remove_accents($context['text']??''));
        $ids=[];
        foreach ($terms as $term) {
            $name=mb_strtolower(remove_accents($term['name']));
            $direct=str_contains($text,$name);
            $aiTopic=(str_contains($name,'gobernanza de la ia')||str_contains($name,'inteligencia artificial'))
                && preg_match('/\b(chatgpt|gemini|llm|ia generativa)\b/',$text);
            $cyber=str_contains($name,'ciberseguridad') && preg_match('/hackeo|cyber|seguridad informatica/',$text);
            if ($direct || $aiTopic || $cyber) { $ids[]=(int)$term['id']; }
        }
        return ['intereses'=>array_slice(array_values(array_unique($ids)),0,3),'confianza'=>$ids?'media':'baja'];
    }
    public static function interestClassification(array $context): array
    {
        $aliases=[
            'inteligencia artificial'=>'/\b(ia|ai|llm|chatgpt|gemini|inteligencia artificial|machine learning)\b/u',
            'gestion de riesgos'=>'/\b(riesgo|riesgos|risk|coso)\b/u',
            'gobierno corporativo'=>'/\b(gobierno corporativo|gobernanza|corporate governance)\b/u',
            'juntas directivas'=>'/\b(juntas? directivas?|directorio|directorios|board)\b/u',
            'sostenibilidad'=>'/\b(sostenibilidad|sustentabilidad|esg|asg)\b/u',
            'transformacion digital'=>'/\b(digital|digitalizacion|transformacion digital)\b/u',
        ];
        $assignments=[];
        foreach ($context['items']??[] as $item) {
            $text=mb_strtolower(remove_accents($item['title']));
            $topic=self::classifiedTopic($text,$context['topics']??[],$aliases);
            $assignments[]=['id'=>(int)$item['id'],'topic_id'=>$topic];
        }
        return ['assignments'=>$assignments,'mode'=>MockAIProvider::MODE];
    }
    private static function classifiedTopic(string $text,array $topics,array $aliases): int
    {
        $topic=0;
        foreach ($topics as $term) {
            $label=mb_strtolower(remove_accents($term['name']));
            if (preg_match($aliases[$label]??('/'.preg_quote($label,'/').'/u'),$text)) {
                $topic=(int)$term['id'];
                break;
            }
        }
        return $topic;
    }
}
