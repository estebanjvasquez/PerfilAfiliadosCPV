-- Re-create the four foreign keys that defer_four_fks.patch removed from the
-- creating migrations, AFTER the replay has created every referenced table.
--
-- Nothing is weakened: same columns, same referenced columns, same names
-- Laravel generates ({table}_{column}_foreign - empresas_city_id_foreign is
-- the exact name PostgreSQL reported when the replay aborted), and the same
-- default referential action, which for both ->constrained() and
-- ->references()->on() without a modifier is NO ACTION.
--
-- Run with -v ON_ERROR_STOP=1. Each statement must succeed; a constraint that
-- cannot be created is a failure of the schema preparation, not something to
-- skip. IF NOT EXISTS is deliberately NOT used: on a fixture that starts empty,
-- an already-present constraint would mean something else created it.

ALTER TABLE empresas
  ADD CONSTRAINT empresas_city_id_foreign
  FOREIGN KEY (city_id) REFERENCES cities (id);

ALTER TABLE sustainabilities
  ADD CONSTRAINT sustainabilities_areas_id_foreign
  FOREIGN KEY (areas_id) REFERENCES areas (id);

ALTER TABLE infratypes
  ADD CONSTRAINT infratypes_infrasectors_id_foreign
  FOREIGN KEY (infrasectors_id) REFERENCES infrasectors (id);

ALTER TABLE infrafacilities
  ADD CONSTRAINT infrafacilities_infraregion_infrasystem_id_foreign
  FOREIGN KEY (infraregion_infrasystem_id) REFERENCES infraregion_infrasystem (id);
