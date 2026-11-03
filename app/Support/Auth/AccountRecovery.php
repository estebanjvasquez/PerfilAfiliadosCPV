<?php

namespace App\Support\Auth;

use App\Models\Empresa;
use App\Models\User;
use App\Notifications\Auth\AccountRecoveryLink;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TASK-0012 (Issue #2 `6602168914`): recuperación de acceso por correo y por RIF.
 *
 * - Las pantallas nunca dicen si una cuenta o un RIF existe: solo llaman a este servicio y muestran un texto fijo.
 * - El envío se difiere hasta después de responder (`defer`), para que el tiempo de respuesta tampoco lo diga.
 * - El token es el del broker de Laravel (`auth.passwords.users`): un solo uso, con hash en la base, vencimiento y
 *   reemplazo del anterior. El enlace fija el correo del titular, así que solo sirve para su propia cuenta.
 * - Por RIF solo se escribe a buzones afiliados elegibles (`eligibleForRif`, R3 del archivo de la tarea).
 */
final class AccountRecovery
{
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Misma normalización que Empresa::setRifAttribute. */
    public static function normalizeRif(string $rif): string
    {
        return mb_strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $rif));
    }

    public static function isValidRif(string $normalized): bool
    {
        return (bool) preg_match('/^[JGVEPC]\d{8,9}$/', $normalized);
    }

    /**
     * La cuenta de ese correo sin distinguir mayúsculas ni espacios, solo si la coincidencia es única. Postgres compara
     * `email = ?` exacto, y hay correos guardados con mayúsculas.
     */
    public static function findByEmail(string $email): ?User
    {
        $matches = User::query()->whereRaw('lower(trim(email)) = ?', [self::normalizeEmail($email)])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Cuentas a las que la ayuda por RIF puede escribir, todas las condiciones leídas ahora:
     * vinculada a la empresa, correo verificado, rol de afiliado por nombre y ningún otro rol, sin permisos directos y
     * vinculada a no más de `max_linked_companies` empresas. Un buzón compartido por dos cuentas se omite (ambiguo).
     *
     * @return Collection<int, User>
     */
    public static function eligibleForRif(string $normalizedRif): Collection
    {
        $empresaIds = Empresa::query()->where('rif', $normalizedRif)->pluck('id');
        if ($empresaIds->isEmpty()) {
            return collect();
        }
        $role = (string) config('account_recovery.affiliate_role');

        $accounts = User::query()
            ->whereHas('empresas', fn ($q) => $q->whereIn('empresas.id', $empresaIds))
            ->whereNotNull('email_verified_at')
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', '<>', $role))
            ->whereDoesntHave('permissions')
            ->addSelect(['linked_companies' => DB::table('empresa_user')->selectRaw('count(distinct empresa_id)')->whereColumn('empresa_user.user_id', 'users.id')])
            ->get()
            ->filter(fn (User $u) => $u->linked_companies <= (int) config('account_recovery.max_linked_companies'))
            ->filter(fn (User $u) => filter_var(trim($u->email), FILTER_VALIDATE_EMAIL));

        $mailboxes = $accounts->groupBy(fn (User $u) => self::normalizeEmail($u->email));
        // Un buzón con dos cuentas: también el login es ambiguo; mejor la Cámara (R6) que elegir una cuenta.
        $shared = User::query()->whereIn(DB::raw('lower(trim(email))'), $mailboxes->keys())
            ->selectRaw('lower(trim(email)) as mailbox')->groupByRaw('lower(trim(email))')->havingRaw('count(*) > 1')
            ->pluck('mailbox')->all();

        return $mailboxes->reject(fn ($group, $mailbox) => in_array($mailbox, $shared, true))
            ->map(fn (Collection $group) => $group->first())->values();
    }

    /**
     * true si el pedido se acepta; false si alguna clave llegó a su límite. Cuenta el intento antes de saber si la
     * cuenta existe, así que el límite tampoco lo dice.
     *
     * @param  array<string, array{0: int, 1: int}>  $keys  clave => [intentos, minutos]
     */
    public static function attempt(array $keys): bool
    {
        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }
        }
        foreach ($keys as $key => [, $minutes]) {
            RateLimiter::hit($key, $minutes * 60);
        }

        return true;
    }

    /**
     * Corre después de enviar la respuesta (en PHP-FPM, tras fastcgi_finish_request). Una sola vez: la aplicación no
     * olvida sus callbacks de terminate, y un proceso que atiende varias peticiones (las pruebas) los repetiría.
     */
    public static function defer(\Closure $work): void
    {
        $done = false;
        app()->terminating(function () use ($work, &$done): void {
            if ($done) {
                return;
            }
            $done = true;
            try {
                $work();
            } catch (\Throwable $e) {
                // Sin el mensaje: podría llevar el correo o el enlace.
                Log::error('Account recovery: sending failed ('.$e::class.').');
            }
        });
    }

    public static function sendByEmail(string $email): void
    {
        if ($user = self::findByEmail($email)) {
            self::sendLink($user, null);
        }
    }

    public static function sendByRif(string $normalizedRif): void
    {
        $recipients = self::eligibleForRif($normalizedRif);
        if ($recipients->count() > (int) config('account_recovery.max_rif_recipients')) {
            Log::warning('Account recovery by RIF: more eligible mailboxes than allowed; nothing sent (see the Cámara).');

            return;
        }
        foreach ($recipients as $user) {
            self::sendLink($user, $normalizedRif);
        }
    }

    private static function sendLink(User $user, ?string $rif): void
    {
        if (! self::mailCanCarryLinks()) {
            Log::warning('Account recovery: mail transport is log; the reset link was not sent so it is not written to the log.');

            return;
        }

        $broker = Password::broker(Filament::getPanel('admin')->getAuthPasswordBroker());
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            return;
        }

        $token = $broker->createToken($user);
        $user->notify(new AccountRecoveryLink(
            Filament::getPanel('admin')->getResetPasswordUrl($token, $user),
            $user->getEmailForPasswordReset(),
            $rif,
        ));
    }

    /** Con `log`, el cuerpo del correo, con el enlace, quedaría en el log. Solo se admite en local y testing. */
    private static function mailCanCarryLinks(): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return $transport !== 'log' || app()->environment(['local', 'testing']);
    }
}
