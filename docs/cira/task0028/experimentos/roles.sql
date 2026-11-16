with ids(id) as (values (157),(91),(333),(391),(107),(138),(74),(94),(73),(487),(83),(523),(560),(480),(75),(563),(100),(187),(141),(122),(194),(78),(573),(80),(382),(96))
select json_agg(json_build_object(
 'id', e.id, 'name', trim(e.name),
 'sectores', (select string_agg(distinct s.name, '; ') from sectors s where s.id in (e.sector_principal_id, e.sector_secundario_id)),
 'servicios', (select string_agg(distinct sv.name, '; ') from empresa_sector_service ess join services sv on sv.id = ess.service_id where ess.empresa_id = e.id),
 'categorias', (select string_agg(tc.code || ' ' || coalesce(tt.name, '') || ' [' || coalesce(tc.tipo_oferta, '-') || ', ' || etc.origen || ']', '; ' order by tc.code) from empresa_taxonomy_category etc join taxonomy_categories tc on tc.id = etc.category_id left join taxonomy_category_translations tt on tt.category_id = tc.id and tt.locale = 'es' where etc.empresa_id = e.id),
 'exp_balanc', (select count(*) from empresa_experiencias x where x.empresa_id = e.id and unaccent(x.descripcion) ilike '%balanc%'),
 'exp_balanc_fabric', (select count(*) from empresa_experiencias x where x.empresa_id = e.id and unaccent(x.descripcion) ~* 'fabrica[^.]{0,80}balanc'),
 'exp_balanc_manten', (select count(*) from empresa_experiencias x where x.empresa_id = e.id and unaccent(x.descripcion) ~* 'manten[^.]{0,80}balanc'),
 'exp_balanc_sumin', (select count(*) from empresa_experiencias x where x.empresa_id = e.id and unaccent(x.descripcion) ~* '(suministr|venta|comercializ)[^.]{0,80}balanc'),
 'exp_valv_fabric', (select count(*) from empresa_experiencias x where x.empresa_id = e.id and unaccent(x.descripcion) ~* 'fabrica[^.]{0,80}valvul'),
 'exp_valv_manten', (select count(*) from empresa_experiencias x where x.empresa_id = e.id and unaccent(x.descripcion) ~* '(manten|repara)[^.]{0,80}valvul')
) order by e.id) from empresas e join ids on ids.id = e.id;
