-- Seed BHN's machine hierarchy into the shared `core` schema (company 4).
--
-- BHN's operational tables (factory_conversion, factory_exit, usage…) carry the
-- factory + line NAMES; the BEFORE INSERT/UPDATE triggers resolve them to core
-- ids via machine_map_* (rebuilt from core by gds:rebuild-machine-maps after
-- this seed). BHN has one factory ("Factory 1") and 14 lines; their names don't
-- collide with BIL's. Machine-level projects are NOT seeded here: 3 of BHN's
-- machine names collide with existing core.machine_projects and that table's
-- name is globally unique — deferred pending a disambiguation decision.
--
-- Idempotent: safe to re-run (guards + INSERT IGNORE on the unique name).

-- One factory for BHN (company 4). Guarded so a re-run doesn't duplicate.
INSERT INTO `core`.`factories` (`company_id`, `name`, `code`, `is_active`, `created_at`, `updated_at`)
SELECT 4, 'Factory 1', 'Factory 1', 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `core`.`factories` WHERE `company_id` = 4 AND `code` = 'Factory 1'
);

SET @fid := (SELECT `id` FROM `core`.`factories` WHERE `company_id` = 4 AND `code` = 'Factory 1' LIMIT 1);

-- The 14 lines (name = legacy linename; code = legacy lineCodeSP, informational).
-- legacy_alias left NULL so a legacy code can't collide with a BIL line alias;
-- BHN's operational tables resolve lines by name.
INSERT IGNORE INTO `core`.`machine_lines` (`factory_id`, `name`, `code`, `is_active`, `created_at`, `updated_at`) VALUES
(@fid, 'Rewinder A',    'X5 A',       1, NOW(), NOW()),
(@fid, 'Rewinder C',    'X5 C',       1, NOW(), NOW()),
(@fid, 'Rewinder Ch 1', 'Rew 2900',   1, NOW(), NOW()),
(@fid, 'Rewinder Ch 2', 'Rew 2800',   1, NOW(), NOW()),
(@fid, 'Rewinder Ch 3', 'Rew 1800',   1, NOW(), NOW()),
(@fid, 'Napkin 1',      'OM 1',       1, NOW(), NOW()),
(@fid, 'Napkin 2',      'OM 2',       1, NOW(), NOW()),
(@fid, 'Napkin 3',      'OM 3',       1, NOW(), NOW()),
(@fid, 'Napkin 4',      'Folder 8.2', 1, NOW(), NOW()),
(@fid, 'Handkerchief',  'Handker',    1, NOW(), NOW()),
(@fid, 'Dishcloth',     'Folding 1',  1, NOW(), NOW()),
(@fid, 'Facial 1',      'DCM 1',      1, NOW(), NOW()),
(@fid, 'Rewinder 8',    'Rew8',       1, NOW(), NOW()),
(@fid, 'Amaco 1',       'Amaco 1',    1, NOW(), NOW());
