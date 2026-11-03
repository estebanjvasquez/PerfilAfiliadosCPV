<?php

/*
 * TASK-0012 (Issue #2 `6602168914`): recuperación de acceso por correo y por RIF.
 * Las reglas y su porqué están en docs/orquestador/tasks/0012-account-recovery.md (R1–R6).
 */
return [

    /*
     * Rol de afiliado, por NOMBRE (nunca por id). La ayuda por RIF solo escribe a cuentas con este rol y ningún otro,
     * sin permisos directos. Si el rol no existe, nadie es elegible.
     */
    'affiliate_role' => env('ACCOUNT_RECOVERY_AFFILIATE_ROLE', 'afiliados'),

    /* Una cuenta vinculada a más empresas que esto se trata como interna o masiva y no recibe correos por RIF. */
    'max_linked_companies' => (int) env('ACCOUNT_RECOVERY_MAX_LINKED_COMPANIES', 3),

    /* Si una empresa tiene más buzones elegibles que esto, no se envía nada por RIF (contra el uso como spam). */
    'max_rif_recipients' => (int) env('ACCOUNT_RECOVERY_MAX_RIF_RECIPIENTS', 5),

    /* Límites de solicitudes: [intentos, minutos]. Además, el broker no crea otro token para la misma cuenta antes de
     * auth.passwords.users.throttle segundos. */
    'limits' => [
        'per_ip' => [5, 15],
        'per_email' => [3, 60],
        'per_rif' => [3, 60],
    ],

];
