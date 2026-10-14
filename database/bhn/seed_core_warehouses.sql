-- Seed BHN's warehouses + gates into the shared `core` schema (company 4).
--
-- BHN operates one Abuja site. Its data references three warehouses by legacy
-- code: FG/sales store '01' (sales_warehouse "Abuja"; orders, bundles, floors),
-- dispatch store '03' (the cagerooms CGR04-10/CGR20 used in loadings), and one
-- raw-materials store (legacy_location_id = 1, "Abuja"). Gates follow BIL's
-- model: cagerooms are warehouse OUT gates (legacy_name = CGRxx), stores have an
-- IN gate, the factory has in/out gates. Names are globally unique, so BHN gets
-- a "BHN " prefix; resolution is by legacy_name (not unique), scoped by company.
--
-- NOT seeded: per-user gate grants (warehouse_gate_user / factory_gate_user) —
-- those are assigned in the gds admin UI per staff member/role.
-- Idempotent: every row is guarded by its business key / unique name.

/* ----------------------------- warehouses ----------------------------- */

-- '01' FG + sales store. Reuse an existing company-4 FG depot if one is already
-- there (dev has "BHN Depot 1" from the BIL->BHN transfer work); else create it.
UPDATE `core`.`warehouses`
   SET `legacy_sales_code` = '01'
 WHERE `company_id` = 4 AND `module` = 'finished-goods'
   AND `legacy_sales_code` IS NULL AND `code` = 'BHN-D1';

INSERT INTO `core`.`warehouses` (`company_id`,`module`,`legacy_sales_code`,`name`,`code`,`is_active`,`created_at`,`updated_at`)
SELECT 4,'finished-goods','01','Abuja FG Store','BHN-FG01',1,NOW(),NOW()
 WHERE NOT EXISTS (SELECT 1 FROM `core`.`warehouses` WHERE `company_id`=4 AND `legacy_sales_code`='01');

-- '03' dispatch store (cagerooms / loading).
INSERT INTO `core`.`warehouses` (`company_id`,`module`,`legacy_sales_code`,`name`,`code`,`is_active`,`created_at`,`updated_at`)
SELECT 4,'finished-goods','03','Abuja Dispatch','BHN-FG03',1,NOW(),NOW()
 WHERE NOT EXISTS (SELECT 1 FROM `core`.`warehouses` WHERE `company_id`=4 AND `legacy_sales_code`='03');

-- Raw-materials store (legacy_location_id = 1).
INSERT INTO `core`.`warehouses` (`company_id`,`module`,`legacy_location_id`,`name`,`code`,`is_active`,`created_at`,`updated_at`)
SELECT 4,'raw-materials',1,'Abuja Raw Materials','BHN-RM1',1,NOW(),NOW()
 WHERE NOT EXISTS (SELECT 1 FROM `core`.`warehouses` WHERE `company_id`=4 AND `module`='raw-materials' AND `legacy_location_id`=1);

SET @w01 := (SELECT `id` FROM `core`.`warehouses` WHERE `company_id`=4 AND `legacy_sales_code`='01' LIMIT 1);
SET @w03 := (SELECT `id` FROM `core`.`warehouses` WHERE `company_id`=4 AND `legacy_sales_code`='03' LIMIT 1);
SET @wrm := (SELECT `id` FROM `core`.`warehouses` WHERE `company_id`=4 AND `module`='raw-materials' AND `legacy_location_id`=1 LIMIT 1);
SET @f1  := (SELECT `id` FROM `core`.`factories`  WHERE `company_id`=4 AND `code`='Factory 1' LIMIT 1);

/* --------------------------- warehouse gates --------------------------- */
-- FG store IN gate.
INSERT INTO `core`.`warehouse_gates` (`warehouse_id`,`name`,`direction`,`legacy_name`,`is_active`,`created_at`,`updated_at`)
SELECT @w01,'BHN FG Store Gate 1','in','FG Store Gate 1',1,NOW(),NOW()
 WHERE NOT EXISTS (SELECT 1 FROM `core`.`warehouse_gates` WHERE `name`='BHN FG Store Gate 1');

-- Dispatch cagerooms as OUT gates (legacy_name = the CGR code used in loadings).
INSERT INTO `core`.`warehouse_gates` (`warehouse_id`,`name`,`direction`,`legacy_name`,`is_active`,`created_at`,`updated_at`)
SELECT g.w, g.n, g.d, g.l, 1, NOW(), NOW() FROM (
    SELECT @w03 AS w,'BHN Cageroom 4'  AS n,'out' AS d,'CGR04' AS l UNION ALL
    SELECT @w03,'BHN Cageroom 5','out','CGR05' UNION ALL
    SELECT @w03,'BHN Cageroom 6','out','CGR06' UNION ALL
    SELECT @w03,'BHN Cageroom 7','out','CGR07' UNION ALL
    SELECT @w03,'BHN Cageroom 8','out','CGR08' UNION ALL
    SELECT @w03,'BHN Cageroom 9','out','CGR09' UNION ALL
    SELECT @w03,'BHN Cageroom 10','out','CGR10' UNION ALL
    SELECT @w03,'BHN Cageroom 20','out','CGR20'
) g
WHERE NOT EXISTS (SELECT 1 FROM `core`.`warehouse_gates` wg WHERE wg.`name` = g.n);

-- RM store in/out gates.
INSERT INTO `core`.`warehouse_gates` (`warehouse_id`,`name`,`direction`,`legacy_name`,`is_active`,`created_at`,`updated_at`)
SELECT g.w, g.n, g.d, g.l, 1, NOW(), NOW() FROM (
    SELECT @wrm AS w,'BHN RM Entrance' AS n,'in'  AS d, NULL AS l UNION ALL
    SELECT @wrm,'BHN RM Exit','out', NULL
) g
WHERE NOT EXISTS (SELECT 1 FROM `core`.`warehouse_gates` wg WHERE wg.`name` = g.n);

/* ---------------------------- factory gates ---------------------------- */
INSERT INTO `core`.`factory_gates` (`factory_id`,`name`,`direction`,`legacy_name`,`is_active`,`created_at`,`updated_at`)
SELECT g.f, g.n, g.d, g.l, 1, NOW(), NOW() FROM (
    SELECT @f1 AS f,'BHN Factory 1 Entrance' AS n,'in'  AS d,'Gate 1' AS l UNION ALL
    SELECT @f1,'BHN Factory 1 Exit','out','Gate 1'
) g
WHERE NOT EXISTS (SELECT 1 FROM `core`.`factory_gates` fg WHERE fg.`name` = g.n);
