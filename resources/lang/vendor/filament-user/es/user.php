<?php

// Traduccion propia - el paquete 3x1io/filament-user (v1.1.11) no trae "es" (solo
// ar/en/ja/pt_BR/ru), y config/app.php usa locale=es con fallback_locale=en. Sin este
// archivo el panel mostraba las etiquetas en ingles. Laravel reemplaza el archivo
// completo del grupo al encontrar uno en lang/vendor/ (no hace merge por clave), por
// eso se traduce el archivo entero en vez de solo agregar claves.
return [
    "resource" => [
        "id" => "ID",
        "single" => "Usuario",
        "email_verified_at" => "Correo verificado",
        "created_at" => "Creado",
        "updated_at" => "Actualizado",
        "verified" => "Verificado",
        "unverified" => "Sin verificar",
        "name" => "Nombre",
        "email" => "Correo",
        "password" => "Contraseña",
        "roles" => "Roles",
        "label" => "Usuarios",
        "title" => [
            "create" => "Crear usuario",
            "edit" => "Editar usuario",
            "list" => "Usuarios",
            "home" => "Usuarios"
        ],
    ]
];
