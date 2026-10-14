<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\UserManual;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TASK-0010B: acceso a la página "Manual de usuario" del panel.
 *
 * Mismo patrón que el resto de `tests/Feature/Filament`: `DatabaseTransactions` sobre `pgsql`, así
 * que los usuarios/roles creados acá se revierten al terminar cada test.
 */
class UserManualPageTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Afiliado "normal": usuario verificado, sin ningún rol ni permiso especial. */
    private function affiliate(): User
    {
        return User::factory()->create();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));

        return $user;
    }

    #[Test]
    public function an_authenticated_affiliate_user_can_open_the_manual(): void
    {
        $this->actingAs($this->affiliate())
            ->get(UserManual::getUrl())
            ->assertOk()
            ->assertSee('Manual de usuario')
            ->assertSee('id="taxonomia-cpv"', false)
            ->assertSee('Buscar y agregar categoría');
    }

    #[Test]
    public function an_admin_can_open_the_manual_too(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(UserManual::getUrl())
            ->assertOk()
            ->assertSee('id="no-encuentro-categoria"', false);
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(UserManual::getUrl())
            ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

        $this->assertGuest();
    }

    #[Test]
    public function the_manual_is_visible_in_the_panel_navigation_for_an_affiliate(): void
    {
        $user = $this->affiliate();
        $this->actingAs($user);

        $this->assertTrue(UserManual::canAccess());
        $this->assertTrue(UserManual::shouldRegisterNavigation());

        $url = UserManual::getUrl();

        // La barra lateral renderizada (no solo la clase) debe incluir el enlace al manual.
        $html = $this->get(Filament::getPanel('admin')->getUrl() ?? '/admin')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="'.preg_quote($url, '/').'"[^>]*>(?:(?!<\/a>).)*Manual de usuario/s',
            $html,
            'El enlace "Manual de usuario" no aparece en la navegación del panel para un afiliado.'
        );
    }
}
