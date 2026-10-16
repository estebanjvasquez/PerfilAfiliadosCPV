<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\UserManual;
use App\Filament\Resources\EmpresaResource\Widgets\StatsOverview;
use App\Filament\Widgets\ProfileHelpWidget;
use App\Models\User;
use App\Support\UserManual\UserManualRenderer;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as FilamentDashboard;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CPV-HOME-BRANDING-AND-LAYOUT (Issue #2 `6210321522`): la página de inicio se llama «Inicio», su título
 * visible es el logo de Perfil de Afiliados y las tarjetas siguen el orden cuenta → ayuda → avisos.
 */
class HomeBrandingLayoutTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function homeHtml(User $user): string
    {
        return $this->actingAs($user)
            ->get(Filament::getPanel('admin')->getUrl())
            ->assertOk()
            ->getContent();
    }

    /** El HTML después del bloque de estilos de Inicio, que también nombra estas clases. */
    private function afterHomeStyles(string $html): string
    {
        $styles = strpos($html, '.cpv-home-notice .fi-wi-stats-overview-stat-value');
        $this->assertNotFalse($styles, 'Faltan los estilos de Inicio.');

        return substr($html, strpos($html, '</style>', $styles));
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));

        return $user;
    }

    #[Test]
    public function the_panel_has_a_single_home_page_at_the_same_url_and_route(): void
    {
        $pages = Filament::getPanel('admin')->getPages();
        $homes = array_values(array_filter($pages, fn ($p) => is_a($p, FilamentDashboard::class, true)));

        $this->assertSame([Dashboard::class], $homes, 'Debe haber una sola página de inicio: la propia.');
        // Misma URL de siempre: la raíz del panel (Panel::getUrl() resolvería al login sin sesión).
        $this->assertSame(url(Filament::getPanel('admin')->getPath()), Dashboard::getUrl());
        $this->assertSame('filament.admin.pages.dashboard', Dashboard::getRouteName());
        $this->assertSame('Inicio', Dashboard::getNavigationLabel());
    }

    #[Test]
    public function the_home_shows_the_logo_as_its_heading_and_is_called_inicio(): void
    {
        $html = $this->homeHtml(User::factory()->create());

        $this->assertMatchesRegularExpression('#<title>\s*Inicio\b#', $html);
        $this->assertMatchesRegularExpression(
            '#<h1[^>]*fi-header-heading[^>]*>\s*<span class="cpv-home-logo"><img src="'.preg_quote(asset(Dashboard::LOGO_PATH), '#').'" alt="Perfil de Afiliados" width="400" height="206"#',
            $html,
            'El <h1> de la página debe ser el logo, con «Perfil de Afiliados» como texto alternativo.'
        );
        $this->assertSame(1, substr_count($html, 'class="cpv-home-logo-img"'), 'Un solo logo en la página.');
        $this->assertStringNotContainsString('Escritorio', $html);
        // El menú lateral muestra «Inicio» enlazando a la misma URL.
        $this->assertMatchesRegularExpression('#href="'.preg_quote(Dashboard::getUrl(), '#').'"[^>]*>.*?Inicio#s', $html);
        // Los estilos del logo y los avisos se inyectan en la página.
        $this->assertStringContainsString('.dark .cpv-home-logo', $html);
    }

    #[Test]
    public function the_logo_asset_exists_with_its_original_proportions(): void
    {
        $size = getimagesize(public_path(Dashboard::LOGO_PATH));

        $this->assertNotFalse($size);
        $this->assertSame([400, 206], [$size[0], $size[1]]);
    }

    #[Test]
    public function the_home_styles_are_only_injected_on_the_home_page(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(UserManual::getUrl())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('cpv-home-logo', $html);
        $this->assertStringNotContainsString('cpv-home-notice', $html);
    }

    #[Test]
    public function the_cards_keep_their_order_and_get_balanced_spans(): void
    {
        $widgets = array_values(Filament::getPanel('admin')->getWidgets());
        $this->assertSame([AccountWidget::class, ProfileHelpWidget::class, StatsOverview::class], $widgets, 'Mismas tarjetas, mismo orden: cuenta, ayuda, avisos.');

        $this->assertSame(['default' => 1, 'lg' => 3], (new Dashboard)->getColumns());
        $this->assertSame(1, (new AccountWidget)->getColumnSpan(), 'La cuenta ocupa 1 de 3 columnas.');
        $this->assertSame(['default' => 1, 'lg' => 2], (new ProfileHelpWidget)->getColumnSpan(), 'La ayuda ocupa las otras 2.');
        $this->assertSame('full', (new StatsOverview)->getColumnSpan(), 'Los avisos van debajo, a todo el ancho.');
    }

    #[Test]
    public function an_affiliate_and_an_admin_see_account_help_and_notices_in_that_order(): void
    {
        foreach (['afiliado' => User::factory()->create(), 'admin' => $this->superAdmin()] as $who => $user) {
            $html = $this->afterHomeStyles($this->homeHtml($user));

            $account = strpos($html, 'fi-account-widget');
            $help = strpos($html, 'cpv-profile-help-widget');
            $notices = strpos($html, 'cpv-home-notice');
            $this->assertNotFalse($account, "{$who}: falta la tarjeta de la cuenta.");
            $this->assertNotFalse($help, "{$who}: falta la tarjeta de ayuda.");
            $this->assertNotFalse($notices, "{$who}: faltan los avisos.");
            $this->assertTrue($account < $help && $help < $notices, "{$who}: el orden debe ser cuenta, ayuda, avisos.");
            $this->assertSame(2, substr_count($html, 'cpv-home-notice'), "{$who}: deben estar los dos avisos.");
            $this->assertStringContainsString(Filament::getPanel('admin')->getLogoutUrl(), $html);
            $this->assertStringContainsString('md:grid-cols-2', $html, "{$who}: los avisos van en 2 columnas.");
        }
    }

    #[Test]
    public function the_manual_describes_inicio_and_keeps_the_escritorio_anchor(): void
    {
        $html = app(UserManualRenderer::class)->render()['html'];
        $start = strpos($html, '<h2 id="escritorio">');
        $this->assertNotFalse($start, 'El ancla #escritorio debe seguir existiendo.');
        $section = substr($html, $start, (strpos($html, '<h2 ', $start + 1) ?: strlen($html)) - $start);

        $this->assertStringContainsString('Inicio y el menú', $section);
        $this->assertStringContainsString('logo de <strong>Perfil de Afiliados</strong>', $section);
        $this->assertStringNotContainsString('Escritorio', $section);
        foreach (['Bienvenida/o', 'Salir', 'Ayuda para completar su perfil', 'a todo el ancho', 'En el teléfono las tarjetas se ven una debajo de otra'] as $text) {
            $this->assertStringContainsString($text, $section, "La sección de Inicio no menciona «{$text}».");
        }
        $this->assertStringNotContainsString('Escritorio', $html, 'El manual ya no debe llamar «Escritorio» a la página de inicio.');
    }
}
