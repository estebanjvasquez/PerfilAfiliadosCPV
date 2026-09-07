<?php

return [
    /*
     * El resource publicado en app/Filament/Resources/UserResource.php (via
     * `php artisan filament-user:publish`, 7 sep 2026 al restaurar este paquete tras el
     * upgrade a Filament v3) es el que se usa - dejar en true evita que el paquete
     * registre ademas su propia copia interna (io3x1\FilamentUser\Resources\UserResource)
     * duplicando el menu.
     */
    "publish_resource" => true,

    /*
     * Grupo de navegacion del resource - historico, ya usado en produccion antes del
     * upgrade (ver config/filament-user.php en la rama main).
     */
    "group" => "Settings",

    /*
     * Boton "Impersonate" (loguearse como el usuario) en la tabla y en Editar.
     */
    "impersonate" => true,

    /*
     * Selector de Roles (Shield) en el formulario de usuario.
     */
    "shield" => true,
];
