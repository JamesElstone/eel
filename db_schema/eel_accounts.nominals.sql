/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for FreeBSD14.3 (amd64)
--
-- Host: localhost    Database: eel_accounts
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `nominal_accounts`
--

LOCK TABLES `nominal_accounts` WRITE;
/*!40000 ALTER TABLE `nominal_accounts` DISABLE KEYS */;
INSERT INTO `nominal_accounts` VALUES
(1,'1000','Bank','asset',1,'allowable',1,10,'2026-03-25 14:48:09'),
(2,'1100','Trade Debtors','asset',2,'allowable',1,20,'2026-03-25 14:48:09'),
(3,'1200','Director Loan Asset','asset',3,'allowable',1,30,'2026-03-25 14:48:09'),
(4,'2000','VAT Control','liability',4,'allowable',1,40,'2026-03-25 14:48:09'),
(5,'2100','Director Loan Liability','liability',5,'allowable',1,50,'2026-03-25 14:48:09'),
(6,'2200','Corporation Tax','liability',6,'allowable',1,60,'2026-03-25 14:48:09'),
(7,'4000','Sales','income',7,'allowable',1,100,'2026-03-25 14:48:09'),
(8,'5000','Materials','cost_of_sales',8,'allowable',1,200,'2026-03-25 14:48:09'),
(9,'6000','Misc Motor Expenses','expense',9,'allowable',1,300,'2026-03-25 14:48:09'),
(10,'6010','Insurance','expense',9,'allowable',1,310,'2026-03-25 14:48:09'),
(11,'6020','Software & Subscriptions','expense',9,'allowable',1,320,'2026-03-25 14:48:09'),
(12,'6030','Professional Fees','expense',9,'allowable',1,330,'2026-03-25 14:48:09'),
(13,'6040','Bank Charges','expense',9,'allowable',1,340,'2026-03-25 14:48:09'),
(14,'6050','Telephone & Internet','expense',9,'allowable',1,350,'2026-03-25 14:48:09'),
(15,'6060','Office Expenses','expense',9,'allowable',1,360,'2026-03-25 14:48:09'),
(16,'6070','Tools & Small Equipment','expense',9,'allowable',1,370,'2026-03-25 14:48:09'),
(17,'6080','Travel & Parking','expense',9,'allowable',1,380,'2026-03-25 14:48:09'),
(18,'6090','Sundry Expenses','expense',9,'allowable',1,390,'2026-03-25 14:48:09'),
(19,'9990','Suspense','asset',NULL,'allowable',1,990,'2026-03-25 14:48:09'),
(20,'9999','Uncategorised','asset',NULL,'allowable',1,999,'2026-03-25 14:48:09'),
(21,'6051','Internet Hosting','expense',9,'allowable',1,351,'2026-04-07 19:55:45'),
(22,'6001','Vehicle Insurance','expense',9,'allowable',1,301,'2026-04-07 20:01:08'),
(23,'6002','Fuel','expense',9,'allowable',1,302,'2026-04-07 20:01:44'),
(24,'6004','Road Tax (DVLA)','expense',9,'allowable',1,304,'2026-04-07 20:02:04'),
(25,'6003','Vehicle Repairs & Maintenance','expense',9,'allowable',1,303,'2026-04-07 20:04:32'),
(26,'6005','MOT & Servicing','expense',9,'allowable',1,305,'2026-04-07 20:05:35'),
(27,'2110','Expense Claims Payable','liability',NULL,'allowable',1,55,'2026-04-08 13:38:02'),
(28,'6006','Road Tolls & Parking','expense',9,'allowable',1,306,'2026-04-08 23:12:35'),
(29,'6100','Staff Subsistence','expense',9,'allowable',1,400,'2026-04-08 23:34:22'),
(30,'6110','Staff Welfare','expense',9,'allowable',1,410,'2026-04-08 23:35:12'),
(31,'6130','Client Entertainment','expense',9,'allowable',1,430,'2026-04-08 23:35:42'),
(32,'1300','Tools & Equipment (FA)','asset',11,'capital',1,130,'2026-04-09 12:48:13'),
(33,'1310','Plant & Machinery','asset',11,'capital',1,131,'2026-04-09 12:48:13'),
(34,'1320','Motor Vehicles','asset',11,'capital',1,132,'2026-04-09 12:48:13'),
(35,'1330','Accum Dep - Tools','asset',11,'capital',1,133,'2026-04-09 12:48:13'),
(36,'1340','Accum Dep - Plant','asset',11,'capital',1,134,'2026-04-09 12:48:13'),
(37,'1350','Accum Dep - Vehicles','asset',11,'capital',1,135,'2026-04-09 12:48:13'),
(38,'4200','Profit on Disposal','income',NULL,'other',1,420,'2026-04-09 12:48:13'),
(39,'6200','Depreciation Expense','expense',NULL,'disallowable',1,620,'2026-04-09 12:48:13'),
(40,'6210','Loss on Disposal','expense',NULL,'other',1,621,'2026-04-09 12:48:13');
/*!40000 ALTER TABLE `nominal_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `nominal_account_subtypes`
--

LOCK TABLES `nominal_account_subtypes` WRITE;
/*!40000 ALTER TABLE `nominal_account_subtypes` DISABLE KEYS */;
INSERT INTO `nominal_account_subtypes` VALUES
(1,'bank','Bank','asset',10,1),
(2,'trade_debtor','Trade Debtor','asset',20,1),
(3,'director_loan_asset','Director Loan Asset','asset',30,1),
(4,'vat_control','VAT Control','liability',40,1),
(5,'director_loan_liability','Director Loan Liability','liability',50,1),
(6,'corp_tax','Corporation Tax','liability',60,1),
(7,'turnover','Turnover','income',100,1),
(8,'materials','Materials','cost_of_sales',200,1),
(9,'overhead','Overhead Expense','expense',300,1),
(10,'expense_payable','Expense Claims Payable','liability',55,1),
(11,'fixed_asset','Fixed Asset','asset',35,1);
/*!40000 ALTER TABLE `nominal_account_subtypes` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-13 22:59:07
