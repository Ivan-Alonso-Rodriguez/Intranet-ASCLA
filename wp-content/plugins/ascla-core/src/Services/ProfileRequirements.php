<?php
namespace ASCLA\Core\Services;

/** Minimum profile fields stay fixed; ASCLA can define additional completion criteria. */
final class ProfileRequirements
{
    public const DEFAULTS=['profile_completion_extra'=>[]];
    private const LABELS=['name'=>'Nombres y apellidos','position'=>'Cargo o profesión','company'=>'Empresa u organización','industries'=>'Sector profesional','location'=>'Ciudad o país','bio'=>'Descripción profesional','interests'=>'Al menos un interés','goals'=>'Al menos un objetivo de networking'];
    private const EXTRA=['areas'=>'Áreas de conocimiento','languages'=>'Idiomas','experience'=>'Experiencia profesional','learn'=>'Áreas que quiero aprender','help'=>'Áreas en las que puedo ayudar','linkedin'=>'LinkedIn','website'=>'Sitio web'];

    public static function options(): array
    {
        return ['required'=>self::LABELS,'additional'=>self::EXTRA];
    }

    public static function settings(array &$data,array $input): void
    {
        if (!array_key_exists('profile_completion_extra',$input)) { return; }
        $fields=$input['profile_completion_extra'];
        Access::require(is_array($fields) && array_is_list($fields),'Criterios de completitud no válidos.',400);
        foreach ($fields as $field) {
            Access::require(is_string($field) && isset(self::EXTRA[$field]),'Criterios de completitud no válidos.',400);
        }
        $data['profile_completion_extra']=array_values(array_unique($fields));
    }

    public static function checks(array $profile): array
    {
        $text=static fn(string $key): bool=>Access::hasText((string)($profile[$key]??''));
        return [
            'name'=>$text('first_name') && $text('last_name'),
            'position'=>$text('position'),
            'company'=>$text('company'),
            'industries'=>!empty($profile['industries']),
            'location'=>$text('country') || $text('city'),
            'bio'=>$text('bio'),
            'interests'=>!empty($profile['interests']),
            'goals'=>!empty($profile['goals']),
        ];
    }

    public static function missing(array $profile): array
    {
        return array_values(array_intersect_key(self::LABELS,array_filter(self::checks($profile),static fn($complete)=>!$complete)));
    }

    public static function completion(array $profile): array
    {
        $checks=self::checks($profile);
        $labels=self::LABELS;
        foreach ((array)Settings::get()['profile_completion_extra'] as $field) {
            if (!isset(self::EXTRA[$field])) { continue; }
            $value=$profile[$field]??'';
            $checks[$field]=is_array($value) ? (bool)$value : Access::hasText((string)$value);
            $labels[$field]=self::EXTRA[$field];
        }
        $completed=count(array_filter($checks));
        $missing=array_values(array_intersect_key($labels,array_filter($checks,static fn($complete)=>!$complete)));
        return ['percent'=>(int)round(100*$completed/count($checks)),'completed'=>$completed,'total'=>count($checks),'minimum'=>100,'checks'=>$checks,'missing'=>$missing];
    }
}
