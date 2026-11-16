-- TASK-0028, solo en el clon aislado cpvt28-db.
drop table if exists esd_noexp;
create table esd_noexp as select esd.empresa_id, esd.document as doc from empresa_search_documents esd;
select 'exp_total', count(*) from empresa_experiencias where coalesce(descripcion,'') <> '';
select 'exp_literal_en_doc', count(*) from empresa_experiencias ee join empresa_search_documents esd on esd.empresa_id = ee.empresa_id where coalesce(ee.descripcion,'') <> '' and strpos(esd.document, ee.descripcion) > 0;
select 'exp_de_empresas_sin_doc', count(*) from empresa_experiencias ee where not exists (select 1 from empresa_search_documents esd where esd.empresa_id = ee.empresa_id);
do $$ declare r record; begin
  for r in select empresa_id, descripcion from empresa_experiencias where coalesce(descripcion,'') <> '' order by length(descripcion) desc loop
    update esd_noexp set doc = replace(doc, r.descripcion, ' ') where empresa_id = r.empresa_id;
  end loop; end $$;
alter table esd_noexp add column search_vector tsvector;
update esd_noexp set search_vector = to_tsvector('spanish', unaccent(doc));
create index esd_noexp_sv on esd_noexp using gin (search_vector);
select 'chars_doc', sum(length(document)) from empresa_search_documents;
select 'chars_noexp', sum(length(doc)) from esd_noexp;
select 'balanc_doc', count(*) from empresa_search_documents, websearch_to_tsquery('spanish', unaccent('balancin')) q where search_vector @@ q;
select 'balanc_noexp', count(*) from esd_noexp, websearch_to_tsquery('spanish', unaccent('balancin')) q where search_vector @@ q;
select 'balanc_noexp_ids', string_agg(empresa_id::text, ',') from esd_noexp, websearch_to_tsquery('spanish', unaccent('balancin')) q where search_vector @@ q;
