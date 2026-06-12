<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePresenceView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
        CREATE VIEW PresenceView AS
        SELECT
            p.empresa_id AS id,
            COALESCE(e.name, '') AS name,
            CASE WHEN p.has_offices = 0 THEN 'X' ELSE ' ' END AS hasOfficesNo,
            CASE WHEN p.has_offices = 0 THEN ' ' ELSE 'X' END AS hasOfficesYes,
            COALESCE(c.country_name, '') AS pais,
            COALESCE(REPLACE(p.rec->>'$.offices_surf', 'null', ''), '') AS mts,
            COALESCE(REPLACE(p.rec->>'$.employees_q', 'null', ''), '') AS emp_q,
            CASE WHEN p.rec->>'$.status' = 'true' THEN 'SÍ' ELSE 'NO' END AS activa,
            CASE WHEN p.has_experience = 0 THEN 'X' ELSE ' ' END AS hasExperienceNo,
            CASE WHEN p.has_experience = 0 THEN ' ' ELSE 'X' END AS hasExperienceYes,
            COALESCE(c_exp.country_name, '') AS paisx,
            COALESCE(p.recx->>'$.projects_q', '') AS proj_q,
            CASE
                WHEN p.recx->>'$.role' = 1 THEN 'Subcontratista'
                WHEN p.recx->>'$.role' = 2 THEN 'Contratista Principal'
                WHEN p.recx->>'$.role' = 3 THEN 'Ambos'
                ELSE ''
            END AS role,
            CASE
                WHEN p.recx->>'$.executed_q' = 1 THEN '< 100.000 USD'
                WHEN p.recx->>'$.executed_q' = 2 THEN '100.000 - 1.000.000 USD'
                WHEN p.recx->>'$.executed_q' = 3 THEN '1.000.001 - 10.000.000 USD'
                ELSE ''
            END AS montox,
            COALESCE(REPLACE(p.recx->>'$.expemployees_q', 'null', ''), '') AS expemployees,
            COALESCE(REPLACE(p.recx->>'$.main_clients', 'null', ''), '') AS clients
        FROM (
            SELECT
                t.empresa_id,
                t.has_offices,
                t.has_experience,
                t.experience_data,
                JSON_EXTRACT(t.office_data, CONCAT('$[', x.idx, ']')) AS rec,
                JSON_EXTRACT(t.experience_data, CONCAT('$[', x.idx, ']')) AS recx
            FROM presences t
            INNER JOIN (
               SELECT ROW_NUMBER() OVER () - 1 AS idx 
            FROM (
                SELECT DISTINCT t.id
                FROM empresas t
                CROSS JOIN json_table(t.customers_country, '$[*]' COLUMNS (id INT PATH '$')) AS jc
            ) AS subquery
            ) AS x ON JSON_EXTRACT(t.office_data, CONCAT('$[', x.idx, ']')) IS NOT NULL
        ) p
            LEFT JOIN empresas e ON e.id = p.empresa_id
            LEFT JOIN countries c ON c.id = p.rec->>'$.country_id'
            LEFT JOIN countries c_exp ON c_exp.id = p.recx->>'$.expcountry_id'
        ORDER BY p.empresa_id;

        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS PresenceView');
    }
};
