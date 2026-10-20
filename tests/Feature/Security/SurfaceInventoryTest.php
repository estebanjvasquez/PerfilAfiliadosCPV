<?php

namespace Tests\Feature\Security;

use Filament\Facades\Filament;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * CPV-SEC-01 (Issue #2 `6293112309` / `6293203444`): inventory of the EFFECTIVE attack surface of the booted application,
 * not of routes/web.php alone.
 *
 * - **Routes:** every registered route with its gathered middleware, classified as public, panel (Filament Authenticate),
 *   verified panel, signed, API or framework.
 * - **Panel:** every resource with its pages and relation managers, every custom page and widget, with the gate that
 *   protects it (policy or `canAccess`).
 * - **Livewire:** every registered component, and the public methods its APP class declares (Livewire lets the browser
 *   call any public method; the framework's own methods are not listed).
 *
 * The inventory is written as JSON evidence (synthetic environment, no data). The assertions are the regression guard:
 * no route is public unless it is on the reviewed list, and every other non-framework route goes through the panel's
 * Authenticate (or Sanctum for the API).
 */
class SurfaceInventoryTest extends TestCase
{
    /** Reviewed public entrypoints (method + URI). A new public route must be reviewed and added here on purpose. */
    private const PUBLIC_ROUTES = [
        'GET|HEAD /' => 'home page (campet view), no data beyond the signed-in user name',
        'GET|HEAD form' => 'legacy Livewire test form (App\Http\Livewire\Form): no database access; see SEC-01 register',
        'GET|HEAD pdf/{empresa}' => 'legacy link: redirect only to the protected download',
        'GET|HEAD xls/{empresa}' => 'legacy link: redirect only to the protected download',
        'GET|HEAD admin/login' => 'panel login (Turnstile)',
        'GET|HEAD admin/register' => 'panel registration',
        'GET|HEAD admin/password-reset/request' => 'password reset request',
        'GET|HEAD admin/password-reset/reset' => 'password reset form (signed)',
        'GET|HEAD filament-impersonate/leave' => 'ends an impersonation held in the session; without one it only redirects',
    ];

    /** Framework entrypoints with their own protection (signature, checksum, CSRF, signed temporary URLs). */
    private const FRAMEWORK_PREFIXES = ['livewire/', 'filament/', 'sanctum/', 'up', '_ignition', 'storage/'];

    #[Test]
    public function the_effective_surface_is_inventoried_and_no_route_is_unexpectedly_public(): void
    {
        $this->get('/admin/login')->assertOk(); // serves the panel once, so it registers its Livewire components

        $routes = [];
        $unexpectedPublic = [];
        $panelWithoutAuth = [];
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $middleware = app('router')->gatherRouteMiddleware($route);
            $flat = implode(',', array_map(fn ($m) => is_string($m) ? $m : get_class($m), $middleware));
            $key = implode('|', $route->methods()).' '.$route->uri();
            $class = $this->classify($route->uri(), $flat);
            $routes[] = [
                'route' => $key,
                'name' => $route->getName(),
                'action' => Str::after((string) $route->getActionName(), 'App\\'),
                'class' => $class,
                'auth' => str_contains($flat, 'Filament\Http\Middleware\Authenticate') ? 'panel' : ((str_contains($flat, 'auth:sanctum') || str_contains($flat, 'Authenticate:sanctum')) ? 'sanctum' : (str_contains($flat, 'Authenticate') ? 'auth' : 'none')),
                'verified' => str_contains($flat, 'EnsureEmailIsVerified') || str_contains($flat, 'verified'),
                'signed' => str_contains($flat, 'ValidateSignature') || str_contains($flat, 'signed'),
                'csrf' => str_contains($flat, 'VerifyCsrfToken'),
            ];
            if ($class === 'public' && ! array_key_exists($key, self::PUBLIC_ROUTES)) {
                $unexpectedPublic[] = $key;
            }
            if (str_starts_with($route->uri(), 'admin') && $class === 'panel' && ! str_contains($flat, 'Filament\Http\Middleware\Authenticate')) {
                $panelWithoutAuth[] = $key;
            }
        }

        $panel = Filament::getPanel('admin');
        $resources = [];
        foreach ($panel->getResources() as $resource) {
            $resources[class_basename($resource)] = [
                'model' => class_basename($resource::getModel()),
                'policy' => ($p = \Illuminate\Support\Facades\Gate::getPolicyFor($resource::getModel())) ? class_basename($p) : null,
                'pages' => array_keys($resource::getPages()),
                'relation_managers' => array_values(array_map(fn ($r) => class_basename(is_string($r) ? $r : get_class($r)), $resource::getRelations())),
                'globally_searchable' => count($resource::getGloballySearchableAttributes()) > 0,
                'custom_query_scope' => (new ReflectionMethod($resource, 'getEloquentQuery'))->getDeclaringClass()->getName() === $resource,
            ];
        }
        $pages = [];
        foreach ($panel->getPages() as $page) {
            $r = new ReflectionClass($page);
            $pages[class_basename($page)] = [
                'gate' => in_array('BezhanSalleh\FilamentShield\Traits\HasPageShield', $this->traits($r), true) ? 'shield page permission'
                    : ((new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName() === $page ? 'custom canAccess' : 'none (any verified panel user)'),
                'public_methods' => $this->appPublicMethods($r),
            ];
        }
        $widgets = array_map(fn ($w) => class_basename(is_string($w) ? $w : get_class($w)), $panel->getWidgets());

        $components = [];
        $aliases = (fn () => $this->aliases)->call(app(ComponentRegistry::class));
        foreach ($aliases as $alias => $class) {
            if (! is_string($class) || ! class_exists($class) || ! str_starts_with($class, 'App\\')) {
                continue;
            }
            $components[Str::after($class, 'App\\')] = $this->appPublicMethods(new ReflectionClass($class));
        }
        ksort($components);

        $inventory = [
            'routes_total' => count($routes),
            'routes_by_class' => array_count_values(array_column($routes, 'class')),
            'routes' => $routes,
            'resources_total' => count($resources),
            'resources' => $resources,
            'pages_total' => count($pages),
            'pages' => $pages,
            'widgets' => $widgets,
            'app_livewire_components_total' => count($components),
            'app_livewire_components_public_methods' => $components,
        ];
        $dir = storage_path('app/pdf_evidence');
        @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/sec01_inventory.json", json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->assertSame([], $unexpectedPublic, 'a new public route must be reviewed and listed in PUBLIC_ROUTES');
        $this->assertSame([], $panelWithoutAuth, 'every panel route goes through the panel Authenticate');
        $this->assertGreaterThan(20, count($resources));
        $this->assertGreaterThan(20, count($pages));
    }

    private function classify(string $uri, string $middleware): string
    {
        foreach (self::FRAMEWORK_PREFIXES as $prefix) {
            if ($uri === rtrim($prefix, '/') || str_starts_with($uri, $prefix)) {
                return 'framework';
            }
        }
        if (str_starts_with($uri, 'api/')) {
            return str_contains($middleware, 'auth:sanctum') || str_contains($middleware, 'Authenticate:sanctum') ? 'api' : 'public';
        }
        if (str_contains($middleware, 'Filament\Http\Middleware\Authenticate')) {
            return 'panel';
        }

        return 'public';
    }

    /** @return list<string> */
    private function traits(ReflectionClass $r): array
    {
        $all = [];
        do {
            $all = array_merge($all, array_keys($r->getTraits()));
            foreach ($r->getTraits() as $t) {
                $all = array_merge($all, $this->traits($t));
            }
        } while ($r = $r->getParentClass());

        return array_values(array_unique($all));
    }

    /** Public, non-static methods declared by an App class or its App traits (the ones the browser can call). */
    private function appPublicMethods(ReflectionClass $r): array
    {
        $methods = [];
        foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $file = (string) $m->getFileName();
            if ($m->isStatic() || ! str_contains(str_replace('\\', '/', $file), '/app/') || str_starts_with($m->getName(), '__')) {
                continue;
            }
            if (in_array($m->getName(), ['render', 'mount', 'boot', 'booted', 'hydrate', 'dehydrate', 'form', 'table', 'infolist', 'getTitle', 'getHeading', 'getSubheading'], true)) {
                continue;
            }
            $methods[] = $m->getName();
        }
        sort($methods);

        return $methods;
    }
}
