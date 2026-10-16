<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Register;
use App\Filament\Pages\TaxonomyMapPage;
use App\Filament\Pages\UserManual;
use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Models\User;
use App\Support\Turnstile;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CPV-VERIFICATION-FLOW-AND-COMPLETION (Issue #2 `6215451160`), parte A: la verificación de correo del panel.
 *
 * Antes, un usuario sin verificar que abría el panel recibía 500 (`Route [verification.notice] not defined`):
 * el panel usaba el middleware de Laravel sin la verificación de Filament.
 *
 * Dobles de prueba (declarados):
 * - **Captcha:** `Turnstile` se reemplaza en el contenedor por un doble que responde sí o no. No hay
 *   conexión a Cloudflare.
 * - **Correo:** transporte `array` del propio Laravel. Renderiza el mensaje (así se genera la URL real del
 *   enlace) pero no lo envía a ningún lado. La URL se extrae del cuerpo y se usa dentro del test; no se
 *   imprime ni se guarda.
 * - **Reenvío:** `Notification::fake()`, para contar los envíos sin transporte.
 *
 * Límites: no hay SMTP real, ni buzón, ni captcha real, ni un navegador de correo.
 */
class EmailVerificationFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Transporte que no envía nada fuera del servidor, como hoy en staging (`log`), pero que deja leer
        // el mensaje.
        config(['mail.default' => 'array']);
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

    /** @return list<\Symfony\Component\Mailer\SentMessage> */
    private function sentMail(): array
    {
        return iterator_to_array(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    private function linkIn(\Symfony\Component\Mailer\SentMessage $message): string
    {
        $html = (string) $message->getOriginalMessage()->getHtmlBody();
        $this->assertSame(1, preg_match('~https?://[^"\s<>]+/email-verification/verify/[^"\s<>]+~', $html, $m), 'El mensaje trae un enlace de verificación del panel.');

        return html_entity_decode($m[0]);
    }

    private function register(string $email): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Register::class)
            ->set('data.name', 'ZZZ Afiliado Registro')
            ->set('data.email', $email)
            ->set('data.password', 'zzz-secret-123')
            ->set('data.passwordConfirmation', 'zzz-secret-123')
            ->set('ts_token', 'doble-de-prueba')
            ->call('register');
    }

    private function prompt(): string
    {
        return Filament::getPanel('admin')->getEmailVerificationPromptUrl();
    }

    /** Las páginas protegidas que revisa este test. */
    private function protectedUrls(?Empresa $empresa = null): array
    {
        $urls = [
            'inicio' => Filament::getPanel('admin')->getUrl(),
            'empresas' => EmpresaResource::getUrl('index'),
            'manual' => UserManual::getUrl(),
            'mapa' => TaxonomyMapPage::getUrl(),
        ];
        if ($empresa) {
            $urls['empresa'] = EmpresaResource::getUrl('edit', ['record' => $empresa]);
        }

        return $urls;
    }

    #[Test]
    public function registration_to_verification_required_to_link_to_panel(): void
    {
        $this->captcha(true);
        $email = 'zzz-registro-'.uniqid().'@example.test';

        $this->register($email)->assertHasNoErrors();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNull($user->email_verified_at, 'El registro deja la cuenta sin verificar.');
        $this->assertTrue(auth()->check() && auth()->id() === $user->id, 'El registro inicia la sesión del usuario nuevo.');
        $this->assertSame([], $user->getRoleNames()->all(), 'El registro no da roles.');
        $this->assertSame(0, $user->empresas()->count(), 'El registro no asocia empresas.');

        // Un solo mensaje, al correo registrado: el del panel (no se duplica con el de Laravel).
        $mail = $this->sentMail();
        $this->assertCount(1, $mail, 'Exactamente un correo de verificación por registro.');
        $this->assertSame($email, $mail[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $link = $this->linkIn($mail[0]);

        // Sin verificar: cada página protegida lleva a la página de verificación; ella responde 200.
        foreach ($this->protectedUrls() as $name => $url) {
            $this->actingAs($user)->get($url)->assertRedirect($this->prompt());
        }
        $page = $this->actingAs($user)->get($this->prompt());
        $page->assertOk()
            ->assertSee('Verifique su correo electrónico')
            ->assertSee('todavía no envía correos')
            ->assertSee('Cámara')
            ->assertDontSee('Hemos enviado')
            ->assertDontSee('array')
            ->assertDontSee('MAIL_');

        // El enlace del mensaje verifica a este usuario y abre el panel.
        $this->actingAs($user)->get($link)->assertRedirect(Filament::getPanel('admin')->getUrl());
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->actingAs($user)->get(Filament::getPanel('admin')->getUrl())->assertOk();
        $this->actingAs($user)->get($this->prompt())->assertRedirect(Filament::getPanel('admin')->getUrl());

        // Verificar no da más acceso que el que ya tenía: ni roles ni empresas.
        $this->assertSame([], $user->getRoleNames()->all());
        $this->assertSame(0, $user->empresas()->count());
    }

    #[Test]
    public function a_failed_captcha_creates_no_account_and_sends_nothing(): void
    {
        $this->captcha(false);
        $email = 'zzz-captcha-'.uniqid().'@example.test';

        $this->register($email)->assertHasErrors(['ts_token']);

        $this->assertFalse(User::where('email', $email)->exists());
        $this->assertSame([], $this->sentMail());
    }

    #[Test]
    public function registration_fires_the_registered_event_once(): void
    {
        $this->captcha(true);
        Event::fake([Registered::class]);

        $this->register('zzz-evento-'.uniqid().'@example.test')->assertHasNoErrors();

        Event::assertDispatchedTimes(Registered::class, 1);
    }

    #[Test]
    public function an_unverified_user_can_log_out_and_the_prompt_does_not_loop(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->prompt())->assertOk();
        $this->actingAs($user)->post(Filament::getPanel('admin')->getLogoutUrl())->assertRedirect();
        $this->assertGuest();
        $this->get($this->prompt())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    }

    #[Test]
    public function an_unverified_user_with_a_company_still_cannot_open_it(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => 'ZZZ EMPRESA SIN VERIFICAR', 'phone' => '+58-212-5551234']);
        $this->assertTrue($empresa->users()->where('users.id', $user->id)->exists());

        $this->actingAs($user)->get(EmpresaResource::getUrl('edit', ['record' => $empresa]))->assertRedirect($this->prompt());
    }

    #[Test]
    public function the_link_only_verifies_the_intended_authenticated_user(): void
    {
        $a = User::factory()->unverified()->create();
        $b = User::factory()->unverified()->create();
        $panel = Filament::getPanel('admin');
        $linkB = $panel->getVerifyEmailUrl($b);

        // Sin sesión: al login, y nadie queda verificado.
        $this->get($linkB)->assertRedirect($panel->getLoginUrl());
        $this->assertNull($b->fresh()->email_verified_at);

        // El enlace de B con la sesión de A: prohibido, y ninguno de los dos queda verificado.
        $this->actingAs($a)->get($linkB)->assertForbidden();
        $this->assertNull($a->fresh()->email_verified_at);
        $this->assertNull($b->fresh()->email_verified_at);

        // Firma alterada.
        $this->actingAs($b)->get($linkB.'0')->assertForbidden();
        $this->actingAs($b)->get(preg_replace('~/verify/(\d+)/~', '/verify/'.$a->id.'/', $linkB))->assertForbidden();
        // Hash de otro correo, con una firma válida de la app: lo rechaza la autorización del pedido.
        $wrongHash = URL::temporarySignedRoute('filament.admin.auth.email-verification.verify', now()->addMinutes(60), ['id' => $b->id, 'hash' => sha1('otro@example.test')]);
        $this->actingAs($b)->get($wrongHash)->assertForbidden();
        $this->assertNull($b->fresh()->email_verified_at);

        // Vencido (config auth.verification.expire, 60 min por defecto).
        $this->travel((int) config('auth.verification.expire', 60) + 1)->minutes();
        $this->actingAs($b)->get($linkB)->assertForbidden();
        $this->assertNull($b->fresh()->email_verified_at);
        $this->travelBack();

        // Un enlace vigente y propio verifica; repetirlo no cambia nada.
        $fresh = $panel->getVerifyEmailUrl($b);
        $this->actingAs($b)->get($fresh)->assertRedirect($panel->getUrl());
        $verifiedAt = $b->fresh()->email_verified_at;
        $this->assertNotNull($verifiedAt);
        $this->actingAs($b->fresh())->get($fresh)->assertRedirect($panel->getUrl());
        $this->assertEquals($verifiedAt, $b->fresh()->email_verified_at);
        $this->assertNull($a->fresh()->email_verified_at);
    }

    #[Test]
    public function resend_is_throttled_and_does_not_claim_delivery_without_a_sending_transport(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $prompt = Filament::getPanel('admin')->getEmailVerificationPromptRouteAction();

        $page = Livewire::actingAs($user)->test($prompt);
        $page->callAction('resendNotification')->assertNotified('Pedido registrado');
        $page->callAction('resendNotification')->assertNotified('Pedido registrado');
        $page->callAction('resendNotification')->assertNotified('Demasiados intentos de reenvío');

        Notification::assertSentToTimes($user, \Filament\Notifications\Auth\VerifyEmail::class, 2);
    }

    #[Test]
    public function with_a_sending_transport_the_wording_says_the_message_was_sent(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->prompt())->assertOk()->assertSee('Le enviamos un correo')->assertDontSee('todavía no envía correos');
        Livewire::actingAs($user)->test(Filament::getPanel('admin')->getEmailVerificationPromptRouteAction())
            ->callAction('resendNotification')
            ->assertNotified('Le reenviamos el correo de verificación');
    }

    #[Test]
    public function a_guest_and_a_verified_user_keep_their_previous_access(): void
    {
        $this->get(EmpresaResource::getUrl('index'))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $verified = User::factory()->create();
        $this->actingAs($verified)->get($this->prompt())->assertRedirect(Filament::getPanel('admin')->getUrl());
        $this->actingAs($verified)->get(UserManual::getUrl())->assertOk();
    }
}
