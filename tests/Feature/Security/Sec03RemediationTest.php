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

    // ================================================================== Revisión 6819392897 (P2, P4, P5)

    /** Petición desde $peer pasada por TrustProxies real; $probe recibe la petición tal como la ve la aplicación. */
    private function seenFrom(string $peer, array $server, \Closure $probe, ?string $url = null): mixed
    {
        $request = Request::create($url ?? 'https://'.$this->appHost().'/admin/login', 'GET', [], [], [], $server + ['REMOTE_ADDR' => $peer]);
        $result = null;
        $this->app->make(TrustProxies::class)->handle($request, function (Request $r) use ($probe, &$result) {
            $this->app->instance('request', $r);
            $result = $probe($r);

            return new Response('');
        });
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        return $result;
    }

    #[Test]
    public function review_p2_by_default_only_the_exact_gateway_is_trusted_not_other_docker_networks_or_containers(): void
    {
        // Otras redes bridge del mismo host (otros sitios) y otro contenedor de la red de la aplicación.
        foreach (['172.17.0.1', '172.19.0.2', '172.20.0.1', '172.18.0.3', '10.0.0.5', '192.168.1.10'] as $peer) {
            $this->assertSame($peer, $this->ipSeenFrom($peer, ['HTTP_CF_CONNECTING_IP' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10']),
                "{$peer} no es el gateway: su cabecera no debe fijar la IP");
        }
        $this->assertSame('203.0.113.10', $this->ipSeenFrom(self::GATEWAY, ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']));
        $this->assertSame('203.0.113.11', $this->ipSeenFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '203.0.113.11']));
    }

    #[Test]
    public function review_p2_a_list_blank_or_malformed_edge_header_falls_back_to_the_peer_and_ipv6_is_accepted(): void
    {
        foreach (['203.0.113.1, 198.51.100.2', ' ', '203.0.113.1:443', '999.1.1.1', '<script>'] as $value) {
            $this->assertSame(self::GATEWAY, $this->ipSeenFrom(self::GATEWAY, ['HTTP_CF_CONNECTING_IP' => $value, 'HTTP_X_FORWARDED_FOR' => '198.51.100.99']),
                "CF-Connecting-IP «{$value}» no es una IP: se queda la del par y se descarta el X-Forwarded-For del cliente");
        }
        $this->assertSame('2001:db8::1', $this->ipSeenFrom(self::GATEWAY, ['HTTP_CF_CONNECTING_IP' => '2001:db8::1']));
    }

    #[Test]
    public function review_p2_without_an_edge_header_setting_the_standard_forwarded_for_is_read_right_to_left_only_from_trusted_peers(): void
    {
        config(['security.proxies.client_ip_header' => '']);

        $this->assertSame('203.0.113.77', $this->ipSeenFrom(self::GATEWAY, ['HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.77']),
            'lo que el cliente antepone queda a la izquierda; cuenta el último salto antes del proxy de confianza');
        $this->assertSame('198.51.100.7', $this->ipSeenFrom('198.51.100.7', ['HTTP_X_FORWARDED_FOR' => '203.0.113.77']));
    }

    #[Test]
    public function review_p2_proto_port_prefix_host_and_rfc7239_forwarded_from_a_trusted_peer_change_nothing_while_the_redirect_is_off(): void
    {
        $user = $this->account();
        $seen = $this->seenFrom(self::GATEWAY, [
            'HTTP_CF_CONNECTING_IP' => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_PREFIX' => '/evil',
            'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
            'HTTP_FORWARDED' => 'for=198.51.100.1;host=attacker.invalid;proto=http',
        ], fn (Request $r) => [
            $r->ip(), $r->isSecure(), $r->getHost(), $r->getPort(), $r->getBaseUrl(),
            Filament::getPanel('admin')->getResetPasswordUrl('synthetic-token', $user),
        ]);

        [$ip, $secure, $host, $port, $base, $link] = $seen;
        $this->assertSame(['203.0.113.20', true, $this->appHost(), 443, ''], [$ip, $secure, $host, $port, $base]);
        $this->assertSame($this->appHost(), parse_url($link, PHP_URL_HOST));
        $this->assertNull(parse_url($link, PHP_URL_PORT));
        $this->assertStringNotContainsString('/evil', $link);
    }

    #[Test]
    public function review_p2_from_an_untrusted_peer_no_forwarded_header_changes_ip_scheme_host_or_links_even_with_the_redirect_on(): void
    {
        config(['security.https.redirect' => true]);
        $user = $this->account();
        [$ip, $secure, $host, $link] = $this->seenFrom('198.51.100.7', [
            'HTTP_CF_CONNECTING_IP' => '203.0.113.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
        ], fn (Request $r) => [$r->ip(), $r->isSecure(), $r->getHost(), Filament::getPanel('admin')->getResetPasswordUrl('synthetic-token', $user)]);

        $this->assertSame(['198.51.100.7', true, $this->appHost()], [$ip, $secure, $host]);
        $this->assertSame($this->appHost(), parse_url($link, PHP_URL_HOST));
    }

    #[Test]
    public function review_p2_two_clients_behind_the_gateway_no_longer_share_the_origin_quota(): void
    {
        $limits = config('account_recovery.limits');
        $key = fn (string $client) => 'account-recovery:email:ip:'.$this->ipSeenFrom(self::GATEWAY, ['HTTP_CF_CONNECTING_IP' => $client]);

        foreach (range(1, $limits['per_ip'][0]) as $_) {
            $this->assertTrue(AccountRecovery::attempt([$key('203.0.113.30') => $limits['per_ip']]));
        }
        $this->assertFalse(AccountRecovery::attempt([$key('203.0.113.30') => $limits['per_ip']]), 'el cliente A agotó su cupo');
        $this->assertTrue(AccountRecovery::attempt([$key('203.0.113.31') => $limits['per_ip']]), 'el cliente B no queda bloqueado por A');
    }

    #[Test]
    public function review_p4_a_failed_token_cleanup_leaves_the_account_unchanged(): void
    {
        $user = $this->account();
        $oldEmail = $user->email;
        $verifiedAt = $user->email_verified_at;
        Password::broker()->createToken($user);

        // Falla el borrado de tokens sin abortar la transacción de PostgreSQL (se lanza antes de ejecutarlo).
        DB::connection()->beforeExecuting(function (string $sql) {
            if (str_starts_with(strtolower(ltrim($sql)), 'delete') && str_contains($sql, 'password_resets')) {
                throw new \RuntimeException('simulated token cleanup failure');
            }
        });

        try {
            $user->update(['email' => 'nuevo'.random_int(10000, 99999).'@example.test']);
            $this->fail('el fallo del borrado debe cortar el guardado');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated token cleanup failure', $e->getMessage());
        }

        $fresh = User::query()->find($user->id);
        $this->assertSame($oldEmail, $fresh->email, 'sin tokens borrados no se cambia el correo');
        $this->assertEquals($verifiedAt, $fresh->email_verified_at);
        $this->assertSame(1, DB::table('password_resets')->where('email', $oldEmail)->count());
    }

    #[Test]
    public function review_p4_a_rolled_back_change_restores_email_verification_and_tokens_together(): void
    {
        $user = $this->account();
        $oldEmail = $user->email;
        Password::broker()->createToken($user);

        DB::beginTransaction();
        $user->update(['email' => 'nuevo'.random_int(10000, 99999).'@example.test']);
        $this->assertNull(User::query()->find($user->id)->email_verified_at);
        $this->assertSame(0, DB::table('password_resets')->where('email', $oldEmail)->count());
        DB::rollBack();

        $fresh = User::query()->find($user->id);
        $this->assertSame($oldEmail, $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame(1, DB::table('password_resets')->where('email', $oldEmail)->count());
    }

    #[Test]
    public function review_p4_a_reset_token_issued_before_the_change_works_neither_with_the_old_nor_the_new_email(): void
    {
        $user = $this->account();
        $oldEmail = $user->email;
        $token = Password::broker()->createToken($user);
        $newEmail = 'nuevo'.random_int(10000, 99999).'@example.test';
        $user->update(['email' => $newEmail]);

        $reset = fn (string $email) => Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => 'Nueva-clave-2026', 'password_confirmation' => 'Nueva-clave-2026'],
            fn (User $u, string $password) => $u->forceFill(['password' => Hash::make($password)])->save(),
        );

        $this->assertSame(Password::INVALID_USER, $reset($oldEmail));
        $this->assertSame(Password::INVALID_TOKEN, $reset($newEmail));
        $this->assertTrue(Hash::check('Original-clave-1', User::query()->find($user->id)->password));
    }

    #[Test]
    public function review_p4_the_email_change_through_the_user_admin_form_drops_verification(): void
    {
        // Mismo patrón que AccountWriteAtomicityTest: un gestor con los permisos de Usuarios, sin tocar roles.
        $manager = User::factory()->create(['email_verified_at' => now()]);
        foreach (['view_any_user', 'view_user', 'update_user'] as $p) {
            $manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($p, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $this->account();

        Livewire::actingAs($manager->fresh())->test(\App\Filament\Resources\UserResource\Pages\EditUser::class, ['record' => $user->getKey()])
            ->set('data.email', 'nuevo'.random_int(10000, 99999).'@example.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->email_verified_at, 'el escritor real (UserResource) pasa por el evento del modelo');
    }

    #[Test]
    public function review_p5_the_redirect_keeps_path_and_query_and_uses_301_for_get_and_head_and_308_for_the_rest(): void
    {
        config(['security.https.redirect' => true]);
        $host = $this->appHost();
        $target = 'https://'.$host.'/admin/login?a=1&b=%C3%B1';
        // call() no aplica withHeaders() (solo get()/post()/…): la cabecera va como variable de servidor.
        $send = fn (string $method) => $this->call($method, 'http://'.$host.'/admin/login?a=1&b=%C3%B1', [], [], [],
            ['REMOTE_ADDR' => self::GATEWAY, 'HTTP_X_FORWARDED_PROTO' => 'http']);

        foreach (['GET' => 301, 'HEAD' => 301, 'POST' => 308, 'PUT' => 308, 'PATCH' => 308, 'DELETE' => 308] as $method => $status) {
            $response = $send($method);
            $this->assertSame($status, $response->getStatusCode(), $method);
            $this->assertSame($target, $response->headers->get('Location'), $method);
            $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'HSTS no va en la respuesta http');
        }
    }

    #[Test]
    public function review_p5_the_redirect_target_is_the_canonical_host_even_for_another_trusted_host(): void
    {
        config(['security.https.redirect' => true, 'security.trusted_hosts.extra' => 'alias.example.test']);

        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'http', 'X-Forwarded-Host' => 'attacker.invalid'])
            ->get('http://alias.example.test/admin/login')
            ->assertStatus(301)->assertRedirect('https://'.$this->appHost().'/admin/login');
    }

    #[Test]
    public function review_p5_following_the_redirect_ends_in_https_with_hsts_and_ambiguous_or_untrusted_proto_never_loops(): void
    {
        config(['security.https.redirect' => true]);
        $host = $this->appHost();

        $location = $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'http'])
            ->get('http://'.$host.'/admin/login')->headers->get('Location');
        $this->flushHeaders();
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get($location)->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=86400');

        // Lista ambigua: no se redirige (solo «http» exacto) y no hay bucle.
        $this->flushHeaders();
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])->withHeaders(['X-Forwarded-Proto' => 'http, https'])
            ->get('http://'.$host.'/admin/login')->assertOk();

        // Un cliente no confiable que dice «https» sobre http no vuelve segura la petición: sin HSTS.
        $this->flushHeaders();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://'.$host.'/admin/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function review_p5_hsts_flags_appear_only_when_explicitly_enabled(): void
    {
        config(['security.https.hsts_include_subdomains' => true, 'security.https.hsts_preload' => true, 'security.https.hsts_max_age' => 300]);

        $this->get('https://'.$this->appHost().'/admin/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=300; includeSubDomains; preload');
    }
}
