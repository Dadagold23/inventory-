-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: inventory
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
-- Table structure for table `approval_actions`
--

DROP TABLE IF EXISTS `approval_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_actions` (
  `action_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `action` enum('approve','reject','review') NOT NULL,
  `comments` text DEFAULT NULL,
  `action_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`action_id`),
  KEY `idx_actions_request` (`request_id`),
  KEY `idx_actions_user` (`user_id`),
  CONSTRAINT `fk_actions_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`request_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval action history';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_actions`
--

LOCK TABLES `approval_actions` WRITE;
/*!40000 ALTER TABLE `approval_actions` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_actions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_requests`
--

DROP TABLE IF EXISTS `approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_requests` (
  `request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `requester_id` bigint(20) unsigned NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `submitted_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_date` timestamp NULL DEFAULT NULL,
  `approval_count` int(10) unsigned NOT NULL DEFAULT 0,
  `required_count` int(10) unsigned NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_requests_workflow` (`workflow_id`),
  KEY `idx_requests_entity` (`entity_type`,`entity_id`),
  KEY `idx_requests_requester` (`requester_id`),
  CONSTRAINT `fk_requests_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_requests_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`workflow_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval requests';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_requests`
--

LOCK TABLES `approval_requests` WRITE;
/*!40000 ALTER TABLE `approval_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_workflow_steps`
--

DROP TABLE IF EXISTS `approval_workflow_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_workflow_steps` (
  `step_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` bigint(20) unsigned NOT NULL,
  `step_number` smallint(5) unsigned NOT NULL,
  `role_required` varchar(50) NOT NULL,
  `action` enum('approve','reject','review') NOT NULL DEFAULT 'approve',
  `notes` text DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`step_id`),
  KEY `idx_steps_workflow` (`workflow_id`),
  CONSTRAINT `fk_steps_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`workflow_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval workflow steps';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_workflow_steps`
--

LOCK TABLES `approval_workflow_steps` WRITE;
/*!40000 ALTER TABLE `approval_workflow_steps` DISABLE KEYS */;
INSERT INTO `approval_workflow_steps` VALUES (1,1,1,'manager','approve','Manager approval for expenses',0,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(2,1,2,'admin','approve','Admin approval for amounts over threshold',1,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(3,2,1,'manager','approve','Manager approval for POs',0,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(4,3,1,'admin','approve','Admin approval for invoices',0,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(5,1,1,'manager','approve','Manager approval for expenses',0,'2026-10-28 14:56:58','2026-10-28 14:56:58'),(6,1,2,'admin','approve','Admin approval for amounts over threshold',1,'2026-10-28 14:56:58','2026-10-28 14:56:58'),(7,2,1,'manager','approve','Manager approval for POs',0,'2026-10-28 14:56:58','2026-10-28 14:56:58'),(8,3,1,'admin','approve','Admin approval for invoices',0,'2026-10-28 14:56:58','2026-10-28 14:56:58');
/*!40000 ALTER TABLE `approval_workflow_steps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_workflows`
--

DROP TABLE IF EXISTS `approval_workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_workflows` (
  `workflow_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`workflow_id`),
  KEY `idx_workflows_company` (`company_id`),
  KEY `idx_workflows_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Approval workflow definitions';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_workflows`
--

LOCK TABLES `approval_workflows` WRITE;
/*!40000 ALTER TABLE `approval_workflows` DISABLE KEYS */;
INSERT INTO `approval_workflows` VALUES (1,1,'Expense Approval','Approval workflow for expenses',1,0,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(2,1,'Purchase Order Approval','Approval workflow for purchase orders',1,1,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(3,1,'Invoice Approval','Approval workflow for invoices',1,2,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(4,1,'Sales Discount Approval','Approval workflow for sales discounts',1,3,'2026-10-28 14:19:03','2026-10-28 14:19:03'),(5,1,'Expense Approval','Approval workflow for expenses',1,0,'2026-10-28 14:56:58','2026-10-28 14:56:58'),(6,1,'Purchase Order Approval','Approval workflow for purchase orders',1,1,'2026-10-28 14:56:58','2026-10-28 14:56:58'),(7,1,'Invoice Approval','Approval workflow for invoices',1,2,'2026-10-28 14:56:58','2026-10-28 14:56:58'),(8,1,'Sales Discount Approval','Approval workflow for sales discounts',1,3,'2026-10-28 14:56:58','2026-10-28 14:56:58');
/*!40000 ALTER TABLE `approval_workflows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `user_id` bigint(20) unsigned NOT NULL,
  `action` enum('CREATE','READ','UPDATE','DELETE','LOGIN','LOGOUT','EXPORT','IMPORT','PRINT') NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=606 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System audit trail';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-10-30 06:26:52'),(2,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','curl/8.16.0','2026-10-30 06:27:04'),(3,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-10-31 14:46:31'),(4,1,1,'LOGIN','users',1,NULL,'Failed login attempt 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-10-31 19:01:02'),(5,1,1,'LOGIN','users',1,NULL,'Failed login attempt 2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-10-31 19:02:12'),(6,1,1,'LOGIN','users',1,NULL,'Failed login attempt 3','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-10-31 19:03:08'),(7,1,1,'LOGIN','users',1,NULL,'Failed login attempt 4','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-10-31 19:05:44'),(8,1,1,'LOGIN','users',1,NULL,'Account locked after repeated failures','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-10-31 19:29:54'),(9,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-11-02 04:24:54'),(10,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-11-02 04:36:02'),(11,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-11-02 04:50:23'),(12,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-11-02 05:00:52'),(13,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','curl/8.16.0','2026-11-02 05:44:52'),(33,1,1,'LOGIN','users',1,NULL,'Failed login attempt 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 17:54:11'),(34,1,1,'LOGIN','users',1,NULL,'Failed login attempt 2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 17:55:14'),(35,1,1,'LOGIN','users',1,NULL,'Failed login attempt 3','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 17:56:03'),(36,1,1,'LOGIN','users',1,NULL,'Failed login attempt 4','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 17:56:16'),(37,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 17:59:10'),(38,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','curl/8.16.0','2026-11-02 18:29:21'),(39,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-11-02 18:59:12'),(42,1,1,'LOGIN','users',1,NULL,'Signed in','::1','curl/8.16.0','2026-11-02 19:52:04'),(43,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 20:00:55'),(44,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-02 20:11:27'),(45,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-03 17:37:10'),(46,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-03 22:56:43'),(47,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-03 23:20:38'),(50,1,18,'LOGIN','users',18,NULL,'Signed in','::1','curl/8.16.0','2026-11-03 23:47:04'),(51,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-03 23:50:03'),(52,1,19,'LOGIN','users',19,NULL,'Signed in','::1','curl/8.16.0','2026-11-04 00:02:52'),(53,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 04:28:02'),(54,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 04:30:08'),(55,1,18,'LOGIN','users',18,NULL,'Signed in','::1','curl/8.16.0','2026-11-04 04:32:57'),(56,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 04:34:01'),(57,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 04:35:24'),(58,1,18,'LOGIN','users',18,NULL,'Signed in','::1','curl/8.16.0','2026-11-04 04:39:53'),(59,1,18,'LOGIN','users',18,NULL,'Signed in','::1','curl/8.16.0','2026-11-04 04:41:16'),(60,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 05:17:23'),(61,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 05:35:09'),(62,1,18,'LOGIN','users',18,NULL,'Failed login attempt 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 05:46:20'),(63,1,18,'LOGIN','users',18,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/156.0.0.0 Safari/537.36','2026-11-04 05:46:22'),(64,1,1,'LOGIN','users',1,NULL,'Failed login attempt 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 18:06:07'),(65,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 18:06:24'),(66,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:09:02'),(67,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:25:14'),(68,1,19,'LOGIN','users',19,NULL,'Failed login attempt 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:26:24'),(69,1,19,'LOGIN','users',19,NULL,'Failed login attempt 2','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:26:36'),(70,1,19,'LOGIN','users',19,NULL,'Failed login attempt 3','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:27:32'),(71,1,19,'LOGIN','users',19,NULL,'Failed login attempt 4','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:27:49'),(72,1,19,'LOGIN','users',19,NULL,'Account locked after repeated failures','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:28:42'),(73,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-04 20:35:35'),(74,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 14:22:43'),(78,1,1,'UPDATE','users',0,NULL,'{\"target_user_id\":0,\"details\":\"Set 3 permissions for role \'cashier\'\"}','::1',NULL,'2026-11-05 18:56:25'),(82,1,1,'UPDATE','users',21,NULL,'{\"target_user_id\":21,\"details\":\"Updated user \'smoke_scope\' (role \'staff\', active 1)\"}','::1',NULL,'2026-11-05 19:22:49'),(83,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1','2026-11-05 19:35:57'),(88,1,1,'CREATE','users',24,NULL,'{\"target_user_id\":24,\"details\":\"Created user \'Kemi\' with role \'cashier\'\"}','::1',NULL,'2026-11-05 19:43:26'),(89,1,1,'LOGOUT','users',1,NULL,'User signed out','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 19:43:46'),(90,1,24,'LOGIN','users',24,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 19:44:10'),(95,1,24,'LOGOUT','users',24,NULL,'User signed out','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 19:53:05'),(96,1,24,'LOGIN','users',24,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 19:53:29'),(97,1,24,'LOGOUT','users',24,NULL,'User signed out','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 19:53:58'),(98,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-05 19:57:01'),(103,1,1,'EXPORT','product_barcodes',6,NULL,'{\"product_id\":6,\"details\":\"Downloaded 3 barcode SVG file(s)\"}','::1',NULL,'2026-11-05 20:26:30'),(104,1,1,'CREATE','product_barcodes',8,NULL,'{\"product_id\":8,\"details\":\"Added code 4006381333931 (ean13, gs1)\"}','::1',NULL,'2026-11-05 20:27:34'),(105,1,1,'UPDATE','product_barcodes',8,NULL,'{\"product_id\":8,\"details\":\"Primary barcode set to 2000000000084\"}','::1',NULL,'2026-11-05 20:28:23'),(106,1,1,'DELETE','product_barcodes',8,NULL,'{\"product_id\":8,\"details\":\"Removed code 4006381333931\"}','::1',NULL,'2026-11-05 20:28:25'),(165,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-06 04:32:53'),(213,1,24,'LOGIN','users',24,NULL,'Failed login attempt 1','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36 Edg/156.0.0.0','2026-11-06 05:42:53'),(214,1,24,'LOGIN','users',24,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36 Edg/156.0.0.0','2026-11-06 05:43:21'),(215,1,1,'CREATE','cashier_shifts',32,NULL,'Opened shift with float 0.00','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-06 05:45:38'),(219,1,1,'UPDATE','gateway_transactions',2,NULL,'{\"reference\":\"PAREF-20261106-075503-FA8F4D\",\"invoice_id\":49,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 06:55:03'),(221,1,1,'UPDATE','gateway_transactions',3,NULL,'{\"reference\":\"PAREF-20261106-075809-018E84\",\"invoice_id\":50,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 06:58:09'),(222,1,1,'CREATE','gateway_refunds',1,NULL,'{\"reference\":\"RFD-20261106-075809-687840\",\"payment_reference\":\"PAREF-20261106-075809-018E84\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 06:58:09'),(224,1,1,'UPDATE','gateway_transactions',7,NULL,'{\"reference\":\"PAREF-20261106-075940-0189DE\",\"invoice_id\":51,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 06:59:40'),(225,1,1,'CREATE','gateway_refunds',2,NULL,'{\"reference\":\"RFD-20261106-075940-F839C9\",\"payment_reference\":\"PAREF-20261106-075940-0189DE\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 06:59:40'),(226,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','2026-11-06 07:00:56'),(228,1,1,'UPDATE','gateway_transactions',11,NULL,'{\"reference\":\"PAREF-20261106-083743-22FF0C\",\"invoice_id\":52,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:37:43'),(229,1,1,'CREATE','gateway_refunds',3,NULL,'{\"reference\":\"RFD-20261106-083744-03277C\",\"payment_reference\":\"PAREF-20261106-083743-22FF0C\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:37:44'),(231,1,1,'UPDATE','gateway_transactions',15,NULL,'{\"reference\":\"PAREF-20261106-083744-022B29\",\"invoice_id\":53,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:37:44'),(233,1,1,'UPDATE','gateway_transactions',16,NULL,'{\"reference\":\"PAREF-20261106-083754-6FAC72\",\"invoice_id\":54,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:37:54'),(267,1,1,'UPDATE','gateway_transactions',17,NULL,'{\"reference\":\"PAREF-20261106-084243-52B7F5\",\"invoice_id\":66,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:42:43'),(268,1,1,'CREATE','gateway_refunds',4,NULL,'{\"reference\":\"RFD-20261106-084243-5AB02E\",\"payment_reference\":\"PAREF-20261106-084243-52B7F5\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:42:43'),(270,1,1,'UPDATE','gateway_transactions',21,NULL,'{\"reference\":\"PAREF-20261106-084243-82EE28\",\"invoice_id\":67,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:42:43'),(282,1,1,'UPDATE','gateway_transactions',22,NULL,'{\"reference\":\"PAREF-20261106-085000-BD7403\",\"invoice_id\":71,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:50:00'),(283,1,1,'CREATE','gateway_refunds',5,NULL,'{\"reference\":\"RFD-20261106-085001-F2D27E\",\"payment_reference\":\"PAREF-20261106-085000-BD7403\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:50:01'),(285,1,1,'UPDATE','gateway_transactions',26,NULL,'{\"reference\":\"PAREF-20261106-085001-9F660A\",\"invoice_id\":72,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 07:50:01'),(314,1,1,'UPDATE','gateway_transactions',27,NULL,'{\"reference\":\"PAREF-20261106-093002-687C5A\",\"invoice_id\":78,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:30:02'),(315,1,1,'CREATE','gateway_refunds',6,NULL,'{\"reference\":\"RFD-20261106-093002-CE4862\",\"payment_reference\":\"PAREF-20261106-093002-687C5A\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:30:02'),(317,1,1,'UPDATE','gateway_transactions',31,NULL,'{\"reference\":\"PAREF-20261106-093002-13DECE\",\"invoice_id\":79,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:30:03'),(319,1,1,'UPDATE','gateway_transactions',32,NULL,'{\"reference\":\"PAREF-20261106-093127-4C6930\",\"invoice_id\":80,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:31:27'),(320,1,1,'CREATE','gateway_refunds',7,NULL,'{\"reference\":\"RFD-20261106-093127-9270B5\",\"payment_reference\":\"PAREF-20261106-093127-4C6930\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:31:27'),(322,1,1,'UPDATE','gateway_transactions',36,NULL,'{\"reference\":\"PAREF-20261106-093127-B75E72\",\"invoice_id\":81,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:31:28'),(324,1,1,'UPDATE','gateway_transactions',37,NULL,'{\"reference\":\"PAREF-20261106-093426-F79EE2\",\"invoice_id\":82,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:34:26'),(325,1,1,'CREATE','gateway_refunds',8,NULL,'{\"reference\":\"RFD-20261106-093426-8C7385\",\"payment_reference\":\"PAREF-20261106-093426-F79EE2\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:34:27'),(327,1,1,'UPDATE','gateway_transactions',41,NULL,'{\"reference\":\"PAREF-20261106-093427-794A69\",\"invoice_id\":83,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 08:34:27'),(376,1,1,'UPDATE','gateway_transactions',58,NULL,'{\"reference\":\"PAREF-20261106-103415-002AC7\",\"invoice_id\":94,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 09:34:15'),(377,1,1,'CREATE','gateway_refunds',9,NULL,'{\"reference\":\"RFD-20261106-103416-6C90E5\",\"payment_reference\":\"PAREF-20261106-103415-002AC7\",\"amount\":500,\"gateway\":\"paystack\",\"status\":\"successful\",\"simulated\":true}','0.0.0.0','','2026-11-06 09:34:16'),(379,1,1,'UPDATE','gateway_transactions',62,NULL,'{\"reference\":\"PAREF-20261106-103416-F9CBCD\",\"invoice_id\":95,\"amount\":2000,\"gateway\":\"paystack\",\"simulated\":true}','0.0.0.0','','2026-11-06 09:34:16'),(591,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','2026-11-06 19:24:28'),(605,1,1,'LOGIN','users',1,NULL,'Signed in','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','2026-11-07 07:56:58');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_transactions`
--

DROP TABLE IF EXISTS `bank_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bank_transactions` (
  `transaction_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `bank_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `transaction_type` enum('deposit','withdrawal','transfer','fee','interest') NOT NULL,
  `transaction_number` varchar(50) NOT NULL,
  `transaction_date` date NOT NULL DEFAULT curdate(),
  `description` text DEFAULT NULL,
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `balance_after` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `reference_number` varchar(100) DEFAULT NULL,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  UNIQUE KEY `transaction_number` (`transaction_number`),
  UNIQUE KEY `uk_transaction_number` (`transaction_number`),
  KEY `idx_bank_transactions_bank` (`bank_id`),
  KEY `idx_bank_transactions_date` (`transaction_date`),
  KEY `idx_bank_transactions_type` (`transaction_type`),
  KEY `fk_bank_transactions_created_by` (`created_by`),
  CONSTRAINT `fk_bank_transactions_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`bank_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_bank_transactions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank transaction audit trail';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_transactions`
--

LOCK TABLES `bank_transactions` WRITE;
/*!40000 ALTER TABLE `bank_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `banks`
--

DROP TABLE IF EXISTS `banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `banks` (
  `bank_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `account_type` varchar(20) DEFAULT 'checking',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `balance` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`bank_id`),
  UNIQUE KEY `uk_banks_account` (`account_number`),
  KEY `idx_banks_company` (`company_id`),
  KEY `idx_banks_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank account catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banks`
--

LOCK TABLES `banks` WRITE;
/*!40000 ALTER TABLE `banks` DISABLE KEYS */;
INSERT INTO `banks` VALUES (1,1,'Checking Account','123456789','checking','USD',0.0000,1,0,'2026-10-28 14:19:33','2026-10-28 14:19:33'),(2,1,'Savings Account','987654321','savings','USD',0.0000,1,1,'2026-10-28 14:19:33','2026-10-28 14:19:33');
/*!40000 ALTER TABLE `banks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `branch_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `code` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch_id`),
  KEY `idx_branches_company` (`company_id`),
  KEY `idx_branches_active` (`is_active`),
  CONSTRAINT `fk_branches_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company branches';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,1,'Main Branch','MAIN',NULL,NULL,NULL,1,0,'2026-10-30 06:21:29','2026-10-30 06:21:29');
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashier_shifts`
--

DROP TABLE IF EXISTS `cashier_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_shifts` (
  `shift_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `opening_float` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `expected_cash` decimal(19,4) DEFAULT NULL,
  `closing_count` decimal(19,4) DEFAULT NULL,
  `variance` decimal(19,4) DEFAULT NULL,
  `opening_note` varchar(255) DEFAULT NULL,
  `closing_note` varchar(255) DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `force_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`shift_id`),
  KEY `idx_shifts_scope` (`company_id`,`branch_id`,`user_id`,`status`),
  KEY `idx_shifts_opened` (`company_id`,`opened_at`),
  KEY `fk_shifts_branch` (`branch_id`),
  KEY `fk_shifts_user` (`user_id`),
  CONSTRAINT `fk_shifts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  CONSTRAINT `fk_shifts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  CONSTRAINT `fk_shifts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_shifts`
--

LOCK TABLES `cashier_shifts` WRITE;
/*!40000 ALTER TABLE `cashier_shifts` DISABLE KEYS */;
INSERT INTO `cashier_shifts` VALUES (32,1,1,1,'open',0.0000,NULL,NULL,NULL,NULL,NULL,'2026-11-06 06:45:37',NULL,0,'2026-11-06 05:45:37','2026-11-06 05:45:37');
/*!40000 ALTER TABLE `cashier_shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  KEY `idx_categories_company` (`company_id`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product/service categories';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,1,'General',NULL,0,1,'2026-10-28 13:52:53','2026-10-28 13:52:53'),(2,1,'General',NULL,0,1,'2026-10-28 13:52:53','2026-10-28 13:52:53'),(3,1,'General',NULL,0,1,'2026-10-28 14:56:57','2026-10-28 14:56:57'),(4,1,'General',NULL,0,1,'2026-10-28 20:25:18','2026-10-28 20:25:18');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chart_of_accounts`
--

DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chart_of_accounts` (
  `account_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `uk_account_code` (`account_code`),
  KEY `idx_chart_company` (`company_id`),
  KEY `idx_chart_parent` (`parent_id`),
  CONSTRAINT `fk_chart_parent` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`account_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Chart of accounts';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chart_of_accounts`
--

LOCK TABLES `chart_of_accounts` WRITE;
/*!40000 ALTER TABLE `chart_of_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `chart_of_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `companies` (
  `company_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `tax_id` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `fiscal_year_start` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`),
  KEY `idx_companies_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (1,'Inventory Management Co.','123456789','123 Business Ave, City, State','555-0100','','USD',1,1,'2026-10-28 13:52:53','2026-11-02 06:04:10'),(2,'Inventory Management Co.','123456789','123 Business Ave, City, State','555-0100',NULL,'USD',1,1,'2026-10-28 13:52:53','2026-10-28 13:52:53'),(3,'Inventory Management Co.','123456789','123 Business Ave, City, State','555-0100',NULL,'USD',1,1,'2026-10-28 14:56:57','2026-10-28 14:56:57'),(4,'Inventory Management Co.','123456789','123 Business Ave, City, State','555-0100',NULL,'USD',1,1,'2026-10-28 20:25:18','2026-10-28 20:25:18');
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_communications`
--

DROP TABLE IF EXISTS `customer_communications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_communications` (
  `communication_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `customer_id` bigint(20) unsigned NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `communication_type` enum('email','phone','letter','sms') NOT NULL DEFAULT 'email',
  `subject` varchar(200) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `sent_date` datetime NOT NULL DEFAULT current_timestamp(),
  `read` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`communication_id`),
  KEY `idx_communications_customer` (`customer_id`),
  KEY `idx_communications_date` (`sent_date`),
  KEY `fk_communications_created_by` (`created_by`),
  CONSTRAINT `fk_communications_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_communications_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer communication history';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_communications`
--

LOCK TABLES `customer_communications` WRITE;
/*!40000 ALTER TABLE `customer_communications` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_communications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_payments`
--

DROP TABLE IF EXISTS `customer_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_payments` (
  `payment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `customer_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `shift_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tendered_amount` decimal(19,4) DEFAULT NULL COMMENT 'Cash or tender handed over for this payment row; NULL when unknown. amount stays the sum applied to the bill',
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'bank_transfer',
  `reference_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `idx_payments_customer` (`customer_id`),
  KEY `idx_payments_invoice` (`invoice_id`),
  KEY `idx_payments_shift` (`shift_id`),
  CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`invoice_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=206 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer payments received';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_payments`
--

LOCK TABLES `customer_payments` WRITE;
/*!40000 ALTER TABLE `customer_payments` DISABLE KEYS */;
INSERT INTO `customer_payments` VALUES (52,1,11,48,NULL,30.0000,NULL,'2026-11-06','cash','','','2026-11-06 05:59:55','2026-11-06 05:59:55');
/*!40000 ALTER TABLE `customer_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_statements`
--

DROP TABLE IF EXISTS `customer_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_statements` (
  `statement_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `customer_id` bigint(20) unsigned NOT NULL,
  `statement_date` date NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `opening_balance` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `sales_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `payments_received` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `ending_balance` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `status` enum('draft','sent') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`statement_id`),
  KEY `idx_statements_customer` (`customer_id`,`statement_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_statements`
--

LOCK TABLES `customer_statements` WRITE;
/*!40000 ALTER TABLE `customer_statements` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_statements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `customer_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `tax_id` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(80) DEFAULT NULL,
  `credit_limit` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `credit_used` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `payment_terms` varchar(40) NOT NULL DEFAULT 'Net 30',
  `is_tax_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `payment_status` varchar(20) NOT NULL DEFAULT 'current',
  PRIMARY KEY (`customer_id`),
  KEY `idx_customers_company` (`company_id`),
  KEY `idx_customers_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (11,1,'Walk-in Customer','','','',1,'2026-11-04 04:33:24','2026-11-07 07:52:21','','','','','',0.0000,0.0000,'Due on Receipt',0,'','current');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dashboard_config`
--

DROP TABLE IF EXISTS `dashboard_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dashboard_config` (
  `config_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `user_id` bigint(20) unsigned NOT NULL,
  `layout_config` text DEFAULT NULL,
  `widget_order` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`config_id`),
  KEY `idx_config_user` (`user_id`),
  KEY `idx_config_company` (`company_id`),
  CONSTRAINT `fk_config_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dashboard user configurations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dashboard_config`
--

LOCK TABLES `dashboard_config` WRITE;
/*!40000 ALTER TABLE `dashboard_config` DISABLE KEYS */;
/*!40000 ALTER TABLE `dashboard_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dashboard_widgets`
--

DROP TABLE IF EXISTS `dashboard_widgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dashboard_widgets` (
  `widget_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `widget_name` varchar(80) NOT NULL,
  `widget_type` enum('summary','chart','table','gauge','metric') NOT NULL DEFAULT 'summary',
  `config_data` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`widget_id`),
  KEY `idx_widgets_company` (`company_id`),
  KEY `idx_widgets_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dashboard widgets';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dashboard_widgets`
--

LOCK TABLES `dashboard_widgets` WRITE;
/*!40000 ALTER TABLE `dashboard_widgets` DISABLE KEYS */;
/*!40000 ALTER TABLE `dashboard_widgets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `department_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `manager_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(30) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`department_id`),
  KEY `idx_departments_company` (`company_id`),
  KEY `idx_departments_branch` (`branch_id`),
  KEY `idx_departments_manager` (`manager_id`),
  CONSTRAINT `fk_departments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company departments';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `employee_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `employee_code` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `employment_type` enum('full_time','part_time','contract') NOT NULL DEFAULT 'full_time',
  `hourly_rate` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `salary` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_id` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `employee_code` (`employee_code`),
  UNIQUE KEY `uk_employees_code` (`employee_code`),
  KEY `idx_employees_company` (`company_id`),
  KEY `idx_employees_active` (`is_active`),
  KEY `fk_employees_user` (`user_id`),
  CONSTRAINT `fk_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Employee catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_categories` (
  `category_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  KEY `idx_expense_categories_company` (`company_id`),
  KEY `idx_expense_categories_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
INSERT INTO `expense_categories` VALUES (3,1,'Qoder Test Category','QTC','',0,0,'2026-11-04 00:01:04','2026-11-04 00:01:04');
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `expense_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `vendor_id` bigint(20) unsigned DEFAULT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `expense_number` varchar(50) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `description` text NOT NULL,
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `category` varchar(50) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `receipt_status` varchar(20) NOT NULL DEFAULT 'none',
  `payment_status` varchar(20) NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`expense_id`),
  KEY `idx_expenses_vendor` (`vendor_id`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_active` (`is_active`),
  KEY `idx_expenses_category` (`category_id`),
  KEY `idx_expenses_employee` (`employee_id`),
  KEY `idx_expenses_status` (`payment_status`),
  KEY `idx_expenses_number` (`expense_number`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Expenses tracking';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_reports`
--

DROP TABLE IF EXISTS `financial_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_reports` (
  `report_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `report_name` varchar(100) NOT NULL,
  `report_type` enum('income_statement','balance_sheet','cash_flow','trial_balance','general_ledger','sales_analytics') NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `generated_by` bigint(20) unsigned NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','generated','exported') NOT NULL DEFAULT 'draft',
  `description` text DEFAULT NULL,
  PRIMARY KEY (`report_id`),
  KEY `idx_reports_company` (`company_id`),
  KEY `idx_reports_type` (`report_type`),
  KEY `idx_reports_period` (`period_start`,`period_end`),
  KEY `fk_reports_generated_by` (`generated_by`),
  CONSTRAINT `fk_reports_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Financial reports catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `financial_reports`
--

LOCK TABLES `financial_reports` WRITE;
/*!40000 ALTER TABLE `financial_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_events`
--

DROP TABLE IF EXISTS `gateway_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_events` (
  `log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `gateway` enum('paystack','flutterwave') NOT NULL,
  `provider_event_id` varchar(80) DEFAULT NULL,
  `dedupe_key` char(64) NOT NULL,
  `event_type` varchar(60) NOT NULL DEFAULT '',
  `transaction_ref` varchar(64) DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  `result` varchar(255) DEFAULT NULL,
  `payload` text DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  UNIQUE KEY `uq_gateway_event_dedupe` (`dedupe_key`),
  KEY `idx_gateway_events_company` (`company_id`,`gateway`,`received_at`),
  CONSTRAINT `fk_gateway_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_events`
--

LOCK TABLES `gateway_events` WRITE;
/*!40000 ALTER TABLE `gateway_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `gateway_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_refunds`
--

DROP TABLE IF EXISTS `gateway_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_refunds` (
  `refund_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `gateway` enum('paystack','flutterwave') NOT NULL,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `refund_reference` varchar(64) NOT NULL,
  `provider_refund_reference` varchar(128) DEFAULT NULL,
  `amount` decimal(19,4) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'NGN',
  `status` enum('pending','successful','failed') NOT NULL DEFAULT 'pending',
  `reason` varchar(255) DEFAULT NULL,
  `simulates_payment` tinyint(1) NOT NULL DEFAULT 0,
  `provider_response` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`refund_id`),
  UNIQUE KEY `uq_gateway_refund_reference` (`company_id`,`refund_reference`),
  KEY `idx_gateway_refunds_txn` (`transaction_id`,`status`),
  KEY `fk_gateway_refunds_user` (`created_by`),
  CONSTRAINT `fk_gateway_refunds_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  CONSTRAINT `fk_gateway_refunds_txn` FOREIGN KEY (`transaction_id`) REFERENCES `gateway_transactions` (`transaction_id`),
  CONSTRAINT `fk_gateway_refunds_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_refunds`
--

LOCK TABLES `gateway_refunds` WRITE;
/*!40000 ALTER TABLE `gateway_refunds` DISABLE KEYS */;
/*!40000 ALTER TABLE `gateway_refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_transactions`
--

DROP TABLE IF EXISTS `gateway_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_transactions` (
  `transaction_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `gateway` enum('paystack','flutterwave') NOT NULL,
  `mode` enum('test','live','sandbox') NOT NULL DEFAULT 'test',
  `reference` varchar(64) NOT NULL,
  `gateway_reference` varchar(128) DEFAULT NULL,
  `provider_id` varchar(64) DEFAULT NULL,
  `checkout_url` varchar(255) DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `shift_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `customer_email` varchar(120) DEFAULT NULL,
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `currency` char(3) NOT NULL DEFAULT 'NGN',
  `status` enum('pending','paid','failed','expired','refunded','partial_refund') NOT NULL DEFAULT 'pending',
  `channel` varchar(40) DEFAULT NULL,
  `authorization_code` varchar(100) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `simulates_payment` tinyint(1) NOT NULL DEFAULT 0,
  `payload` text DEFAULT NULL,
  `provider_response` text DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `finalised_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  UNIQUE KEY `uq_gateway_reference` (`company_id`,`reference`),
  KEY `idx_gateway_txn_status` (`company_id`,`status`,`created_at`),
  KEY `idx_gateway_txn_gateway_ref` (`gateway`,`gateway_reference`),
  KEY `idx_gateway_txn_invoice` (`invoice_id`),
  KEY `fk_gateway_txn_branch` (`branch_id`),
  KEY `fk_gateway_txn_shift` (`shift_id`),
  KEY `fk_gateway_txn_user` (`user_id`),
  CONSTRAINT `fk_gateway_txn_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gateway_txn_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  CONSTRAINT `fk_gateway_txn_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`invoice_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gateway_txn_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gateway_txn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_transactions`
--

LOCK TABLES `gateway_transactions` WRITE;
/*!40000 ALTER TABLE `gateway_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `gateway_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `income_records`
--

DROP TABLE IF EXISTS `income_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `income_records` (
  `income_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_number` varchar(50) DEFAULT NULL,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `source` varchar(100) NOT NULL,
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `payment_status` varchar(20) NOT NULL DEFAULT 'pending',
  `gross_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `net_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sales_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `income_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `recognized` tinyint(1) NOT NULL DEFAULT 0,
  `recognition_date` date DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`income_id`),
  KEY `idx_income_company` (`company_id`),
  KEY `idx_income_date` (`income_date`),
  KEY `idx_income_source` (`source`),
  KEY `idx_income_status` (`payment_status`),
  KEY `idx_income_customer` (`customer_id`),
  KEY `idx_income_source_id` (`source_id`),
  KEY `idx_income_number` (`transaction_number`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Income recording';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `income_records`
--

LOCK TABLES `income_records` WRITE;
/*!40000 ALTER TABLE `income_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `income_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `income_sources`
--

DROP TABLE IF EXISTS `income_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `income_sources` (
  `income_source_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`income_source_id`),
  KEY `idx_income_sources_company` (`company_id`),
  KEY `idx_income_sources_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `income_sources`
--

LOCK TABLES `income_sources` WRITE;
/*!40000 ALTER TABLE `income_sources` DISABLE KEYS */;
/*!40000 ALTER TABLE `income_sources` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transactions`
--

DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_transactions` (
  `transaction_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `product_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `transaction_type` enum('receiving','sale','adjustment','transfer_in','transfer_out') NOT NULL,
  `quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `total_value` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `idx_invtx_product` (`product_id`),
  KEY `idx_invtx_branch` (`branch_id`),
  KEY `idx_invtx_type` (`transaction_type`),
  KEY `idx_invtx_date` (`created_at`),
  KEY `idx_invtx_reference` (`reference_type`,`reference_id`),
  KEY `fk_invtx_user` (`user_id`),
  CONSTRAINT `fk_invtx_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_invtx_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_invtx_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=554 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stock movement ledger';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
INSERT INTO `inventory_transactions` VALUES (63,1,8,1,'adjustment',21.0000,40.0000,840.0000,NULL,NULL,1,'','2026-11-06 05:56:44'),(64,1,8,1,'sale',6.0000,40.0000,240.0000,'sales_invoice',48,24,'Sale INV-20261106-3811','2026-11-06 05:59:55');
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_receipts`
--

DROP TABLE IF EXISTS `invoice_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_receipts` (
  `invoice_receipt_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `purchase_order_id` bigint(20) unsigned DEFAULT NULL,
  `vendor_id` bigint(20) unsigned NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `receipt_date` date DEFAULT NULL,
  `status` enum('matching','full_match','partial_match','mismatch','paid') NOT NULL DEFAULT 'matching',
  `invoice_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `receipt_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `invoiced_quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `received_quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `quantity_variance` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `amount_due` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `paid` tinyint(1) NOT NULL DEFAULT 0,
  `payment_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`invoice_receipt_id`),
  KEY `idx_invoice_receipts_company` (`company_id`),
  KEY `idx_invoice_receipts_po` (`purchase_order_id`),
  KEY `idx_invoice_receipts_vendor` (`vendor_id`),
  KEY `idx_invoice_receipts_status` (`status`),
  CONSTRAINT `fk_invoice_receipts_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`purchase_order_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invoice_receipts_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_receipts`
--

LOCK TABLES `invoice_receipts` WRITE;
/*!40000 ALTER TABLE `invoice_receipts` DISABLE KEYS */;
INSERT INTO `invoice_receipts` VALUES (3,1,3,4,'12121212121212','1123','2026-11-04','2026-11-04','paid',25010.0000,25010.0000,100.0000,100.0000,0.0000,25010.0000,1,'2026-11-05','',1,'2026-11-04 20:12:47','2026-11-05 19:49:32');
/*!40000 ALTER TABLE `invoice_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entries`
--

DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entries` (
  `entry_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `entry_number` varchar(50) NOT NULL,
  `entry_date` date NOT NULL DEFAULT curdate(),
  `description` text DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `status` enum('draft','posted') NOT NULL DEFAULT 'draft',
  `posted_date` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`entry_id`),
  UNIQUE KEY `entry_number` (`entry_number`),
  KEY `idx_journals_company` (`company_id`),
  KEY `idx_journals_date` (`entry_date`),
  KEY `idx_journals_status` (`status`),
  KEY `fk_journals_created_by` (`created_by`),
  CONSTRAINT `fk_journals_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Journal entries';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entries`
--

LOCK TABLES `journal_entries` WRITE;
/*!40000 ALTER TABLE `journal_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entry_lines`
--

DROP TABLE IF EXISTS `journal_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entry_lines` (
  `line_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `debit_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `credit_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `description` text DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`line_id`),
  KEY `idx_lines_entry` (`entry_id`),
  KEY `idx_lines_account` (`account_id`),
  CONSTRAINT `fk_lines_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`account_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_lines_entry` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`entry_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Journal entry lines';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entry_lines`
--

LOCK TABLES `journal_entry_lines` WRITE;
/*!40000 ALTER TABLE `journal_entry_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_entry_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pay_rates`
--

DROP TABLE IF EXISTS `pay_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pay_rates` (
  `pay_rate_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `rate_type` enum('hourly','salary','commission') NOT NULL DEFAULT 'hourly',
  `rate_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `effective_date` date NOT NULL DEFAULT curdate(),
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pay_rate_id`),
  KEY `idx_payrates_employee` (`employee_id`),
  CONSTRAINT `fk_payrates_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Employee pay rates history';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pay_rates`
--

LOCK TABLES `pay_rates` WRITE;
/*!40000 ALTER TABLE `pay_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `pay_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_gateways`
--

DROP TABLE IF EXISTS `payment_gateways`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_gateways` (
  `gateway_config_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `gateway` enum('paystack','flutterwave') NOT NULL,
  `mode` enum('test','live') NOT NULL DEFAULT 'test',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sandbox_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `test_public_key` varchar(1000) DEFAULT NULL,
  `test_secret_key` varchar(1000) DEFAULT NULL,
  `test_webhook_secret` varchar(1000) DEFAULT NULL,
  `live_public_key` varchar(1000) DEFAULT NULL,
  `live_secret_key` varchar(1000) DEFAULT NULL,
  `live_webhook_secret` varchar(1000) DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `last_check_result` varchar(255) DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`gateway_config_id`),
  UNIQUE KEY `uq_gateway_company` (`company_id`,`gateway`),
  KEY `fk_gateway_user` (`updated_by`),
  CONSTRAINT `fk_gateway_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  CONSTRAINT `fk_gateway_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_gateways`
--

LOCK TABLES `payment_gateways` WRITE;
/*!40000 ALTER TABLE `payment_gateways` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_gateways` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_made`
--

DROP TABLE IF EXISTS `payment_made`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_made` (
  `payment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `vendor_id` bigint(20) unsigned NOT NULL,
  `purchase_order_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `payment_date` date NOT NULL DEFAULT curdate(),
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `payment_method` varchar(50) NOT NULL DEFAULT 'bank_transfer',
  `reference_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `idx_payments_vendor` (`vendor_id`),
  KEY `idx_payments_po` (`purchase_order_id`),
  KEY `idx_payments_date` (`payment_date`),
  KEY `idx_payment_made_ir` (`invoice_receipt_id`),
  CONSTRAINT `fk_payment_made_ir` FOREIGN KEY (`invoice_receipt_id`) REFERENCES `invoice_receipts` (`invoice_receipt_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`purchase_order_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payments made to vendors';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_made`
--

LOCK TABLES `payment_made` WRITE;
/*!40000 ALTER TABLE `payment_made` DISABLE KEYS */;
INSERT INTO `payment_made` VALUES (5,1,4,3,3,'2026-11-05',500000.0000,'bank_transfer','','',24,'2026-11-05 19:49:32','2026-11-05 19:49:32');
/*!40000 ALTER TABLE `payment_made` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `payment_method_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `is_cash` tinyint(1) NOT NULL DEFAULT 0,
  `is_online` tinyint(1) NOT NULL DEFAULT 0,
  `gateway` enum('paystack','flutterwave') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_method_id`),
  UNIQUE KEY `uq_payment_methods_company_code` (`company_id`,`code`),
  KEY `idx_payment_methods_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_methods`
--

LOCK TABLES `payment_methods` WRITE;
/*!40000 ALTER TABLE `payment_methods` DISABLE KEYS */;
INSERT INTO `payment_methods` VALUES (1,1,'cash','Cash',NULL,1,0,NULL,1,10,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(2,1,'bank_transfer','Bank Transfer',NULL,0,0,NULL,1,20,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(3,1,'credit_card','Card (POS terminal)',NULL,0,0,NULL,1,30,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(4,1,'check','Cheque',NULL,0,0,NULL,1,40,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(5,1,'credit_sale','Credit Sale (on account)',NULL,0,0,NULL,1,50,'2026-11-05 15:56:26','2026-11-05 15:56:26');
/*!40000 ALTER TABLE `payment_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_run_lines`
--

DROP TABLE IF EXISTS `payroll_run_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_run_lines` (
  `line_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `description` varchar(100) NOT NULL,
  `type` enum('earning','deduction','tax') NOT NULL DEFAULT 'earning',
  `amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`line_id`),
  KEY `idx_runlines_run` (`run_id`),
  KEY `idx_runlines_employee` (`employee_id`),
  CONSTRAINT `fk_runlines_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_runlines_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`run_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payroll run line items';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_run_lines`
--

LOCK TABLES `payroll_run_lines` WRITE;
/*!40000 ALTER TABLE `payroll_run_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_run_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_runs`
--

DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `run_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `run_date` date NOT NULL DEFAULT curdate(),
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('draft','processed','reversed') NOT NULL DEFAULT 'draft',
  `gross_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `net_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`run_id`),
  KEY `idx_payrolls_company` (`company_id`),
  KEY `idx_payrolls_status` (`status`),
  KEY `fk_payrolls_created_by` (`created_by`),
  CONSTRAINT `fk_payrolls_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payroll runs';
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
  `permission_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Permission catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'dashboard.view','View the dashboard'),(2,'inventory.view','View products and stock'),(3,'inventory.create','Create products and stock records'),(4,'inventory.update','Edit products and record stock movements'),(5,'inventory.delete','Deactivate products'),(6,'sales.view','View customers, quotes, sales and POS'),(7,'sales.create','Create customers, quotes, sales and POS checkouts'),(8,'sales.update','Edit quotes and record sales payments'),(9,'sales.delete','Cancel sales and delete quotes'),(10,'receivables.view','View customer receivables and statements'),(11,'receivables.create','Create statements and customer communications'),(12,'receivables.update','Edit customer credit terms'),(13,'receivables.delete','Deactivate customers'),(14,'payables.view','View suppliers, purchase orders and invoice receipts'),(15,'payables.create','Create suppliers, purchase orders and invoice receipts'),(16,'payables.update','Edit suppliers, purchase orders and invoice receipts'),(17,'payables.delete','Deactivate suppliers'),(18,'expenses.view','View expense categories, expenses and summaries'),(19,'expenses.create','Create expense categories and expenses'),(20,'expenses.update','Edit expenses and payment status'),(21,'expenses.delete','Deactivate categories and cancel expenses'),(22,'income.view','View income sources and records'),(23,'income.create','Create income sources and records'),(24,'income.update','Edit and recognize income records'),(25,'income.delete','Deactivate income sources and cancel records'),(26,'payments.view','View payments received and made'),(27,'payments.create','Record payments received and made'),(28,'payments.delete','Reverse recorded payments'),(29,'payroll.view','View employees and payroll runs'),(30,'payroll.create','Create employees, runs and payroll lines'),(31,'payroll.update','Edit employees, runs and payroll lines'),(32,'payroll.delete','Remove payroll lines'),(33,'payroll.approve','Process and reverse payroll runs'),(34,'tax.view','View tax rates, transactions and summaries'),(35,'tax.create','Create tax rates and transactions'),(36,'tax.update','Edit tax rates and reconciliation status'),(37,'tax.delete','Delete tax rates and transactions'),(38,'ledger.view','View chart of accounts, journal entries and trial balance'),(39,'ledger.create','Create accounts and journal entries'),(40,'ledger.update','Post journal entries'),(41,'ledger.delete','Delete or reverse journal entries'),(42,'reports.view','View financial reports'),(43,'reports.create','Generate financial reports'),(44,'reports.update','Regenerate financial reports'),(45,'reports.delete','Delete financial reports'),(46,'reports.export','Export financial reports'),(47,'approvals.view','View approval workflows and requests'),(48,'approvals.create','Create workflows and submit approval requests'),(49,'approvals.update','Edit or toggle approval workflows'),(50,'approvals.approve','Approve or reject requests'),(51,'audit.view','View the audit log'),(52,'company.view','View companies, branches and departments'),(53,'company.create','Create companies, branches and departments'),(54,'company.update','Edit companies, branches and departments'),(55,'company.delete','Deactivate branches and departments'),(56,'settings.view','View system settings'),(57,'settings.update','Create and update system settings'),(58,'settings.delete','Delete system settings'),(59,'bank.view','View bank accounts and transactions'),(60,'bank.create','Create bank accounts and record transactions'),(61,'bank.update','Edit bank accounts'),(62,'bank.delete','Deactivate bank accounts'),(63,'users.view','View users and their role/branch assignments'),(64,'users.create','Create user accounts'),(65,'users.update','Edit users, roles, branch access and permission overrides'),(66,'users.delete','Deactivate user accounts'),(67,'users.reset_password','Reset another user\'s password'),(68,'roles.view','View roles and permission grants'),(69,'roles.update','Create roles and change role permission grants'),(70,'payment_methods.view','View configured payment methods'),(71,'payment_methods.update','Create, edit and deactivate payment methods'),(72,'shifts.view','View cashier shifts and reconciliation reports'),(73,'shifts.create','Open a cashier shift'),(74,'shifts.update','Record drawer movements and close a shift'),(75,'shifts.delete','Void a shift that has no activity'),(76,'gateways.view','View payment gateway configuration, transactions and reconciliation'),(77,'gateways.update','Configure gateway credentials, mode and enablement'),(78,'gateways.verify','Re-verify a payment against the gateway and run reconciliation'),(79,'gateways.refund','Issue gateway refunds');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_barcodes`
--

DROP TABLE IF EXISTS `product_barcodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_barcodes` (
  `barcode_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(40) NOT NULL,
  `symbology` enum('ean13','ean8','upc_a','code128','qr','other') NOT NULL DEFAULT 'code128',
  `source` enum('system','gs1','manual','import') NOT NULL DEFAULT 'system',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`barcode_id`),
  UNIQUE KEY `uq_product_barcodes_code_company` (`code`,`company_id`),
  KEY `idx_product_barcodes_product` (`product_id`),
  KEY `fk_pb_company` (`company_id`),
  CONSTRAINT `fk_pb_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  CONSTRAINT `fk_pb_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_barcodes`
--

LOCK TABLES `product_barcodes` WRITE;
/*!40000 ALTER TABLE `product_barcodes` DISABLE KEYS */;
INSERT INTO `product_barcodes` VALUES (1,1,1,'2000000000015','ean13','system',1,'2026-11-05 19:36:43','2026-11-05 19:36:43'),(2,2,1,'2000000000022','ean13','system',1,'2026-11-05 19:36:43','2026-11-05 19:36:43'),(3,6,1,'2000000000060','ean13','system',1,'2026-11-05 19:36:43','2026-11-05 19:36:43'),(4,7,1,'2000000000077','ean13','system',1,'2026-11-05 19:36:43','2026-11-05 19:36:43'),(5,8,1,'2000000000084','ean13','system',1,'2026-11-05 19:36:43','2026-11-06 05:51:15');
/*!40000 ALTER TABLE `product_barcodes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_locations`
--

DROP TABLE IF EXISTS `product_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_locations` (
  `location_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `product_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `on_hand_quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `reserved_quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `available_quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `on_hand_value` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `last_counted` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uk_product_location` (`product_id`,`branch_id`),
  KEY `idx_pl_branch` (`branch_id`),
  KEY `idx_pl_company` (`company_id`),
  CONSTRAINT `fk_pl_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pl_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=165 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-branch stock levels';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_locations`
--

LOCK TABLES `product_locations` WRITE;
/*!40000 ALTER TABLE `product_locations` DISABLE KEYS */;
INSERT INTO `product_locations` VALUES (5,1,6,1,0.0000,0.0000,0.0000,0.0000,'2026-11-04 00:47:25','2026-11-03 23:47:25','2026-11-03 23:47:25'),(6,1,7,1,0.0000,0.0000,0.0000,0.0000,'2026-11-04 00:52:37','2026-11-03 23:52:37','2026-11-03 23:52:37'),(7,1,8,1,15.0000,0.0000,15.0000,600.0000,'2026-11-06 06:59:55','2026-11-04 00:03:06','2026-11-06 05:59:55'),(9,1,2,1,0.0000,0.0000,0.0000,0.0000,'2026-11-04 05:28:54','2026-11-04 04:28:54','2026-11-04 04:28:54');
/*!40000 ALTER TABLE `product_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `category_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `sku` varchar(50) NOT NULL,
  `barcode` varchar(40) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `track_inventory` tinyint(1) NOT NULL DEFAULT 1,
  `manage_stock` tinyint(1) NOT NULL DEFAULT 1,
  `sales_price` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `cost_price` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `reorder_level` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `maximum_level` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `target_level` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `sku` (`sku`),
  UNIQUE KEY `uk_products_sku` (`sku`),
  UNIQUE KEY `uq_products_barcode` (`barcode`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_active` (`is_active`),
  KEY `idx_products_sku` (`sku`),
  KEY `fk_products_company` (`company_id`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_products_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=165 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,1,1,'WID-001','2000000000015','Widget Standard','Standard widget for general use',1,1,99.9000,45.0000,10.0000,100.0000,50.0000,1,0,'2026-10-28 13:54:09','2026-11-05 19:36:43'),(2,1,1,'WID-002','2000000000022','Widget Premium','Premium widget with extended features',1,1,149.9000,85.0000,10.0000,50.0000,25.0000,1,1,'2026-10-28 13:54:09','2026-11-05 19:36:43'),(6,1,1,'QDT-001','2000000000060','UI Edited Product','test',1,1,12.5000,9.2500,3.0000,999.0000,50.0000,1,0,'2026-11-03 23:47:25','2026-11-05 19:36:43'),(7,1,1,'QDU-49956851','2000000000077','UI Test Product','',1,1,15.0000,10.0000,0.0000,999999.0000,100.0000,1,0,'2026-11-03 23:52:37','2026-11-05 19:36:43'),(8,1,1,'STF-001','2000000000084','Staff Product','',1,1,5.0000,4.0000,15.0000,99.0000,10.0000,1,0,'2026-11-04 00:03:06','2026-11-06 05:51:14');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(19,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `received_quantity` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `total_price` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `total_cost` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `idx_poi_order` (`purchase_order_id`),
  KEY `idx_poi_product` (`product_id`),
  CONSTRAINT `fk_poi_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`purchase_order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Purchase order line items';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
INSERT INTO `purchase_order_items` VALUES (2,3,8,'Staff Product (STF-001)',100.0000,250.0000,320.0000,0.0000,0.0000,0.0000,25000.0000,25000.0000,32000.0000,'2026-11-04 20:11:29');
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `purchase_order_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `vendor_id` bigint(20) unsigned NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `required_date` date DEFAULT NULL,
  `total_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `amount_owed` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `amount_received` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `status` enum('draft','sent','pending','partial','partial_received','received','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`purchase_order_id`),
  KEY `idx_purchase_orders_vendor` (`vendor_id`),
  KEY `idx_purchase_orders_status` (`status`),
  KEY `idx_purchase_orders_company` (`company_id`),
  KEY `idx_purchase_orders_branch` (`branch_id`),
  KEY `idx_purchase_orders_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Purchase orders';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (3,1,1,4,'PO-20261104-4178','2026-11-04',NULL,'2026-11-13',25007.4990,25000.0000,0.0100,2.5000,0.0004,9.9990,0.0000,500000.0000,'received','',1,1,'2026-11-04 21:13:18','2026-11-04 20:10:17','2026-11-05 19:49:32');
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quote_lines`
--

DROP TABLE IF EXISTS `quote_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quote_lines` (
  `line_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quote_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(19,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`line_id`),
  KEY `idx_quote_lines_quote` (`quote_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quote_lines`
--

LOCK TABLES `quote_lines` WRITE;
/*!40000 ALTER TABLE `quote_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `quote_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotes`
--

DROP TABLE IF EXISTS `quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quotes` (
  `quote_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `customer_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `quote_number` varchar(50) NOT NULL,
  `status` enum('draft','sent','accepted','rejected','expired','converted') NOT NULL DEFAULT 'draft',
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `subtotal` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `notes` text DEFAULT NULL,
  `converted_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`quote_id`),
  UNIQUE KEY `uk_quotes_number` (`quote_number`),
  KEY `idx_quotes_company_status` (`company_id`,`status`),
  KEY `idx_quotes_customer` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotes`
--

LOCK TABLES `quotes` WRITE;
/*!40000 ALTER TABLE `quotes` DISABLE KEYS */;
/*!40000 ALTER TABLE `quotes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_columns`
--

DROP TABLE IF EXISTS `report_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_columns` (
  `column_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` bigint(20) unsigned NOT NULL,
  `column_name` varchar(100) NOT NULL,
  `data_source` varchar(100) NOT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `format_type` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`column_id`),
  KEY `idx_columns_report` (`report_id`),
  CONSTRAINT `fk_columns_report` FOREIGN KEY (`report_id`) REFERENCES `financial_reports` (`report_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Financial report columns';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_columns`
--

LOCK TABLES `report_columns` WRITE;
/*!40000 ALTER TABLE `report_columns` DISABLE KEYS */;
/*!40000 ALTER TABLE `report_columns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `permission` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uk_role_permission` (`role`,`permission`)
) ENGINE=InnoDB AUTO_INCREMENT=349 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Role-permission mappings';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (147,'accountant','bank.create'),(148,'accountant','bank.update'),(149,'accountant','bank.view'),(150,'accountant','dashboard.view'),(151,'accountant','expenses.view'),(346,'accountant','gateways.refund'),(345,'accountant','gateways.verify'),(344,'accountant','gateways.view'),(152,'accountant','income.view'),(153,'accountant','ledger.create'),(154,'accountant','ledger.update'),(155,'accountant','ledger.view'),(156,'accountant','payment_methods.view'),(157,'accountant','payments.create'),(158,'accountant','payments.view'),(159,'accountant','payroll.view'),(160,'accountant','reports.create'),(161,'accountant','reports.export'),(162,'accountant','reports.view'),(335,'accountant','shifts.view'),(163,'accountant','tax.create'),(164,'accountant','tax.update'),(165,'accountant','tax.view'),(193,'approver','approvals.approve'),(194,'approver','approvals.view'),(195,'approver','dashboard.view'),(196,'approver','expenses.view'),(347,'approver','gateways.view'),(197,'approver','inventory.view'),(198,'approver','payables.view'),(199,'approver','payment_methods.view'),(200,'approver','sales.view'),(336,'approver','shifts.view'),(208,'auditor','approvals.view'),(209,'auditor','audit.view'),(210,'auditor','bank.view'),(211,'auditor','company.view'),(212,'auditor','dashboard.view'),(213,'auditor','expenses.view'),(348,'auditor','gateways.view'),(214,'auditor','income.view'),(215,'auditor','inventory.view'),(216,'auditor','ledger.view'),(217,'auditor','payables.view'),(218,'auditor','payment_methods.view'),(219,'auditor','payments.view'),(220,'auditor','payroll.view'),(221,'auditor','receivables.view'),(222,'auditor','reports.export'),(223,'auditor','reports.view'),(224,'auditor','roles.view'),(225,'auditor','sales.view'),(226,'auditor','settings.view'),(334,'auditor','shifts.view'),(227,'auditor','tax.view'),(228,'auditor','users.view'),(309,'cashier','dashboard.view'),(310,'cashier','inventory.view'),(311,'cashier','payment_methods.view'),(312,'cashier','payments.create'),(313,'cashier','payments.view'),(314,'cashier','receivables.view'),(308,'cashier','sales.create'),(307,'cashier','sales.view'),(318,'cashier','shifts.create'),(319,'cashier','shifts.update'),(317,'cashier','shifts.view'),(117,'inventory_manager','dashboard.view'),(118,'inventory_manager','inventory.create'),(119,'inventory_manager','inventory.delete'),(120,'inventory_manager','inventory.update'),(121,'inventory_manager','inventory.view'),(122,'inventory_manager','payables.create'),(123,'inventory_manager','payables.update'),(124,'inventory_manager','payables.view'),(125,'inventory_manager','payment_methods.view'),(126,'inventory_manager','reports.view'),(50,'manager','approvals.approve'),(48,'manager','approvals.create'),(49,'manager','approvals.update'),(47,'manager','approvals.view'),(51,'manager','audit.view'),(56,'manager','bank.create'),(58,'manager','bank.delete'),(57,'manager','bank.update'),(55,'manager','bank.view'),(53,'manager','company.update'),(52,'manager','company.view'),(1,'manager','dashboard.view'),(19,'manager','expenses.create'),(21,'manager','expenses.delete'),(20,'manager','expenses.update'),(18,'manager','expenses.view'),(343,'manager','gateways.refund'),(342,'manager','gateways.verify'),(341,'manager','gateways.view'),(23,'manager','income.create'),(25,'manager','income.delete'),(24,'manager','income.update'),(22,'manager','income.view'),(3,'manager','inventory.create'),(5,'manager','inventory.delete'),(4,'manager','inventory.update'),(2,'manager','inventory.view'),(39,'manager','ledger.create'),(41,'manager','ledger.delete'),(40,'manager','ledger.update'),(38,'manager','ledger.view'),(15,'manager','payables.create'),(17,'manager','payables.delete'),(16,'manager','payables.update'),(14,'manager','payables.view'),(79,'manager','payment_methods.view'),(27,'manager','payments.create'),(28,'manager','payments.delete'),(26,'manager','payments.view'),(33,'manager','payroll.approve'),(30,'manager','payroll.create'),(32,'manager','payroll.delete'),(31,'manager','payroll.update'),(29,'manager','payroll.view'),(11,'manager','receivables.create'),(13,'manager','receivables.delete'),(12,'manager','receivables.update'),(10,'manager','receivables.view'),(43,'manager','reports.create'),(45,'manager','reports.delete'),(46,'manager','reports.export'),(44,'manager','reports.update'),(42,'manager','reports.view'),(80,'manager','roles.view'),(7,'manager','sales.create'),(9,'manager','sales.delete'),(8,'manager','sales.update'),(6,'manager','sales.view'),(54,'manager','settings.view'),(324,'manager','shifts.create'),(326,'manager','shifts.delete'),(325,'manager','shifts.update'),(323,'manager','shifts.view'),(35,'manager','tax.create'),(37,'manager','tax.delete'),(36,'manager','tax.update'),(34,'manager','tax.view'),(81,'manager','users.create'),(82,'manager','users.update'),(83,'manager','users.view'),(239,'owner','approvals.approve'),(240,'owner','approvals.create'),(241,'owner','approvals.update'),(242,'owner','approvals.view'),(243,'owner','audit.view'),(244,'owner','bank.create'),(245,'owner','bank.delete'),(246,'owner','bank.update'),(247,'owner','bank.view'),(248,'owner','company.create'),(249,'owner','company.delete'),(250,'owner','company.update'),(251,'owner','company.view'),(252,'owner','dashboard.view'),(253,'owner','expenses.create'),(254,'owner','expenses.delete'),(255,'owner','expenses.update'),(256,'owner','expenses.view'),(340,'owner','gateways.refund'),(338,'owner','gateways.update'),(339,'owner','gateways.verify'),(337,'owner','gateways.view'),(257,'owner','income.create'),(258,'owner','income.delete'),(259,'owner','income.update'),(260,'owner','income.view'),(261,'owner','inventory.create'),(262,'owner','inventory.delete'),(263,'owner','inventory.update'),(264,'owner','inventory.view'),(265,'owner','ledger.create'),(266,'owner','ledger.delete'),(267,'owner','ledger.update'),(268,'owner','ledger.view'),(269,'owner','payables.create'),(270,'owner','payables.delete'),(271,'owner','payables.update'),(272,'owner','payables.view'),(273,'owner','payment_methods.update'),(274,'owner','payment_methods.view'),(275,'owner','payments.create'),(276,'owner','payments.delete'),(277,'owner','payments.view'),(278,'owner','payroll.approve'),(279,'owner','payroll.create'),(280,'owner','payroll.delete'),(281,'owner','payroll.update'),(282,'owner','payroll.view'),(283,'owner','receivables.create'),(284,'owner','receivables.delete'),(285,'owner','receivables.update'),(286,'owner','receivables.view'),(287,'owner','reports.create'),(288,'owner','reports.delete'),(289,'owner','reports.export'),(290,'owner','reports.update'),(291,'owner','reports.view'),(292,'owner','roles.view'),(293,'owner','sales.create'),(294,'owner','sales.delete'),(295,'owner','sales.update'),(296,'owner','sales.view'),(297,'owner','settings.update'),(298,'owner','settings.view'),(328,'owner','shifts.create'),(330,'owner','shifts.delete'),(329,'owner','shifts.update'),(327,'owner','shifts.view'),(299,'owner','tax.create'),(300,'owner','tax.delete'),(301,'owner','tax.update'),(302,'owner','tax.view'),(303,'owner','users.create'),(304,'owner','users.reset_password'),(305,'owner','users.update'),(306,'owner','users.view'),(178,'procurement','approvals.create'),(179,'procurement','approvals.view'),(180,'procurement','dashboard.view'),(181,'procurement','expenses.view'),(182,'procurement','inventory.view'),(183,'procurement','payables.create'),(184,'procurement','payables.update'),(185,'procurement','payables.view'),(186,'procurement','payment_methods.view'),(132,'sales_manager','dashboard.view'),(133,'sales_manager','inventory.view'),(134,'sales_manager','payment_methods.view'),(135,'sales_manager','payments.create'),(136,'sales_manager','payments.view'),(137,'sales_manager','receivables.create'),(138,'sales_manager','receivables.update'),(139,'sales_manager','receivables.view'),(140,'sales_manager','reports.export'),(141,'sales_manager','reports.view'),(142,'sales_manager','sales.create'),(143,'sales_manager','sales.delete'),(144,'sales_manager','sales.update'),(145,'sales_manager','sales.view'),(332,'sales_manager','shifts.create'),(333,'sales_manager','shifts.update'),(331,'sales_manager','shifts.view'),(77,'staff','approvals.create'),(76,'staff','approvals.view'),(78,'staff','bank.view'),(59,'staff','dashboard.view'),(70,'staff','expenses.create'),(69,'staff','expenses.view'),(72,'staff','income.create'),(71,'staff','income.view'),(61,'staff','inventory.create'),(62,'staff','inventory.update'),(60,'staff','inventory.view'),(68,'staff','payables.view'),(86,'staff','payment_methods.view'),(74,'staff','payments.create'),(73,'staff','payments.view'),(67,'staff','receivables.create'),(66,'staff','receivables.view'),(75,'staff','reports.view'),(64,'staff','sales.create'),(65,'staff','sales.update'),(63,'staff','sales.view'),(321,'staff','shifts.create'),(322,'staff','shifts.update'),(320,'staff','shifts.view'),(102,'storekeeper','approvals.create'),(103,'storekeeper','approvals.view'),(104,'storekeeper','dashboard.view'),(105,'storekeeper','inventory.create'),(106,'storekeeper','inventory.update'),(107,'storekeeper','inventory.view'),(108,'storekeeper','payables.view'),(109,'storekeeper','payment_methods.view');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uq_roles_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','Super Administrator','Full access to all modules and administration',1,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(2,'owner','Business Owner / Director','Business-wide access excluding user deletion and role configuration',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(3,'manager','General Manager','Inventory, sales, expenses, income and approvals',1,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(4,'inventory_manager','Inventory Manager','Inventory, purchasing and stock reporting',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(5,'storekeeper','Storekeeper','Stock receiving, issuing and adjustments',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(6,'cashier','Cashier','POS sales and payment entry',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(7,'sales_manager','Sales Manager','Sales, customers, quotes and sales analytics',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(8,'accountant','Accountant','Ledger, tax, banking, reports and reconciliation',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(9,'procurement','Procurement Officer','Suppliers, purchase orders and requisitions',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(10,'approver','Approving Officer','Approval queues and decisions',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(11,'auditor','Auditor','Read-only review of records, reports and audit trail',0,1,'2026-11-05 15:56:26','2026-11-05 15:56:26'),(12,'staff','Staff','Day-to-day inventory and sales entry',1,1,'2026-11-05 15:56:26','2026-11-05 15:56:26');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_invoice_items`
--

DROP TABLE IF EXISTS `sales_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_invoice_items` (
  `item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(19,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `idx_sii_invoice` (`invoice_id`),
  KEY `idx_sii_product` (`product_id`),
  CONSTRAINT `fk_sii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sii_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=543 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales invoice line items';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_invoice_items`
--

LOCK TABLES `sales_invoice_items` WRITE;
/*!40000 ALTER TABLE `sales_invoice_items` DISABLE KEYS */;
INSERT INTO `sales_invoice_items` VALUES (55,48,8,'Staff Product',6.0000,5.0000,40.0000,0.0000,0.0000,0.0000,30.0000,'2026-11-06 05:59:55');
/*!40000 ALTER TABLE `sales_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_invoices`
--

DROP TABLE IF EXISTS `sales_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_invoices` (
  `invoice_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `customer_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `shift_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `amount_paid` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `amount_owed` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `payment_method` varchar(30) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `posted_to_accounting` tinyint(1) NOT NULL DEFAULT 0,
  `paid_date` date DEFAULT NULL,
  `status` enum('draft','pending','partial','paid','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `uq_sales_idempotency` (`company_id`,`idempotency_key`),
  KEY `idx_invoices_customer` (`customer_id`),
  KEY `idx_invoices_status` (`status`),
  KEY `idx_sales_invoices_issue` (`company_id`,`issue_date`),
  KEY `idx_sales_invoices_branch` (`branch_id`),
  KEY `idx_sales_invoices_customer` (`customer_id`),
  KEY `idx_sales_shift` (`shift_id`),
  CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=197 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales invoices';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_invoices`
--

LOCK TABLES `sales_invoices` WRITE;
/*!40000 ALTER TABLE `sales_invoices` DISABLE KEYS */;
INSERT INTO `sales_invoices` VALUES (48,1,11,1,NULL,'INV-20261106-3811','2026-11-06','2026-11-06',30.0000,30.0000,0.0000,0.0000,0.0000,30.0000,0.0000,'cash',24,'d511caaf9b41af3f3a14169a244dca84',0,'2026-11-06','paid','','2026-11-06 05:59:55','2026-11-06 05:59:55');
/*!40000 ALTER TABLE `sales_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `migration_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration_id`),
  UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES (1,'001_auth_core.sql','6563b7c20f13362377a03c75d99917fdcd6afe12d618f5ed42abccb6cf32b5cd','2026-10-30 06:21:28'),(2,'002_core_inventory.sql','e32bd0eaa124cb7a9834d80c654be52583647782029863494bdb3df8c7daf064','2026-10-30 06:21:29'),(3,'003_company_branch_departments.sql','285fa3cfc3152a6593b03c43efcfe990d456fd210f13577a03f8418b8faeb8b2','2026-11-02 04:34:08'),(4,'004_sales_receivables.sql','02674f54528c2e9322fbe7749ac0492dd627484e03c4a9e4f22298c9a07aaa10','2026-11-02 04:38:06'),(5,'005_ap_expense_income.sql','b7d7daefa79f35d103bc9d5757d4fc770616fc7e6705dd04d8a9453595f0de6d','2026-11-02 04:58:15'),(6,'006_expense_vendor_nullable.sql','6eed2b81c7c3fb2fe4273ec5b81ae788b3a41261e4a0e8a144cec4cd42992a10','2026-11-02 05:08:17'),(7,'007_rbac_permissions.sql','c53fe237ebd628964e88abbfd5eca7f73f888dd1d45477a185184d1d1f883a8a','2026-11-02 18:56:27'),(8,'008_admin_core.sql','5dbed72c3123e8e6d7a68630faee46564a847c27c4b513aa615b578ddbcae167','2026-11-05 15:56:26'),(9,'009_payment_method_widen.sql','7248eed81b91947068f5b28986d4d320e2253c29bc026496e31035936c397531','2026-11-05 19:14:23'),(10,'010_barcodes.sql','107bc2f11b8f5391868db53130117da80474a33d6f4428d632d266a3b07ddcf6','2026-11-05 19:33:16'),(11,'011_cashier_shifts.sql','a46af062d5e47f0f974d4b5771b63b9aabf24f708ea230c2064df6b8cb1175db','2026-11-05 21:02:57'),(12,'012_payment_gateways.sql','98dc2123d6ad05e7605a6b7b06d3f20d0228cc2da22c76b8d5302cd6d7d6cca8','2026-11-06 06:17:13'),(13,'013_gateway_provider_id.sql','4cf913b65f36202b28c364b03a2e6ace362e6acc7d13bdd9eae4dd43444ca0b7','2026-11-06 06:22:11'),(14,'014_receipt_printing.sql','3cf3d9b349a97b7dbfe2af6e5b9de9ffc9aa6813910a7921e38adbadb3ad1385','2026-11-06 11:48:55'),(15,'015_sales_analytics.sql','a527e7b9fdfbe3a4375f4bad606f0244ab9d17bdb6cd79e7ad12e5303c0d04c6','2026-11-06 14:48:06');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `key` varchar(80) NOT NULL,
  `value` text NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'misc',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `uk_settings_company_key` (`company_id`,`key`),
  KEY `idx_settings_company` (`company_id`),
  KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Application settings catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,1,'decimal_places','2','general','Decimal_places setting',1,'2026-11-05 19:13:49','2026-11-05 19:16:24'),(2,1,'default_tax_rate','0','general','Default_tax_rate setting',1,'2026-11-05 19:13:49','2026-11-05 19:16:24'),(3,1,'default_ap_terms','Net 30','general','Default_ap_terms setting',1,'2026-11-05 19:13:49','2026-11-05 19:16:24'),(4,1,'default_income_source','0','general','Default_income_source setting',1,'2026-11-05 19:13:49','2026-11-05 19:16:24'),(5,1,'allow_self_approval','0','general','Allow_self_approval setting',1,'2026-11-05 19:16:11','2026-11-05 19:16:24'),(6,1,'pos_requires_shift','1','misc',NULL,1,'2026-11-05 22:42:29','2026-11-06 13:02:05');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shift_movements`
--

DROP TABLE IF EXISTS `shift_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_movements` (
  `movement_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `movement_type` enum('float_adjust','paid_in','paid_out','cash_refund') NOT NULL,
  `amount` decimal(19,4) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `reference_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `idx_movements_shift` (`shift_id`,`movement_type`),
  KEY `fk_movements_company` (`company_id`),
  KEY `fk_movements_user` (`created_by`),
  KEY `fk_movements_invoice` (`reference_invoice_id`),
  CONSTRAINT `fk_movements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`company_id`),
  CONSTRAINT `fk_movements_invoice` FOREIGN KEY (`reference_invoice_id`) REFERENCES `sales_invoices` (`invoice_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_movements_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_movements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shift_movements`
--

LOCK TABLES `shift_movements` WRITE;
/*!40000 ALTER TABLE `shift_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `shift_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `key` varchar(80) NOT NULL,
  `value` text DEFAULT NULL,
  `value_type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `key` (`key`),
  UNIQUE KEY `uk_settings_company_key` (`company_id`,`key`),
  KEY `idx_settings_company` (`company_id`),
  KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System configuration settings';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,1,'company_name','Inventory Management Co.','string','Company name for reports and documents',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(2,1,'company_address','123 Business Ave, City, State','string','Company address',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(3,1,'company_phone','555-0100','string','Company phone number',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(4,1,'currency_default','USD','string','Default currency for all transactions',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(5,1,'decimal_places','4','number','Default decimal places for monetary values',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(6,1,'enable_inventory','1','boolean','Enable inventory management',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(7,1,'enable_approvals','1','boolean','Enable approval workflows',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(8,1,'tax_enabled','1','boolean','Tax management enabled',1,'2026-10-28 13:54:50','2026-10-28 13:54:50'),(9,1,'backup_interval','24','number','Automatic backup interval in hours',1,'2026-10-28 13:54:50','2026-10-28 13:54:50');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tax_rates`
--

DROP TABLE IF EXISTS `tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax_rates` (
  `tax_rate_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `rate` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `tax_type` enum('gst','vat','sales_tax','use_tax') NOT NULL DEFAULT 'sales_tax',
  `jurisdiction` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_date` date NOT NULL DEFAULT curdate(),
  `end_date` date DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tax_rate_id`),
  UNIQUE KEY `uk_taxrates_jurisdiction` (`jurisdiction`,`tax_type`),
  KEY `idx_taxrates_company` (`company_id`),
  KEY `idx_taxrates_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tax rates catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_rates`
--

LOCK TABLES `tax_rates` WRITE;
/*!40000 ALTER TABLE `tax_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `tax_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tax_transactions`
--

DROP TABLE IF EXISTS `tax_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax_transactions` (
  `transaction_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `tax_rate_id` bigint(20) unsigned NOT NULL,
  `taxable_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(19,4) NOT NULL DEFAULT 0.0000,
  `transaction_type` enum('sale','purchase','adjustment') NOT NULL DEFAULT 'sale',
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `transaction_date` date NOT NULL DEFAULT curdate(),
  `description` text DEFAULT NULL,
  `is_reconciled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `idx_taxtransactions_rate` (`tax_rate_id`),
  KEY `idx_taxtransactions_date` (`transaction_date`),
  KEY `idx_taxtransactions_type` (`transaction_type`),
  CONSTRAINT `fk_taxtransactions_rate` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`tax_rate_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tax transaction audit trail';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_transactions`
--

LOCK TABLES `tax_transactions` WRITE;
/*!40000 ALTER TABLE `tax_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `tax_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_branch_access`
--

DROP TABLE IF EXISTS `user_branch_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_branch_access` (
  `user_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`branch_id`),
  KEY `idx_user_branch_branch` (`branch_id`),
  CONSTRAINT `fk_uba_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uba_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-user branch access';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_branch_access`
--

LOCK TABLES `user_branch_access` WRITE;
/*!40000 ALTER TABLE `user_branch_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_branch_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permissions`
--

DROP TABLE IF EXISTS `user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permissions` (
  `user_permission_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_permission_id`),
  KEY `idx_user_permissions_user` (`user_id`),
  KEY `idx_user_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_user_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-permission assignments';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permissions`
--

LOCK TABLES `user_permissions` WRITE;
/*!40000 ALTER TABLE `user_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_sessions`
--

DROP TABLE IF EXISTS `user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_sessions` (
  `session_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User session tracking';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_sessions`
--

LOCK TABLES `user_sessions` WRITE;
/*!40000 ALTER TABLE `user_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'staff',
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `last_ip_address` varchar(45) DEFAULT NULL,
  `failed_login_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  `password_reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`),
  KEY `idx_users_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System users';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','admin@inventory.com','admin','$2y$10$yksGvF/BHl16Mp6oifZDJezt5DsTJ3HR9u5kwlWNo/r0NkKraHmBW','admin',1,NULL,1,'2026-10-28 13:52:53','2026-11-07 07:56:58','2026-11-07 08:56:58','::1',0,NULL,'2026-11-02 18:57:56',NULL,NULL),(18,'demo','demo@example.com','Demo Administrator','$2y$10$5Oeihds/.tU6UO12YRhTJ.Ury/FdS0h/rHkMG/Tkq2fX/BiFmMyx6','admin',1,1,1,'2026-11-03 23:42:10','2026-11-04 05:46:22','2026-11-04 06:46:22','::1',0,NULL,NULL,NULL,NULL),(19,'staff','staff@inventory.com','Staff','$2y$10$p8m6TnUikYIiDyFtbSbjw.si42FO0S34UgibjU6cLLUXjo4s4802q','staff',1,1,1,'2026-11-04 00:01:56','2026-11-04 20:31:44','2026-11-04 01:02:52','::1',0,'2026-11-04 21:58:42',NULL,NULL,NULL),(24,'Kemi','bola.staff@inventory.com','Bolakale','$2y$10$JmQmduGZx2PF8yuTVhPV3u6UoZe4xkHkLILfgRZ7Xmj6k7ZSc2Wlq','cashier',1,1,1,'2026-11-05 19:43:26','2026-11-06 05:43:21','2026-11-06 06:43:21','::1',0,NULL,'2026-11-05 20:43:26',NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vendors`
--

DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendors` (
  `vendor_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `tax_id` varchar(20) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(80) DEFAULT NULL,
  `payment_terms` varchar(40) NOT NULL DEFAULT 'Net 30',
  `is_tax_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `default_currency` varchar(3) NOT NULL DEFAULT 'USD',
  `notes` text DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`vendor_id`),
  KEY `idx_vendors_company` (`company_id`),
  KEY `idx_vendors_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vendor catalogue';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vendors`
--

LOCK TABLES `vendors` WRITE;
/*!40000 ALTER TABLE `vendors` DISABLE KEYS */;
INSERT INTO `vendors` VALUES (4,1,'Akin Concepts Limited','Abdullateef O. Matanmi Olalekan Dada','111111111','07035627734','No 2 Raji Olaosebikan Doherty Street\r\nHamdalat International Secondary School','Offa','Kwara','250101','Nigeria','Net 30',0,'USD',NULL,'mirrorageconcepts@gmail.com',1,'2026-11-04 20:06:16','2026-11-04 20:06:16');
/*!40000 ALTER TABLE `vendors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `zz_deprecated_company_settings`
--

DROP TABLE IF EXISTS `zz_deprecated_company_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `zz_deprecated_company_settings` (
  `setting_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `key` varchar(80) NOT NULL,
  `value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `key` (`key`),
  UNIQUE KEY `uk_settings_company_key` (`company_id`,`key`),
  KEY `idx_settings_company` (`company_id`),
  KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company configuration settings';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `zz_deprecated_company_settings`
--

LOCK TABLES `zz_deprecated_company_settings` WRITE;
/*!40000 ALTER TABLE `zz_deprecated_company_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `zz_deprecated_company_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-07  9:03:28
