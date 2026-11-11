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
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0018 / SEC-03 (Issue #2 `6802857575`): correcciones P1–P5.
 *
 * Los oráculos de TASK-0017 (Sec03AccountRecoveryOpenFindingsTest) siguen sin cambios y pasan a verde con estas
 * correcciones; los controles (Sec03AccountRecoveryControlsTest) siguen verdes. Esta clase cubre los bordes de cada
 * corrección y lo que no debe romperse: hosts legítimos, pares no confiables, Turnstile y límites, correo sin cambio,
 * HSTS y redirección sin bucles. Ninguna prueba sale del proceso: sin staging, sin correo real, sin cabeceras a nadie.
 */
#[Group('sec03')]
#[Group('sec03-remediation')]
class Sec03RemediationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const GATEWAY = '172.18.0.1';

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

    private function account(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => Hash::make('Original-clave-1')]);
        $user->assignRole(Role::findOrCreate('afiliados', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function appHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** La IP que la aplicación ve para una petición que llega desde $peer con $server (TrustProxies real). */
    private function ipSeenFrom(string $peer, array $server = []): string
    {
        $request = Request::create('https://'.$this->appHost().'/admin/login', 'GET', [], [], [], $server + ['REMOTE_ADDR' => $peer]);
        $ip = '';
        $this->app->make(TrustProxies::class)->handle($request, function (Request $r) use (&$ip) {
            $ip = (string) $r->ip();

            return new Response('');
        });
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        return $ip;
    }

    private function company(string $rif, User $holder): void
    {
        $this->actingAs(User::factory()->create());
        $empresa = Empresa::create(['rif' => $rif, 'name' => 'ZZZSEC03EMPRESA'.random_int(1000, 9999), 'phone' => '+58-212-5550000',
            'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $holder->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ P1: origen canónico y hosts

    #[Test]
    public function p1_the_application_host_is_still_served(): void
    {
        $this->get('http://'.$this->appHost().'/admin/login')->assertOk();
        $this->get('http://'.strtoupper($this->appHost()).'/admin/login')->assertOk();
    }

    #[Test]
    public function p1_an_extra_trusted_host_is_served_and_a_lookalike_subdomain_is_not(): void
    {
        config(['security.trusted_hosts.extra' => 'alias.example.test']);

        $this->get('http://alias.example.test/admin/login')->assertOk();
        $this->assertContains($this->get('http://evil.'.$this->appHost().'/admin/login')->getStatusCode(), [400, 404, 421],
            'sin subdominios: solo hosts exactos');
        $this->assertContains($this->get('http://'.$this->appHost().'.evil.test/admin/login')->getStatusCode(), [400, 404, 421]);
    }

    #[Test]
    public function p1_an_https_app_url_gives_https_links_even_from_a_plain_http_request(): void
    {
        config(['app.url' => 'https://perfil.example.test']);
        $user = User::factory()->create(['email_verified_at' => null]);

        $request = Request::create('http://attacker.invalid/admin/login', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.5']);
        $this->app->instance('request', $request);

        foreach ([
            Filament::getPanel('admin')->getResetPasswordUrl('synthetic-token', $user),
            Filament::getPanel('admin')->getVerifyEmailUrl($user),
            route('filament.admin.auth.login'),
            url('/admin'),
        ] as $url) {
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME), $url);
            $this->assertSame('perfil.example.test', parse_url($url, PHP_URL_HOST), $url);
        }
    }

    #[Test]
    public function p1_a_signed_link_built_with_the_canonical_host_is_still_accepted(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->assignRole(Role::findOrCreate('afiliados', 'web'));

        $this->actingAs($user)->get(Filament::getPanel('admin')->getVerifyEmailUrl($user))->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at, 'la firma se valida con el mismo host que la generó');
    }

    // ------------------------------------------------------------------ P2: IP real

    #[Test]
    public function p2_the_edge_header_is_taken_only_from_a_trusted_peer_and_overrides_a_forged_forwarded_for(): void
    {
        $this->assertSame('203.0.113.10', $this->ipSeenFrom(self::GATEWAY, [
            'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
        ]), 'lo que el cliente ponga en X-Forwarded-For no cuenta: manda la cabecera del borde');

        $this->assertSame('198.51.100.7', $this->ipSeenFrom('198.51.100.7', [
            'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ]), 'desde un par que no es de confianza no se acepta ninguna IP reenviada');
    }

    #[Test]
    public function p2_without_a_valid_edge_header_the_peer_ip_is_kept(): void
    {
        $this->assertSame(self::GATEWAY, $this->ipSeenFrom(self::GATEWAY, ['HTTP_X_FORWARDED_FOR' => '198.51.100.99']));
        $this->assertSame(self::GATEWAY, $this->ipSeenFrom(self::GATEWAY, ['HTTP_CF_CONNECTING_IP' => 'not-an-ip']));
    }

    #[Test]
    public function p2_trust_can_be_turned_off_and_a_wildcard_is_never_accepted(): void
    {
        foreach (['none', '*', '**', ''] as $value) {
            config(['security.proxies.trusted' => $value]);
            $this->assertSame(self::GATEWAY, $this->ipSeenFrom(self::GATEWAY, ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']),
                "TRUSTED_PROXIES={$value} no debe confiar en nadie");
        }
    }

    #[Test]
    public function p2_the_forwarded_host_is_dropped_even_from_a_trusted_peer(): void
    {
        $request = Request::create('https://'.$this->appHost().'/admin/login', 'GET', [], [], [], [
            'REMOTE_ADDR' => self::GATEWAY,
            'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
        ]);
        $seen = null;
        $this->app->make(TrustProxies::class)->handle($request, function (Request $r) use (&$seen) {
            $seen = [$r->getHost(), $r->headers->has('X-Forwarded-Host')];

            return new Response('');
        });
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame([$this->appHost(), false], $seen);
    }

    #[Test]
    public function p2_a_real_request_through_the_kernel_gets_the_client_ip(): void
    {
        $seen = null;
        \Illuminate\Support\Facades\Route::get('/__sec03_ip_probe', function (Request $r) use (&$seen) {
            $seen = $r->ip();

            return 'ok';
        });

        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])
            ->withHeaders(['CF-Connecting-IP' => '203.0.113.44'])
            ->get('/__sec03_ip_probe')->assertOk();

        $this->assertSame('203.0.113.44', $seen);
    }

    // ------------------------------------------------------------------ P3: Turnstile antes de los cupos

    #[Test]
    public function p3_failed_turnstile_still_counts_against_the_origin_limit(): void
    {
        $victim = $this->account();

        $this->captcha(false);
        foreach (range(1, 5) as $_) {
            Livewire::test(RequestPasswordReset::class)->set('data.email', $victim->email)->call('request')
                ->assertHasErrors('ts_token');
        }
        $this->captcha(true);
        Livewire::test(RequestPasswordReset::class)->set('data.email', $victim->email)->call('request')
            ->assertNotified(RequestPasswordReset::THROTTLED_TITLE);
        $this->app->terminate();

        Notification::assertNothingSent();
    }

    #[Test]
    public function p3_the_holders_quota_still_limits_valid_requests_with_the_same_answer(): void
    {
        $holder = $this->account();

        foreach (range(1, 3) as $_) {
            Livewire::test(RequestPasswordReset::class)->set('data.email', $holder->email)->call('request')
                ->assertNotified(RequestPasswordReset::SENT_TITLE);
        }
        Livewire::test(RequestPasswordReset::class)->set('data.email', $holder->email)->call('request')
            ->assertNotified(RequestPasswordReset::THROTTLED_TITLE);

        $rif = 'J'.random_int(100000000, 999999999);
        $this->company($rif, $this->account());
        foreach (range(1, 3) as $_) {
            Livewire::test(RecoverAccessByRif::class)->set('data.rif', $rif)->call('request')
                ->assertNotified(RecoverAccessByRif::SENT_TITLE);
        }
        // Mismo origen (Livewire usa 127.0.0.1): el 4.º pedido de esta IP en la pantalla RIF sigue dentro del cupo de
        // origen (5 en 15 min), así que lo que frena es el cupo del RIF (3 por hora).
        Livewire::test(RecoverAccessByRif::class)->set('data.rif', $rif)->call('request')
            ->assertNotified(RecoverAccessByRif::THROTTLED_TITLE);
    }

    // ------------------------------------------------------------------ P4: cambio de correo

    #[Test]
    public function p4_a_real_email_change_drops_verification_even_if_the_edit_sets_it_and_removes_old_reset_tokens(): void
    {
        $user = $this->account();
        $oldEmail = $user->email;
        Password::broker()->createToken($user);
        $this->assertSame(1, DB::table('password_resets')->where('email', $oldEmail)->count(), 'precondición: token emitido');

        $user->update(['email' => 'nuevo'.random_int(10000, 99999).'@example.test', 'email_verified_at' => now()]);

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, DB::table('password_resets')->where('email', $oldEmail)->count(), 'el token del correo anterior ya no sirve');
    }

    #[Test]
    public function p4_the_same_mailbox_keeps_its_verification(): void
    {
        $user = $this->account();
        $verifiedAt = $user->email_verified_at;

        $user->update(['email' => '  '.strtoupper($user->email).' ']);
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at, 'mayúsculas o espacios no son otro buzón');

        $user->update(['name' => 'Otro nombre', 'phone' => '+58-212-5550001']);
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
    }

    #[Test]
    public function p4_an_unverified_new_mailbox_is_asked_to_verify_at_the_next_login(): void
    {
        $user = $this->account();
        $user->update(['email' => 'nuevo'.random_int(10000, 99999).'@example.test']);

        $this->actingAs($user->fresh())->get('/admin')->assertRedirect(Filament::getPanel('admin')->getEmailVerificationPromptUrl());
    }

    // ------------------------------------------------------------------ P5: HSTS y redirección

    #[Test]
    public function p5_hsts_is_sent_over_https_for_one_day_without_subdomains_or_preload(): void
    {
        $this->get('https://'.$this->appHost().'/admin/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=86400');

        $this->get('http://'.$this->appHost().'/admin/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        config(['security.https.hsts_max_age' => 0]);
        $this->get('https://'.$this->appHost().'/admin/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function p5_the_redirect_is_off_by_default_even_when_the_edge_says_http(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])
            ->withHeaders(['X-Forwarded-Proto' => 'http'])
            ->get('https://'.$this->appHost().'/admin/login')
            ->assertOk();
    }

    #[Test]
    public function p5_when_enabled_only_a_trusted_http_hop_is_redirected_to_the_canonical_https_host_and_nothing_loops(): void
    {
        config(['security.https.redirect' => true]);
        $host = $this->appHost();

        // Borde en http, por un proxy de confianza: 301 al host canónico (nunca al Host de la petición).
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'http'])
            ->get('http://'.$host.'/admin/login?x=1')
            ->assertStatus(301)->assertRedirect('https://'.$host.'/admin/login?x=1');
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'http'])
            ->post('http://'.$host.'/admin/login')->assertStatus(308);

        // Borde en https, sin cabecera, o cabecera de un cliente no confiable: no hay redirección (no hay bucle).
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://'.$host.'/admin/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])
            ->get('https://'.$host.'/admin/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeaders(['X-Forwarded-Proto' => 'http'])
            ->get('http://'.$host.'/admin/login')->assertOk();
    }
}
