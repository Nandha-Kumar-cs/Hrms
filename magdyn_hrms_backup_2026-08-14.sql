-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: magdyn_hrms
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(120) NOT NULL DEFAULT 'System',
  `action` varchar(60) NOT NULL,
  `module` varchar(60) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_module` (`module`,`created_at`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` (`id`, `user_id`, `user_name`, `action`, `module`, `description`, `ip_address`, `created_at`) VALUES (1,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: Arul Raj (0034)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹15,000.00\"}]}','192.168.1.243','2026-08-04 14:35:04'),(2,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: NAVEEN RAJ  K (MAGDYN017)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹12,000.00\"}]}','192.168.1.40','2026-08-04 14:38:56'),(3,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: NAVEEN RAJ  K (MAGDYN017)\",\"changes\":[{\"field\":\"PF & ESI Deduction\",\"from\":\"Enabled\",\"to\":\"Disabled\"}]}','192.168.1.40','2026-08-04 14:41:33'),(4,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: Nanda Kumar (MAGDYN-024)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹15,000.00\"}]}','192.168.1.40','2026-08-04 14:54:37'),(5,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 15:07:45'),(6,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: Rajesh  T (0030)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹16,900.00\"}]}','192.168.1.40','2026-08-04 15:19:04'),(7,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: BHAVATHARANI (MAGDYN-019)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹14,000.00\"}]}','192.168.1.243','2026-08-04 15:30:09'),(8,1,'System Admin','updated','Employee','Updated employee: Rajesh  T (0030)','192.168.1.40','2026-08-04 15:36:34'),(9,1,'System Admin','updated','Employee','Updated employee: Rajesh  T (0030)','192.168.1.40','2026-08-04 15:36:44'),(10,1,'System Admin','updated','Employee','Updated employee: Rajesh  T (0030)','192.168.1.40','2026-08-04 15:37:49'),(11,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 15:48:26'),(12,1,'System Admin','updated','Employee','Updated employee: Arul Raj (0034)','192.168.1.243','2026-08-04 15:52:01'),(13,1,'System Admin','updated','Employee','Updated employee: BHAVATHARANI (MAGDYN-019)','192.168.1.243','2026-08-04 15:52:25'),(14,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: CHITRA (11)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹12,000.00\"}]}','192.168.1.243','2026-08-04 16:38:13'),(15,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 16:47:39'),(16,1,'System Admin','updated','Employee','{\"summary\":\"Updated employee: Gopika (0037)\",\"changes\":[{\"field\":\"CTC\\/Month\",\"from\":\"₹0.00\",\"to\":\"₹15,000.00\"}]}','192.168.1.243','2026-08-04 16:53:56'),(17,1,'System Admin','updated','Employee','Updated employee: Gopika (0037)','192.168.1.243','2026-08-04 17:06:26'),(18,1,'System Admin','updated','Employee','Updated employee: Gopika (0037)','192.168.1.243','2026-08-04 17:13:36'),(19,1,'System Admin','created','Department','Created department: IT','192.168.1.243','2026-08-04 17:14:52'),(20,1,'System Admin','created','Designation','Created designation: Software Engineer (Dept: IT)','192.168.1.243','2026-08-04 17:15:11'),(21,1,'System Admin','updated','Employee','Updated employee: Gopika (0037)','192.168.1.243','2026-08-04 17:15:44'),(22,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 17:18:30'),(23,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 17:22:26'),(24,1,'System Admin','updated','Employee','Updated employee: Ravi kumar (0018)','192.168.1.243','2026-08-04 17:23:09'),(25,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 17:29:56'),(26,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-04 18:06:30'),(27,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.45','2026-08-13 17:35:54'),(28,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 17:38:08'),(29,1,'System Admin','updated','Employee','Updated employee: Ravi kumar (0018)','192.168.1.45','2026-08-13 17:39:04'),(30,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 17:41:47'),(31,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 17:43:13'),(32,1,'System Admin','updated','Employee ID Card','Regenerated QR token for Ravi kumar (0018) — previously printed cards no longer work','192.168.1.45','2026-08-13 17:47:02'),(33,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 17:52:03'),(34,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 17:53:02'),(35,1,'System Admin','created','Employee ID Card','Issued ID card / QR token for Raj mohan (0021)','::1','2026-08-13 17:56:22'),(36,1,'System Admin','updated','Employee ID Card','Regenerated QR token for Ravi kumar (0018) — previously printed cards no longer work','192.168.1.45','2026-08-13 17:57:25'),(37,1,'System Admin','updated','Employee','Updated employee: Ravi kumar (0018)','192.168.1.45','2026-08-13 18:14:31'),(38,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 18:17:58'),(39,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 18:18:21'),(40,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.173','2026-08-13 18:21:37'),(41,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.45','2026-08-13 18:41:45'),(42,1,'System Admin','logout','Auth','Logged out: System Admin','192.168.1.45','2026-08-13 18:41:47'),(43,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.45','2026-08-13 18:42:38'),(44,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.40','2026-08-13 19:14:05'),(45,1,'System Admin','updated','Employee','Updated employee: Ravi kumar (0018)','192.168.1.45','2026-08-13 19:17:06'),(46,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.45','2026-08-13 19:20:26'),(47,1,'System Admin','updated','Employee','Updated employee: Ravi kumar (0018)','192.168.1.45','2026-08-13 19:23:31'),(48,1,'System Admin','updated','Employee','Updated employee: NAVEEN RAJ  K (MAGDYN017)','192.168.1.40','2026-08-13 19:29:25'),(49,1,'System Admin','updated','Employee','Updated employee: NAVEEN RAJ  K (MAGDYN017)','192.168.1.40','2026-08-13 19:30:27'),(50,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 19:32:00'),(51,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.40','2026-08-13 19:32:17'),(52,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:32:29'),(53,1,'System Admin','created','Employee ID Card','Issued ID card / QR token for Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:32:52'),(54,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:34:34'),(55,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:36:02'),(56,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:37:02'),(57,1,'System Admin','updated','Employee ID Card','Regenerated QR token for NAVEEN RAJ  K (MAGDYN017) — previously printed cards no longer work','192.168.1.40','2026-08-13 19:40:37'),(58,1,'System Admin','created','Employee ID Card','Issued ID card / QR token for Antony peter (0026)','192.168.1.40','2026-08-13 19:43:20'),(59,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:50:40'),(60,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:51:00'),(61,1,'System Admin','created','Employee ID Card','Issued ID card / QR token for Murgan Ramdoss (0022)','192.168.1.40','2026-08-13 19:51:36'),(62,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 19:52:57'),(63,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:53:54'),(64,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:54:10'),(65,1,'System Admin','updated','Employee','Updated employee: NAVEEN RAJ  K (MAGDYN017)','192.168.1.40','2026-08-13 19:56:04'),(66,1,'System Admin','updated','Employee','Updated employee: Nanda Kumar (MAGDYN-024)','192.168.1.45','2026-08-13 19:56:59'),(67,1,'System Admin','updated','Employee','Updated employee: Ravi kumar (0018)','192.168.1.45','2026-08-13 20:02:28'),(68,1,'System Admin','updated','Employee','Updated employee: Raj mohan (0021)','192.168.1.45','2026-08-13 20:02:46'),(69,1,'System Admin','updated','Employee ID Card','Regenerated QR token for Nanda Kumar (MAGDYN-024) — previously printed cards no longer work','192.168.1.45','2026-08-13 20:06:03'),(70,1,'System Admin','updated','Employee ID Card','Regenerated QR token for NAVEEN RAJ  K (MAGDYN017) — previously printed cards no longer work','192.168.1.40','2026-08-13 20:20:55'),(71,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 20:28:08'),(72,1,'System Admin','created','Employee ID Card','Issued ID card / QR token for Muthu R (0029)','192.168.1.45','2026-08-13 20:40:23'),(73,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 20:49:09'),(74,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.45','2026-08-13 21:17:05'),(75,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 21:22:36'),(76,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 21:32:48'),(77,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 21:35:54'),(78,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-13 21:41:29'),(79,1,'System Admin','updated','Employee ID Card','Regenerated QR token for Nanda Kumar (MAGDYN-024) — previously printed cards no longer work','192.168.1.45','2026-08-13 21:51:00'),(80,1,'System Admin','login','Auth','Logged in: System Admin','192.168.1.45','2026-08-14 13:03:51'),(81,1,'System Admin','updated','Employee ID Card','Regenerated QR token for Nanda Kumar (MAGDYN-024) — previously printed cards no longer work','192.168.1.45','2026-08-14 13:04:08'),(82,1,'System Admin','login','Auth','Logged in: System Admin','::1','2026-08-14 15:01:36');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_settings`
--

LOCK TABLES `app_settings` WRITE;
/*!40000 ALTER TABLE `app_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_assignments`
--

DROP TABLE IF EXISTS `asset_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `employee_id` int(10) unsigned NOT NULL,
  `assigned_date` date NOT NULL,
  `returned_date` date DEFAULT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `condition_at_assign` enum('New','Good','Fair','Poor') DEFAULT 'Good',
  `condition_at_return` enum('New','Good','Fair','Poor') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_returned` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `asset_assignments_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`),
  CONSTRAINT `asset_assignments_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_assignments`
--

LOCK TABLES `asset_assignments` WRITE;
/*!40000 ALTER TABLE `asset_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_categories`
--

DROP TABLE IF EXISTS `asset_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_categories`
--

LOCK TABLES `asset_categories` WRITE;
/*!40000 ALTER TABLE `asset_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `brand` varchar(80) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(12,2) DEFAULT NULL,
  `condition` enum('New','Good','Fair','Poor') DEFAULT 'Good',
  `status` enum('Available','Assigned','Under Repair','Retired') DEFAULT 'Available',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_code` (`asset_code`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `shift_id` int(10) unsigned DEFAULT NULL,
  `att_date` date NOT NULL,
  `status` enum('On Time','Late','Absent','OD','Comp Off','Half Day','Holiday','On Leave') NOT NULL,
  `leave_classification` enum('paid','unpaid') DEFAULT NULL,
  `in_time` time DEFAULT NULL,
  `out_time` time DEFAULT NULL,
  `worked_hours` decimal(5,2) DEFAULT NULL,
  `deduction_amount` decimal(10,2) DEFAULT NULL,
  `ot_hours` decimal(5,2) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `marked_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_date` (`employee_id`,`att_date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1914 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` (`id`, `employee_id`, `shift_id`, `att_date`, `status`, `leave_classification`, `in_time`, `out_time`, `worked_hours`, `deduction_amount`, `ot_hours`, `remarks`, `marked_by`, `created_at`) VALUES (1,2,1,'2026-07-01','On Time',NULL,'09:00:00','18:19:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:21'),(2,2,1,'2026-07-02','Late',NULL,'09:26:00','18:24:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(3,2,1,'2026-07-03','Late',NULL,'09:50:00','18:23:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(4,2,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(5,2,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(6,2,1,'2026-07-06','Late',NULL,'09:18:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(7,2,1,'2026-07-07','On Time',NULL,'08:48:00','18:26:00',NULL,NULL,0.38,NULL,1,'2026-08-04 14:34:21'),(8,2,1,'2026-07-08','On Time',NULL,'09:08:00','18:23:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(9,2,1,'2026-07-09','On Time',NULL,'08:54:00','20:15:00',NULL,NULL,2.10,NULL,1,'2026-08-04 14:34:21'),(10,2,1,'2026-07-10','On Time',NULL,'09:04:00','13:37:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(11,2,1,'2026-07-11','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(12,2,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(13,2,1,'2026-07-13','On Time',NULL,'08:55:00','18:29:00',NULL,NULL,0.32,NULL,1,'2026-08-04 14:34:21'),(14,2,1,'2026-07-14','On Time',NULL,'08:54:00','18:24:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:21'),(15,2,1,'2026-07-15','On Time',NULL,'09:09:00','20:15:00',NULL,NULL,1.85,NULL,1,'2026-08-04 14:34:21'),(16,2,1,'2026-07-16','Absent',NULL,'09:00:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:21'),(17,2,1,'2026-07-17','On Time',NULL,'08:59:00','18:25:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:21'),(18,2,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(19,2,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(20,2,1,'2026-07-20','On Time',NULL,'09:09:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(21,2,1,'2026-07-21','On Time',NULL,'09:04:00','18:25:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:22'),(22,2,1,'2026-07-22','On Time',NULL,'09:00:00','18:24:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(23,2,1,'2026-07-23','On Time',NULL,'09:00:00','18:33:00',NULL,NULL,0.30,NULL,1,'2026-08-04 14:34:22'),(24,2,1,'2026-07-24','On Time',NULL,'09:03:00','18:29:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:22'),(25,2,1,'2026-07-25','On Time',NULL,'09:03:00','20:11:00',NULL,NULL,1.88,NULL,1,'2026-08-04 14:34:22'),(26,2,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(27,2,1,'2026-07-27','On Time',NULL,'09:03:00','20:12:00',NULL,NULL,1.90,NULL,1,'2026-08-04 14:34:22'),(28,2,1,'2026-07-28','On Time',NULL,'08:54:00','18:23:00',NULL,NULL,0.23,NULL,1,'2026-08-04 14:34:22'),(29,2,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(30,2,1,'2026-07-30','On Time',NULL,'09:03:00','18:26:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(31,2,1,'2026-07-31','On Time',NULL,'09:03:00','18:37:00',NULL,NULL,0.32,NULL,1,'2026-08-04 14:34:22'),(32,8,1,'2026-07-01','On Time',NULL,'08:48:00','18:20:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:22'),(33,8,1,'2026-07-02','On Time',NULL,'08:56:00','20:13:00',NULL,NULL,2.03,NULL,1,'2026-08-04 14:34:22'),(34,8,1,'2026-07-03','On Time',NULL,'08:54:00','18:15:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:22'),(35,8,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(36,8,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(37,8,1,'2026-07-06','On Time',NULL,'08:57:00','18:17:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(38,8,1,'2026-07-07','On Time',NULL,'08:46:00','18:21:00',NULL,NULL,0.33,NULL,1,'2026-08-04 14:34:22'),(39,8,1,'2026-07-08','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(40,8,1,'2026-07-09','Absent',NULL,'08:55:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(41,8,1,'2026-07-10','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(42,8,1,'2026-07-11','On Time',NULL,'08:56:00','18:16:00',NULL,NULL,8.83,NULL,1,'2026-08-04 14:34:22'),(43,8,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(44,8,1,'2026-07-13','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(45,8,1,'2026-07-14','On Time',NULL,'08:55:00','18:17:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(46,8,1,'2026-07-15','On Time',NULL,'09:00:00','18:22:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(47,8,1,'2026-07-16','On Time',NULL,'09:01:00','18:28:00',NULL,NULL,0.20,NULL,1,'2026-08-04 14:34:22'),(48,8,1,'2026-07-17','On Time',NULL,'08:54:00','18:17:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(49,8,1,'2026-07-18','On Time',NULL,'08:56:00','18:08:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(50,8,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(51,8,1,'2026-07-20','On Time',NULL,'08:53:00','18:18:00',NULL,NULL,0.17,NULL,1,'2026-08-04 14:34:22'),(52,8,1,'2026-07-21','On Time',NULL,'09:04:00','18:20:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:22'),(53,8,1,'2026-07-22','On Time',NULL,'08:49:00','18:16:00',NULL,NULL,0.20,NULL,1,'2026-08-04 14:34:22'),(54,8,1,'2026-07-23','Absent',NULL,'09:00:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(55,8,1,'2026-07-24','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(56,8,1,'2026-07-25','On Time',NULL,'08:55:00','18:26:00',NULL,NULL,9.02,NULL,1,'2026-08-04 14:34:22'),(57,8,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(58,8,1,'2026-07-27','On Time',NULL,'08:49:00','18:27:00',NULL,NULL,0.38,NULL,1,'2026-08-04 14:34:22'),(59,8,1,'2026-07-28','On Time',NULL,'08:53:00','18:34:00',NULL,NULL,0.43,NULL,1,'2026-08-04 14:34:22'),(60,8,1,'2026-07-29','On Time',NULL,'08:52:00','18:19:00',NULL,NULL,0.20,NULL,1,'2026-08-04 14:34:22'),(61,8,1,'2026-07-30','On Time',NULL,'08:56:00','18:18:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(62,8,1,'2026-07-31','On Time',NULL,'08:48:00','18:20:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:22'),(63,11,1,'2026-07-01','On Time',NULL,'09:00:00','18:34:00',8.57,0.00,0.32,NULL,1,'2026-08-04 14:34:22'),(64,11,1,'2026-07-02','On Time',NULL,'08:55:00','20:14:00',10.32,0.00,2.07,NULL,1,'2026-08-04 14:34:22'),(65,11,1,'2026-07-03','On Time',NULL,'08:54:00','18:18:00',8.40,0.00,0.15,NULL,1,'2026-08-04 14:34:22'),(66,11,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(67,11,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(68,11,1,'2026-07-06','On Time',NULL,'08:57:00','18:17:00',8.33,0.00,0.08,NULL,1,'2026-08-04 14:34:22'),(69,11,1,'2026-07-07','On Time',NULL,'08:55:00','18:19:00',8.40,0.00,0.15,NULL,1,'2026-08-04 14:34:22'),(70,11,1,'2026-07-08','On Time',NULL,'09:09:00','18:16:00',8.12,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(71,11,1,'2026-07-09','On Time',NULL,'09:10:00','18:16:00',8.10,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(72,11,1,'2026-07-10','On Time',NULL,'09:03:00','18:16:00',8.22,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(73,11,1,'2026-07-11','On Time',NULL,'08:56:00','18:19:00',8.38,0.00,8.88,NULL,1,'2026-08-04 14:34:22'),(74,11,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(75,11,1,'2026-07-13','On Time',NULL,'09:01:00','18:15:00',8.23,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(76,11,1,'2026-07-14','Late',NULL,'09:29:00','18:16:00',7.78,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(77,11,1,'2026-07-15','On Time',NULL,'09:00:00','18:17:00',8.28,0.00,0.03,NULL,1,'2026-08-04 14:34:22'),(78,11,1,'2026-07-16','On Time',NULL,'09:08:00','18:16:00',8.13,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(79,11,1,'2026-07-17','Absent',NULL,'09:18:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(80,11,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(81,11,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(82,11,1,'2026-07-20','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(83,11,1,'2026-07-21','On Time',NULL,'09:05:00','18:16:00',8.18,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(84,11,1,'2026-07-22','On Time',NULL,'09:10:00','18:15:00',8.08,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(85,11,1,'2026-07-23','On Time',NULL,'09:04:00','18:16:00',8.20,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(86,11,1,'2026-07-24','Late',NULL,'09:16:00','18:17:00',8.02,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(87,11,1,'2026-07-25','Late',NULL,'09:32:00','18:20:00',7.80,0.00,8.30,NULL,1,'2026-08-04 14:34:22'),(88,11,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(89,11,1,'2026-07-27','On Time',NULL,'09:08:00','18:16:00',8.13,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(90,11,1,'2026-07-28','On Time',NULL,'09:08:00','18:15:00',8.12,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(91,11,1,'2026-07-29','Late',NULL,'09:28:00','18:17:00',7.82,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(92,11,1,'2026-07-30','On Time',NULL,'09:03:00','18:16:00',8.22,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(93,11,1,'2026-07-31','On Time',NULL,'09:00:00','18:18:00',8.30,0.00,0.05,NULL,1,'2026-08-04 14:34:22'),(94,14,1,'2026-07-01','On Time',NULL,'08:58:00','18:25:00',8.45,0.00,0.20,NULL,1,'2026-08-04 14:34:22'),(95,14,1,'2026-07-02','On Time',NULL,'09:00:00','13:50:00',4.08,236.90,0.15,NULL,1,'2026-08-04 14:34:22'),(96,14,1,'2026-07-03','Half Day',NULL,'14:03:00','18:22:00',4.07,241.94,NULL,NULL,1,'2026-08-04 14:34:22'),(97,14,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(98,14,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(99,14,1,'2026-07-06','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(100,14,1,'2026-07-07','On Time',NULL,'08:52:00','18:18:00',8.43,0.00,0.18,NULL,1,'2026-08-04 14:34:22'),(101,14,1,'2026-07-08','On Time',NULL,'09:09:00','18:17:00',8.13,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(102,14,1,'2026-07-09','On Time',NULL,'09:01:00','18:20:00',8.32,0.00,0.07,NULL,1,'2026-08-04 14:34:22'),(103,14,1,'2026-07-10','On Time',NULL,'09:03:00','18:18:00',8.25,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(104,14,1,'2026-07-11','On Time',NULL,'08:56:00','18:19:00',8.38,0.00,8.88,NULL,1,'2026-08-04 14:34:22'),(105,14,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(106,14,1,'2026-07-13','On Time',NULL,'09:11:00','18:18:00',8.12,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(107,14,1,'2026-07-14','On Time',NULL,'08:46:00','18:30:00',8.73,0.00,0.48,NULL,1,'2026-08-04 14:34:22'),(108,14,1,'2026-07-15','On Time',NULL,'09:02:00','18:20:00',8.30,0.00,0.05,NULL,1,'2026-08-04 14:34:22'),(109,14,1,'2026-07-16','On Time',NULL,'09:08:00','18:20:00',8.20,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(110,14,1,'2026-07-17','Absent',NULL,'09:18:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(111,14,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(112,14,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(113,14,1,'2026-07-20','On Time',NULL,'09:01:00','18:41:00',8.67,0.00,0.42,NULL,1,'2026-08-04 14:34:22'),(114,14,1,'2026-07-21','On Time',NULL,'09:15:00','18:20:00',8.08,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(115,14,1,'2026-07-22','On Time',NULL,'09:10:00','18:22:00',8.20,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(116,14,1,'2026-07-23','On Time',NULL,'09:12:00','18:18:00',8.10,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(117,14,1,'2026-07-24','On Time',NULL,'09:13:00','18:21:00',8.13,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(118,14,1,'2026-07-25','On Time',NULL,'08:55:00','18:19:00',8.40,0.00,8.90,NULL,1,'2026-08-04 14:34:22'),(119,14,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(120,14,1,'2026-07-27','On Time',NULL,'09:11:00','18:19:00',8.13,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(121,14,1,'2026-07-28','On Time',NULL,'09:08:00','18:17:00',8.15,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(122,14,1,'2026-07-29','On Time',NULL,'09:04:00','18:20:00',8.27,0.00,0.02,NULL,1,'2026-08-04 14:34:22'),(123,14,1,'2026-07-30','Late',NULL,'10:30:00','18:17:00',6.78,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(124,14,1,'2026-07-31','Late',NULL,'09:20:00','18:21:00',8.02,0.00,0.10,NULL,1,'2026-08-04 14:34:22'),(125,21,1,'2026-07-01','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(126,21,1,'2026-07-02','On Time',NULL,'09:14:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(127,21,1,'2026-07-03','On Time',NULL,'09:10:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(128,21,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(129,21,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(130,21,1,'2026-07-06','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(131,21,1,'2026-07-07','Late',NULL,'09:18:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(132,21,1,'2026-07-08','On Time',NULL,'09:12:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(133,21,1,'2026-07-09','On Time',NULL,'09:13:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(134,21,1,'2026-07-10','On Time',NULL,'09:11:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(135,21,1,'2026-07-11','On Time',NULL,'09:13:00','18:18:00',NULL,NULL,8.58,NULL,1,'2026-08-04 14:34:22'),(136,21,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(137,21,1,'2026-07-13','Late',NULL,'09:20:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(138,21,1,'2026-07-14','Late',NULL,'09:34:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(139,21,1,'2026-07-15','Late',NULL,'09:18:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(140,21,1,'2026-07-16','On Time',NULL,'09:14:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(141,21,1,'2026-07-17','Late',NULL,'09:23:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(142,21,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(143,21,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(144,21,1,'2026-07-20','Late',NULL,'09:20:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(145,21,1,'2026-07-21','Late',NULL,'09:28:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(146,21,1,'2026-07-22','Late',NULL,'09:26:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(147,21,1,'2026-07-23','Late',NULL,'09:21:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(148,21,1,'2026-07-24','Late',NULL,'09:18:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(149,21,1,'2026-07-25','On Time',NULL,'09:12:00','18:15:00',NULL,NULL,8.55,NULL,1,'2026-08-04 14:34:22'),(150,21,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(151,21,1,'2026-07-27','Late',NULL,'09:20:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(152,21,1,'2026-07-28','Late',NULL,'09:16:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(153,21,1,'2026-07-29','On Time',NULL,'09:15:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(154,21,1,'2026-07-30','On Time',NULL,'09:12:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(155,21,1,'2026-07-31','On Time',NULL,'09:08:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(156,23,1,'2026-07-01','On Time',NULL,'09:00:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(157,23,1,'2026-07-02','On Time',NULL,'08:57:00','18:15:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(158,23,1,'2026-07-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(159,23,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(160,23,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(161,23,1,'2026-07-06','On Time',NULL,'08:58:00','18:18:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(162,23,1,'2026-07-07','On Time',NULL,'09:09:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(163,23,1,'2026-07-08','On Time',NULL,'09:04:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(164,23,1,'2026-07-09','On Time',NULL,'08:57:00','18:16:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(165,23,1,'2026-07-10','On Time',NULL,'09:03:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(166,23,1,'2026-07-11','On Time',NULL,'09:05:00','18:17:00',NULL,NULL,8.70,NULL,1,'2026-08-04 14:34:22'),(167,23,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(168,23,1,'2026-07-13','On Time',NULL,'08:55:00','18:15:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(169,23,1,'2026-07-14','On Time',NULL,'09:06:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(170,23,1,'2026-07-15','On Time',NULL,'09:00:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(171,23,1,'2026-07-16','On Time',NULL,'09:01:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(172,23,1,'2026-07-17','On Time',NULL,'09:01:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(173,23,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(174,23,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(175,23,1,'2026-07-20','On Time',NULL,'08:57:00','18:16:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(176,23,1,'2026-07-21','On Time',NULL,'09:06:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(177,23,1,'2026-07-22','On Time',NULL,'08:53:00','18:15:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(178,23,1,'2026-07-23','Late',NULL,'09:56:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(179,23,1,'2026-07-24','On Time',NULL,'09:04:00','18:20:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:22'),(180,23,1,'2026-07-25','On Time',NULL,'09:06:00','18:15:00',NULL,NULL,8.65,NULL,1,'2026-08-04 14:34:22'),(181,23,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(182,23,1,'2026-07-27','On Time',NULL,'08:52:00','18:18:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:22'),(183,23,1,'2026-07-28','On Time',NULL,'09:11:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(184,23,1,'2026-07-29','On Time',NULL,'08:59:00','18:15:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:22'),(185,23,1,'2026-07-30','On Time',NULL,'08:56:00','18:15:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(186,23,1,'2026-07-31','On Time',NULL,'09:15:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(187,25,1,'2026-07-01','On Time',NULL,'09:08:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(188,25,1,'2026-07-02','On Time',NULL,'08:55:00','18:15:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(189,25,1,'2026-07-03','Late',NULL,'09:21:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(190,25,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(191,25,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(192,25,1,'2026-07-06','Late',NULL,'09:45:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(193,25,1,'2026-07-07','On Time',NULL,'09:06:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(194,25,1,'2026-07-08','Late',NULL,'09:45:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(195,25,1,'2026-07-09','On Time',NULL,'09:10:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(196,25,1,'2026-07-10','Late',NULL,'09:21:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(197,25,1,'2026-07-11','Absent',NULL,'09:49:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(198,25,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(199,25,1,'2026-07-13','Late',NULL,'09:22:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(200,25,1,'2026-07-14','Late',NULL,'09:40:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(201,25,1,'2026-07-15','Late',NULL,'09:29:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(202,25,1,'2026-07-16','Late',NULL,'09:31:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(203,25,1,'2026-07-17','Late',NULL,'10:24:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(204,25,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(205,25,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(206,25,1,'2026-07-20','Half Day',NULL,'18:16:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(207,25,1,'2026-07-21','Late',NULL,'09:40:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(208,25,1,'2026-07-22','Half Day',NULL,'18:15:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(209,25,1,'2026-07-23','Late',NULL,'09:19:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(210,25,1,'2026-07-24','Late',NULL,'09:30:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(211,25,1,'2026-07-25','Half Day',NULL,'18:16:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(212,25,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(213,25,1,'2026-07-27','Half Day',NULL,'18:17:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(214,25,1,'2026-07-28','Late',NULL,'09:46:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(215,25,1,'2026-07-29','On Time',NULL,'09:11:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(216,25,1,'2026-07-30','Late',NULL,'09:42:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(217,25,1,'2026-07-31','Late',NULL,'09:45:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(218,29,1,'2026-07-01','Late',NULL,'09:35:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(219,29,1,'2026-07-02','Late',NULL,'09:26:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(220,29,1,'2026-07-03','Late',NULL,'09:41:00','17:11:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(221,29,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(222,29,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(223,29,1,'2026-07-06','Late',NULL,'09:35:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(224,29,1,'2026-07-07','Late',NULL,'09:32:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(225,29,1,'2026-07-08','On Time',NULL,'09:10:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(226,29,1,'2026-07-09','Late',NULL,'09:28:00','18:26:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(227,29,1,'2026-07-10','Late',NULL,'09:23:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(228,29,1,'2026-07-11','Late',NULL,'09:30:00','18:16:00',NULL,NULL,8.27,NULL,1,'2026-08-04 14:34:22'),(229,29,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(230,29,1,'2026-07-13','Late',NULL,'09:29:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(231,29,1,'2026-07-14','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(232,29,1,'2026-07-15','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(233,29,1,'2026-07-16','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(234,29,1,'2026-07-17','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(235,29,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(236,29,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(237,29,1,'2026-07-20','Late',NULL,'10:17:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(238,29,1,'2026-07-21','Late',NULL,'09:47:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(239,29,1,'2026-07-22','Half Day',NULL,'13:06:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(240,29,1,'2026-07-23','Late',NULL,'09:35:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(241,29,1,'2026-07-24','Late',NULL,'09:30:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(242,29,1,'2026-07-25','Late',NULL,'09:37:00','18:16:00',NULL,NULL,8.15,NULL,1,'2026-08-04 14:34:22'),(243,29,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(244,29,1,'2026-07-27','Late',NULL,'09:18:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(245,29,1,'2026-07-28','Late',NULL,'09:26:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(246,29,1,'2026-07-29','Late',NULL,'09:32:00','16:08:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(247,29,1,'2026-07-30','Late',NULL,'09:26:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(248,29,1,'2026-07-31','Late',NULL,'09:30:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(249,34,1,'2026-07-01','On Time',NULL,'09:09:00','18:32:00',8.38,0.00,0.13,NULL,1,'2026-08-04 14:34:22'),(250,34,1,'2026-07-02','Late',NULL,'09:36:00','18:23:00',7.78,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(251,34,1,'2026-07-03','Late',NULL,'09:40:00','18:50:00',8.17,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(252,34,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(253,34,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(254,34,1,'2026-07-06','Late',NULL,'09:36:00','18:23:00',7.78,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(255,34,1,'2026-07-07','Late',NULL,'09:30:00','18:25:00',7.92,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(256,34,1,'2026-07-08','On Time',NULL,'09:02:00','18:22:00',8.33,0.00,0.08,NULL,1,'2026-08-04 14:34:22'),(257,34,1,'2026-07-09','On Time',NULL,'09:02:00','18:27:00',8.42,0.00,0.17,NULL,1,'2026-08-04 14:34:22'),(258,34,1,'2026-07-10','Half Day',NULL,'11:54:00','18:18:00',5.65,225.81,NULL,NULL,1,'2026-08-04 14:34:22'),(259,34,1,'2026-07-11','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(260,34,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(261,34,1,'2026-07-13','Absent',NULL,'08:56:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(262,34,1,'2026-07-14','Late',NULL,'09:34:00','18:26:00',7.87,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(263,34,1,'2026-07-15','Late',NULL,'09:24:00','18:20:00',7.93,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(264,34,1,'2026-07-16','Absent',NULL,'09:26:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(265,34,1,'2026-07-17','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(266,34,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(267,34,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(268,34,1,'2026-07-20','On Time',NULL,'09:11:00','18:21:00',8.17,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(269,34,1,'2026-07-21','Late',NULL,'09:31:00','18:17:00',7.77,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(270,34,1,'2026-07-22','Half Day',NULL,'18:19:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(271,34,1,'2026-07-23','Late',NULL,'09:27:00','18:41:00',8.23,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(272,34,1,'2026-07-24','Late',NULL,'09:16:00','18:45:00',8.48,0.00,0.23,NULL,1,'2026-08-04 14:34:22'),(273,34,1,'2026-07-25','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(274,34,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(275,34,1,'2026-07-27','On Time',NULL,'09:12:00','18:24:00',8.20,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(276,34,1,'2026-07-28','Absent',NULL,'09:12:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(277,34,1,'2026-07-29','Absent',NULL,'09:27:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(278,34,1,'2026-07-30','On Time',NULL,'09:14:00','18:14:00',8.00,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(279,34,1,'2026-07-31','Late',NULL,'09:28:00','13:30:00',3.28,266.26,NULL,NULL,1,'2026-08-04 14:34:22'),(280,1,1,'2026-07-01','On Time',NULL,'09:05:00','18:20:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(281,1,1,'2026-07-02','On Time',NULL,'08:55:00','20:14:00',NULL,NULL,2.07,NULL,1,'2026-08-04 14:34:22'),(282,1,1,'2026-07-03','On Time',NULL,'08:56:00','18:19:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(283,1,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(284,1,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(285,1,1,'2026-07-06','Late',NULL,'09:19:00','14:33:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(286,1,1,'2026-07-07','On Time',NULL,'09:06:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(287,1,1,'2026-07-08','On Time',NULL,'09:06:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(288,1,1,'2026-07-09','On Time',NULL,'09:07:00','20:14:00',NULL,NULL,1.87,NULL,1,'2026-08-04 14:34:22'),(289,1,1,'2026-07-10','On Time',NULL,'09:03:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(290,1,1,'2026-07-11','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(291,1,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(292,1,1,'2026-07-13','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(293,1,1,'2026-07-14','On Time',NULL,'09:06:00','11:49:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(294,1,1,'2026-07-15','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(295,1,1,'2026-07-16','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(296,1,1,'2026-07-17','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(297,1,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(298,1,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(299,1,1,'2026-07-20','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(300,1,1,'2026-07-21','On Time',NULL,'09:05:00','18:24:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(301,1,1,'2026-07-22','On Time',NULL,'08:52:00','18:15:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(302,1,1,'2026-07-23','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(303,1,1,'2026-07-24','On Time',NULL,'09:07:00','18:20:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(304,1,1,'2026-07-25','Absent',NULL,'09:06:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(305,1,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(306,1,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(307,1,1,'2026-07-28','On Time',NULL,'09:06:00','15:47:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(308,1,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(309,1,1,'2026-07-30','On Time',NULL,'08:50:00','18:17:00',NULL,NULL,0.20,NULL,1,'2026-08-04 14:34:22'),(310,1,1,'2026-07-31','Late',NULL,'09:21:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(311,6,1,'2026-07-01','On Time',NULL,'08:55:00','18:24:00',NULL,NULL,0.23,NULL,1,'2026-08-04 14:34:22'),(312,6,1,'2026-07-02','On Time',NULL,'08:56:00','20:14:00',NULL,NULL,2.05,NULL,1,'2026-08-04 14:34:22'),(313,6,1,'2026-07-03','Absent',NULL,'08:56:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(314,6,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(315,6,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(316,6,1,'2026-07-06','On Time',NULL,'08:59:00','18:17:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(317,6,1,'2026-07-07','Absent',NULL,'08:55:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(318,6,1,'2026-07-08','On Time',NULL,'09:01:00','18:23:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(319,6,1,'2026-07-09','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:22'),(320,6,1,'2026-07-10','On Time',NULL,'09:02:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(321,6,1,'2026-07-11','On Time',NULL,'08:56:00','18:18:00',NULL,NULL,8.87,NULL,1,'2026-08-04 14:34:22'),(322,6,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(323,6,1,'2026-07-13','On Time',NULL,'08:55:00','19:17:00',NULL,NULL,1.12,NULL,1,'2026-08-04 14:34:22'),(324,6,1,'2026-07-14','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:22'),(325,6,1,'2026-07-15','On Time',NULL,'09:03:00','13:31:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(326,6,1,'2026-07-16','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(327,6,1,'2026-07-17','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(328,6,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(329,6,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(330,6,1,'2026-07-20','On Time',NULL,'08:58:00','18:22:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(331,6,1,'2026-07-21','On Time',NULL,'09:05:00','20:13:00',NULL,NULL,1.88,NULL,1,'2026-08-04 14:34:22'),(332,6,1,'2026-07-22','On Time',NULL,'08:55:00','18:17:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(333,6,1,'2026-07-23','On Time',NULL,'09:01:00','18:42:00',NULL,NULL,0.43,NULL,1,'2026-08-04 14:34:22'),(334,6,1,'2026-07-24','On Time',NULL,'08:52:00','19:17:00',NULL,NULL,1.17,NULL,1,'2026-08-04 14:34:22'),(335,6,1,'2026-07-25','On Time',NULL,'08:55:00','18:19:00',NULL,NULL,8.90,NULL,1,'2026-08-04 14:34:22'),(336,6,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(337,6,1,'2026-07-27','Late',NULL,'09:50:00','13:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(338,6,1,'2026-07-28','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(339,6,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(340,6,1,'2026-07-30','Absent',NULL,'08:52:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(341,6,1,'2026-07-31','Absent',NULL,'08:55:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(342,7,1,'2026-07-01','Absent',NULL,'09:43:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(343,7,1,'2026-07-02','Late',NULL,'09:40:00','18:17:00',7.62,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(344,7,1,'2026-07-03','Late',NULL,'09:22:00','18:17:00',7.92,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(345,7,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(346,7,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(347,7,1,'2026-07-06','Late',NULL,'10:20:00','18:16:00',6.93,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(348,7,1,'2026-07-07','Absent',NULL,'09:39:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(349,7,1,'2026-07-08','Late',NULL,'09:39:00','18:16:00',7.62,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(350,7,1,'2026-07-09','Late',NULL,'09:29:00','18:15:00',7.77,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(351,7,1,'2026-07-10','Late',NULL,'09:40:00','18:16:00',7.60,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(352,7,1,'2026-07-11','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(353,7,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(354,7,1,'2026-07-13','Late',NULL,'09:30:00','18:15:00',7.75,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(355,7,1,'2026-07-14','Late',NULL,'09:29:00','18:17:00',7.80,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(356,7,1,'2026-07-15','Late',NULL,'09:31:00','18:17:00',7.77,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(357,7,1,'2026-07-16','Late',NULL,'09:30:00','18:16:00',7.77,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(358,7,1,'2026-07-17','Late',NULL,'09:34:00','18:15:00',7.68,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(359,7,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(360,7,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(361,7,1,'2026-07-20','Late',NULL,'09:27:00','18:18:00',7.85,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(362,7,1,'2026-07-21','Late',NULL,'09:17:00','18:17:00',8.00,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(363,7,1,'2026-07-22','Late',NULL,'09:28:00','18:15:00',7.78,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(364,7,1,'2026-07-23','Late',NULL,'09:39:00','18:17:00',7.63,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(365,7,1,'2026-07-24','Late',NULL,'09:28:00','18:16:00',7.80,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(366,7,1,'2026-07-25','Late',NULL,'09:32:00','18:16:00',7.73,0.00,8.23,NULL,1,'2026-08-04 14:34:22'),(367,7,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(368,7,1,'2026-07-27','Half Day',NULL,'18:24:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(369,7,1,'2026-07-28','Late',NULL,'09:34:00','18:19:00',7.75,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(370,7,1,'2026-07-29','Late',NULL,'09:42:00','18:18:00',7.60,0.00,NULL,NULL,1,'2026-08-04 14:34:22'),(371,7,1,'2026-07-30','Late',NULL,'09:37:00','19:14:00',8.62,0.00,0.37,NULL,1,'2026-08-04 14:34:22'),(372,7,1,'2026-07-31','Absent',NULL,'09:27:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(373,9,1,'2026-07-01','On Time',NULL,'08:53:00','18:15:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(374,9,1,'2026-07-02','On Time',NULL,'08:55:00','18:17:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(375,9,1,'2026-07-03','On Time',NULL,'08:53:00','18:15:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(376,9,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(377,9,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(378,9,1,'2026-07-06','On Time',NULL,'08:58:00','18:16:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(379,9,1,'2026-07-07','On Time',NULL,'08:51:00','18:15:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(380,9,1,'2026-07-08','On Time',NULL,'09:01:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(381,9,1,'2026-07-09','On Time',NULL,'08:57:00','18:15:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(382,9,1,'2026-07-10','On Time',NULL,'09:01:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(383,9,1,'2026-07-11','On Time',NULL,'08:56:00','18:16:00',NULL,NULL,8.83,NULL,1,'2026-08-04 14:34:22'),(384,9,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(385,9,1,'2026-07-13','On Time',NULL,'08:56:00','18:15:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(386,9,1,'2026-07-14','On Time',NULL,'08:51:00','18:15:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(387,9,1,'2026-07-15','On Time',NULL,'09:00:00','18:17:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:22'),(388,9,1,'2026-07-16','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(389,9,1,'2026-07-17','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(390,9,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(391,9,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(392,9,1,'2026-07-20','On Time',NULL,'08:53:00','18:15:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(393,9,1,'2026-07-21','On Time',NULL,'09:04:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(394,9,1,'2026-07-22','On Time',NULL,'08:50:00','18:15:00',NULL,NULL,0.17,NULL,1,'2026-08-04 14:34:22'),(395,9,1,'2026-07-23','On Time',NULL,'09:01:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(396,9,1,'2026-07-24','On Time',NULL,'08:52:00','18:16:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(397,9,1,'2026-07-25','On Time',NULL,'08:55:00','18:22:00',NULL,NULL,8.95,NULL,1,'2026-08-04 14:34:22'),(398,9,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(399,9,1,'2026-07-27','On Time',NULL,'08:47:00','18:17:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:22'),(400,9,1,'2026-07-28','On Time',NULL,'08:42:00','14:41:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(401,9,1,'2026-07-29','On Time',NULL,'08:52:00','18:17:00',NULL,NULL,0.17,NULL,1,'2026-08-04 14:34:22'),(402,9,1,'2026-07-30','On Time',NULL,'08:50:00','18:15:00',NULL,NULL,0.17,NULL,1,'2026-08-04 14:34:22'),(403,9,1,'2026-07-31','On Time',NULL,'08:46:00','13:36:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(404,10,1,'2026-07-01','On Time',NULL,'08:49:00','18:17:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:22'),(405,10,1,'2026-07-02','On Time',NULL,'08:56:00','18:29:00',NULL,NULL,0.30,NULL,1,'2026-08-04 14:34:22'),(406,10,1,'2026-07-03','On Time',NULL,'08:54:00','18:20:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:22'),(407,10,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(408,10,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(409,10,1,'2026-07-06','On Time',NULL,'08:58:00','18:17:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(410,10,1,'2026-07-07','On Time',NULL,'08:49:00','18:17:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:22'),(411,10,1,'2026-07-08','On Time',NULL,'09:02:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(412,10,1,'2026-07-09','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(413,10,1,'2026-07-10','On Time',NULL,'09:02:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(414,10,1,'2026-07-11','On Time',NULL,'08:56:00','18:18:00',NULL,NULL,8.87,NULL,1,'2026-08-04 14:34:22'),(415,10,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(416,10,1,'2026-07-13','On Time',NULL,'08:55:00','17:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(417,10,1,'2026-07-14','On Time',NULL,'08:47:00','18:17:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:22'),(418,10,1,'2026-07-15','On Time',NULL,'09:00:00','18:17:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:22'),(419,10,1,'2026-07-16','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(420,10,1,'2026-07-17','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(421,10,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(422,10,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(423,10,1,'2026-07-20','On Time',NULL,'08:55:00','18:17:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(424,10,1,'2026-07-21','On Time',NULL,'09:04:00','17:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(425,10,1,'2026-07-22','On Time',NULL,'08:53:00','18:16:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(426,10,1,'2026-07-23','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(427,10,1,'2026-07-24','On Time',NULL,'08:54:00','18:18:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(428,10,1,'2026-07-25','On Time',NULL,'08:55:00','18:18:00',NULL,NULL,8.88,NULL,1,'2026-08-04 14:34:22'),(429,10,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(430,10,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(431,10,1,'2026-07-28','On Time',NULL,'08:53:00','18:16:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(432,10,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(433,10,1,'2026-07-30','On Time',NULL,'08:54:00','18:17:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(434,10,1,'2026-07-31','On Time',NULL,'08:49:00','18:18:00',NULL,NULL,0.23,NULL,1,'2026-08-04 14:34:22'),(435,13,1,'2026-07-01','On Time',NULL,'09:00:00','18:20:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(436,13,1,'2026-07-02','On Time',NULL,'09:00:00','18:21:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:22'),(437,13,1,'2026-07-03','On Time',NULL,'09:05:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(438,13,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(439,13,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(440,13,1,'2026-07-06','On Time',NULL,'08:58:00','18:17:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(441,13,1,'2026-07-07','On Time',NULL,'08:58:00','18:20:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(442,13,1,'2026-07-08','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(443,13,1,'2026-07-09','On Time',NULL,'09:01:00','20:14:00',NULL,NULL,1.97,NULL,1,'2026-08-04 14:34:22'),(444,13,1,'2026-07-10','On Time',NULL,'09:06:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(445,13,1,'2026-07-11','On Time',NULL,'08:58:00','13:34:00',NULL,NULL,4.10,NULL,1,'2026-08-04 14:34:22'),(446,13,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(447,13,1,'2026-07-13','On Time',NULL,'08:57:00','18:17:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(448,13,1,'2026-07-14','On Time',NULL,'08:56:00','18:18:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(449,13,1,'2026-07-15','On Time',NULL,'09:00:00','18:22:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:22'),(450,13,1,'2026-07-16','On Time',NULL,'09:01:00','18:38:00',NULL,NULL,0.37,NULL,1,'2026-08-04 14:34:22'),(451,13,1,'2026-07-17','On Time',NULL,'09:02:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(452,13,1,'2026-07-18','On Time',NULL,'09:00:00','18:06:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(453,13,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(454,13,1,'2026-07-20','On Time',NULL,'08:54:00','18:18:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:22'),(455,13,1,'2026-07-21','On Time',NULL,'09:07:00','18:24:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:22'),(456,13,1,'2026-07-22','On Time',NULL,'09:06:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(457,13,1,'2026-07-23','On Time',NULL,'09:02:00','13:36:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(458,13,1,'2026-07-24','On Time',NULL,'08:52:00','18:25:00',NULL,NULL,0.30,NULL,1,'2026-08-04 14:34:22'),(459,13,1,'2026-07-25','On Time',NULL,'08:57:00','18:21:00',NULL,NULL,8.90,NULL,1,'2026-08-04 14:34:22'),(460,13,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(461,13,1,'2026-07-27','Half Day',NULL,'14:40:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(462,13,1,'2026-07-28','Half Day',NULL,'14:33:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(463,13,1,'2026-07-29','Absent',NULL,'10:19:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(464,13,1,'2026-07-30','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(465,13,1,'2026-07-31','On Time',NULL,'08:56:00','18:39:00',NULL,NULL,0.47,NULL,1,'2026-08-04 14:34:22'),(466,15,1,'2026-07-01','On Time',NULL,'09:08:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(467,15,1,'2026-07-02','On Time',NULL,'09:01:00','18:19:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(468,15,1,'2026-07-03','On Time',NULL,'09:00:00','18:19:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(469,15,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(470,15,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(471,15,1,'2026-07-06','Half Day',NULL,'18:17:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(472,15,1,'2026-07-07','On Time',NULL,'08:57:00','18:17:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(473,15,1,'2026-07-08','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(474,15,1,'2026-07-09','On Time',NULL,'08:59:00','18:19:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(475,15,1,'2026-07-10','On Time',NULL,'09:05:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(476,15,1,'2026-07-11','On Time',NULL,'09:04:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(477,15,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(478,15,1,'2026-07-13','On Time',NULL,'09:02:00','18:18:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:22'),(479,15,1,'2026-07-14','On Time',NULL,'09:04:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(480,15,1,'2026-07-15','On Time',NULL,'09:00:00','18:17:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:22'),(481,15,1,'2026-07-16','On Time',NULL,'09:06:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(482,15,1,'2026-07-17','On Time',NULL,'09:03:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(483,15,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(484,15,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(485,15,1,'2026-07-20','Late',NULL,'09:22:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(486,15,1,'2026-07-21','On Time',NULL,'09:05:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(487,15,1,'2026-07-22','On Time',NULL,'09:02:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(488,15,1,'2026-07-23','On Time',NULL,'09:05:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(489,15,1,'2026-07-24','On Time',NULL,'09:08:00','18:20:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(490,15,1,'2026-07-25','On Time',NULL,'09:01:00','18:17:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:22'),(491,15,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(492,15,1,'2026-07-27','On Time',NULL,'09:03:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(493,15,1,'2026-07-28','On Time',NULL,'09:00:00','18:17:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:22'),(494,15,1,'2026-07-29','On Time',NULL,'09:05:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(495,15,1,'2026-07-30','Late',NULL,'09:45:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(496,15,1,'2026-07-31','On Time',NULL,'09:15:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(497,17,1,'2026-07-01','On Time',NULL,'08:50:00','18:18:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:22'),(498,17,1,'2026-07-02','On Time',NULL,'08:58:00','20:14:00',NULL,NULL,2.02,NULL,1,'2026-08-04 14:34:22'),(499,17,1,'2026-07-03','On Time',NULL,'08:54:00','18:15:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:22'),(500,17,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(501,17,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(502,17,1,'2026-07-06','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(503,17,1,'2026-07-07','On Time',NULL,'08:53:00','18:16:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:22'),(504,17,1,'2026-07-08','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(505,17,1,'2026-07-09','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(506,17,1,'2026-07-10','Late',NULL,'10:11:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(507,17,1,'2026-07-11','On Time',NULL,'08:57:00','18:27:00',NULL,NULL,9.00,NULL,1,'2026-08-04 14:34:22'),(508,17,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(509,17,1,'2026-07-13','On Time',NULL,'08:57:00','18:15:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(510,17,1,'2026-07-14','On Time',NULL,'08:55:00','18:23:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:22'),(511,17,1,'2026-07-15','On Time',NULL,'09:01:00','18:21:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:22'),(512,17,1,'2026-07-16','On Time',NULL,'09:01:00','18:18:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:22'),(513,17,1,'2026-07-17','On Time',NULL,'08:57:00','10:38:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(514,17,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(515,17,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(516,17,1,'2026-07-20','On Time',NULL,'08:57:00','18:16:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:22'),(517,17,1,'2026-07-21','On Time',NULL,'09:04:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(518,17,1,'2026-07-22','On Time',NULL,'09:09:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(519,17,1,'2026-07-23','On Time',NULL,'09:03:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(520,17,1,'2026-07-24','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(521,17,1,'2026-07-25','On Time',NULL,'08:57:00','18:19:00',NULL,NULL,8.87,NULL,1,'2026-08-04 14:34:22'),(522,17,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(523,17,1,'2026-07-27','Half Day',NULL,'13:47:00','22:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(524,17,1,'2026-07-28','Half Day',NULL,'13:56:00','22:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(525,17,1,'2026-07-29','Half Day',NULL,'13:48:00','22:13:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(526,17,1,'2026-07-30','On Time',NULL,'09:07:00','18:20:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(527,17,1,'2026-07-31','On Time',NULL,'09:01:00','18:27:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:22'),(528,26,1,'2026-07-01','On Time',NULL,'08:25:00','20:03:00',NULL,NULL,2.38,NULL,1,'2026-08-04 14:34:22'),(529,26,1,'2026-07-02','On Time',NULL,'08:55:00','19:28:00',NULL,NULL,1.30,NULL,1,'2026-08-04 14:34:22'),(530,26,1,'2026-07-03','On Time',NULL,'09:08:00','17:33:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(531,26,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(532,26,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(533,26,1,'2026-07-06','Absent',NULL,'08:58:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(534,26,1,'2026-07-07','On Time',NULL,'08:46:00','18:17:00',NULL,NULL,0.27,NULL,1,'2026-08-04 14:34:22'),(535,26,1,'2026-07-08','On Time',NULL,'09:02:00','18:20:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:22'),(536,26,1,'2026-07-09','On Time',NULL,'08:55:00','19:17:00',NULL,NULL,1.12,NULL,1,'2026-08-04 14:34:22'),(537,26,1,'2026-07-10','On Time',NULL,'09:02:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(538,26,1,'2026-07-11','On Time',NULL,'08:59:00','18:30:00',NULL,NULL,0.27,NULL,1,'2026-08-04 14:34:22'),(539,26,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:22'),(540,26,1,'2026-07-13','On Time',NULL,'09:02:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(541,26,1,'2026-07-14','On Time',NULL,'08:49:00','18:51:00',NULL,NULL,0.78,NULL,1,'2026-08-04 14:34:23'),(542,26,1,'2026-07-15','On Time',NULL,'09:01:00','19:16:00',NULL,NULL,1.00,NULL,1,'2026-08-04 14:34:23'),(543,26,1,'2026-07-16','On Time',NULL,'09:02:00','18:12:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(544,26,1,'2026-07-17','On Time',NULL,'08:54:00','18:17:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(545,26,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(546,26,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(547,26,1,'2026-07-20','On Time',NULL,'08:54:00','18:19:00',NULL,NULL,0.17,NULL,1,'2026-08-04 14:34:23'),(548,26,1,'2026-07-21','On Time',NULL,'09:04:00','18:44:00',NULL,NULL,0.42,NULL,1,'2026-08-04 14:34:23'),(549,26,1,'2026-07-22','Late',NULL,'09:21:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(550,26,1,'2026-07-23','On Time',NULL,'09:04:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(551,26,1,'2026-07-24','On Time',NULL,'08:53:00','18:16:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(552,26,1,'2026-07-25','On Time',NULL,'09:01:00','18:21:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:23'),(553,26,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(554,26,1,'2026-07-27','On Time',NULL,'09:03:00','18:46:00',NULL,NULL,0.47,NULL,1,'2026-08-04 14:34:23'),(555,26,1,'2026-07-28','On Time',NULL,'08:38:00','18:34:00',NULL,NULL,0.68,NULL,1,'2026-08-04 14:34:23'),(556,26,1,'2026-07-29','On Time',NULL,'08:49:00','18:40:00',NULL,NULL,0.60,NULL,1,'2026-08-04 14:34:23'),(557,26,1,'2026-07-30','On Time',NULL,'08:54:00','18:16:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:23'),(558,26,1,'2026-07-31','On Time',NULL,'08:53:00','18:17:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:23'),(559,28,1,'2026-07-01','On Time',NULL,'08:54:00','19:49:00',NULL,NULL,1.67,NULL,1,'2026-08-04 14:34:23'),(560,28,1,'2026-07-02','Absent',NULL,'09:02:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(561,28,1,'2026-07-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(562,28,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(563,28,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(564,28,1,'2026-07-06','On Time',NULL,'09:10:00','18:28:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(565,28,1,'2026-07-07','On Time',NULL,'08:59:00','18:21:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:23'),(566,28,1,'2026-07-08','On Time',NULL,'09:01:00','18:18:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:23'),(567,28,1,'2026-07-09','On Time',NULL,'08:54:00','20:14:00',NULL,NULL,2.08,NULL,1,'2026-08-04 14:34:23'),(568,28,1,'2026-07-10','On Time',NULL,'09:02:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(569,28,1,'2026-07-11','On Time',NULL,'08:59:00','18:38:00',NULL,NULL,9.15,NULL,1,'2026-08-04 14:34:23'),(570,28,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(571,28,1,'2026-07-13','On Time',NULL,'08:55:00','19:16:00',NULL,NULL,1.10,NULL,1,'2026-08-04 14:34:23'),(572,28,1,'2026-07-14','On Time',NULL,'08:46:00','18:22:00',NULL,NULL,0.35,NULL,1,'2026-08-04 14:34:23'),(573,28,1,'2026-07-15','Absent',NULL,'09:00:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(574,28,1,'2026-07-16','On Time',NULL,'09:01:00','18:38:00',NULL,NULL,0.37,NULL,1,'2026-08-04 14:34:23'),(575,28,1,'2026-07-17','On Time',NULL,'09:01:00','18:17:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(576,28,1,'2026-07-18','On Time',NULL,'08:57:00','18:06:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(577,28,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(578,28,1,'2026-07-20','On Time',NULL,'08:53:00','18:23:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(579,28,1,'2026-07-21','On Time',NULL,'09:11:00','20:13:00',NULL,NULL,1.78,NULL,1,'2026-08-04 14:34:23'),(580,28,1,'2026-07-22','On Time',NULL,'08:49:00','18:20:00',NULL,NULL,0.27,NULL,1,'2026-08-04 14:34:23'),(581,28,1,'2026-07-23','On Time',NULL,'09:00:00','20:30:00',NULL,NULL,2.25,NULL,1,'2026-08-04 14:34:23'),(582,28,1,'2026-07-24','On Time',NULL,'08:52:00','19:05:00',NULL,NULL,0.97,NULL,1,'2026-08-04 14:34:23'),(583,28,1,'2026-07-25','On Time',NULL,'08:57:00','20:09:00',NULL,NULL,10.70,NULL,1,'2026-08-04 14:34:23'),(584,28,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(585,28,1,'2026-07-27','On Time',NULL,'08:45:00','20:01:00',NULL,NULL,2.02,NULL,1,'2026-08-04 14:34:23'),(586,28,1,'2026-07-28','On Time',NULL,'08:41:00','20:18:00',NULL,NULL,2.37,NULL,1,'2026-08-04 14:34:23'),(587,28,1,'2026-07-29','Half Day',NULL,'18:44:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(588,28,1,'2026-07-30','On Time',NULL,'08:50:00','18:18:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(589,28,1,'2026-07-31','On Time',NULL,'08:56:00','19:04:00',NULL,NULL,0.88,NULL,1,'2026-08-04 14:34:23'),(590,32,1,'2026-07-01','On Time',NULL,'09:02:00','18:19:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:23'),(591,32,1,'2026-07-02','On Time',NULL,'09:05:00','18:20:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(592,32,1,'2026-07-03','On Time',NULL,'09:05:00','18:23:00',NULL,NULL,0.30,NULL,1,'2026-08-04 14:34:23'),(593,32,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(594,32,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(595,32,1,'2026-07-06','On Time',NULL,'09:10:00','18:19:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:23'),(596,32,1,'2026-07-07','On Time',NULL,'09:07:00','18:20:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(597,32,1,'2026-07-08','On Time',NULL,'09:05:00','18:18:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(598,32,1,'2026-07-09','On Time',NULL,'09:04:00','18:25:00',NULL,NULL,0.35,NULL,1,'2026-08-04 14:34:23'),(599,32,1,'2026-07-10','On Time',NULL,'09:13:00','18:19:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:23'),(600,32,1,'2026-07-11','On Time',NULL,'09:05:00','18:18:00',NULL,NULL,8.72,NULL,1,'2026-08-04 14:34:23'),(601,32,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(602,32,1,'2026-07-13','On Time',NULL,'09:03:00','18:18:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(603,32,1,'2026-07-14','On Time',NULL,'09:01:00','18:17:00',NULL,NULL,0.27,NULL,1,'2026-08-04 14:34:23'),(604,32,1,'2026-07-15','On Time',NULL,'09:01:00','18:20:00',NULL,NULL,0.32,NULL,1,'2026-08-04 14:34:23'),(605,32,1,'2026-07-16','On Time',NULL,'09:01:00','18:20:00',NULL,NULL,0.32,NULL,1,'2026-08-04 14:34:23'),(606,32,1,'2026-07-17','On Time',NULL,'09:10:00','18:18:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(607,32,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(608,32,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(609,32,1,'2026-07-20','On Time',NULL,'09:06:00','18:21:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(610,32,1,'2026-07-21','On Time',NULL,'09:07:00','18:23:00',NULL,NULL,0.27,NULL,1,'2026-08-04 14:34:23'),(611,32,1,'2026-07-22','On Time',NULL,'09:09:00','18:17:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(612,32,1,'2026-07-23','On Time',NULL,'09:06:00','18:17:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:23'),(613,32,1,'2026-07-24','Absent',NULL,'08:57:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(614,32,1,'2026-07-25','On Time',NULL,'09:02:00','18:21:00',NULL,NULL,8.82,NULL,1,'2026-08-04 14:34:23'),(615,32,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(616,32,1,'2026-07-27','On Time',NULL,'09:09:00','18:18:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:23'),(617,32,1,'2026-07-28','On Time',NULL,'09:08:00','18:17:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:23'),(618,32,1,'2026-07-29','On Time',NULL,'09:05:00','18:18:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(619,32,1,'2026-07-30','On Time',NULL,'09:04:00','18:15:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:23'),(620,32,1,'2026-07-31','On Time',NULL,'09:05:00','18:18:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(621,36,1,'2026-07-01','On Time',NULL,'09:14:00','19:56:00',NULL,NULL,1.45,NULL,1,'2026-08-04 14:34:23'),(622,36,1,'2026-07-02','Half Day',NULL,'18:23:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(623,36,1,'2026-07-03','Late',NULL,'09:18:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(624,36,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(625,36,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(626,36,1,'2026-07-06','Late',NULL,'09:38:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(627,36,1,'2026-07-07','On Time',NULL,'09:07:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(628,36,1,'2026-07-08','On Time',NULL,'09:13:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(629,36,1,'2026-07-09','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(630,36,1,'2026-07-10','On Time',NULL,'09:09:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(631,36,1,'2026-07-11','On Time',NULL,'09:01:00','18:17:00',NULL,NULL,8.77,NULL,1,'2026-08-04 14:34:23'),(632,36,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(633,36,1,'2026-07-13','Late',NULL,'09:27:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(634,36,1,'2026-07-14','On Time',NULL,'09:06:00','18:50:00',NULL,NULL,0.48,NULL,1,'2026-08-04 14:34:23'),(635,36,1,'2026-07-15','On Time',NULL,'09:04:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(636,36,1,'2026-07-16','On Time',NULL,'09:09:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(637,36,1,'2026-07-17','On Time',NULL,'09:15:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(638,36,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(639,36,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(640,36,1,'2026-07-20','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(641,36,1,'2026-07-21','Late',NULL,'09:17:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(642,36,1,'2026-07-22','On Time',NULL,'09:09:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(643,36,1,'2026-07-23','On Time',NULL,'09:07:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(644,36,1,'2026-07-24','On Time',NULL,'09:05:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(645,36,1,'2026-07-25','On Time',NULL,'09:15:00','16:16:00',NULL,NULL,6.52,NULL,1,'2026-08-04 14:34:23'),(646,36,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(647,36,1,'2026-07-27','On Time',NULL,'09:14:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(648,36,1,'2026-07-28','On Time',NULL,'09:09:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(649,36,1,'2026-07-29','On Time',NULL,'09:06:00','18:22:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(650,36,1,'2026-07-30','On Time',NULL,'09:07:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(651,36,1,'2026-07-31','On Time',NULL,'09:07:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(652,40,1,'2026-07-01','Late',NULL,'09:20:00','19:38:00',NULL,NULL,1.05,NULL,1,'2026-08-04 14:34:23'),(653,40,1,'2026-07-02','Late',NULL,'09:17:00','19:16:00',NULL,NULL,0.73,NULL,1,'2026-08-04 14:34:23'),(654,40,1,'2026-07-03','Late',NULL,'09:22:00','19:50:00',NULL,NULL,1.22,NULL,1,'2026-08-04 14:34:23'),(655,40,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(656,40,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(657,40,1,'2026-07-06','Late',NULL,'09:18:00','19:12:00',NULL,NULL,0.65,NULL,1,'2026-08-04 14:34:23'),(658,40,1,'2026-07-07','On Time',NULL,'09:09:00','18:54:00',NULL,NULL,0.50,NULL,1,'2026-08-04 14:34:23'),(659,40,1,'2026-07-08','On Time',NULL,'09:15:00','18:39:00',NULL,NULL,0.15,NULL,1,'2026-08-04 14:34:23'),(660,40,1,'2026-07-09','Late',NULL,'09:16:00','19:20:00',NULL,NULL,0.82,NULL,1,'2026-08-04 14:34:23'),(661,40,1,'2026-07-10','Late',NULL,'09:22:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(662,40,1,'2026-07-11','Late',NULL,'09:17:00','18:41:00',NULL,NULL,8.90,NULL,1,'2026-08-04 14:34:23'),(663,40,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(664,40,1,'2026-07-13','Late',NULL,'09:23:00','19:18:00',NULL,NULL,0.67,NULL,1,'2026-08-04 14:34:23'),(665,40,1,'2026-07-14','Late',NULL,'09:20:00','18:54:00',NULL,NULL,0.32,NULL,1,'2026-08-04 14:34:23'),(666,40,1,'2026-07-15','Late',NULL,'09:17:00','19:22:00',NULL,NULL,0.83,NULL,1,'2026-08-04 14:34:23'),(667,40,1,'2026-07-16','Late',NULL,'09:19:00','19:03:00',NULL,NULL,0.48,NULL,1,'2026-08-04 14:34:23'),(668,40,1,'2026-07-17','On Time',NULL,'09:14:00','19:07:00',NULL,NULL,0.63,NULL,1,'2026-08-04 14:34:23'),(669,40,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(670,40,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(671,40,1,'2026-07-20','Late',NULL,'09:19:00','18:41:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:23'),(672,40,1,'2026-07-21','Late',NULL,'09:16:00','19:22:00',NULL,NULL,0.85,NULL,1,'2026-08-04 14:34:23'),(673,40,1,'2026-07-22','Late',NULL,'09:19:00','18:49:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(674,40,1,'2026-07-23','Late',NULL,'09:22:00','18:57:00',NULL,NULL,0.33,NULL,1,'2026-08-04 14:34:23'),(675,40,1,'2026-07-24','Late',NULL,'09:32:00','18:26:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(676,40,1,'2026-07-25','Late',NULL,'09:25:00','19:26:00',NULL,NULL,9.52,NULL,1,'2026-08-04 14:34:23'),(677,40,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(678,40,1,'2026-07-27','Absent',NULL,'09:24:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(679,40,1,'2026-07-28','Late',NULL,'09:29:00','19:25:00',NULL,NULL,0.68,NULL,1,'2026-08-04 14:34:23'),(680,40,1,'2026-07-29','Late',NULL,'09:19:00','19:27:00',NULL,NULL,0.88,NULL,1,'2026-08-04 14:34:23'),(681,40,1,'2026-07-30','Absent',NULL,'09:20:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(682,40,1,'2026-07-31','Half Day',NULL,'13:53:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(683,38,1,'2026-07-01','On Time',NULL,'08:25:00','19:56:00',NULL,NULL,2.27,NULL,1,'2026-08-04 14:34:23'),(684,38,1,'2026-07-02','On Time',NULL,'08:55:00','20:13:00',NULL,NULL,2.05,NULL,1,'2026-08-04 14:34:23'),(685,38,1,'2026-07-03','On Time',NULL,'08:53:00','20:44:00',NULL,NULL,2.60,NULL,1,'2026-08-04 14:34:23'),(686,38,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(687,38,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(688,38,1,'2026-07-06','On Time',NULL,'08:57:00','19:12:00',NULL,NULL,1.00,NULL,1,'2026-08-04 14:34:23'),(689,38,1,'2026-07-07','Absent',NULL,'08:45:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(690,38,1,'2026-07-08','On Time',NULL,'09:02:00','18:41:00',NULL,NULL,0.40,NULL,1,'2026-08-04 14:34:23'),(691,38,1,'2026-07-09','On Time',NULL,'08:55:00','20:14:00',NULL,NULL,2.07,NULL,1,'2026-08-04 14:34:23'),(692,38,1,'2026-07-10','On Time',NULL,'09:01:00','18:19:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(693,38,1,'2026-07-11','On Time',NULL,'08:55:00','18:45:00',NULL,NULL,9.33,NULL,1,'2026-08-04 14:34:23'),(694,38,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(695,38,1,'2026-07-13','Half Day',NULL,'19:19:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(696,38,1,'2026-07-14','On Time',NULL,'08:46:00','18:56:00',NULL,NULL,0.92,NULL,1,'2026-08-04 14:34:23'),(697,38,1,'2026-07-15','On Time',NULL,'09:03:00','19:35:00',NULL,NULL,1.28,NULL,1,'2026-08-04 14:34:23'),(698,38,1,'2026-07-16','On Time',NULL,'09:00:00','18:41:00',NULL,NULL,0.43,NULL,1,'2026-08-04 14:34:23'),(699,38,1,'2026-07-17','On Time',NULL,'08:54:00','19:32:00',NULL,NULL,1.38,NULL,1,'2026-08-04 14:34:23'),(700,38,1,'2026-07-18','On Time',NULL,'09:15:00','18:09:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(701,38,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(702,38,1,'2026-07-20','On Time',NULL,'08:53:00','18:36:00',NULL,NULL,0.47,NULL,1,'2026-08-04 14:34:23'),(703,38,1,'2026-07-21','On Time',NULL,'09:07:00','20:13:00',NULL,NULL,1.85,NULL,1,'2026-08-04 14:34:23'),(704,38,1,'2026-07-22','On Time',NULL,'08:49:00','18:46:00',NULL,NULL,0.70,NULL,1,'2026-08-04 14:34:23'),(705,38,1,'2026-07-23','Absent',NULL,'09:08:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(706,38,1,'2026-07-24','On Time',NULL,'08:52:00','18:21:00',NULL,NULL,0.23,NULL,1,'2026-08-04 14:34:23'),(707,38,1,'2026-07-25','Absent',NULL,'08:54:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(708,38,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(709,38,1,'2026-07-27','On Time',NULL,'08:33:00','20:30:00',NULL,NULL,2.70,NULL,1,'2026-08-04 14:34:23'),(710,38,1,'2026-07-28','On Time',NULL,'07:56:00','22:22:00',NULL,NULL,5.18,NULL,1,'2026-08-04 14:34:23'),(711,38,1,'2026-07-29','On Time',NULL,'08:21:00','22:13:00',NULL,NULL,4.62,NULL,1,'2026-08-04 14:34:23'),(712,38,1,'2026-07-30','On Time',NULL,'08:50:00','18:46:00',NULL,NULL,0.68,NULL,1,'2026-08-04 14:34:23'),(713,38,1,'2026-07-31','On Time',NULL,'08:46:00','19:13:00',NULL,NULL,1.20,NULL,1,'2026-08-04 14:34:23'),(714,5,1,'2026-07-01','On Time',NULL,'08:42:00','18:31:00',NULL,NULL,0.57,NULL,1,'2026-08-04 14:34:23'),(715,5,1,'2026-07-02','On Time',NULL,'08:57:00','18:17:00',NULL,NULL,0.08,NULL,1,'2026-08-04 14:34:23'),(716,5,1,'2026-07-03','Late',NULL,'09:16:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(717,5,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(718,5,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(719,5,1,'2026-07-06','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(720,5,1,'2026-07-07','On Time',NULL,'09:01:00','19:22:00',NULL,NULL,1.10,NULL,1,'2026-08-04 14:34:23'),(721,5,1,'2026-07-08','On Time',NULL,'09:10:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(722,5,1,'2026-07-09','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:23'),(723,5,1,'2026-07-10','On Time',NULL,'09:03:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(724,5,1,'2026-07-11','On Time',NULL,'08:56:00','18:19:00',NULL,NULL,8.88,NULL,1,'2026-08-04 14:34:23'),(725,5,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(726,5,1,'2026-07-13','Late',NULL,'09:22:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(727,5,1,'2026-07-14','Late',NULL,'09:35:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(728,5,1,'2026-07-15','On Time',NULL,'09:00:00','18:18:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(729,5,1,'2026-07-16','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(730,5,1,'2026-07-17','Late',NULL,'09:18:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(731,5,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(732,5,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(733,5,1,'2026-07-20','Late',NULL,'09:40:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(734,5,1,'2026-07-21','Late',NULL,'09:30:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(735,5,1,'2026-07-22','Half Day',NULL,'13:47:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(736,5,1,'2026-07-23','On Time',NULL,'09:00:00','18:16:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(737,5,1,'2026-07-24','Late',NULL,'09:16:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(738,5,1,'2026-07-25','Late',NULL,'09:49:00','18:16:00',NULL,NULL,7.95,NULL,1,'2026-08-04 14:34:23'),(739,5,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(740,5,1,'2026-07-27','On Time',NULL,'09:11:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(741,5,1,'2026-07-28','On Time',NULL,'09:12:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(742,5,1,'2026-07-29','Late',NULL,'09:31:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(743,5,1,'2026-07-30','On Time',NULL,'09:15:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(744,5,1,'2026-07-31','On Time',NULL,'09:00:00','18:16:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(745,30,1,'2026-07-01','On Time',NULL,'08:59:00','18:30:00',NULL,NULL,0.27,NULL,1,'2026-08-04 14:34:23'),(746,30,1,'2026-07-02','On Time',NULL,'08:57:00','18:16:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:23'),(747,30,1,'2026-07-03','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:23'),(748,30,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(749,30,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(750,30,1,'2026-07-06','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(751,30,1,'2026-07-07','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(752,30,1,'2026-07-08','Late',NULL,'09:34:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(753,30,1,'2026-07-09','On Time',NULL,'09:02:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(754,30,1,'2026-07-10','Late',NULL,'09:27:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(755,30,1,'2026-07-11','Absent',NULL,'08:56:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(756,30,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(757,30,1,'2026-07-13','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(758,30,1,'2026-07-14','On Time',NULL,'09:13:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(759,30,1,'2026-07-15','Late',NULL,'09:35:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(760,30,1,'2026-07-16','On Time',NULL,'09:01:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(761,30,1,'2026-07-17','Late',NULL,'09:23:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(762,30,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(763,30,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(764,30,1,'2026-07-20','On Time',NULL,'09:02:00','18:18:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(765,30,1,'2026-07-21','On Time',NULL,'09:05:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(766,30,1,'2026-07-22','Late',NULL,'09:23:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(767,30,1,'2026-07-23','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(768,30,1,'2026-07-24','Late',NULL,'09:20:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(769,30,1,'2026-07-25','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(770,30,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(771,30,1,'2026-07-27','Late',NULL,'09:19:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(772,30,1,'2026-07-28','Late',NULL,'09:23:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(773,30,1,'2026-07-29','Late',NULL,'09:27:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(774,30,1,'2026-07-30','Late',NULL,'09:17:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(775,30,1,'2026-07-31','On Time',NULL,'09:10:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(776,31,1,'2026-07-01','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(777,31,1,'2026-07-02','Half Day',NULL,'18:22:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(778,31,1,'2026-07-03','On Time',NULL,'09:12:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(779,31,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(780,31,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(781,31,1,'2026-07-06','On Time',NULL,'09:08:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(782,31,1,'2026-07-07','On Time',NULL,'08:45:00','18:23:00',NULL,NULL,0.38,NULL,1,'2026-08-04 14:34:23'),(783,31,1,'2026-07-08','On Time',NULL,'09:03:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(784,31,1,'2026-07-09','On Time',NULL,'08:59:00','18:16:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:23'),(785,31,1,'2026-07-10','On Time',NULL,'09:02:00','18:18:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(786,31,1,'2026-07-11','Late',NULL,'09:18:00','18:19:00',NULL,NULL,8.52,NULL,1,'2026-08-04 14:34:23'),(787,31,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(788,31,1,'2026-07-13','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:23'),(789,31,1,'2026-07-14','Half Day',NULL,'18:19:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(790,31,1,'2026-07-15','Late',NULL,'09:30:00','18:22:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(791,31,1,'2026-07-16','Late',NULL,'09:27:00','18:31:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(792,31,1,'2026-07-17','On Time',NULL,'09:00:00','18:19:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:23'),(793,31,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(794,31,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(795,31,1,'2026-07-20','On Time',NULL,'09:02:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(796,31,1,'2026-07-21','On Time',NULL,'09:11:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(797,31,1,'2026-07-22','Late',NULL,'09:33:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(798,31,1,'2026-07-23','Late',NULL,'09:21:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(799,31,1,'2026-07-24','Late',NULL,'09:17:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(800,31,1,'2026-07-25','Late',NULL,'09:16:00','18:19:00',NULL,NULL,8.55,NULL,1,'2026-08-04 14:34:23'),(801,31,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(802,31,1,'2026-07-27','Late',NULL,'09:45:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(803,31,1,'2026-07-28','On Time',NULL,'09:07:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(804,31,1,'2026-07-29','Late',NULL,'09:19:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(805,31,1,'2026-07-30','Absent',NULL,'09:47:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(806,31,1,'2026-07-31','Late',NULL,'09:18:00','18:20:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(807,35,1,'2026-07-01','Absent',NULL,'09:25:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(808,35,1,'2026-07-02','Late',NULL,'09:39:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(809,35,1,'2026-07-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(810,35,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(811,35,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(812,35,1,'2026-07-06','Late',NULL,'09:29:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(813,35,1,'2026-07-07','Late',NULL,'09:28:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(814,35,1,'2026-07-08','Absent',NULL,'09:30:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(815,35,1,'2026-07-09','Absent',NULL,'09:33:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(816,35,1,'2026-07-10','Late',NULL,'09:39:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(817,35,1,'2026-07-11','Late',NULL,'10:35:00','18:35:00',NULL,NULL,7.50,NULL,1,'2026-08-04 14:34:23'),(818,35,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(819,35,1,'2026-07-13','Absent',NULL,'09:35:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(820,35,1,'2026-07-14','Late',NULL,'10:05:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(821,35,1,'2026-07-15','Late',NULL,'09:32:00','19:13:00',NULL,NULL,0.43,NULL,1,'2026-08-04 14:34:23'),(822,35,1,'2026-07-16','Late',NULL,'09:38:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(823,35,1,'2026-07-17','Late',NULL,'09:33:00','18:30:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(824,35,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(825,35,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(826,35,1,'2026-07-20','Late',NULL,'09:43:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(827,35,1,'2026-07-21','Late',NULL,'09:33:00','18:19:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(828,35,1,'2026-07-22','Late',NULL,'09:28:00','16:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(829,35,1,'2026-07-23','Late',NULL,'09:19:00','18:03:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(830,35,1,'2026-07-24','Half Day',NULL,'18:17:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(831,35,1,'2026-07-25','Late',NULL,'10:46:00','18:16:00',NULL,NULL,7.00,NULL,1,'2026-08-04 14:34:23'),(832,35,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(833,35,1,'2026-07-27','Late',NULL,'10:05:00','18:22:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(834,35,1,'2026-07-28','Late',NULL,'09:34:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(835,35,1,'2026-07-29','Half Day',NULL,'14:40:00','18:24:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(836,35,1,'2026-07-30','Late',NULL,'10:45:00','14:53:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(837,35,1,'2026-07-31','Absent',NULL,'09:57:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(838,37,1,'2026-07-01','On Time',NULL,'09:00:00','18:30:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(839,37,1,'2026-07-02','Late',NULL,'09:36:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(840,37,1,'2026-07-03','Late',NULL,'09:40:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(841,37,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(842,37,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(843,37,1,'2026-07-06','Late',NULL,'09:36:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(844,37,1,'2026-07-07','On Time',NULL,'08:54:00','18:24:00',NULL,NULL,0.25,NULL,1,'2026-08-04 14:34:23'),(845,37,1,'2026-07-08','On Time',NULL,'09:04:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(846,37,1,'2026-07-09','On Time',NULL,'09:02:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(847,37,1,'2026-07-10','On Time',NULL,'09:03:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(848,37,1,'2026-07-11','On Time',NULL,'08:56:00','18:20:00',NULL,NULL,8.90,NULL,1,'2026-08-04 14:34:23'),(849,37,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(850,37,1,'2026-07-13','On Time',NULL,'09:09:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(851,37,1,'2026-07-14','On Time',NULL,'08:58:00','18:16:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(852,37,1,'2026-07-15','On Time',NULL,'09:08:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(853,37,1,'2026-07-16','On Time',NULL,'09:01:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(854,37,1,'2026-07-17','On Time',NULL,'09:05:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(855,37,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(856,37,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(857,37,1,'2026-07-20','On Time',NULL,'09:11:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(858,37,1,'2026-07-21','On Time',NULL,'09:05:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(859,37,1,'2026-07-22','On Time',NULL,'09:04:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(860,37,1,'2026-07-23','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(861,37,1,'2026-07-24','Late',NULL,'09:49:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(862,37,1,'2026-07-25','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(863,37,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(864,37,1,'2026-07-27','On Time',NULL,'09:12:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(865,37,1,'2026-07-28','Absent',NULL,'08:59:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(866,37,1,'2026-07-29','On Time',NULL,'08:49:00','18:18:00',NULL,NULL,0.23,NULL,1,'2026-08-04 14:34:23'),(867,37,1,'2026-07-30','On Time',NULL,'09:08:00','18:24:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(868,37,1,'2026-07-31','On Time',NULL,'09:05:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(869,33,1,'2026-07-01','On Time',NULL,'08:59:00','18:29:00',8.50,0.00,0.25,NULL,1,'2026-08-04 14:34:23'),(870,33,1,'2026-07-02','On Time',NULL,'08:58:00','18:16:00',8.30,0.00,0.05,NULL,1,'2026-08-04 14:34:23'),(871,33,1,'2026-07-03','On Time',NULL,'09:10:00','18:16:00',8.10,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(872,33,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(873,33,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(874,33,1,'2026-07-06','Late',NULL,'09:18:00','18:18:00',8.00,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(875,33,1,'2026-07-07','On Time',NULL,'08:54:00','19:22:00',9.47,0.00,1.22,NULL,1,'2026-08-04 14:34:23'),(876,33,1,'2026-07-08','On Time',NULL,'09:04:00','18:16:00',8.20,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(877,33,1,'2026-07-09','On Time',NULL,'09:01:00','18:16:00',8.25,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(878,33,1,'2026-07-10','On Time',NULL,'09:03:00','18:16:00',8.22,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(879,33,1,'2026-07-11','On Time',NULL,'08:56:00','18:19:00',8.38,0.00,8.88,NULL,1,'2026-08-04 14:34:23'),(880,33,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(881,33,1,'2026-07-13','On Time',NULL,'08:55:00','18:16:00',8.35,0.00,0.10,NULL,1,'2026-08-04 14:34:23'),(882,33,1,'2026-07-14','On Time',NULL,'08:58:00','18:16:00',8.30,0.00,0.05,NULL,1,'2026-08-04 14:34:23'),(883,33,1,'2026-07-15','On Time',NULL,'09:08:00','18:18:00',8.17,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(884,33,1,'2026-07-16','On Time',NULL,'09:01:00','18:15:00',8.23,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(885,33,1,'2026-07-17','On Time',NULL,'09:05:00','18:15:00',8.17,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(886,33,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(887,33,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(888,33,1,'2026-07-20','On Time',NULL,'09:06:00','18:17:00',8.18,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(889,33,1,'2026-07-21','On Time',NULL,'09:05:00','18:16:00',8.18,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(890,33,1,'2026-07-22','On Time',NULL,'09:05:00','18:16:00',8.18,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(891,33,1,'2026-07-23','On Time',NULL,'09:01:00','18:16:00',8.25,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(892,33,1,'2026-07-24','On Time',NULL,'09:04:00','18:18:00',8.23,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(893,33,1,'2026-07-25','On Time',NULL,'09:00:00','18:16:00',8.27,0.00,8.77,NULL,1,'2026-08-04 14:34:23'),(894,33,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(895,33,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(896,33,1,'2026-07-28','On Time',NULL,'08:59:00','18:16:00',8.28,0.00,0.03,NULL,1,'2026-08-04 14:34:23'),(897,33,1,'2026-07-29','On Time',NULL,'08:49:00','18:18:00',8.48,0.00,0.23,NULL,1,'2026-08-04 14:34:23'),(898,33,1,'2026-07-30','On Time',NULL,'09:08:00','18:16:00',8.13,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(899,33,1,'2026-07-31','On Time',NULL,'09:05:00','18:16:00',8.18,0.00,NULL,NULL,1,'2026-08-04 14:34:23'),(900,39,1,'2026-07-01','On Time',NULL,'08:58:00','18:30:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:23'),(901,39,1,'2026-07-02','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:23'),(902,39,1,'2026-07-03','On Time',NULL,'08:54:00','18:15:00',NULL,NULL,0.10,NULL,1,'2026-08-04 14:34:23'),(903,39,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(904,39,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(905,39,1,'2026-07-06','Late',NULL,'10:11:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(906,39,1,'2026-07-07','On Time',NULL,'08:55:00','19:22:00',NULL,NULL,1.20,NULL,1,'2026-08-04 14:34:23'),(907,39,1,'2026-07-08','On Time',NULL,'09:09:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(908,39,1,'2026-07-09','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(909,39,1,'2026-07-10','On Time',NULL,'09:03:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(910,39,1,'2026-07-11','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(911,39,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(912,39,1,'2026-07-13','On Time',NULL,'09:01:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(913,39,1,'2026-07-14','On Time',NULL,'08:53:00','18:16:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(914,39,1,'2026-07-15','On Time',NULL,'09:00:00','18:18:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(915,39,1,'2026-07-16','On Time',NULL,'09:08:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(916,39,1,'2026-07-17','Late',NULL,'09:18:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(917,39,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(918,39,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(919,39,1,'2026-07-20','On Time',NULL,'09:01:00','18:17:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(920,39,1,'2026-07-21','On Time',NULL,'09:15:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(921,39,1,'2026-07-22','On Time',NULL,'09:10:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(922,39,1,'2026-07-23','On Time',NULL,'09:04:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(923,39,1,'2026-07-24','On Time',NULL,'08:56:00','18:18:00',NULL,NULL,0.12,NULL,1,'2026-08-04 14:34:23'),(924,39,1,'2026-07-25','On Time',NULL,'08:55:00','18:16:00',NULL,NULL,8.85,NULL,1,'2026-08-04 14:34:23'),(925,39,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(926,39,1,'2026-07-27','On Time',NULL,'09:08:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(927,39,1,'2026-07-28','On Time',NULL,'09:08:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(928,39,1,'2026-07-29','On Time',NULL,'09:04:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(929,39,1,'2026-07-30','On Time',NULL,'09:03:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(930,39,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(931,4,1,'2026-07-01','Absent',NULL,'09:48:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(932,4,1,'2026-07-02','Absent',NULL,'09:30:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(933,4,1,'2026-07-03','Absent',NULL,'09:50:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(934,4,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(935,4,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(936,4,1,'2026-07-06','Absent',NULL,'09:14:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(937,4,1,'2026-07-07','Absent',NULL,'10:38:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(938,4,1,'2026-07-08','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(939,4,1,'2026-07-09','Absent',NULL,'09:09:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(940,4,1,'2026-07-10','Absent',NULL,'09:19:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(941,4,1,'2026-07-11','Absent',NULL,'10:54:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(942,4,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(943,4,1,'2026-07-13','Absent',NULL,'10:37:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(944,4,1,'2026-07-14','Absent',NULL,'09:31:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(945,4,1,'2026-07-15','Absent',NULL,'09:55:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(946,4,1,'2026-07-16','Absent',NULL,'09:49:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(947,4,1,'2026-07-17','Absent',NULL,'10:18:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(948,4,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(949,4,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(950,4,1,'2026-07-20','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(951,4,1,'2026-07-21','Absent',NULL,'09:27:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(952,4,1,'2026-07-22','Absent',NULL,'10:34:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(953,4,1,'2026-07-23','Absent',NULL,'09:45:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(954,4,1,'2026-07-24','Absent',NULL,'10:49:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(955,4,1,'2026-07-25','Absent',NULL,'09:31:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(956,4,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(957,4,1,'2026-07-27','Absent',NULL,'10:18:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(958,4,1,'2026-07-28','Absent',NULL,'09:32:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(959,4,1,'2026-07-29','Absent',NULL,'09:28:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(960,4,1,'2026-07-30','Half Day',NULL,'11:10:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(961,4,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(962,20,1,'2026-07-01','On Time',NULL,'08:52:00','20:04:00',NULL,NULL,1.95,NULL,1,'2026-08-04 14:34:23'),(963,20,1,'2026-07-02','On Time',NULL,'09:07:00','20:11:00',NULL,NULL,1.82,NULL,1,'2026-08-04 14:34:23'),(964,20,1,'2026-07-03','On Time',NULL,'08:56:00','20:44:00',NULL,NULL,2.55,NULL,1,'2026-08-04 14:34:23'),(965,20,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(966,20,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(967,20,1,'2026-07-06','On Time',NULL,'08:59:00','19:11:00',NULL,NULL,0.95,NULL,1,'2026-08-04 14:34:23'),(968,20,1,'2026-07-07','On Time',NULL,'08:48:00','19:23:00',NULL,NULL,1.33,NULL,1,'2026-08-04 14:34:23'),(969,20,1,'2026-07-08','Absent',NULL,'09:03:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(970,20,1,'2026-07-09','On Time',NULL,'09:06:00','18:02:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(971,20,1,'2026-07-10','On Time',NULL,'09:05:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(972,20,1,'2026-07-11','On Time',NULL,'08:59:00','18:40:00',NULL,NULL,9.18,NULL,1,'2026-08-04 14:34:23'),(973,20,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(974,20,1,'2026-07-13','Absent',NULL,'08:57:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(975,20,1,'2026-07-14','On Time',NULL,'09:02:00','18:50:00',NULL,NULL,0.55,NULL,1,'2026-08-04 14:34:23'),(976,20,1,'2026-07-15','On Time',NULL,'09:02:00','20:14:00',NULL,NULL,1.95,NULL,1,'2026-08-04 14:34:23'),(977,20,1,'2026-07-16','Absent',NULL,'09:03:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(978,20,1,'2026-07-17','On Time',NULL,'08:57:00','19:32:00',NULL,NULL,1.33,NULL,1,'2026-08-04 14:34:23'),(979,20,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(980,20,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(981,20,1,'2026-07-20','On Time',NULL,'09:12:00','18:41:00',NULL,NULL,0.23,NULL,1,'2026-08-04 14:34:23'),(982,20,1,'2026-07-21','On Time',NULL,'09:05:00','20:14:00',NULL,NULL,1.90,NULL,1,'2026-08-04 14:34:23'),(983,20,1,'2026-07-22','On Time',NULL,'08:53:00','18:50:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(984,20,1,'2026-07-23','Half Day',NULL,'19:27:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(985,20,1,'2026-07-24','On Time',NULL,'08:54:00','19:17:00',NULL,NULL,1.13,NULL,1,'2026-08-04 14:34:23'),(986,20,1,'2026-07-25','On Time',NULL,'08:56:00','20:10:00',NULL,NULL,10.73,NULL,1,'2026-08-04 14:34:23'),(987,20,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(988,20,1,'2026-07-27','Half Day',NULL,'22:18:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(989,20,1,'2026-07-28','Half Day',NULL,'22:22:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(990,20,1,'2026-07-29','Half Day',NULL,'22:13:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(991,20,1,'2026-07-30','On Time',NULL,'08:50:00','19:15:00',NULL,NULL,1.17,NULL,1,'2026-08-04 14:34:23'),(992,20,1,'2026-07-31','On Time',NULL,'08:47:00','19:16:00',NULL,NULL,1.23,NULL,1,'2026-08-04 14:34:23'),(993,3,1,'2026-07-01','Absent',NULL,'09:09:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(994,3,1,'2026-07-02','On Time',NULL,'08:55:00','18:20:00',NULL,NULL,0.17,NULL,1,'2026-08-04 14:34:23'),(995,3,1,'2026-07-03','On Time',NULL,'08:58:00','18:26:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(996,3,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(997,3,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(998,3,1,'2026-07-06','On Time',NULL,'09:15:00','18:25:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(999,3,1,'2026-07-07','On Time',NULL,'09:11:00','18:20:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1000,3,1,'2026-07-08','On Time',NULL,'09:01:00','18:18:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:23'),(1001,3,1,'2026-07-09','On Time',NULL,'08:54:00','18:26:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:23'),(1002,3,1,'2026-07-10','On Time',NULL,'09:04:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1003,3,1,'2026-07-11','Absent',NULL,'09:00:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1004,3,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1005,3,1,'2026-07-13','On Time',NULL,'09:08:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1006,3,1,'2026-07-14','On Time',NULL,'08:57:00','18:25:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(1007,3,1,'2026-07-15','On Time',NULL,'09:02:00','18:18:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(1008,3,1,'2026-07-16','On Time',NULL,'09:02:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1009,3,1,'2026-07-17','On Time',NULL,'09:02:00','18:18:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(1010,3,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1011,3,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1012,3,1,'2026-07-20','On Time',NULL,'09:01:00','18:18:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:23'),(1013,3,1,'2026-07-21','On Time',NULL,'09:06:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1014,3,1,'2026-07-22','On Time',NULL,'08:59:00','18:17:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(1015,3,1,'2026-07-23','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1016,3,1,'2026-07-24','Absent',NULL,'09:09:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1017,3,1,'2026-07-25','Absent',NULL,'09:00:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1018,3,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1019,3,1,'2026-07-27','On Time',NULL,'09:06:00','18:22:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(1020,3,1,'2026-07-28','On Time',NULL,'09:05:00','18:22:00',NULL,NULL,0.03,NULL,1,'2026-08-04 14:34:23'),(1021,3,1,'2026-07-29','Late',NULL,'09:18:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1022,3,1,'2026-07-30','Late',NULL,'09:16:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1023,3,1,'2026-07-31','On Time',NULL,'09:07:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1024,12,1,'2026-07-01','Half Day',NULL,'14:35:00','18:25:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1025,12,1,'2026-07-02','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1026,12,1,'2026-07-03','Half Day',NULL,'14:28:00','18:09:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1027,12,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1028,12,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1029,12,1,'2026-07-06','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1030,12,1,'2026-07-07','Half Day',NULL,'14:33:00','18:14:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1031,12,1,'2026-07-08','Half Day',NULL,'14:15:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1032,12,1,'2026-07-09','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1033,12,1,'2026-07-10','Half Day',NULL,'14:45:00','18:17:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1034,12,1,'2026-07-11','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1035,12,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1036,12,1,'2026-07-13','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1037,12,1,'2026-07-14','Half Day',NULL,'14:48:00','18:11:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1038,12,1,'2026-07-15','Half Day',NULL,'14:34:00','18:11:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1039,12,1,'2026-07-16','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1040,12,1,'2026-07-17','Half Day',NULL,'14:28:00','18:12:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1041,12,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1042,12,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1043,12,1,'2026-07-20','Half Day',NULL,'14:17:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1044,12,1,'2026-07-21','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1045,12,1,'2026-07-22','Half Day',NULL,'14:34:00','18:13:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1046,12,1,'2026-07-23','Half Day',NULL,'14:51:00','18:26:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1047,12,1,'2026-07-24','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1048,12,1,'2026-07-25','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1049,12,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1050,12,1,'2026-07-27','Half Day',NULL,'14:26:00','18:06:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1051,12,1,'2026-07-28','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1052,12,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1053,12,1,'2026-07-30','Half Day',NULL,'14:54:00','18:05:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1054,12,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1055,22,1,'2026-07-01','Late',NULL,'09:39:00','18:34:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1056,22,1,'2026-07-02','Half Day',NULL,'18:25:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1057,22,1,'2026-07-03','Late',NULL,'10:11:00','19:25:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1058,22,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1059,22,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1060,22,1,'2026-07-06','Late',NULL,'09:36:00','18:39:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1061,22,1,'2026-07-07','Late',NULL,'10:07:00','18:43:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1062,22,1,'2026-07-08','Half Day',NULL,'13:35:00','18:41:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1063,22,1,'2026-07-09','Half Day',NULL,'11:27:00','19:27:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1064,22,1,'2026-07-10','Absent',NULL,'09:39:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1065,22,1,'2026-07-11','Late',NULL,'09:58:00','18:19:00',NULL,NULL,7.85,NULL,1,'2026-08-04 14:34:23'),(1066,22,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1067,22,1,'2026-07-13','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1068,22,1,'2026-07-14','Half Day',NULL,'18:42:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1069,22,1,'2026-07-15','Half Day',NULL,'18:48:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1070,22,1,'2026-07-16','Late',NULL,'09:54:00','19:03:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1071,22,1,'2026-07-17','Late',NULL,'09:49:00','19:31:00',NULL,NULL,0.45,NULL,1,'2026-08-04 14:34:23'),(1072,22,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1073,22,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1074,22,1,'2026-07-20','Late',NULL,'10:10:00','18:36:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1075,22,1,'2026-07-21','Late',NULL,'09:20:00','18:31:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1076,22,1,'2026-07-22','Late',NULL,'10:30:00','18:21:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1077,22,1,'2026-07-23','Late',NULL,'10:00:00','18:56:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1078,22,1,'2026-07-24','Late',NULL,'09:47:00','18:51:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1079,22,1,'2026-07-25','Half Day',NULL,'18:47:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1080,22,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1081,22,1,'2026-07-27','Half Day',NULL,'18:37:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1082,22,1,'2026-07-28','Late',NULL,'09:20:00','18:33:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1083,22,1,'2026-07-29','Half Day',NULL,'18:51:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1084,22,1,'2026-07-30','Half Day',NULL,'18:23:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1085,22,1,'2026-07-31','Late',NULL,'10:03:00','18:44:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1086,16,1,'2026-07-01','On Time',NULL,'08:34:00','18:22:00',NULL,NULL,0.55,NULL,1,'2026-08-04 14:34:23'),(1087,16,1,'2026-07-02','On Time',NULL,'08:55:00','19:14:00',NULL,NULL,1.07,NULL,1,'2026-08-04 14:34:23'),(1088,16,1,'2026-07-03','On Time',NULL,'08:53:00','18:19:00',NULL,NULL,0.18,NULL,1,'2026-08-04 14:34:23'),(1089,16,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1090,16,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1091,16,1,'2026-07-06','On Time',NULL,'08:58:00','18:17:00',NULL,NULL,0.07,NULL,1,'2026-08-04 14:34:23'),(1092,16,1,'2026-07-07','On Time',NULL,'08:48:00','18:23:00',NULL,NULL,0.33,NULL,1,'2026-08-04 14:34:23'),(1093,16,1,'2026-07-08','On Time',NULL,'09:02:00','18:15:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1094,16,1,'2026-07-09','On Time',NULL,'08:55:00','18:27:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:23'),(1095,16,1,'2026-07-10','On Time',NULL,'09:01:00','18:19:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(1096,16,1,'2026-07-11','On Time',NULL,'08:56:00','18:17:00',NULL,NULL,8.85,NULL,1,'2026-08-04 14:34:23'),(1097,16,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1098,16,1,'2026-07-13','On Time',NULL,'08:55:00','18:18:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(1099,16,1,'2026-07-14','On Time',NULL,'08:46:00','18:18:00',NULL,NULL,0.28,NULL,1,'2026-08-04 14:34:23'),(1100,16,1,'2026-07-15','On Time',NULL,'09:00:00','18:16:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(1101,16,1,'2026-07-16','On Time',NULL,'09:01:00','18:17:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(1102,16,1,'2026-07-17','On Time',NULL,'08:54:00','18:17:00',NULL,NULL,0.13,NULL,1,'2026-08-04 14:34:23'),(1103,16,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1104,16,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1105,16,1,'2026-07-20','On Time',NULL,'09:07:00','18:16:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1106,16,1,'2026-07-21','On Time',NULL,'09:04:00','18:20:00',NULL,NULL,0.02,NULL,1,'2026-08-04 14:34:23'),(1107,16,1,'2026-07-22','Absent',NULL,'08:51:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1108,16,1,'2026-07-23','On Time',NULL,'09:06:00','18:18:00',NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1109,16,1,'2026-07-24','On Time',NULL,'08:53:00','18:21:00',NULL,NULL,0.22,NULL,1,'2026-08-04 14:34:23'),(1110,16,1,'2026-07-25','On Time',NULL,'09:08:00','18:29:00',NULL,NULL,8.85,NULL,1,'2026-08-04 14:34:23'),(1111,16,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1112,16,1,'2026-07-27','On Time',NULL,'08:49:00','18:24:00',NULL,NULL,0.33,NULL,1,'2026-08-04 14:34:23'),(1113,16,1,'2026-07-28','On Time',NULL,'08:23:00','18:17:00',NULL,NULL,0.65,NULL,1,'2026-08-04 14:34:23'),(1114,16,1,'2026-07-29','On Time',NULL,'08:39:00','18:14:00',NULL,NULL,0.33,NULL,1,'2026-08-04 14:34:23'),(1115,16,1,'2026-07-30','On Time',NULL,'09:01:00','18:19:00',NULL,NULL,0.05,NULL,1,'2026-08-04 14:34:23'),(1116,16,1,'2026-07-31','Half Day',NULL,'18:16:00',NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1117,19,1,'2026-07-01','On Time',NULL,'08:58:00','16:05:00',6.28,83.06,0.12,NULL,1,'2026-08-04 14:34:23'),(1118,19,1,'2026-07-02','On Time',NULL,'08:59:00','16:03:00',6.27,83.87,0.07,NULL,1,'2026-08-04 14:34:23'),(1119,19,1,'2026-07-03','On Time',NULL,'08:53:00','16:05:00',6.37,79.03,0.20,NULL,1,'2026-08-04 14:34:23'),(1120,19,1,'2026-07-04','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1121,19,1,'2026-07-05','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1122,19,1,'2026-07-06','On Time',NULL,'08:59:00','16:06:00',6.27,83.87,0.12,NULL,1,'2026-08-04 14:34:23'),(1123,19,1,'2026-07-07','On Time',NULL,'08:53:00','16:13:00',6.37,79.03,0.33,NULL,1,'2026-08-04 14:34:23'),(1124,19,1,'2026-07-08','On Time',NULL,'09:01:00','16:14:00',6.23,85.48,0.22,NULL,1,'2026-08-04 14:34:23'),(1125,19,1,'2026-07-09','On Time',NULL,'08:58:00','16:11:00',6.28,83.06,0.22,NULL,1,'2026-08-04 14:34:23'),(1126,19,1,'2026-07-10','On Time',NULL,'09:05:00','16:15:00',6.17,88.71,0.17,NULL,1,'2026-08-04 14:34:23'),(1127,19,1,'2026-07-11','On Time',NULL,'09:01:00','16:18:00',6.28,83.06,0.28,NULL,1,'2026-08-04 14:34:23'),(1128,19,1,'2026-07-12','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1129,19,1,'2026-07-13','On Time',NULL,'08:57:00','16:12:00',6.30,82.26,0.25,NULL,1,'2026-08-04 14:34:23'),(1130,19,1,'2026-07-14','On Time',NULL,'09:09:00','16:15:00',6.10,91.94,0.10,NULL,1,'2026-08-04 14:34:23'),(1131,19,1,'2026-07-15','On Time',NULL,'09:00:00','16:15:00',6.25,84.68,0.25,NULL,1,'2026-08-04 14:34:23'),(1132,19,1,'2026-07-16','On Time',NULL,'09:01:00','16:16:00',6.25,84.68,0.25,NULL,1,'2026-08-04 14:34:23'),(1133,19,1,'2026-07-17','On Time',NULL,'08:57:00','16:12:00',6.30,82.26,0.25,NULL,1,'2026-08-04 14:34:23'),(1134,19,1,'2026-07-18','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1135,19,1,'2026-07-19','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1136,19,1,'2026-07-20','On Time',NULL,'08:54:00','16:05:00',6.35,79.84,0.18,NULL,1,'2026-08-04 14:34:23'),(1137,19,1,'2026-07-21','On Time',NULL,'09:04:00','16:17:00',6.22,86.29,0.22,NULL,1,'2026-08-04 14:34:23'),(1138,19,1,'2026-07-22','On Time',NULL,'08:50:00','16:17:00',6.45,75.00,0.45,NULL,1,'2026-08-04 14:34:23'),(1139,19,1,'2026-07-23','On Time',NULL,'09:01:00','16:16:00',6.25,84.68,0.25,NULL,1,'2026-08-04 14:34:23'),(1140,19,1,'2026-07-24','On Time',NULL,'09:01:00','16:23:00',6.37,79.03,0.37,NULL,1,'2026-08-04 14:34:23'),(1141,19,1,'2026-07-25','On Time',NULL,'09:00:00','16:20:00',6.33,80.65,0.33,NULL,1,'2026-08-04 14:34:23'),(1142,19,1,'2026-07-26','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:23'),(1143,19,1,'2026-07-27','On Time',NULL,'08:50:00','16:13:00',6.42,76.61,0.38,NULL,1,'2026-08-04 14:34:24'),(1144,19,1,'2026-07-28','On Time',NULL,'08:59:00','16:13:00',6.27,83.87,0.23,NULL,1,'2026-08-04 14:34:24'),(1145,19,1,'2026-07-29','On Time',NULL,'09:00:00','16:30:00',6.50,72.58,0.17,NULL,1,'2026-08-04 14:34:24'),(1146,19,1,'2026-07-30','On Time',NULL,'08:51:00','16:13:00',6.40,77.42,0.37,NULL,1,'2026-08-04 14:34:24'),(1147,19,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 14:34:24'),(1315,41,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:14:32'),(1322,18,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:14:32'),(1325,24,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:14:32'),(1326,27,1,'2026-07-31','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:14:32'),(1356,41,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1357,4,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1358,11,1,'2026-08-03','On Time',NULL,'09:00:00','13:30:00',3.75,257.06,NULL,NULL,1,'2026-08-04 16:20:49'),(1359,12,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1360,34,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1361,8,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1362,19,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1363,18,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1364,14,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1365,29,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1366,24,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1367,27,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1368,25,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1369,3,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1370,6,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1371,16,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1372,37,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1373,33,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1374,38,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1375,36,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1376,2,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1377,28,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1378,7,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1379,30,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1380,9,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1381,1,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1382,32,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1383,15,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1384,39,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1385,26,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1386,23,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1387,20,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1388,17,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1389,35,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1390,13,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1391,10,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1392,22,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1393,5,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1394,21,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1395,40,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1396,31,1,'2026-08-03','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:20:49'),(1520,41,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:38:50'),(1527,18,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:38:50'),(1530,24,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:38:50'),(1531,27,1,'2026-07-29','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 16:38:50'),(1684,41,1,'2026-07-02','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:31:59'),(1691,18,1,'2026-07-02','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:31:59'),(1694,24,1,'2026-07-02','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:31:59'),(1695,27,1,'2026-07-02','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:31:59'),(1725,41,1,'2026-07-30','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:32:39'),(1732,18,1,'2026-07-30','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:32:39'),(1735,24,1,'2026-07-30','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:32:39'),(1736,27,1,'2026-07-30','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 17:32:39'),(1873,41,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 18:02:21'),(1880,18,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 18:02:21'),(1883,24,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 18:02:21'),(1884,27,1,'2026-07-27','Absent',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-04 18:02:21');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `benefit_fund_types`
--

DROP TABLE IF EXISTS `benefit_fund_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `benefit_fund_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'primary',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `benefit_fund_types`
--

LOCK TABLES `benefit_fund_types` WRITE;
/*!40000 ALTER TABLE `benefit_fund_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `benefit_fund_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comp_off_credits`
--

DROP TABLE IF EXISTS `comp_off_credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comp_off_credits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `work_date` date NOT NULL,
  `day_type` enum('sunday','saturday','public_holiday') NOT NULL,
  `holiday_name` varchar(150) DEFAULT NULL,
  `status` enum('credited','cancelled') NOT NULL DEFAULT 'credited',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `comp_off_credit_emp_date_unique` (`employee_id`,`work_date`),
  KEY `idx_credits_date` (`work_date`),
  KEY `idx_credits_status` (`status`),
  CONSTRAINT `comp_off_credits_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comp_off_credits`
--

LOCK TABLES `comp_off_credits` WRITE;
/*!40000 ALTER TABLE `comp_off_credits` DISABLE KEYS */;
/*!40000 ALTER TABLE `comp_off_credits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comp_off_requests`
--

DROP TABLE IF EXISTS `comp_off_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comp_off_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `worked_date` date NOT NULL,
  `comp_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `comp_off_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comp_off_requests`
--

LOCK TABLES `comp_off_requests` WRITE;
/*!40000 ALTER TABLE `comp_off_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `comp_off_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comp_off_working_days`
--

DROP TABLE IF EXISTS `comp_off_working_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comp_off_working_days` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `work_date` date NOT NULL,
  `day_type` enum('sunday','saturday','public_holiday') NOT NULL,
  `holiday_name` varchar(150) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `declared_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_date` (`work_date`),
  KEY `idx_working_days_date` (`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comp_off_working_days`
--

LOCK TABLES `comp_off_working_days` WRITE;
/*!40000 ALTER TABLE `comp_off_working_days` DISABLE KEYS */;
/*!40000 ALTER TABLE `comp_off_working_days` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comp_offs`
--

DROP TABLE IF EXISTS `comp_offs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comp_offs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(150) NOT NULL,
  `availed_date` date DEFAULT NULL,
  `status` enum('pending','availed','lapsed') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `comp_off_emp_holiday_unique` (`employee_id`,`holiday_date`),
  KEY `idx_comp_offs_date` (`holiday_date`),
  KEY `idx_comp_offs_status` (`status`),
  CONSTRAINT `comp_offs_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comp_offs`
--

LOCK TABLES `comp_offs` WRITE;
/*!40000 ALTER TABLE `comp_offs` DISABLE KEYS */;
/*!40000 ALTER TABLE `comp_offs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `head_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` (`id`, `name`, `head_id`, `created_at`, `status`) VALUES (1,'IT',NULL,'2026-08-04 17:14:52','active');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `designations`
--

DROP TABLE IF EXISTS `designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `designations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `designations`
--

LOCK TABLES `designations` WRITE;
/*!40000 ALTER TABLE `designations` DISABLE KEYS */;
INSERT INTO `designations` (`id`, `name`, `department_id`, `status`) VALUES (1,'Software Engineer',1,'active');
/*!40000 ALTER TABLE `designations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_benefits`
--

DROP TABLE IF EXISTS `employee_benefits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_benefits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `benefit_fund_type_id` int(11) DEFAULT NULL,
  `frequency` enum('weekly','fortnightly','monthly','quarterly','half_yearly','annual') NOT NULL DEFAULT 'monthly',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `benefit_name` varchar(255) DEFAULT NULL,
  `fund_type` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_mode` enum('cash','cashless') NOT NULL DEFAULT 'cash',
  `effective_month` date NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `added_by` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_eb_fund_type` (`benefit_fund_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_benefits`
--

LOCK TABLES `employee_benefits` WRITE;
/*!40000 ALTER TABLE `employee_benefits` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_benefits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_bonuses`
--

DROP TABLE IF EXISTS `employee_bonuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_bonuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `type` enum('monthly_bonus','performance','festival','overtime','one_time') NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `payroll_month` tinyint(4) NOT NULL,
  `payroll_year` smallint(6) NOT NULL,
  `added_by` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_bonuses`
--

LOCK TABLES `employee_bonuses` WRITE;
/*!40000 ALTER TABLE `employee_bonuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_bonuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_documents`
--

DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_name` varchar(200) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_documents`
--

LOCK TABLES `employee_documents` WRITE;
/*!40000 ALTER TABLE `employee_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_family_members`
--

DROP TABLE IF EXISTS `employee_family_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_family_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `relationship` varchar(50) NOT NULL,
  `dob` date DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `dependency_status` enum('dependent','independent') DEFAULT 'dependent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_family_members`
--

LOCK TABLES `employee_family_members` WRITE;
/*!40000 ALTER TABLE `employee_family_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_family_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_increments`
--

DROP TABLE IF EXISTS `employee_increments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_increments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `effective_date` date NOT NULL,
  `previous_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `new_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `increment_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `increment_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_increments`
--

LOCK TABLES `employee_increments` WRITE;
/*!40000 ALTER TABLE `employee_increments` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_increments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_loans`
--

DROP TABLE IF EXISTS `employee_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `type` enum('loan','advance') DEFAULT 'loan',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,2) DEFAULT 0.00,
  `date_given` date NOT NULL,
  `monthly_deduction` decimal(10,2) DEFAULT 0.00,
  `total_months` int(10) unsigned NOT NULL DEFAULT 1,
  `paid_months` int(10) unsigned NOT NULL DEFAULT 0,
  `returned_amount` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','closed','completed') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_loans`
--

LOCK TABLES `employee_loans` WRITE;
/*!40000 ALTER TABLE `employee_loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_portal_access_log`
--

DROP TABLE IF EXISTS `employee_portal_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_portal_access_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `token_id` int(10) unsigned DEFAULT NULL,
  `event` varchar(40) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_epal_emp` (`employee_id`),
  KEY `idx_epal_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_portal_access_log`
--

LOCK TABLES `employee_portal_access_log` WRITE;
/*!40000 ALTER TABLE `employee_portal_access_log` DISABLE KEYS */;
INSERT INTO `employee_portal_access_log` (`id`, `employee_id`, `token_id`, `event`, `success`, `ip_address`, `user_agent`, `created_at`) VALUES (1,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:48'),(2,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:48'),(3,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:48'),(4,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:48'),(5,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:48'),(6,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:49'),(7,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:49'),(8,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:41:49'),(9,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:13'),(10,11,2,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:13'),(11,11,2,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:13'),(12,11,2,'view_slip',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:14'),(13,11,2,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:14'),(14,11,2,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:14'),(15,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:14'),(16,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:14'),(17,33,3,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:14'),(18,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:15'),(19,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:15'),(20,33,3,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:15'),(21,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:15'),(22,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:16'),(23,33,3,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:16'),(24,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:16'),(25,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:16'),(26,33,3,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:17'),(27,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:17'),(28,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:17'),(29,33,3,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:17'),(30,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(31,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(32,33,3,'locked',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(33,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(34,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(35,33,3,'locked',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(36,33,3,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(37,11,2,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:43:18'),(38,NULL,NULL,'scan_invalid',0,'::1',NULL,'2026-08-13 17:43:33'),(39,11,2,'scan',0,'::1',NULL,'2026-08-13 17:43:57'),(40,11,2,'login_ok',1,'::1',NULL,'2026-08-13 17:43:57'),(41,11,2,'scan',0,'192.168.1.45',NULL,'2026-08-13 17:43:57'),(42,11,2,'view_slip',1,'::1',NULL,'2026-08-13 17:43:57'),(43,11,2,'view_slip',1,'::1',NULL,'2026-08-13 17:43:57'),(44,11,2,'scan',0,'::1','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:48:25'),(45,11,2,'scan',0,'192.168.1.45','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:49:40'),(46,11,2,'scan',0,'192.168.1.45','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:49:46'),(47,11,2,'scan',0,'::1','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:50:22'),(48,11,2,'login_ok',1,'::1','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:50:28'),(49,11,2,'view_attendance',1,'::1','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:50:42'),(50,11,2,'view_slip',1,'::1','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 17:51:01'),(51,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:04'),(52,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:04'),(53,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:04'),(54,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:04'),(55,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:04'),(56,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:05'),(57,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:05'),(58,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 17:52:05'),(59,1,1,'scan',0,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-13 17:57:40'),(60,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:17:59'),(61,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:17:59'),(62,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:17:59'),(63,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:17:59'),(64,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:17:59'),(65,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:18:00'),(66,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:18:00'),(67,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 18:18:00'),(68,1,1,'scan',0,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-13 18:19:02'),(69,1,1,'login_ok',1,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-13 18:20:01'),(70,1,1,'view_attendance',1,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-13 18:20:09'),(71,1,1,'view_attendance',1,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-13 18:20:13'),(72,1,1,'scan',0,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:22:24'),(73,1,1,'login_fail',0,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:23:01'),(74,1,1,'scan',0,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:23:01'),(75,1,1,'scan',0,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:23:45'),(76,1,1,'login_ok',1,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:23:51'),(77,1,1,'view_attendance',1,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:24:03'),(78,1,1,'view_attendance',1,'192.168.1.173','Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-08-13 18:24:40'),(79,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:01'),(80,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:01'),(81,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:01'),(82,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:01'),(83,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:01'),(84,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:02'),(85,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:02'),(86,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:32:02'),(87,33,3,'scan',0,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:41:26'),(88,33,3,'login_ok',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:42:08'),(89,33,3,'view_slip',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:42:28'),(90,33,3,'view_attendance',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:43:56'),(91,33,3,'view_attendance',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:43:59'),(92,33,3,'view_attendance',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:44:26'),(93,33,3,'view_attendance',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:44:28'),(94,33,3,'view_slip',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:46:07'),(95,33,3,'view_attendance',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:46:57'),(96,33,3,'view_attendance',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:47:01'),(97,33,3,'view_slip',1,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 19:47:53'),(98,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:58'),(99,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:58'),(100,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:58'),(101,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:58'),(102,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:58'),(103,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:58'),(104,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:59'),(105,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 19:52:59'),(106,37,5,'scan',0,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 20:06:34'),(107,37,5,'scan',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:06:59'),(108,37,5,'login_ok',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:07:21'),(109,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:07:23'),(110,37,5,'scan',0,'192.168.1.178','Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1','2026-08-13 20:09:10'),(111,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:09:33'),(112,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:09:40'),(113,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:10:41'),(114,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:10:43'),(115,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:10:50'),(116,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:10:57'),(117,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:11:03'),(118,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:19:28'),(119,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:19:35'),(120,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:19:43'),(121,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:19:47'),(122,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:20:09'),(123,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:23:45'),(124,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:23:48'),(125,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:23:55'),(126,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:24:00'),(127,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:24:02'),(128,37,5,'download_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:24:02'),(129,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:24:08'),(130,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:24:17'),(131,37,5,'download_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:24:17'),(132,33,3,'scan',0,'::1',NULL,'2026-08-13 20:26:10'),(133,33,3,'login_ok',1,'::1',NULL,'2026-08-13 20:26:10'),(134,33,3,'view_attendance',1,'::1',NULL,'2026-08-13 20:26:10'),(135,33,3,'view_slip',1,'::1',NULL,'2026-08-13 20:26:10'),(136,33,3,'view_slip',1,'::1',NULL,'2026-08-13 20:26:10'),(137,33,3,'download_slip',1,'::1',NULL,'2026-08-13 20:26:10'),(138,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:09'),(139,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:09'),(140,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:09'),(141,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:09'),(142,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:09'),(143,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:09'),(144,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:10'),(145,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:28:10'),(146,37,5,'scan',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:48:37'),(147,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:10'),(148,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:10'),(149,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:11'),(150,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:11'),(151,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:11'),(152,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:11'),(153,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:11'),(154,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 20:49:11'),(155,37,5,'login_ok',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:51:13'),(156,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:51:17'),(157,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:51:24'),(158,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:51:26'),(159,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:58:07'),(160,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:58:09'),(161,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 20:58:11'),(162,37,5,'scan',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:15:45'),(163,33,3,'scan',0,'::1',NULL,'2026-08-13 21:15:47'),(164,33,3,'login_ok',1,'::1',NULL,'2026-08-13 21:15:47'),(165,33,3,'view_attendance',1,'::1',NULL,'2026-08-13 21:15:47'),(166,37,5,'login_ok',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:15:55'),(167,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:00'),(168,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:03'),(169,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:29'),(170,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:35'),(171,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:38'),(172,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:41'),(173,37,5,'download_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:16:41'),(174,37,5,'view_attendance',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:22:14'),(175,37,5,'scan',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:22:37'),(176,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:37'),(177,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:37'),(178,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:38'),(179,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:38'),(180,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:38'),(181,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:38'),(182,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:38'),(183,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:22:38'),(184,37,5,'scan',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:22:39'),(185,37,5,'scan',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:22:40'),(186,37,5,'login_ok',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:28:04'),(187,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:28:09'),(188,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:28:14'),(189,37,5,'download_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:28:14'),(190,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:28:18'),(191,37,5,'download_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 16; I2404) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/123.0.6312.118 Mobile Safari/537.36 VivoBrowser/14.7.4.1','2026-08-13 21:28:18'),(192,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:29:20'),(193,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:29:25'),(194,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:30:46'),(195,37,5,'download_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:30:46'),(196,37,5,'scan',0,'::1',NULL,'2026-08-13 21:32:19'),(197,37,5,'login_ok',1,'::1',NULL,'2026-08-13 21:32:19'),(198,37,5,'view_slip',1,'::1',NULL,'2026-08-13 21:32:19'),(199,37,5,'view_slip',1,'::1',NULL,'2026-08-13 21:32:19'),(200,37,5,'download_slip',1,'::1',NULL,'2026-08-13 21:32:19'),(201,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:32:20'),(202,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:32:28'),(203,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:32:33'),(204,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-13 21:32:39'),(205,37,5,'scan',0,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.30096.1 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36 MSIX','2026-08-13 21:33:00'),(206,37,5,'login_ok',1,'192.168.1.45','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 21:33:22'),(207,37,5,'view_slip',1,'192.168.1.45','Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','2026-08-13 21:33:22'),(208,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:55'),(209,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:55'),(210,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:55'),(211,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:55'),(212,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:55'),(213,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:55'),(214,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:56'),(215,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:35:56'),(216,37,5,'view_slip',1,'192.168.1.45','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.30096.1 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36 MSIX','2026-08-13 21:39:24'),(217,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-13 21:40:34'),(218,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-13 21:40:47'),(219,37,5,'scan',0,'::1',NULL,'2026-08-13 21:40:56'),(220,37,5,'login_ok',1,'::1',NULL,'2026-08-13 21:40:56'),(221,37,5,'view_slip',1,'::1',NULL,'2026-08-13 21:40:56'),(222,37,5,'view_slip',1,'::1',NULL,'2026-08-13 21:40:56'),(223,37,5,'download_slip',1,'::1',NULL,'2026-08-13 21:40:56'),(224,37,5,'view_attendance',1,'::1',NULL,'2026-08-13 21:40:57'),(225,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-13 21:41:22'),(226,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:30'),(227,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:30'),(228,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:30'),(229,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:30'),(230,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:30'),(231,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:31'),(232,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:31'),(233,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-13 21:41:31'),(234,37,5,'view_slip',1,'192.168.1.39','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-13 21:50:56'),(235,NULL,NULL,'scan_invalid',0,'192.168.1.39','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-13 21:51:01'),(236,NULL,NULL,'scan_invalid',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:51:05'),(237,NULL,NULL,'scan_invalid',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:51:06'),(238,NULL,NULL,'scan_invalid',0,'192.168.1.39','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36','2026-08-13 21:51:08'),(239,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:37'),(240,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:37'),(241,NULL,NULL,'scan_invalid',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:37'),(242,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:37'),(243,1,1,'login_fail',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:37'),(244,1,1,'scan',0,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:37'),(245,1,1,'login_ok',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:38'),(246,1,1,'view_attendance',1,'::1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','2026-08-14 15:01:38');
/*!40000 ALTER TABLE `employee_portal_access_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_promotions`
--

DROP TABLE IF EXISTS `employee_promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_promotions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `letter_id` int(11) DEFAULT NULL,
  `employee_id` int(11) NOT NULL,
  `effective_date` date NOT NULL,
  `promotion_date` date DEFAULT NULL,
  `previous_designation_id` int(11) DEFAULT NULL,
  `new_designation_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `salary_revision` decimal(12,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `letter_reference` varchar(60) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_promo_letter` (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_promotions`
--

LOCK TABLES `employee_promotions` WRITE;
/*!40000 ALTER TABLE `employee_promotions` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_promotions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_qr_tokens`
--

DROP TABLE IF EXISTS `employee_qr_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_qr_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eqt_token` (`token`),
  UNIQUE KEY `uk_eqt_employee` (`employee_id`),
  CONSTRAINT `fk_eqt_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_qr_tokens`
--

LOCK TABLES `employee_qr_tokens` WRITE;
/*!40000 ALTER TABLE `employee_qr_tokens` DISABLE KEYS */;
INSERT INTO `employee_qr_tokens` (`id`, `employee_id`, `token`, `password_hash`, `is_active`, `failed_attempts`, `locked_until`, `last_used_at`, `issued_at`, `created_by`, `created_at`, `updated_at`) VALUES (1,1,'f0ddf81153669da54649d4047246297e8a238627752bbf559a088cb2f599cfe8','$2y$10$.XRsP7dEH6SwJTFULjmxLeBwNWqEnR97C/xLQzsSOUPZEbZ2VUzSm',1,0,NULL,'2026-08-14 15:01:38','2026-08-13 17:57:25',1,'2026-08-13 17:38:02','2026-08-14 15:01:38'),(2,11,'92a4ba1b7839e1f47606ea6933d6a4278ecee7ad7338f82d2aa3e21b0a54865e','$2y$10$0DqQfefw6XEWHIRiZ1ojtO1Fbhl.BHN4KkKN2B9Ce1n0.kqsWRdT6',1,0,NULL,'2026-08-13 17:50:28','2026-08-13 17:43:13',1,'2026-08-13 17:43:13','2026-08-13 17:50:28'),(3,33,'bda5aa6577aea5091cf92360af9d9d417248258d5bc0700c573b3eb5aab90663','$2y$10$xEhV2.e67jTTV7czxL8luOXUIzN0WbFN9ue1sSgSr0m76seC4dSpO',1,0,NULL,'2026-08-13 21:15:47','2026-08-13 20:20:55',1,'2026-08-13 17:43:13','2026-08-13 21:15:47'),(4,2,'725fc914030d3aba68d85c98006515d9ff20763657ea0e3cc48de1188c13f4ec',NULL,1,0,NULL,NULL,'2026-08-13 17:56:22',1,'2026-08-13 17:56:22',NULL),(5,37,'6c76e10670268f75a5f0cd0b0ca8acb8b4f49ac9959cc9c75f0c4735fbe79389','$2y$10$e6S22knZteHu7b7S296Bmub5qVHpBxybKJuJSn8WinzJe2G8jbm7q',1,0,NULL,'2026-08-13 21:40:56','2026-08-14 13:04:08',1,'2026-08-13 19:32:52','2026-08-14 13:04:08'),(6,4,'56ef6141fe03a4be6083c9649595519b17a0d3ced41451325e5883e702a223c3',NULL,1,0,NULL,NULL,'2026-08-13 19:43:20',1,'2026-08-13 19:43:20',NULL),(7,3,'0c3776e2d1ee564b5f3ba5b40ec192535e897298bf7cf40731f755d5912a5043','$2y$10$LAWr9bWmLRnbQBJZss5NoujhqXxlkt3dXVfJOIrN2NZwuqNMsrAv.',1,0,NULL,NULL,'2026-08-13 19:51:36',1,'2026-08-13 19:51:36','2026-08-13 19:51:36'),(8,6,'4ec0f63e1daa1a9e14dd52d0fd5ff6cb5a1abf14e4d67b5848e6dd212558125f',NULL,1,0,NULL,NULL,'2026-08-13 20:40:23',1,'2026-08-13 20:40:23',NULL);
/*!40000 ALTER TABLE `employee_qr_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_shift_schedule`
--

DROP TABLE IF EXISTS `employee_shift_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_shift_schedule` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `shift_id` int(10) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `note` varchar(160) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ess_emp_date` (`employee_id`,`start_date`),
  KEY `idx_ess_shift` (`shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_shift_schedule`
--

LOCK TABLES `employee_shift_schedule` WRITE;
/*!40000 ALTER TABLE `employee_shift_schedule` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_shift_schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `lunch_batch_id` int(10) unsigned DEFAULT NULL,
  `shift_id` int(10) unsigned DEFAULT NULL,
  `employee_id` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(180) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Other','Prefer not to say') DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `designation_id` int(10) unsigned DEFAULT NULL,
  `ot_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pf_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `manager_id` int(10) unsigned DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `probation_end` date DEFAULT NULL,
  `employment_type` enum('Full Time','Part Time','Contract','Intern') DEFAULT 'Full Time',
  `status` enum('Active','Inactive','On Leave','Resigned','Terminated') DEFAULT 'Active',
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(40) DEFAULT NULL,
  `bank_ifsc` varchar(20) DEFAULT NULL,
  `pan_number` varchar(15) DEFAULT NULL,
  `aadhaar_number` varchar(15) DEFAULT NULL,
  `uan_number` varchar(15) DEFAULT NULL,
  `esic_number` varchar(20) DEFAULT NULL,
  `emergency_name` varchar(120) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `fixed_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `variable_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  UNIQUE KEY `email` (`email`),
  KEY `department_id` (`department_id`),
  KEY `designation_id` (`designation_id`),
  KEY `manager_id` (`manager_id`),
  CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `employees_ibfk_2` FOREIGN KEY (`designation_id`) REFERENCES `designations` (`id`),
  CONSTRAINT `employees_ibfk_3` FOREIGN KEY (`manager_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` (`id`, `entity_id`, `lunch_batch_id`, `shift_id`, `employee_id`, `name`, `email`, `phone`, `dob`, `gender`, `blood_group`, `address`, `city`, `state`, `pincode`, `department_id`, `designation_id`, `ot_enabled`, `pf_enabled`, `manager_id`, `join_date`, `probation_end`, `employment_type`, `status`, `bank_name`, `bank_account`, `bank_ifsc`, `pan_number`, `aadhaar_number`, `uan_number`, `esic_number`, `emergency_name`, `emergency_phone`, `photo`, `fixed_salary`, `variable_salary`, `created_at`, `updated_at`) VALUES (1,3,NULL,1,'0018','Ravi kumar','0018@noemail.local','8825849117','1998-05-09',NULL,'B+','25, Anna Nagar Main Road','Chennai','Tamil Nadu','600040',1,1,0,1,NULL,'2026-04-15',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'emp_6a7dbc2fbf556.jpg',0.00,0.00,'2026-08-04 14:29:57','2026-08-13 20:02:28'),(2,1,NULL,1,'0021','Raj mohan','0021@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-13 20:02:46'),(3,NULL,NULL,1,'0022','Murgan Ramdoss','0022@noemail.local','9940527300','2004-09-23',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-06-22',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(4,NULL,NULL,1,'0026','Antony peter','0026@noemail.local','9962048534',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2025-02-28',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(5,NULL,NULL,1,'0028','Subash','subashbscit2020@gmail.com','8870132055',NULL,NULL,NULL,'thirupur',NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2025-06-20',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(6,NULL,NULL,1,'0029','Muthu R','0029@noemail.local','9820680177',NULL,NULL,NULL,'telephone Nagar perungudi',NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2025-06-11',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(7,NULL,NULL,1,'0030','Rajesh  T','0030@noemail.local','9043507441',NULL,NULL,NULL,'Kannaigi nagar',NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2025-07-11',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,16900.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(8,NULL,NULL,1,'0031','Bikash Patbandha','0031@noemail.local','8882517484',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2025-07-08',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(9,NULL,NULL,1,'0032','Ram kumar','0032@noemail.local','9884927957','1978-01-05',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-04-10',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(10,NULL,NULL,1,'0033','Srithar','0033@noemail.local','90038184266','1997-05-17',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-06-24',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(11,3,NULL,1,'0034','Arul Raj','arulrajmech501@gmail.com','6379589107','2000-07-15',NULL,NULL,'Madipakkam',NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-06-01',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,15000.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(12,NULL,NULL,1,'0035','Aswathi','0035@noemail.local','7550257430','2008-05-25',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-05-04',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(13,NULL,NULL,1,'0036','Sriram magdyn','sriramsri9454@gmail.com','6380906087','1999-09-23',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-05-05',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(14,1,NULL,1,'0037','Gopika','0037@noemail.local','8787870816',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,0,1,NULL,NULL,NULL,'Full Time','Active','SBI','456453264728',NULL,NULL,NULL,'8293y9219393893','89399028039284903',NULL,NULL,NULL,15000.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(15,NULL,NULL,1,'0038','Revathi','0038@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(16,NULL,NULL,1,'0039','Namashivam','0039@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(17,NULL,NULL,1,'0040','Shaul Ahmeed','0040@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(18,NULL,NULL,1,'0330','Empname0330','0330@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(19,NULL,NULL,1,'11','CHITRA','11@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,12000.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:22:39'),(20,NULL,NULL,1,'MAGDYN-001','SATISH KUMAR ANAND','magdyn.001@noemail.local','9381050572',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(21,NULL,NULL,1,'MAGDYN-002','SUNDARA MURTHY','magdyn.002@noemail.local','9962096005',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(22,NULL,NULL,1,'MAGDYN-003','STEPHEN RAJ KUMAR','magdyn.003@noemail.local','9962096006',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(23,NULL,NULL,1,'MAGDYN-004','SARAVANAN','magdyn.004@noemail.local','8056117277',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(24,NULL,NULL,1,'MAGDYN-005','MAHESH','magdyn.005@noemail.local','9445167824',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(25,NULL,NULL,1,'MAGDYN-006','MUNUSAMY','magdyn.006@noemail.local','8939160852',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(26,NULL,NULL,1,'MAGDYN-007','S.UMAPATHI','umapathivibu@gmail.com','9865613619',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2025-04-11',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(27,NULL,NULL,1,'MAGDYN-008','MOHAN','magdyn.008@noemail.local','9962143767',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(28,NULL,NULL,1,'MAGDYN-009','Rajeev  Gandhi','magdyn.009@noemail.local',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(29,NULL,NULL,1,'MAGDYN-010','JAMES SELVARAJ','magdyn.010@noemail.local','9445745259',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(30,NULL,NULL,1,'MAGDYN-012','Rakesh Balu','magdyn.012@noemail.local','7358984609',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2024-08-12',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(31,NULL,NULL,1,'MAGDYN-014','VINOD','magdyn.014@noemail.local','9499006343',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(32,NULL,NULL,1,'MAGDYN-016','REKHA','magdyn.016@noemail.local','7299110023',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(33,1,NULL,1,'MAGDYN017','NAVEEN RAJ  K','naveenrajkrishnan2003@gmail.com','7094842134','2003-12-01','Male','B+','No. 24, Lakshmi Nagar, 3rd Cross Street Velachery Chennai – 600042 Tamil Nadu India','Chennai','Tamil Nadu','60004 2',1,1,0,0,NULL,'2026-03-16',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,12000.00,0.00,'2026-08-04 14:29:57','2026-08-13 19:56:04'),(34,1,NULL,1,'MAGDYN-019','BHAVATHARANI','magdyn.019@noemail.local','9003018847',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2024-09-26',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,14000.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:22:39'),(35,NULL,NULL,1,'MAGDYN-020','SHYAM SUNDAR','magdyn.020@noemail.local','8220719802',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(36,NULL,NULL,1,'MAGDYN-023.','Ragavendira','srkumar3798@gmail.com','8637478125','1998-04-03',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-04-01',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(37,1,NULL,1,'MAGDYN-024','Nanda Kumar','magdyn.024@noemail.local','7010089546','2003-03-28','Male','A+','Redhills,Chennai,6000096',NULL,NULL,NULL,1,1,0,1,NULL,'2024-08-12',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'emp_6a7dce750e727.png',15000.00,0.00,'2026-08-04 14:29:57','2026-08-13 19:56:59'),(38,NULL,NULL,1,'MAGDYN025','Pramoth','magdyn025@noemail.local','8838161046',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(39,NULL,NULL,1,'MAGDYN027','Rohith','rohithkalai004@gmail.com','6385573123','2005-04-24',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2026-03-20',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(40,NULL,NULL,1,'MAGDYN-13','VIDHYA .M','magdyn.13@noemail.local','9176632733',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2024-04-15',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42'),(41,NULL,NULL,1,'MAGDYN-15','ANJU','magdyn.15@noemail.local','7401755622',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,'2024-05-01',NULL,'Full Time','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,'2026-08-04 14:29:57','2026-08-04 17:17:42');
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entities`
--

DROP TABLE IF EXISTS `entities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `name_font` varchar(32) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `website` varchar(255) DEFAULT NULL,
  `signatory_name` varchar(255) DEFAULT NULL,
  `signatory_title` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entities`
--

LOCK TABLES `entities` WRITE;
/*!40000 ALTER TABLE `entities` DISABLE KEYS */;
INSERT INTO `entities` (`id`, `name`, `name_font`, `address`, `city`, `state`, `pincode`, `phone`, `email`, `logo`, `created_at`, `website`, `signatory_name`, `signatory_title`, `signature`) VALUES (1,'Magneto Dynamics Pvt Ltd','georgia','123 Business Park','Chennai','Tamil Nadu','600001','','','entity_1786630063_80eeea8b.jpg','2026-08-04 15:48:46','',NULL,'',NULL),(3,'Magdyn IT','copperplate','','','','','','magdynpvtltd@gmail.com','entity_1786624525_7bdd6aa5.jpg','2026-08-04 15:51:25','',NULL,'',NULL);
/*!40000 ALTER TABLE `entities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holiday_types`
--

DROP TABLE IF EXISTS `holiday_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holiday_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'primary',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holiday_types`
--

LOCK TABLES `holiday_types` WRITE;
/*!40000 ALTER TABLE `holiday_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `holiday_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `h_date` date NOT NULL,
  `type` enum('National','Optional','Company') DEFAULT 'National',
  `holiday_type_id` int(10) unsigned DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `is_working_day` tinyint(1) NOT NULL DEFAULT 0,
  `working_day_reason` varchar(500) DEFAULT NULL,
  `source` enum('manual','national','import') NOT NULL DEFAULT 'manual',
  PRIMARY KEY (`id`),
  UNIQUE KEY `h_date` (`h_date`),
  KEY `idx_holidays_type` (`holiday_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holidays`
--

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_balances`
--

DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_balances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `leave_type_id` int(10) unsigned NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `total_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `used_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `lb_emp_type_year` (`employee_id`,`leave_type_id`,`year`),
  KEY `leave_type_id` (`leave_type_id`),
  CONSTRAINT `leave_balances_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_balances_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balances`
--

LOCK TABLES `leave_balances` WRITE;
/*!40000 ALTER TABLE `leave_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `leave_type_id` int(10) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_requested` decimal(5,1) NOT NULL DEFAULT 1.0,
  `reason` text DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lr_emp` (`employee_id`),
  KEY `idx_lr_type` (`leave_type_id`),
  KEY `idx_lr_status` (`status`),
  KEY `idx_lr_start` (`start_date`),
  CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_types`
--

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `days_allowed` int(11) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `is_comp_off` tinyint(1) NOT NULL DEFAULT 0,
  `carry_forward` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_types`
--

LOCK TABLES `leave_types` WRITE;
/*!40000 ALTER TABLE `leave_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `letters`
--

DROP TABLE IF EXISTS `letters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `letters` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `type` enum('Offer','Confirmation','Increment','Promotion') NOT NULL,
  `issued_date` date NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `issued_by` int(10) unsigned DEFAULT NULL,
  `status` enum('Draft','Issued','Acknowledged') DEFAULT 'Draft',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `letters_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `letters`
--

LOCK TABLES `letters` WRITE;
/*!40000 ALTER TABLE `letters` DISABLE KEYS */;
INSERT INTO `letters` (`id`, `employee_id`, `type`, `issued_date`, `reference`, `content`, `pdf_path`, `issued_by`, `status`, `created_at`) VALUES (1,11,'Offer','2026-08-04','HR/O/2026/0001','Dear Arul Raj,\r\n\r\nWith reference to your application and the interviews you had with Magdyn IT, we are pleased to offer you employment in our company on the following terms and conditions.\r\n\r\n1. Designation          : N/A\r\n2. Department           : N/A\r\n3. Date Of Joining      : \r\n4. Compensation         : Rs 15,000 per month + retirals\r\n5. Probation            : First six months from the date of joining will be treated as probation period. During this period, no increments will apply.\r\n6. Confirmation         : After completion of six months, we will evaluate your performance and decide whether to retain your services. Unless the employment is confirmed in writing at the end of the probation period, it should be considered terminated.\r\n7. House Of work        : 9.00am to 6.15pm (with weekly off as per company policy)\r\n8. Notice Of termination: During the probation period, your service can be terminated by either side by giving two day\'s written notice. Upon confirmation, one month\'s written notice is required from either side. If you are already on an assignment and if your presence in the assignment is necessary as assessed by the management, the management reserves the right to require you to work till the assignment is complete.\r\n9. Leave Policy         : As per the rules of the company, you can avail 6 days casual & 6 days sick leave per year.\r\n\r\nPlease sign and return the copy of this letter in token of your acceptance, if the terms and conditions specified above and enclosed are acceptable to you.\r\n\r\nWe welcome you to Magdyn IT and look forward to your contribution to the success and growth of the Company\r\nFor Magdyn IT\r\n\r\n\r\n\r\nAuthorized Signatory\r\n\r\nI agree to the above terms and conditions and will be joining on:\r\n\r\n[ Arul Raj ]                              confirmed Date Of Joining\r\n                                             \r\n\r\n____________________________________________________________\r\nSALARY BREAKUP\r\n____________________________________________________________\r\n1. Basic: 8,250\r\n2. HRA: 3,750\r\n3. Conveyance allowance: 750\r\n4. Vehicle allowance: 750\r\n5. Product Incentive: 1,500\r\n   Gross Pay: 15,000\r\n\r\n   Total Cost to Company: 15,000\r\n\r\nNote :\r\n1. All payments are subject to Tax deduction at source (TDS). You are responsible for declaring your tax exemptions & tax liabilities\r\n2. Take home pay will be Gross Pay - Applicable Statutory deductions(PF, ESI, Professional Tax etc.)\r\n3. All reimbursements are at actuals and need to be supported with bills/vouchers whenever available\r\n\r\nThis offer is contingent upon satisfactory completion of all pre-employment requirements.',NULL,1,'Draft','2026-08-04 15:53:45'),(2,34,'Offer','2026-08-04','HR/O/2026/0002','Dear BHAVATHARANI,\r\n\r\nWith reference to your application and the interviews you had with Magneto Dynamics Pvt Ltd, we are pleased to offer you employment in our company on the following terms and conditions.\r\n\r\n1. Designation          : N/A\r\n2. Department           : N/A\r\n3. Date Of Joining      : \r\n4. Compensation         : Rs 14,000 per month + retirals\r\n5. Probation            : First six months from the date of joining will be treated as probation period. During this period, no increments will apply.\r\n6. Confirmation         : After completion of six months, we will evaluate your performance and decide whether to retain your services. Unless the employment is confirmed in writing at the end of the probation period, it should be considered terminated.\r\n7. House Of work        : 9.00am to 6.15pm (with weekly off as per company policy)\r\n8. Notice Of termination: During the probation period, your service can be terminated by either side by giving two day\'s written notice. Upon confirmation, one month\'s written notice is required from either side. If you are already on an assignment and if your presence in the assignment is necessary as assessed by the management, the management reserves the right to require you to work till the assignment is complete.\r\n9. Leave Policy         : As per the rules of the company, you can avail 6 days casual & 6 days sick leave per year.\r\n\r\nPlease sign and return the copy of this letter in token of your acceptance, if the terms and conditions specified above and enclosed are acceptable to you.\r\n\r\nWe welcome you to Magneto Dynamics Pvt Ltd and look forward to your contribution to the success and growth of the Company\r\nFor Magneto Dynamics Pvt Ltd\r\n\r\n\r\n\r\nAuthorized Signatory\r\n\r\nI agree to the above terms and conditions and will be joining on:\r\n\r\n[ BHAVATHARANI ]                              confirmed Date Of Joining\r\n                                             \r\n\r\n____________________________________________________________\r\nSALARY BREAKUP\r\n____________________________________________________________\r\n1. Basic: 7,700\r\n2. HRA: 3,500\r\n3. Conveyance allowance: 700\r\n4. Vehicle allowance: 700\r\n5. Product Incentive: 1,400\r\n   Gross Pay: 14,000\r\n\r\n   Total Cost to Company: 14,000\r\n\r\nNote :\r\n1. All payments are subject to Tax deduction at source (TDS). You are responsible for declaring your tax exemptions & tax liabilities\r\n2. Take home pay will be Gross Pay - Applicable Statutory deductions(PF, ESI, Professional Tax etc.)\r\n3. All reimbursements are at actuals and need to be supported with bills/vouchers whenever available\r\n\r\nThis offer is contingent upon satisfactory completion of all pre-employment requirements.',NULL,1,'Draft','2026-08-04 15:54:02');
/*!40000 ALTER TABLE `letters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_repayments`
--

DROP TABLE IF EXISTS `loan_repayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_repayments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_loan_id` int(11) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `salary_slip_id` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_loan_id` (`employee_loan_id`),
  KEY `salary_slip_id` (`salary_slip_id`),
  CONSTRAINT `fk_loan_repay_loan` FOREIGN KEY (`employee_loan_id`) REFERENCES `employee_loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_repayments`
--

LOCK TABLES `loan_repayments` WRITE;
/*!40000 ALTER TABLE `loan_repayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_repayments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lunch_batches`
--

DROP TABLE IF EXISTS `lunch_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lunch_batches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(80) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lunch_batches`
--

LOCK TABLES `lunch_batches` WRITE;
/*!40000 ALTER TABLE `lunch_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `lunch_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text DEFAULT NULL,
  `type` varchar(30) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `od_requests`
--

DROP TABLE IF EXISTS `od_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `od_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `place` varchar(200) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `od_date` date NOT NULL,
  `purpose` text DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `requested_at` datetime DEFAULT current_timestamp(),
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_od_status` (`status`),
  KEY `idx_od_emp` (`employee_id`),
  CONSTRAINT `od_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `od_requests`
--

LOCK TABLES `od_requests` WRITE;
/*!40000 ALTER TABLE `od_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `od_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `on_duties`
--

DROP TABLE IF EXISTS `on_duties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `on_duties` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `od_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_emp_date` (`employee_id`,`od_date`),
  KEY `idx_od_date` (`od_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `on_duties`
--

LOCK TABLES `on_duties` WRITE;
/*!40000 ALTER TABLE `on_duties` DISABLE KEYS */;
/*!40000 ALTER TABLE `on_duties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_runs`
--

DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payroll_month` char(7) NOT NULL,
  `status` enum('Draft','Processed','Finalized') DEFAULT 'Draft',
  `processed_by` int(10) unsigned DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_month` (`payroll_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_runs`
--

LOCK TABLES `payroll_runs` WRITE;
/*!40000 ALTER TABLE `payroll_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(60) NOT NULL,
  `action` varchar(40) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mod_act` (`module`,`action`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` (`id`, `module`, `action`, `label`) VALUES (1,'idcard','view','View / Print Employee ID Card'),(2,'idcard','generate','Generate Employee ID Card & QR token'),(3,'idcard','revoke','Revoke / Regenerate Employee QR token');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `push_subscriptions`
--

DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` text DEFAULT NULL,
  `auth_key` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `push_subscriptions`
--

LOCK TABLES `push_subscriptions` WRITE;
/*!40000 ALTER TABLE `push_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `push_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pwa_module_access`
--

DROP TABLE IF EXISTS `pwa_module_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pwa_module_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(60) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pwa_module_access`
--

LOCK TABLES `pwa_module_access` WRITE;
/*!40000 ALTER TABLE `pwa_module_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `pwa_module_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (1,1),(1,2),(1,3),(71,1);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `self_scope` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` (`id`, `name`, `description`, `self_scope`, `created_at`) VALUES (1,'Super Admin','Full system access',0,'2026-05-28 15:48:13'),(71,'Employee','Self-service employee login',1,'2026-08-04 14:29:57');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salary_components`
--

DROP TABLE IF EXISTS `salary_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salary_components` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('allowance','deduction') NOT NULL,
  `calculation_type` enum('percentage','fixed') NOT NULL,
  `value` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salary_components`
--

LOCK TABLES `salary_components` WRITE;
/*!40000 ALTER TABLE `salary_components` DISABLE KEYS */;
INSERT INTO `salary_components` (`id`, `name`, `type`, `calculation_type`, `value`, `sort_order`, `created_at`, `updated_at`) VALUES (1,'Basic','allowance','percentage',55.0000,1,'2026-08-04 14:41:16','2026-08-04 14:41:16'),(2,'HRA','allowance','percentage',25.0000,2,'2026-08-04 14:41:16','2026-08-04 14:41:16'),(3,'Conveyance allowance','allowance','percentage',5.0000,3,'2026-08-04 14:41:16','2026-08-04 14:41:16'),(4,'Vehicle allowance','allowance','percentage',5.0000,4,'2026-08-04 14:41:16','2026-08-04 14:41:16'),(5,'Product Incentive','allowance','percentage',10.0000,5,'2026-08-04 14:41:16','2026-08-04 14:41:16');
/*!40000 ALTER TABLE `salary_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salary_slips`
--

DROP TABLE IF EXISTS `salary_slips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salary_slips` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payroll_run_id` int(10) unsigned DEFAULT NULL,
  `employee_id` int(10) unsigned NOT NULL,
  `payroll_month` char(7) NOT NULL,
  `fixed_salary` decimal(12,2) DEFAULT 0.00,
  `variable_salary` decimal(12,2) DEFAULT 0.00,
  `allowances` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowances`)),
  `deductions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deductions_json`)),
  `attendance_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attendance_summary`)),
  `working_days` int(11) DEFAULT 26,
  `present_days` int(11) DEFAULT 0,
  `lop_days` int(11) DEFAULT 0,
  `basic` decimal(12,2) DEFAULT 0.00,
  `hra` decimal(12,2) DEFAULT 0.00,
  `conveyance` decimal(12,2) DEFAULT 0.00,
  `medical` decimal(12,2) DEFAULT 0.00,
  `special_allow` decimal(12,2) DEFAULT 0.00,
  `other_allow` decimal(12,2) DEFAULT 0.00,
  `gross_earnings` decimal(12,2) DEFAULT 0.00,
  `pf_employee` decimal(12,2) DEFAULT 0.00,
  `pf_employer` decimal(12,2) DEFAULT 0.00,
  `esi_employee` decimal(12,2) DEFAULT 0.00,
  `esi_employer` decimal(12,2) DEFAULT 0.00,
  `tds` decimal(12,2) DEFAULT 0.00,
  `other_deductions` decimal(12,2) DEFAULT 0.00,
  `total_deductions` decimal(12,2) DEFAULT 0.00,
  `net_pay` decimal(12,2) DEFAULT 0.00,
  `slip_pdf` varchar(255) DEFAULT NULL,
  `status` enum('Draft','Generated','Sent') DEFAULT 'Draft',
  `slip_type` enum('batch','individual') NOT NULL DEFAULT 'batch',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_payroll_month` (`employee_id`,`payroll_month`),
  KEY `fk_slip_payroll_run` (`payroll_run_id`),
  CONSTRAINT `fk_slip_payroll_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `salary_slips_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salary_slips`
--

LOCK TABLES `salary_slips` WRITE;
/*!40000 ALTER TABLE `salary_slips` DISABLE KEYS */;
INSERT INTO `salary_slips` (`id`, `payroll_run_id`, `employee_id`, `payroll_month`, `fixed_salary`, `variable_salary`, `allowances`, `deductions_json`, `attendance_summary`, `working_days`, `present_days`, `lop_days`, `basic`, `hra`, `conveyance`, `medical`, `special_allow`, `other_allow`, `gross_earnings`, `pf_employee`, `pf_employer`, `esi_employee`, `esi_employer`, `tds`, `other_deductions`, `total_deductions`, `net_pay`, `slip_pdf`, `status`, `slip_type`, `created_at`) VALUES (1,NULL,11,'2026-07',15000.00,0.00,'{\"Basic\":7717.74,\"HRA\":3508.06,\"Conveyance allowance\":701.61,\"Vehicle allowance\":701.61,\"Product Incentive\":1403.23}','{\"Provident Fund (PF)\":926.13,\"ESI (Employee)\":112.5,\"Late Deduction (105 min, 2× rate)\":116.43}','{\"shift_name\":null,\"total_working_days\":25,\"present_days\":23,\"half_days\":0,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":2,\"late_days\":4,\"no_checkout_absent\":0,\"half_day_deduction\":0,\"short_days\":0,\"short_deduction\":0,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":33.27,\"late_minutes\":105,\"late_grace_minutes\":90,\"deductable_late_mins\":210,\"late_deduction\":116.43,\"absent_deduction\":967.74,\"paid_days\":29,\"unpaid_days\":2,\"earn_ratio\":0.935484,\"basic_full_month\":8250,\"per_day_salary\":483.87,\"per_hour_rate\":60.48,\"calendar_days\":31,\"basic_salary\":7717.74,\"ctc_per_month\":15000}',25,23,2,7717.74,3508.06,0.00,0.00,0.00,0.00,14032.25,926.13,926.13,112.50,112.50,0.00,0.00,1155.06,12877.19,NULL,'Generated','individual','2026-08-04 14:35:51'),(3,NULL,33,'2026-07',12000.00,0.00,'{\"Basic\":6600,\"HRA\":3000,\"Conveyance allowance\":600,\"Vehicle allowance\":600,\"Product Incentive\":1200}','{\"Absent Deduction (1 days)\":387.1}','{\"shift_name\":null,\"total_working_days\":25,\"present_days\":24,\"half_days\":0,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":1,\"late_days\":1,\"no_checkout_absent\":0,\"half_day_deduction\":0,\"short_days\":0,\"short_deduction\":0,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":26.61,\"late_minutes\":18,\"late_grace_minutes\":90,\"deductable_late_mins\":0,\"late_deduction\":0,\"absent_deduction\":387.1,\"per_day_salary\":387.1,\"per_hour_rate\":48.39,\"calendar_days\":31,\"basic_salary\":6600,\"ctc_per_month\":12000}',25,24,1,6600.00,3000.00,0.00,0.00,0.00,0.00,12000.00,0.00,0.00,0.00,0.00,0.00,0.00,387.10,11612.90,NULL,'Generated','individual','2026-08-04 14:42:02'),(5,NULL,34,'2026-07',14000.00,0.00,'{\"Basic\":5566.45,\"HRA\":2530.21,\"Conveyance allowance\":506.04,\"Vehicle allowance\":506.04,\"Product Incentive\":1012.08}','{\"Provident Fund (PF)\":667.97,\"ESI (Employee)\":105,\"Half Day - Late Arrival (1 day)\":124.19,\"Late Deduction (274 min, 2× rate)\":283.58}','{\"shift_name\":null,\"total_working_days\":25,\"present_days\":17,\"half_days\":1,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":8,\"late_days\":9,\"no_checkout_absent\":0,\"half_day_deduction\":225.81,\"short_days\":1,\"short_deduction\":266.27,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":31.05,\"late_minutes\":274,\"late_grace_minutes\":90,\"deductable_late_mins\":548,\"late_deduction\":283.58,\"absent_deduction\":3612.9,\"paid_days\":22.41,\"unpaid_days\":8.59,\"earn_ratio\":0.722916,\"basic_full_month\":7700,\"per_day_salary\":451.61,\"per_hour_rate\":56.45,\"calendar_days\":31,\"basic_salary\":5566.45,\"ctc_per_month\":14000}',25,17,8,5566.45,2530.21,0.00,0.00,0.00,0.00,10120.82,667.97,667.97,105.00,105.00,0.00,0.00,1180.74,8940.08,NULL,'Generated','individual','2026-08-04 15:30:48'),(7,NULL,7,'2026-07',16900.00,0.00,'{\"Basic\":7795.81,\"HRA\":3543.55,\"Conveyance allowance\":708.71,\"Vehicle allowance\":708.71,\"Product Incentive\":1417.42}','{\"Provident Fund (PF)\":935.5,\"ESI (Employee)\":126.75,\"Late Deduction (688 min, 2× rate)\":859.54}','{\"shift_name\":null,\"total_working_days\":25,\"present_days\":20,\"half_days\":0,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":5,\"late_days\":20,\"no_checkout_absent\":0,\"half_day_deduction\":0,\"short_days\":0,\"short_deduction\":0,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":37.48,\"late_minutes\":688,\"late_grace_minutes\":90,\"deductable_late_mins\":1376,\"late_deduction\":859.54,\"absent_deduction\":2725.81,\"paid_days\":26,\"unpaid_days\":5,\"earn_ratio\":0.83871,\"basic_full_month\":9295,\"per_day_salary\":545.16,\"per_hour_rate\":68.15,\"calendar_days\":31,\"basic_salary\":7795.81,\"ctc_per_month\":16900}',25,20,5,7795.81,3543.55,0.00,0.00,0.00,0.00,14174.20,935.50,935.50,126.75,126.75,0.00,0.00,1921.79,12252.41,NULL,'Generated','individual','2026-08-04 15:37:18'),(8,NULL,11,'2026-08',15000.00,0.00,'{\"Basic\":1987.64,\"HRA\":903.47,\"Conveyance allowance\":180.69,\"Vehicle allowance\":180.69,\"Product Incentive\":361.39}','{\"Provident Fund (PF)\":238.52,\"ESI (Employee)\":112.5}','{\"shift_name\":null,\"total_working_days\":24,\"present_days\":1,\"half_days\":0,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":23,\"late_days\":0,\"no_checkout_absent\":0,\"half_day_deduction\":0,\"short_days\":1,\"short_deduction\":257.08,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":33.27,\"late_minutes\":0,\"late_grace_minutes\":90,\"deductable_late_mins\":0,\"late_deduction\":0,\"absent_deduction\":11129.03,\"paid_days\":7.47,\"unpaid_days\":23.53,\"earn_ratio\":0.240926,\"basic_full_month\":8250,\"per_day_salary\":483.87,\"per_hour_rate\":60.48,\"calendar_days\":31,\"basic_salary\":1987.64,\"ctc_per_month\":15000}',24,1,23,1987.64,903.47,0.00,0.00,0.00,0.00,3613.88,238.52,238.52,112.50,112.50,0.00,0.00,351.02,3262.86,NULL,'Generated','individual','2026-08-04 16:21:34'),(9,NULL,19,'2026-07',12000.00,0.00,'{\"Basic\":5303.06,\"HRA\":2410.48,\"Conveyance allowance\":482.1,\"Vehicle allowance\":482.1,\"Product Incentive\":964.19}','{\"Provident Fund (PF)\":636.37,\"ESI (Employee)\":90}','{\"shift_name\":null,\"total_working_days\":25,\"present_days\":24,\"half_days\":0,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":1,\"late_days\":0,\"no_checkout_absent\":0,\"half_day_deduction\":0,\"short_days\":24,\"short_deduction\":1970.98,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":26.61,\"late_minutes\":0,\"late_grace_minutes\":90,\"deductable_late_mins\":0,\"late_deduction\":0,\"absent_deduction\":387.1,\"paid_days\":24.91,\"unpaid_days\":6.09,\"earn_ratio\":0.803494,\"basic_full_month\":6600,\"per_day_salary\":387.1,\"per_hour_rate\":48.39,\"calendar_days\":31,\"basic_salary\":5303.06,\"ctc_per_month\":12000}',25,24,1,5303.06,2410.48,0.00,0.00,0.00,0.00,9641.93,636.37,636.37,90.00,90.00,0.00,0.00,726.37,8915.56,NULL,'Generated','individual','2026-08-04 16:39:12'),(10,NULL,14,'2026-07',15000.00,0.00,'{\"Basic\":7587.45,\"HRA\":3448.84,\"Conveyance allowance\":689.77,\"Vehicle allowance\":689.77,\"Product Incentive\":1379.54}','{\"Provident Fund (PF)\":910.49,\"ESI (Employee)\":112.5,\"Half Day - Late Arrival (1 day)\":133.06,\"Late Deduction (110 min, 2× rate)\":121.98}','{\"shift_name\":\"General\",\"total_working_days\":25,\"present_days\":23,\"half_days\":1,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":2,\"late_days\":2,\"no_checkout_absent\":0,\"half_day_deduction\":241.94,\"short_days\":1,\"short_deduction\":236.9,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":33.27,\"late_minutes\":110,\"late_grace_minutes\":90,\"deductable_late_mins\":220,\"late_deduction\":121.98,\"absent_deduction\":967.74,\"paid_days\":28.51,\"unpaid_days\":2.49,\"earn_ratio\":0.91969,\"basic_full_month\":8250,\"per_day_salary\":483.87,\"per_hour_rate\":60.48,\"calendar_days\":31,\"basic_salary\":7587.45,\"ctc_per_month\":15000}',25,23,2,7587.45,3448.84,0.00,0.00,0.00,0.00,13795.37,910.49,910.49,112.50,112.50,0.00,0.00,1278.03,12517.34,NULL,'Generated','individual','2026-08-04 16:54:28'),(11,NULL,37,'2026-08',15000.00,0.00,'{\"Basic\":1862.9,\"HRA\":846.77,\"Conveyance allowance\":169.35,\"Vehicle allowance\":169.35,\"Product Incentive\":338.71}','{\"Provident Fund (PF)\":223.55,\"ESI (Employee)\":112.5}','{\"shift_name\":\"General\",\"total_working_days\":24,\"present_days\":0,\"half_days\":0,\"leave_days\":0,\"paid_leave_days\":0,\"approved_leave_days\":0,\"absent_days\":24,\"late_days\":0,\"no_checkout_absent\":0,\"half_day_deduction\":0,\"short_days\":0,\"short_deduction\":0,\"ot_hours\":0,\"ot_amount\":0,\"ot_per_hour_rate\":33.27,\"late_minutes\":0,\"late_grace_minutes\":90,\"deductable_late_mins\":0,\"late_deduction\":0,\"absent_deduction\":11612.9,\"paid_days\":7,\"unpaid_days\":24,\"earn_ratio\":0.225806,\"basic_full_month\":8250,\"per_day_salary\":483.87,\"per_hour_rate\":60.48,\"calendar_days\":31,\"basic_salary\":1862.9,\"ctc_per_month\":15000}',24,0,24,1862.90,846.77,0.00,0.00,0.00,0.00,3387.08,223.55,223.55,112.50,112.50,0.00,0.00,336.05,3051.03,NULL,'Generated','individual','2026-08-13 19:45:52');
/*!40000 ALTER TABLE `salary_slips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salary_structures`
--

DROP TABLE IF EXISTS `salary_structures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salary_structures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `basic` decimal(12,2) DEFAULT 0.00,
  `hra` decimal(12,2) DEFAULT 0.00,
  `conveyance` decimal(12,2) DEFAULT 0.00,
  `medical` decimal(12,2) DEFAULT 0.00,
  `special_allow` decimal(12,2) DEFAULT 0.00,
  `other_allow` decimal(12,2) DEFAULT 0.00,
  `gross` decimal(12,2) GENERATED ALWAYS AS (`basic` + `hra` + `conveyance` + `medical` + `special_allow` + `other_allow`) STORED,
  `lop_per_day` decimal(10,4) GENERATED ALWAYS AS ((`basic` + `hra` + `conveyance` + `medical` + `special_allow` + `other_allow`) / 26) STORED,
  `is_current` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `salary_structures_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salary_structures`
--

LOCK TABLES `salary_structures` WRITE;
/*!40000 ALTER TABLE `salary_structures` DISABLE KEYS */;
INSERT INTO `salary_structures` (`id`, `employee_id`, `effective_from`, `basic`, `hra`, `conveyance`, `medical`, `special_allow`, `other_allow`, `gross`, `lop_per_day`, `is_current`, `created_at`) VALUES (1,11,'2026-08-04',7500.00,3000.00,1500.00,0.00,3000.00,0.00,15000.00,576.9231,1,'2026-08-04 14:35:04'),(2,33,'2026-08-04',6000.00,2400.00,1200.00,0.00,2400.00,0.00,12000.00,461.5385,1,'2026-08-04 14:38:56'),(3,37,'2026-08-04',7500.00,3000.00,1500.00,0.00,3000.00,0.00,15000.00,576.9231,1,'2026-08-04 14:54:37'),(4,7,'2026-08-04',8450.00,3380.00,1690.00,0.00,3380.00,0.00,16900.00,650.0000,1,'2026-08-04 15:19:04'),(5,34,'2026-08-04',7000.00,2800.00,1400.00,0.00,2800.00,0.00,14000.00,538.4615,1,'2026-08-04 15:30:09'),(6,19,'2026-08-04',6000.00,2400.00,1200.00,0.00,2400.00,0.00,12000.00,461.5385,1,'2026-08-04 16:38:13'),(7,14,'2026-08-04',7500.00,3000.00,1500.00,0.00,3000.00,0.00,15000.00,576.9231,1,'2026-08-04 16:53:56');
/*!40000 ALTER TABLE `salary_structures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shift_breaks`
--

DROP TABLE IF EXISTS `shift_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_breaks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` int(10) unsigned NOT NULL,
  `kind` enum('tea','break') NOT NULL DEFAULT 'tea',
  `name` varchar(80) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift_breaks_shift` (`shift_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shift_breaks`
--

LOCK TABLES `shift_breaks` WRITE;
/*!40000 ALTER TABLE `shift_breaks` DISABLE KEYS */;
INSERT INTO `shift_breaks` (`id`, `shift_id`, `kind`, `name`, `start_time`, `end_time`, `created_at`) VALUES (1,1,'tea','Tea Break 1','11:00:00','11:15:00','2026-08-04 17:17:42'),(2,1,'tea','Tea Break 2','16:00:00','16:15:00','2026-08-04 17:17:42');
/*!40000 ALTER TABLE `shift_breaks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shifts`
--

DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shifts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `daily_grace_mins` int(11) NOT NULL DEFAULT 15,
  `monthly_grace_mins` int(11) NOT NULL DEFAULT 90,
  `ot_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `ot_trigger_time` time DEFAULT NULL,
  `ot_baseline_time` time DEFAULT NULL,
  `half_day_cutoff` time DEFAULT NULL,
  `lunch_start` time DEFAULT NULL,
  `lunch_end` time DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shifts`
--

LOCK TABLES `shifts` WRITE;
/*!40000 ALTER TABLE `shifts` DISABLE KEYS */;
INSERT INTO `shifts` (`id`, `name`, `status`, `start_time`, `end_time`, `daily_grace_mins`, `monthly_grace_mins`, `ot_enabled`, `ot_trigger_time`, `ot_baseline_time`, `half_day_cutoff`, `lunch_start`, `lunch_end`, `created_at`, `updated_at`) VALUES (1,'General','active','09:00:00','18:00:00',15,90,1,'20:30:00','18:15:00','11:00:00','13:00:00','13:30:00','2026-08-04 17:17:42','2026-08-04 17:17:42'),(2,'Morning (6-2)','active','06:00:00','14:00:00',15,90,0,NULL,NULL,'08:00:00',NULL,NULL,'2026-08-04 17:17:42','2026-08-04 17:17:42'),(3,'Evening (2-10)','active','14:00:00','22:00:00',15,90,0,NULL,NULL,'16:00:00',NULL,NULL,'2026-08-04 17:17:42','2026-08-04 17:17:42');
/*!40000 ALTER TABLE `shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `training_course_roles`
--

DROP TABLE IF EXISTS `training_course_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_course_roles` (
  `course_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`course_id`,`role_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `training_course_roles_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `training_courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_course_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `training_course_roles`
--

LOCK TABLES `training_course_roles` WRITE;
/*!40000 ALTER TABLE `training_course_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `training_course_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `training_courses`
--

DROP TABLE IF EXISTS `training_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_courses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `training_type` varchar(80) DEFAULT NULL,
  `trainer_name` varchar(120) DEFAULT NULL,
  `duration_hrs` int(11) DEFAULT 1,
  `is_mandatory` tinyint(1) DEFAULT 0,
  `status` enum('Draft','Active','Completed','Archived') NOT NULL DEFAULT 'Active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `training_courses`
--

LOCK TABLES `training_courses` WRITE;
/*!40000 ALTER TABLE `training_courses` DISABLE KEYS */;
/*!40000 ALTER TABLE `training_courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `training_enrollments`
--

DROP TABLE IF EXISTS `training_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_enrollments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int(10) unsigned NOT NULL,
  `employee_id` int(10) unsigned NOT NULL,
  `enrolled_at` datetime DEFAULT current_timestamp(),
  `status` enum('Enrolled','In Progress','Completed','Failed') DEFAULT 'Enrolled',
  `score` decimal(5,2) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_course_emp` (`course_id`,`employee_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `training_enrollments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `training_courses` (`id`),
  CONSTRAINT `training_enrollments_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `training_enrollments`
--

LOCK TABLES `training_enrollments` WRITE;
/*!40000 ALTER TABLE `training_enrollments` DISABLE KEYS */;
/*!40000 ALTER TABLE `training_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_notification_prefs`
--

DROP TABLE IF EXISTS `user_notification_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notification_prefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `notification_type` varchar(60) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_type` (`user_id`,`notification_type`),
  CONSTRAINT `user_notification_prefs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_notification_prefs`
--

LOCK TABLES `user_notification_prefs` WRITE;
/*!40000 ALTER TABLE `user_notification_prefs` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_notification_prefs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permissions`
--

DROP TABLE IF EXISTS `user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_perm` (`user_id`,`permission_id`),
  KEY `idx_up_user` (`user_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permissions`
--

LOCK TABLES `user_permissions` WRITE;
/*!40000 ALTER TABLE `user_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(180) NOT NULL,
  `name` varchar(120) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role_id` int(10) unsigned NOT NULL DEFAULT 6,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sso_uid` varchar(180) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=225 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`id`, `email`, `name`, `password_hash`, `role_id`, `employee_id`, `is_active`, `sso_uid`, `avatar`, `last_login`, `created_at`) VALUES (1,'admin@hrms.local','System Admin','$2y$12$AYf3RrJ8dJdOneXlVJLK.OMG5/Gpi4Siil5GbvUclbgOkOD6p3riS',1,NULL,1,NULL,NULL,'2026-08-14 15:01:36','2026-05-28 15:48:14'),(218,'subashbscit2020@gmail.com','Subash','$2y$10$ku3R/QvEof27bimj7i8JCuIuKnfba5u2JKJ//ca42gLkBJ8.EYDk6',71,5,1,NULL,NULL,NULL,'2026-08-04 14:29:57'),(219,'arulrajmech501@gmail.com','Arul Raj','$2y$10$5ljvSB8.MEN2Dk62CyFP/ODHJiXPc3nuSE7PQ4jj.tnzr2sO7HRmS',71,11,1,NULL,NULL,NULL,'2026-08-04 14:29:57'),(220,'sriramsri9454@gmail.com','Sriram magdyn','$2y$10$1w3NPSq0eqUKLvpZhxb1oeupqOGFl2g.qEkFpNT.xgPOvtIk5fymG',71,13,1,NULL,NULL,NULL,'2026-08-04 14:29:57'),(221,'umapathivibu@gmail.com','S.UMAPATHI','$2y$10$jlfJUvmkghxOxLflwFBozuFpNdqNfVEVkM3klWz169zHH1boISRDi',71,26,1,NULL,NULL,NULL,'2026-08-04 14:29:57'),(222,'naveenrajkrishnan2003@gmail.com','NAVEEN RAJ  K','$2y$10$/HUDFLFPUMd5WBXIGgDJyO3XPL2SIEO8aButlUVZso5qGMge00dOy',71,33,1,NULL,NULL,NULL,'2026-08-04 14:29:57'),(223,'srkumar3798@gmail.com','Ragavendira','$2y$10$G9wHodrYQEMrxxwpoJGshucL8IFzL9mlENlaGcCM.FwwwHe6YoQd2',71,36,1,NULL,NULL,NULL,'2026-08-04 14:29:57'),(224,'rohithkalai004@gmail.com','Rohith','$2y$10$7/XjEKBmbA5IcS2poNQLx.1s2IDc6YHn3ltXgdRo3lyFXbeAi1yD.',71,39,1,NULL,NULL,NULL,'2026-08-04 14:29:57');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'magdyn_hrms'
--

--
-- Dumping routines for database 'magdyn_hrms'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-14 15:08:00
