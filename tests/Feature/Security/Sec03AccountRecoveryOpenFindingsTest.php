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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0017 / SEC-03 (Issue #2 `6797800863`): oráculos de los hallazgos ABIERTOS.
 *
 * Cada prueba describe el comportamiento SEGURO y por eso FALLA en `b048df0`: el fallo es la evidencia reproducible del
 * hallazgo (ID en el nombre del método y en el informe `audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md`). Pasarán
 * cuando una corrección autorizada lo resuelva; no se implementa ninguna aquí.
 *
 * Alcance de la evidencia: la aplicación aislada en el runner. Si un hallazgo es explotable a través del borde público
 * (Cloudflare + túnel) se discute en el informe; estas pruebas no envían ninguna petición a staging.
 */
#[Group('sec03')]
#[Group('sec03-open-findings')]
class Sec03AccountRecoveryOpenFindingsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const FORGED_HOST = 'attacker.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['mail.default' => 'array']);
        $this->captcha(true);
        Notification::fake();
        Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        Role::findOrCreate('afiliados', 'web');
    }

    private function captcha(bool $passes): void
    {
        $this->app->instance(Turnstile::class, new class($passes) extends Turnstile
        {
            public function __construct(private bool $passes) {}

            public function enabled(): bool
            {
                return true;
            }

            public function verify(?string $token, ?string $ip = null): bool
            {
                return $this->passes;
            }
        });
    }

    private function account(?string $email = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => Hash::make('Original-clave-1')]
            + ($email === null ? [] : ['email' => $email]));
        $user->assignRole(Role::findOrCreate('afiliados', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function appHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** Corre $work con la petición que vería la aplicación detrás de nginx: REMOTE_ADDR del gateway y TrustProxies real. */
    private function behindTheProxy(array $server, \Closure $work): mixed
    {
        $host = $server['HTTP_HOST'] ?? $this->appHost();
        $request = Request::create('https://'.$host.'/admin/password-reset/request', 'GET', [], [], [], $server + ['REMOTE_ADDR' => '172.18.0.1']);
        $result = null;
        $this->app->make(TrustProxies::class)->handle($request, function (Request $r) use ($work, &$result) {
            $this->app->instance('request', $r);
            $result = $work($r);

            return new Response('');
        });
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        return $result;
    }

    private function sentUrl(User $user): ?string
    {
        $url = null;
        Notification::assertSentTo($user, AccountRecoveryLink::class, function (AccountRecoveryLink $n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return $url;
    }

    // ------------------------------------------------------------------ F-SEC03-01: host de los enlaces

    #[Test]
    public function f_sec03_01_the_reset_link_keeps_the_configured_host_when_the_request_carries_another_host(): void
    {
        $a = $this->account();
        $this->behindTheProxy(['HTTP_HOST' => self::FORGED_HOST], fn () => AccountRecovery::sendByEmail($a->email));

        $this->assertSame($this->appHost(), parse_url((string) $this->sentUrl($a), PHP_URL_HOST),
            'el enlace de recuperación debe usar solo el host permitido, nunca el de la petición');
    }

    #[Test]
    public function f_sec03_01_the_reset_link_from_the_real_page_keeps_the_configured_host(): void
    {
        $a = $this->account();
        Livewire::withHeaders(['Host' => self::FORGED_HOST])
            ->test(RequestPasswordReset::class)->set('data.email', $a->email)->call('request');
        $this->app->terminate();

        $this->assertSame($this->appHost(), parse_url((string) $this->sentUrl($a), PHP_URL_HOST));
    }

    #[Test]
    public function f_sec03_01_the_email_verification_link_keeps_the_configured_host(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $url = $this->behindTheProxy(['HTTP_HOST' => self::FORGED_HOST], fn () => Filament::getPanel('admin')->getVerifyEmailUrl($user));

        $this->assertSame($this->appHost(), parse_url($url, PHP_URL_HOST));
    }

    #[Test]
    public function f_sec03_02_a_request_for_a_host_that_is_not_the_application_is_refused(): void
    {
        $status = $this->get('http://'.self::FORGED_HOST.'/admin/password-reset/request')->getStatusCode();

        $this->assertContains($status, [400, 404, 421], "un host no permitido debe rechazarse (TrustHosts o dominio del panel); respondió {$status}");
    }

    // ------------------------------------------------------------------ F-SEC03-03: IP del cliente detrás del proxy

    #[Test]
    public function f_sec03_03_two_clients_behind_the_tunnel_are_seen_as_two_different_ips(): void
    {
        $ip = fn (string $client) => $this->behindTheProxy(
            ['HTTP_X_FORWARDED_FOR' => $client, 'HTTP_CF_CONNECTING_IP' => $client],
            fn (Request $r) => $r->ip(),
        );

        $this->assertNotSame($ip('203.0.113.10'), $ip('198.51.100.20'),
            'los límites «por IP» (recuperación, login, reset) deben separar a los clientes reales, no al gateway');
    }

    // ------------------------------------------------------------------ F-SEC03-04: cupo consumido sin Turnstile

    #[Test]
    public function f_sec03_04_requests_that_fail_turnstile_do_not_use_up_the_holders_email_quota(): void
    {
        $victim = $this->account();

        $this->captcha(false);
        foreach (range(1, 3) as $_) {
            Livewire::test(RequestPasswordReset::class)->set('data.email', $victim->email)->call('request');
        }
        $this->app->terminate();
        Notification::assertNothingSent();

        $this->captcha(true);
        Livewire::test(RequestPasswordReset::class)->set('data.email', $victim->email)->call('request');
        $this->app->terminate();

        Notification::assertSentTo($victim, AccountRecoveryLink::class);
    }

    #[Test]
    public function f_sec03_04_requests_that_fail_turnstile_do_not_use_up_the_rif_quota(): void
    {
        $holder = $this->account();
        $this->actingAs(User::factory()->create());
        $rif = 'J'.random_int(100000000, 999999999);
        $empresa = Empresa::create(['rif' => $rif, 'name' => 'ZZZSEC03EMPRESA'.random_int(1000, 9999), 'phone' => '+58-212-5550000',
            'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $holder->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->captcha(false);
        foreach (range(1, 3) as $_) {
            Livewire::test(RecoverAccessByRif::class)->set('data.rif', $rif)->call('request');
        }
        $this->captcha(true);
        Livewire::test(RecoverAccessByRif::class)->set('data.rif', $rif)->call('request');
        $this->app->terminate();

        Notification::assertSentTo($holder, AccountRecoveryLink::class);
    }

    // ------------------------------------------------------------------ F-SEC03-05: cambio de correo

    #[Test]
    public function f_sec03_05_changing_the_email_drops_its_verification_and_its_eligibility_by_rif(): void
    {
        $user = $this->account();
        $this->actingAs(User::factory()->create());
        $rif = 'J'.random_int(100000000, 999999999);
        $empresa = Empresa::create(['rif' => $rif, 'name' => 'ZZZSEC03EMPRESA'.random_int(1000, 9999), 'phone' => '+58-212-5550000',
            'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(1, AccountRecovery::eligibleForRif($rif), 'precondición: elegible con el correo verificado');

        // El único escritor de correo de cuentas es UserResource (form de Filament → update del modelo).
        $user->update(['email' => 'cambiado'.random_int(10000, 99999).'@example.test']);

        $this->assertNull($user->fresh()->email_verified_at, 'un buzón nuevo no debe heredar la verificación del anterior');
        $this->assertCount(0, AccountRecovery::eligibleForRif($rif), 'la ayuda por RIF no debe escribir a un buzón sin verificar');
    }
}
