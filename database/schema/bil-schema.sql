-- gds legacy schema: bil database (STRUCTURE ONLY — no data).
-- Non-authoritative documentation of the legacy MySQL schema the app connects to.
-- Regenerate: mysqldump --no-data --skip-comments --skip-lock-tables --force --routines --events bil
-- NOTE: the 'core' DB is defined by database/migrations, not dumped here.
-- Skipped: view `bil.bpl_products` (a leftover cross-DB view from the split whose
-- underlying table now lives in the bpl DB, so it's invalid in bil and omitted).


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `actions`;
/*!50001 DROP VIEW IF EXISTS `actions`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `actions` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `request`,
 1 AS `type`,
 1 AS `date`,
 1 AS `comment`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `booking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking` (
  `id` varchar(20) NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `warehousecode` varchar(3) NOT NULL,
  `customerid` int NOT NULL,
  `date_needed` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `date` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `approved_date` varchar(11) DEFAULT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `productid` int DEFAULT NULL,
  `quantity` int NOT NULL,
  `status` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_accounts`;
/*!50001 DROP VIEW IF EXISTS `bpl_accounts`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_accounts` AS SELECT 
 1 AS `id`,
 1 AS `account`,
 1 AS `beneficiary`,
 1 AS `intermediary`,
 1 AS `correspondent`,
 1 AS `further_acc`,
 1 AS `currency_id`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_banks`;
/*!50001 DROP VIEW IF EXISTS `bpl_banks`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_banks` AS SELECT 
 1 AS `id`,
 1 AS `name`,
 1 AS `number`,
 1 AS `sortcode`,
 1 AS `swiftcode`,
 1 AS `address`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_bill_of_lading`;
/*!50001 DROP VIEW IF EXISTS `bpl_bill_of_lading`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_bill_of_lading` AS SELECT 
 1 AS `id`,
 1 AS `packing_id`,
 1 AS `number`,
 1 AS `issued_date`,
 1 AS `shipped_date`,
 1 AS `arrival_date`,
 1 AS `doc`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_customers`;
/*!50001 DROP VIEW IF EXISTS `bpl_customers`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_customers` AS SELECT 
 1 AS `id`,
 1 AS `type`,
 1 AS `customername`,
 1 AS `customerlabel`,
 1 AS `customercountry`,
 1 AS `customeraddress`,
 1 AS `customertelephone`,
 1 AS `port`,
 1 AS `fax`,
 1 AS `email`,
 1 AS `products`,
 1 AS `created_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_delivery`;
/*!50001 DROP VIEW IF EXISTS `bpl_delivery`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_delivery` AS SELECT 
 1 AS `id`,
 1 AS `customer_name`,
 1 AS `date`,
 1 AS `vechile_number`,
 1 AS `loader`,
 1 AS `driver`,
 1 AS `truck_weight`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `container_number`,
 1 AS `driver_phone`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_delivery_barcode`;
/*!50001 DROP VIEW IF EXISTS `bpl_delivery_barcode`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_delivery_barcode` AS SELECT 
 1 AS `id`,
 1 AS `delivery_barcode`,
 1 AS `status`,
 1 AS `deleted_at`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `date`,
 1 AS `full_weight`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_delivery_details`;
/*!50001 DROP VIEW IF EXISTS `bpl_delivery_details`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_delivery_details` AS SELECT 
 1 AS `id`,
 1 AS `bpl_delivery_id`,
 1 AS `barcode`,
 1 AS `product_id`,
 1 AS `delivery_barcode`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_factoryexit`;
/*!50001 DROP VIEW IF EXISTS `bpl_factoryexit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_factoryexit` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_grades`;
/*!50001 DROP VIEW IF EXISTS `bpl_grades`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_grades` AS SELECT 
 1 AS `id`,
 1 AS `gradename`,
 1 AS `type`,
 1 AS `grade`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_invoice_payments`;
/*!50001 DROP VIEW IF EXISTS `bpl_invoice_payments`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_invoice_payments` AS SELECT 
 1 AS `id`,
 1 AS `packing_id`,
 1 AS `amount`,
 1 AS `date`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_packing_list`;
/*!50001 DROP VIEW IF EXISTS `bpl_packing_list`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_packing_list` AS SELECT 
 1 AS `id`,
 1 AS `order_id`,
 1 AS `number`,
 1 AS `containers`,
 1 AS `date`,
 1 AS `split`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_payment_terms`;
/*!50001 DROP VIEW IF EXISTS `bpl_payment_terms`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_payment_terms` AS SELECT 
 1 AS `id`,
 1 AS `payment_terms`,
 1 AS `days`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_production`;
/*!50001 DROP VIEW IF EXISTS `bpl_production`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_production` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `customer_id`,
 1 AS `papermachine`,
 1 AS `product_id`,
 1 AS `hardrollnumber`,
 1 AS `barcode`,
 1 AS `brightness`,
 1 AS `corediameter`,
 1 AS `joints`,
 1 AS `paperweight`,
 1 AS `weight`,
 1 AS `status`,
 1 AS `hold`,
 1 AS `comments`,
 1 AS `dateofmanufacture`,
 1 AS `deleted_at`,
 1 AS `timestamp`,
 1 AS `net_weight`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_products`;

-- failed on view `bpl_products`: CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `bpl_products` AS select `bpl`.`bpl_products`.`id` AS `id`,`bpl`.`bpl_products`.`old` AS `old`,`bpl`.`bpl_products`.`productname` AS `productname`,`bpl`.`bpl_products`.`gradetype` AS `gradetype`,`bpl`.`bpl_products`.`brightness` AS `brightness`,`bpl`.`bpl_products`.`gsm` AS `gsm`,`bpl`.`bpl_products`.`ply` AS `ply`,`bpl`.`bpl_products`.`width` AS `width`,`bpl`.`bpl_products`.`diameter` AS `diameter`,`bpl`.`bpl_products`.`slice` AS `slice`,`bpl`.`bpl_products`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_products`

DROP TABLE IF EXISTS `bpl_proforma`;
/*!50001 DROP VIEW IF EXISTS `bpl_proforma`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_proforma` AS SELECT 
 1 AS `order_id`,
 1 AS `customer_ref`,
 1 AS `freight`,
 1 AS `container`,
 1 AS `freight_price`,
 1 AS `terms`,
 1 AS `shipment`,
 1 AS `payment_term_id`,
 1 AS `nxp`,
 1 AS `currency_id`,
 1 AS `account_id`,
 1 AS `date`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_proforma_items`;
/*!50001 DROP VIEW IF EXISTS `bpl_proforma_items`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_proforma_items` AS SELECT 
 1 AS `id`,
 1 AS `order_item_id`,
 1 AS `price`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_quarantine`;
/*!50001 DROP VIEW IF EXISTS `bpl_quarantine`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_quarantine` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_quarantine_storeexit`;
/*!50001 DROP VIEW IF EXISTS `bpl_quarantine_storeexit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_quarantine_storeexit` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_sales`;
/*!50001 DROP VIEW IF EXISTS `bpl_sales`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_sales` AS SELECT 
 1 AS `id`,
 1 AS `ref`,
 1 AS `username`,
 1 AS `customerid`,
 1 AS `company`,
 1 AS `date`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_sales_items`;
/*!50001 DROP VIEW IF EXISTS `bpl_sales_items`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_sales_items` AS SELECT 
 1 AS `id`,
 1 AS `order_id`,
 1 AS `productid`,
 1 AS `weight`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_softroll_factoryexit`;
/*!50001 DROP VIEW IF EXISTS `bpl_softroll_factoryexit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_softroll_factoryexit` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_softroll_production`;
/*!50001 DROP VIEW IF EXISTS `bpl_softroll_production`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_softroll_production` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `softrollnumber`,
 1 AS `grade_id`,
 1 AS `barcode`,
 1 AS `brightness`,
 1 AS `weight`,
 1 AS `grammage`,
 1 AS `diameter`,
 1 AS `status`,
 1 AS `dateofmanufacture`,
 1 AS `deleted_at`,
 1 AS `timestamp`,
 1 AS `papermachine`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_stock`;
/*!50001 DROP VIEW IF EXISTS `bpl_stock`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_stock` AS SELECT 
 1 AS `id`,
 1 AS `location_id`,
 1 AS `product_id`,
 1 AS `quantity`,
 1 AS `weight`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_stock_locations`;
/*!50001 DROP VIEW IF EXISTS `bpl_stock_locations`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_stock_locations` AS SELECT 
 1 AS `id`,
 1 AS `type`,
 1 AS `location`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_store_count`;
/*!50001 DROP VIEW IF EXISTS `bpl_store_count`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_store_count` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_storeentrance`;
/*!50001 DROP VIEW IF EXISTS `bpl_storeentrance`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_storeentrance` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_storeentrance_trash`;
/*!50001 DROP VIEW IF EXISTS `bpl_storeentrance_trash`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_storeentrance_trash` AS SELECT 
 1 AS `id`,
 1 AS `deletion_id`,
 1 AS `created_at`,
 1 AS `user`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_storeentrance_trash_details`;
/*!50001 DROP VIEW IF EXISTS `bpl_storeentrance_trash_details`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_storeentrance_trash_details` AS SELECT 
 1 AS `id`,
 1 AS `deletion_id`,
 1 AS `date_of_entrance`,
 1 AS `barcode`,
 1 AS `productname`,
 1 AS `gradetype`,
 1 AS `location`,
 1 AS `weight`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `user`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_storeexit`;
/*!50001 DROP VIEW IF EXISTS `bpl_storeexit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_storeexit` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_transporters`;
/*!50001 DROP VIEW IF EXISTS `bpl_transporters`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_transporters` AS SELECT 
 1 AS `id`,
 1 AS `transportername`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_waybill_payment`;
/*!50001 DROP VIEW IF EXISTS `bpl_waybill_payment`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_waybill_payment` AS SELECT 
 1 AS `id`,
 1 AS `barcode`,
 1 AS `transporter_id`,
 1 AS `amount`,
 1 AS `amount_paid`,
 1 AS `balance`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `customer_name`,
 1 AS `vechicle_number`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `bpl_waybill_payment_history`;
/*!50001 DROP VIEW IF EXISTS `bpl_waybill_payment_history`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `bpl_waybill_payment_history` AS SELECT 
 1 AS `id`,
 1 AS `waybill_payment_id`,
 1 AS `amount`,
 1 AS `created_at`,
 1 AS `updated`,
 1 AS `payment_date`,
 1 AS `payment_note`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `countries`;
/*!50001 DROP VIEW IF EXISTS `countries`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `countries` AS SELECT 
 1 AS `id`,
 1 AS `iso`,
 1 AS `name`,
 1 AS `nicename`,
 1 AS `iso3`,
 1 AS `numcode`,
 1 AS `phonecode`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `currencies`;
/*!50001 DROP VIEW IF EXISTS `currencies`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `currencies` AS SELECT 
 1 AS `id`,
 1 AS `code`,
 1 AS `name`,
 1 AS `symbol`,
 1 AS `short_name`,
 1 AS `decimal_unit`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `damagedgoods_rawmaterial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `damagedgoods_rawmaterial` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_name` varchar(300) NOT NULL,
  `barcode` varchar(300) NOT NULL,
  `location_id` int DEFAULT NULL,
  `entrance_date` date NOT NULL,
  `product_id` int NOT NULL,
  `weight` float NOT NULL,
  `status` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dumping_site`;
/*!50001 DROP VIEW IF EXISTS `dumping_site`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `dumping_site` AS SELECT 
 1 AS `id`,
 1 AS `dump_site`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_blocked_reel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_blocked_reel` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) NOT NULL,
  `location` varchar(50) NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `weight` float NOT NULL,
  `dateblocked` varchar(20) NOT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location` varchar(255) NOT NULL,
  `linename` varchar(255) NOT NULL,
  `sublinename` varchar(25) DEFAULT NULL,
  `linecode` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_entrance_rawmaterials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_entrance_rawmaterials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_name` varchar(300) NOT NULL,
  `barcode` varchar(300) NOT NULL,
  `location_id` int NOT NULL,
  `entrance_date` date NOT NULL,
  `product_id` int NOT NULL,
  `weight` float NOT NULL,
  `status` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fer_barcode_idx` (`barcode`),
  KEY `fer_entrance_date_idx` (`entrance_date`),
  KEY `fer_status_id_idx` (`status`,`id`),
  KEY `fer_product_id_idx` (`product_id`),
  KEY `fer_location_id_idx` (`location_id`)
) ENGINE=MyISAM AUTO_INCREMENT=178206 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_entrance_reel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_entrance_reel` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) NOT NULL,
  `location` varchar(50) NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `dateofentrance` varchar(20) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=88433 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_event`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_event` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barcode` varchar(20) NOT NULL,
  `productname` varchar(200) NOT NULL,
  `weight` double NOT NULL,
  `event` set('remain','return') NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=824 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_exit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_exit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `productid` int NOT NULL,
  `exitlocation` varchar(255) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `bundles` int NOT NULL,
  `dateofexit` varchar(20) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=1199916 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_hod`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_hod` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `division` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_lines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `factoryname` varchar(20) DEFAULT NULL,
  `linename` varchar(255) NOT NULL,
  `linecode` varchar(5) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_machine_maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_machine_maintenance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `jobtitle` varchar(100) NOT NULL,
  `jobid` varchar(50) DEFAULT NULL,
  `linename` varchar(255) NOT NULL,
  `project` varchar(100) NOT NULL,
  `subproject` varchar(100) NOT NULL,
  `division` varchar(100) NOT NULL,
  `staff` varchar(100) NOT NULL,
  `user` varchar(255) NOT NULL,
  `date` varchar(11) NOT NULL,
  `starttime` varchar(20) NOT NULL,
  `endtime` varchar(20) NOT NULL,
  `note` text NOT NULL,
  `duration` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=43402 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_machine_maintenance_comment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_machine_maintenance_comment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `user` varchar(100) NOT NULL,
  `comment` text NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_preproduction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_preproduction` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `productname` varchar(200) NOT NULL,
  `linename` varchar(50) NOT NULL,
  `bundles` int NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `linename` (`linename`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_preproduction_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_preproduction_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `linename` text NOT NULL,
  `productname` text NOT NULL,
  `quantity` int NOT NULL,
  `username` text NOT NULL,
  `date_modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25125 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_production`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_production` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `productid` int NOT NULL,
  `factory` varchar(20) NOT NULL,
  `linename` varchar(255) NOT NULL,
  `sublinename` varchar(25) DEFAULT NULL,
  `barcode` varchar(255) NOT NULL,
  `bundles` int NOT NULL,
  `specs` json DEFAULT NULL,
  `netweight` float NOT NULL DEFAULT '0',
  `grossweight` float NOT NULL DEFAULT '0',
  `dateofproduction` varchar(20) NOT NULL,
  `actualdate` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1202842 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lineid` int NOT NULL,
  `linename` varchar(255) NOT NULL,
  `sublinename` varchar(255) NOT NULL,
  `project` varchar(255) NOT NULL,
  `code` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_staff` (
  `id` int NOT NULL AUTO_INCREMENT,
  `staff_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `division` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_sublines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_sublines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lineid` int NOT NULL,
  `linename` varchar(255) NOT NULL,
  `sublinename` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_subprojects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_subprojects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lineid` int NOT NULL,
  `linename` varchar(255) NOT NULL,
  `sublinename` varchar(255) NOT NULL,
  `project` varchar(255) NOT NULL,
  `projectcode` varchar(100) NOT NULL,
  `subproject` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_transfer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_transfer` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `factoryname` varchar(50) NOT NULL,
  `dateoftransfer` varchar(20) NOT NULL,
  `origin` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_usage_rawmaterials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_usage_rawmaterials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `location` varchar(50) NOT NULL,
  `linename` varchar(50) NOT NULL,
  `project` varchar(50) NOT NULL,
  `pre_productname` varchar(255) DEFAULT NULL,
  `weight` float NOT NULL,
  `dateofuse` varchar(20) NOT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  `status` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`shift`,`barcode`) USING BTREE,
  KEY `fur_dateofuse_idx` (`dateofuse`),
  KEY `fur_stats_cover` (`dateofuse`,`is_deleted`,`barcode`,`weight`,`location`,`shift`)
) ENGINE=InnoDB AUTO_INCREMENT=123167 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_usage_rawmaterials_copy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_usage_rawmaterials_copy` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) NOT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `location` varchar(50) NOT NULL,
  `linename` varchar(50) NOT NULL,
  `project` varchar(50) NOT NULL,
  `pre_productname` varchar(255) DEFAULT NULL,
  `weight` float NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `dateofuse` varchar(20) NOT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`shift`,`barcode`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=139919 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_usage_reel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_usage_reel` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) NOT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `location` varchar(50) NOT NULL,
  `linename` varchar(50) NOT NULL,
  `project` varchar(50) NOT NULL,
  `pre_productname` varchar(255) DEFAULT NULL,
  `weight` float NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `dateofuse` varchar(20) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`shift`,`barcode`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=286480 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_waste`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_waste` (
  `id` int NOT NULL AUTO_INCREMENT,
  `causes_id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `factoryname` varchar(50) NOT NULL,
  `linename` varchar(50) NOT NULL,
  `project` varchar(50) NOT NULL,
  `pre_productname` varchar(255) DEFAULT NULL,
  `weight` float NOT NULL,
  `dateofentry` varchar(20) NOT NULL,
  `origin` varchar(20) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_waste_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factory_waste_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `causes` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factoryentrance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factoryentrance_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `factoryname` varchar(255) NOT NULL,
  `entrancelocation` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factoryentrance_rawmaterial`;
/*!50001 DROP VIEW IF EXISTS `factoryentrance_rawmaterial`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factoryentrance_rawmaterial` AS SELECT 
 1 AS `id`,
 1 AS `user_name`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `entrance_date`,
 1 AS `product_id`,
 1 AS `weight`,
 1 AS `status`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factoryexit_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factoryexit_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `factoryname` varchar(255) NOT NULL,
  `exitlocation` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fg_inter_received`;
/*!50001 DROP VIEW IF EXISTS `fg_inter_received`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `fg_inter_received` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `timestamp`,
 1 AS `productid`,
 1 AS `productcode`,
 1 AS `productname`,
 1 AS `bundle`,
 1 AS `trucknumber`,
 1 AS `to_company`,
 1 AS `from_company`,
 1 AS `dateoftransfer`,
 1 AS `barcode`,
 1 AS `status`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `fg_inter_transfer`;
/*!50001 DROP VIEW IF EXISTS `fg_inter_transfer`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `fg_inter_transfer` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `timestamp`,
 1 AS `productid`,
 1 AS `productcode`,
 1 AS `productname`,
 1 AS `bundle`,
 1 AS `trucknumber`,
 1 AS `to_company`,
 1 AS `from_company`,
 1 AS `dateoftransfer`,
 1 AS `barcode`,
 1 AS `transfer_number`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `jumboreel_customers`;
/*!50001 DROP VIEW IF EXISTS `jumboreel_customers`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `jumboreel_customers` AS SELECT 
 1 AS `id`,
 1 AS `customername`,
 1 AS `customerlabel`,
 1 AS `customercountry`,
 1 AS `customeraddress`,
 1 AS `customertelephone`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `jumboreel_factoryexit`;
/*!50001 DROP VIEW IF EXISTS `jumboreel_factoryexit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `jumboreel_factoryexit` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `barcode`,
 1 AS `exitlocation`,
 1 AS `dateofexit`,
 1 AS `status`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `jumboreel_factoryexit_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_factoryexit_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `factoryname` varchar(255) NOT NULL,
  `exitlocation` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_grades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `gradename` tinytext NOT NULL,
  `gradetype` varchar(5) NOT NULL,
  `grade` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gradetype` (`gradetype`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_production_forecast`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_production_forecast` (
  `id` int NOT NULL AUTO_INCREMENT,
  `gradeid` int NOT NULL,
  `weight` float NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gradeid` (`gradeid`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_production_forecast_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_production_forecast_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `gradeid` int NOT NULL,
  `weight` float NOT NULL,
  `month` varchar(2) NOT NULL,
  `year` varchar(4) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productname` varchar(200) NOT NULL,
  `gradetype` varchar(20) NOT NULL,
  `gradename` varchar(200) NOT NULL,
  `grade` set('Economy','Premium') DEFAULT NULL,
  `brightness` float NOT NULL,
  `gsm` float NOT NULL,
  `ply` int NOT NULL,
  `width` float NOT NULL,
  `diameter` float NOT NULL,
  `slice` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productname` (`productname`)
) ENGINE=InnoDB AUTO_INCREMENT=707 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location` varchar(20) NOT NULL,
  `productid` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `weight` double NOT NULL,
  `modification` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`location`,`productid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=857 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_storeentrance`;
/*!50001 DROP VIEW IF EXISTS `jumboreel_storeentrance`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `jumboreel_storeentrance` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `barcode`,
 1 AS `entrancelocation`,
 1 AS `dateofentrance`,
 1 AS `status`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `jumboreel_storeentrance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_storeentrance_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `store` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `entrancelocation` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_storeexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_storeexit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `exitlocation` varchar(50) NOT NULL,
  `dateofexit` varchar(20) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8684 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jumboreel_storeexit_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jumboreel_storeexit_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `storename` varchar(20) NOT NULL,
  `exitlocation` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `local_governments`;
/*!50001 DROP VIEW IF EXISTS `local_governments`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `local_governments` AS SELECT 
 1 AS `id`,
 1 AS `state_id`,
 1 AS `name`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `logs`;
/*!50001 DROP VIEW IF EXISTS `logs`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `logs` AS SELECT 
 1 AS `id`,
 1 AS `channel`,
 1 AS `level`,
 1 AS `message`,
 1 AS `time`,
 1 AS `user`,
 1 AS `data`,
 1 AS `system`,
 1 AS `ip`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `productid` int NOT NULL AUTO_INCREMENT,
  `productname` varchar(255) NOT NULL,
  `productcode` varchar(255) NOT NULL,
  `productbundles` varchar(50) DEFAULT NULL,
  `productgroup` varchar(50) DEFAULT NULL,
  `basepaper` varchar(255) DEFAULT NULL,
  `mach` varchar(255) DEFAULT NULL,
  `embossing` varchar(255) DEFAULT NULL,
  `lamedge` varchar(255) DEFAULT NULL,
  `revnumber` int NOT NULL DEFAULT '0',
  `revdate` varchar(20) NOT NULL,
  `productrolls` int NOT NULL DEFAULT '0',
  `productpacks` int NOT NULL DEFAULT '0',
  `gsm` float NOT NULL DEFAULT '0',
  `logweight` float NOT NULL DEFAULT '0',
  `sheetwidth` varchar(20) NOT NULL DEFAULT '0:0:0',
  `sheetlength` float NOT NULL DEFAULT '0',
  `clipweight` float NOT NULL DEFAULT '0',
  `actualrollweight` float NOT NULL DEFAULT '0',
  `rolllength` float NOT NULL DEFAULT '0',
  `coreweight` float NOT NULL DEFAULT '0',
  `corediameter` float NOT NULL DEFAULT '0',
  `diameter` float NOT NULL DEFAULT '0',
  `perimeter` float NOT NULL DEFAULT '0',
  `pulls` float NOT NULL DEFAULT '0',
  `netweight` float NOT NULL DEFAULT '0',
  `hardrollsource` varchar(50) DEFAULT NULL,
  `ply` int NOT NULL DEFAULT '0',
  `hardrollwidth` float NOT NULL DEFAULT '0',
  `rollsperbundle` int NOT NULL DEFAULT '0',
  `wrapperweight` float NOT NULL DEFAULT '0',
  `polybagweight` float NOT NULL DEFAULT '0',
  `polybundleweight` float NOT NULL DEFAULT '0',
  `sheetcounts` int NOT NULL DEFAULT '0',
  `grossweight` float NOT NULL DEFAULT '0',
  `hardrollgsm` varchar(5) DEFAULT NULL,
  `waste` varchar(4) DEFAULT NULL,
  `bundlespertonne` varchar(20) DEFAULT NULL,
  `imagepath` varchar(255) DEFAULT NULL,
  `timestamp` int NOT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`productid`),
  UNIQUE KEY `productcode` (`productcode`),
  UNIQUE KEY `productname` (`productname`)
) ENGINE=InnoDB AUTO_INCREMENT=372 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products_old`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products_old` (
  `productid` int NOT NULL AUTO_INCREMENT,
  `productname` varchar(255) NOT NULL,
  `productcode` varchar(255) NOT NULL,
  `productbundles` varchar(50) DEFAULT NULL,
  `productgroup` varchar(50) DEFAULT NULL,
  `basepaper` varchar(255) DEFAULT NULL,
  `mach` varchar(255) DEFAULT NULL,
  `embossing` varchar(255) DEFAULT NULL,
  `lamedge` varchar(255) DEFAULT NULL,
  `revnumber` int NOT NULL DEFAULT '0',
  `revdate` varchar(20) NOT NULL,
  `productrolls` int NOT NULL DEFAULT '0',
  `productpacks` int NOT NULL DEFAULT '0',
  `gsm` float NOT NULL DEFAULT '0',
  `logweight` float NOT NULL DEFAULT '0',
  `sheetwidth` varchar(20) NOT NULL DEFAULT '0:0:0',
  `sheetlength` float NOT NULL DEFAULT '0',
  `clipweight` float NOT NULL DEFAULT '0',
  `actualrollweight` float NOT NULL DEFAULT '0',
  `rolllength` float NOT NULL DEFAULT '0',
  `coreweight` float NOT NULL DEFAULT '0',
  `corediameter` float NOT NULL DEFAULT '0',
  `diameter` float NOT NULL DEFAULT '0',
  `perimeter` float NOT NULL DEFAULT '0',
  `pulls` float NOT NULL DEFAULT '0',
  `netweight` float NOT NULL DEFAULT '0',
  `hardrollsource` varchar(50) DEFAULT NULL,
  `ply` int NOT NULL DEFAULT '0',
  `hardrollwidth` float NOT NULL DEFAULT '0',
  `rollsperbundle` int NOT NULL DEFAULT '0',
  `wrapperweight` float NOT NULL DEFAULT '0',
  `polybagweight` float NOT NULL DEFAULT '0',
  `polybundleweight` float NOT NULL DEFAULT '0',
  `sheetcounts` int NOT NULL DEFAULT '0',
  `grossweight` float NOT NULL DEFAULT '0',
  `hardrollgsm` varchar(5) DEFAULT NULL,
  `waste` varchar(4) DEFAULT NULL,
  `bundlespertonne` varchar(20) DEFAULT NULL,
  `imagepath` varchar(255) DEFAULT NULL,
  `timestamp` int NOT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`productid`),
  UNIQUE KEY `productcode` (`productcode`),
  UNIQUE KEY `productname` (`productname`)
) ENGINE=InnoDB AUTO_INCREMENT=318 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qc_revision`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `qc_revision` (
  `id` int NOT NULL,
  `data` json NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterial_inter_transfer`;
/*!50001 DROP VIEW IF EXISTS `rawmaterial_inter_transfer`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterial_inter_transfer` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `barcode`,
 1 AS `dateoftransfer`,
 1 AS `timestamp`,
 1 AS `to_company`,
 1 AS `from_company`,
 1 AS `productid`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterial_store_location`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterial_store_location` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `suppliercode`,
 1 AS `productid`,
 1 AS `barcode`,
 1 AS `weight`,
 1 AS `location_id`,
 1 AS `dateofcreation`,
 1 AS `status`,
 1 AS `sub_barcode`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterials_copy`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_copy`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_copy` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `suppliercode`,
 1 AS `productid`,
 1 AS `barcode`,
 1 AS `weight`,
 1 AS `dateofcreation`,
 1 AS `location`,
 1 AS `status`,
 1 AS `timestamp`,
 1 AS `sub_barcode`,
 1 AS `location_id`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterials_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `groupname` varchar(50) NOT NULL,
  `groupcode` varchar(5) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupname` (`groupname`),
  UNIQUE KEY `groupcode` (`groupcode`)
) ENGINE=MyISAM AUTO_INCREMENT=990011 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_inter_received`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_inter_received`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_inter_received` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `suppliercode`,
 1 AS `productid`,
 1 AS `barcode`,
 1 AS `weight`,
 1 AS `dateofcreation`,
 1 AS `location`,
 1 AS `status`,
 1 AS `timestamp`,
 1 AS `sub_barcode`,
 1 AS `location_id`,
 1 AS `company_id`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterials_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `storecode` varchar(100) NOT NULL,
  `productname` varchar(255) NOT NULL,
  `accountcode` varchar(100) NOT NULL,
  `groupid` int NOT NULL,
  `subgroupid` int NOT NULL,
  `common` set('Yes','No') NOT NULL DEFAULT 'No',
  PRIMARY KEY (`id`),
  UNIQUE KEY `storecode` (`storecode`),
  UNIQUE KEY `productname` (`productname`)
) ENGINE=MyISAM AUTO_INCREMENT=990004 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location` varchar(20) NOT NULL,
  `productid` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `weight` double NOT NULL,
  `modification` longtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`location`,`productid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=150 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_store_exit`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_store_exit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_store_exit` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `status`,
 1 AS `dateofcreation`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterials_store_unused`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_store_unused` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productname` text NOT NULL,
  `weight` float NOT NULL,
  `date` text NOT NULL,
  `timestamp` bigint NOT NULL,
  `origin` varchar(20) DEFAULT NULL,
  `location` varchar(20) DEFAULT NULL,
  `modifications` json NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1349 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_storeexit_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_storeexit_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `storename` varchar(255) NOT NULL,
  `exitlocation` varchar(255) NOT NULL,
  `factoryname` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_subgroups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_subgroups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subgroupname` varchar(50) NOT NULL,
  `subgroupcode` varchar(6) NOT NULL,
  `groupid` int NOT NULL,
  `sub_barcode` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subgroupname` (`subgroupname`),
  UNIQUE KEY `groupcode` (`subgroupcode`)
) ENGINE=MyISAM AUTO_INCREMENT=990012 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_supplier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplierid` varchar(20) NOT NULL,
  `suppliername` varchar(200) NOT NULL,
  `suppliercode` varchar(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=337 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_supplier_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_supplier_deliveries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `suppliercode` varchar(3) DEFAULT NULL,
  `productid` int NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `weight` float NOT NULL,
  `dateofcreation` varchar(20) NOT NULL,
  `location` varchar(30) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `timestamp` varchar(255) DEFAULT NULL,
  `sub_barcode` json DEFAULT NULL,
  `location_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rmsd_barcode_idx` (`barcode`),
  KEY `rmsd_dateofcreation_idx` (`dateofcreation`),
  KEY `rmsd_productid_idx` (`productid`),
  KEY `rmsd_suppliercode_idx` (`suppliercode`),
  KEY `rmsd_cover_idx` (`dateofcreation`,`productid`,`suppliercode`,`weight`)
) ENGINE=MyISAM AUTO_INCREMENT=129462 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_transfer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_transfer` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `exitlocation` varchar(50) NOT NULL,
  `transferlocation` varchar(50) NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `dateoftransfer` varchar(20) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=47 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_transferlocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_transferlocations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exitlocation` varchar(50) NOT NULL,
  `transferlocation` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_warehouse_entry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_warehouse_entry` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `suppliercode` varchar(3) DEFAULT NULL,
  `productid` int NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `weight` float NOT NULL,
  `location_id` int NOT NULL,
  `dateofcreation` date NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `sub_barcode` json DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source` varchar(20) NOT NULL DEFAULT 'supplier',
  PRIMARY KEY (`id`),
  KEY `rmwe_barcode_idx` (`barcode`),
  KEY `rmwe_status_product_loc_idx` (`status`,`productid`,`location_id`),
  KEY `rmwe_dateofcreation_idx` (`dateofcreation`),
  KEY `rmwe_source_idx` (`source`)
) ENGINE=MyISAM AUTO_INCREMENT=229860 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rawmaterials_warehouse_exit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rawmaterials_warehouse_exit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(255) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `location_id` varchar(255) NOT NULL,
  `status` varchar(255) DEFAULT NULL,
  `dateofcreation` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `rmse_barcode_idx` (`barcode`),
  KEY `rmse_dateofcreation_idx` (`dateofcreation`)
) ENGINE=MyISAM AUTO_INCREMENT=311106 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `redirections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `redirections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `page` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reprint_approval`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reprint_approval` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sequence_number` varchar(255) DEFAULT NULL,
  `user` varchar(255) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `product` varchar(255) NOT NULL,
  `weight` int NOT NULL,
  `message` longtext NOT NULL,
  `dateofcreation` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` varchar(255) NOT NULL,
  `authorizer` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `return_approval`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `return_approval` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sequence_number` varchar(255) DEFAULT NULL,
  `user` varchar(255) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `product` varchar(255) NOT NULL,
  `weight` int NOT NULL,
  `message` longtext NOT NULL,
  `dateofcreation` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` varchar(255) NOT NULL,
  `authorizer` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ra_barcode_idx` (`barcode`)
) ENGINE=MyISAM AUTO_INCREMENT=3507 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customercode` varchar(255) NOT NULL,
  `customername` varchar(255) NOT NULL,
  `customeraddress` varchar(1000) DEFAULT NULL,
  `customerphonenumber` varchar(20) DEFAULT NULL,
  `customercity` varchar(50) NOT NULL,
  `customerstate` varchar(255) DEFAULT NULL,
  `customerdesignation` varchar(50) DEFAULT NULL,
  `customerregion` varchar(50) DEFAULT NULL,
  `customercountry` varchar(255) NOT NULL,
  `channel` varchar(25) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1924 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_delivery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_delivery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `deliverynumber` int NOT NULL,
  `barcode` varchar(20) NOT NULL,
  `username` varchar(255) NOT NULL,
  `loadnumber` int NOT NULL,
  `dateofdelivery` varchar(11) NOT NULL,
  `timestamp` int NOT NULL,
  `deliverycustomerid` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=134525 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_delivery_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_delivery_daily` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productid` varchar(11) NOT NULL,
  `productname` varchar(255) NOT NULL,
  `bundles` int NOT NULL,
  `closingdate` varchar(20) NOT NULL,
  `openingdate` varchar(20) NOT NULL,
  `status` varchar(50) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1253854 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_designation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_designation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sales_region` varchar(50) NOT NULL,
  `sales_designation` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_forecast`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_forecast` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productid` int NOT NULL,
  `bundles` int NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=276 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_forecast_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_forecast_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productid` int NOT NULL,
  `bundles` int NOT NULL,
  `dateofforecast` varchar(20) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8290 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_loading`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_loading` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loadnumber` int NOT NULL,
  `loader` varchar(255) DEFAULT NULL,
  `barcode` varchar(20) NOT NULL,
  `username` varchar(255) NOT NULL,
  `sod_id` int DEFAULT NULL,
  `cageroomcode` varchar(7) NOT NULL,
  `transporterid` int NOT NULL,
  `trucknumber` varchar(30) DEFAULT NULL,
  `truckdriver` varchar(50) DEFAULT NULL,
  `quantityloaded` int NOT NULL,
  `dateofloading` varchar(20) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `timestamp` int NOT NULL,
  `sales_loading_customerid` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=639105 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_loading_return`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_loading_return` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barcode` varchar(20) NOT NULL,
  `username` varchar(20) NOT NULL,
  `loading_id` int NOT NULL,
  `sod_id` int NOT NULL,
  `quantityunloaded` int NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=338384 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_order` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(200) DEFAULT NULL,
  `orderid` varchar(20) NOT NULL,
  `warehousecode` varchar(3) NOT NULL,
  `customerid` int NOT NULL,
  `dateoforder` varchar(11) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orderid` (`orderid`)
) ENGINE=InnoDB AUTO_INCREMENT=111232 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_order_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_order_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `orderid` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `productid` int NOT NULL,
  `quantityordered` int NOT NULL,
  `foc` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=595350 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_return`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_return` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `returnnumber` int NOT NULL,
  `sod_id` int NOT NULL,
  `quantityreturned` int NOT NULL,
  `quantityrejected` int NOT NULL,
  `dateofreturn` varchar(20) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2624 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_transporters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_transporters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transportername` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transportername` (`transportername`)
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_warehouse`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_warehouse` (
  `warehouselocation` varchar(255) NOT NULL,
  `warehousecode` varchar(50) NOT NULL,
  PRIMARY KEY (`warehousecode`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_waybill`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_waybill` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barcode` varchar(50) NOT NULL,
  `username` varchar(255) NOT NULL,
  `deliverynumber` varchar(20) NOT NULL,
  `receiptnumber` int DEFAULT NULL,
  `transportcost` float NOT NULL,
  `dateofwaybill` varchar(20) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=54665 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `softroll_factoryexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `softroll_factoryexit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `barcode` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `location_id` tinyint NOT NULL,
  `date` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `softroll_stock`;
/*!50001 DROP VIEW IF EXISTS `softroll_stock`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `softroll_stock` AS SELECT 
 1 AS `id`,
 1 AS `location_id`,
 1 AS `grade_id`,
 1 AS `quantity`,
 1 AS `weight`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `softroll_storeentrance`;
/*!50001 DROP VIEW IF EXISTS `softroll_storeentrance`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `softroll_storeentrance` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `softroll_storeexit`;
/*!50001 DROP VIEW IF EXISTS `softroll_storeexit`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `softroll_storeexit` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `location_id`,
 1 AS `date`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `deleted_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `states`;
/*!50001 DROP VIEW IF EXISTS `states`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `states` AS SELECT 
 1 AS `id`,
 1 AS `name`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `stock`;
/*!50001 DROP VIEW IF EXISTS `stock`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `stock` AS SELECT 
 1 AS `id`,
 1 AS `warehousecode`,
 1 AS `productid`,
 1 AS `productcode`,
 1 AS `opening`,
 1 AS `closing`,
 1 AS `date`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `stock_2020_05_04`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_2020_05_04` (
  `id` int NOT NULL AUTO_INCREMENT,
  `warehousecode` varchar(2) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `productid` int NOT NULL,
  `productcode` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `opening` int NOT NULL DEFAULT '0',
  `closing` int NOT NULL,
  `date` varchar(20) NOT NULL DEFAULT '2020/05/04',
  `timestamp` int NOT NULL DEFAULT '1589962267',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=251 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_transfer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `transfernumber` int NOT NULL,
  `productid` int NOT NULL,
  `warehousecode` varchar(3) NOT NULL,
  `trucknumber` varchar(20) DEFAULT NULL,
  `quantitytransferred` int NOT NULL,
  `dateoftransfer` varchar(20) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6633 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stockhistory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stockhistory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `warehousecode` varchar(3) NOT NULL DEFAULT '01',
  `productid` int NOT NULL,
  `opening` int NOT NULL,
  `closing` int NOT NULL,
  `date` varchar(20) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehousecode` (`warehousecode`,`productid`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=811960 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_adjustment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_adjustment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `warehousecode` varchar(3) NOT NULL,
  `floor` tinyint NOT NULL,
  `productid` int NOT NULL,
  `purpose` varchar(15) DEFAULT NULL,
  `bundles` int NOT NULL,
  `comment` text,
  `date` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_cagerooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_cagerooms` (
  `cageroomnumber` varchar(50) NOT NULL,
  `cageroomcode` varchar(7) NOT NULL,
  `warehousecode` varchar(3) NOT NULL,
  PRIMARY KEY (`cageroomcode`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_entrance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_entrance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `productid` int NOT NULL,
  `entrancelocation` varchar(255) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `bundles` int NOT NULL,
  `dateofentrance` varchar(20) NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=1162507 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_floors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_floors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `floor_name` varchar(10) NOT NULL,
  `warehousecode` varchar(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storebundle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `storebundle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `warehousecode` varchar(3) NOT NULL DEFAULT '01',
  `productid` int DEFAULT NULL,
  `bundles` int NOT NULL,
  `timestamp` text NOT NULL,
  `modifications` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehousecode` (`warehousecode`,`productid`)
) ENGINE=InnoDB AUTO_INCREMENT=437 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storebundle_floor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `storebundle_floor` (
  `id` int NOT NULL AUTO_INCREMENT,
  `floor_id` int NOT NULL DEFAULT '3',
  `product_id` int NOT NULL,
  `bundles` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `floor_id` (`floor_id`,`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=767 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storeentrance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `storeentrance_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `storefloor` varchar(255) NOT NULL,
  `entrancelocation` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storelocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `storelocations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `storeline` varchar(20) NOT NULL,
  `linecapacity` int NOT NULL,
  `productname` varchar(255) NOT NULL,
  `productcode` varchar(255) NOT NULL,
  `palettes` int NOT NULL,
  `bundles` int NOT NULL,
  `date` varchar(20) NOT NULL,
  `timestamp` bigint NOT NULL,
  `modifications` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=561 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfer_company_from`;
/*!50001 DROP VIEW IF EXISTS `transfer_company_from`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `transfer_company_from` AS SELECT 
 1 AS `id`,
 1 AS `company_name`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `transfer_company_to`;
/*!50001 DROP VIEW IF EXISTS `transfer_company_to`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `transfer_company_to` AS SELECT 
 1 AS `id`,
 1 AS `company_name`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `user`;
/*!50001 DROP VIEW IF EXISTS `user`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `user` AS SELECT 
 1 AS `userid`,
 1 AS `username`,
 1 AS `fullname`,
 1 AS `email`,
 1 AS `password`,
 1 AS `userlevel`,
 1 AS `redirection_id`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `userlevels`;
/*!50001 DROP VIEW IF EXISTS `userlevels`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `userlevels` AS SELECT 
 1 AS `level`,
 1 AS `default_user`,
 1 AS `role_description`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `wp-grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp-grades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `grade_name` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wp_b2b`;
/*!50001 DROP VIEW IF EXISTS `wp_b2b`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wp_b2b` AS SELECT 
 1 AS `id`,
 1 AS `b2b`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `wp_data`;
/*!50001 DROP VIEW IF EXISTS `wp_data`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wp_data` AS SELECT 
 1 AS `id`,
 1 AS `supplier_id`,
 1 AS `grade_id`,
 1 AS `quantity`,
 1 AS `wetness`,
 1 AS `unit_price`,
 1 AS `cash_advance`,
 1 AS `total_amount`,
 1 AS `paper_type_id`,
 1 AS `dumping_site_id`,
 1 AS `b2b_id`,
 1 AS `date`,
 1 AS `additional`,
 1 AS `special_agent_transporter`,
 1 AS `weather_conditional`,
 1 AS `bpl_situation`,
 1 AS `commercial_situation`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `addition`,
 1 AS `additional_amount`,
 1 AS `last_edit_date`,
 1 AS `comment`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `wp_grades`;
/*!50001 DROP VIEW IF EXISTS `wp_grades`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wp_grades` AS SELECT 
 1 AS `id`,
 1 AS `grade_name`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `wp_papertype`;
/*!50001 DROP VIEW IF EXISTS `wp_papertype`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wp_papertype` AS SELECT 
 1 AS `id`,
 1 AS `paper_type`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `wp_states`;
/*!50001 DROP VIEW IF EXISTS `wp_states`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wp_states` AS SELECT 
 1 AS `id`,
 1 AS `state`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `wp_suppliers`;
/*!50001 DROP VIEW IF EXISTS `wp_suppliers`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wp_suppliers` AS SELECT 
 1 AS `id`,
 1 AS `supplier_code`,
 1 AS `supplier_name`,
 1 AS `supplier_phonenumber`,
 1 AS `created_at`,
 1 AS `supplier_state`*/;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `actions`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `actions` AS select `core`.`actions`.`id` AS `id`,`core`.`actions`.`user` AS `user`,`core`.`actions`.`request` AS `request`,`core`.`actions`.`type` AS `type`,`core`.`actions`.`date` AS `date`,`core`.`actions`.`comment` AS `comment` from `core`.`actions` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_accounts`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_accounts` AS select `bpl`.`bpl_accounts`.`id` AS `id`,`bpl`.`bpl_accounts`.`account` AS `account`,`bpl`.`bpl_accounts`.`beneficiary` AS `beneficiary`,`bpl`.`bpl_accounts`.`intermediary` AS `intermediary`,`bpl`.`bpl_accounts`.`correspondent` AS `correspondent`,`bpl`.`bpl_accounts`.`further_acc` AS `further_acc`,`bpl`.`bpl_accounts`.`currency_id` AS `currency_id`,`bpl`.`bpl_accounts`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_accounts` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_banks`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_banks` AS select `bpl`.`bpl_banks`.`id` AS `id`,`bpl`.`bpl_banks`.`name` AS `name`,`bpl`.`bpl_banks`.`number` AS `number`,`bpl`.`bpl_banks`.`sortcode` AS `sortcode`,`bpl`.`bpl_banks`.`swiftcode` AS `swiftcode`,`bpl`.`bpl_banks`.`address` AS `address` from `bpl`.`bpl_banks` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_bill_of_lading`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_bill_of_lading` AS select `bpl`.`bpl_bill_of_lading`.`id` AS `id`,`bpl`.`bpl_bill_of_lading`.`packing_id` AS `packing_id`,`bpl`.`bpl_bill_of_lading`.`number` AS `number`,`bpl`.`bpl_bill_of_lading`.`issued_date` AS `issued_date`,`bpl`.`bpl_bill_of_lading`.`shipped_date` AS `shipped_date`,`bpl`.`bpl_bill_of_lading`.`arrival_date` AS `arrival_date`,`bpl`.`bpl_bill_of_lading`.`doc` AS `doc`,`bpl`.`bpl_bill_of_lading`.`timestamp` AS `timestamp` from `bpl`.`bpl_bill_of_lading` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_customers`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_customers` AS select `bpl`.`bpl_customers`.`id` AS `id`,`bpl`.`bpl_customers`.`type` AS `type`,`bpl`.`bpl_customers`.`customername` AS `customername`,`bpl`.`bpl_customers`.`customerlabel` AS `customerlabel`,`bpl`.`bpl_customers`.`customercountry` AS `customercountry`,`bpl`.`bpl_customers`.`customeraddress` AS `customeraddress`,`bpl`.`bpl_customers`.`customertelephone` AS `customertelephone`,`bpl`.`bpl_customers`.`port` AS `port`,`bpl`.`bpl_customers`.`fax` AS `fax`,`bpl`.`bpl_customers`.`email` AS `email`,`bpl`.`bpl_customers`.`products` AS `products`,`bpl`.`bpl_customers`.`created_at` AS `created_at`,`bpl`.`bpl_customers`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_customers` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_delivery`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_delivery` AS select `bpl`.`bpl_delivery`.`id` AS `id`,`bpl`.`bpl_delivery`.`customer_name` AS `customer_name`,`bpl`.`bpl_delivery`.`date` AS `date`,`bpl`.`bpl_delivery`.`vechile_number` AS `vechile_number`,`bpl`.`bpl_delivery`.`loader` AS `loader`,`bpl`.`bpl_delivery`.`driver` AS `driver`,`bpl`.`bpl_delivery`.`truck_weight` AS `truck_weight`,`bpl`.`bpl_delivery`.`created_at` AS `created_at`,`bpl`.`bpl_delivery`.`updated_at` AS `updated_at`,`bpl`.`bpl_delivery`.`container_number` AS `container_number`,`bpl`.`bpl_delivery`.`driver_phone` AS `driver_phone` from `bpl`.`bpl_delivery` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_delivery_barcode`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_delivery_barcode` AS select `bpl`.`bpl_delivery_barcode`.`id` AS `id`,`bpl`.`bpl_delivery_barcode`.`delivery_barcode` AS `delivery_barcode`,`bpl`.`bpl_delivery_barcode`.`status` AS `status`,`bpl`.`bpl_delivery_barcode`.`deleted_at` AS `deleted_at`,`bpl`.`bpl_delivery_barcode`.`created_at` AS `created_at`,`bpl`.`bpl_delivery_barcode`.`updated_at` AS `updated_at`,`bpl`.`bpl_delivery_barcode`.`date` AS `date`,`bpl`.`bpl_delivery_barcode`.`full_weight` AS `full_weight` from `bpl`.`bpl_delivery_barcode` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_delivery_details`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_delivery_details` AS select `bpl`.`bpl_delivery_details`.`id` AS `id`,`bpl`.`bpl_delivery_details`.`bpl_delivery_id` AS `bpl_delivery_id`,`bpl`.`bpl_delivery_details`.`barcode` AS `barcode`,`bpl`.`bpl_delivery_details`.`product_id` AS `product_id`,`bpl`.`bpl_delivery_details`.`delivery_barcode` AS `delivery_barcode`,`bpl`.`bpl_delivery_details`.`created_at` AS `created_at`,`bpl`.`bpl_delivery_details`.`updated_at` AS `updated_at` from `bpl`.`bpl_delivery_details` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_factoryexit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_factoryexit` AS select `bpl`.`bpl_factoryexit`.`id` AS `id`,`bpl`.`bpl_factoryexit`.`user` AS `user`,`bpl`.`bpl_factoryexit`.`barcode` AS `barcode`,`bpl`.`bpl_factoryexit`.`location_id` AS `location_id`,`bpl`.`bpl_factoryexit`.`date` AS `date`,`bpl`.`bpl_factoryexit`.`status` AS `status`,`bpl`.`bpl_factoryexit`.`created_at` AS `created_at`,`bpl`.`bpl_factoryexit`.`updated_at` AS `updated_at`,`bpl`.`bpl_factoryexit`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_factoryexit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_grades`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_grades` AS select `bpl`.`bpl_grades`.`id` AS `id`,`bpl`.`bpl_grades`.`gradename` AS `gradename`,`bpl`.`bpl_grades`.`type` AS `type`,`bpl`.`bpl_grades`.`grade` AS `grade`,`bpl`.`bpl_grades`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_grades` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_invoice_payments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_invoice_payments` AS select `bpl`.`bpl_invoice_payments`.`id` AS `id`,`bpl`.`bpl_invoice_payments`.`packing_id` AS `packing_id`,`bpl`.`bpl_invoice_payments`.`amount` AS `amount`,`bpl`.`bpl_invoice_payments`.`date` AS `date` from `bpl`.`bpl_invoice_payments` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_packing_list`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_packing_list` AS select `bpl`.`bpl_packing_list`.`id` AS `id`,`bpl`.`bpl_packing_list`.`order_id` AS `order_id`,`bpl`.`bpl_packing_list`.`number` AS `number`,`bpl`.`bpl_packing_list`.`containers` AS `containers`,`bpl`.`bpl_packing_list`.`date` AS `date`,`bpl`.`bpl_packing_list`.`split` AS `split`,`bpl`.`bpl_packing_list`.`timestamp` AS `timestamp` from `bpl`.`bpl_packing_list` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_payment_terms`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_payment_terms` AS select `bpl`.`bpl_payment_terms`.`id` AS `id`,`bpl`.`bpl_payment_terms`.`payment_terms` AS `payment_terms`,`bpl`.`bpl_payment_terms`.`days` AS `days`,`bpl`.`bpl_payment_terms`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_payment_terms` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_production`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_production` AS select `bpl`.`bpl_production`.`id` AS `id`,`bpl`.`bpl_production`.`username` AS `username`,`bpl`.`bpl_production`.`customer_id` AS `customer_id`,`bpl`.`bpl_production`.`papermachine` AS `papermachine`,`bpl`.`bpl_production`.`product_id` AS `product_id`,`bpl`.`bpl_production`.`hardrollnumber` AS `hardrollnumber`,`bpl`.`bpl_production`.`barcode` AS `barcode`,`bpl`.`bpl_production`.`brightness` AS `brightness`,`bpl`.`bpl_production`.`corediameter` AS `corediameter`,`bpl`.`bpl_production`.`joints` AS `joints`,`bpl`.`bpl_production`.`paperweight` AS `paperweight`,`bpl`.`bpl_production`.`weight` AS `weight`,`bpl`.`bpl_production`.`status` AS `status`,`bpl`.`bpl_production`.`hold` AS `hold`,`bpl`.`bpl_production`.`comments` AS `comments`,`bpl`.`bpl_production`.`dateofmanufacture` AS `dateofmanufacture`,`bpl`.`bpl_production`.`deleted_at` AS `deleted_at`,`bpl`.`bpl_production`.`timestamp` AS `timestamp`,`bpl`.`bpl_production`.`net_weight` AS `net_weight` from `bpl`.`bpl_production` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_products`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_products` AS select `bpl`.`bpl_products`.`id` AS `id`,`bpl`.`bpl_products`.`old` AS `old`,`bpl`.`bpl_products`.`productname` AS `productname`,`bpl`.`bpl_products`.`gradetype` AS `gradetype`,`bpl`.`bpl_products`.`brightness` AS `brightness`,`bpl`.`bpl_products`.`gsm` AS `gsm`,`bpl`.`bpl_products`.`ply` AS `ply`,`bpl`.`bpl_products`.`width` AS `width`,`bpl`.`bpl_products`.`diameter` AS `diameter`,`bpl`.`bpl_products`.`slice` AS `slice`,`bpl`.`bpl_products`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_products` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_proforma`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_proforma` AS select `bpl`.`bpl_proforma`.`order_id` AS `order_id`,`bpl`.`bpl_proforma`.`customer_ref` AS `customer_ref`,`bpl`.`bpl_proforma`.`freight` AS `freight`,`bpl`.`bpl_proforma`.`container` AS `container`,`bpl`.`bpl_proforma`.`freight_price` AS `freight_price`,`bpl`.`bpl_proforma`.`terms` AS `terms`,`bpl`.`bpl_proforma`.`shipment` AS `shipment`,`bpl`.`bpl_proforma`.`payment_term_id` AS `payment_term_id`,`bpl`.`bpl_proforma`.`nxp` AS `nxp`,`bpl`.`bpl_proforma`.`currency_id` AS `currency_id`,`bpl`.`bpl_proforma`.`account_id` AS `account_id`,`bpl`.`bpl_proforma`.`date` AS `date`,`bpl`.`bpl_proforma`.`created_at` AS `created_at`,`bpl`.`bpl_proforma`.`updated_at` AS `updated_at`,`bpl`.`bpl_proforma`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_proforma` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_proforma_items`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_proforma_items` AS select `bpl`.`bpl_proforma_items`.`id` AS `id`,`bpl`.`bpl_proforma_items`.`order_item_id` AS `order_item_id`,`bpl`.`bpl_proforma_items`.`price` AS `price` from `bpl`.`bpl_proforma_items` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_quarantine`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_quarantine` AS select `bpl`.`bpl_quarantine`.`id` AS `id`,`bpl`.`bpl_quarantine`.`user` AS `user`,`bpl`.`bpl_quarantine`.`barcode` AS `barcode`,`bpl`.`bpl_quarantine`.`location_id` AS `location_id`,`bpl`.`bpl_quarantine`.`date` AS `date`,`bpl`.`bpl_quarantine`.`status` AS `status`,`bpl`.`bpl_quarantine`.`created_at` AS `created_at`,`bpl`.`bpl_quarantine`.`updated_at` AS `updated_at`,`bpl`.`bpl_quarantine`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_quarantine` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_quarantine_storeexit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_quarantine_storeexit` AS select `bpl`.`bpl_quarantine_storeexit`.`id` AS `id`,`bpl`.`bpl_quarantine_storeexit`.`user` AS `user`,`bpl`.`bpl_quarantine_storeexit`.`barcode` AS `barcode`,`bpl`.`bpl_quarantine_storeexit`.`location_id` AS `location_id`,`bpl`.`bpl_quarantine_storeexit`.`date` AS `date`,`bpl`.`bpl_quarantine_storeexit`.`status` AS `status`,`bpl`.`bpl_quarantine_storeexit`.`created_at` AS `created_at`,`bpl`.`bpl_quarantine_storeexit`.`updated_at` AS `updated_at`,`bpl`.`bpl_quarantine_storeexit`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_quarantine_storeexit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_sales`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_sales` AS select `bpl`.`bpl_sales`.`id` AS `id`,`bpl`.`bpl_sales`.`ref` AS `ref`,`bpl`.`bpl_sales`.`username` AS `username`,`bpl`.`bpl_sales`.`customerid` AS `customerid`,`bpl`.`bpl_sales`.`company` AS `company`,`bpl`.`bpl_sales`.`date` AS `date`,`bpl`.`bpl_sales`.`created_at` AS `created_at`,`bpl`.`bpl_sales`.`updated_at` AS `updated_at`,`bpl`.`bpl_sales`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_sales` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_sales_items`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_sales_items` AS select `bpl`.`bpl_sales_items`.`id` AS `id`,`bpl`.`bpl_sales_items`.`order_id` AS `order_id`,`bpl`.`bpl_sales_items`.`productid` AS `productid`,`bpl`.`bpl_sales_items`.`weight` AS `weight` from `bpl`.`bpl_sales_items` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_softroll_factoryexit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_softroll_factoryexit` AS select `bpl`.`bpl_softroll_factoryexit`.`id` AS `id`,`bpl`.`bpl_softroll_factoryexit`.`user` AS `user`,`bpl`.`bpl_softroll_factoryexit`.`barcode` AS `barcode`,`bpl`.`bpl_softroll_factoryexit`.`location_id` AS `location_id`,`bpl`.`bpl_softroll_factoryexit`.`date` AS `date`,`bpl`.`bpl_softroll_factoryexit`.`status` AS `status`,`bpl`.`bpl_softroll_factoryexit`.`created_at` AS `created_at`,`bpl`.`bpl_softroll_factoryexit`.`updated_at` AS `updated_at`,`bpl`.`bpl_softroll_factoryexit`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_softroll_factoryexit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_softroll_production`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_softroll_production` AS select `bpl`.`bpl_softroll_production`.`id` AS `id`,`bpl`.`bpl_softroll_production`.`username` AS `username`,`bpl`.`bpl_softroll_production`.`softrollnumber` AS `softrollnumber`,`bpl`.`bpl_softroll_production`.`grade_id` AS `grade_id`,`bpl`.`bpl_softroll_production`.`barcode` AS `barcode`,`bpl`.`bpl_softroll_production`.`brightness` AS `brightness`,`bpl`.`bpl_softroll_production`.`weight` AS `weight`,`bpl`.`bpl_softroll_production`.`grammage` AS `grammage`,`bpl`.`bpl_softroll_production`.`diameter` AS `diameter`,`bpl`.`bpl_softroll_production`.`status` AS `status`,`bpl`.`bpl_softroll_production`.`dateofmanufacture` AS `dateofmanufacture`,`bpl`.`bpl_softroll_production`.`deleted_at` AS `deleted_at`,`bpl`.`bpl_softroll_production`.`timestamp` AS `timestamp`,`bpl`.`bpl_softroll_production`.`papermachine` AS `papermachine` from `bpl`.`bpl_softroll_production` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_stock`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_stock` AS select `bpl`.`bpl_stock`.`id` AS `id`,`bpl`.`bpl_stock`.`location_id` AS `location_id`,`bpl`.`bpl_stock`.`product_id` AS `product_id`,`bpl`.`bpl_stock`.`quantity` AS `quantity`,`bpl`.`bpl_stock`.`weight` AS `weight` from `bpl`.`bpl_stock` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_stock_locations`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_stock_locations` AS select `bpl`.`bpl_stock_locations`.`id` AS `id`,`bpl`.`bpl_stock_locations`.`type` AS `type`,`bpl`.`bpl_stock_locations`.`location` AS `location` from `bpl`.`bpl_stock_locations` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_store_count`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_store_count` AS select `bpl`.`bpl_store_count`.`id` AS `id`,`bpl`.`bpl_store_count`.`user` AS `user`,`bpl`.`bpl_store_count`.`barcode` AS `barcode`,`bpl`.`bpl_store_count`.`location_id` AS `location_id`,`bpl`.`bpl_store_count`.`date` AS `date`,`bpl`.`bpl_store_count`.`status` AS `status`,`bpl`.`bpl_store_count`.`created_at` AS `created_at`,`bpl`.`bpl_store_count`.`updated_at` AS `updated_at`,`bpl`.`bpl_store_count`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_store_count` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_storeentrance`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_storeentrance` AS select `bpl`.`bpl_storeentrance`.`id` AS `id`,`bpl`.`bpl_storeentrance`.`user` AS `user`,`bpl`.`bpl_storeentrance`.`barcode` AS `barcode`,`bpl`.`bpl_storeentrance`.`location_id` AS `location_id`,`bpl`.`bpl_storeentrance`.`date` AS `date`,`bpl`.`bpl_storeentrance`.`status` AS `status`,`bpl`.`bpl_storeentrance`.`created_at` AS `created_at`,`bpl`.`bpl_storeentrance`.`updated_at` AS `updated_at`,`bpl`.`bpl_storeentrance`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_storeentrance` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_storeentrance_trash`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_storeentrance_trash` AS select `bpl`.`bpl_storeentrance_trash`.`id` AS `id`,`bpl`.`bpl_storeentrance_trash`.`deletion_id` AS `deletion_id`,`bpl`.`bpl_storeentrance_trash`.`created_at` AS `created_at`,`bpl`.`bpl_storeentrance_trash`.`user` AS `user` from `bpl`.`bpl_storeentrance_trash` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_storeentrance_trash_details`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_storeentrance_trash_details` AS select `bpl`.`bpl_storeentrance_trash_details`.`id` AS `id`,`bpl`.`bpl_storeentrance_trash_details`.`deletion_id` AS `deletion_id`,`bpl`.`bpl_storeentrance_trash_details`.`date_of_entrance` AS `date_of_entrance`,`bpl`.`bpl_storeentrance_trash_details`.`barcode` AS `barcode`,`bpl`.`bpl_storeentrance_trash_details`.`productname` AS `productname`,`bpl`.`bpl_storeentrance_trash_details`.`gradetype` AS `gradetype`,`bpl`.`bpl_storeentrance_trash_details`.`location` AS `location`,`bpl`.`bpl_storeentrance_trash_details`.`weight` AS `weight`,`bpl`.`bpl_storeentrance_trash_details`.`created_at` AS `created_at`,`bpl`.`bpl_storeentrance_trash_details`.`updated_at` AS `updated_at`,`bpl`.`bpl_storeentrance_trash_details`.`user` AS `user` from `bpl`.`bpl_storeentrance_trash_details` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_storeexit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_storeexit` AS select `bpl`.`bpl_storeexit`.`id` AS `id`,`bpl`.`bpl_storeexit`.`user` AS `user`,`bpl`.`bpl_storeexit`.`barcode` AS `barcode`,`bpl`.`bpl_storeexit`.`location_id` AS `location_id`,`bpl`.`bpl_storeexit`.`date` AS `date`,`bpl`.`bpl_storeexit`.`status` AS `status`,`bpl`.`bpl_storeexit`.`created_at` AS `created_at`,`bpl`.`bpl_storeexit`.`updated_at` AS `updated_at`,`bpl`.`bpl_storeexit`.`deleted_at` AS `deleted_at` from `bpl`.`bpl_storeexit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_transporters`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_transporters` AS select `bpl`.`bpl_transporters`.`id` AS `id`,`bpl`.`bpl_transporters`.`transportername` AS `transportername` from `bpl`.`bpl_transporters` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_waybill_payment`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_waybill_payment` AS select `bpl`.`bpl_waybill_payment`.`id` AS `id`,`bpl`.`bpl_waybill_payment`.`barcode` AS `barcode`,`bpl`.`bpl_waybill_payment`.`transporter_id` AS `transporter_id`,`bpl`.`bpl_waybill_payment`.`amount` AS `amount`,`bpl`.`bpl_waybill_payment`.`amount_paid` AS `amount_paid`,`bpl`.`bpl_waybill_payment`.`balance` AS `balance`,`bpl`.`bpl_waybill_payment`.`status` AS `status`,`bpl`.`bpl_waybill_payment`.`created_at` AS `created_at`,`bpl`.`bpl_waybill_payment`.`updated_at` AS `updated_at`,`bpl`.`bpl_waybill_payment`.`customer_name` AS `customer_name`,`bpl`.`bpl_waybill_payment`.`vechicle_number` AS `vechicle_number` from `bpl`.`bpl_waybill_payment` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `bpl_waybill_payment_history`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `bpl_waybill_payment_history` AS select `bpl`.`bpl_waybill_payment_history`.`id` AS `id`,`bpl`.`bpl_waybill_payment_history`.`waybill_payment_id` AS `waybill_payment_id`,`bpl`.`bpl_waybill_payment_history`.`amount` AS `amount`,`bpl`.`bpl_waybill_payment_history`.`created_at` AS `created_at`,`bpl`.`bpl_waybill_payment_history`.`updated` AS `updated`,`bpl`.`bpl_waybill_payment_history`.`payment_date` AS `payment_date`,`bpl`.`bpl_waybill_payment_history`.`payment_note` AS `payment_note` from `bpl`.`bpl_waybill_payment_history` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `countries`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `countries` AS select `core`.`countries`.`id` AS `id`,`core`.`countries`.`iso` AS `iso`,`core`.`countries`.`name` AS `name`,`core`.`countries`.`nicename` AS `nicename`,`core`.`countries`.`iso3` AS `iso3`,`core`.`countries`.`numcode` AS `numcode`,`core`.`countries`.`phonecode` AS `phonecode` from `core`.`countries` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `currencies`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `currencies` AS select `core`.`currencies`.`id` AS `id`,`core`.`currencies`.`code` AS `code`,`core`.`currencies`.`name` AS `name`,`core`.`currencies`.`symbol` AS `symbol`,`core`.`currencies`.`short_name` AS `short_name`,`core`.`currencies`.`decimal_unit` AS `decimal_unit` from `core`.`currencies` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `dumping_site`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `dumping_site` AS select `bpl`.`dumping_site`.`id` AS `id`,`bpl`.`dumping_site`.`dump_site` AS `dump_site`,`bpl`.`dumping_site`.`created_at` AS `created_at` from `bpl`.`dumping_site` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factoryentrance_rawmaterial`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factoryentrance_rawmaterial` AS select `factory_entrance_rawmaterials`.`id` AS `id`,`factory_entrance_rawmaterials`.`user_name` AS `user_name`,`factory_entrance_rawmaterials`.`barcode` AS `barcode`,`factory_entrance_rawmaterials`.`location_id` AS `location_id`,`factory_entrance_rawmaterials`.`entrance_date` AS `entrance_date`,`factory_entrance_rawmaterials`.`product_id` AS `product_id`,`factory_entrance_rawmaterials`.`weight` AS `weight`,`factory_entrance_rawmaterials`.`status` AS `status` from `factory_entrance_rawmaterials` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `fg_inter_received`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `fg_inter_received` AS select `core`.`fg_inter_received`.`id` AS `id`,`core`.`fg_inter_received`.`username` AS `username`,`core`.`fg_inter_received`.`timestamp` AS `timestamp`,`core`.`fg_inter_received`.`productid` AS `productid`,`core`.`fg_inter_received`.`productcode` AS `productcode`,`core`.`fg_inter_received`.`productname` AS `productname`,`core`.`fg_inter_received`.`bundle` AS `bundle`,`core`.`fg_inter_received`.`trucknumber` AS `trucknumber`,`core`.`fg_inter_received`.`to_company` AS `to_company`,`core`.`fg_inter_received`.`from_company` AS `from_company`,`core`.`fg_inter_received`.`dateoftransfer` AS `dateoftransfer`,`core`.`fg_inter_received`.`barcode` AS `barcode`,`core`.`fg_inter_received`.`status` AS `status` from `core`.`fg_inter_received` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `fg_inter_transfer`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `fg_inter_transfer` AS select `core`.`fg_inter_transfer`.`id` AS `id`,`core`.`fg_inter_transfer`.`username` AS `username`,`core`.`fg_inter_transfer`.`timestamp` AS `timestamp`,`core`.`fg_inter_transfer`.`productid` AS `productid`,`core`.`fg_inter_transfer`.`productcode` AS `productcode`,`core`.`fg_inter_transfer`.`productname` AS `productname`,`core`.`fg_inter_transfer`.`bundle` AS `bundle`,`core`.`fg_inter_transfer`.`trucknumber` AS `trucknumber`,`core`.`fg_inter_transfer`.`to_company` AS `to_company`,`core`.`fg_inter_transfer`.`from_company` AS `from_company`,`core`.`fg_inter_transfer`.`dateoftransfer` AS `dateoftransfer`,`core`.`fg_inter_transfer`.`barcode` AS `barcode`,`core`.`fg_inter_transfer`.`transfer_number` AS `transfer_number` from `core`.`fg_inter_transfer` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `jumboreel_customers`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `jumboreel_customers` AS select `core`.`jumboreel_customers`.`id` AS `id`,`core`.`jumboreel_customers`.`customername` AS `customername`,`core`.`jumboreel_customers`.`customerlabel` AS `customerlabel`,`core`.`jumboreel_customers`.`customercountry` AS `customercountry`,`core`.`jumboreel_customers`.`customeraddress` AS `customeraddress`,`core`.`jumboreel_customers`.`customertelephone` AS `customertelephone` from `core`.`jumboreel_customers` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `jumboreel_factoryexit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `jumboreel_factoryexit` AS select `core`.`jumboreel_factoryexit`.`id` AS `id`,`core`.`jumboreel_factoryexit`.`username` AS `username`,`core`.`jumboreel_factoryexit`.`barcode` AS `barcode`,`core`.`jumboreel_factoryexit`.`exitlocation` AS `exitlocation`,`core`.`jumboreel_factoryexit`.`dateofexit` AS `dateofexit`,`core`.`jumboreel_factoryexit`.`status` AS `status`,`core`.`jumboreel_factoryexit`.`timestamp` AS `timestamp` from `core`.`jumboreel_factoryexit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `jumboreel_storeentrance`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `jumboreel_storeentrance` AS select `core`.`jumboreel_storeentrance`.`id` AS `id`,`core`.`jumboreel_storeentrance`.`username` AS `username`,`core`.`jumboreel_storeentrance`.`barcode` AS `barcode`,`core`.`jumboreel_storeentrance`.`entrancelocation` AS `entrancelocation`,`core`.`jumboreel_storeentrance`.`dateofentrance` AS `dateofentrance`,`core`.`jumboreel_storeentrance`.`status` AS `status`,`core`.`jumboreel_storeentrance`.`timestamp` AS `timestamp` from `core`.`jumboreel_storeentrance` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `local_governments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `local_governments` AS select `core`.`local_governments`.`id` AS `id`,`core`.`local_governments`.`state_id` AS `state_id`,`core`.`local_governments`.`name` AS `name` from `core`.`local_governments` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `logs`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `logs` AS select `core`.`logs`.`id` AS `id`,`core`.`logs`.`channel` AS `channel`,`core`.`logs`.`level` AS `level`,`core`.`logs`.`message` AS `message`,`core`.`logs`.`time` AS `time`,`core`.`logs`.`user` AS `user`,`core`.`logs`.`data` AS `data`,`core`.`logs`.`system` AS `system`,`core`.`logs`.`ip` AS `ip` from `core`.`logs` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterial_inter_transfer`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterial_inter_transfer` AS select `core`.`rawmaterial_inter_transfer`.`id` AS `id`,`core`.`rawmaterial_inter_transfer`.`username` AS `username`,`core`.`rawmaterial_inter_transfer`.`barcode` AS `barcode`,`core`.`rawmaterial_inter_transfer`.`dateoftransfer` AS `dateoftransfer`,`core`.`rawmaterial_inter_transfer`.`timestamp` AS `timestamp`,`core`.`rawmaterial_inter_transfer`.`to_company` AS `to_company`,`core`.`rawmaterial_inter_transfer`.`from_company` AS `from_company`,`core`.`rawmaterial_inter_transfer`.`productid` AS `productid` from `core`.`rawmaterial_inter_transfer` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials` AS select `rawmaterials_warehouse_entry`.`id` AS `id`,`rawmaterials_warehouse_entry`.`username` AS `username`,`rawmaterials_warehouse_entry`.`suppliercode` AS `suppliercode`,`rawmaterials_warehouse_entry`.`productid` AS `productid`,`rawmaterials_warehouse_entry`.`barcode` AS `barcode`,`rawmaterials_warehouse_entry`.`weight` AS `weight`,`rawmaterials_warehouse_entry`.`location_id` AS `location_id`,`rawmaterials_warehouse_entry`.`dateofcreation` AS `dateofcreation`,`rawmaterials_warehouse_entry`.`status` AS `status`,`rawmaterials_warehouse_entry`.`sub_barcode` AS `sub_barcode`,`rawmaterials_warehouse_entry`.`timestamp` AS `timestamp` from `rawmaterials_warehouse_entry` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_copy`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_copy` AS select `rawmaterials_supplier_deliveries`.`id` AS `id`,`rawmaterials_supplier_deliveries`.`username` AS `username`,`rawmaterials_supplier_deliveries`.`suppliercode` AS `suppliercode`,`rawmaterials_supplier_deliveries`.`productid` AS `productid`,`rawmaterials_supplier_deliveries`.`barcode` AS `barcode`,`rawmaterials_supplier_deliveries`.`weight` AS `weight`,`rawmaterials_supplier_deliveries`.`dateofcreation` AS `dateofcreation`,`rawmaterials_supplier_deliveries`.`location` AS `location`,`rawmaterials_supplier_deliveries`.`status` AS `status`,`rawmaterials_supplier_deliveries`.`timestamp` AS `timestamp`,`rawmaterials_supplier_deliveries`.`sub_barcode` AS `sub_barcode`,`rawmaterials_supplier_deliveries`.`location_id` AS `location_id` from `rawmaterials_supplier_deliveries` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_inter_received`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_inter_received` AS select `core`.`rawmaterials_inter_received`.`id` AS `id`,`core`.`rawmaterials_inter_received`.`username` AS `username`,`core`.`rawmaterials_inter_received`.`suppliercode` AS `suppliercode`,`core`.`rawmaterials_inter_received`.`productid` AS `productid`,`core`.`rawmaterials_inter_received`.`barcode` AS `barcode`,`core`.`rawmaterials_inter_received`.`weight` AS `weight`,`core`.`rawmaterials_inter_received`.`dateofcreation` AS `dateofcreation`,`core`.`rawmaterials_inter_received`.`location` AS `location`,`core`.`rawmaterials_inter_received`.`status` AS `status`,`core`.`rawmaterials_inter_received`.`timestamp` AS `timestamp`,`core`.`rawmaterials_inter_received`.`sub_barcode` AS `sub_barcode`,`core`.`rawmaterials_inter_received`.`location_id` AS `location_id`,`core`.`rawmaterials_inter_received`.`company_id` AS `company_id` from `core`.`rawmaterials_inter_received` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_store_exit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_store_exit` AS select `rawmaterials_warehouse_exit`.`id` AS `id`,`rawmaterials_warehouse_exit`.`user` AS `user`,`rawmaterials_warehouse_exit`.`barcode` AS `barcode`,`rawmaterials_warehouse_exit`.`location_id` AS `location_id`,`rawmaterials_warehouse_exit`.`status` AS `status`,`rawmaterials_warehouse_exit`.`dateofcreation` AS `dateofcreation`,`rawmaterials_warehouse_exit`.`created_at` AS `created_at`,`rawmaterials_warehouse_exit`.`updated_at` AS `updated_at` from `rawmaterials_warehouse_exit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `softroll_stock`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `softroll_stock` AS select `bpl`.`softroll_stock`.`id` AS `id`,`bpl`.`softroll_stock`.`location_id` AS `location_id`,`bpl`.`softroll_stock`.`grade_id` AS `grade_id`,`bpl`.`softroll_stock`.`quantity` AS `quantity`,`bpl`.`softroll_stock`.`weight` AS `weight` from `bpl`.`softroll_stock` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `softroll_storeentrance`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `softroll_storeentrance` AS select `bpl`.`softroll_storeentrance`.`id` AS `id`,`bpl`.`softroll_storeentrance`.`user` AS `user`,`bpl`.`softroll_storeentrance`.`barcode` AS `barcode`,`bpl`.`softroll_storeentrance`.`location_id` AS `location_id`,`bpl`.`softroll_storeentrance`.`date` AS `date`,`bpl`.`softroll_storeentrance`.`status` AS `status`,`bpl`.`softroll_storeentrance`.`created_at` AS `created_at`,`bpl`.`softroll_storeentrance`.`updated_at` AS `updated_at`,`bpl`.`softroll_storeentrance`.`deleted_at` AS `deleted_at` from `bpl`.`softroll_storeentrance` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `softroll_storeexit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `softroll_storeexit` AS select `bpl`.`softroll_storeexit`.`id` AS `id`,`bpl`.`softroll_storeexit`.`user` AS `user`,`bpl`.`softroll_storeexit`.`barcode` AS `barcode`,`bpl`.`softroll_storeexit`.`location_id` AS `location_id`,`bpl`.`softroll_storeexit`.`date` AS `date`,`bpl`.`softroll_storeexit`.`status` AS `status`,`bpl`.`softroll_storeexit`.`created_at` AS `created_at`,`bpl`.`softroll_storeexit`.`updated_at` AS `updated_at`,`bpl`.`softroll_storeexit`.`deleted_at` AS `deleted_at` from `bpl`.`softroll_storeexit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `states`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `states` AS select `core`.`states`.`id` AS `id`,`core`.`states`.`name` AS `name` from `core`.`states` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `stock`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `stock` AS select `bpl`.`stock`.`id` AS `id`,`bpl`.`stock`.`warehousecode` AS `warehousecode`,`bpl`.`stock`.`productid` AS `productid`,`bpl`.`stock`.`productcode` AS `productcode`,`bpl`.`stock`.`opening` AS `opening`,`bpl`.`stock`.`closing` AS `closing`,`bpl`.`stock`.`date` AS `date`,`bpl`.`stock`.`timestamp` AS `timestamp` from `bpl`.`stock` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `transfer_company_from`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `transfer_company_from` AS select `core`.`transfer_company_from`.`id` AS `id`,`core`.`transfer_company_from`.`company_name` AS `company_name` from `core`.`transfer_company_from` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `transfer_company_to`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `transfer_company_to` AS select `core`.`transfer_company_to`.`id` AS `id`,`core`.`transfer_company_to`.`company_name` AS `company_name` from `core`.`transfer_company_to` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `user`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `user` AS select `core`.`user`.`userid` AS `userid`,`core`.`user`.`username` AS `username`,`core`.`user`.`fullname` AS `fullname`,`core`.`user`.`email` AS `email`,`core`.`user`.`password` AS `password`,`core`.`user`.`userlevel` AS `userlevel`,`core`.`user`.`redirection_id` AS `redirection_id`,`core`.`user`.`created_at` AS `created_at` from `core`.`user` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `userlevels`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `userlevels` AS select `core`.`userlevels`.`level` AS `level`,`core`.`userlevels`.`default_user` AS `default_user`,`core`.`userlevels`.`role_description` AS `role_description` from `core`.`userlevels` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `wp_b2b`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wp_b2b` AS select `bpl`.`wp_b2b`.`id` AS `id`,`bpl`.`wp_b2b`.`b2b` AS `b2b`,`bpl`.`wp_b2b`.`created_at` AS `created_at` from `bpl`.`wp_b2b` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `wp_data`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wp_data` AS select `bpl`.`wp_data`.`id` AS `id`,`bpl`.`wp_data`.`supplier_id` AS `supplier_id`,`bpl`.`wp_data`.`grade_id` AS `grade_id`,`bpl`.`wp_data`.`quantity` AS `quantity`,`bpl`.`wp_data`.`wetness` AS `wetness`,`bpl`.`wp_data`.`unit_price` AS `unit_price`,`bpl`.`wp_data`.`cash_advance` AS `cash_advance`,`bpl`.`wp_data`.`total_amount` AS `total_amount`,`bpl`.`wp_data`.`paper_type_id` AS `paper_type_id`,`bpl`.`wp_data`.`dumping_site_id` AS `dumping_site_id`,`bpl`.`wp_data`.`b2b_id` AS `b2b_id`,`bpl`.`wp_data`.`date` AS `date`,`bpl`.`wp_data`.`additional` AS `additional`,`bpl`.`wp_data`.`special_agent_transporter` AS `special_agent_transporter`,`bpl`.`wp_data`.`weather_conditional` AS `weather_conditional`,`bpl`.`wp_data`.`bpl_situation` AS `bpl_situation`,`bpl`.`wp_data`.`commercial_situation` AS `commercial_situation`,`bpl`.`wp_data`.`created_at` AS `created_at`,`bpl`.`wp_data`.`updated_at` AS `updated_at`,`bpl`.`wp_data`.`addition` AS `addition`,`bpl`.`wp_data`.`additional_amount` AS `additional_amount`,`bpl`.`wp_data`.`last_edit_date` AS `last_edit_date`,`bpl`.`wp_data`.`comment` AS `comment` from `bpl`.`wp_data` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `wp_grades`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wp_grades` AS select `bpl`.`wp_grades`.`id` AS `id`,`bpl`.`wp_grades`.`grade_name` AS `grade_name`,`bpl`.`wp_grades`.`created_at` AS `created_at`,`bpl`.`wp_grades`.`updated_at` AS `updated_at` from `bpl`.`wp_grades` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `wp_papertype`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wp_papertype` AS select `bpl`.`wp_papertype`.`id` AS `id`,`bpl`.`wp_papertype`.`paper_type` AS `paper_type`,`bpl`.`wp_papertype`.`created_at` AS `created_at` from `bpl`.`wp_papertype` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `wp_states`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wp_states` AS select `bpl`.`wp_states`.`id` AS `id`,`bpl`.`wp_states`.`state` AS `state`,`bpl`.`wp_states`.`created_at` AS `created_at` from `bpl`.`wp_states` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `wp_suppliers`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wp_suppliers` AS select `bpl`.`wp_suppliers`.`id` AS `id`,`bpl`.`wp_suppliers`.`supplier_code` AS `supplier_code`,`bpl`.`wp_suppliers`.`supplier_name` AS `supplier_name`,`bpl`.`wp_suppliers`.`supplier_phonenumber` AS `supplier_phonenumber`,`bpl`.`wp_suppliers`.`created_at` AS `created_at`,`bpl`.`wp_suppliers`.`supplier_state` AS `supplier_state` from `bpl`.`wp_suppliers` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

