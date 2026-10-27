<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0011 (Bloque 3, Issue #2 `6438570942`): acceso directo desde Inicio a la empresa del usuario, con su avance y lo
 * que le falta. Aplica a cualquier rol que tenga empresas vinculadas y pueda verlas: un afiliado ve su empresa (o las
 * pocas que atiende) con los pendientes; quien tiene muchas (personal de la Cámara) ve el total y el enlace al listado,
 * sin calcular pendientes de cada una.
 *
 * Solo las empresas vinculadas en `empresa_user` (leído directo: `Empresa::users()` exige sesión). El % y los
 * pendientes se calculan en vivo (misma fuente, no se contradicen) y solo para hasta MAX_DETAILED empresas.
 */
class MyCompaniesWidget extends Widget
{
    public const MAX_DETAILED = 3;

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.my-companies-widget';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('view_any_empresa') && static::linkedQuery($user->getKey())->exists();
    }

    private static function linkedQuery(int|string $userId)
    {
        return Empresa::query()->whereIn('id', DB::table('empresa_user')->where('user_id', $userId)->select('empresa_id'));
    }

    protected function getViewData(): array
    {
        $query = static::linkedQuery(Auth::id());
        $total = (clone $query)->count();

        $companies = $total > self::MAX_DETAILED
            ? collect()
            : $query->orderBy('name')->get()->map(fn (Empresa $empresa) => [
                'name' => $empresa->name,
                'percentage' => $empresa->completionPercentage(),
                'pending' => collect($empresa->completionData())->reject(fn (bool $complete) => $complete)->keys()->all(),
                'url' => EmpresaResource::getUrl('edit', ['record' => $empresa]),
            ]);

        return [
            'total' => $total,
            'companies' => $companies,
            'listUrl' => EmpresaResource::getUrl('index'),
        ];
    }
}
