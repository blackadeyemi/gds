-- Prune the BHN staging schema to company 2 (BHN) only.
--
-- The Abuja legacy DB is SHARED between two companies (its internal `company`
-- table: 1 = BIL ABUJA, 2 = BHN). BIL ABUJA is already part of the BIL company
-- we model; moving its data into the `bil` db is separate work. The `bhn` schema
-- must hold ONLY BHN's rows, so we delete company 1 here, before the
-- column-intersection refresh reads staging.
--
-- Runs with the staging schema as the default DB (see legacy_refresh_bhn
-- post_stage_sql). Order matters: company-tagged parents first, then the
-- detail/transaction children they orphan. Line names are SHARED across both
-- companies, so conversion setup/history (keyed only by line name) can't be
-- scoped and is excluded from the refresh map instead of pruned here.

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Company-tagged tables -> keep only company 2.
DELETE FROM factory_exit                WHERE company_id <> 2;
DELETE FROM factory_production          WHERE company_id <> 2;
DELETE FROM factory_usage_reel          WHERE company_id <> 2;
DELETE FROM factory_usage_rawmaterials  WHERE company_id <> 2;
DELETE FROM factoryentrance_rawmaterial WHERE company_id <> 2;
DELETE FROM factory_entrance_reel       WHERE company_id <> 2;
DELETE FROM rawmaterials                WHERE company_id <> 2;
DELETE FROM rawmaterials_store_exit     WHERE company_id <> 2;
DELETE FROM store_entrance              WHERE company_id <> 2;
DELETE FROM jumboreel_stock             WHERE company_id <> 2;
DELETE FROM sales_order                 WHERE company_id <> 2;
DELETE FROM sales_customers             WHERE company_id <> 2;
DELETE FROM sales_forecast              WHERE company_id <> 2;
DELETE FROM sales_forecast_history      WHERE company_id <> 2;
DELETE FROM rawmaterials_supplier       WHERE company_id <> 2;
DELETE FROM rawmaterials_products       WHERE company_id <> 2;
DELETE FROM return_approval             WHERE company_id <> 2;
DELETE FROM reprint_approval            WHERE company_id <> 2;
DELETE FROM damagedgoods_rawmaterial    WHERE company_id <> 2;
DELETE FROM products                    WHERE company    <> 2;

-- 2) Detail / transaction children -> delete rows orphaned by step 1.
--    The sales chain cascades: order -> order_details -> loading -> delivery
--    -> waybill, plus loading_return off loading.
DELETE FROM sales_order_details
  WHERE orderid NOT IN (SELECT orderid FROM sales_order);
DELETE FROM sales_loading
  WHERE sod_id NOT IN (SELECT id FROM sales_order_details);
DELETE FROM sales_loading_return
  WHERE loading_id NOT IN (SELECT id FROM sales_loading);
DELETE FROM sales_delivery
  WHERE loadnumber NOT IN (SELECT loadnumber FROM sales_loading);
DELETE FROM sales_waybill
  WHERE deliverynumber NOT IN (SELECT deliverynumber FROM sales_delivery);

-- Store bundle stock is keyed by product -> keep only company-2 products.
DELETE FROM storebundle
  WHERE productid NOT IN (SELECT productid FROM products);
DELETE FROM storebundle_floor
  WHERE product_id NOT IN (SELECT productid FROM products);
DELETE FROM store_adjustment
  WHERE productid NOT IN (SELECT productid FROM products);

SET FOREIGN_KEY_CHECKS = 1;
