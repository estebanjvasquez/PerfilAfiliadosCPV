<?php

/**
 * Estado del correo del sistema, SIN imprimir ningun valor secreto.
 *
 * Uso (operador, en el servidor, desde el directorio de despliegue):
 *
 *   docker compose exec -T -u www-data app php < docs/deploy/mail_status_readonly.php
 *
 * Lee la configuracion que la app usa DE VERDAD (la cacheada si existe), y solo informa:
 *   - el nombre del mailer por defecto y su modo de transporte (smtp, log, array, ...);
 *   - si el transporte envia fuera del servidor o es una simulacion;
 *   - para smtp: host/puerto/cifrado/usuario/contrasena -> "configurado" o "falta" (nunca el valor);
 *   - remitente: "configurado" o "falta" (nunca la direccion);
 *   - si la configuracion esta cacheada.
 * No envia correo, no abre conexiones de red, no toca la base de datos y no vuelca el entorno.
 */

$out = fopen('php://stdout', 'w');

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
} catch (Throwable $e) {
    fwrite($out, 'arranque=ERROR '.get_class($e)."\n");
    exit(1);
}

$present = static fn ($v): string => ($v === null || $v === '' || $v === 'null') ? 'falta' : 'configurado';

$mailer = (string) config('mail.default');
$transport = (string) (config("mail.mailers.{$mailer}.transport") ?? $mailer);
$simulated = in_array($transport, ['log', 'array'], true);

$lines = [
    'mailer_por_defecto='.$mailer,
    'transporte='.$transport,
    'envia_fuera_del_servidor='.($simulated ? 'no (simulado)' : 'si'),
    'configuracion_cacheada='.($app->configurationIsCached() ? 'si' : 'no'),
    'remitente_direccion='.$present(config('mail.from.address')),
    'remitente_nombre='.$present(config('mail.from.name')),
];

if ($transport === 'smtp') {
    $smtp = config("mail.mailers.{$mailer}");
    $lines[] = 'smtp_host='.$present($smtp['host'] ?? null);
    $lines[] = 'smtp_puerto='.$present($smtp['port'] ?? null);
    $lines[] = 'smtp_cifrado='.$present($smtp['encryption'] ?? ($smtp['scheme'] ?? null));
    $lines[] = 'smtp_usuario='.$present($smtp['username'] ?? null);
    $lines[] = 'smtp_contrasena='.$present($smtp['password'] ?? null);
}

fwrite($out, implode("\n", $lines)."\n");
exit(0);
