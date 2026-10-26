<?php
function ascla_test_support_state(int $id,int $admin,string $state='open'): void
{
    wp_set_current_user($admin);
    ASCLA\Core\Services\SupportRequests::assign($id,$admin);
    foreach (['progress','resolved','closed'] as $next) {
        if ($state==='open') { break; }
        ASCLA\Core\Services\Administration::contactStatus($id,$next,$next==='resolved'?'Respuesta de prueba documentada.':'');
        if ($next===$state) { break; }
    }
}
