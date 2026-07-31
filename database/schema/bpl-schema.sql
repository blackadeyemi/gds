-- gds legacy schema: bpl database (STRUCTURE ONLY — no data).
-- Non-authoritative documentation of the legacy MySQL schema the app connects to.
-- Regenerate: mysqldump --no-data --skip-comments --routines --events bpl
-- NOTE: the 'core' DB is NOT dumped here — it is defined by database/migrations.


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
DROP TABLE IF EXISTS `bpl_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `beneficiary` int NOT NULL,
  `intermediary` int DEFAULT NULL,
  `correspondent` int DEFAULT NULL,
  `further_acc` varchar(100) DEFAULT NULL,
  `currency_id` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account` (`beneficiary`,`currency_id`) USING BTREE,
  KEY `beneficiary` (`beneficiary`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_banks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `number` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `sortcode` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `swiftcode` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `number` (`number`),
  UNIQUE KEY `account` (`name`,`number`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_bill_of_lading`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_bill_of_lading` (
  `id` int NOT NULL AUTO_INCREMENT,
  `packing_id` int NOT NULL,
  `number` varchar(100) NOT NULL,
  `issued_date` varchar(11) NOT NULL,
  `shipped_date` varchar(11) NOT NULL,
  `arrival_date` varchar(11) DEFAULT NULL,
  `doc` varchar(50) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`packing_id`),
  UNIQUE KEY `ladingNumber` (`number`)
) ENGINE=InnoDB AUTO_INCREMENT=388 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(10) DEFAULT NULL,
  `customername` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `customerlabel` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `customercountry` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `customeraddress` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `customertelephone` varchar(30) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `port` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `fax` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `email` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `products` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customername` (`customername`),
  UNIQUE KEY `customerlabel` (`customerlabel`)
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_delivery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_delivery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(255) NOT NULL,
  `date` varchar(255) NOT NULL,
  `vechile_number` varchar(255) DEFAULT NULL,
  `loader` varchar(255) DEFAULT NULL,
  `driver` varchar(255) DEFAULT NULL,
  `truck_weight` varchar(255) DEFAULT NULL,
  `created_at` varchar(255) DEFAULT NULL,
  `updated_at` varchar(255) DEFAULT NULL,
  `container_number` varchar(255) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=1243 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_delivery_barcode`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_delivery_barcode` (
  `id` int NOT NULL AUTO_INCREMENT,
  `delivery_barcode` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `deleted_at` varchar(255) NOT NULL,
  `created_at` varchar(255) NOT NULL,
  `updated_at` varchar(255) NOT NULL,
  `date` varchar(255) NOT NULL,
  `full_weight` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=1239 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_delivery_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_delivery_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bpl_delivery_id` varchar(255) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `product_id` varchar(255) NOT NULL,
  `delivery_barcode` varchar(255) NOT NULL,
  `created_at` varchar(255) DEFAULT NULL,
  `updated_at` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=24124 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_factoryexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_factoryexit` (
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
) ENGINE=InnoDB AUTO_INCREMENT=136331 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_grades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `gradename` tinytext CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `type` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `grade` varchar(20) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gradetype` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_invoice_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_invoice_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `packing_id` int NOT NULL,
  `amount` float NOT NULL,
  `date` varchar(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `packing_id` (`packing_id`)
) ENGINE=InnoDB AUTO_INCREMENT=333 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_packing_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_packing_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `number` varchar(100) NOT NULL,
  `containers` json NOT NULL,
  `date` varchar(11) NOT NULL,
  `split` set('0','1') NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `IDENTIFIER` (`number`) USING BTREE,
  UNIQUE KEY `UNIQUE` (`order_id`,`number`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=637 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_payment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_payment_terms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_terms` varchar(150) NOT NULL,
  `days` int NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `terms` (`payment_terms`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_production`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_production` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `customer_id` int NOT NULL,
  `papermachine` varchar(50) NOT NULL,
  `product_id` int NOT NULL,
  `hardrollnumber` varchar(50) NOT NULL,
  `barcode` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `brightness` float NOT NULL,
  `corediameter` float DEFAULT NULL,
  `joints` int NOT NULL,
  `paperweight` float NOT NULL,
  `weight` float NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `hold` varchar(30) DEFAULT NULL,
  `comments` varchar(255) DEFAULT NULL,
  `dateofmanufacture` varchar(20) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `net_weight` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hardrollnumber` (`hardrollnumber`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=293489 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_products_hardroll`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_products_hardroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `old` varchar(200) DEFAULT NULL,
  `productname` varchar(200) NOT NULL,
  `gradetype` varchar(20) NOT NULL,
  `brightness` float DEFAULT NULL,
  `gsm` float NOT NULL,
  `ply` int NOT NULL,
  `width` float NOT NULL,
  `diameter` float NOT NULL,
  `slice` int NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productname` (`productname`)
) ENGINE=InnoDB AUTO_INCREMENT=5278 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_products_softroll`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_products_softroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `productname` varchar(200) NOT NULL,
  `grade_id` int NOT NULL,
  `grammage` varchar(255) NOT NULL,
  `diameter` varchar(255) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `productname` (`productname`),
  KEY `grade_id` (`grade_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_proforma`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_proforma` (
  `order_id` int NOT NULL,
  `customer_ref` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `freight` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `container` int NOT NULL,
  `freight_price` float NOT NULL,
  `terms` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `shipment` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `payment_term_id` int NOT NULL,
  `nxp` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `currency_id` int NOT NULL,
  `account_id` int NOT NULL,
  `date` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_proforma_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_proforma_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_item_id` int NOT NULL,
  `price` float NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_item_id` (`order_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=655 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_quarantine`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_quarantine` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `barcode` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `location_id` tinyint DEFAULT NULL,
  `date` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_quarantine_storeexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_quarantine_storeexit` (
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `customerid` int NOT NULL,
  `company` varchar(255) DEFAULT NULL,
  `date` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ref` (`ref`,`customerid`)
) ENGINE=InnoDB AUTO_INCREMENT=456 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_sales_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_sales_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `productid` int DEFAULT NULL,
  `weight` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=718 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_softroll_factoryexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_softroll_factoryexit` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1599 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_softroll_production`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_softroll_production` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `softrollnumber` varchar(50) NOT NULL,
  `grade_id` int NOT NULL,
  `barcode` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `brightness` float NOT NULL,
  `weight` float NOT NULL,
  `grammage` varchar(255) NOT NULL,
  `diameter` varchar(255) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `dateofmanufacture` varchar(20) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `papermachine` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hardrollnumber` (`softrollnumber`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=1713 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location_id` tinyint NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int unsigned NOT NULL,
  `weight` double NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`location_id`,`product_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=384 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_stock_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_stock_locations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` tinyint NOT NULL,
  `location` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `location` (`location`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_store_count`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_store_count` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1250 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_storeentrance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_storeentrance` (
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
) ENGINE=InnoDB AUTO_INCREMENT=138782 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_storeentrance_trash`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_storeentrance_trash` (
  `id` int NOT NULL AUTO_INCREMENT,
  `deletion_id` varchar(255) NOT NULL,
  `created_at` varchar(255) NOT NULL,
  `user` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3298 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_storeentrance_trash_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_storeentrance_trash_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `deletion_id` varchar(255) NOT NULL,
  `date_of_entrance` timestamp(6) NOT NULL,
  `barcode` varchar(1255) NOT NULL,
  `productname` varchar(255) NOT NULL,
  `gradetype` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `weight` int NOT NULL,
  `created_at` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=8713 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_storeexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_storeexit` (
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
) ENGINE=InnoDB AUTO_INCREMENT=127931 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_transporters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_transporters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transportername` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transportername` (`transportername`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_waybill_payment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_waybill_payment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barcode` varchar(199) NOT NULL,
  `transporter_id` int NOT NULL,
  `amount` float NOT NULL,
  `amount_paid` float NOT NULL,
  `balance` float NOT NULL,
  `status` enum('Paid','Partial','Outstanding','') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `customer_name` varchar(199) DEFAULT NULL,
  `vechicle_number` varchar(199) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bpl_waybill_payment_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bpl_waybill_payment_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `waybill_payment_id` int NOT NULL,
  `amount` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `payment_date` date NOT NULL,
  `payment_note` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `dumping_site`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dumping_site` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dump_site` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factory_details`;
/*!50001 DROP VIEW IF EXISTS `factory_details`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_details` AS SELECT 
 1 AS `id`,
 1 AS `location`,
 1 AS `linename`,
 1 AS `sublinename`,
 1 AS `linecode`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_entrance_reel`;
/*!50001 DROP VIEW IF EXISTS `factory_entrance_reel`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_entrance_reel` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `location`,
 1 AS `barcode`,
 1 AS `dateofentrance`,
 1 AS `status`,
 1 AS `is_deleted`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_lines`;
/*!50001 DROP VIEW IF EXISTS `factory_lines`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_lines` AS SELECT 
 1 AS `id`,
 1 AS `factoryname`,
 1 AS `linename`,
 1 AS `linecode`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_preproduction`;
/*!50001 DROP VIEW IF EXISTS `factory_preproduction`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_preproduction` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `productname`,
 1 AS `linename`,
 1 AS `bundles`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_projects`;
/*!50001 DROP VIEW IF EXISTS `factory_projects`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_projects` AS SELECT 
 1 AS `id`,
 1 AS `lineid`,
 1 AS `linename`,
 1 AS `sublinename`,
 1 AS `project`,
 1 AS `code`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_staff`;
/*!50001 DROP VIEW IF EXISTS `factory_staff`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_staff` AS SELECT 
 1 AS `id`,
 1 AS `staff_id`,
 1 AS `name`,
 1 AS `department`,
 1 AS `division`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_sublines`;
/*!50001 DROP VIEW IF EXISTS `factory_sublines`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_sublines` AS SELECT 
 1 AS `id`,
 1 AS `lineid`,
 1 AS `linename`,
 1 AS `sublinename`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_subprojects`;
/*!50001 DROP VIEW IF EXISTS `factory_subprojects`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_subprojects` AS SELECT 
 1 AS `id`,
 1 AS `lineid`,
 1 AS `linename`,
 1 AS `sublinename`,
 1 AS `project`,
 1 AS `projectcode`,
 1 AS `subproject`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `factory_usage_rawmaterials`;
/*!50001 DROP VIEW IF EXISTS `factory_usage_rawmaterials`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `factory_usage_rawmaterials` AS SELECT 
 1 AS `id`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `shift`,
 1 AS `location`,
 1 AS `linename`,
 1 AS `project`,
 1 AS `pre_productname`,
 1 AS `weight`,
 1 AS `dateofuse`,
 1 AS `is_deleted`,
 1 AS `timestamp`,
 1 AS `status`*/;
SET character_set_client = @saved_cs_client;
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
/*!50001 DROP VIEW IF EXISTS `products`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `products` AS SELECT 
 1 AS `productid`,
 1 AS `productname`,
 1 AS `productcode`,
 1 AS `productbundles`,
 1 AS `productgroup`,
 1 AS `basepaper`,
 1 AS `mach`,
 1 AS `embossing`,
 1 AS `lamedge`,
 1 AS `revnumber`,
 1 AS `revdate`,
 1 AS `productrolls`,
 1 AS `productpacks`,
 1 AS `gsm`,
 1 AS `logweight`,
 1 AS `sheetwidth`,
 1 AS `sheetlength`,
 1 AS `clipweight`,
 1 AS `actualrollweight`,
 1 AS `rolllength`,
 1 AS `coreweight`,
 1 AS `corediameter`,
 1 AS `diameter`,
 1 AS `perimeter`,
 1 AS `pulls`,
 1 AS `netweight`,
 1 AS `hardrollsource`,
 1 AS `ply`,
 1 AS `hardrollwidth`,
 1 AS `rollsperbundle`,
 1 AS `wrapperweight`,
 1 AS `polybagweight`,
 1 AS `polybundleweight`,
 1 AS `sheetcounts`,
 1 AS `grossweight`,
 1 AS `hardrollgsm`,
 1 AS `waste`,
 1 AS `bundlespertonne`,
 1 AS `imagepath`,
 1 AS `timestamp`,
 1 AS `is_deleted`*/;
SET character_set_client = @saved_cs_client;
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
/*!50001 DROP VIEW IF EXISTS `rawmaterial_store_location`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterial_store_location` AS SELECT 
 1 AS `id`,
 1 AS `location`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;
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
/*!50001 DROP VIEW IF EXISTS `rawmaterials_groups`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_groups` AS SELECT 
 1 AS `id`,
 1 AS `groupname`,
 1 AS `groupcode`*/;
SET character_set_client = @saved_cs_client;
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
/*!50001 DROP VIEW IF EXISTS `rawmaterials_products`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_products` AS SELECT 
 1 AS `id`,
 1 AS `storecode`,
 1 AS `productname`,
 1 AS `accountcode`,
 1 AS `groupid`,
 1 AS `subgroupid`,
 1 AS `common`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterials_stock`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_stock`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_stock` AS SELECT 
 1 AS `id`,
 1 AS `location`,
 1 AS `productid`,
 1 AS `quantity`,
 1 AS `weight`,
 1 AS `modification`*/;
SET character_set_client = @saved_cs_client;
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
DROP TABLE IF EXISTS `rawmaterials_subgroups`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_subgroups`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_subgroups` AS SELECT 
 1 AS `id`,
 1 AS `subgroupname`,
 1 AS `subgroupcode`,
 1 AS `groupid`,
 1 AS `sub_barcode`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `rawmaterials_supplier`;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_supplier`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `rawmaterials_supplier` AS SELECT 
 1 AS `id`,
 1 AS `supplierid`,
 1 AS `suppliername`,
 1 AS `suppliercode`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `reprint_approval`;
/*!50001 DROP VIEW IF EXISTS `reprint_approval`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `reprint_approval` AS SELECT 
 1 AS `id`,
 1 AS `sequence_number`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `product`,
 1 AS `weight`,
 1 AS `message`,
 1 AS `dateofcreation`,
 1 AS `type`,
 1 AS `timestamp`,
 1 AS `status`,
 1 AS `authorizer`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `return_approval`;
/*!50001 DROP VIEW IF EXISTS `return_approval`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `return_approval` AS SELECT 
 1 AS `id`,
 1 AS `sequence_number`,
 1 AS `user`,
 1 AS `barcode`,
 1 AS `product`,
 1 AS `weight`,
 1 AS `message`,
 1 AS `dateofcreation`,
 1 AS `type`,
 1 AS `timestamp`,
 1 AS `status`,
 1 AS `authorizer`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_customers`;
/*!50001 DROP VIEW IF EXISTS `sales_customers`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_customers` AS SELECT 
 1 AS `id`,
 1 AS `customercode`,
 1 AS `customername`,
 1 AS `customeraddress`,
 1 AS `customerphonenumber`,
 1 AS `customercity`,
 1 AS `customerstate`,
 1 AS `customerdesignation`,
 1 AS `customerregion`,
 1 AS `customercountry`,
 1 AS `channel`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_delivery`;
/*!50001 DROP VIEW IF EXISTS `sales_delivery`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_delivery` AS SELECT 
 1 AS `id`,
 1 AS `deliverynumber`,
 1 AS `barcode`,
 1 AS `username`,
 1 AS `loadnumber`,
 1 AS `dateofdelivery`,
 1 AS `timestamp`,
 1 AS `deliverycustomerid`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_loading`;
/*!50001 DROP VIEW IF EXISTS `sales_loading`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_loading` AS SELECT 
 1 AS `id`,
 1 AS `loadnumber`,
 1 AS `loader`,
 1 AS `barcode`,
 1 AS `username`,
 1 AS `sod_id`,
 1 AS `cageroomcode`,
 1 AS `transporterid`,
 1 AS `trucknumber`,
 1 AS `truckdriver`,
 1 AS `quantityloaded`,
 1 AS `dateofloading`,
 1 AS `status`,
 1 AS `timestamp`,
 1 AS `sales_loading_customerid`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_loading_return`;
/*!50001 DROP VIEW IF EXISTS `sales_loading_return`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_loading_return` AS SELECT 
 1 AS `id`,
 1 AS `barcode`,
 1 AS `username`,
 1 AS `loading_id`,
 1 AS `sod_id`,
 1 AS `quantityunloaded`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_order`;
/*!50001 DROP VIEW IF EXISTS `sales_order`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_order` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `orderid`,
 1 AS `warehousecode`,
 1 AS `customerid`,
 1 AS `dateoforder`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_order_details`;
/*!50001 DROP VIEW IF EXISTS `sales_order_details`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_order_details` AS SELECT 
 1 AS `id`,
 1 AS `orderid`,
 1 AS `productid`,
 1 AS `quantityordered`,
 1 AS `foc`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_return`;
/*!50001 DROP VIEW IF EXISTS `sales_return`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_return` AS SELECT 
 1 AS `id`,
 1 AS `username`,
 1 AS `returnnumber`,
 1 AS `sod_id`,
 1 AS `quantityreturned`,
 1 AS `quantityrejected`,
 1 AS `dateofreturn`,
 1 AS `timestamp`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_transporters`;
/*!50001 DROP VIEW IF EXISTS `sales_transporters`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_transporters` AS SELECT 
 1 AS `id`,
 1 AS `transportername`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `sales_warehouse`;
/*!50001 DROP VIEW IF EXISTS `sales_warehouse`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `sales_warehouse` AS SELECT 
 1 AS `warehouselocation`,
 1 AS `warehousecode`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `softroll_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `softroll_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location_id` varchar(20) NOT NULL,
  `grade_id` varchar(255) NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `weight` double NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQUE KEYS` (`location_id`,`grade_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `softroll_storeentrance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `softroll_storeentrance` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1474 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `softroll_storeexit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `softroll_storeexit` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1564 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `states`;
/*!50001 DROP VIEW IF EXISTS `states`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `states` AS SELECT 
 1 AS `id`,
 1 AS `name`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `warehousecode` varchar(2) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `productid` int NOT NULL,
  `productcode` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `opening` int NOT NULL DEFAULT '0',
  `closing` int NOT NULL,
  `date` varchar(20) NOT NULL DEFAULT '2020/05/04',
  `timestamp` int NOT NULL DEFAULT '1589962267',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=257 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_cagerooms`;
/*!50001 DROP VIEW IF EXISTS `store_cagerooms`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `store_cagerooms` AS SELECT 
 1 AS `cageroomnumber`,
 1 AS `cageroomcode`,
 1 AS `warehousecode`*/;
SET character_set_client = @saved_cs_client;
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
DROP TABLE IF EXISTS `wp_b2b`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_b2b` (
  `id` int NOT NULL AUTO_INCREMENT,
  `b2b` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wp_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `grade_id` int NOT NULL,
  `quantity` varchar(191) NOT NULL,
  `wetness` varchar(191) NOT NULL,
  `unit_price` int NOT NULL,
  `cash_advance` varchar(191) DEFAULT NULL,
  `total_amount` varchar(191) NOT NULL,
  `paper_type_id` int NOT NULL,
  `dumping_site_id` int DEFAULT NULL,
  `b2b_id` int NOT NULL,
  `date` date NOT NULL,
  `additional` longtext,
  `special_agent_transporter` longtext,
  `weather_conditional` longtext,
  `bpl_situation` longtext,
  `commercial_situation` longtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `addition` varchar(199) DEFAULT NULL,
  `additional_amount` varchar(199) DEFAULT NULL,
  `last_edit_date` date DEFAULT NULL,
  `comment` longtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wp_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_grades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `grade_name` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wp_papertype`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_papertype` (
  `id` int NOT NULL AUTO_INCREMENT,
  `paper_type` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wp_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_states` (
  `id` int NOT NULL AUTO_INCREMENT,
  `state` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wp_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(191) NOT NULL,
  `supplier_name` varchar(191) NOT NULL,
  `supplier_phonenumber` json NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `supplier_state` varchar(30) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=646 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
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
/*!50001 DROP VIEW IF EXISTS `factory_details`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_details` AS select `bil`.`factory_details`.`id` AS `id`,`bil`.`factory_details`.`location` AS `location`,`bil`.`factory_details`.`linename` AS `linename`,`bil`.`factory_details`.`sublinename` AS `sublinename`,`bil`.`factory_details`.`linecode` AS `linecode` from `bil`.`factory_details` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_entrance_reel`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_entrance_reel` AS select `bil`.`factory_entrance_reel`.`id` AS `id`,`bil`.`factory_entrance_reel`.`user` AS `user`,`bil`.`factory_entrance_reel`.`location` AS `location`,`bil`.`factory_entrance_reel`.`barcode` AS `barcode`,`bil`.`factory_entrance_reel`.`dateofentrance` AS `dateofentrance`,`bil`.`factory_entrance_reel`.`status` AS `status`,`bil`.`factory_entrance_reel`.`is_deleted` AS `is_deleted`,`bil`.`factory_entrance_reel`.`timestamp` AS `timestamp` from `bil`.`factory_entrance_reel` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_lines`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_lines` AS select `bil`.`factory_lines`.`id` AS `id`,`bil`.`factory_lines`.`factoryname` AS `factoryname`,`bil`.`factory_lines`.`linename` AS `linename`,`bil`.`factory_lines`.`linecode` AS `linecode` from `bil`.`factory_lines` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_preproduction`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_preproduction` AS select `bil`.`factory_preproduction`.`id` AS `id`,`bil`.`factory_preproduction`.`username` AS `username`,`bil`.`factory_preproduction`.`productname` AS `productname`,`bil`.`factory_preproduction`.`linename` AS `linename`,`bil`.`factory_preproduction`.`bundles` AS `bundles`,`bil`.`factory_preproduction`.`timestamp` AS `timestamp` from `bil`.`factory_preproduction` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_projects`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_projects` AS select `bil`.`factory_projects`.`id` AS `id`,`bil`.`factory_projects`.`lineid` AS `lineid`,`bil`.`factory_projects`.`linename` AS `linename`,`bil`.`factory_projects`.`sublinename` AS `sublinename`,`bil`.`factory_projects`.`project` AS `project`,`bil`.`factory_projects`.`code` AS `code` from `bil`.`factory_projects` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_staff`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_staff` AS select `bil`.`factory_staff`.`id` AS `id`,`bil`.`factory_staff`.`staff_id` AS `staff_id`,`bil`.`factory_staff`.`name` AS `name`,`bil`.`factory_staff`.`department` AS `department`,`bil`.`factory_staff`.`division` AS `division` from `bil`.`factory_staff` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_sublines`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_sublines` AS select `bil`.`factory_sublines`.`id` AS `id`,`bil`.`factory_sublines`.`lineid` AS `lineid`,`bil`.`factory_sublines`.`linename` AS `linename`,`bil`.`factory_sublines`.`sublinename` AS `sublinename` from `bil`.`factory_sublines` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_subprojects`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_subprojects` AS select `bil`.`factory_subprojects`.`id` AS `id`,`bil`.`factory_subprojects`.`lineid` AS `lineid`,`bil`.`factory_subprojects`.`linename` AS `linename`,`bil`.`factory_subprojects`.`sublinename` AS `sublinename`,`bil`.`factory_subprojects`.`project` AS `project`,`bil`.`factory_subprojects`.`projectcode` AS `projectcode`,`bil`.`factory_subprojects`.`subproject` AS `subproject` from `bil`.`factory_subprojects` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factory_usage_rawmaterials`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factory_usage_rawmaterials` AS select `bil`.`factory_usage_rawmaterials`.`id` AS `id`,`bil`.`factory_usage_rawmaterials`.`user` AS `user`,`bil`.`factory_usage_rawmaterials`.`barcode` AS `barcode`,`bil`.`factory_usage_rawmaterials`.`shift` AS `shift`,`bil`.`factory_usage_rawmaterials`.`location` AS `location`,`bil`.`factory_usage_rawmaterials`.`linename` AS `linename`,`bil`.`factory_usage_rawmaterials`.`project` AS `project`,`bil`.`factory_usage_rawmaterials`.`pre_productname` AS `pre_productname`,`bil`.`factory_usage_rawmaterials`.`weight` AS `weight`,`bil`.`factory_usage_rawmaterials`.`dateofuse` AS `dateofuse`,`bil`.`factory_usage_rawmaterials`.`is_deleted` AS `is_deleted`,`bil`.`factory_usage_rawmaterials`.`timestamp` AS `timestamp`,`bil`.`factory_usage_rawmaterials`.`status` AS `status` from `bil`.`factory_usage_rawmaterials` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `factoryentrance_rawmaterial`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `factoryentrance_rawmaterial` AS select `bil`.`factoryentrance_rawmaterial`.`id` AS `id`,`bil`.`factoryentrance_rawmaterial`.`user_name` AS `user_name`,`bil`.`factoryentrance_rawmaterial`.`barcode` AS `barcode`,`bil`.`factoryentrance_rawmaterial`.`location_id` AS `location_id`,`bil`.`factoryentrance_rawmaterial`.`entrance_date` AS `entrance_date`,`bil`.`factoryentrance_rawmaterial`.`product_id` AS `product_id`,`bil`.`factoryentrance_rawmaterial`.`weight` AS `weight`,`bil`.`factoryentrance_rawmaterial`.`status` AS `status` from `bil`.`factoryentrance_rawmaterial` */;
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
/*!50001 DROP VIEW IF EXISTS `products`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `products` AS select `bil`.`products`.`productid` AS `productid`,`bil`.`products`.`productname` AS `productname`,`bil`.`products`.`productcode` AS `productcode`,`bil`.`products`.`productbundles` AS `productbundles`,`bil`.`products`.`productgroup` AS `productgroup`,`bil`.`products`.`basepaper` AS `basepaper`,`bil`.`products`.`mach` AS `mach`,`bil`.`products`.`embossing` AS `embossing`,`bil`.`products`.`lamedge` AS `lamedge`,`bil`.`products`.`revnumber` AS `revnumber`,`bil`.`products`.`revdate` AS `revdate`,`bil`.`products`.`productrolls` AS `productrolls`,`bil`.`products`.`productpacks` AS `productpacks`,`bil`.`products`.`gsm` AS `gsm`,`bil`.`products`.`logweight` AS `logweight`,`bil`.`products`.`sheetwidth` AS `sheetwidth`,`bil`.`products`.`sheetlength` AS `sheetlength`,`bil`.`products`.`clipweight` AS `clipweight`,`bil`.`products`.`actualrollweight` AS `actualrollweight`,`bil`.`products`.`rolllength` AS `rolllength`,`bil`.`products`.`coreweight` AS `coreweight`,`bil`.`products`.`corediameter` AS `corediameter`,`bil`.`products`.`diameter` AS `diameter`,`bil`.`products`.`perimeter` AS `perimeter`,`bil`.`products`.`pulls` AS `pulls`,`bil`.`products`.`netweight` AS `netweight`,`bil`.`products`.`hardrollsource` AS `hardrollsource`,`bil`.`products`.`ply` AS `ply`,`bil`.`products`.`hardrollwidth` AS `hardrollwidth`,`bil`.`products`.`rollsperbundle` AS `rollsperbundle`,`bil`.`products`.`wrapperweight` AS `wrapperweight`,`bil`.`products`.`polybagweight` AS `polybagweight`,`bil`.`products`.`polybundleweight` AS `polybundleweight`,`bil`.`products`.`sheetcounts` AS `sheetcounts`,`bil`.`products`.`grossweight` AS `grossweight`,`bil`.`products`.`hardrollgsm` AS `hardrollgsm`,`bil`.`products`.`waste` AS `waste`,`bil`.`products`.`bundlespertonne` AS `bundlespertonne`,`bil`.`products`.`imagepath` AS `imagepath`,`bil`.`products`.`timestamp` AS `timestamp`,`bil`.`products`.`is_deleted` AS `is_deleted` from `bil`.`products` */;
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
/*!50001 DROP VIEW IF EXISTS `rawmaterial_store_location`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterial_store_location` AS select `bil`.`rawmaterial_store_location`.`id` AS `id`,`bil`.`rawmaterial_store_location`.`location` AS `location`,`bil`.`rawmaterial_store_location`.`created_at` AS `created_at`,`bil`.`rawmaterial_store_location`.`updated_at` AS `updated_at` from `bil`.`rawmaterial_store_location` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials` AS select `bil`.`rawmaterials`.`id` AS `id`,`bil`.`rawmaterials`.`username` AS `username`,`bil`.`rawmaterials`.`suppliercode` AS `suppliercode`,`bil`.`rawmaterials`.`productid` AS `productid`,`bil`.`rawmaterials`.`barcode` AS `barcode`,`bil`.`rawmaterials`.`weight` AS `weight`,`bil`.`rawmaterials`.`location_id` AS `location_id`,`bil`.`rawmaterials`.`dateofcreation` AS `dateofcreation`,`bil`.`rawmaterials`.`status` AS `status`,`bil`.`rawmaterials`.`sub_barcode` AS `sub_barcode`,`bil`.`rawmaterials`.`timestamp` AS `timestamp` from `bil`.`rawmaterials` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_copy`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_copy` AS select `bil`.`rawmaterials_copy`.`id` AS `id`,`bil`.`rawmaterials_copy`.`username` AS `username`,`bil`.`rawmaterials_copy`.`suppliercode` AS `suppliercode`,`bil`.`rawmaterials_copy`.`productid` AS `productid`,`bil`.`rawmaterials_copy`.`barcode` AS `barcode`,`bil`.`rawmaterials_copy`.`weight` AS `weight`,`bil`.`rawmaterials_copy`.`dateofcreation` AS `dateofcreation`,`bil`.`rawmaterials_copy`.`location` AS `location`,`bil`.`rawmaterials_copy`.`status` AS `status`,`bil`.`rawmaterials_copy`.`timestamp` AS `timestamp`,`bil`.`rawmaterials_copy`.`sub_barcode` AS `sub_barcode`,`bil`.`rawmaterials_copy`.`location_id` AS `location_id` from `bil`.`rawmaterials_copy` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_groups`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_groups` AS select `bil`.`rawmaterials_groups`.`id` AS `id`,`bil`.`rawmaterials_groups`.`groupname` AS `groupname`,`bil`.`rawmaterials_groups`.`groupcode` AS `groupcode` from `bil`.`rawmaterials_groups` */;
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
/*!50001 DROP VIEW IF EXISTS `rawmaterials_products`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_products` AS select `bil`.`rawmaterials_products`.`id` AS `id`,`bil`.`rawmaterials_products`.`storecode` AS `storecode`,`bil`.`rawmaterials_products`.`productname` AS `productname`,`bil`.`rawmaterials_products`.`accountcode` AS `accountcode`,`bil`.`rawmaterials_products`.`groupid` AS `groupid`,`bil`.`rawmaterials_products`.`subgroupid` AS `subgroupid`,`bil`.`rawmaterials_products`.`common` AS `common` from `bil`.`rawmaterials_products` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_stock`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_stock` AS select `bil`.`rawmaterials_stock`.`id` AS `id`,`bil`.`rawmaterials_stock`.`location` AS `location`,`bil`.`rawmaterials_stock`.`productid` AS `productid`,`bil`.`rawmaterials_stock`.`quantity` AS `quantity`,`bil`.`rawmaterials_stock`.`weight` AS `weight`,`bil`.`rawmaterials_stock`.`modification` AS `modification` from `bil`.`rawmaterials_stock` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_store_exit`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_store_exit` AS select `bil`.`rawmaterials_store_exit`.`id` AS `id`,`bil`.`rawmaterials_store_exit`.`user` AS `user`,`bil`.`rawmaterials_store_exit`.`barcode` AS `barcode`,`bil`.`rawmaterials_store_exit`.`location_id` AS `location_id`,`bil`.`rawmaterials_store_exit`.`status` AS `status`,`bil`.`rawmaterials_store_exit`.`dateofcreation` AS `dateofcreation`,`bil`.`rawmaterials_store_exit`.`created_at` AS `created_at`,`bil`.`rawmaterials_store_exit`.`updated_at` AS `updated_at` from `bil`.`rawmaterials_store_exit` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_subgroups`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_subgroups` AS select `bil`.`rawmaterials_subgroups`.`id` AS `id`,`bil`.`rawmaterials_subgroups`.`subgroupname` AS `subgroupname`,`bil`.`rawmaterials_subgroups`.`subgroupcode` AS `subgroupcode`,`bil`.`rawmaterials_subgroups`.`groupid` AS `groupid`,`bil`.`rawmaterials_subgroups`.`sub_barcode` AS `sub_barcode` from `bil`.`rawmaterials_subgroups` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `rawmaterials_supplier`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `rawmaterials_supplier` AS select `bil`.`rawmaterials_supplier`.`id` AS `id`,`bil`.`rawmaterials_supplier`.`supplierid` AS `supplierid`,`bil`.`rawmaterials_supplier`.`suppliername` AS `suppliername`,`bil`.`rawmaterials_supplier`.`suppliercode` AS `suppliercode` from `bil`.`rawmaterials_supplier` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `reprint_approval`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `reprint_approval` AS select `bil`.`reprint_approval`.`id` AS `id`,`bil`.`reprint_approval`.`sequence_number` AS `sequence_number`,`bil`.`reprint_approval`.`user` AS `user`,`bil`.`reprint_approval`.`barcode` AS `barcode`,`bil`.`reprint_approval`.`product` AS `product`,`bil`.`reprint_approval`.`weight` AS `weight`,`bil`.`reprint_approval`.`message` AS `message`,`bil`.`reprint_approval`.`dateofcreation` AS `dateofcreation`,`bil`.`reprint_approval`.`type` AS `type`,`bil`.`reprint_approval`.`timestamp` AS `timestamp`,`bil`.`reprint_approval`.`status` AS `status`,`bil`.`reprint_approval`.`authorizer` AS `authorizer` from `bil`.`reprint_approval` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `return_approval`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `return_approval` AS select `bil`.`return_approval`.`id` AS `id`,`bil`.`return_approval`.`sequence_number` AS `sequence_number`,`bil`.`return_approval`.`user` AS `user`,`bil`.`return_approval`.`barcode` AS `barcode`,`bil`.`return_approval`.`product` AS `product`,`bil`.`return_approval`.`weight` AS `weight`,`bil`.`return_approval`.`message` AS `message`,`bil`.`return_approval`.`dateofcreation` AS `dateofcreation`,`bil`.`return_approval`.`type` AS `type`,`bil`.`return_approval`.`timestamp` AS `timestamp`,`bil`.`return_approval`.`status` AS `status`,`bil`.`return_approval`.`authorizer` AS `authorizer` from `bil`.`return_approval` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_customers`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_customers` AS select `bil`.`sales_customers`.`id` AS `id`,`bil`.`sales_customers`.`customercode` AS `customercode`,`bil`.`sales_customers`.`customername` AS `customername`,`bil`.`sales_customers`.`customeraddress` AS `customeraddress`,`bil`.`sales_customers`.`customerphonenumber` AS `customerphonenumber`,`bil`.`sales_customers`.`customercity` AS `customercity`,`bil`.`sales_customers`.`customerstate` AS `customerstate`,`bil`.`sales_customers`.`customerdesignation` AS `customerdesignation`,`bil`.`sales_customers`.`customerregion` AS `customerregion`,`bil`.`sales_customers`.`customercountry` AS `customercountry`,`bil`.`sales_customers`.`channel` AS `channel`,`bil`.`sales_customers`.`created_at` AS `created_at` from `bil`.`sales_customers` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_delivery`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_delivery` AS select `bil`.`sales_delivery`.`id` AS `id`,`bil`.`sales_delivery`.`deliverynumber` AS `deliverynumber`,`bil`.`sales_delivery`.`barcode` AS `barcode`,`bil`.`sales_delivery`.`username` AS `username`,`bil`.`sales_delivery`.`loadnumber` AS `loadnumber`,`bil`.`sales_delivery`.`dateofdelivery` AS `dateofdelivery`,`bil`.`sales_delivery`.`timestamp` AS `timestamp`,`bil`.`sales_delivery`.`deliverycustomerid` AS `deliverycustomerid` from `bil`.`sales_delivery` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_loading`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_loading` AS select `bil`.`sales_loading`.`id` AS `id`,`bil`.`sales_loading`.`loadnumber` AS `loadnumber`,`bil`.`sales_loading`.`loader` AS `loader`,`bil`.`sales_loading`.`barcode` AS `barcode`,`bil`.`sales_loading`.`username` AS `username`,`bil`.`sales_loading`.`sod_id` AS `sod_id`,`bil`.`sales_loading`.`cageroomcode` AS `cageroomcode`,`bil`.`sales_loading`.`transporterid` AS `transporterid`,`bil`.`sales_loading`.`trucknumber` AS `trucknumber`,`bil`.`sales_loading`.`truckdriver` AS `truckdriver`,`bil`.`sales_loading`.`quantityloaded` AS `quantityloaded`,`bil`.`sales_loading`.`dateofloading` AS `dateofloading`,`bil`.`sales_loading`.`status` AS `status`,`bil`.`sales_loading`.`timestamp` AS `timestamp`,`bil`.`sales_loading`.`sales_loading_customerid` AS `sales_loading_customerid` from `bil`.`sales_loading` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_loading_return`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_loading_return` AS select `bil`.`sales_loading_return`.`id` AS `id`,`bil`.`sales_loading_return`.`barcode` AS `barcode`,`bil`.`sales_loading_return`.`username` AS `username`,`bil`.`sales_loading_return`.`loading_id` AS `loading_id`,`bil`.`sales_loading_return`.`sod_id` AS `sod_id`,`bil`.`sales_loading_return`.`quantityunloaded` AS `quantityunloaded`,`bil`.`sales_loading_return`.`timestamp` AS `timestamp` from `bil`.`sales_loading_return` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_order`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_order` AS select `bil`.`sales_order`.`id` AS `id`,`bil`.`sales_order`.`username` AS `username`,`bil`.`sales_order`.`orderid` AS `orderid`,`bil`.`sales_order`.`warehousecode` AS `warehousecode`,`bil`.`sales_order`.`customerid` AS `customerid`,`bil`.`sales_order`.`dateoforder` AS `dateoforder`,`bil`.`sales_order`.`timestamp` AS `timestamp` from `bil`.`sales_order` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_order_details`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_order_details` AS select `bil`.`sales_order_details`.`id` AS `id`,`bil`.`sales_order_details`.`orderid` AS `orderid`,`bil`.`sales_order_details`.`productid` AS `productid`,`bil`.`sales_order_details`.`quantityordered` AS `quantityordered`,`bil`.`sales_order_details`.`foc` AS `foc` from `bil`.`sales_order_details` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_return`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_return` AS select `bil`.`sales_return`.`id` AS `id`,`bil`.`sales_return`.`username` AS `username`,`bil`.`sales_return`.`returnnumber` AS `returnnumber`,`bil`.`sales_return`.`sod_id` AS `sod_id`,`bil`.`sales_return`.`quantityreturned` AS `quantityreturned`,`bil`.`sales_return`.`quantityrejected` AS `quantityrejected`,`bil`.`sales_return`.`dateofreturn` AS `dateofreturn`,`bil`.`sales_return`.`timestamp` AS `timestamp` from `bil`.`sales_return` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_transporters`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_transporters` AS select `bil`.`sales_transporters`.`id` AS `id`,`bil`.`sales_transporters`.`transportername` AS `transportername` from `bil`.`sales_transporters` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `sales_warehouse`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `sales_warehouse` AS select `bil`.`sales_warehouse`.`warehouselocation` AS `warehouselocation`,`bil`.`sales_warehouse`.`warehousecode` AS `warehousecode` from `bil`.`sales_warehouse` */;
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
/*!50001 DROP VIEW IF EXISTS `store_cagerooms`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `store_cagerooms` AS select `bil`.`store_cagerooms`.`cageroomnumber` AS `cageroomnumber`,`bil`.`store_cagerooms`.`cageroomcode` AS `cageroomcode`,`bil`.`store_cagerooms`.`warehousecode` AS `warehousecode` from `bil`.`store_cagerooms` */;
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
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

