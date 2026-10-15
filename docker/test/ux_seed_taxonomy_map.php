<?php

/*
 * CPV-TAXONOMY-MAP (instruction 6191513374, point 4): a SYNTHETIC published taxonomy of production
 * size, so the map's browser check measures payload and behaviour at real scale instead of on the
 * handful of rows of the isolated fixture.
 *
 * Sizing follows the aggregate shape measured on staging on 2026-10-15 (counts only, no rows were
 * copied): 48 groups, 333 families (3-17 per group), 2,418 active categories (median 5 per family,
 * at most 44), 698 inactive categories, 55 empty families, 1 group with no category at all,
 * about 65% goods / 35% services. Names are generated from a small Spanish vocabulary with accents;
 * they are NOT real taxonomy names.
 *
 * Differences declared on purpose:
 *   - 3 categories have no offer type (real data has none), to exercise the «Sin tipo» badge;
 *   - a few marker names exist for the browser assertions: one group «Grupo Xilófono», one family
 *     «Familia Quetzal», one category «Zigurat …» and one name with HTML special characters.
 *
 * Runs only inside the disposable stack: it refuses unless APP_ENV=testing and the pgsql connection
 * is the runner's `testdb`/`cpv_test`. Deterministic (fixed seed).
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.pgsql');
if (app()->environment() !== 'testing' || ($cfg['host'] ?? null) !== 'testdb' || ($cfg['database'] ?? null) !== 'cpv_test') {
    fwrite(STDERR, "REFUSING: not the disposable test database\n");
    exit(3);
}

mt_srand(20261015);
$db = Illuminate\Support\Facades\DB::connection('pgsql');
$now = now();

$nouns = ['Válvulas', 'Bombas', 'Compresores', 'Tuberías', 'Mantenimiento', 'Inspección', 'Equipos', 'Sistemas', 'Perforación',
    'Ingeniería', 'Fabricación', 'Logística', 'Instrumentación', 'Señalización', 'Calibración', 'Protección catódica', 'Grúas',
    'Generadores', 'Turbinas', 'Recubrimientos', 'Aislamiento', 'Andamios', 'Cables', 'Transformadores', 'Sensores',
    'Intercambiadores', 'Tanques', 'Brocas', 'Revestimientos', 'Análisis químico', 'Soldadura', 'Montaje', 'Tableros',
    'Filtros', 'Separadores', 'Medidores', 'Bridas', 'Juntas', 'Motores', 'Embarcaciones'];
$qualifiers = ['de alta presión', 'eléctricos', 'submarinos', 'para pozos', 'de control', 'industriales', 'y accesorios',
    'en sitio', 'costa afuera', 'de seguridad', 'térmicos', 'hidráulicos', 'neumáticos', 'de proceso', 'para refinerías',
    'de emergencia', 'a prueba de explosión', 'de acero inoxidable', 'certificados', 'portátiles', 'de gran diámetro',
    'para gas', 'anticorrosivos', 'de medición fiscal'];
$name = function (int $i) use ($nouns, $qualifiers) {
    return $nouns[mt_rand(0, count($nouns) - 1)].' '.$qualifiers[mt_rand(0, count($qualifiers) - 1)].' '.sprintf('S%04d', $i);
};

// --- shape --------------------------------------------------------------------------------------
$GROUPS = 48; $FAMILIES = 333; $ACTIVE = 2418; $INACTIVE = 698; $EMPTY_FAMILIES = 55; $MAX_PER_FAMILY = 44;

$familiesPerGroup = array_fill(0, $GROUPS, 3);
for ($left = $FAMILIES - 3 * $GROUPS; $left > 0;) {
    $g = mt_rand(0, $GROUPS - 1);
    if ($familiesPerGroup[$g] < 17) { $familiesPerGroup[$g]++; $left--; }
}
$familyIndex = [];
foreach ($familiesPerGroup as $g => $n) {
    for ($f = 0; $f < $n; $f++) { $familyIndex[] = $g; }
}
// The last group is entirely empty; the rest of the empty families are spread at random.
$emptyGroup = $GROUPS - 1;
$empty = [];
foreach ($familyIndex as $i => $g) { if ($g === $emptyGroup) { $empty[$i] = true; } }
// Family #5 is the «Familia Quetzal» marker and must have categories.
while (count($empty) < $EMPTY_FAMILIES) {
    $r = mt_rand(0, count($familyIndex) - 1);
    if ($r !== 5) { $empty[$r] = true; }
}

$sizes = [];
$weights = [];
foreach ($familyIndex as $i => $g) {
    $sizes[$i] = isset($empty[$i]) ? 0 : 1;
    $weights[$i] = isset($empty[$i]) ? 0 : (mt_rand() / mt_getrandmax()) ** 3;
}
$sumW = array_sum($weights);
$base = array_sum($sizes);
foreach ($weights as $i => $w) {
    if ($w > 0) { $sizes[$i] = min($MAX_PER_FAMILY, $sizes[$i] + (int) floor($w / $sumW * ($ACTIVE - $base))); }
}
for ($left = $ACTIVE - array_sum($sizes); $left > 0;) {
    $i = mt_rand(0, count($sizes) - 1);
    if (! isset($empty[$i]) && $sizes[$i] < $MAX_PER_FAMILY) { $sizes[$i]++; $left--; }
}

// --- rows ---------------------------------------------------------------------------------------
$insert = function (array $rows) use ($db) {
    foreach (array_chunk($rows, 500) as $chunk) { $db->table('taxonomy_categories')->insert($chunk); }
};
$translate = function (array $names) use ($db, $now) {
    $ids = $db->table('taxonomy_categories')->whereIn('code', array_keys($names))->pluck('id', 'code');
    $rows = [];
    foreach ($names as $code => $label) { $rows[] = ['category_id' => $ids[$code], 'locale' => 'es', 'name' => $label, 'created_at' => $now, 'updated_at' => $now]; }
    foreach (array_chunk($rows, 500) as $chunk) { $db->table('taxonomy_category_translations')->insert($chunk); }

    return $ids;
};
$row = fn ($code, $parent, $level, $path, $active = true, $tipo = null) => [
    'code' => $code, 'parent_id' => $parent, 'level' => $level, 'path' => $path, 'is_active' => $active,
    'tipo_oferta' => $tipo, 'source_version' => 'ux-synthetic-taxonomy-map', 'created_at' => $now, 'updated_at' => $now,
];

$db->transaction(function () use ($GROUPS, $familiesPerGroup, $familyIndex, $sizes, $INACTIVE, $name, $insert, $translate, $row) {
    $groupNames = [];
    $groupRows = [];
    for ($g = 0; $g < $GROUPS; $g++) {
        $code = sprintf('CPV-5%02d', $g + 1);
        $groupRows[] = $row($code, null, 0, $code);
        $groupNames[$code] = $g === 0 ? 'Grupo Xilófono de pruebas' : 'Grupo '.$name(9000 + $g);
    }
    $insert($groupRows);
    $groupIds = $translate($groupNames);

    $familyNames = [];
    $familyRows = [];
    $familyCodes = [];
    $perGroupSeq = [];
    foreach ($familyIndex as $i => $g) {
        $gcode = sprintf('CPV-5%02d', $g + 1);
        $perGroupSeq[$g] = ($perGroupSeq[$g] ?? 0) + 1;
        $code = sprintf('%s.%02d', $gcode, $perGroupSeq[$g]);
        $familyCodes[$i] = $code;
        $familyRows[] = $row($code, $groupIds[$gcode], 1, "{$gcode}/{$code}");
        $familyNames[$code] = $i === 5 ? 'Familia Quetzal de pruebas' : 'Familia '.$name(8000 + $i);
    }
    $insert($familyRows);
    $familyIds = $translate($familyNames);

    $categoryNames = [];
    $categoryRows = [];
    $serial = 0;
    $noType = 0;
    foreach ($sizes as $i => $count) {
        $fcode = $familyCodes[$i];
        $gcode = explode('.', $fcode)[0];
        for ($c = 1; $c <= $count; $c++) {
            $serial++;
            $tipo = mt_rand(1, 100) <= 65 ? 'bien' : 'servicio';
            if ($noType < 3 && $serial % 700 === 0) { $tipo = null; $noType++; }
            $code = sprintf('%s.%03d%s', $fcode, $c, $tipo === 'servicio' ? 'S' : 'G');
            $categoryRows[] = $row($code, $familyIds[$fcode], 2, "{$gcode}/{$fcode}/{$code}", true, $tipo);
            $categoryNames[$code] = match ($serial) {
                1234 => 'Zigurat de ensayo ñandú',
                1500 => '<b>negrita</b> & "comillas" de prueba',
                default => $name($serial),
            };
        }
    }
    // Inactive categories, spread over all families (empty ones too, as in real data).
    for ($k = 1; $k <= $INACTIVE; $k++) {
        $i = mt_rand(0, count($familyCodes) - 1);
        $fcode = $familyCodes[$i];
        $gcode = explode('.', $fcode)[0];
        $code = sprintf('%s.9%03dG', $fcode, $k);
        $categoryRows[] = $row($code, $familyIds[$fcode], 2, "{$gcode}/{$fcode}/{$code}", false, 'bien');
        $categoryNames[$code] = 'Inactiva '.$name(50000 + $k);
    }
    $insert($categoryRows);
    $translate($categoryNames);

    echo 'seeded groups=', count($groupRows), ' families=', count($familyRows), ' categories_active=', $serial,
        ' categories_inactive=', $INACTIVE, ' empty_families=', count(array_filter($sizes, fn ($s) => $s === 0)),
        ' no_type=', $noType, "\n";
});

// The company list only shows companies linked to the current user. Link the synthetic Super
// Admin to the synthetic company (both created by run_tests.sh) so the browser check can open the
// company's "Categorías CPV" tab. Synthetic rows only, inside the disposable stack.
$admin = App\Models\User::where('email', 'zzz-ux-admin@example.test')->first();
// By name, case-insensitive: the model normalizes the RIF it was created with (J-00000000-9) and
// stores the company name in upper case.
$empresa = App\Models\Empresa::whereRaw('upper(name) like ?', ['ZZZ UX EMPRESA FICTICIA%'])->first()
    ?? App\Models\Empresa::where('rif', 'like', 'J%000000009')->first();
echo 'ux admin found: ', (int) (bool) $admin, ' synthetic company found: ', (int) (bool) $empresa, "\n";
// Empresa::users() itself dereferences Auth::User()->id (pre-existing; it fails with no logged-in
// user), so every use of that relation below runs logged in as the synthetic admin.
// Laravel's handler renders an uncaught error and still exits 0, so failures here are explicit.
try {
    if ($admin) {
        Illuminate\Support\Facades\Auth::login($admin);
    }
    if ($admin && $empresa && ! $empresa->users()->where('users.id', $admin->id)->exists()) {
        $empresa->users()->attach($admin->id);
    }
    // EmpresaPolicy asks for Shield permissions that production keeps in its database and this
    // stack does not seed (super_admin has no gate bypass: define_via_gate = false). Grant the
    // two the company screen needs to the synthetic admin only.
    if ($admin) {
        foreach (['view_any_empresa', 'view_empresa', 'update_empresa'] as $permission) {
            $admin->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    // EditEmpresa::getSteps() reads the LAST row of a loop over cities as `$value` (pre-existing),
    // so with an empty `cities` table the company page fails before its tabs render. Production
    // has cities; this stack gets one synthetic country/state/city if there is none.
    $main = Illuminate\Support\Facades\DB::connection();
    if ($main->table('cities')->count() === 0) {
        $countryId = $main->table('countries')->insertGetId(['country_name' => 'ZZZ País sintético', 'created_at' => now(), 'updated_at' => now()]);
        $stateId = $main->table('states')->insertGetId(['country_id' => $countryId, 'state_name' => 'ZZZ Estado sintético', 'created_at' => now(), 'updated_at' => now()]);
        $main->table('cities')->insert(['country_id' => $countryId, 'states_id' => $stateId, 'city_name' => 'ZZZ Ciudad sintética', 'created_at' => now(), 'updated_at' => now()]);
        echo "synthetic city added for the company page\n";
    }
    echo 'ux admin linked to the synthetic company: ', (int) ($admin && $empresa && $empresa->users()->where('users.id', $admin->id)->exists()), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'could not link the synthetic admin: '.get_class($e).': '.$e->getMessage()."\n");
    exit(4);
}

$m = app(App\Support\Taxonomy\TaxonomyMapBuilder::class);
$t0 = hrtime(true);
$tree = $m->build();
$ms = (hrtime(true) - $t0) / 1e6;
echo 'map totals ', json_encode($tree['totals']), ' build_ms=', round($ms, 1), "\n";
