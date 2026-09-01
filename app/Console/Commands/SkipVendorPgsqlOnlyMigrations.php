<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca como "ya corridas" en la tabla `migrations` de MYSQL (conexion default) las migraciones
 * que vienen empaquetadas DENTRO de un paquete de composer y se auto-registran siempre via
 * `loadMigrationsFrom()` en el ServiceProvider del paquete - sin importar que conexion este
 * activa, y sin ningun `$connection` propio que las limite a pgsql.
 *
 * Encontrado con `php artisan migrate --pretend` (sin --database) antes de ejecutarlo de verdad:
 * `vendor/pgvector/pgvector/.../2022_08_03_000000_create_vector_extension.php` hace
 * `CREATE EXTENSION IF NOT EXISTS vector` - sintaxis exclusiva de Postgres, error de sintaxis
 * inmediato en MariaDB. Como el archivo vive en vendor/ (se pisa en cada `composer update`) no se
 * le puede agregar `$connection = 'pgsql'` a mano, ni moverlo de carpeta como se hizo con las
 * migraciones propias en database/migrations/pgsql/ (`loadMigrationsFrom` del paquete no respeta
 * el flag --path).
 *
 * La extension ya fue creada en Postgres (ver `php artisan migrate:status --database=pgsql`, esta
 * migracion aparece "Ran" ahi) - lo unico que falta es que MySQL, que nunca la corrio, deje de
 * considerarla pendiente para siempre. Se logra insertando un registro en su propia tabla
 * `migrations` (mismo mecanismo interno que usa el propio `migrate` para llevar la cuenta) -
 * equivalente a decir "esto no aplica aca, dalo por hecho".
 *
 * Correr UNA VEZ por cada entorno/base de datos MySQL nueva (dev local, servidor de pruebas,
 * produccion si alguna vez corre `migrate --force` sin --database=pgsql) - es idempotente, no
 * hace nada si ya esta marcada.
 */
class SkipVendorPgsqlOnlyMigrations extends Command
{
    protected $signature = 'db:skip-vendor-pgsql-migrations';

    protected $description = 'Marca como ya corridas en MySQL las migraciones de paquetes que solo aplican a Postgres';

    /**
     * Nombres de migracion (sin ".php") que son Postgres-only pero vienen de un paquete de
     * composer con auto-registro incondicional. Agregar aca cualquier otra que aparezca en el
     * futuro (ej. si se agrega otro paquete con el mismo patron).
     */
    private const NAMES = [
        '2022_08_03_000000_create_vector_extension',
    ];

    public function handle(): int
    {
        $batch = DB::connection('mysql')->table('migrations')->max('batch') ?? 0;

        foreach (self::NAMES as $name) {
            $exists = DB::connection('mysql')->table('migrations')->where('migration', $name)->exists();

            if ($exists) {
                $this->line("{$name}: ya estaba marcada, sin cambios.");

                continue;
            }

            DB::connection('mysql')->table('migrations')->insert([
                'migration' => $name,
                'batch' => $batch,
            ]);

            $this->info("{$name}: marcada como ya corrida en mysql.");
        }

        return self::SUCCESS;
    }
}
