<?php

/*
 * TASK-0025 (F-SEC01-T24-02): the 13 report views of the shared Supabase database, as PostgreSQL returns them
 * (pg_get_viewdef, read-only catalog query on 2026-11-13). Schema only: no rows. In Supabase these views were
 * created outside the Laravel migration set (see db:skip-mysql-only-view-migrations), so the isolated test database
 * does not have them; the security tests create them from here to reproduce the live state.
 */

return [
    'capacityView' => <<<'SQL'
 SELECT empresas.id,
    empresas.name,
    string_agg(DISTINCT sectors.name::text, ', '::text ORDER BY (sectors.name::text)) AS "Sector",
    string_agg(DISTINCT services.name::text, ', '::text ORDER BY (services.name::text)) AS "Servicios",
    ( SELECT sum(round(COALESCE((elem.value ->> 'junior_q'::text)::numeric, 0::numeric))) + sum(round(COALESCE((elem.value ->> 'medium_q'::text)::numeric, 0::numeric))) + sum(round(COALESCE((elem.value ->> 'senior_q'::text)::numeric, 0::numeric)))
           FROM assets a2
             CROSS JOIN LATERAL jsonb_array_elements(a2.employee::jsonb) elem(value)
          WHERE a2.empresa_id = empresas.id) AS rrhh,
    ( SELECT sum(round(COALESCE((elem.value ->> 'facility_q'::text)::numeric, 0::numeric))) AS sum
           FROM assets a2
             CROSS JOIN LATERAL jsonb_array_elements(a2.facility::jsonb) elem(value)
          WHERE a2.empresa_id = empresas.id) AS instalaciones,
    ( SELECT
                CASE max(round((elem.value ->> 'machinery_qid'::text)::numeric))
                    WHEN 1 THEN '1-10'::text
                    WHEN 2 THEN '11-50'::text
                    WHEN 3 THEN '51-100'::text
                    WHEN 4 THEN '> 100'::text
                    ELSE NULL::text
                END AS "case"
           FROM assets a2
             CROSS JOIN LATERAL jsonb_array_elements(a2.machinery::jsonb) elem(value)
          WHERE a2.empresa_id = empresas.id) AS maquinaria,
    ( SELECT
                CASE max(round((elem.value ->> 'inventory_est'::text)::numeric))
                    WHEN 1 THEN '< 100.000'::text
                    WHEN 2 THEN '100.001 - 1.000.000'::text
                    WHEN 3 THEN '1.000.001 - 10.000.000'::text
                    WHEN 4 THEN '> 10.000.000'::text
                    ELSE NULL::text
                END AS "case"
           FROM assets a2
             CROSS JOIN LATERAL jsonb_array_elements(a2.inventory::jsonb) elem(value)
          WHERE a2.empresa_id = empresas.id AND (elem.value ->> 'inventory_est'::text) IS NOT NULL) AS inventario
   FROM empresas
     LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
     LEFT JOIN services ON empresa_sector_service.service_id = services.id
     LEFT JOIN sectors ON services.sectors_id = sectors.id
  GROUP BY empresas.id, empresas.name
SQL,
    'catalogoView' => <<<'SQL'
 SELECT empresas.id,
    empresas.name,
    string_agg(DISTINCT COALESCE(sectors.name, ''::character varying)::text, ', '::text ORDER BY (COALESCE(sectors.name, ''::character varying)::text)) AS "Sector",
    empresas.street,
    (cities.city_name::text || ', '::text) || countries.country_name::text AS "CIUDAD",
    empresas.ano_fund AS fundacion,
    empresas.rif,
    empresas.phone,
    empresas.website,
    COALESCE(( SELECT string_agg(distinct_contacts.entry, '; 
'::text ORDER BY (lower(unaccent(distinct_contacts.contact_name::text))), distinct_contacts.contact_id) AS string_agg
           FROM ( SELECT DISTINCT contacts.id AS contact_id,
                    contacts.name AS contact_name,
                    (((('('::text || COALESCE(contacts.name, ''::character varying)::text) || COALESCE(', '::text || contacts."position"::text, ''::text)) || COALESCE(', '::text || contacts.phone::text, ''::text)) || COALESCE(', '::text || contacts.email::text, ''::text)) || ')'::text AS entry
                   FROM contact_empresa
                     JOIN contacts ON contact_empresa.contact_id = contacts.id
                  WHERE contact_empresa.empresa_id = empresas.id) distinct_contacts), '()'::text) AS "CONTACTOS",
    string_agg(DISTINCT COALESCE(chambers.name, ''::character varying)::text, ', '::text ORDER BY (COALESCE(chambers.name, ''::character varying)::text)) AS camara
   FROM empresas
     JOIN empresa_user ON empresas.id = empresa_user.empresa_id
     LEFT JOIN cities ON empresas.city_id = cities.id
     LEFT JOIN countries ON cities.country_id = countries.id
     LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
     LEFT JOIN services ON empresa_sector_service.service_id = services.id
     LEFT JOIN sectors ON services.sectors_id = sectors.id
     LEFT JOIN chamber_empresa ON empresas.id = chamber_empresa.empresa_id
     LEFT JOIN chambers ON chamber_empresa.chamber_id = chambers.id
  GROUP BY empresas.id, empresas.name, empresas.street, ((cities.city_name::text || ', '::text) || countries.country_name::text), empresas.ano_fund, empresas.rif, empresas.phone, empresas.website
SQL,
    'ClientsView' => <<<'SQL'
 SELECT t.id,
    t.name,
    ( SELECT string_agg(DISTINCT sectors.name::text, ', '::text ORDER BY (sectors.name::text)) AS string_agg
           FROM empresas
             LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
             LEFT JOIN services ON empresa_sector_service.service_id = services.id
             LEFT JOIN sectors ON services.sectors_id = sectors.id
          WHERE empresas.id = t.id) AS "Sector",
    replace(COALESCE(elem.value ->> 'customer_name'::text, ''::text), 'null'::text, ''::text) AS cliente,
    ( SELECT countries.country_name
           FROM countries
          WHERE countries.id = ((elem.value ->> 'country_id'::text)::integer)) AS pais
   FROM empresas t
     CROSS JOIN LATERAL jsonb_array_elements(t.customers_country::jsonb) elem(value)
  ORDER BY t.id
SQL,
    'ExperienceView' => <<<'SQL'
 SELECT empresa_id AS id,
    ( SELECT empresas.name
           FROM empresas
          WHERE empresas.id = z.empresa_id) AS name,
    ( SELECT infrasectors.sector_name
           FROM infrasectors
          WHERE infrasectors.id = (((z.rec -> 'data'::text) ->> 'infrasectors_id'::text)::integer)) AS sectorind,
    ( SELECT infratypes.type_name
           FROM infratypes
          WHERE infratypes.id = (((z.rec -> 'data'::text) ->> 'infratypes_id'::text)::integer)) AS tipoind,
    ( SELECT infrasystems.system_name
           FROM infrasystems
          WHERE infrasystems.id = (((z.rec -> 'data'::text) ->> 'infrasystems_id'::text)::integer)) AS systemind,
    ( SELECT infraregions.region_name
           FROM infraregions
          WHERE infraregions.id = (((z.rec -> 'data'::text) ->> 'infraregions_id'::text)::integer)) AS regionind,
    ( SELECT infrafacilities.facility_name
           FROM infrafacilities
          WHERE infrafacilities.id = (((z.rec -> 'data'::text) ->> 'infrafacilities_id'::text)::integer)) AS facilityind,
    ( SELECT sectors.name
           FROM sectors
          WHERE sectors.id = (((z.rec -> 'data'::text) ->> 'sectors_id'::text)::integer)) AS sector,
    ( SELECT string_agg(services.name::text || ' '::text, ','::text ORDER BY services.id) AS string_agg
           FROM services
          WHERE (services.id IN ( SELECT v.value::integer AS v
                   FROM jsonb_array_elements_text(
                        CASE
                            WHEN jsonb_typeof(COALESCE((z.rec -> 'data'::text) -> 'services_id'::text, 'null'::jsonb)) = 'array'::text THEN (z.rec -> 'data'::text) -> 'services_id'::text
                            ELSE '[]'::jsonb
                        END) v(value)))) AS service,
    (rec -> 'data'::text) ->> 'exp_year'::text AS ano,
        CASE (rec -> 'data'::text) ->> 'magnitud'::text
            WHEN '1'::text THEN '< 100.000 USD'::text
            WHEN '2'::text THEN '100.001 - 1.000.000 USD'::text
            WHEN '3'::text THEN '1.000.001 - 10.000.000 USD'::text
            WHEN '4'::text THEN '> 10.000.001 USD'::text
            ELSE NULL::text
        END AS magnitud,
        CASE
            WHEN ((rec -> 'data'::text) -> 'prof_tech'::text) IS NULL THEN NULL::text
            WHEN jsonb_typeof((rec -> 'data'::text) -> 'prof_tech'::text) = 'null'::text THEN ''::text
            ELSE replace((rec -> 'data'::text) ->> 'prof_tech'::text, 'null'::text, ''::text)
        END AS prof_tech,
        CASE
            WHEN ((rec -> 'data'::text) -> 'manpower'::text) IS NULL THEN NULL::text
            WHEN jsonb_typeof((rec -> 'data'::text) -> 'manpower'::text) = 'null'::text THEN ''::text
            ELSE replace((rec -> 'data'::text) ->> 'manpower'::text, 'null'::text, ''::text)
        END AS manpower,
        CASE
            WHEN jsonb_typeof((rec -> 'data'::text) -> 'Descripcion'::text) = 'null'::text THEN ''::text
            ELSE (rec -> 'data'::text) ->> 'Descripcion'::text
        END AS descripcion
   FROM ( SELECT t.empresa_id,
            elem.value AS rec
           FROM experiences t
             CROSS JOIN LATERAL jsonb_array_elements(
                CASE
                    WHEN jsonb_typeof(t.exp_year::jsonb) = 'array'::text THEN t.exp_year::jsonb
                    ELSE jsonb_build_array(t.exp_year::jsonb)
                END) elem(value)) z
  ORDER BY empresa_id
SQL,
    'FacilityView' => <<<'SQL'
 SELECT a.empresa_id AS id,
    max(empresas.name::text) AS name,
    max(
        CASE
            WHEN round(e.facility_type)::integer = 0 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Oficinas_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 0 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Oficinas_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 0 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Oficinas_own",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 1 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Talleres_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 1 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Talleres_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 1 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Talleres_own",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 2 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Manufactura_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 2 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Manufactura_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 2 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Manufactura_own",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 3 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Almacenes_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 3 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Almacenes_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 3 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Almacenes_own",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 4 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Laboratorios_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 4 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Laboratorios_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 4 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Laboratorios_own",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 5 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Marinas_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 5 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Marinas_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 5 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Marinas_own",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 6 THEN round(e.facility_q)::integer::text
            ELSE ' '::text
        END) AS "Otros_q",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 6 THEN round(e.facility_surf)::integer::text
            ELSE ' '::text
        END) AS "Otros_surf",
    max(
        CASE
            WHEN round(e.facility_type)::integer = 6 THEN
            CASE
                WHEN round(e.facility_own)::integer = 1 THEN 'Propia'::text
                WHEN round(e.facility_own)::integer = 2 THEN 'Alquilada'::text
                WHEN round(e.facility_own)::integer = 3 THEN 'Ambas'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Otros_own"
   FROM assets a
     JOIN LATERAL jsonb_to_recordset(a.facility::jsonb) e(facility_q numeric, facility_own numeric, facility_surf numeric, facility_type numeric) ON true
     JOIN empresas ON a.empresa_id = empresas.id
  GROUP BY a.empresa_id
SQL,
    'FinanceView' => <<<'SQL'
 SELECT empresas.id,
    empresas.name,
    string_agg(DISTINCT sectors.name::text, ', '::text ORDER BY (sectors.name::text)) AS "Sector",
    ( SELECT sum(round(COALESCE((elem.value ->> 'junior_q'::text)::numeric, 0::numeric))) + sum(round(COALESCE((elem.value ->> 'medium_q'::text)::numeric, 0::numeric))) + sum(round(COALESCE((elem.value ->> 'senior_q'::text)::numeric, 0::numeric)))
           FROM assets a2
             CROSS JOIN LATERAL jsonb_array_elements(a2.employee::jsonb) elem(value)
          WHERE a2.empresa_id = empresas.id) AS rrhh,
    max(
        CASE
            WHEN empresas.billing_id = 1 THEN '< 100.000 USD'::text
            WHEN empresas.billing_id = 2 THEN '100.001 - 1.000.000 USD'::text
            WHEN empresas.billing_id = 3 THEN '1.000.001 - 10.000.000 USD'::text
            WHEN empresas.billing_id = 4 THEN '> 10.000.001 USD'::text
            ELSE NULL::text
        END) AS "BILLING",
    max(
        CASE
            WHEN empresas.status_id = 1 THEN 'Activa'::text
            WHEN empresas.status_id = 0 THEN 'Inactiva'::text
            ELSE NULL::text
        END) AS "ESTADO",
    max(
        CASE
            WHEN empresas.property_id = 1 THEN 'Privado'::text
            WHEN empresas.property_id = 0 THEN 'Publico'::text
            ELSE NULL::text
        END) AS "CAPITAL",
    max(
        CASE
            WHEN empresas.origin_id = 1 THEN 'Nacional'::text
            WHEN empresas.origin_id = 0 THEN 'Internacional'::text
            ELSE NULL::text
        END) AS "ORIGEN"
   FROM empresas
     LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
     LEFT JOIN services ON empresa_sector_service.service_id = services.id
     LEFT JOIN sectors ON services.sectors_id = sectors.id
  GROUP BY empresas.id, empresas.name
SQL,
    'InventoryView' => <<<'SQL'
 SELECT a.empresa_id AS id,
    max(empresas.name::text) AS name,
    max(
        CASE
            WHEN round(e.inventory_name)::integer = 0 THEN
            CASE
                WHEN round(e.inventory_q)::integer = 1 THEN '1 - 10'::text
                WHEN round(e.inventory_q)::integer = 2 THEN '11 - 50'::text
                WHEN round(e.inventory_q)::integer = 3 THEN '51 - 100'::text
                WHEN round(e.inventory_q)::integer = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Materia_q",
    max(
        CASE
            WHEN round(e.inventory_name)::integer = 0 THEN
            CASE
                WHEN round(e.inventory_est)::integer = 1 THEN '< 100.000 USD'::text
                WHEN round(e.inventory_est)::integer = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN round(e.inventory_est)::integer = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN round(e.inventory_est)::integer = 4 THEN '> 10.000.000 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Materia_est",
    max(
        CASE
            WHEN round(e.inventory_name)::integer = 0 THEN e.inventory_unit
            ELSE ' '::text
        END) AS "Materia_unit",
    max(
        CASE
            WHEN round(e.inventory_name)::integer = 1 THEN
            CASE
                WHEN round(e.inventory_q)::integer = 1 THEN '1 - 10'::text
                WHEN round(e.inventory_q)::integer = 2 THEN '11 - 50'::text
                WHEN round(e.inventory_q)::integer = 3 THEN '51 - 100'::text
                WHEN round(e.inventory_q)::integer = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Producto_q",
    max(
        CASE
            WHEN round(e.inventory_name)::integer = 1 THEN
            CASE
                WHEN round(e.inventory_est)::integer = 1 THEN '< 100.000 USD'::text
                WHEN round(e.inventory_est)::integer = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN round(e.inventory_est)::integer = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN round(e.inventory_est)::integer = 4 THEN '> 10.000.000 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Producto_est",
    max(
        CASE
            WHEN round(e.inventory_name)::integer = 1 THEN e.inventory_unit
            ELSE ' '::text
        END) AS "Producto_unit"
   FROM assets a
     JOIN LATERAL jsonb_to_recordset(a.inventory::jsonb) e(inventory_q numeric, inventory_est numeric, inventory_unit text, inventory_name numeric) ON true
     JOIN empresas ON a.empresa_id = empresas.id
  GROUP BY a.empresa_id
SQL,
    'MachineryView' => <<<'SQL'
 SELECT a.empresa_id AS id,
    max(empresas.name::text) AS name,
    max(
        CASE
            WHEN e.machinery_name = 0 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Equip_med_lev_qua",
    max(
        CASE
            WHEN e.machinery_name = 0 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Equip_med_lev_est",
    max(
        CASE
            WHEN e.machinery_name = 1 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Equip_mar_flu_qua",
    max(
        CASE
            WHEN e.machinery_name = 1 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Equip_mar_flu_est",
    max(
        CASE
            WHEN e.machinery_name = 2 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Mov_terr_cons_qua",
    max(
        CASE
            WHEN e.machinery_name = 2 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Mov_terr_cons_est",
    max(
        CASE
            WHEN e.machinery_name = 3 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Equip_men_cons_qua",
    max(
        CASE
            WHEN e.machinery_name = 3 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Equip_men_cons_est",
    max(
        CASE
            WHEN e.machinery_name = 4 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Fab_metal_elec_qua",
    max(
        CASE
            WHEN e.machinery_name = 4 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Fab_metal_elec_est",
    max(
        CASE
            WHEN e.machinery_name = 5 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Mont_elec_meca_qua",
    max(
        CASE
            WHEN e.machinery_name = 5 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Mont_elec_meca_est",
    max(
        CASE
            WHEN e.machinery_name = 6 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Maq_herr_meca_qua",
    max(
        CASE
            WHEN e.machinery_name = 6 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Maq_herr_meca_est",
    max(
        CASE
            WHEN e.machinery_name = 7 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Almac_trans_qua",
    max(
        CASE
            WHEN e.machinery_name = 7 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Almac_trans_est",
    max(
        CASE
            WHEN e.machinery_name = 8 THEN
            CASE
                WHEN e.machinery_qid = 1 THEN '1 - 10'::text
                WHEN e.machinery_qid = 2 THEN '11 - 50'::text
                WHEN e.machinery_qid = 3 THEN '51 - 100'::text
                WHEN e.machinery_qid = 4 THEN '> 100'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Serv_poz_inst_qua",
    max(
        CASE
            WHEN e.machinery_name = 8 THEN
            CASE
                WHEN e.machinery_est = 1 THEN '< 100.000 USD'::text
                WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD'::text
                WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD'::text
                WHEN e.machinery_est = 4 THEN '> 10.000.001 USD'::text
                ELSE NULL::text
            END
            ELSE ' '::text
        END) AS "Serv_poz_inst_est"
   FROM assets a
     JOIN LATERAL jsonb_to_recordset(a.machinery::jsonb) e(machinery_est integer, machinery_qid integer, machinery_name integer) ON true
     JOIN empresas ON a.empresa_id = empresas.id
  GROUP BY a.empresa_id
  ORDER BY a.empresa_id
SQL,
    'ManagementDetView' => <<<'SQL'
 SELECT a.empresa_id AS id,
    empresas.name,
        CASE
            WHEN a.iso9001 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso9001,
        CASE
            WHEN a.iso17025 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso17025,
        CASE
            WHEN a.quality_otros IS TRUE THEN ( SELECT ('['::text || string_agg(to_json(t.elem ->> 'quality_otros_name'::text)::text, ', '::text ORDER BY t.ord)) || ']'::text
               FROM jsonb_array_elements(a.quality_data::jsonb) WITH ORDINALITY t(elem, ord))
            ELSE 'NO'::text
        END AS "QUALITY_OTROS",
        CASE
            WHEN a.iso14001 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso14001,
        CASE
            WHEN a.iso50001 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso50001,
        CASE
            WHEN a.environment_otros IS TRUE THEN ( SELECT ('['::text || string_agg(to_json(t.elem ->> 'environment_otros_name'::text)::text, ', '::text ORDER BY t.ord)) || ']'::text
               FROM jsonb_array_elements(a.environment_data::jsonb) WITH ORDINALITY t(elem, ord))
            ELSE 'NO'::text
        END AS "ENVIRONMENT_OTROS",
        CASE
            WHEN a.dun IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS dun,
        CASE
            WHEN a.iso37001 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso37001,
        CASE
            WHEN a.credibility_otros IS TRUE THEN ( SELECT ('['::text || string_agg(to_json(t.elem ->> 'credibility_otros_name'::text)::text, ', '::text ORDER BY t.ord)) || ']'::text
               FROM jsonb_array_elements(a.credibility_data::jsonb) WITH ORDINALITY t(elem, ord))
            ELSE 'NO'::text
        END AS "CREDIBILITY_OTROS",
        CASE
            WHEN a.iso45001 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso45001,
        CASE
            WHEN a.ovid IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS ovid,
        CASE
            WHEN a.security_otros IS TRUE THEN ( SELECT ('['::text || string_agg(to_json(t.elem ->> 'security_otros_name'::text)::text, ', '::text ORDER BY t.ord)) || ']'::text
               FROM jsonb_array_elements(a.security_data::jsonb) WITH ORDINALITY t(elem, ord))
            ELSE 'NO'::text
        END AS "SECURITY_OTROS",
        CASE
            WHEN a.pmi IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS pmi,
        CASE
            WHEN a.pmi_otros IS TRUE THEN ( SELECT ('['::text || string_agg(to_json(t.elem ->> 'pmi_otros_name'::text)::text, ', '::text ORDER BY t.ord)) || ']'::text
               FROM jsonb_array_elements(a.pmi_data::jsonb) WITH ORDINALITY t(elem, ord))
            ELSE 'NO'::text
        END AS "PMI_OTROS",
        CASE
            WHEN a.iso27001 IS TRUE THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS iso27001,
        CASE
            WHEN a.info_otros IS TRUE THEN ( SELECT ('['::text || string_agg(to_json(t.elem ->> 'info_otros_name'::text)::text, ', '::text ORDER BY t.ord)) || ']'::text
               FROM jsonb_array_elements(a.info_data::jsonb) WITH ORDINALITY t(elem, ord))
            ELSE 'NO'::text
        END AS "INFO_OTROS"
   FROM management a
     JOIN empresas ON a.empresa_id = empresas.id
SQL,
    'ManagementView' => <<<'SQL'
 SELECT empresas.id,
    empresas.name,
    string_agg(DISTINCT sectors.name::text, ', '::text) AS "Sector",
        CASE
            WHEN management.iso9001 IS TRUE OR management.iso17025 IS TRUE OR management.quality_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END AS "Calidad",
        CASE
            WHEN management.iso14001 IS TRUE OR management.iso50001 IS TRUE OR management.environment_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END AS "Ambiente",
        CASE
            WHEN management.iso45001 IS TRUE OR management.ovid IS TRUE OR management.security_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END AS "Seguridad",
        CASE
            WHEN management.pmi IS TRUE OR management.pmi_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END AS "Gestion",
        CASE
            WHEN management.dun IS TRUE OR management.iso37001 IS TRUE OR management.credibility_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END AS "Credibilidad",
        CASE
            WHEN management.iso27001 IS TRUE OR management.info_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END AS "Informacion"
   FROM empresas
     LEFT JOIN management ON empresas.id = management.empresa_id
     LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
     LEFT JOIN services ON empresa_sector_service.service_id = services.id
     LEFT JOIN sectors ON services.sectors_id = sectors.id
  GROUP BY empresas.id, empresas.name, (
        CASE
            WHEN management.iso9001 IS TRUE OR management.iso17025 IS TRUE OR management.quality_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END), (
        CASE
            WHEN management.iso14001 IS TRUE OR management.iso50001 IS TRUE OR management.environment_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END), (
        CASE
            WHEN management.iso45001 IS TRUE OR management.ovid IS TRUE OR management.security_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END), (
        CASE
            WHEN management.pmi IS TRUE OR management.pmi_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END), (
        CASE
            WHEN management.dun IS TRUE OR management.iso37001 IS TRUE OR management.credibility_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END), (
        CASE
            WHEN management.iso27001 IS TRUE OR management.info_otros IS TRUE THEN 'Si'::text
            ELSE 'No'::text
        END)
SQL,
    'PresenceView' => <<<'SQL'
 SELECT p.empresa_id AS id,
    COALESCE(e.name, ''::character varying) AS name,
        CASE
            WHEN p.has_offices IS FALSE THEN 'X'::text
            ELSE ' '::text
        END AS "hasOfficesNo",
        CASE
            WHEN p.has_offices IS FALSE THEN ' '::text
            ELSE 'X'::text
        END AS "hasOfficesYes",
    COALESCE(c.country_name, ''::character varying) AS pais,
    replace(COALESCE(p.rec ->> 'offices_surf'::text, ''::text), 'null'::text, ''::text) AS mts,
    replace(COALESCE(p.rec ->> 'employees_q'::text, ''::text), 'null'::text, ''::text) AS emp_q,
        CASE
            WHEN (p.rec ->> 'status'::text) = 'true'::text THEN 'SÍ'::text
            ELSE 'NO'::text
        END AS activa,
        CASE
            WHEN p.has_experience IS FALSE THEN 'X'::text
            ELSE ' '::text
        END AS "hasExperienceNo",
        CASE
            WHEN p.has_experience IS FALSE THEN ' '::text
            ELSE 'X'::text
        END AS "hasExperienceYes",
    COALESCE(c_exp.country_name, ''::character varying) AS paisx,
        CASE
            WHEN (p.recx -> 'projects_q'::text) IS NULL THEN ''::text
            WHEN jsonb_typeof(p.recx -> 'projects_q'::text) = 'null'::text THEN ''::text
            ELSE p.recx ->> 'projects_q'::text
        END AS proj_q,
        CASE
            WHEN (p.recx ->> 'role'::text) = '1'::text THEN 'Subcontratista'::text
            WHEN (p.recx ->> 'role'::text) = '2'::text THEN 'Contratista Principal'::text
            WHEN (p.recx ->> 'role'::text) = '3'::text THEN 'Ambos'::text
            ELSE ''::text
        END AS role,
        CASE
            WHEN (p.recx ->> 'executed_q'::text) = '1'::text THEN '< 100.000 USD'::text
            WHEN (p.recx ->> 'executed_q'::text) = '2'::text THEN '100.000 - 1.000.000 USD'::text
            WHEN (p.recx ->> 'executed_q'::text) = '3'::text THEN '1.000.001 - 10.000.000 USD'::text
            ELSE ''::text
        END AS montox,
    replace(COALESCE(p.recx ->> 'expemployees_q'::text, ''::text), 'null'::text, ''::text) AS expemployees,
    replace(COALESCE(p.recx ->> 'main_clients'::text, ''::text), 'null'::text, ''::text) AS clients
   FROM ( SELECT t.empresa_id,
            t.has_offices,
            t.has_experience,
            o.rec,
            t.experience_data::jsonb -> (o.ord - 1)::integer AS recx
           FROM presences t
             CROSS JOIN LATERAL jsonb_array_elements(t.office_data::jsonb) WITH ORDINALITY o(rec, ord)) p
     LEFT JOIN empresas e ON e.id = p.empresa_id
     LEFT JOIN countries c ON c.id = ((p.rec ->> 'country_id'::text)::integer)
     LEFT JOIN countries c_exp ON c_exp.id = ((p.recx ->> 'expcountry_id'::text)::integer)
  ORDER BY p.empresa_id
SQL,
    'ResourceView' => <<<'SQL'
 SELECT a.empresa_id AS id,
    max(empresas.name::text) AS name,
    sum(
        CASE
            WHEN e.employee_type = 0 THEN e.junior_q
            ELSE 0
        END) AS "Bachilleres_Junior",
    sum(
        CASE
            WHEN e.employee_type = 0 THEN e.medium_q
            ELSE 0
        END) AS "Bachilleres_Medium",
    sum(
        CASE
            WHEN e.employee_type = 0 THEN e.senior_q
            ELSE 0
        END) AS "Bachilleres_Senior",
    sum(
        CASE
            WHEN e.employee_type = 1 THEN e.junior_q
            ELSE 0
        END) AS "Tecnicos_Junior",
    sum(
        CASE
            WHEN e.employee_type = 1 THEN e.medium_q
            ELSE 0
        END) AS "Tecnicos_Medium",
    sum(
        CASE
            WHEN e.employee_type = 1 THEN e.senior_q
            ELSE 0
        END) AS "Tecnicos_Senior",
    sum(
        CASE
            WHEN e.employee_type = 2 THEN e.junior_q
            ELSE 0
        END) AS "Ingenieros_Junior",
    sum(
        CASE
            WHEN e.employee_type = 2 THEN e.medium_q
            ELSE 0
        END) AS "Ingenieros_Medium",
    sum(
        CASE
            WHEN e.employee_type = 2 THEN e.senior_q
            ELSE 0
        END) AS "Ingenieros_Senior",
    sum(
        CASE
            WHEN e.employee_type = 3 THEN e.junior_q
            ELSE 0
        END) AS "Administrativos_Junior",
    sum(
        CASE
            WHEN e.employee_type = 3 THEN e.medium_q
            ELSE 0
        END) AS "Administrativos_Medium",
    sum(
        CASE
            WHEN e.employee_type = 3 THEN e.senior_q
            ELSE 0
        END) AS "Administrativos_Senior",
    sum(
        CASE
            WHEN e.employee_type = 4 THEN e.junior_q
            ELSE 0
        END) AS "Gerentes_Junior",
    sum(
        CASE
            WHEN e.employee_type = 4 THEN e.medium_q
            ELSE 0
        END) AS "Gerentes_Medium",
    sum(
        CASE
            WHEN e.employee_type = 4 THEN e.senior_q
            ELSE 0
        END) AS "Gerentes_Senior",
    sum(
        CASE
            WHEN e.employee_type = 5 THEN e.junior_q
            ELSE 0
        END) AS "Directivos_Junior",
    sum(
        CASE
            WHEN e.employee_type = 5 THEN e.medium_q
            ELSE 0
        END) AS "Directivos_Medium",
    sum(
        CASE
            WHEN e.employee_type = 5 THEN e.senior_q
            ELSE 0
        END) AS "Directivos_Senior",
    sum(e.tfila) AS "Total"
   FROM assets a
     JOIN LATERAL jsonb_to_recordset(a.employee::jsonb) e(tfila integer, junior_q integer, medium_q integer, senior_q integer, employee_type integer) ON true
     JOIN empresas ON a.empresa_id = empresas.id
  GROUP BY a.empresa_id
SQL,
    'SustainabilityView' => <<<'SQL'
 SELECT empresas.id,
    empresas.name,
    string_agg(DISTINCT sectors.name::text, ', '::text) AS "Sector",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 1 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Maximizacion",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 2 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Creacion",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 3 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Energias",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 4 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Funcionalidad",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 5 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Participacion",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 6 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Fomento",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 7 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Reorientacion",
        CASE
            WHEN (( SELECT count(sustainabilities_1.areas_id) AS count
               FROM sustainabilities sustainabilities_1
              WHERE sustainabilities_1.areas_id = 8 AND sustainabilities_1.empresa_id = empresas.id)) > 0 THEN 'Sí'::text
            ELSE 'No'::text
        END AS "Desarrollo"
   FROM empresas
     LEFT JOIN sustainabilities ON empresas.id = sustainabilities.empresa_id
     LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
     LEFT JOIN services ON empresa_sector_service.service_id = services.id
     LEFT JOIN sectors ON services.sectors_id = sectors.id
  GROUP BY empresas.id, empresas.name
SQL,
];
