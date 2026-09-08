<?php

namespace App\Filament\Resources\Concerns;

use Filament\Forms;

/**
 * Pedido 8 sep 2026: poder cargar el Nombre y la Descripción en español e inglés desde el propio
 * formulario de Crear/Editar, sin tener que guardar primero y recién ahí entrar a la pestaña
 * "Traducciones" — un RelationManager solo funciona sobre un registro que YA existe, Filament no
 * lo deja usar en la página de Crear (el registro todavía no tiene id).
 *
 * Compartido entre TaxonomyCategoryResource/TaxonomyGroupResource/TaxonomyFamilyResource (las 3
 * vistas de `taxonomy_categories`) y sus páginas de Crear/Editar. Los 4 campos
 * (name_es/name_en/description_es/description_en) son VIRTUALES — no son columnas de
 * `taxonomy_categories`, la traducción vive en la tabla aparte `taxonomy_category_translations`
 * (una fila por idioma, ver acuerdo punto 5 con Lorenzo) — por eso no se resuelven con el
 * `->relationship()` de Filament (pensado para UNA relación completa, no 2 idiomas fijos) sino que
 * se sacan a mano de los datos del formulario y se guardan aparte, después de que el registro
 * principal ya se guardó.
 *
 * La pestaña "Traducciones" (TranslationsRelationManager) sigue disponible al editar — sirve para
 * cargar un 3er idioma el día que haga falta, o revisar/borrar filas sueltas. Estos 4 campos son el
 * atajo para el caso de todos los días (ES/EN), no lo reemplazan.
 */
trait ManagesInlineTranslations
{
    protected array $pendingTranslations = [];

    protected static function translationFields(): array
    {
        return [
            Forms\Components\TextInput::make('name_es')
                ->label('Nombre (ES)')
                ->maxLength(255)
                ->helperText('Se guarda en taxonomy_category_translations (locale=es), no acá.'),
            Forms\Components\TextInput::make('name_en')
                ->label('Nombre (EN)')
                ->maxLength(255),
            Forms\Components\Textarea::make('description_es')
                ->label('Descripción (ES)')
                ->rows(2)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('description_en')
                ->label('Descripción (EN)')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Saca los 4 campos virtuales de $data (lo muta por referencia, ya que no son columnas reales
     * y no deben llegar al create()/update() del modelo principal) y los devuelve agrupados por
     * locale para guardarlos aparte en saveTranslations().
     *
     * @return array<string, array{name: ?string, description: ?string}>
     */
    protected function pullTranslationsFromData(array &$data): array
    {
        $translations = [
            'es' => ['name' => $data['name_es'] ?? null, 'description' => $data['description_es'] ?? null],
            'en' => ['name' => $data['name_en'] ?? null, 'description' => $data['description_en'] ?? null],
        ];

        unset($data['name_es'], $data['name_en'], $data['description_es'], $data['description_en']);

        return $translations;
    }

    /**
     * Crea/actualiza las filas de traducción de $record. Dejar "Nombre" en blanco borra la
     * traducción de ese idioma si ya existía (comportamiento esperado de un campo de formulario
     * que el usuario controla directamente) — un nombre vacío no tiene sentido como traducción.
     */
    protected function saveTranslations($record, array $translations): void
    {
        foreach ($translations as $locale => $fields) {
            if (blank($fields['name'])) {
                $record->translations()->where('locale', $locale)->delete();

                continue;
            }

            $record->translations()->updateOrCreate(
                ['locale' => $locale],
                ['name' => $fields['name'], 'description' => $fields['description']]
            );
        }
    }

    /** Precarga los 4 campos virtuales en $data a partir de las traducciones ya guardadas (Editar). */
    protected function mergeExistingTranslationsIntoData(array $data, $record): array
    {
        $record->loadMissing('translations');

        $es = $record->translations->firstWhere('locale', 'es');
        $en = $record->translations->firstWhere('locale', 'en');

        $data['name_es'] = $es?->name;
        $data['name_en'] = $en?->name;
        $data['description_es'] = $es?->description;
        $data['description_en'] = $en?->description;

        return $data;
    }
}
