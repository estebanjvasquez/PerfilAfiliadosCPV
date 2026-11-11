<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\Auth\RecoverAccessByRif;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Http\Middleware\TrustProxies;
use App\Models\Empresa;
use App\Models\User;
use App\Notifications\Auth\AccountRecoveryLink;
use App\Support\Auth\AccountRecovery;
use App\Support\Turnstile;
use Filament\Facades\Filament;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0017 / SEC-03 (Issue #2 `6797800863`): controles de recuperación de cuentas que DEBEN mantenerse.
 *
 * Cada prueba es un oráculo independiente: falla si el control es vulnerable. Aquí están las hipótesis que la
 * auditoría DESCARTÓ en el alcance probado; las que se confirmaron están en Sec03AccountRecoveryOpenFindingsTest.
 *
 * Dobles declarados: Turnstile responde sí; notificaciones con Notification::fake() (sin SMTP); el envío diferido corre
 * en el terminate de la aplicación. Cuentas, empresas y tokens sintéticos en la PostgreSQL desechable del runner. Los
 * tokens nunca se imprimen: las aserciones fallidas solo nombran cuentas sintéticas.
 */
#[Group('sec03')]
class Sec03AccountRecoveryControlsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['mail.default' => 'array']);
        $this->app->instance(Turnstile::class, new class extends Turnstile
        {
            public function enabled(): bool
            {
                return true;
            }

            public function verify(?string $token, ?string $ip = null): bool
            {
                return true;
            }
        });
        Notification::fake();
        Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        Role::findOrCreate('afiliados', 'web');
    }

    // ------------------------------------------------------------------ fixtures

    private function account(string $role = 'afiliados', ?string $email = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => Hash::make('Original-clave-1')]
            + ($email === null ? [] : ['email' => $email]));
        if ($role !== '') {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(string $rif, User ...$users): Empresa
    {
        $this->actingAs($this->account('super_admin'));
        $empresa = Empresa::create(['rif' => $rif, 'name' => 'ZZZSEC03EMPRESA'.random_int(1000, 9999), 'phone' => '+58-212-5550000',
            'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();
        foreach ($users as $user) {
            DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $empresa;
    }

    private function requestByEmail(string $email): void
    {
        Livewire::test(RequestPasswordReset::class)->set('data.email', $email)->call('request');
        $this->app->terminate();
    }

    /** @return array{url: string, email: string, token: string} */
    private function linkFor(User $user): array
    {
        $url = null;
        Notification::assertSentTo($user, AccountRecoveryLink::class, function (AccountRecoveryLink $n) use (&$url) {
            $url = $n->url;

            return true;
        });
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return ['url' => $url, 'email' => $q['email'], 'token' => $q['token']];
    }

    private function reset(string $email, string $token, string $password = 'Nueva-clave-2026'): void
    {
        Livewire::withQueryParams(['email' => $email, 'token' => $token])
            ->test(ResetPassword::class)
            ->set('password', $password)
            ->set('passwordConfirmation', $password)
            ->call('resetPassword');
    }

    private function passwordIs(User $user, string $plain): bool
    {
        return Hash::check($plain, (string) $user->fresh()->password);
    }

    // ------------------------------------------------------------------ A. vinculación del token

    #[Test]
    public function a_token_issued_for_a_never_changes_the_password_of_b_whatever_email_is_sent_with_it(): void
    {
        $a = $this->account();
        $b = $this->account();
        $this->requestByEmail($a->email);
        $tokenA = $this->linkFor($a)['token'];

        // Mismo token con el correo de B, con mayúsculas y espacios: el broker busca el registro de B y no lo valida.
        foreach ([$b->email, mb_strtoupper($b->email), ' '.$b->email.' '] as $email) {
            $this->reset($email, $tokenA, 'Robada-clave-2026');
        }

        $this->assertTrue($this->passwordIs($b, 'Original-clave-1'), 'la contraseña de B no cambia con el token de A');
        $this->assertTrue($this->passwordIs($a, 'Original-clave-1'), 'tampoco cambia la de A, porque el correo no era el suyo');
        // Y el token de A sigue sirviendo solo para A.
        $this->reset($a->email, $tokenA);
        $this->assertTrue($this->passwordIs($a, 'Nueva-clave-2026'));
    }

    #[Test]
    public function the_email_and_token_of_the_reset_page_cannot_be_changed_from_the_browser(): void
    {
        $a = $this->account();
        $b = $this->account();
        $this->requestByEmail($a->email);
        $link = $this->linkFor($a);

        foreach (['email' => $b->email, 'token' => str_repeat('0', 64)] as $property => $value) {
            try {
                Livewire::withQueryParams(['email' => $link['email'], 'token' => $link['token']])
                    ->test(ResetPassword::class)
                    ->set($property, $value);
                $this->fail("la propiedad {$property} de la página de restablecimiento no está bloqueada");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($this->passwordIs($b, 'Original-clave-1'));
    }

    #[Test]
    public function the_reset_link_is_signed_and_an_altered_email_or_missing_signature_is_refused(): void
    {
        $a = $this->account();
        $b = $this->account();
        $this->requestByEmail($a->email);
        $url = $this->linkFor($a)['url'];

        $this->get($url)->assertOk();

        $altered = str_replace('email='.urlencode($a->email), 'email='.urlencode($b->email), $url);
        $this->assertNotSame($url, $altered, 'el enlace contiene el correo del titular');
        $this->get($altered)->assertForbidden();

        $unsigned = preg_replace('/([?&])signature=[^&]+/', '$1', $url);
        $this->get($unsigned)->assertForbidden();
    }

    #[Test]
    public function the_reset_page_with_a_token_is_not_cacheable_and_does_not_widen_the_referrer(): void
    {
        $a = $this->account();
        $this->requestByEmail($a->email);
        $response = $this->get($this->linkFor($a)['url'])->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertTrue(str_contains($cache, 'no-cache') || str_contains($cache, 'no-store'), "Cache-Control: {$cache}");
        $this->assertStringContainsString('private', $cache);
        $this->assertNotContains(strtolower((string) $response->headers->get('Referrer-Policy')), ['unsafe-url', 'no-referrer-when-downgrade'],
            'la aplicación no amplía el Referer del navegador (por defecto strict-origin-when-cross-origin)');
    }

    #[Test]
    public function the_token_is_stored_only_as_a_hash_works_once_is_replaced_by_a_new_one_and_expires(): void
    {
        $a = $this->account();
        $this->requestByEmail($a->email);
        $first = $this->linkFor($a)['token'];

        $stored = (string) DB::table('password_resets')->where('email', $a->email)->value('token');
        $this->assertNotSame($first, $stored, 'el token no se guarda en claro');
        $this->assertTrue(Hash::check($first, $stored));
        $this->assertSame(64, strlen($first), 'HMAC-SHA256 de 40 caracteres aleatorios');
        $this->assertSame(1, DB::table('password_resets')->where('email', $a->email)->count());

        // Un segundo pedido después del throttle de 60 s reemplaza el token: el primero deja de servir.
        $this->travel(61)->seconds();
        Notification::fake();
        $this->requestByEmail($a->email);
        $second = $this->linkFor($a)['token'];
        $this->assertNotSame($first, $second);
        $this->assertSame(1, DB::table('password_resets')->where('email', $a->email)->count(), 'solo queda un token vivo');
        $this->reset($a->email, $first, 'Vieja-clave-2026');
        $this->assertTrue($this->passwordIs($a, 'Original-clave-1'), 'el token reemplazado no sirve');

        // Vencimiento: a los 61 minutos el token vigente ya no sirve.
        $this->travel(61)->minutes();
        $this->reset($a->email, $second, 'Tarde-clave-2026');
        $this->assertTrue($this->passwordIs($a, 'Original-clave-1'), 'el token vencido no sirve');
    }

    #[Test]
    public function a_used_token_cannot_be_replayed(): void
    {
        $a = $this->account();
        $this->requestByEmail($a->email);
        $token = $this->linkFor($a)['token'];

        $this->reset($a->email, $token, 'Primera-clave-2026');
        $this->assertTrue($this->passwordIs($a, 'Primera-clave-2026'));
        $this->assertSame(0, DB::table('password_resets')->where('email', $a->email)->count(), 'el uso borra el token');

        $this->reset($a->email, $token, 'Segunda-clave-2026');
        $this->assertTrue($this->passwordIs($a, 'Primera-clave-2026'), 'el mismo token no sirve dos veces');
    }

    #[Test]
    public function a_reset_rotates_the_remember_token_and_ends_sessions_opened_with_the_old_password(): void
    {
        $a = $this->account();
        $oldHash = (string) $a->password;
        $oldRemember = (string) $a->remember_token;
        $this->requestByEmail($a->email);
        $this->reset($a->email, $this->linkFor($a)['token']);

        $fresh = $a->fresh();
        $this->assertNotSame($oldRemember, (string) $fresh->remember_token, 'la cookie «recordarme» anterior deja de valer');

        // Una sesión abierta antes del cambio guarda el hash de la clave vieja: el panel la cierra en la siguiente petición.
        $this->actingAs($fresh, 'web')
            ->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($oldHash)])
            ->get('/admin');
        $this->assertGuest('web');

        // Control: una sesión con el hash vigente sigue abierta.
        $this->actingAs($fresh, 'web')
            ->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie((string) $fresh->password)])
            ->get('/admin');
        $this->assertAuthenticatedAs($fresh, 'web');
    }

    #[Test]
    public function accounts_whose_emails_differ_only_by_case_get_no_link_and_cannot_use_each_others_token(): void
    {
        $base = 'caso'.random_int(10000, 99999).'@example.test';
        $lower = $this->account(email: $base);
        $upper = $this->account(email: mb_strtoupper($base));

        $this->requestByEmail($base);
        Notification::assertNothingSent();

        // Aunque una de ellas recibiera un token (p. ej. emitido por un administrador), no sirve para la otra.
        $token = \Illuminate\Support\Facades\Password::broker(Filament::getPanel('admin')->getAuthPasswordBroker())->createToken($lower);
        $this->reset($upper->email, $token, 'Ajena-clave-2026');
        $this->assertTrue($this->passwordIs($upper, 'Original-clave-1'));
    }

    // ------------------------------------------------------------------ C. recuperación por RIF

    #[Test]
    public function each_rif_recipient_gets_a_link_bound_to_its_own_account_only(): void
    {
        $one = $this->account();
        $two = $this->account();
        $admin = $this->account('super_admin');
        $rif = 'J'.random_int(100000000, 999999999);
        $this->company($rif, $one, $two, $admin);

        Livewire::test(RecoverAccessByRif::class)->set('data.rif', $rif)->call('request');
        $this->app->terminate();

        Notification::assertNotSentTo($admin, AccountRecoveryLink::class);
        $linkOne = $this->linkFor($one);
        $linkTwo = $this->linkFor($two);
        $this->assertSame($one->email, $linkOne['email']);
        $this->assertSame($two->email, $linkTwo['email']);

        $this->reset($two->email, $linkOne['token'], 'Cruzada-clave-2026');
        $this->reset($admin->email, $linkOne['token'], 'Cruzada-clave-2026');
        $this->assertTrue($this->passwordIs($two, 'Original-clave-1'), 'el enlace de un destinatario no sirve para otro');
        $this->assertTrue($this->passwordIs($admin, 'Original-clave-1'));
    }

    // ------------------------------------------------------------------ B. cabeceras de proxy

    #[Test]
    public function a_forged_x_forwarded_host_from_an_untrusted_client_does_not_reach_the_reset_link(): void
    {
        $a = $this->account();
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $request = Request::create('https://'.$appHost.'/admin/password-reset/request', 'GET', [], [], [], [
            'REMOTE_ADDR' => '172.18.0.1',
            'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ]);

        $url = null;
        $this->app->make(TrustProxies::class)->handle($request, function (Request $r) use ($a, &$url) {
            $this->app->instance('request', $r);
            $url = Filament::getPanel('admin')->getResetPasswordUrl('synthetic-token', $a);

            return new Response('');
        });
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame($appHost, parse_url($url, PHP_URL_HOST));
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertNull(parse_url($url, PHP_URL_PORT));
    }

    // ------------------------------------------------------------------ D. verificación de correo

    #[Test]
    public function a_verification_link_only_verifies_its_own_account_and_dies_when_the_email_changes(): void
    {
        $owner = User::factory()->create(['email_verified_at' => null]);
        $other = User::factory()->create(['email_verified_at' => null]);
        $owner->assignRole(Role::findOrCreate('afiliados', 'web'));
        $other->assignRole(Role::findOrCreate('afiliados', 'web'));
        $link = Filament::getPanel('admin')->getVerifyEmailUrl($owner);

        // Otra cuenta abre el enlace del titular: no verifica a nadie.
        $this->actingAs($other)->get($link)->assertForbidden();
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
        auth()->logout();

        // El titular cambia de correo: el enlace del correo anterior ya no verifica el nuevo.
        $owner->forceFill(['email' => 'nuevo'.random_int(10000, 99999).'@example.test'])->save();
        $this->actingAs($owner->fresh())->get($link)->assertForbidden();
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    #[Test]
    public function the_recovery_and_reset_routes_keep_csrf_session_checks_and_the_signature(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['filament.admin.auth.login', 'filament.admin.auth.password-reset.request',
            'filament.admin.auth.password-reset.reset', 'filament.admin.auth.account-recovery.rif'] as $name) {
            $mw = $routes->getByName($name)->gatherMiddleware();
            $this->assertContains(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, $mw, $name);
            $this->assertContains(\Filament\Http\Middleware\AuthenticateSession::class, $mw, $name);
        }
        $this->assertContains('signed', $routes->getByName('filament.admin.auth.password-reset.reset')->gatherMiddleware());
        $this->assertSame([], (fn () => $this->except)->call(app(\App\Http\Middleware\VerifyCsrfToken::class)),
            'sin excepciones de CSRF en la aplicación');
    }

    #[Test]
    public function a_failed_send_logs_only_the_exception_class_never_the_link(): void
    {
        $a = $this->account();
        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context);
        });
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class implements \Illuminate\Contracts\Notifications\Dispatcher
        {
            public function send($notifiables, $notification)
            {
                throw new \RuntimeException('smtp down; url='.$notification->url);
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                $this->send($notifiables, $notification);
            }
        });

        AccountRecovery::defer(fn () => AccountRecovery::sendByEmail($a->email));
        $this->app->terminate();

        $this->assertNotEmpty($logged, 'el fallo queda registrado');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('token=', $line);
            $this->assertStringNotContainsString('password-reset', $line);
            $this->assertStringNotContainsString($a->email, $line);
        }
    }
}
