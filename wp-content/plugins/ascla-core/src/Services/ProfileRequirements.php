<?php
namespace ASCLA\Core\Services;

/** RN-031/RN-039: only the minimum publication fields contribute to completion. */
final class ProfileRequirements
{
    public static function checks(array $profile): array
    {
        $text=static fn(string $key): bool=>(bool)preg_match('/[^\\s\\p{Z}\\x{200B}\\x{FEFF}]/u',(string)($profile[$key]??''));
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
        $labels=['name'=>'Nombres y apellidos','position'=>'Cargo o profesión','company'=>'Empresa u organización','industries'=>'Sector profesional','location'=>'Ciudad o país','bio'=>'Descripción profesional','interests'=>'Al menos un interés','goals'=>'Al menos un objetivo de networking'];
        return array_values(array_intersect_key($labels,array_filter(self::checks($profile),static fn($complete)=>!$complete)));
    }

    public static function completion(array $profile): array
    {
        $checks=self::checks($profile);
        $completed=count(array_filter($checks));
        return ['percent'=>(int)round(100*$completed/count($checks)),'completed'=>$completed,'total'=>count($checks),'minimum'=>100,'checks'=>$checks,'missing'=>self::missing($profile)];
    }
}
