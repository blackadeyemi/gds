-- BHN-specific schema divergences, applied AFTER cloning BIL's modern structure
-- into the `bhn` schema (see database/bhn/README.md). Idempotent-ish: safe to
-- re-run only once per object; guard manually if re-provisioning.
--
-- The Abuja legacy carries data BIL's modern constraints don't allow:
--   products.productcode is UNIQUE in BIL, but BHN's real history reused one code
--   (FGSO0001) for two different active products, both referenced by productid
--   across 88k+ operational rows. Neither row can be dropped, so BHN relaxes the
--   unique index to a plain index.

ALTER TABLE `bhn`.`products`
  DROP INDEX `productcode`,
  ADD INDEX `products_productcode_idx` (`productcode`);
