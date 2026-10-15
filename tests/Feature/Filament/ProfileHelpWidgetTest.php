<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TaxonomyMapPage;
use App\Filament\Pages\UserManual;
use App\Filament\Resources\EmpresaResource\Widgets\StatsOverview;
use App\Filament\Widgets\ProfileHelpWidget;
use App\Models\User;
use App\Support\UserManual\UserManualRenderer;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CPV-HOME-USEFUL-HELP (Issue #2 `6195707471`): el Escritorio ya no muestra la tarjeta promocional
 * de Filament y en su lugar ofrece «Ayuda para completar su perfil» (manual y mapa de la taxonomía).
 */
class ProfileHelpWidgetTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));

        return $user;
    }

    /** El HTML de la tarjeta de ayuda dentro de la página, para no confundirla con el menú lateral. */
    private function helpCard(string $html): string
    {
        $this->assertMatchesRegularExpression('/cpv-profile-help-widget/', $html, 'La tarjeta de ayuda no está en el Escritorio.');
        $start = strpos($html, 'cpv-profile-help-widget');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    #[Test]
    public function the_promotional_card_is_replaced_and_every_other_dashboard_widget_is_kept(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
        $this->assertContains(ProfileHelpWidget::class, $widgets);
        $this->assertContains(AccountWidget::class, $widgets);
        $this->assertContains(StatsOverview::class, $widgets);
        $this->assertSame(1, collect($widgets)->filter(fn ($w) => $w === ProfileHelpWidget::class)->count(), 'Registrada y descubierta: debe aparecer una sola vez.');
        // Mismo lugar que la tarjeta que reemplaza: justo después de la de la cuenta.
        $this->assertSame([AccountWidget::class, ProfileHelpWidget::class], array_slice(array_values($widgets), 0, 2));
    }

    #[Test]
    public function an_affiliate_sees_the_help_card_with_both_links_and_no_filament_promotion(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(Filament::getPanel('admin')->getUrl())
            ->assertOk()
            ->getContent();

        $card = $this->helpCard($html);
        $this->assertStringContainsString('Ayuda para completar su perfil', $card);
        $this->assertStringContainsString('href="'.UserManual::getUrl().'"', $card);
        $this->assertStringContainsString('href="'.TaxonomyMapPage::getUrl().'"', $card);
        $this->assertStringContainsString('Manual de usuario', $card);
        $this->assertStringContainsString('Mapa de la taxonomía', $card);
        $this->assertStringContainsString('las categorías se agregan desde la pestaña Categorías CPV', $card, 'La tarjeta no debe sugerir que el mapa asigna categorías.');

        foreach (['fi-filament-info-widget', 'filamentphp.com', 'github.com/filamentphp'] as $promo) {
            $this->assertStringNotContainsString($promo, $html, "El Escritorio sigue mostrando la promoción de Filament ({$promo}).");
        }
        // La tarjeta de la cuenta (con «Salir») sigue en su lugar.
        $this->assertStringContainsString('fi-account-widget', $html);
        $this->assertStringContainsString(Filament::getPanel('admin')->getLogoutUrl(), $html);
    }

    #[Test]
    public function an_admin_sees_the_same_help_card(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(Filament::getPanel('admin')->getUrl())
            ->assertOk()
            ->getContent();

        $card = $this->helpCard($html);
        $this->assertStringContainsString('href="'.UserManual::getUrl().'"', $card);
        $this->assertStringContainsString('href="'.TaxonomyMapPage::getUrl().'"', $card);
        $this->assertStringNotContainsString('fi-filament-info-widget', $html);
    }

    #[Test]
    public function a_guest_is_sent_to_login_and_the_card_follows_the_existing_access_checks(): void
    {
        // La URL del Escritorio en sí: para un invitado, Panel::getUrl() ya resuelve al login.
        $this->get(Dashboard::getUrl())
            ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

        $this->assertSame(UserManual::canAccess(), ProfileHelpWidget::canView());
        $this->assertFalse(ProfileHelpWidget::canView(), 'Sin sesión no se muestra.');

        $this->actingAs(User::factory()->create());
        $this->assertSame(UserManual::canAccess(), ProfileHelpWidget::canView());
        $this->assertTrue(ProfileHelpWidget::canView());
    }

    #[Test]
    public function the_card_renders_without_querying_the_database(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $queries = 0;
        DB::connection('pgsql')->listen(function () use (&$queries) {
            $queries++;
        });

        Livewire::actingAs($user)->test(ProfileHelpWidget::class)
            ->assertSee('Ayuda para completar su perfil')
            ->assertSee(UserManual::getUrl(), false)
            ->assertSee(TaxonomyMapPage::getUrl(), false);

        $this->assertSame(0, $queries, 'La tarjeta solo arma enlaces: no debe consultar la base.');
    }

    #[Test]
    public function the_manual_describes_the_resulting_home_page(): void
    {
        $html = app(UserManualRenderer::class)->render()['html'];
        $start = strpos($html, '<h2 id="escritorio">');
        $this->assertNotFalse($start);
        $section = substr($html, $start, (strpos($html, '<h2 ', $start + 1) ?: strlen($html)) - $start);

        foreach (['Ayuda para completar su perfil', 'Manual de usuario', 'Mapa de la taxonomía', 'Salir', 'href="#mapa-taxonomia"'] as $text) {
            $this->assertStringContainsString($text, $section, "La sección Escritorio del manual no menciona «{$text}».");
        }
        $this->assertStringContainsString('El mapa no agrega categorías', $section);
    }
}
