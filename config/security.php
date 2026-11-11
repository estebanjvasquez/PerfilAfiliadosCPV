<?php

/*
 * TASK-0018 / SEC-03 (Issue #2 `6802857575`): origen canónico, hosts, proxies y transporte.
 * Hallazgos y porqué: audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md y docs/orquestador/tasks/0018-*.md.
 * Nada de esto se aplica en APP_ENV=local, para no estorbar el desarrollo con otros hosts.
 */
return [

    /*
     * P1 (F-SEC03-01): todo enlace generado (recuperación, verificación, notificaciones) usa la raíz de APP_URL, nunca
     * el Host de la petición. Si APP_URL es https, el esquema es siempre https.
     */
    'canonical_url' => (bool) env('APP_CANONICAL_URL', true),

    /*
     * P1 (F-SEC03-02): se rechazan (404) las peticiones cuyo Host no es el de APP_URL, el dominio del panel o uno de esta
     * lista (hosts exactos, separados por comas; sin comodines).
     */
    'trusted_hosts' => [
        'enforce' => (bool) env('APP_TRUSTED_HOSTS_ENFORCE', true),
        'extra' => env('APP_TRUSTED_HOSTS', ''),
    ],

    /*
     * P2 (F-SEC03-03): IP real del cliente.
     *
     * `trusted` son los únicos pares desde los que se acepta la IP reenviada (IPs o CIDR, separados por comas; `none`
     * desactiva). El valor por defecto es la topología de este repositorio (docker-compose.yml): nginx solo escucha en
     * 127.0.0.1:8080 y PHP lo ve llegar desde el gateway de la red bridge de Docker (172.16.0.0/12). `*` no se admite.
     *
     * `client_ip_header` es la cabecera con la IP del cliente que pone el borde (Cloudflare la reescribe siempre). Solo
     * se lee si el par es de confianza; si falta o no es una IP, la petición se queda con la IP del par, como antes.
     * Vacío = X-Forwarded-For estándar (para un despliegue sin Cloudflare). X-Forwarded-Host nunca se acepta.
     */
    'proxies' => [
        'trusted' => env('TRUSTED_PROXIES', '127.0.0.1,::1,172.16.0.0/12'),
        'client_ip_header' => env('TRUSTED_PROXY_CLIENT_IP_HEADER', 'CF-Connecting-IP'),
    ],

    /*
     * P5 (F-SEC03-06): transporte.
     *
     * `redirect`: redirige a https solo cuando un proxy de confianza dice explícitamente X-Forwarded-Proto: http (así no
     * hay bucle si el borde no manda la cabecera). Apagado por defecto: antes hay que confirmar qué cabecera llega por el
     * túnel. La alternativa recomendada es «Always Use HTTPS» en Cloudflare (infraestructura, con autorización).
     *
     * `hsts_max_age`: segundos de Strict-Transport-Security en respuestas https; 0 lo apaga. Por defecto 1 día, fácil de
     * revertir (con 0 se deja de enviar; con max-age=0 los navegadores lo olvidan). Subir por etapas con aprobación.
     * `includeSubDomains` y `preload` quedan apagados: no se activan sin aprobación específica del propietario.
     */
    'https' => [
        'redirect' => (bool) env('HTTPS_REDIRECT', false),
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 86400),
        'hsts_include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
        'hsts_preload' => (bool) env('HSTS_PRELOAD', false),
    ],

];
