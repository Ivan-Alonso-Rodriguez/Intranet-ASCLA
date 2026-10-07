<?php
/** Explicit complete fixture; tests for missing fields deliberately bypass this helper. */
function ascla_test_profile(array $overrides=[]): array
{
    $catalogs=\ASCLA\Core\Services\Profiles::catalogs();
    return $overrides+[
        'first_name'=>'Persona','last_name'=>'Prueba','position'=>'Analista','company'=>'Organización de prueba',
        'city'=>'Lima','bio'=>'Biografía del perfil de prueba',
        'industries'=>[(int)$catalogs['industry'][0]['id']],
        'interests'=>[(int)$catalogs['interest'][0]['id']],
        'goals'=>[(int)$catalogs['goal'][0]['id']],
    ];
}
