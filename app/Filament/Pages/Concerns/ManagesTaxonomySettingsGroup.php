<?php

namespace App\Filament\Pages\Concerns;

use App\Models\TaxonomySetting;
use App\Services\TaxonomyAuditLogger;
use App\Support\Taxonomy\TaxonomyRankingParameters;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * TAXV2-4: lógica compartida entre TaxonomyRankingSettingsPage y TaxonomyNoiseSettingsPage - ambas
 * son la misma UI (editar un subconjunto de `taxonomy_settings`, con descripción/default/rango/
 * ejemplo por campo y un botón "Restaurar default"), solo cambia qué grupos de
 * `TaxonomyRankingParameters` muestra cada una (sección 4.9 vs 4.10 del documento de
 * instrucciones). El simulador "consulta+empresa -> score antes/después" de la sección 4.9 se
 * agrega en TAXV2-5, una vez exista el refuerzo de ranking sobre el que simular.
 */
trait ManagesTaxonomySettingsGroup
{
    /** @return string[] Nombres de grupo de TaxonomyRankingParameters::GROUP_* que esta página administra. */
    abstract protected static function groups(): array;

    public ?array $data = [];

    /**
     * TAXV2-10: permiso `taxonomy_edit_weights` de la sección 13 - asignado al rol `super_admin` por
     * `TaxonomyV2PermissionsSeeder` (correrlo es obligatorio para que esta página no quede
     * inaccesible ni para el propio super_admin - ver docblock de ese seeder).
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('taxonomy_edit_weights') ?? false;
    }

    protected function definitionsForThisPage(): array
    {
        return array_filter(
            TaxonomyRankingParameters::definitions(),
            fn ($definition) => in_array($definition['group'], static::groups(), true)
        );
    }

    public function mount(): void
    {
        $keys = array_keys($this->definitionsForThisPage());
        $current = TaxonomySetting::query()->whereIn('key', $keys)->pluck('value', 'key');

        $this->form->fill($current->all());
    }

    /**
     * No se llama `form()` a propósito: `InteractsWithForms` (usado junto con este trait en cada
     * Page) ya define un método `form` propio - 2 traits con el mismo nombre de método sobre la
     * misma clase es una colisión fatal en tiempo de carga (PHP no puede resolver precedencia entre
     * traits solo). Cada Page define su propio `form(Form $form): Form` que delega acá.
     */
    protected function buildSettingsForm(Form $form): Form
    {
        $byGroup = [];
        foreach ($this->definitionsForThisPage() as $key => $definition) {
            $byGroup[$definition['group']][$key] = $definition;
        }

        $sections = [];
        foreach ($byGroup as $groupLabel => $definitions) {
            $fields = [];
            foreach ($definitions as $key => $definition) {
                $fields[] = TextInput::make($key)
                    ->label($definition['label'])
                    ->numeric()
                    ->step(0.0001)
                    ->minValue($definition['min'])
                    ->maxValue($definition['max'])
                    ->required()
                    ->helperText(
                        $definition['description'].
                        " Default: {$definition['default']}. Rango: {$definition['min']}–{$definition['max']}. Ej.: {$definition['example']}"
                    );
            }

            $sections[] = Section::make($groupLabel)->columns(2)->schema($fields);
        }

        return $form->schema($sections)->statePath('data');
    }

    /**
     * TAXV2-9: registra cada valor que de verdad cambió, sin pedir motivo (ver docblock de la
     * clase - exigir una justificación en cada prueba durante la calibración de la sección 4.9
     * haría impracticable el simulador). `TaxonomyAuditLogger::record()` ya descarta por su cuenta
     * los campos que no cambiaron.
     */
    public function save(): void
    {
        $keys = array_keys($this->definitionsForThisPage());
        $oldValues = TaxonomySetting::query()->whereIn('key', $keys)->pluck('value', 'key');
        $now = now();

        foreach ($this->form->getState() as $key => $value) {
            TaxonomySetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_by' => Auth::id(), 'updated_at' => $now]
            );

            TaxonomyAuditLogger::record(
                entityType: TaxonomySetting::class,
                entityId: $key,
                field: 'value',
                oldValue: $oldValues->get($key),
                newValue: $value,
            );
        }

        Notification::make()->success()->title('Parámetros actualizados')->send();
    }

    public function restoreDefaults(): void
    {
        $defaults = TaxonomyRankingParameters::definitions();
        $definitions = $this->definitionsForThisPage();
        $oldValues = TaxonomySetting::query()->whereIn('key', array_keys($definitions))->pluck('value', 'key');
        $now = now();

        foreach ($definitions as $key => $definition) {
            TaxonomySetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $defaults[$key]['default'], 'updated_by' => Auth::id(), 'updated_at' => $now]
            );

            TaxonomyAuditLogger::record(
                entityType: TaxonomySetting::class,
                entityId: $key,
                field: 'value',
                oldValue: $oldValues->get($key),
                newValue: $defaults[$key]['default'],
                reason: 'Restaurado a su valor default',
            );
        }

        $this->mount();

        Notification::make()->success()->title('Valores restaurados a su default')->send();
    }
}
