<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Corrige un bug real de datos: varias vistas de reporte tenian un CASE que asumia el valor
 * del rango MAS ALTO (ej. "> 10.000.001 USD") cuando la empresa nunca habia seleccionado nada
 * en el select correspondiente (columna nullable en la BD), en vez de mostrar vacio/NULL como
 * ya hacian correctamente otras columnas hermanas (ESTADO/CAPITAL/ORIGEN en FinanceView).
 * Detectado primero en el Reporte de Capacidad Financiera (FinanceView.BILLING) y confirmado
 * en el mismo patron en ExperienceView.magnitud, MachineryView y InventoryView (rangos de
 * cantidad/valor estimado), y FacilityView.*_own (bug aun mas obvio: el ELSE devolvia el
 * texto de un rango numerico ("> 100") para un campo que en realidad es Propia/Alquilada/Ambas).
 *
 * Todas son vistas de solo consulta (no se tocan datos), asi que el fix es simplemente
 * recrearlas con el CASE corregido (WHEN explicito para el ultimo tramo + ELSE NULL).
 */
return new class extends Migration
{
    public function up()
    {
        // ============================================================
        // FinanceView — bug original reportado por el cliente.
        // ============================================================
        DB::statement('DROP VIEW IF EXISTS FinanceView');
        DB::statement("
            CREATE VIEW FinanceView AS
            select
                empresas.id as id, empresas.name, GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as Sector,

                max((SELECT sum(resultj) +  sum(resultm) + sum(results)
                FROM assets,
                JSON_TABLE(
                    `employee`,
                    '\$[*]' COLUMNS(
                        NESTED PATH '\$.junior_q' COLUMNS (resultj DECIMAL PATH '\$'),
                        NESTED PATH '\$.medium_q' COLUMNS (resultm DECIMAL PATH '\$'),
                        NESTED PATH '\$.senior_q' COLUMNS (results DECIMAL PATH '\$')
                    )
                ) AS TOT_RH  where assets.empresa_id = empresas.id))  as rrhh,

                MAX(CASE
                    WHEN billing_id = '1' THEN '< 100.000 USD'
                    WHEN billing_id = '2' THEN '100.001 - 1.000.000 USD'
                    WHEN billing_id = '3' THEN '1.000.001 - 10.000.000 USD'
                    WHEN billing_id = '4' THEN '> 10.000.001 USD'
                    ELSE NULL
                END) AS BILLING,

                MAX(CASE
                    WHEN status_id = '1' THEN 'Activa'
                    WHEN status_id = '0' THEN 'Inactiva'
                    ELSE NULL
                END) AS ESTADO,

                MAX(CASE
                    WHEN property_id = '1' THEN 'Privado'
                    WHEN property_id = '0' THEN 'Publico'
                    ELSE NULL
                END) AS CAPITAL,

                MAX(CASE
                    WHEN origin_id = '1' THEN 'Nacional'
                    WHEN origin_id = '0' THEN 'Internacional'
                    ELSE NULL
                END) AS ORIGEN

            from
                empresas

                left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                left join services on empresa_sector_service.service_id = services.id
                left join sectors on services.sectors_id = sectors.id

            GROUP BY 1, 2
        ");

        // ============================================================
        // ExperienceView — 'magnitud' (Orden de Magnitud del Contrato) tenia el mismo bug.
        // ============================================================
        DB::statement('DROP VIEW IF EXISTS ExperienceView');
        DB::statement("
            CREATE VIEW ExperienceView AS
            SELECT
                empresa_id as id,
                (select empresas.name from empresas where id = empresa_id) as name,
                (select infrasectors.sector_name from infrasectors where id = json_unquote(json_extract(rec, '\$.data.infrasectors_id'))) as sectorind,
                (select infratypes.type_name from infratypes where id = json_unquote(json_extract(rec, '\$.data.infratypes_id'))) as tipoind,
                (select infrasystems.system_name from infrasystems where id = json_unquote(json_extract(rec, '\$.data.infrasystems_id'))) as systemind,
                (select infraregions.region_name from infraregions where id = json_unquote(json_extract(rec, '\$.data.infraregions_id'))) as regionind,
                (select infrafacilities.facility_name from infrafacilities where id = json_unquote(json_extract(rec, '\$.data.infrafacilities_id'))) as facilityind,

                (select sectors.name from sectors where id = json_unquote(json_extract(rec, '\$.data.sectors_id'))) as sector,

                (select GROUP_CONCAT(services.name, ' ') from services where id in
                (SELECT JSON_EXTRACT(json_unquote(json_extract(rec, '\$.data.services_id')), CONCAT('\$[', idx, ']'))
                    FROM ( SELECT 0 idx UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) src)) as service,

                json_unquote(json_extract(rec, '\$.data.exp_year')) ano,

                CASE json_unquote(json_extract(rec, '\$.data.magnitud'))
                    WHEN '1' THEN '< 100.000 USD'
                    WHEN '2' THEN '100.001 - 1.000.000 USD'
                    WHEN '3' THEN '1.000.001 - 10.000.000 USD'
                    WHEN '4' THEN '> 10.000.001 USD'
                    ELSE NULL
                END AS magnitud,

                REPLACE(json_unquote(json_extract(rec, '\$.data.prof_tech')), 'null', '') prof_tech,
                REPLACE(json_unquote(json_extract(rec, '\$.data.manpower')), 'null', '') manpower,

                json_unquote(json_extract(rec, '\$.data.Descripcion')) descripcion
            FROM (
                SELECT t.empresa_id,

                JSON_EXTRACT(t.exp_year, CONCAT('\$[', x.idx, ']')) AS rec
                FROM
                    experiences t
                    INNER JOIN (
                        SELECT ROW_NUMBER() OVER () - 1 AS idx
                        FROM (
                            SELECT DISTINCT t.id
                            FROM empresas t
                            CROSS JOIN json_table(t.customers_country, '\$[*]' COLUMNS (id INT PATH '\$')) AS jc
                        ) AS subquery
                    )
                AS x ON JSON_EXTRACT(t.exp_year, CONCAT('\$[', x.idx, ']')) IS NOT NULL

                left join experiences on experiences.empresa_id = t.id
                ) z

            ORDER BY empresa_id
        ");

        // ============================================================
        // MachineryView — cantidad y valor estimado por categoria, mismo bug en las 9 categorias.
        // ============================================================
        DB::statement('DROP VIEW IF EXISTS MachineryView');
        DB::statement("
            CREATE VIEW MachineryView AS
            SELECT
                a.empresa_id as id,
                max(empresas.name) as name,
                MAX(CASE WHEN e.machinery_name = 0 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Equip_med_lev_qua,
                MAX(CASE WHEN e.machinery_name = 0 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Equip_med_lev_est,
                MAX(CASE WHEN e.machinery_name = 1 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Equip_mar_flu_qua,
                MAX(CASE WHEN e.machinery_name = 1 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Equip_mar_flu_est,
                MAX(CASE WHEN e.machinery_name = 2 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Mov_terr_cons_qua,
                MAX(CASE WHEN e.machinery_name = 2 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Mov_terr_cons_est,
                MAX(CASE WHEN e.machinery_name = 3 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Equip_men_cons_qua,
                MAX(CASE WHEN e.machinery_name = 3 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Equip_men_cons_est,
                MAX(CASE WHEN e.machinery_name = 4 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Fab_metal_elec_qua,
                MAX(CASE WHEN e.machinery_name = 4 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Fab_metal_elec_est,
                MAX(CASE WHEN e.machinery_name = 5 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Mont_elec_meca_qua,
                MAX(CASE WHEN e.machinery_name = 5 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Mont_elec_meca_est,
                MAX(CASE WHEN e.machinery_name = 6 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Maq_herr_meca_qua,
                MAX(CASE WHEN e.machinery_name = 6 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Maq_herr_meca_est,
                MAX(CASE WHEN e.machinery_name = 7 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Almac_trans_qua,
                MAX(CASE WHEN e.machinery_name = 7 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Almac_trans_est,
                MAX(CASE WHEN e.machinery_name = 8 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Serv_poz_inst_qua,
                MAX(CASE WHEN e.machinery_name = 8 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS Serv_poz_inst_est

            FROM assets a
            CROSS JOIN JSON_TABLE(
                a.machinery,
                '\$[*]' COLUMNS(
                    machinery_est INT PATH '\$.machinery_est',
                    machinery_qid INT PATH '\$.machinery_qid',
                    machinery_name INT PATH '\$.machinery_name'
                )
            ) e
            join empresas on a.empresa_id = empresas.id

            GROUP BY a.empresa_id

            ORDER BY id
        ");

        // ============================================================
        // FacilityView — '*_own' (Tipo de Propiedad) tenia un ELSE aun mas incorrecto:
        // devolvia el texto de un rango numerico ('> 100') en un campo que es Propia/Alquilada/Ambas.
        // ============================================================
        DB::statement('DROP VIEW IF EXISTS FacilityView');
        DB::statement("
            CREATE VIEW FacilityView AS
            SELECT
                a.empresa_id as id,
                max(empresas.name) as name,
                MAX(CASE WHEN e.facility_type = 0 THEN e.facility_q ELSE ' ' END) AS Oficinas_q,
                MAX(CASE WHEN e.facility_type = 0 THEN e.facility_surf ELSE ' ' END) AS Oficinas_surf,
                MAX(CASE WHEN e.facility_type = 0 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Oficinas_own,
                MAX(CASE WHEN e.facility_type = 1 THEN e.facility_q ELSE ' ' END) AS Talleres_q,
                MAX(CASE WHEN e.facility_type = 1 THEN e.facility_surf ELSE ' ' END) AS Talleres_surf,
                MAX(CASE WHEN e.facility_type = 1 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Talleres_own,
                MAX(CASE WHEN e.facility_type = 2 THEN e.facility_q ELSE ' ' END) AS Manufactura_q,
                MAX(CASE WHEN e.facility_type = 2 THEN e.facility_surf ELSE ' ' END) Manufactura_surf,
                MAX(CASE WHEN e.facility_type = 2 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Manufactura_own,
                MAX(CASE WHEN e.facility_type = 3 THEN e.facility_q ELSE ' ' END) AS Almacenes_q,
                MAX(CASE WHEN e.facility_type = 3 THEN e.facility_surf ELSE ' ' END) AS Almacenes_surf,
                MAX(CASE WHEN e.facility_type = 3 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Almacenes_own,
                MAX(CASE WHEN e.facility_type = 4 THEN e.facility_q ELSE ' ' END) AS Laboratorios_q,
                MAX(CASE WHEN e.facility_type = 4 THEN e.facility_surf ELSE ' ' END) AS Laboratorios_surf,
                MAX(CASE WHEN e.facility_type = 4 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Laboratorios_own,
                MAX(CASE WHEN e.facility_type = 5 THEN e.facility_q ELSE ' ' END) AS Marinas_q,
                MAX(CASE WHEN e.facility_type = 5 THEN e.facility_surf ELSE ' ' END) AS Marinas_surf,
                MAX(CASE WHEN e.facility_type = 5 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Marinas_own,
                MAX(CASE WHEN e.facility_type = 6 THEN e.facility_q ELSE ' ' END) AS Otros_q,
                MAX(CASE WHEN e.facility_type = 6 THEN e.facility_surf ELSE ' ' END) AS Otros_surf,
                MAX(CASE WHEN e.facility_type = 6 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS Otros_own
            FROM assets a
            CROSS JOIN JSON_TABLE(
                a.facility,
                '\$[*]' COLUMNS(
                    facility_q INT PATH '\$.facility_q',
                    facility_own INT PATH '\$.facility_own',
                    facility_surf INT PATH '\$.facility_surf',
                    facility_type INT PATH '\$.facility_type'
                )
            ) e
            join empresas on a.empresa_id = empresas.id

            GROUP BY a.empresa_id
        ");

        // ============================================================
        // InventoryView — cantidad y valor estimado por categoria, mismo bug que Machinery.
        // ============================================================
        DB::statement('DROP VIEW IF EXISTS InventoryView');
        DB::statement("
            CREATE VIEW InventoryView AS
            SELECT
                a.empresa_id as id,
                max(empresas.name) as name,
                MAX(CASE WHEN e.inventory_name = 0 THEN (CASE WHEN e.inventory_q = 1 THEN '1 - 10' WHEN e.inventory_q = 2 THEN '11 - 50' WHEN e.inventory_q = 3 THEN '51 - 100' WHEN e.inventory_q = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Materia_q,
                MAX(CASE WHEN e.inventory_name = 0 THEN (CASE WHEN e.inventory_est = 1 THEN '< 100.000 USD' WHEN e.inventory_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.inventory_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.inventory_est = 4 THEN '> 10.000.000 USD' ELSE NULL END) ELSE ' ' END) AS Materia_est,
                MAX(CASE WHEN e.inventory_name = 0 THEN e.inventory_unit ELSE ' ' END) AS Materia_unit,
                MAX(CASE WHEN e.inventory_name = 1 THEN (CASE WHEN e.inventory_q = 1 THEN '1 - 10' WHEN e.inventory_q = 2 THEN '11 - 50' WHEN e.inventory_q = 3 THEN '51 - 100' WHEN e.inventory_q = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS Producto_q,
                MAX(CASE WHEN e.inventory_name = 1 THEN (CASE WHEN e.inventory_est = 1 THEN '< 100.000 USD' WHEN e.inventory_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.inventory_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.inventory_est = 4 THEN '> 10.000.000 USD' ELSE NULL END) ELSE ' ' END) AS Producto_est,
                MAX(CASE WHEN e.inventory_name = 1 THEN e.inventory_unit ELSE ' ' END) AS Producto_unit

            FROM assets a
            CROSS JOIN JSON_TABLE(
                a.inventory,
                '\$[*]' COLUMNS(
                    inventory_q INT PATH '\$.inventory_q',
                    inventory_est INT PATH '\$.inventory_est',
                    inventory_unit TEXT PATH '\$.inventory_unit',
                    inventory_name TINYTEXT PATH '\$.inventory_name'
                )
            ) e
            join empresas on a.empresa_id = empresas.id

            GROUP BY a.empresa_id
        ");
    }

    public function down()
    {
        // No hay una version "anterior" util a la que volver (el bug original asumia valores
        // incorrectos) — down() no reintroduce el bug, solo deja las vistas como estan.
    }
};
