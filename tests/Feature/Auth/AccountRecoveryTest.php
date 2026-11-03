<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\RecoverAccessByRif;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Login;
use App\Models\Empresa;
use App\Models\User;
use App\Notifications\Auth\AccountRecoveryLink;
use App\Support\Turnstile;
use Filament\Facades\Filament;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0012 (Issue #2 `6602168914`): recuperación de acceso por correo y por RIF.
 *
 * Reglas escritas como literales (ver el archivo de la tarea, R1–R6):
 * - R1: por correo, el titular recibe su enlace; la pantalla responde lo mismo exista o no la cuenta (también con límite);
 *   la búsqueda no distingue mayúsculas; el envío ocurre después de responder; el token es de un solo uso, vence a los
 *   60 minutos y solo sirve para la cuenta a la que se envió;
 * - R2/R3: por RIF, cada buzón afiliado elegible recibe su propio enlace, sin repetir buzones; quedan fuera las cuentas
 *   sin verificar, las internas (super_admin, sin rol, con otro rol o permisos directos) y las vinculadas a más de 3
 *   empresas; si hay más buzones que el tope, no se envía nada; la pantalla nunca lista ni cuenta;
 * - R4: con transporte `log` fuera de local/testing no se envía el enlace;
 * - R5: el login acepta el correo con otras mayúsculas y sigue exigiendo la contraseña.
 *
 * Dobles de prueba (declarados): Turnstile se reemplaza por un doble que responde sí o no; las notificaciones van a
 * `Notification::fake()` (sin SMTP). El envío diferido corre en el terminate de la aplicación, como después de responder
 * en PHP-FPM; cada petición de prueba de Livewire pasa por el kernel HTTP, que lo llama (`runDeferred` lo repite, sin
 * efecto, para que se lea en la prueba). Usuarios y empresas sintéticos, en la PostgreSQL desechable del runner.
 */
class AccountRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['mail.default' => 'array']);
        $this->captcha(true);
        Notification::fake();
        // Empresa::boot() vincula a cada empresa nueva las cuentas con role_id = 1. Si el id 1 está libre, que sea
        // super_admin, como en staging, y no el rol de afiliado.
        Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        Role::findOrCreate('afiliados', 'web');
    }

    // ------------------------------------------------------------------ fixtures

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

    private function account(string $role = 'afiliados', bool $verified = true, ?string $email = null): User
    {
        $user = User::factory()->create(['email_verified_at' => $verified ? now() : null, 'password' => Hash::make('Original-clave-1')]
            + ($email === null ? [] : ['email' => $email]));
        if ($role !== '') {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(string $rif, User ...$users): Empresa
    {
        $creator = $this->account('super_admin');
        $this->actingAs($creator);
        $empresa = Empresa::create(['rif' => $rif, 'name' => 'ZZZRECEMPRESA'.random_int(1000, 9999), 'phone' => '+58-212-5550000',
            'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();
        foreach ($users as $user) {
            $this->link($empresa, $user);
        }

        return $empresa;
    }

    private function link(Empresa $empresa, User $user): void
    {
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function rif(): string
    {
        return 'J'.random_int(100000000, 999999999);
    }

    private function requestByEmail(string $email)
    {
        return Livewire::test(RequestPasswordReset::class)->set('data.email', $email)->call('request');
    }

    private function requestByRif(string $rif)
    {
        return Livewire::test(RecoverAccessByRif::class)->set('data.rif', $rif)->call('request');
    }

    /** Lo que la persona ve después de pedir: título y cuerpo de la notificación de Filament. */
    private function shown(): array
    {
        return array_map(fn ($n) => [$n['title'] ?? null, $n['body'] ?? null], session()->pull('filament.notifications', []));
    }

    private function runDeferred(): void
    {
        $this->app->terminate();
    }

    private function linkFor(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, AccountRecoveryLink::class, function (AccountRecoveryLink $n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return $url;
    }

    /** @return array{0: string, 1: string} email y token del enlace firmado */
    private function tokenFrom(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return [$q['email'], $q['token']];
    }

    private function reset(string $email, string $token, string $password = 'Nueva-clave-2026')
    {
        return Livewire::withQueryParams(['email' => $email, 'token' => $token])
            ->test(ResetPassword::class)
            ->set('password', $password)
            ->set('passwordConfirmation', $password)
            ->call('resetPassword');
    }

    // ------------------------------------------------------------------ superficie y textos

    #[Test]
    public function the_login_says_the_email_is_the_username_and_links_both_recovery_paths(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('Su usuario es su correo electrónico')
            ->assertSee('¿Olvidaste tu contraseña?')
            ->assertSee(route('filament.admin.auth.password-reset.request'), false);

        $this->get('/admin/password-reset/request')->assertOk()
            ->assertSee('No recuerdo el correo de acceso')
            ->assertSee(route('filament.admin.auth.account-recovery.rif'), false);

        $this->get('/admin/recuperar-acceso')->assertOk()
            ->assertSee('RIF de la empresa')
            ->assertSee('Cámara');
    }

    #[Test]
    public function the_manual_describes_both_paths_with_the_labels_the_screens_really_show(): void
    {
        $manual = file_get_contents(resource_path('manual/manual_usuario.md'));
        $source = file_get_contents(app_path('Filament/Pages/Auth/RequestPasswordReset.php'))
            .file_get_contents(app_path('Filament/Pages/Auth/RecoverAccessByRif.php'))
            .file_get_contents(app_path('Filament/Pages/Login.php'))
            .file_get_contents(app_path('Notifications/Auth/AccountRecoveryLink.php'));

        foreach (['¿Olvidaste tu contraseña?', 'No recuerdo el correo de acceso', 'Enviar el enlace', 'Enviar la ayuda',
            'RIF de la empresa', 'Recuperar el acceso al Perfil de Afiliados', 'Elegir una contraseña nueva'] as $label) {
            $this->assertStringContainsString($label, $manual, "El manual no cita «{$label}».");
            $this->assertStringContainsString($label, $source, "«{$label}» ya no existe en la pantalla: actualice el manual.");
        }
        $this->assertStringContainsString('{#olvido-correo}', $manual);
        $this->assertStringNotContainsString('no ofrece hoy una opción para recuperar la contraseña', $manual);
    }

    // ------------------------------------------------------------------ R1: por correo

    #[Test]
    public function the_sending_waits_until_after_the_response_and_runs_once(): void
    {
        $runs = 0;
        \App\Support\Auth\AccountRecovery::defer(function () use (&$runs) {
            $runs++;
        });
        $this->assertSame(0, $runs, 'nada se envía mientras se arma la respuesta');

        $this->app->terminate();
        $this->app->terminate();
        $this->assertSame(1, $runs);
    }

    #[Test]
    public function an_existing_and_an_unknown_email_get_the_same_answer_and_only_the_holder_gets_a_link(): void
    {
        $holder = $this->account();

        $this->requestByEmail($holder->email)->assertHasNoErrors();
        $existing = $this->shown();
        $this->runDeferred();
        Notification::assertSentTo($holder, AccountRecoveryLink::class);
        Notification::assertCount(1);

        $this->requestByEmail('nadie-'.random_int(1000, 9999).'@example.test')->assertHasNoErrors();
        $unknown = $this->shown();
        $this->runDeferred();
        Notification::assertCount(1);

        $this->assertNotEmpty($existing);
        $this->assertSame($existing, $unknown, 'la respuesta no revela si la cuenta existe');
        $this->assertSame([[RequestPasswordReset::SENT_TITLE, RequestPasswordReset::SENT_BODY]], $existing);
    }

    #[Test]
    public function the_email_lookup_ignores_case_and_spaces_and_an_unverified_holder_still_gets_the_link(): void
    {
        $mixed = $this->account(email: 'Mixta.Caso'.random_int(1000, 9999).'@Example.test');
        $unverified = $this->account(verified: false);

        // Sin espacios: el campo type=email del navegador ya los quita antes de enviar.
        $this->requestByEmail(mb_strtolower($mixed->email));
        $this->requestByEmail($unverified->email);
        $this->runDeferred();

        Notification::assertSentTo($mixed, AccountRecoveryLink::class);
        Notification::assertSentTo($unverified, AccountRecoveryLink::class);
    }

    #[Test]
    public function a_valid_link_resets_only_its_own_password_once_and_expires(): void
    {
        $holder = $this->account();
        $other = $this->account();

        $this->requestByEmail($holder->email);
        $this->runDeferred();
        [$email, $token] = $this->tokenFrom($this->linkFor($holder));
        $this->assertSame($holder->email, $email);
        $this->get($this->linkFor($holder))->assertOk();

        // El token de una cuenta no cambia la contraseña de otra.
        $this->reset($other->email, $token);
        $this->assertTrue(Hash::check('Original-clave-1', $other->fresh()->password));

        $this->reset($email, $token)->assertHasNoErrors();
        $this->assertTrue(Hash::check('Nueva-clave-2026', $holder->fresh()->password));
        $this->assertNotSame($holder->remember_token, $holder->fresh()->remember_token);

        // Un solo uso.
        $this->reset($email, $token, 'Otra-clave-2026');
        $this->assertTrue(Hash::check('Nueva-clave-2026', $holder->fresh()->password));

        // Vence a los 60 minutos.
        $this->travel(2)->minutes();
        Notification::fake();
        $this->requestByEmail($holder->email);
        $this->runDeferred();
        [, $late] = $this->tokenFrom($this->linkFor($holder));
        $this->travel(61)->minutes();
        $this->reset($email, $late, 'Tardia-clave-2026');
        $this->assertTrue(Hash::check('Nueva-clave-2026', $holder->fresh()->password));
    }

    #[Test]
    public function a_failed_reset_is_explained_in_spanish(): void
    {
        $holder = $this->account();

        $this->reset($holder->email, 'token-inventado');

        $this->assertSame([[__('passwords.token'), null]], $this->shown());
        $this->assertStringContainsString('enlace', __('passwords.token'));
    }

    #[Test]
    public function email_requests_are_limited_per_address_and_per_origin_with_the_same_answer(): void
    {
        $holder = $this->account();

        for ($i = 0; $i < 3; $i++) {
            $this->requestByEmail($holder->email);
            $this->travel(2)->minutes(); // pasa el throttle de 60 s del broker
            $this->runDeferred();
        }
        Notification::assertSentToTimes($holder, AccountRecoveryLink::class, 3);
        $this->shown();

        $this->requestByEmail($holder->email);
        $this->runDeferred();
        Notification::assertSentToTimes($holder, AccountRecoveryLink::class, 3);
        $limitedExisting = $this->shown();

        // Por origen: después de 5 solicitudes en 15 minutos, también para correos que no existen.
        for ($i = 0; $i < 2; $i++) {
            $this->requestByEmail("otro{$i}-".random_int(1000, 9999).'@example.test');
        }
        $this->shown();
        $this->requestByEmail('ultimo-'.random_int(1000, 9999).'@example.test');
        $limitedUnknown = $this->shown();

        $this->assertSame([RequestPasswordReset::THROTTLED_TITLE], array_column($limitedExisting, 0));
        $this->assertSame($limitedExisting, $limitedUnknown, 'el límite no revela si la cuenta existe');
    }

    #[Test]
    public function a_failed_captcha_sends_nothing(): void
    {
        $holder = $this->account();
        $this->captcha(false);

        $this->requestByEmail($holder->email)->assertHasErrors(['ts_token']);
        $this->requestByRif($this->company($this->rif(), $holder)->rif)->assertHasErrors(['ts_token']);
        $this->runDeferred();

        Notification::assertNothingSent();
    }

    #[Test]
    public function with_the_log_transport_outside_local_the_link_is_not_sent(): void
    {
        $holder = $this->account();
        config(['mail.default' => 'log']);
        $this->app['env'] = 'production';
        Log::spy();

        try {
            $this->requestByEmail($holder->email);
            $this->runDeferred();
        } finally {
            $this->app['env'] = 'testing';
        }

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'log') && ! str_contains($m, 'token'));
    }

    // ------------------------------------------------------------------ R2/R3: por RIF

    #[Test]
    public function by_rif_each_eligible_affiliate_mailbox_gets_its_own_link_and_internal_accounts_none(): void
    {
        $a = $this->account();
        $b = $this->account();
        $unverified = $this->account(verified: false);
        $super = $this->account('super_admin');
        $noRole = $this->account('');
        $twoRoles = $this->account();
        $twoRoles->assignRole(Role::findOrCreate('reportes', 'web'));
        $direct = $this->account();
        $direct->givePermissionTo(Permission::findOrCreate('view_any_empresa', 'web'));
        $wide = $this->account();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        for ($i = 0; $i < 3; $i++) {
            $this->company($this->rif(), $wide);
        }
        $empresa = $this->company($this->rif(), $a, $b, $unverified, $super, $noRole, $twoRoles, $direct, $wide);

        $this->requestByRif(strtolower(substr($empresa->rif, 0, 1)).'-'.substr($empresa->rif, 1, 8).'-'.substr($empresa->rif, 9))->assertHasNoErrors();
        $known = $this->shown();
        $this->runDeferred();

        Notification::assertSentToTimes($a, AccountRecoveryLink::class, 1);
        Notification::assertSentToTimes($b, AccountRecoveryLink::class, 1);
        Notification::assertCount(2);
        Notification::assertSentTo($a, AccountRecoveryLink::class, fn (AccountRecoveryLink $n) => $n->viaRif && str_contains($n->url, urlencode($a->email)));

        $this->requestByRif($this->rif());
        $unknown = $this->shown();
        $this->runDeferred();
        Notification::assertCount(2);

        $this->assertSame([[RecoverAccessByRif::SENT_TITLE, RecoverAccessByRif::SENT_BODY]], $known);
        $this->assertSame($known, $unknown, 'la respuesta no revela si el RIF existe ni cuántas cuentas tiene');
        foreach ([$a, $b] as $user) {
            $this->assertStringNotContainsString($user->email, implode(' ', array_merge(...$known)));
        }
    }

    #[Test]
    public function by_rif_a_mailbox_shared_by_two_accounts_is_skipped_and_too_many_mailboxes_send_nothing(): void
    {
        $base = 'dup'.random_int(1000, 9999).'@example.test';
        $upper = $this->account(email: ucfirst($base));
        $lower = $this->account(email: $base);
        $single = $this->account();
        $this->requestByRif($this->company($this->rif(), $upper, $lower, $single)->rif);
        $this->runDeferred();
        Notification::assertNotSentTo([$upper, $lower], AccountRecoveryLink::class);
        Notification::assertSentTo($single, AccountRecoveryLink::class);

        Notification::fake();
        $many = array_map(fn () => $this->account(), range(1, 6));
        $this->requestByRif($this->company($this->rif(), ...$many)->rif);
        $this->runDeferred();
        Notification::assertNothingSent();
    }

    #[Test]
    public function by_rif_the_format_is_validated_and_requests_are_limited_per_rif(): void
    {
        $this->requestByRif('12345')->assertHasErrors(['data.rif']);

        $holder = $this->account();
        $rif = $this->company($this->rif(), $holder)->rif;
        for ($i = 0; $i < 3; $i++) {
            $this->requestByRif($rif);
            $this->travel(2)->minutes();
            $this->runDeferred();
        }
        $this->shown();
        $this->requestByRif($rif);
        $this->runDeferred();

        Notification::assertSentToTimes($holder, AccountRecoveryLink::class, 3);
        $this->assertSame([RecoverAccessByRif::THROTTLED_TITLE], array_column($this->shown(), 0));
    }

    // ------------------------------------------------------------------ R5: login

    #[Test]
    public function the_login_accepts_the_email_in_other_case_and_still_checks_the_password(): void
    {
        $user = $this->account(email: 'Login.Caso'.random_int(1000, 9999).'@Example.test');

        Livewire::test(Login::class)->set('data.email', mb_strtolower($user->email))->set('data.password', 'mala-clave')
            ->call('authenticate')->assertHasErrors(['data.email']);
        $this->assertGuest();

        Livewire::test(Login::class)->set('data.email', mb_strtolower($user->email))->set('data.password', 'Original-clave-1')
            ->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }
}
