<?php
// CPV-TAXONOMY-MAP: the map's publication rules applied to the live taxonomy, as AGGREGATE counts.
// READ ONLY transaction + ROLLBACK. No company data, no names, no codes.
$out = fopen('php://stdout', 'w');
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('pgsql');
$db->beginTransaction();
try {
    $db->unprepared('SET TRANSACTION READ ONLY');
    $one = fn ($sql) => (array) $db->selectOne($sql);
    $r = [];
    $r['read_only'] = $db->selectOne('SHOW transaction_read_only')->transaction_read_only;
    // Visible by the map's rules.
    $r['visible'] = $one("
        with g as (select id from taxonomy_categories where level=0 and is_active and parent_id is null),
             f as (select c.id from taxonomy_categories c join g on g.id=c.parent_id where c.level=1 and c.is_active),
             k as (select c.id, c.tipo_oferta from taxonomy_categories c join f on f.id=c.parent_id where c.level=2 and c.is_active)
        select (select count(*) from g) groups, (select count(*) from f) families, (select count(*) from k) categories,
               (select count(*) from k where tipo_oferta='bien') bien, (select count(*) from k where tipo_oferta='servicio') servicio,
               (select count(*) from k where tipo_oferta is null or tipo_oferta not in ('bien','servicio')) otro,
               (select count(*) from f where not exists (select 1 from k join taxonomy_categories kc on kc.id=k.id where kc.parent_id=f.id)) empty_families,
               (select count(*) from g where not exists (select 1 from taxonomy_categories ff join k on true join taxonomy_categories kc on kc.id=k.id and kc.parent_id=ff.id where ff.parent_id=g.id and ff.id in (select id from f))) empty_groups");
    // Why active nodes are excluded.
    $r['excluded_active'] = $one("
        select
          (select count(*) from taxonomy_categories c join taxonomy_categories p on p.id=c.parent_id where c.level=2 and c.is_active and p.level=1 and not p.is_active) cat_under_inactive_family,
          (select count(*) from taxonomy_categories c join taxonomy_categories p on p.id=c.parent_id join taxonomy_categories g on g.id=p.parent_id where c.level=2 and c.is_active and p.is_active and not g.is_active) cat_under_inactive_group,
          (select count(*) from taxonomy_categories c join taxonomy_categories p on p.id=c.parent_id where c.level=1 and c.is_active and not p.is_active) family_under_inactive_group,
          (select count(*) from taxonomy_categories c left join taxonomy_categories p on p.id=c.parent_id where c.is_active and c.level>0 and (p.id is null or p.level <> c.level-1)) malformed_or_orphan,
          (select count(*) from taxonomy_categories c where c.is_active and c.level=0 and c.parent_id is not null) group_with_parent,
          (select count(*) from taxonomy_categories c where c.is_active and c.level not in (0,1,2)) other_level");
    // Names.
    $r['names'] = $one("
        select count(*) filter (where es.name is null or trim(es.name)='') active_without_es,
               count(*) filter (where (es.name is null or trim(es.name)='') and (en.name is null or trim(en.name)='')) active_without_any_name
        from taxonomy_categories c
        left join taxonomy_category_translations es on es.category_id=c.id and es.locale='es'
        left join taxonomy_category_translations en on en.category_id=c.id and en.locale='en'
        where c.is_active");
    // What the add-categories search can reach today (is_active, level != 0), for the search/map discrepancy.
    $r['search_reachable_but_not_on_map'] = $one("
        select count(*) n from taxonomy_categories c left join taxonomy_categories p on p.id=c.parent_id left join taxonomy_categories g on g.id=p.parent_id
        where c.level=2 and c.is_active and not (p.level=1 and p.is_active and g.level=0 and g.is_active and g.parent_id is null)");
    fwrite($out, json_encode($r, JSON_PRETTY_PRINT)."\n");
} finally {
    $db->rollBack();
}
