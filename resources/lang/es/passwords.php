<?php

// TASK-0012: estados del broker de contraseñas en español. Sin este archivo salían en inglés (`resources/lang`
// tiene prioridad sobre `lang/`, ver TASK-0011). Las páginas de solicitud no muestran estos estados: responden siempre
// lo mismo (App\Filament\Pages\Auth\RequestPasswordReset).
return [
    'reset' => 'Su contraseña se cambió. Ya puede entrar con la nueva.',
    'sent' => 'Si ese correo es el de una cuenta, recibirá un enlace para elegir una contraseña nueva.',
    'throttled' => 'Espere un momento antes de intentar de nuevo.',
    'token' => 'Este enlace ya no es válido: venció, ya se usó o no corresponde a esta cuenta. Pida uno nuevo desde «¿Olvidaste tu contraseña?».',
    'user' => 'Este enlace ya no es válido: venció, ya se usó o no corresponde a esta cuenta. Pida uno nuevo desde «¿Olvidaste tu contraseña?».',
];
