<?php
// TASK-0014: reproduce «balancín» on the shared DB, read-only (transaction READ ONLY, rolled back).
chdir('C:/Proyectos/GitHub/PerfilAfiliadosCPV');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.pgsql.port' => 6543]);
use Illuminate\Support\Facades\DB;
$db = DB::connection('pgsql');
$db->beginTransaction();
$db->statement('set transaction read only');

$out = [];
foreach (['balancín', 'balancin', 'Balancín', 'BALANCIN', 'balancines', 'pumping unit', 'pump jack'] as $q) {
    $res = app(App\Services\TaxonomyCategorySearch::class)->search($q, 15);
    $out['selector_empresa'][$q] = $res->map(function ($r) use ($db) {
        $c = $db->selectOne('select code, level, is_active, path, chamber_relevance from taxonomy_categories where id = ?', [$r['category_id']]);
        return ['id' => $r['category_id'], 'code' => $r['code'], 'level' => $r['level'], 'active' => $c->is_active, 'relevance' => $c->chamber_relevance,
            'breadcrumb' => $r['breadcrumb'], 'match' => $r['match_type'], 'why' => $r['explanation']];
    })->all();
}

// Admin list searches as implemented: name_es LIKE (case/accent-sensitive), code ILIKE (Filament default on pgsql)
foreach (['balancín', 'balancin', 'Balancín'] as $q) {
    foreach ([0 => 'grupo', 1 => 'familia', 2 => 'categoria'] as $lvl => $name) {
        $out['admin_nombre_es_like'][$q][$name] = array_map(fn ($r) => "$r->code | $r->name | activo=" . ($r->is_active ? 1 : 0),
            $db->select("select tc.code, t.name, tc.is_active from taxonomy_categories tc join taxonomy_category_translations t on t.category_id=tc.id and t.locale='es'
                where tc.level=? and t.name like ? order by tc.code", [$lvl, "%$q%"]));
    }
}
// Same, normalized (what a coherent search would find): ES/EN names, synonyms, dictionary terms/aliases (any status)
$out['normalizado_nombres_es_en'] = array_map(fn ($r) => "$r->code | nivel $r->level | activo=" . ($r->is_active ? 1 : 0) . " | $r->locale: $r->name",
    $db->select("select tc.code, tc.level, tc.is_active, t.locale, t.name from taxonomy_categories tc join taxonomy_category_translations t on t.category_id=tc.id
        where unaccent(t.name) ilike unaccent('%balanc%') or t.name ilike '%pumping unit%' or t.name ilike '%pump jack%' order by tc.code"));
$out['sinonimos_categoria'] = array_map(fn ($r) => "$r->code | $r->term", $db->select("select tc.code, s.term from taxonomy_category_synonyms s join taxonomy_categories tc on tc.id=s.category_id where unaccent(s.term) ilike unaccent('%balanc%')"));
$out['sinonimos_total'] = $db->selectOne('select count(*) n from taxonomy_category_synonyms')->n;
$terms = $db->select("select t.id, t.term, t.canonical_term, t.status, (select string_agg(a.alias, ', ') from taxonomy_term_aliases a where a.term_id=t.id) aliases
    from taxonomy_terms t where unaccent(t.term) ilike unaccent('%balanc%') or unaccent(t.canonical_term) ilike unaccent('%balanc%')
    or exists (select 1 from taxonomy_term_aliases a where a.term_id=t.id and unaccent(a.alias) ilike unaccent('%balanc%')) order by t.term");
foreach ($terms as $t) {
    $rels = $db->select("select tc.code, tc.level, tc.is_active, r.status, r.relation_type, r.weight, r.source from taxonomy_term_cpv_relations r join taxonomy_categories tc on tc.id=r.category_id where r.term_id=? order by r.status, tc.code", [$t->id]);
    $out['diccionario'][] = ['term' => $t->term, 'canonical' => $t->canonical_term, 'status' => $t->status, 'aliases' => $t->aliases,
        'relaciones' => array_map(fn ($r) => "$r->code nivel $r->level activo=" . ($r->is_active ? 1 : 0) . " | $r->status | $r->relation_type | peso $r->weight | $r->source", $rels)];
}
$db->rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
