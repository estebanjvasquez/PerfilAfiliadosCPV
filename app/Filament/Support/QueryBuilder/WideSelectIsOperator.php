<?php

namespace App\Filament\Support\QueryBuilder;

use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;

/**
 * Fix de UI encontrado el 7 sep 2026 en el filtro "tipo Excel" de Taxonomía CPV: el select
 * "Valor" de un SelectConstraint queda con ~1/3 del ancho del bloque (el select operator ocupa
 * columna 1 de 3, y el `IsOperator::getFormSchema()` de Filament no marca su propio Select con
 * `columnSpanFull()` dentro del grupo de columna 2 de esas 3 - a diferencia de `TextConstraint`,
 * cuyos operadores SÍ lo hacen, ver ContainsOperator::getFormSchema()). Con opciones largas
 * ("Does not belong") el select queda cortado ("Do...").
 *
 * No se toca el vendor (se pisaría en cada `composer update`) - se sobreescribe el único método
 * que cambia (`getFormSchema()`), heredando el resto (label, summary, apply/whereIn) intacto de
 * `IsOperator`. Se usa en vez del operador por defecto vía
 * `SelectConstraint::make(...)->operators([WideSelectIsOperator::class, IsFilledOperator::make()...])`.
 */
class WideSelectIsOperator extends IsOperator
{
    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    public function getFormSchema(): array
    {
        return array_map(
            fn ($component) => $component->columnSpanFull(),
            parent::getFormSchema()
        );
    }
}
