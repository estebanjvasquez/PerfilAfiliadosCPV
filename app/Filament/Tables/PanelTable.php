<?php

namespace App\Filament\Tables;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Tables\Table;

/**
 * Tabla del panel sin la opción «Todos» de registros por página (staging 2026-11-11).
 *
 * Con «Todos» elegido, una tabla grande superaba los 30 s de PHP y se quedaba cargando, y la
 * elección guardada en la sesión dejaba al usuario bloqueado. Esto no puede vivir solo en
 * Table::configureUsing(): las páginas de reportes usan la API anterior de Filament
 * (getTableQuery(), getTableColumns()...) y su table() vuelve a asignar las opciones después de
 * configurar. Table::make() resuelve la clase desde el contenedor, así que AdminPanelProvider
 * enlaza Table con esta clase y la regla vale para cualquier tabla, se defina como se defina.
 *
 * Las descargas de reportes (Excel/PDF) no dependen de la paginación: siguen trayendo todo.
 */
class PanelTable extends Table
{
    public function getPaginationPageOptions(): array
    {
        $configured = $this->evaluate($this->paginationPageOptions);

        if ($configured === null) {
            return AdminPanelProvider::TABLE_PAGE_OPTIONS;
        }

        $options = array_values(array_filter($configured, fn ($option) => $option !== 'all'));

        return $options === [] ? AdminPanelProvider::TABLE_PAGE_OPTIONS : $options;
    }
}
