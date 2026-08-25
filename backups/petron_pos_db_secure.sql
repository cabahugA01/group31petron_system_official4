-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: petron_pos_db_secure

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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_log_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_act_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,13,'Logout','Yyeng C. (Staff) logged out',NULL,'::1','2026-08-21 13:06:20','2026-08-21 13:06:20'),(2,13,'Logout','Yyeng C. (Staff) logged out',NULL,'::1','2026-08-21 13:06:20','2026-08-21 13:06:20'),(3,13,'Login','Yyeng C. (Staff) logged in via Email',NULL,'::1','2026-08-21 13:46:49','2026-08-21 13:46:49'),(4,13,'Clock In','Auto clock-in on login - Station 1253 - First Shift: 6:00 AM - 2:00 PM',NULL,'::1','2026-08-21 13:46:49','2026-08-21 13:46:49'),(5,13,'Logout','Yyeng C. (Staff) logged out',NULL,'::1','2026-08-21 14:02:20','2026-08-21 14:02:20'),(6,13,'Clock Out','Auto clock-out on logout',NULL,'::1','2026-08-21 14:02:20','2026-08-21 14:02:20'),(7,13,'Logout','Yyeng C. (Staff) logged out',NULL,'::1','2026-08-21 14:02:20','2026-08-21 14:02:20'),(8,4,'View Product Pricing','Admin viewed pricing for station 1253',NULL,'::1','2026-08-21 14:08:40','2026-08-21 14:08:40'),(9,4,'View Product Pricing','Admin viewed pricing for station 1253',NULL,'::1','2026-08-21 14:08:40','2026-08-21 14:08:40'),(10,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:13:15','2026-08-21 14:13:15'),(11,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:13:33','2026-08-21 14:13:33'),(12,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:13:51','2026-08-21 14:13:51'),(13,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:14:09','2026-08-21 14:14:09'),(14,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:14:27','2026-08-21 14:14:27'),(15,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:14:45','2026-08-21 14:14:45'),(16,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:15:03','2026-08-21 14:15:03'),(17,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:15:21','2026-08-21 14:15:21'),(18,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:15:37','2026-08-21 14:15:37'),(19,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'0.0.0.0','2026-08-21 14:15:52','2026-08-21 14:15:52'),(20,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:15:55','2026-08-21 14:15:55'),(21,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:16:13','2026-08-21 14:16:13'),(22,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:16:31','2026-08-21 14:16:31'),(23,4,'View Product Pricing','Admin viewed pricing for station 1253',NULL,'::1','2026-08-21 14:16:43','2026-08-21 14:16:43'),(24,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:16:49','2026-08-21 14:16:49'),(25,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:17:07','2026-08-21 14:17:07'),(26,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:17:25','2026-08-21 14:17:25'),(27,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:17:43','2026-08-21 14:17:43'),(28,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:18:01','2026-08-21 14:18:01'),(29,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:18:19','2026-08-21 14:18:19'),(30,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:18:37','2026-08-21 14:18:37'),(31,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:18:55','2026-08-21 14:18:55'),(32,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:19:13','2026-08-21 14:19:13'),(33,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:19:31','2026-08-21 14:19:31'),(34,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:19:49','2026-08-21 14:19:49'),(35,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:20:07','2026-08-21 14:20:07'),(36,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:20:25','2026-08-21 14:20:25'),(37,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:20:43','2026-08-21 14:20:43'),(38,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:21:01','2026-08-21 14:21:01'),(39,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:21:19','2026-08-21 14:21:19'),(40,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:21:37','2026-08-21 14:21:37'),(41,3,'View Product Pricing','Manager viewed pricing for station 1253',NULL,'::1','2026-08-21 14:21:55','2026-08-21 14:21:55'),(42,4,'Logout','Romeca Katherine Jane Tello Pepito (Admin) logged out',NULL,'::1','2026-08-21 14:44:35','2026-08-21 14:44:35'),(43,3,'Logout','Edgar Eslit (Manager) logged out',NULL,'::1','2026-08-21 14:44:35','2026-08-21 14:44:35'),(44,4,'View Product Pricing','Admin viewed pricing for station 1253',NULL,'0.0.0.0','2026-08-21 14:45:14','2026-08-21 14:45:14'),(45,4,'Login','Romeca Katherine Jane Tello Pepito (Admin) logged in via Email',NULL,'::1','2026-08-21 14:45:50','2026-08-21 14:45:50'),(46,3,'Login','Edgar Eslit (Manager) logged in via Email',NULL,'::1','2026-08-21 14:50:29','2026-08-21 14:50:29'),(47,13,'Login','Yyeng C. (Staff) logged in via Email',NULL,'::1','2026-08-21 14:55:00','2026-08-21 14:55:00'),(48,13,'Clock In','Auto clock-in on login - Station 1253 - First Shift: 6:00 AM - 2:00 PM',NULL,'::1','2026-08-21 14:55:00','2026-08-21 14:55:00'),(49,4,'Logout','Romeca Katherine Jane Tello Pepito (Admin) logged out',NULL,'::1','2026-08-21 15:09:11','2026-08-21 15:09:11'),(50,1,'Login','Yang (Superadmin) logged in via Email',NULL,'::1','2026-08-21 15:09:31','2026-08-21 15:09:31'),(51,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:28:25','2026-08-21 15:28:25'),(52,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:31:07','2026-08-21 15:31:07'),(53,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:31:38','2026-08-21 15:31:38'),(54,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:34:00','2026-08-21 15:34:00'),(55,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:34:24','2026-08-21 15:34:24'),(56,13,'Logout','Yyeng C. (Staff) logged out',NULL,'::1','2026-08-21 15:34:29','2026-08-21 15:34:29'),(57,13,'Clock Out','Auto clock-out on logout',NULL,'::1','2026-08-21 15:34:29','2026-08-21 15:34:29'),(58,13,'Login','Yyeng C. (Staff) logged in via Email',NULL,'::1','2026-08-21 15:34:45','2026-08-21 15:34:45'),(59,13,'Clock In','Auto clock-in on login - Station 1253 - First Shift: 6:00 AM - 2:00 PM',NULL,'::1','2026-08-21 15:34:45','2026-08-21 15:34:45'),(60,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:35:51','2026-08-21 15:35:51'),(61,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:43:21','2026-08-21 15:43:21'),(62,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:44:24','2026-08-21 15:44:24'),(63,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:44:37','2026-08-21 15:44:37'),(64,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:52:19','2026-08-21 15:52:19'),(65,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:54:57','2026-08-21 15:54:57'),(66,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"disabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:55:15','2026-08-21 15:55:15'),(67,1,'Module Configuration','Saved config for dashboard @ All Stations (Global): {\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',NULL,'::1','2026-08-21 15:55:21','2026-08-21 15:55:21'),(68,1,'Database Management','Saved backup configuration',NULL,'::1','2026-08-21 16:52:01','2026-08-21 16:52:01'),(69,1,'Database Management','Saved backup configuration',NULL,'::1','2026-08-21 16:52:01','2026-08-21 16:52:01');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adjustment_history`
--

DROP TABLE IF EXISTS `adjustment_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adjustment_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(255) NOT NULL,
  `transaction_db_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` int(11) NOT NULL,
  `reason` text NOT NULL,
  `old_values_json` text NOT NULL,
  `new_values_json` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ah_txn` (`transaction_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_approved_by` (`approved_by`),
  CONSTRAINT `fk_adjustment_history_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adjustment_history_request_id` FOREIGN KEY (`request_id`) REFERENCES `stock_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adjustment_history`
--

LOCK TABLES `adjustment_history` WRITE;
/*!40000 ALTER TABLE `adjustment_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `adjustment_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adjustment_types`
--

DROP TABLE IF EXISTS `adjustment_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adjustment_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adjustment_types`
--

LOCK TABLES `adjustment_types` WRITE;
/*!40000 ALTER TABLE `adjustment_types` DISABLE KEYS */;
INSERT INTO `adjustment_types` VALUES (1,'Delivery','Fuel delivery adjustment',1,'2026-08-07 12:35:49','2026-08-07 12:35:49'),(2,'Theft/Loss','Fuel theft or loss adjustment',1,'2026-08-07 12:35:49','2026-08-07 12:35:49'),(3,'Calibration','Pump calibration adjustment',1,'2026-08-07 12:35:49','2026-08-07 12:35:49'),(4,'Spillage','Fuel spillage adjustment',1,'2026-08-07 12:35:49','2026-08-07 12:35:49'),(5,'Transfer','Fuel transfer between tanks',1,'2026-08-07 12:35:49','2026-08-07 12:35:49'),(6,'Other','Other fuel adjustment',1,'2026-08-07 12:35:49','2026-08-07 12:35:49');
/*!40000 ALTER TABLE `adjustment_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `log_type` varchar(50) NOT NULL COMMENT 'user, transaction, inventory, system',
  `action_type` varchar(100) NOT NULL COMMENT 'Login, Logout, Create, Update, Delete, View',
  `action_details` text DEFAULT NULL,
  `entity_type` varchar(100) DEFAULT NULL COMMENT 'users, sales, inventory, customers, etc',
  `entity_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL COMMENT 'Success, Failed, Pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,13,'user','Logout','Yyeng C. (Staff) logged out','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 05:06:20'),(2,13,'user','Logout','Yyeng C. (Staff) logged out','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 05:06:20'),(3,13,'user','Login','Yyeng C. (Staff) logged in via Email','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 05:46:49'),(4,13,'user','Logout','Yyeng C. (Staff) logged out','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 06:02:20'),(5,13,'user','Logout','Yyeng C. (Staff) logged out','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 06:02:20'),(6,4,'user','Logout','Romeca Katherine Jane Tello Pepito (Admin) logged out','users',4,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','Success',NULL,'2026-08-21 06:44:35'),(7,3,'user','Logout','Edgar Eslit (Manager) logged out','users',3,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','Success',NULL,'2026-08-21 06:44:35'),(8,4,'user','Login','Romeca Katherine Jane Tello Pepito (Admin) logged in via Email','users',4,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','Success',NULL,'2026-08-21 06:45:50'),(9,3,'user','Login','Edgar Eslit (Manager) logged in via Email','users',3,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','Success',NULL,'2026-08-21 06:50:29'),(10,13,'user','Login','Yyeng C. (Staff) logged in via Email','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 06:55:00'),(11,4,'user','Logout','Romeca Katherine Jane Tello Pepito (Admin) logged out','users',4,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','Success',NULL,'2026-08-21 07:09:11'),(12,1,'user','Login','Yang (Superadmin) logged in via Email','users',1,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','Success',NULL,'2026-08-21 07:09:31'),(13,13,'user','Logout','Yyeng C. (Staff) logged out','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 07:34:29'),(14,13,'user','Login','Yyeng C. (Staff) logged in via Email','users',13,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','Success',NULL,'2026-08-21 07:34:45');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_trail`
--

DROP TABLE IF EXISTS `audit_trail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_trail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(255) NOT NULL,
  `manager_id` int(11) NOT NULL,
  `action_type` varchar(60) NOT NULL,
  `new_value` text DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `station_id` int(11) NOT NULL DEFAULT 0,
  `entity_type` varchar(60) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `source_table` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_txn` (`transaction_id`),
  KEY `idx_mgr` (`manager_id`),
  KEY `idx_ts` (`created_at`),
  KEY `idx_manager_id` (`manager_id`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_audit_trail_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_trail_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_trail`
--

LOCK TABLES `audit_trail` WRITE;
/*!40000 ALTER TABLE `audit_trail` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_trail` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Fuel','Fuel products','2026-03-23 16:40:07','2026-03-23 16:40:07'),(2,'Merchandise','Merchandise products','2026-03-23 16:40:07','2026-03-23 16:40:07'),(3,'Parts','Vehicle parts and accessories','2026-03-23 16:40:07','2026-03-23 16:40:07'),(4,'Supplies','Station supplies and consumables','2026-03-23 16:40:07','2026-03-23 16:40:07');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_accounts_receivable`
--

DROP TABLE IF EXISTS `customer_accounts_receivable`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_accounts_receivable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `transaction_id` varchar(255) NOT NULL,
  `transaction_db_id` int(11) NOT NULL,
  `or_number` varchar(100) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `outstanding_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(60) NOT NULL DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_car_cust` (`customer_id`),
  KEY `idx_car_txn` (`transaction_id`),
  KEY `idx_car_stat` (`status`),
  KEY `idx_customer_id` (`customer_id`),
  CONSTRAINT `fk_customer_accounts_receivable_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_accounts_receivable`
--

LOCK TABLES `customer_accounts_receivable` WRITE;
/*!40000 ALTER TABLE `customer_accounts_receivable` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_accounts_receivable` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_credit_transactions`
--

DROP TABLE IF EXISTS `customer_credit_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_credit_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL DEFAULT 1,
  `customer_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'Credit Payment',
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_customer_id` (`customer_id`),
  CONSTRAINT `fk_customer_credit_transactions_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_customer_credit_transactions_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_credit_transactions`
--

LOCK TABLES `customer_credit_transactions` WRITE;
/*!40000 ALTER TABLE `customer_credit_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_credit_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_requests`
--

DROP TABLE IF EXISTS `customer_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `customer_type` varchar(30) NOT NULL DEFAULT 'walk-in',
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `vehicle_make` varchar(100) DEFAULT NULL,
  `vehicle_model` varchar(100) DEFAULT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `request_reason` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `manager_remarks` text DEFAULT NULL,
  `customer_record_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_station_status` (`station_id`,`status`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_customer_record` (`customer_record_id`),
  KEY `fk_cr_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_cr_customer_record` FOREIGN KEY (`customer_record_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cr_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cr_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_requests`
--

LOCK TABLES `customer_requests` WRITE;
/*!40000 ALTER TABLE `customer_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_timeline`
--

DROP TABLE IF EXISTS `customer_timeline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_timeline` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cust` (`customer_id`),
  KEY `idx_customer_timeline_created_by` (`created_by`),
  CONSTRAINT `fk_ctimeline_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ctimeline_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_timeline`
--

LOCK TABLES `customer_timeline` WRITE;
/*!40000 ALTER TABLE `customer_timeline` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_timeline` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_vehicles`
--

DROP TABLE IF EXISTS `customer_vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `plate_number` varchar(50) NOT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year_model` varchar(20) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `engine_no` varchar(100) DEFAULT NULL,
  `chassis_no` varchar(100) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cust` (`customer_id`),
  CONSTRAINT `fk_cvehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_vehicles`
--

LOCK TABLES `customer_vehicles` WRITE;
/*!40000 ALTER TABLE `customer_vehicles` DISABLE KEYS */;
INSERT INTO `customer_vehicles` VALUES (1,1,'KAF‑5466','Sedan','Toyota','Vios','2024','Red','ENG‑5466‑TOY','CHS‑TOY‑2024‑5466','archived','2026-08-08 12:00:36'),(2,2,'NBG‑7821','SUV','Honda','CR‑V',NULL,NULL,NULL,NULL,'active','2026-08-08 12:24:03'),(3,3,'ABC 1234','SUV','Toyota','Fortuner',NULL,NULL,NULL,NULL,'active','2026-08-08 15:57:04'),(4,4,'123','Motorcycle','suzuki','raider','2025','red','123','446','active','2026-08-14 14:46:46'),(5,5,'789','sniper','2025','yamaha',NULL,NULL,NULL,NULL,'active','2026-08-14 14:54:57'),(6,6,'121245','sedan','honda','civic',NULL,NULL,NULL,NULL,'active','2026-08-20 12:16:42'),(7,7,'NBG‑7821','SUV','Honda','CR‑V',NULL,NULL,NULL,NULL,'active','2026-08-20 14:04:46'),(8,8,'FTG-9087','Sedan','','','','','EGT-0000988','GTU099997777','active','2026-08-20 14:11:08');
/*!40000 ALTER TABLE `customer_vehicles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `type` enum('cash','credit') DEFAULT 'cash',
  `merchandise_type` enum('oil_lube_grease','car_accessories','oil_fuel_filter','others','multiple') DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `current_balance` decimal(12,2) DEFAULT 0.00,
  `points` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'active',
  `station_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `balance` decimal(10,2) DEFAULT 0.00,
  `id_type` varchar(50) DEFAULT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `account_status` varchar(50) DEFAULT 'active',
  `mgr_status` varchar(20) DEFAULT 'pending',
  `mgr_notes` text DEFAULT NULL,
  `mgr_reviewed_by` int(11) DEFAULT NULL,
  `mgr_reviewed_at` datetime DEFAULT NULL,
  `gov_id_image` varchar(500) DEFAULT NULL,
  `cr_document` varchar(500) DEFAULT NULL,
  `verification_status` varchar(50) DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `customer_type` varchar(30) NOT NULL DEFAULT 'walk-in',
  `registered_by` int(11) DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `customer_id` varchar(50) DEFAULT NULL,
  `gov_id_type` varchar(100) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `company_address` text DEFAULT NULL,
  `company_contact_person` varchar(255) DEFAULT NULL,
  `company_contact_number` varchar(50) DEFAULT NULL,
  `verification_remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `vehicle_make` varchar(100) DEFAULT NULL,
  `vehicle_brand` varchar(100) DEFAULT NULL,
  `vehicle_model` varchar(100) DEFAULT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `chassis_number` varchar(100) DEFAULT NULL,
  `outstanding_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_terms` varchar(50) DEFAULT '30 Days',
  `gov_id_file` varchar(500) DEFAULT NULL,
  `cr_file` varchar(500) DEFAULT NULL,
  `or_file` varchar(500) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `archive_remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_customer_station` (`station_id`),
  KEY `idx_id2` (`id`),
  KEY `idx_mgr_reviewed_by_auto` (`mgr_reviewed_by`),
  KEY `idx_customer_id_val` (`customer_id`),
  KEY `fk_customers_updated_by` (`updated_by`),
  CONSTRAINT `fk_customer_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_mgr_reviewed_by_9bc6` FOREIGN KEY (`mgr_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'Shenn M. Mendez','N/A',NULL,'N/A','mendez@gmail.com','Lapasan, Cagayan De Oro City','cash',NULL,15000.00,0.00,34,'active',1253,'2026-08-08 12:00:36',0.00,NULL,NULL,'active','Verified',NULL,NULL,NULL,NULL,NULL,'Verified',NULL,NULL,'Shenn','M.','Mendez','walk-in',3,'2026-08-08 12:00:36','CUS-1253-202608-001','Driver\'s License',NULL,NULL,NULL,NULL,NULL,3,'2026-08-12 21:44:31','KAF‑5466','Toyota','Toyota','Vios','Sedan',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(2,'Mary S. Cruz','09524132501',NULL,'09524132501','','Cugman, Cagayan De Oro','cash',NULL,10000.00,0.00,27,'active',1253,'2026-08-08 12:24:03',0.00,NULL,NULL,'active','Verified',NULL,NULL,NULL,NULL,NULL,'Verified',NULL,NULL,'Mary','S.','Cruz','walk-in',3,'2026-08-08 12:24:03','CUS-1253-202608-002','',NULL,NULL,NULL,NULL,NULL,3,'2026-08-12 21:44:08','NBG‑7821','Honda','Honda','CR‑V','SUV',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(3,'Ben I. Pangilinan','09766446011',NULL,'09766446011','','Patag, Cagayan De Oro City','cash',NULL,10000.00,0.00,3,'active',1253,'2026-08-08 15:57:04',0.00,NULL,NULL,'active','Verified',NULL,NULL,NULL,NULL,NULL,'Verified',NULL,NULL,'Ben','I.','Pangilinan','walk-in',3,'2026-08-08 15:57:04','CUS-1253-202608-003','',NULL,NULL,NULL,NULL,NULL,3,'2026-08-14 14:34:41','ABC 1234','Toyota','Toyota','Fortuner','SUV',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(4,'eds P pay','09095332320',NULL,'09095332320','edsp@gamil.com','capisnon','cash',NULL,0.00,0.00,2,'active',1253,'2026-08-14 14:46:46',0.00,NULL,NULL,'active','Verified',NULL,NULL,NULL,NULL,NULL,'Verified',NULL,NULL,'eds','P','pay','walk-in',3,'2026-08-14 14:46:46','CUS-1253-202608-004','Driver\'s License',NULL,NULL,NULL,NULL,NULL,3,'2026-08-14 14:46:52','123','suzuki','suzuki','raider','Motorcycle',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(5,'memaw i kroro','09551629100',NULL,'09551629100',NULL,'burgos','cash',NULL,0.00,0.00,0,'active',1253,'2026-08-14 14:54:57',0.00,NULL,NULL,'active','Verified',NULL,NULL,NULL,NULL,NULL,'Verified',NULL,NULL,'memaw','i','kroro','regular',3,'2026-08-14 14:54:57','CUS-1253-202608-005',NULL,NULL,NULL,NULL,NULL,NULL,3,'2026-08-18 23:22:55','789','2025','2025','yamaha','sniper',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(6,'chris  val','09095332320',NULL,'09095332320',NULL,'apovel','cash',NULL,0.00,0.00,0,'active',1253,'2026-08-20 12:16:42',0.00,NULL,NULL,'active','pending',NULL,NULL,NULL,NULL,NULL,'pending',NULL,NULL,'chris','','val','walk-in',3,'2026-08-20 12:16:42','CUS-1253-202608-006',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'121245','honda','honda','civic','sedan',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(7,'airel d Cruz','09524132501',NULL,'09524132501',NULL,'','cash',NULL,0.00,0.00,48,'active',1253,'2026-08-20 14:04:46',0.00,NULL,NULL,'active','pending',NULL,NULL,NULL,NULL,NULL,'pending',NULL,NULL,'airel','d','Cruz','walk-in',3,'2026-08-20 14:04:46','CUS-1253-202608-007',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'NBG‑7821','Honda','Honda','CR‑V','SUV',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(8,'Sandara F. Pagaling','09095332358',NULL,'09095332358','sandara@gmail.com','Cugman, Cagayan De Oro','cash',NULL,0.00,0.00,32,'active',1253,'2026-08-20 14:11:08',0.00,NULL,NULL,'active','pending',NULL,NULL,NULL,NULL,NULL,'pending',NULL,NULL,'Sandara','F.','Pagaling','registered',3,'2026-08-20 14:11:08','CUS-1253-202608-008','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'FTG-9087','','','','Sedan',NULL,NULL,0.00,'30 Days',NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `database_backups`
--

DROP TABLE IF EXISTS `database_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `database_backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_name` varchar(255) NOT NULL,
  `backup_file` varchar(255) NOT NULL,
  `backup_size` bigint(20) DEFAULT 0,
  `backup_type` varchar(50) DEFAULT 'Full Backup',
  `station_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'Completed',
  `backup_path` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `compression` varchar(20) DEFAULT 'SQL',
  `verified` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_database_backups_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_database_backups_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `database_backups`
--

LOCK TABLES `database_backups` WRITE;
/*!40000 ALTER TABLE `database_backups` DISABLE KEYS */;
/*!40000 ALTER TABLE `database_backups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deliveries_oversight`
--

DROP TABLE IF EXISTS `deliveries_oversight`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deliveries_oversight` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_type` enum('fuel','merchandise') NOT NULL DEFAULT 'fuel',
  `delivery_ref` varchar(100) NOT NULL DEFAULT '',
  `supplier` varchar(200) NOT NULL DEFAULT '',
  `product` varchar(200) NOT NULL DEFAULT '',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `expected_quantity` decimal(12,3) DEFAULT NULL COMMENT 'Expected quantity from PO',
  `actual_quantity` decimal(12,3) DEFAULT NULL COMMENT 'Actual quantity received',
  `damaged_quantity` decimal(12,3) DEFAULT 0.000 COMMENT 'Damaged/unusable quantity',
  `unit_price` decimal(12,2) DEFAULT NULL COMMENT 'Unit price for payment computation',
  `expected_amount` decimal(15,2) DEFAULT NULL COMMENT 'Expected total amount',
  `payable_amount` decimal(15,2) DEFAULT NULL COMMENT 'Actual payable amount after adjustments',
  `unit` varchar(30) NOT NULL DEFAULT 'L',
  `delivery_date` date NOT NULL,
  `dr_number` varchar(100) DEFAULT NULL,
  `encoded_by` int(11) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `status` varchar(60) NOT NULL DEFAULT 'Pending Manager Approval',
  `admin_id` int(11) DEFAULT NULL,
  `admin_action_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `source_ref` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `remarks` text DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `return_reason` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `manager_action_at` datetime DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `batch_id` varchar(100) DEFAULT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `received_by_name` varchar(200) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `discrepancy_type` varchar(50) DEFAULT NULL,
  `resolution_action` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `finalized_by` int(11) DEFAULT NULL,
  `delivery_time` time DEFAULT NULL,
  `sales_invoice_no` varchar(100) DEFAULT NULL,
  `received_shift` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`delivery_date`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_encoded_by_auto` (`encoded_by`),
  KEY `fk_deliveries_oversight_manager_id` (`manager_id`),
  CONSTRAINT `fk2_deliveries_oversight_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_oversight_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_oversight_encoded_by_9bc6` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_oversight_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_deliveries_oversight_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deliveries_oversight`
--

LOCK TABLES `deliveries_oversight` WRITE;
/*!40000 ALTER TABLE `deliveries_oversight` DISABLE KEYS */;
/*!40000 ALTER TABLE `deliveries_oversight` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `error_tracking_logs`
--

DROP TABLE IF EXISTS `error_tracking_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `error_tracking_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `error_type` varchar(100) NOT NULL,
  `error_message` text DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `module_name` varchar(100) DEFAULT NULL,
  `severity` varchar(20) DEFAULT 'error',
  `status` varchar(20) DEFAULT 'unresolved',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_error_type` (`error_type`),
  KEY `idx_severity` (`severity`),
  KEY `fk_etl_station` (`station_id`),
  CONSTRAINT `fk_etl_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `error_tracking_logs`
--

LOCK TABLES `error_tracking_logs` WRITE;
/*!40000 ALTER TABLE `error_tracking_logs` DISABLE KEYS */;
INSERT INTO `error_tracking_logs` VALUES (1,NULL,'Warning Log','Backup delayed by 2 minutes',NULL,'Backup','Warning','Resolved','2026-08-21 09:22:46'),(2,NULL,'Warning Log','Query response time spike on transactions index',NULL,'Database','Warning','Active','2026-08-21 07:22:46'),(3,NULL,'Critical Log','Failed login threshold exceeded for IP 192.168.1.45',NULL,'Authentication','Critical','Active','2026-08-21 05:22:46'),(4,NULL,'Information Log','System accent color preference updated',NULL,'System Settings','Information','Resolved','2026-08-20 10:22:46'),(5,NULL,'Warning Log','Module access cached clearance re-sync',NULL,'Module Config','Warning','Active','2026-08-19 10:22:46');
/*!40000 ALTER TABLE `error_tracking_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_adjustments`
--

DROP TABLE IF EXISTS `fuel_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `adjustment_date` date DEFAULT NULL,
  `fuel_type` varchar(50) DEFAULT NULL,
  `fuel_type_id` int(11) DEFAULT NULL,
  `adjustment_type` varchar(50) DEFAULT NULL,
  `adjustment_type_id` int(11) DEFAULT NULL,
  `liters` decimal(10,2) DEFAULT NULL,
  `previous_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `new_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ugt_no` varchar(50) DEFAULT NULL,
  `adjustment_direction` varchar(20) NOT NULL DEFAULT 'Decrease',
  `variance` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_fuel_adjustments_station_date` (`station_id`,`adjustment_date`),
  KEY `idx_fuel_adjustments_status` (`status`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_fuel_type_id` (`fuel_type_id`),
  KEY `idx_approved_by_auto` (`approved_by`),
  KEY `fk_fa_adj_type` (`adjustment_type_id`),
  CONSTRAINT `fk_fa_adj_type` FOREIGN KEY (`adjustment_type_id`) REFERENCES `adjustment_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fa_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_adjustments_approved_by_9bc6` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_adjustments_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_adjustments_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_adjustments_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_adjustments`
--

LOCK TABLES `fuel_adjustments` WRITE;
/*!40000 ALTER TABLE `fuel_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_batches`
--

DROP TABLE IF EXISTS `fuel_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fuel_type_id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `batch_number` varchar(50) NOT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `quantity_received` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remaining_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `selling_price_per_liter` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `supplier` varchar(200) DEFAULT NULL,
  `date_received` date NOT NULL,
  `encoded_by` int(11) DEFAULT NULL,
  `status` enum('active','depleted','cancelled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fuel_type` (`fuel_type_id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`date_received`),
  KEY `idx_fuel_type_id` (`fuel_type_id`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_encoded_by` (`encoded_by`),
  CONSTRAINT `fk_fuel_batches_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_batches_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_batches_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_batches`
--

LOCK TABLES `fuel_batches` WRITE;
/*!40000 ALTER TABLE `fuel_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_calibration_records`
--

DROP TABLE IF EXISTS `fuel_calibration_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_calibration_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `calibration_liters` decimal(10,2) NOT NULL DEFAULT 0.00,
  `calibration_reason` varchar(255) DEFAULT NULL,
  `staff_id` int(11) NOT NULL,
  `calibration_date` datetime NOT NULL,
  `shift_period` varchar(50) DEFAULT NULL,
  `pump_number` varchar(20) DEFAULT NULL,
  `previous_reading` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station_fuel` (`station_id`,`fuel_type`),
  KEY `idx_calibration_date` (`calibration_date`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_fuel_calibration_records_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_calibration_records_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_calibration_records`
--

LOCK TABLES `fuel_calibration_records` WRITE;
/*!40000 ALTER TABLE `fuel_calibration_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_calibration_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_config_history`
--

DROP TABLE IF EXISTS `fuel_config_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_config_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `fuel_inventory_id` int(11) DEFAULT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_by_name` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `station_id` (`station_id`),
  KEY `fuel_inventory_id` (`fuel_inventory_id`),
  KEY `idx_fuel_config_history_updated_by` (`updated_by`),
  CONSTRAINT `fk_fch_inventory` FOREIGN KEY (`fuel_inventory_id`) REFERENCES `fuel_inventory` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fch_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fch_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_config_history`
--

LOCK TABLES `fuel_config_history` WRITE;
/*!40000 ALTER TABLE `fuel_config_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_config_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_deliveries`
--

DROP TABLE IF EXISTS `fuel_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `fuel_type` varchar(100) DEFAULT NULL,
  `supplier` varchar(100) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `delivery_liters` decimal(10,2) DEFAULT NULL,
  `tank_assigned` varchar(100) DEFAULT NULL,
  `tanker_number` varchar(50) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(60) NOT NULL DEFAULT 'Pending',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fuel_deliveries_station_date` (`station_id`,`delivery_date`),
  KEY `idx_fuel_deliveries_status` (`status`),
  KEY `idx_received_by_auto` (`received_by`),
  KEY `idx_verified_by_auto` (`verified_by`),
  CONSTRAINT `fk_fuel_deliveries_received_by_9bc6` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_deliveries_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_deliveries_verified_by_9bc6` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_deliveries`
--

LOCK TABLES `fuel_deliveries` WRITE;
/*!40000 ALTER TABLE `fuel_deliveries` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_deliveries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_inventory`
--

DROP TABLE IF EXISTS `fuel_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `fuel_type_id` int(11) NOT NULL,
  `current_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fuel_type` varchar(100) NOT NULL,
  `current_level` decimal(10,2) NOT NULL DEFAULT 0.00,
  `capacity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` decimal(10,2) DEFAULT 500.00,
  `critical_level` decimal(10,2) DEFAULT 200.00,
  `price_per_liter` decimal(10,2) NOT NULL DEFAULT 0.00,
  `latest_calibration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `calibration_date` datetime DEFAULT NULL,
  `calibration_staff` int(11) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `ugt_no` varchar(20) DEFAULT NULL COMMENT 'Underground Storage Tank number, e.g. UGT-01',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_station_fuel` (`station_id`,`fuel_type`),
  KEY `idx_station` (`station_id`),
  KEY `idx_fuel_type` (`fuel_type`),
  KEY `idx_fi_stock_alert` (`station_id`,`current_stock`,`reorder_level`),
  KEY `idx_fuel_type_id` (`fuel_type_id`),
  KEY `fk_fuel_inventory_updated_by` (`updated_by`),
  CONSTRAINT `fk_fuel_inv_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_inv_type` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_inventory_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_inventory_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_inventory_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_inventory`
--

LOCK TABLES `fuel_inventory` WRITE;
/*!40000 ALTER TABLE `fuel_inventory` DISABLE KEYS */;
INSERT INTO `fuel_inventory` VALUES (6,1253,10,0.00,'Diesel (UGT #1)',0.00,14000.00,5000.00,2500.00,89.00,0.00,NULL,NULL,'active','2026-08-20 07:44:45',4,'UGT #1'),(7,1253,11,0.00,'Turbo Diesel (UGT #5)',0.00,14000.00,2000.00,1000.00,80.00,0.00,NULL,NULL,'active','2026-08-13 17:55:40',3,'UGT #5'),(10,1253,14,0.00,'Kerosene (UGT #7)',0.00,14000.00,5000.00,2500.00,91.00,0.00,NULL,NULL,'active','2026-08-13 17:55:40',3,'UGT #7'),(31,1253,28,0.00,'Diesel 2 (UGT #2)',0.00,14000.00,5000.00,2500.00,89.00,0.00,NULL,NULL,'active','2026-08-02 15:25:50',4,'UGT #2'),(32,1253,29,0.00,'XCS Plus (UGT #3)',0.00,14000.00,5000.00,2500.00,82.00,0.00,NULL,NULL,'active','2026-08-15 06:01:25',4,'UGT #3'),(33,1253,30,0.00,'Xtra UNL 1 (UGT #4)',0.00,14000.00,2000.00,1000.00,72.00,0.00,NULL,NULL,'active','2026-08-20 07:44:44',4,'UGT #4'),(34,1253,31,0.00,'Xtra UNL 2 (UGT #6)',0.00,14000.00,5000.00,2500.00,72.00,0.00,NULL,NULL,'active','2026-08-02 10:10:34',4,'UGT #6');
/*!40000 ALTER TABLE `fuel_inventory` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER before_insert_fuel_inventory
        BEFORE INSERT ON fuel_inventory
        FOR EACH ROW
        BEGIN
            IF NEW.capacity IS NOT NULL AND NEW.capacity > 0 THEN
                IF NEW.current_level > NEW.capacity THEN
                    SET NEW.current_level = NEW.capacity;
                END IF;
                IF NEW.current_stock > NEW.capacity THEN
                    SET NEW.current_stock = NEW.capacity;
                END IF;
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER before_update_fuel_inventory
        BEFORE UPDATE ON fuel_inventory
        FOR EACH ROW
        BEGIN
            IF NEW.capacity IS NOT NULL AND NEW.capacity > 0 THEN
                IF NEW.current_level > NEW.capacity THEN
                    SET NEW.current_level = NEW.capacity;
                END IF;
                IF NEW.current_stock > NEW.capacity THEN
                    SET NEW.current_stock = NEW.capacity;
                END IF;
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `fuel_management_config`
--

DROP TABLE IF EXISTS `fuel_management_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_management_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `data_type` varchar(20) DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_management_config`
--

LOCK TABLES `fuel_management_config` WRITE;
/*!40000 ALTER TABLE `fuel_management_config` DISABLE KEYS */;
INSERT INTO `fuel_management_config` VALUES (1,'primary_color','#00264D','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(2,'accent_color','#CC0000','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(3,'success_color','#28A745','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(4,'warning_color','#FFC107','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(5,'danger_color','#DC3545','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(6,'info_color','#17A2B8','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(7,'secondary_color','#666666','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03'),(8,'bg_color','#F2F2F2','string',NULL,'2026-08-19 14:40:03','2026-08-19 14:40:03');
/*!40000 ALTER TABLE `fuel_management_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_price_history`
--

DROP TABLE IF EXISTS `fuel_price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `fuel_id` int(11) DEFAULT NULL,
  `fuel_type` varchar(100) DEFAULT NULL,
  `old_price` decimal(12,2) DEFAULT 0.00,
  `new_price` decimal(12,2) DEFAULT 0.00,
  `difference` decimal(12,2) DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_by_name` varchar(255) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Approved',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fuel_id` (`fuel_id`),
  KEY `idx_fuel_price_history_station_id` (`station_id`),
  KEY `idx_approved_by` (`approved_by`),
  KEY `idx_updated_by` (`updated_by`),
  CONSTRAINT `fk_fph_inventory` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_inventory` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fph_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_price_history_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_price_history_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_price_history`
--

LOCK TABLES `fuel_price_history` WRITE;
/*!40000 ALTER TABLE `fuel_price_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_price_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_price_log`
--

DROP TABLE IF EXISTS `fuel_price_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_price_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `fuel_type_id` int(11) DEFAULT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `old_price` decimal(10,4) NOT NULL,
  `new_price` decimal(10,4) NOT NULL,
  `price_difference` decimal(10,4) NOT NULL,
  `change_type` varchar(50) DEFAULT 'Price Update',
  `reason_for_change` text DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_by_name` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `change_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_fuel_type` (`fuel_type`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_timestamp` (`change_timestamp`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_fuel_type_id` (`fuel_type_id`),
  CONSTRAINT `fk_fuel_price_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_price_log_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_price_log_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_price_log`
--

LOCK TABLES `fuel_price_log` WRITE;
/*!40000 ALTER TABLE `fuel_price_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_price_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_pricing`
--

DROP TABLE IF EXISTS `fuel_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_pricing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `fuel_type_id` int(11) NOT NULL,
  `price_per_liter` decimal(10,2) NOT NULL,
  `effective_date` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station_fuel_active` (`station_id`,`fuel_type_id`,`is_active`),
  KEY `fuel_type_id` (`fuel_type_id`),
  KEY `fk_fuel_pricing_created_by` (`created_by`),
  CONSTRAINT `fk_fuel_pricing_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_pricing_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_pricing_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=7076 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_pricing`
--

LOCK TABLES `fuel_pricing` WRITE;
/*!40000 ALTER TABLE `fuel_pricing` DISABLE KEYS */;
INSERT INTO `fuel_pricing` VALUES (6261,1253,10,85.00,'2026-02-16 19:49:51',1,1,'2026-02-16 19:49:51','2026-07-30 15:27:40'),(6262,1253,11,79.50,'2026-02-16 19:49:51',1,1,'2026-02-16 19:49:51','2026-07-30 15:27:40'),(6265,1253,14,90.00,'2026-02-16 19:49:51',1,1,'2026-02-16 19:49:51','2026-07-30 15:27:40'),(7068,1253,27,89.00,'2026-07-30 15:27:40',1,1,'2026-07-30 15:27:40','2026-08-02 23:25:42'),(7069,1253,28,89.50,'2026-07-30 15:27:40',1,1,'2026-07-30 15:27:40','2026-08-02 21:42:44'),(7070,1253,29,82.00,'2026-07-30 15:27:40',1,1,'2026-07-30 15:27:40','2026-08-02 21:42:21'),(7071,1253,30,80.75,'2026-07-30 15:27:40',1,1,'2026-07-30 15:27:40','2026-07-30 15:27:40'),(7072,1253,31,80.75,'2026-07-30 15:27:40',1,1,'2026-07-30 15:27:40','2026-07-30 15:27:40'),(7073,1253,32,70.00,'2026-07-30 15:27:40',1,1,'2026-07-30 15:27:40','2026-07-30 15:27:40');
/*!40000 ALTER TABLE `fuel_pricing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_pumps`
--

DROP TABLE IF EXISTS `fuel_pumps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_pumps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `pump_number` varchar(20) NOT NULL,
  `fuel_type_id` int(11) NOT NULL,
  `capacity` decimal(10,2) DEFAULT 0.00,
  `status` enum('Active','Inactive','Maintenance') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `calibration_value` decimal(10,2) DEFAULT NULL,
  `calibration_updated_by` int(11) DEFAULT NULL,
  `calibration_updated_at` timestamp NULL DEFAULT NULL,
  `calibration_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pump_station` (`station_id`),
  KEY `fk_pump_fuel_type` (`fuel_type_id`),
  KEY `fk_fp_calibration_updated_by` (`calibration_updated_by`),
  KEY `idx_fp_station_calibration` (`station_id`,`calibration_updated_at`),
  KEY `idx_id` (`id`),
  KEY `idx_station_pump` (`station_id`,`pump_number`),
  CONSTRAINT `fk_fp_calibration_updated_by` FOREIGN KEY (`calibration_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_pumps_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_pumps_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_pumps_type` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24156 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_pumps`
--

LOCK TABLES `fuel_pumps` WRITE;
/*!40000 ALTER TABLE `fuel_pumps` DISABLE KEYS */;
INSERT INTO `fuel_pumps` VALUES (21419,1253,'DIESEL 1 - 1',10,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21420,1253,'DIESEL 1 - 2',10,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21421,1253,'DIESEL 1 - 3',10,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21422,1253,'DIESEL 1 - 4',10,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21423,1253,'DIESEL 2 - 5',10,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21424,1253,'DIESEL 2 - 6',10,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21425,1253,'KEROSENE - 1',14,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21426,1253,'TURBO DIESEL - 1',11,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21427,1253,'TURBO DIESEL - 2',11,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21428,1253,'XCS PLUS - 1',29,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21429,1253,'XCS PLUS - 2',29,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21430,1253,'XCS PLUS - 3',29,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21431,1253,'XCS PLUS - 4',29,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21432,1253,'XTRA UNL 1 - 1',30,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21433,1253,'XTRA UNL 1 - 2',30,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21434,1253,'XTRA UNL 2 - 3',31,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL),(21435,1253,'XTRA UNL 2 - 4',31,0.00,'Active','2026-07-02 21:44:40',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `fuel_pumps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_purchase_orders`
--

DROP TABLE IF EXISTS `fuel_purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) NOT NULL,
  `station_id` int(11) NOT NULL,
  `fuel_type_id` int(11) NOT NULL,
  `volume` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `supplier_id` int(11) NOT NULL DEFAULT 1,
  `expected_delivery_date` date NOT NULL,
  `actual_volume` decimal(10,2) DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `status` varchar(100) NOT NULL DEFAULT 'Approved PO',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `batch_id` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_fuel_type` (`fuel_type_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `fk_fpo_delivered_by` (`delivered_by`),
  KEY `fk_fuel_purchase_orders_approved_by` (`approved_by`),
  CONSTRAINT `fk_fpo_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fpo_delivered_by` FOREIGN KEY (`delivered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fpo_fuel_type` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fpo_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fpo_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fuel_purchase_orders_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_purchase_orders`
--

LOCK TABLES `fuel_purchase_orders` WRITE;
/*!40000 ALTER TABLE `fuel_purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_sales_closing`
--

DROP TABLE IF EXISTS `fuel_sales_closing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_sales_closing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `shift` varchar(50) NOT NULL,
  `shift_period` varchar(50) DEFAULT NULL,
  `total_fuel_sales` decimal(12,2) DEFAULT 0.00,
  `total_liters` decimal(10,2) DEFAULT 0.00,
  `diesel_sales` decimal(12,2) DEFAULT 0.00,
  `turbo_diesel_sales` decimal(12,2) DEFAULT 0.00,
  `xcs_plus_sales` decimal(12,2) DEFAULT 0.00,
  `xtra_advance_sales` decimal(12,2) DEFAULT 0.00,
  `kerosene_sales` decimal(12,2) DEFAULT 0.00,
  `olg_sales` decimal(12,2) DEFAULT 0.00,
  `tba_sales` decimal(12,2) DEFAULT 0.00,
  `service_income` decimal(12,2) DEFAULT 0.00,
  `other_sales` decimal(12,2) DEFAULT 0.00,
  `ar_collected` decimal(12,2) DEFAULT 0.00,
  `total_store_sales` decimal(12,2) DEFAULT 0.00,
  `cash_shift1` decimal(12,2) DEFAULT 0.00,
  `cash_shift2` decimal(12,2) DEFAULT 0.00,
  `total_cash` decimal(12,2) DEFAULT 0.00,
  `ar_shift1` decimal(12,2) DEFAULT 0.00,
  `ar_shift2` decimal(12,2) DEFAULT 0.00,
  `total_ar` decimal(12,2) DEFAULT 0.00,
  `gross_sales` decimal(12,2) DEFAULT 0.00,
  `expected_cash` decimal(12,2) DEFAULT 0.00,
  `total_cash_bank` decimal(12,2) DEFAULT 0.00,
  `encoded_by` int(11) NOT NULL,
  `encoded_at` datetime NOT NULL,
  `status` varchar(30) DEFAULT 'draft',
  `beginning_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_variance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `new_ar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `outstanding_ar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_ar_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `beginning_bank_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank_deposits` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank_movements` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_adjustments` decimal(12,2) NOT NULL DEFAULT 0.00,
  `overall_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `merchandise_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `job_order_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_sales_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_bank_in` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank_withdrawals` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `beginning_ar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ar_adjustments` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ar_voided` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ending_ar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_cash_in` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank_withdrawal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `verified_by` varchar(150) DEFAULT NULL,
  `checked_by` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `station_id` (`station_id`),
  KEY `report_date` (`report_date`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_encoded_by` (`encoded_by`),
  CONSTRAINT `fk_fuel_sales_closing_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_sales_closing_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_sales_closing`
--

LOCK TABLES `fuel_sales_closing` WRITE;
/*!40000 ALTER TABLE `fuel_sales_closing` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_sales_closing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_status_history`
--

DROP TABLE IF EXISTS `fuel_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `fuel_inventory_id` int(11) DEFAULT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `status` varchar(50) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_by_name` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `station_id` (`station_id`),
  KEY `fuel_inventory_id` (`fuel_inventory_id`),
  KEY `idx_changed_by` (`changed_by`),
  CONSTRAINT `fk_fsh_inventory` FOREIGN KEY (`fuel_inventory_id`) REFERENCES `fuel_inventory` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fsh_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_status_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_status_history`
--

LOCK TABLES `fuel_status_history` WRITE;
/*!40000 ALTER TABLE `fuel_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_stock_in`
--

DROP TABLE IF EXISTS `fuel_stock_in`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_stock_in` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_id` int(11) NOT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `fuel_type` varchar(255) NOT NULL,
  `qty_expected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty_received` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty_variance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `condition_flag` enum('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
  `remarks` text DEFAULT NULL,
  `level_before` decimal(12,2) NOT NULL DEFAULT 0.00,
  `level_after` decimal(12,2) NOT NULL DEFAULT 0.00,
  `encoded_by` int(11) NOT NULL,
  `encoded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `batch_ref` varchar(100) DEFAULT NULL,
  `delivery_ref` varchar(100) DEFAULT NULL,
  `selling_price_per_liter` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_encoded_at` (`encoded_at`),
  KEY `idx_delivery_id` (`delivery_id`),
  KEY `fk_fuel_stock_in_encoded_by` (`encoded_by`),
  CONSTRAINT `fk_fuel_stock_in_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_stock_in_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_stock_in`
--

LOCK TABLES `fuel_stock_in` WRITE;
/*!40000 ALTER TABLE `fuel_stock_in` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_stock_in` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_stock_request_audit`
--

DROP TABLE IF EXISTS `fuel_stock_request_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_stock_request_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_by_role` varchar(50) DEFAULT NULL,
  `old_status` varchar(100) DEFAULT NULL,
  `new_status` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fuel_req_id` (`request_id`),
  KEY `idx_request_id` (`request_id`),
  CONSTRAINT `fk_fuel_stock_request_audit_request_id` FOREIGN KEY (`request_id`) REFERENCES `stock_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_stock_request_audit`
--

LOCK TABLES `fuel_stock_request_audit` WRITE;
/*!40000 ALTER TABLE `fuel_stock_request_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_stock_request_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_stock_requests`
--

DROP TABLE IF EXISTS `fuel_stock_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_stock_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `current_level` decimal(12,2) NOT NULL DEFAULT 0.00,
  `capacity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock_status` varchar(30) NOT NULL DEFAULT 'LOW',
  `requested_liters` decimal(12,2) NOT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(100) DEFAULT 'Pending Manager Review',
  `approved_liters` decimal(12,2) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_manager_id` (`manager_id`),
  CONSTRAINT `fk_fuel_stock_requests_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_stock_requests_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_stock_requests_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_stock_requests`
--

LOCK TABLES `fuel_stock_requests` WRITE;
/*!40000 ALTER TABLE `fuel_stock_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_stock_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_suppliers`
--

DROP TABLE IF EXISTS `fuel_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `registration_details` text DEFAULT NULL,
  `delivery_terms` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `station_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `station_id` (`station_id`),
  CONSTRAINT `fuel_suppliers_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_suppliers`
--

LOCK TABLES `fuel_suppliers` WRITE;
/*!40000 ALTER TABLE `fuel_suppliers` DISABLE KEYS */;
INSERT INTO `fuel_suppliers` VALUES (1,'Petron Corporation','Petron CDO Sales & Supply Manager','(088) 856-4321 / +63 917 800 7387','cdo.orders@petron.com / contactus@petron.com','Petron Regional Depot & Sales Office, Zone 4, Carmen, Cagayan de Oro City, Misamis Oriental, 9000 Philippines','SEC Reg. No. 31171 | TIN: 000-168-801-000 | CDO Regional Branch','FOB Destination / Net 30 Days / CDO Local Tanker Lorry & Container Delivery',1,1253,'2026-07-01 12:13:52','2026-07-28 13:36:38'),(2,'Petron Corporation','Petron CDO Sales & Supply Manager','(088) 856-4321 / +63 917 800 7387','cdo.orders@petron.com / contactus@petron.com','Petron Regional Depot & Sales Office, Zone 4, Carmen, Cagayan de Oro City, Misamis Oriental, 9000 Philippines','SEC Reg. No. 31171 | TIN: 000-168-801-000 | CDO Regional Branch','FOB Destination / Net 30 Days / CDO Local Tanker Lorry & Container Delivery',1,1253,'2026-07-28 13:32:54','2026-08-18 15:51:37');
/*!40000 ALTER TABLE `fuel_suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_transactions`
--

DROP TABLE IF EXISTS `fuel_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(50) NOT NULL,
  `station_id` int(11) NOT NULL,
  `pump_id` int(11) DEFAULT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `present_reading` decimal(10,2) NOT NULL,
  `previous_reading` decimal(10,2) NOT NULL,
  `calibration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `staff_calibration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_per_liter` decimal(10,2) NOT NULL,
  `liters_sold` decimal(10,2) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Internal',
  `shift_period` varchar(50) NOT NULL DEFAULT 'general',
  `staff_id` int(11) NOT NULL,
  `transaction_date` datetime NOT NULL,
  `status` varchar(50) DEFAULT 'Pending Validation',
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `shift_name` varchar(100) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_fuel_type` (`fuel_type`),
  KEY `idx_pump_id` (`pump_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_id_ft` (`id`),
  KEY `idx_ft_customer_id` (`customer_id`),
  KEY `fk_fuel_transactions_manager_id` (`manager_id`),
  KEY `idx_validated_by` (`validated_by`),
  CONSTRAINT `fk_fuel_trans_pump` FOREIGN KEY (`pump_id`) REFERENCES `fuel_pumps` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_transactions_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_transactions_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_transactions_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_transactions_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_transactions_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_fuel_transactions_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_transactions`
--

LOCK TABLES `fuel_transactions` WRITE;
/*!40000 ALTER TABLE `fuel_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_types`
--

DROP TABLE IF EXISTS `fuel_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `price_per_liter` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fuel_type_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_types`
--

LOCK TABLES `fuel_types` WRITE;
/*!40000 ALTER TABLE `fuel_types` DISABLE KEYS */;
INSERT INTO `fuel_types` VALUES (10,'Diesel','Stock: 21,524.30 L | ???50.67',85.00),(11,'Turbo Diesel','Petron Fuel: Turbo Diesel',79.50),(14,'Kerosene','Petron Fuel: Kerosene',90.00),(27,'Diesel 1','Petron Fuel: Diesel 1',89.00),(28,'Diesel 2','Petron Fuel: Diesel 2',89.50),(29,'XCS Plus','Petron Fuel: XCS Plus',82.00),(30,'Xtra UNL 1','Petron Fuel: Xtra UNL 1',80.75),(31,'Xtra UNL 2','Petron Fuel: Xtra UNL 2',80.75),(32,'XTRA UNL',NULL,70.00);
/*!40000 ALTER TABLE `fuel_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_variance_reports`
--

DROP TABLE IF EXISTS `fuel_variance_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fuel_variance_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL DEFAULT 0,
  `pump_id` int(11) DEFAULT NULL,
  `tank_id` int(11) DEFAULT NULL,
  `variance_liters` decimal(12,2) NOT NULL DEFAULT 0.00,
  `variance_type` varchar(50) NOT NULL DEFAULT 'Daily Reconciliation',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station_created` (`station_id`,`created_at`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_pump_id` (`pump_id`),
  CONSTRAINT `fk_fuel_variance_reports_pump_id` FOREIGN KEY (`pump_id`) REFERENCES `fuel_pumps` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fuel_variance_reports_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_variance_reports`
--

LOCK TABLES `fuel_variance_reports` WRITE;
/*!40000 ALTER TABLE `fuel_variance_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_variance_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integration_audit`
--

DROP TABLE IF EXISTS `integration_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `integration_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action_type` varchar(80) NOT NULL DEFAULT 'Update',
  `endpoint_name` varchar(120) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_by_name` varchar(120) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_changed_by` (`changed_by`),
  CONSTRAINT `fk_integration_audit_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integration_audit`
--

LOCK TABLES `integration_audit` WRITE;
/*!40000 ALTER TABLE `integration_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `integration_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_logs`
--

DROP TABLE IF EXISTS `inventory_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL COMMENT 'stock_in, stock_out, adjustment, transfer',
  `movement_type` varchar(10) DEFAULT 'OUT',
  `reason` varchar(255) DEFAULT NULL,
  `quantity_before` decimal(12,2) DEFAULT 0.00,
  `quantity_after` decimal(12,2) DEFAULT 0.00,
  `quantity_change` decimal(12,2) DEFAULT 0.00,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'receiving_batch, sale, adjustment, transfer',
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_reference_id_auto` (`reference_id`),
  KEY `idx_inv_log_station` (`station_id`),
  KEY `idx_inv_log_prod` (`product_id`),
  KEY `idx_inv_log_ref` (`reference_no`),
  CONSTRAINT `fk2_inventory_logs_reference_id` FOREIGN KEY (`reference_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_logs_product_id` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_inventory_logs_reference_id_f911` FOREIGN KEY (`reference_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_logs_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_inventory_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_logs`
--

LOCK TABLES `inventory_logs` WRITE;
/*!40000 ALTER TABLE `inventory_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_products`
--

DROP TABLE IF EXISTS `inventory_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `unit` varchar(30) DEFAULT 'pcs',
  `description` text DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT 0.00,
  `unit_price` decimal(12,2) DEFAULT 0.00,
  `unit_cost` decimal(12,2) DEFAULT 0.00,
  `selling_price` decimal(12,2) DEFAULT 0.00,
  `stock` int(11) DEFAULT 0,
  `stock_quantity` int(11) DEFAULT 0,
  `min_stock` int(11) DEFAULT 5,
  `max_stock` int(11) DEFAULT 100,
  `reorder_level` int(11) DEFAULT 5,
  `critical_level` int(11) DEFAULT 10,
  `station_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','discontinued') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_sku` (`sku`),
  KEY `idx_station` (`station_id`),
  CONSTRAINT `fk_inventory_products_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=965 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_products`
--

LOCK TABLES `inventory_products` WRITE;
/*!40000 ALTER TABLE `inventory_products` DISABLE KEYS */;
INSERT INTO `inventory_products` VALUES (1,'Diesel','P0001',NULL,'Fuel Products','Diesel','L','pcs',NULL,0.00,0.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(2,'Turbo Diesel','P0002',NULL,'Fuel Products','Turbo','L','pcs',NULL,0.00,0.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(3,'XCS','P0003',NULL,'Fuel Products','Xcs','L','pcs',NULL,0.00,0.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(4,'Xtra Advance','P0004',NULL,'Fuel Products','Xtra','L','pcs',NULL,0.00,0.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(5,'Kerosene','P0005',NULL,'Fuel Products','Kerosene','L','pcs',NULL,0.00,0.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(6,'HD 10 (P/18)','P0006',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,1860.91,2658.44,1860.91,2658.44,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(7,'HD 30 (P/18)','P0007',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,1411.07,2015.82,1411.07,2015.82,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(8,'HD 40 (P/18)','P0008',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,1944.60,2778.00,1944.60,2778.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(9,'GEP 90 (P/18)','P0009',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,2242.80,3204.00,2242.80,3204.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(10,'GEP 140 (P/18)','P0010',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,2324.00,3320.00,2324.00,3320.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(11,'MP Grease (P/35)','P0011',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,3360.40,4800.57,3360.40,4800.57,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(12,'Hydrotur (P/18)','P0012',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,2119.60,3028.00,2119.60,3028.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(13,'Trekker (P/18)','P0013',NULL,'Oils/Lubes/Grease','Generic','Pail','Pail',NULL,2113.87,3019.81,2113.87,3019.81,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(14,'Ultron Touring (6/4)','P0014',NULL,'Oils/Lubes/Grease','Generic','Case','Case',NULL,556.75,795.36,556.75,795.36,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(15,'Ultron Extra (6/4)','P0015',NULL,'Oils/Lubes/Grease','Generic','Case','Case',NULL,477.40,682.00,477.40,682.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(16,'Blaze Racing Fully Synthetic (6/4)','P0016',NULL,'Oils/Lubes/Grease','Generic','Case','Case',NULL,931.70,1331.00,931.70,1331.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(17,'GEP 90 (6/4)','P0017',NULL,'Oils/Lubes/Grease','Generic','Case','Case',NULL,508.90,727.00,508.90,727.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(18,'GEP 140 (6/4)','P0018',NULL,'Oils/Lubes/Grease','Generic','Case','Case',NULL,520.80,744.00,520.80,744.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(22,'2T Powerburn (1L)','P0022',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,123.20,176.00,123.20,176.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(23,'All Terrain / Rev X Fully Syn (1L)','P0023',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,279.29,398.99,279.29,398.99,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(24,'All Terrain / Rev X Blend (1L)','P0024',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,280.00,400.00,280.00,400.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(25,'Ultron Touring (1L)','P0025',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,140.60,200.85,140.60,200.85,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(26,'Blaze Racing Blend (1L)','P0026',NULL,'Oils/Lubes/Grease','Blaze','Liter (L)','pcs',NULL,290.00,380.00,290.00,380.00,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(27,'Blaze Racing Fully Syn (1L)','P0027',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,284.20,406.00,284.20,406.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(28,'Blaze Racing Extra (1L)','P0028',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,123.20,176.00,123.20,176.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(29,'Rev-X Trekker (1L)','P0029',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,147.00,210.00,147.00,210.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(30,'HD 30 (1L)','P0030',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,111.30,159.00,111.30,159.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(31,'HD 40 (1L)','P0031',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,129.50,185.00,129.50,185.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(32,'MO 30 (1L)','P0032',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,115.50,165.00,115.50,165.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(33,'MO 40 (1L)','P0033',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,113.40,162.00,113.40,162.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(34,'ATF Premium (1L)','P0034',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,140.00,200.00,140.00,200.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(35,'ATF HTF (1L)','P0035',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,140.00,200.00,140.00,200.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(36,'GEP 90 (1L)','P0036',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,131.60,188.00,131.60,188.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(37,'GEP 140 (1L)','P0037',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,133.70,191.00,133.70,191.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(38,'Sprint 4T Rider (1L)','P0038',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,130.20,186.00,130.20,186.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(39,'Enduro (1L)','P0039',NULL,'Oils/Lubes/Grease','Generic','Liter (L)','Liter (L)',NULL,148.40,212.00,148.40,212.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(40,'MP Grease (0.05kg)','P0040',NULL,'Oils/Lubes/Grease','Generic','Kilogram (kg)','Kilogram (kg)',NULL,112.64,160.91,112.64,160.91,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(41,'MP Grease (2kg)','P0041',NULL,'Oils/Lubes/Grease','Generic','Kilogram (kg)','Kilogram (kg)',NULL,483.60,690.85,483.60,690.85,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(57,'Wiper Wash (2L)','P0057',NULL,'Car Accessories','Generic','Liter (L)','Liter (L)',NULL,0.00,300.00,0.00,200.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(76,'Nomis Oil Filter Spark','P0076',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,228.00,380.00,228.00,380.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(78,'Oil Filter C-101','P0078',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,147.00,245.00,147.00,245.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(79,'Oil Filter C-110','P0079',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,93.00,155.00,93.00,155.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(80,'Oil Filter C-111','P0080',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,100.80,168.00,100.80,168.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(81,'Oil Filter C-115','P0081',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,255.00,425.00,255.00,425.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(82,'Oil Filter C-226','P0082',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,226.80,378.00,226.80,378.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(83,'Oil Filter C-313','P0083',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,325.20,542.00,325.20,542.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(84,'Oil Filter C-502','P0084',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,139.20,232.00,139.20,232.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(85,'Oil Filter C-506','P0085',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,205.20,342.00,205.20,342.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(86,'Oil Filter C-512','P0086',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,136.80,228.00,136.80,228.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(91,'Oil Filter (Sorento) FO-2112','P0091',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,192.00,320.00,192.00,320.00,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(105,'Fuel Filter FC-017','P0105',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,412.20,687.00,412.20,687.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(108,'Oil Filter FES-5617','P0108',NULL,'Filters','Generic','Piece (pc)','Piece (pc)',NULL,171.00,285.00,171.00,285.00,0,0,24,100,24,10,1253,'','2026-08-03 01:00:04','2026-08-21 12:36:17'),(113,'Mineral Water (1L)','P0113',NULL,'Drinks/Food','Generic','Bottle','Bottle',NULL,9.73,13.90,9.73,13.90,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(114,'Mineral Water (500ml)','P0114',NULL,'Drinks/Food','Generic','Bottle','Bottle',NULL,6.00,8.57,6.00,8.57,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(121,'Chippy Big','P0121',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,23.40,26.91,23.40,26.91,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-21 12:36:17'),(124,'Clover Big','P0124',NULL,'Snacks','Generic','Bag','Bag',NULL,16.91,24.15,16.91,24.15,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(127,'Choco Mucho Cookies','P0127',NULL,'Snacks','Rebisco','pcs','pcs',NULL,7.29,8.38,7.29,8.38,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-21 12:36:17'),(132,'LM Sotanghon','P0132',NULL,'Snacks','Generic','Pack','Pack',NULL,18.06,25.80,18.06,25.80,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(133,'LM Jjampong Small','P0133',NULL,'Snacks','Generic','Pack','Pack',NULL,18.03,25.75,18.03,25.75,0,0,24,100,24,10,1253,'active','2026-08-03 01:00:04','2026-08-21 12:36:17'),(697,'Oil Saver (425ml)','',NULL,'Merchandise',NULL,NULL,'pcs',NULL,110.00,150.00,110.00,150.00,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(698,'Engine Flush - Petron (500ml)','',NULL,'Merchandise',NULL,NULL,'pcs',NULL,110.00,150.00,110.00,150.00,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(699,'Engine Flush - Hardex (440ml)','',NULL,'Merchandise',NULL,NULL,'pcs',NULL,110.00,150.00,110.00,150.00,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(700,'Whiz Oil Treatment','',NULL,'Car Accessories','Whiz','pcs','pcs',NULL,190.00,190.00,190.00,190.00,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-21 12:36:17'),(743,'Whiz Oil Treatment Plus (425ml)','',NULL,'Merchandise',NULL,NULL,'pcs',NULL,110.00,150.00,110.00,150.00,0,0,24,480,24,10,1253,'active','2026-07-27 20:21:48','2026-08-18 23:51:28'),(744,'Royal (1.5L)','P0112-R',NULL,'Drinks/Food','Royal','1.5LTR','1.5LTR',NULL,63.87,63.87,63.87,63.87,0,0,24,480,24,10,1253,'active','2026-07-28 02:36:40','2026-08-21 12:36:17'),(745,'Sprite Mismo (500ml)','P0110-S',NULL,'Drinks/Food','Sprite','Bottle','pcs',NULL,0.00,16.75,11.73,16.75,0,0,24,480,24,10,1253,'active','2026-07-28 02:36:40','2026-08-21 12:36:17'),(746,'Royal Mismo (500ml)','P0110-R',NULL,'Drinks/Food','Royal','Bottle','pcs',NULL,0.00,16.75,11.73,16.75,0,0,24,480,24,10,1253,'active','2026-07-28 02:36:40','2026-08-21 12:36:17'),(747,'Sprite Swakto (200ml)','P0111-S',NULL,'Drinks/Food','Sprite','Bottle','pcs',NULL,0.00,12.25,8.58,12.25,0,0,24,480,24,10,1253,'active','2026-07-28 02:36:40','2026-08-21 12:36:17'),(748,'Royal Swakto (200ml)','P0111-R',NULL,'Drinks/Food','Royal','Bottle','pcs',NULL,0.00,12.25,8.58,12.25,0,0,24,480,24,10,1253,'active','2026-07-28 02:36:40','2026-08-21 12:36:17'),(749,'Coca-Cola Swakto (200ml)','',NULL,'Merchandise',NULL,'P/18','P/18',NULL,0.00,3057.21,2658.44,3057.21,0,0,5,100,5,10,1253,'active','2026-07-28 21:54:14','2026-08-21 12:36:17'),(750,'Oil Filter C-513','',NULL,'Oils/Lubes/Grease','Petron','24/1','24/1',NULL,159.00,2318.19,159.00,2318.19,0,0,5,100,5,10,1253,'active','2026-07-30 09:35:59','2026-08-21 12:36:17'),(751,'HD 40','P1003',NULL,'Oils/Lubes/Grease','Petron','24/1','24/1',NULL,185.00,3194.70,185.00,3194.70,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(752,'GEP 90','P1004',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,188.00,3684.60,188.00,3684.60,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(753,'GEP 140','P1005',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,191.00,3818.00,191.00,3818.00,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(754,'MP GREASE','P1006',NULL,'Oils/Lubes/Grease','Petron','2KG','2KG',NULL,690.85,5520.66,690.85,5520.66,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(755,'HYDROTUR','P1007',NULL,'Oils/Lubes/Grease','Petron','P/18','P/18',NULL,3028.00,3482.20,3028.00,3482.20,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(756,'TREKKER','P1008',NULL,'Oils/Lubes/Grease','Petron','6/4','6/4',NULL,820.73,3472.78,820.73,3472.78,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(757,'ULTRON TOURING','P1009',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,200.85,914.66,200.85,914.66,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(758,'ULTRON EXTRA','P1010',NULL,'Oils/Lubes/Grease','Petron','6/4','6/4',NULL,682.00,784.30,682.00,784.30,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(759,'BLAZE RACING FULLY SYNTHETIC','P1011',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,406.00,1530.65,406.00,1530.65,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(760,'2T AUTOLUBE','P1012',NULL,'Oils/Lubes/Grease','Petron','60/200ml','60/200ml',NULL,37.00,50.00,37.00,42.55,0,0,24,10000,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(761,'2T POWERBURN','P1013',NULL,'Oils/Lubes/Grease','Petron','24/1','24/1',NULL,176.00,40.25,176.00,40.25,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(762,'SPRINT 4T RIDER','P1014',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,186.00,39.56,186.00,39.56,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(763,'ALL TERRAIN / REV X FULLY SYN','P1015',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,398.99,458.84,398.99,458.84,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(764,'ALL TERRAIN / REV X SYNTHETIC BLEND','P1016',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,400.00,460.00,400.00,460.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(765,'BLAZE RACING SYNTHETIC BLEND','P1017',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,381.00,438.15,381.00,438.15,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(766,'BLAZE RACING EXTRA','P1018',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,176.00,202.40,176.00,202.40,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(767,'REV-X TREKKER','P1019',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,210.00,241.50,210.00,241.50,0,0,24,100,24,10,1253,'','2026-08-03 00:49:10','2026-08-21 12:36:17'),(768,'M O 30','P1020',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,165.00,189.75,165.00,189.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(769,'M O 40','P1021',NULL,'Oils/Lubes/Grease','Petron','24/1','24/1',NULL,162.00,186.30,162.00,186.30,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(770,'ATF PREMIUM','P1022',NULL,'Oils/Lubes/Grease','Petron','24/1','24/1',NULL,200.00,230.00,200.00,230.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(771,'ATF HTF','P1023',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,200.00,230.00,200.00,230.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:10','2026-08-21 12:36:17'),(772,'ENDURO','P1024',NULL,'Oils/Lubes/Grease','Petron','12/1','12/1',NULL,212.00,243.80,212.00,243.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:11','2026-08-21 12:36:17'),(773,'OIL SAVER','P1025',NULL,'Car Accessories','Petron','425ml','425ml',NULL,135.03,155.28,135.03,155.28,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:11','2026-08-21 12:36:17'),(774,'ENGINE FLUSH - PETRON','P1026',NULL,'Car Accessories','Petron','500ml','500ml',NULL,148.00,170.20,148.00,170.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:11','2026-08-21 12:36:17'),(775,'ENGINE FLUSH - HARDEX','P1027',NULL,'Car Accessories','Hardex','440ml','440ml',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:11','2026-08-07 22:07:42'),(776,'BLUE SPRAY WASHER FLUID','P1028',NULL,'Car Accessories','Generic','100ml','100ml',NULL,85.00,97.75,85.00,97.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:11','2026-08-21 12:36:17'),(777,'HD 10','P5001',NULL,'Oils/Lubes/Grease','Petron','P/18','P/18',NULL,2658.44,3057.21,2658.44,3057.21,0,0,24,100,24,10,1253,'','2026-08-03 00:49:58','2026-08-21 12:36:17'),(778,'HD 30','P5002',NULL,'Oils/Lubes/Grease','Petron','24/1','24/1',NULL,159.00,2318.19,159.00,2318.19,0,0,24,100,24,10,1253,'','2026-08-03 00:49:58','2026-08-21 12:36:17'),(779,'COOLANT','P5003',NULL,'Car Accessories','Generic','500ml','500ml',NULL,102.00,117.30,102.00,117.30,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(780,'COOLANT GREEN','P5004',NULL,'Car Accessories','Coolant','1L','1L',NULL,143.00,200.00,143.00,164.45,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(781,'COOLANT PINK','P5005',NULL,'Car Accessories','Generic','1L','1L',NULL,143.00,164.45,143.00,164.45,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(782,'WD 40 191ML','P5006',NULL,'Car Accessories','WD-40','6oz','6oz',NULL,210.00,241.50,210.00,241.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(783,'SILICON OIL','P5007',NULL,'Car Accessories','Generic','pcs','pcs',NULL,88.00,101.20,88.00,101.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(784,'PETROMATE PENETRATING OIL','P5008',NULL,'Car Accessories','Petron','450ml','450ml',NULL,178.00,204.70,178.00,204.70,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(785,'TIRE BLACK SM.','P5009',NULL,'Car Accessories','Generic','pcs','pcs',NULL,85.00,97.75,85.00,97.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(786,'TIRE BLACK BIG','P5010',NULL,'Car Accessories','Generic','pcs','pcs',NULL,185.00,212.75,185.00,212.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(787,'TURTLE WAX HARD SHELL (SOFT PASTE)','P5011',NULL,'Car Accessories','Turtle Wax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(788,'TURTLE WAX HARD LIQUID','P5012',NULL,'Car Accessories','Turtle Wax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(789,'POWER BOOSTER','P5013',NULL,'Car Accessories','Generic','pcs','pcs',NULL,51.00,58.65,51.00,58.65,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(790,'CLEAN N SHINE SHAMPOO','P5014',NULL,'Car Accessories','Generic','pcs','pcs',NULL,71.69,82.44,71.69,82.44,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(791,'VS 1 PROTECTOR SMALL','P5015',NULL,'Car Accessories','VS1','pcs','pcs',NULL,140.00,161.00,140.00,161.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(792,'VS 1 PROTECTOR BIG','P5016',NULL,'Car Accessories','VS1','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(793,'ARMOR ALL SM','P5017',NULL,'Car Accessories','Armor All','pcs','pcs',NULL,140.00,200.00,150.00,161.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(794,'ARMOR ALL BIG','P5018',NULL,'Car Accessories','Armor','Piece (pc)','pcs',NULL,285.00,400.00,0.00,327.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(795,'WIPER WASH','P5019',NULL,'Car Accessories','Wiper','2L','2L',NULL,185.00,212.75,185.00,212.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(796,'GAS SAVER','P5020',NULL,'Car Accessories','Generic','pcs','pcs',NULL,57.37,65.98,57.37,65.98,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(797,'NEO SHALDAN','P5021',NULL,'Car Accessories','Shaldan','pcs','pcs',NULL,155.00,178.25,155.00,178.25,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(798,'TOPIAS FRESHENER','P5022',NULL,'Car Accessories','Topias','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(799,'LITTLE TREES','P5023',NULL,'Car Accessories','Little Trees','pcs','pcs',NULL,50.00,57.50,50.00,57.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(800,'CALIFORNIA SCENTS','P5024',NULL,'Car Accessories','California Scents','pcs','pcs',NULL,195.00,224.25,195.00,224.25,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(801,'GLADE SPRAY','P5025',NULL,'Car Accessories','Glade','pcs','pcs',NULL,245.00,281.75,245.00,281.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(802,'BRAKE FLUID 900 ML','P5026',NULL,'Car Accessories','Petron','900ml','900ml',NULL,268.00,308.20,268.00,308.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(803,'BRAKE FLUID MED','P5027',NULL,'Car Accessories','Petron','500ml','500ml',NULL,89.00,102.35,89.00,102.35,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(804,'BRAKE FLUID SM.','P5028',NULL,'Car Accessories','Petron','250ml','250ml',NULL,59.00,67.85,59.00,67.85,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(805,'TIRE VALVE RUBBER','P5029',NULL,'Car Accessories','Generic','pcs','pcs',NULL,12.00,13.80,12.00,13.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(806,'TIRE VALVE STEEL','P5030',NULL,'Car Accessories','Generic','pcs','pcs',NULL,60.00,69.00,60.00,69.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(807,'RZ AUTO TIRE SEAL','P5031',NULL,'Car Accessories','RZ','pcs','pcs',NULL,320.00,368.00,320.00,368.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(808,'GASKET MAKER','P5032',NULL,'Car Accessories','Generic','pcs','pcs',NULL,55.00,63.25,55.00,63.25,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(809,'CHAMOIS / KANEBO','P5033',NULL,'Car Accessories','Kanebo','pcs','pcs',NULL,330.00,379.50,330.00,379.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(810,'PATCH #11','P5034',NULL,'Car Accessories','Generic','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(811,'PATCH #12','P5035',NULL,'Car Accessories','Generic','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(812,'BRAKE CLEANER HARDEX','P5036',NULL,'Car Accessories','Hardex','400ml','400ml',NULL,245.00,281.75,245.00,281.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(813,'VALKARN CEMENT','P5037',NULL,'Car Accessories','Valkarn','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(814,'MP 1 (MED) PATCH','P5038',NULL,'Car Accessories','Generic','pcs','pcs',NULL,42.50,48.88,42.50,48.88,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(815,'MP 2 (LARGE) PATCH','P5039',NULL,'Car Accessories','Generic','pcs','pcs',NULL,132.00,151.80,132.00,151.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(816,'CT 20 RADIAL PATCH','P5040',NULL,'Car Accessories','Generic','pcs','pcs',NULL,132.00,151.80,132.00,151.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(817,'SAKURA F-1508','P5041',NULL,'Filters','Sakura','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(818,'SAKURA FC-1510','P5042',NULL,'Filters','Sakura','pcs','pcs',NULL,510.00,586.50,510.00,586.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(819,'OIL FILTER SPARK- 65400','P5043',NULL,'Filters','Spark','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-07 22:07:42'),(820,'NOMIS OIL FILTER SPARK-NLT 060','P5044',NULL,'Filters','Nomis','pcs','pcs',NULL,380.00,437.00,380.00,437.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(821,'VIC OIL FILTER C-034','P5045',NULL,'Filters','VIC','pcs','pcs',NULL,279.00,320.85,279.00,320.85,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(822,'VIC OIL FILTER C-101','P5046',NULL,'Filters','VIC','pcs','pcs',NULL,245.00,281.75,245.00,281.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(823,'VIC OIL FILTER C-106','P5047',NULL,'Filters','VIC','pcs','pcs',NULL,168.00,193.20,168.00,193.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:49:59','2026-08-21 12:36:17'),(824,'VIC OIL FILTER C-110','P5048',NULL,'Filters','VIC','pcs','pcs',NULL,155.00,178.25,155.00,178.25,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(825,'VIC OIL FILTER C-111','P5049',NULL,'Filters','VIC','pcs','pcs',NULL,168.00,193.20,168.00,193.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(826,'VIC OIL FILTER C-112','P5050',NULL,'Filters','VIC','pcs','pcs',NULL,372.00,427.80,372.00,427.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(827,'VIC OIL FILTER C-115','P5051',NULL,'Filters','VIC','pcs','pcs',NULL,425.00,488.75,425.00,488.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(828,'VIC OIL FILTER O-119','P5052',NULL,'Filters','VIC','pcs','pcs',NULL,168.00,193.20,168.00,193.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(829,'VIC OIL FILTER C-204','P5053',NULL,'Filters','VIC','pcs','pcs',NULL,241.00,277.15,241.00,277.15,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(830,'VIC OIL FILTER C-206','P5054',NULL,'Filters','VIC','pcs','pcs',NULL,223.00,256.45,223.00,256.45,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(831,'VIC OIL FILTER C-207','P5055',NULL,'Filters','VIC','pcs','pcs',NULL,177.00,203.55,177.00,203.55,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(832,'VIC OIL FILTER C-209','P5056',NULL,'Filters','VIC','pcs','pcs',NULL,238.00,273.70,238.00,273.70,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(833,'VIC OIL FILTER C-226','P5057',NULL,'Filters','VIC','pcs','pcs',NULL,378.00,434.70,378.00,434.70,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(834,'VIC OIL FILTER C-303','P5058',NULL,'Filters','VIC','pcs','pcs',NULL,168.00,193.20,168.00,193.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(835,'VIC OIL FILTER C-304','P5059',NULL,'Filters','VIC','pcs','pcs',NULL,166.00,190.90,166.00,190.90,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(836,'VIC OIL FILTER C-305','P5060',NULL,'Filters','VIC','pcs','pcs',NULL,410.00,471.50,410.00,471.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(837,'VIC OIL FILTER C-306','P5061',NULL,'Filters','VIC','pcs','pcs',NULL,434.00,499.10,434.00,499.10,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(838,'VIC OIL FILTER C-312','P5062',NULL,'Filters','VIC','pcs','pcs',NULL,172.00,197.80,172.00,197.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(839,'VIC OIL FILTER C-313','P5063',NULL,'Filters','VIC','pcs','pcs',NULL,542.00,623.30,542.00,623.30,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(840,'VIC OIL FILTER C-405','P5064',NULL,'Filters','VIC','pcs','pcs',NULL,168.00,193.20,168.00,193.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(841,'VIC OIL FILTER C-406','P5065',NULL,'Filters','VIC','pcs','pcs',NULL,168.00,193.20,168.00,193.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(842,'VIC OIL FILTER O-407 A','P5066',NULL,'Filters','VIC','pcs','pcs',NULL,281.00,323.15,281.00,323.15,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(843,'VIC OIL FILTER C-412','P5067',NULL,'Filters','VIC','pcs','pcs',NULL,422.00,485.30,422.00,485.30,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(844,'VIC OIL FILTER C-415','P5068',NULL,'Filters','VIC','pcs','pcs',NULL,161.00,185.15,161.00,185.15,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(845,'VIC OIL FILTER C-502','P5069',NULL,'Filters','VIC','pcs','pcs',NULL,232.00,266.80,232.00,266.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(846,'VIC OIL FILTER C-503','P5070',NULL,'Filters','VIC','pcs','pcs',NULL,284.00,326.60,284.00,326.60,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(847,'VIC OIL FILTER C-506','P5071',NULL,'Filters','VIC','pcs','pcs',NULL,342.00,393.30,342.00,393.30,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(848,'VIC OIL FILTER C-512','P5072',NULL,'Filters','VIC','pcs','pcs',NULL,228.00,262.20,228.00,262.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(849,'VIC OIL FILTER C-513','P5073',NULL,'Filters','VIC','pcs','pcs',NULL,190.00,218.50,190.00,218.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(850,'VIC OIL FILTER C-519','P5074',NULL,'Filters','VIC','pcs','pcs',NULL,496.00,570.40,496.00,570.40,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(851,'VIC OIL FILTER C-524','P5075',NULL,'Filters','VIC','pcs','pcs',NULL,250.00,287.50,250.00,287.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(852,'VIC OIL FILTER C-526','P5076',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-07 22:07:42'),(853,'VIC OIL FILTER C-527','P5077',NULL,'Filters','VIC','pcs','pcs',NULL,208.00,239.20,208.00,239.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(854,'VIC OIL FILTER C-529','P5078',NULL,'Filters','VIC','pcs','pcs',NULL,273.00,313.95,273.00,313.95,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(855,'VIC OIL FILTER O-586','P5079',NULL,'Filters','VIC','pcs','pcs',NULL,294.00,338.10,294.00,338.10,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(856,'VIC OIL FILTER C-932','P5080',NULL,'Filters','VIC','pcs','pcs',NULL,149.00,171.35,149.00,171.35,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(857,'VIC OIL FILTER STAREX / HYUNDAI','P5081',NULL,'Filters','VIC','pcs','pcs',NULL,280.00,322.00,280.00,322.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(858,'VIC FUEL FILTER FC-158','P5082',NULL,'Filters','VIC','pcs','pcs',NULL,572.00,657.80,572.00,657.80,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(859,'VIC FUEL FILTER F-193','P5083',NULL,'Filters','VIC','pcs','pcs',NULL,238.00,273.70,238.00,273.70,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(860,'VIC FUEL FILTER FC-208A','P5084',NULL,'Filters','VIC','pcs','pcs',NULL,219.00,251.85,219.00,251.85,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(861,'VIC FUEL FILTER FC-317','P5085',NULL,'Filters','VIC','pcs','pcs',NULL,269.00,309.35,269.00,309.35,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(862,'VIC FUEL FILTER FC-319','P5086',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-07 22:07:42'),(863,'VIC FUEL FILTER FC-321','P5087',NULL,'Filters','VIC','pcs','pcs',NULL,657.00,755.55,657.00,755.55,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(864,'VIC FUEL FILTER FC-510','P5088',NULL,'Filters','VIC','pcs','pcs',NULL,633.00,727.95,633.00,727.95,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(865,'VIC FUEL FILTER FC-234','P5089',NULL,'Filters','VIC','pcs','pcs',NULL,738.00,848.70,738.00,848.70,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(866,'VIC FUEL FILTER FC-235','P5090',NULL,'Filters','VIC','pcs','pcs',NULL,676.00,777.40,676.00,777.40,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(867,'OIL FILTER FES 5321','P5091',NULL,'Filters','Fleetmax','pcs','pcs',NULL,270.00,310.50,270.00,310.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(868,'OIL FILTER FES 5640','P5092',NULL,'Filters','Fleetmax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-07 22:07:42'),(869,'OIL FILTER (SORENTO) FO-2112/27420','P5093',NULL,'Filters','Generic','pcs','pcs',NULL,320.00,368.00,320.00,368.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(870,'OIL FILTER FES 5712','P5094',NULL,'Filters','Fleetmax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-07 22:07:42'),(871,'OIL FILTER FES 5342','P5095',NULL,'Filters','Fleetmax','pcs','pcs',NULL,270.00,310.50,270.00,310.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(872,'OIL FILTER C-525','P5096',NULL,'Filters','VIC','pcs','pcs',NULL,1108.00,1274.20,1108.00,1274.20,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(873,'FUEL FILTER FFS-1530','P5097',NULL,'Filters','Fleetmax','pcs','pcs',NULL,520.00,598.00,520.00,598.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(874,'FUEL FILTER FFS-1501','P5098',NULL,'Filters','Fleetmax','pcs','pcs',NULL,435.00,500.25,435.00,500.25,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(875,'HOWO FUEL FILTER VG1560080012','P5099',NULL,'Filters','Howo','pcs','pcs',NULL,450.00,517.50,450.00,517.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(876,'HOWO OIL FILTER-186 1012000','P5100',NULL,'Filters','Howo','pcs','pcs',NULL,520.00,598.00,520.00,598.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(877,'OIL FILTER C-223','P5101',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(878,'OIL FILTER C-509A','P5102',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(879,'OIL FILTER C-510A','P5103',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(880,'FUEL FILTER FC-322','P5104',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(881,'FUEL FILTER FC-326','P5105',NULL,'Filters','VIC','pcs','pcs',NULL,318.00,365.70,318.00,365.70,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(882,'OIL FILTER DAI-WA DU 581','P5106',NULL,'Filters','Dai-Wa','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(883,'OIL FILTER YO-581','P5107',NULL,'Filters','Generic','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(884,'OIL FILTER EO-581','P5108',NULL,'Filters','Generic','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-07 22:07:42'),(885,'OIL FILTER EO-568','P5109',NULL,'Filters','Generic','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-07 22:07:42'),(886,'OIL FILTER O-1012 S','P5110',NULL,'Filters','Generic','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(887,'OIL FILTER 94797406','P5111',NULL,'Filters','Generic','pcs','pcs',NULL,350.00,402.50,350.00,402.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:00','2026-08-21 12:36:17'),(888,'FUJIITO 5262313','P5112',NULL,'Filters','Fujiito','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(889,'FUJIITO 5266016','P5113',NULL,'Filters','Fujiito','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(890,'FUJIITO 5262311','P5114',NULL,'Filters','Fujiito','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(891,'FUJIITO 5264870','P5115',NULL,'Filters','Fujiito','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(892,'FLEETMAX FES 5715','P5116',NULL,'Filters','Fleetmax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-07 22:07:42'),(893,'FLEETMAX FES 5714','P5117',NULL,'Filters','Fleetmax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-07 22:07:42'),(894,'FLEETMAX FES 5708','P5118',NULL,'Filters','Fleetmax','pcs','pcs',NULL,370.00,425.50,370.00,425.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(895,'OIL FILTER FES 5583','P5119',NULL,'Filters','Fleetmax','pcs','pcs',NULL,350.00,402.50,350.00,402.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(896,'OIL FILTER C-419','P5120',NULL,'Filters','VIC','pcs','pcs',NULL,921.00,1059.15,921.00,1059.15,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(897,'OIL FILTER C-231','P5121',NULL,'Filters','VIC','pcs','pcs',NULL,241.00,277.15,241.00,277.15,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(898,'OIL FILTER C-232','P5122',NULL,'Filters','VIC','pcs','pcs',NULL,191.00,219.65,191.00,219.65,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(899,'OIL FILTER C-707','P5123',NULL,'Filters','VIC','pcs','pcs',NULL,153.00,175.95,153.00,175.95,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(900,'OIL FILTER O-010','P5124',NULL,'Filters','VIC','pcs','pcs',NULL,526.00,604.90,526.00,604.90,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(901,'OIL FILTER O-008','P5125',NULL,'Filters','VIC','pcs','pcs',NULL,316.00,363.40,316.00,363.40,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(902,'OIL FILTER C-039','P5126',NULL,'Filters','VIC','pcs','pcs',NULL,243.00,279.45,243.00,279.45,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(903,'OIL FILTER FES-5583 CAMRY','P5127',NULL,'Filters','Fleetmax','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(904,'OIL FILTER C-117 MG','P5128',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-07 22:07:42'),(905,'OIL FILTER C -010','P5129',NULL,'Filters','VIC','pcs','pcs',NULL,180.00,250.00,180.00,250.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-07 22:07:42'),(906,'Fuel Filter FC-017','P5130',NULL,'Filters','VIC','pcs','pcs',NULL,687.00,790.05,687.00,790.05,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(907,'FUEL FILTER F-197','P5131',NULL,'Filters','VIC','pcs','pcs',NULL,453.00,520.95,453.00,520.95,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(908,'FUEL FILTER FFS-1478 (NAVARA)','P5132',NULL,'Filters','Fleetmax','pcs','pcs',NULL,840.00,966.00,840.00,966.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(909,'Oil Filter FES-5617','P5133',NULL,'Filters','Fleetmax','pcs','pcs',NULL,285.00,327.75,285.00,327.75,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(910,'OIL FILTER C-5614','P5134',NULL,'Filters','VIC','pcs','pcs',NULL,450.00,517.50,450.00,517.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(911,'Coca-Cola Mismo','P5135',NULL,'Drinks/Food','Coca-Cola','MISMO','MISMO',NULL,16.75,19.26,16.75,19.26,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(912,'Sprite Mismo','P5136',NULL,'Drinks/Food','Sprite','MISMO','MISMO',NULL,16.75,19.26,16.75,19.26,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(913,'Royal Mismo','P5137',NULL,'Drinks/Food','Royal','MISMO','MISMO',NULL,16.75,19.26,16.75,19.26,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(914,'Coca-Cola Swakto','P5138',NULL,'Drinks/Food','Coca-Cola','SWAKTO','SWAKTO',NULL,12.25,14.09,12.25,14.09,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(915,'Sprite Swakto','P5139',NULL,'Drinks/Food','Sprite','SWAKTO','SWAKTO',NULL,12.25,14.09,12.25,14.09,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(916,'Royal Swakto','P5140',NULL,'Drinks/Food','Royal','SWAKTO','SWAKTO',NULL,12.25,14.09,12.25,14.09,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(917,'Coca-Cola (1.5L)','P5141',NULL,'Drinks/Food','Coca-Cola','1.5LTR','1.5LTR',NULL,63.87,73.45,63.87,73.45,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(918,'Sprite (1.5L)','P5142',NULL,'Drinks/Food','Sprite','1.5LTR','1.5LTR',NULL,63.87,73.45,63.87,73.45,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(919,'Mineral Water - Nature\'s Spring','P5143',NULL,'Drinks/Food','Nature\'s Spring','500ML','500ML',NULL,8.57,15.99,8.57,15.99,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(920,'Gatorade','P5144',NULL,'Drinks/Food','Gatorade','pcs','pcs',NULL,41.30,47.50,41.30,47.50,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(921,'Skyflakes Singles','P5145',NULL,'Snacks','Skyflakes','pcs','pcs',NULL,5.41,6.22,5.41,6.22,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(922,'Presto Singles','P5146',NULL,'Snacks','Presto','pcs','pcs',NULL,5.84,6.72,5.84,6.72,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(923,'Presto Slugs','P5147',NULL,'Snacks','Presto','pcs','pcs',NULL,17.75,20.41,17.75,20.41,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(924,'Butter Coconut Slugs','P5148',NULL,'Snacks','Monde','pcs','pcs',NULL,21.95,25.24,21.95,25.24,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(925,'Sweetcorn Big','P5149',NULL,'Snacks','Sweetcorn','pcs','pcs',NULL,15.85,18.23,15.85,18.23,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(926,'Fita Singles','P5150',NULL,'Snacks','MY San','pcs','pcs',NULL,5.94,6.83,5.94,6.83,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-21 12:36:17'),(927,'Fita Slugs','P5151',NULL,'Snacks','MY San','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:01','2026-08-07 22:07:42'),(928,'Oishi Prawn Crackers','P5152',NULL,'Snacks','Oishi','pcs','pcs',NULL,15.15,17.42,15.15,17.42,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(929,'Clover Chips Big','P5153',NULL,'Snacks','Leslie\'s','BIG','BIG',NULL,24.15,27.77,24.15,27.77,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(930,'Piattos Big','P5154',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,33.20,38.18,33.20,38.18,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(931,'Roller Coaster','P5155',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,23.85,27.43,23.85,27.43,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(932,'Cheese Ring Big','P5156',NULL,'Snacks','Regent','pcs','pcs',NULL,15.80,18.17,15.80,18.17,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(933,'Chiz Curls Big','P5157',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,19.55,22.48,19.55,22.48,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(934,'Breadstix Family','P5158',NULL,'Snacks','MY San','pcs','pcs',NULL,33.90,38.99,33.90,38.99,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(935,'Potato Fries','P5159',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,14.70,16.91,14.70,16.91,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(936,'Snacku Big','P5160',NULL,'Snacks','Regent','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(937,'Snacku Small','P5161',NULL,'Snacks','Regent','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(938,'Cream O 360g','P5162',NULL,'Snacks','Universal Robina','360g','360g',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(939,'Cream O 330g','P5163',NULL,'Snacks','Universal Robina','330g','330g',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(940,'Eggnog','P5164',NULL,'Snacks','Monde','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(941,'Mr. Chips Big','P5165',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(942,'Lucky Me Sotanghon','P5166',NULL,'Drinks/Food','Lucky Me','pcs','pcs',NULL,25.80,29.67,25.80,29.67,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(943,'Lucky Me Jjampong Small','P5167',NULL,'Drinks/Food','Lucky Me','SMALL','SMALL',NULL,25.75,29.61,25.75,29.61,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(944,'Lucky Me Jjampong Big 70g','P5168',NULL,'Drinks/Food','Lucky Me','70g','70g',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(945,'Nissin Cup Noodles','P5169',NULL,'Drinks/Food','Nissin','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(946,'Cracklings Big','P5170',NULL,'Snacks','Oishi','pcs','pcs',NULL,14.75,16.96,14.75,16.96,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(947,'Pringles','P5171',NULL,'Snacks','Pringles','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(948,'Marty\'s Big','P5172',NULL,'Snacks','Oishi','pcs','pcs',NULL,25.00,35.00,25.00,35.00,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-07 22:07:42'),(949,'Nova','P5173',NULL,'Snacks','Jack \'n Jill','pcs','pcs',NULL,34.10,39.22,34.10,39.22,0,0,24,100,24,10,1253,'active','2026-08-03 00:50:02','2026-08-21 12:36:17'),(950,'Test Engine Oil 1786984056','TEST-ENG-1786984056',NULL,'Oil & Lubricants',NULL,NULL,'pcs',NULL,100.00,150.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-08-18 00:27:36','2026-08-21 12:36:17'),(956,'Draft Test Engine Oil','DRAFT-OIL-1786987285',NULL,'merchandise',NULL,NULL,'pcs',NULL,300.00,450.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-08-18 01:21:25','2026-08-21 12:36:17'),(957,'Draft Test Engine Oil','DRAFT-OIL-1786987307',NULL,'merchandise',NULL,NULL,'pcs',NULL,300.00,450.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-08-18 01:21:47','2026-08-21 12:36:17'),(958,'Draft Test Engine Oil','DRAFT-OIL-1786987326',NULL,'merchandise',NULL,NULL,'pcs',NULL,300.00,450.00,0.00,0.00,0,0,5,100,5,10,1253,'active','2026-08-18 01:22:06','2026-08-21 12:36:17');
/*!40000 ALTER TABLE `inventory_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_order_parts`
--

DROP TABLE IF EXISTS `job_order_parts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_order_parts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity_used` int(11) NOT NULL DEFAULT 1,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_jop_job_order` (`job_order_id`),
  KEY `fk_jop_product` (`product_id`),
  CONSTRAINT `fk_jo_parts_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_order_parts_job_order_id` FOREIGN KEY (`job_order_id`) REFERENCES `job_orders` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_order_parts`
--

LOCK TABLES `job_order_parts` WRITE;
/*!40000 ALTER TABLE `job_order_parts` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_order_parts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_order_service_types`
--

DROP TABLE IF EXISTS `job_order_service_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_order_service_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_code` varchar(20) DEFAULT NULL,
  `service_key` varchar(50) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Others',
  `base_rate_per_hour` decimal(10,2) DEFAULT 0.00,
  `icon_class` varchar(50) DEFAULT NULL,
  `color_class` varchar(20) DEFAULT NULL,
  `allows_custom_input` tinyint(1) DEFAULT 0,
  `allows_manual_parts` tinyint(1) DEFAULT 1,
  `active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `status` enum('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  `submitted_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `service_price` decimal(10,2) NOT NULL DEFAULT 400.00,
  `labor_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `estimated_duration` int(11) DEFAULT 60,
  `required_mechanics` int(11) DEFAULT 1,
  `description` text DEFAULT NULL,
  `station_id` int(11) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `min_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_description` varchar(255) DEFAULT NULL,
  `pricing_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_key` (`service_key`),
  KEY `idx_submitted_by_auto` (`submitted_by`),
  KEY `idx_reviewed_by_auto` (`reviewed_by`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_job_order_service_types_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_job_order_service_types_reviewed_by_9bc6` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_order_service_types_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_job_order_service_types_submitted_by_9bc6` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_order_service_types`
--

LOCK TABLES `job_order_service_types` WRITE;
/*!40000 ALTER TABLE `job_order_service_types` DISABLE KEYS */;
INSERT INTO `job_order_service_types` VALUES (1,'SRV-0001','SRV-0001','Change Oil - Mineral','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,1,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1800.00,350.00,30,1,NULL,1253,NULL,1650.00,1650.00,'',NULL),(2,'SRV-0002','SRV-0002','Change Oil - Semi Synthetic','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,2,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1800.00,350.00,30,1,NULL,1253,NULL,2450.00,2450.00,'',NULL),(3,'SRV-0003','SRV-0003','Change Oil - Fully Synthetic','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,3,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1800.00,350.00,30,1,NULL,1253,NULL,3450.00,3450.00,'',NULL),(4,'SRV-0004','SRV-0004','Engine Flush','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,4,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',330.00,220.00,60,1,NULL,1253,NULL,550.00,550.00,'',NULL),(5,'SRV-0005','SRV-0005','Oil Filter Replacement','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,5,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',350.00,150.00,15,1,NULL,1253,NULL,450.00,450.00,'',NULL),(6,'SRV-0006','SRV-0006','Air Filter Replacement','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,6,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',650.00,100.00,15,1,NULL,1253,NULL,850.00,850.00,'',NULL),(7,'SRV-0007','SRV-0007','Cabin Air Filter Replacement','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,7,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',650.00,100.00,15,1,NULL,1253,NULL,950.00,950.00,'',NULL),(8,'SRV-0008','SRV-0008','Fuel Filter Replacement','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,8,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',750.00,300.00,30,1,NULL,1253,NULL,1500.00,1500.00,'',NULL),(9,'SRV-0009','SRV-0009','Greasing Service','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,9,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',210.00,140.00,60,1,NULL,1253,NULL,350.00,350.00,'',NULL),(10,'SRV-0010','SRV-0010','Oil Additive Application','Lubrication',0.00,'fa-oil-can','text-primary',0,1,1,10,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',270.00,180.00,60,1,NULL,1253,NULL,450.00,450.00,'',NULL),(11,'SRV-0011','SRV-0011','Basic PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,11,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,4500.00,4500.00,'',NULL),(12,'SRV-0012','SRV-0012','Standard PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,12,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,6500.00,6500.00,'',NULL),(13,'SRV-0013','SRV-0013','Major PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,13,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,9500.00,9500.00,'',NULL),(14,'SRV-0014','SRV-0014','5,000 km PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,14,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,3800.00,3800.00,'',NULL),(15,'SRV-0015','SRV-0015','10,000 km PMS','Preventive Maintenance',0.00,'fa-clipboard-list','text-primary',0,1,1,15,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,5800.00,5800.00,'',NULL),(16,'SRV-0016','SRV-0016','20,000 km PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,16,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,8500.00,8500.00,'',NULL),(17,'SRV-0017','SRV-0017','40,000 km PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,17,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,12500.00,12500.00,'',NULL),(18,'SRV-0018','SRV-0018','80,000 km PMS','PMS',0.00,'fa-clipboard-list','text-primary',0,1,1,18,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1200.00,90,2,NULL,1253,NULL,18500.00,18500.00,'',NULL),(19,'SRV-0019','SRV-0019','Spark Plug Replacement','Engine',0.00,'fa-car-battery','text-primary',0,1,1,19,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',800.00,350.00,30,1,NULL,1253,NULL,1200.00,1200.00,'',NULL),(20,'SRV-0020','SRV-0020','Glow Plug Replacement','Engine',0.00,'fa-car-battery','text-primary',0,1,1,20,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1500.00,1000.00,60,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(21,'SRV-0021','SRV-0021','Throttle Body Cleaning','Engine',0.00,'fa-car-battery','text-primary',0,1,1,21,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',250.00,500.00,45,1,NULL,1253,NULL,1500.00,1500.00,'',NULL),(22,'SRV-0022','SRV-0022','Fuel Injector Cleaning','Engine',0.00,'fa-car-battery','text-primary',0,1,1,22,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',500.00,1000.00,90,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(23,'SRV-0023','SRV-0023','Intake Manifold Cleaning','Engine',0.00,'fa-car-battery','text-primary',0,1,1,23,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1500.00,1000.00,60,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(24,'SRV-0024','SRV-0024','Carbon Cleaning','Engine',0.00,'fa-car-battery','text-primary',0,1,1,24,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2100.00,1400.00,60,1,NULL,1253,NULL,3500.00,3500.00,'',NULL),(25,'SRV-0025','SRV-0025','PCV Valve Replacement','Engine',0.00,'fa-car-battery','text-primary',0,1,1,25,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',570.00,380.00,60,1,NULL,1253,NULL,950.00,950.00,'',NULL),(26,'SRV-0026','SRV-0026','Timing Belt Inspection','Engine',0.00,'fa-car-battery','text-primary',0,1,1,26,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',510.00,340.00,60,1,NULL,1253,NULL,850.00,850.00,'',NULL),(27,'SRV-0027','SRV-0027','Timing Belt Replacement','Engine',0.00,'fa-car-battery','text-primary',0,1,1,27,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',4500.00,3000.00,60,1,NULL,1253,NULL,7500.00,7500.00,'',NULL),(28,'SRV-0028','SRV-0028','Serpentine Belt Replacement','Engine',0.00,'fa-car-battery','text-primary',0,1,1,28,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1500.00,1000.00,60,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(29,'SRV-0029','SRV-0029','Valve Cover Gasket Replacement','Engine',0.00,'fa-car-battery','text-primary',0,1,1,29,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1800.00,1200.00,60,1,NULL,1253,NULL,3000.00,3000.00,'',NULL),(30,'SRV-0030','SRV-0030','Engine Compression Test','Engine',0.00,'fa-car-battery','text-primary',0,1,1,30,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1200.00,800.00,60,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(31,'SRV-0031','SRV-0031','Fuel Pump Inspection','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,31,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',350.00,500.00,45,1,NULL,1253,NULL,900.00,900.00,'',NULL),(32,'SRV-0032','SRV-0032','Fuel Pump Replacement','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,32,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3800.00,1200.00,90,1,NULL,1253,NULL,5500.00,5500.00,'',NULL),(33,'SRV-0033','SRV-0033','Fuel Tank Cleaning','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,33,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',500.00,1800.00,180,2,NULL,1253,NULL,3500.00,3500.00,'',NULL),(34,'SRV-0034','SRV-0034','Fuel Line Cleaning','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,34,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',300.00,800.00,60,1,NULL,1253,NULL,1500.00,1500.00,'',NULL),(35,'SRV-0035','SRV-0035','Fuel Injector Testing','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,35,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',500.00,1000.00,90,1,NULL,1253,NULL,1800.00,1800.00,'',NULL),(36,'SRV-0036','SRV-0036','Diesel Injector Calibration','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,36,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1000.00,3500.00,180,2,NULL,1253,NULL,4500.00,4500.00,'',NULL),(37,'SRV-0037','SRV-0037','Fuel Pressure Test','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,37,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',250.00,450.00,30,1,NULL,1253,NULL,1200.00,1200.00,'',NULL),(38,'SRV-0038','SRV-0038','Fuel Rail Cleaning','Fuel System',0.00,'fa-gas-pump','text-primary',0,1,1,38,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',300.00,1200.00,75,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(39,'SRV-0039','SRV-0039','Radiator Flush','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,39,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',650.00,450.00,45,1,NULL,1253,NULL,1250.00,1250.00,'',NULL),(40,'SRV-0040','SRV-0040','Coolant Replacement','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,40,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',800.00,350.00,30,1,NULL,1253,NULL,1650.00,1650.00,'',NULL),(41,'SRV-0041','SRV-0041','Radiator Cleaning','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,41,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',400.00,1200.00,120,2,NULL,1253,NULL,2000.00,2000.00,'',NULL),(42,'SRV-0042','SRV-0042','Thermostat Replacement','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,42,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1200.00,600.00,60,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(43,'SRV-0043','SRV-0043','Radiator Hose Replacement','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,43,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',650.00,350.00,30,1,NULL,1253,NULL,1500.00,1500.00,'',NULL),(44,'SRV-0044','SRV-0044','Water Pump Replacement','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,44,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2800.00,1800.00,150,2,NULL,1253,NULL,6500.00,6500.00,'',NULL),(45,'SRV-0045','SRV-0045','Cooling System Pressure Test','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,45,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',200.00,350.00,30,1,NULL,1253,NULL,1000.00,1000.00,'',NULL),(46,'SRV-0046','SRV-0046','Radiator Cap Replacement','Cooling System',0.00,'fa-snowflake','text-primary',0,1,1,46,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',350.00,100.00,10,1,NULL,1253,NULL,600.00,600.00,'',NULL),(47,'SRV-0047','SRV-0047','ATF Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,47,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1800.00,500.00,45,1,NULL,1253,NULL,3500.00,3500.00,'',NULL),(48,'SRV-0048','SRV-0048','ATF Dialysis / Flush','Transmission',0.00,'fa-cogs','text-primary',0,1,1,48,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',4500.00,1500.00,90,2,NULL,1253,NULL,6500.00,6500.00,'',NULL),(49,'SRV-0049','SRV-0049','Manual Transmission Oil Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,49,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1200.00,400.00,45,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(50,'SRV-0050','SRV-0050','CVT Fluid Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,50,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3200.00,800.00,60,1,NULL,1253,NULL,4500.00,4500.00,'',NULL),(51,'SRV-0051','SRV-0051','Differential Oil Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,51,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1400.00,400.00,45,1,NULL,1253,NULL,2200.00,2200.00,'',NULL),(52,'SRV-0052','SRV-0052','Transfer Case Oil Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,52,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1500.00,450.00,45,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(53,'SRV-0053','SRV-0053','Gear Oil Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,53,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1100.00,350.00,40,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(54,'SRV-0054','SRV-0054','Clutch Fluid Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,54,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',350.00,300.00,30,1,NULL,1253,NULL,1200.00,1200.00,'',NULL),(55,'SRV-0055','SRV-0055','Clutch Disc Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,55,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',4500.00,3500.00,240,2,NULL,1253,NULL,8500.00,8500.00,'',NULL),(56,'SRV-0056','SRV-0056','Clutch Pressure Plate Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,56,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3800.00,2500.00,180,2,NULL,1253,NULL,6500.00,6500.00,'',NULL),(57,'SRV-0057','SRV-0057','Clutch Release Bearing Replacement','Transmission',0.00,'fa-cogs','text-primary',0,1,1,57,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1800.00,2000.00,150,2,NULL,1253,NULL,3500.00,3500.00,'',NULL),(58,'SRV-0058','SRV-0058','Brake Cleaning','Brake',0.00,'fa-circle','text-primary',0,1,1,58,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',150.00,450.00,45,1,NULL,1253,NULL,600.00,600.00,'',NULL),(59,'SRV-0059','SRV-0059','Brake Pad Replacement (Front)','Brake',0.00,'fa-circle','text-primary',0,1,1,59,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1600.00,450.00,45,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(60,'SRV-0060','SRV-0060','Brake Pad Replacement (Rear)','Brake',0.00,'fa-circle','text-primary',0,1,1,60,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1500.00,450.00,45,1,NULL,1253,NULL,2300.00,2300.00,'',NULL),(61,'SRV-0061','SRV-0061','Brake Shoe Replacement','Brake',0.00,'fa-circle','text-primary',0,1,1,61,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1400.00,500.00,60,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(62,'SRV-0062','SRV-0062','Brake Disc Resurfacing','Brake',0.00,'fa-circle','text-primary',0,1,1,62,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',400.00,800.00,90,2,NULL,1253,NULL,1500.00,1500.00,'',NULL),(63,'SRV-0063','SRV-0063','Brake Disc Replacement','Brake',0.00,'fa-circle','text-primary',0,1,1,63,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3200.00,600.00,60,1,NULL,1253,NULL,5500.00,5500.00,'',NULL),(64,'SRV-0064','SRV-0064','Brake Drum Resurfacing','Brake',0.00,'fa-circle','text-primary',0,1,1,64,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',350.00,750.00,90,1,NULL,1253,NULL,1300.00,1300.00,'',NULL),(65,'SRV-0065','SRV-0065','Brake Fluid Replacement','Brake',0.00,'fa-circle','text-primary',0,1,1,65,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',450.00,450.00,45,2,NULL,1253,NULL,1200.00,1200.00,'',NULL),(66,'SRV-0066','SRV-0066','Brake Caliper Overhaul','Brake',0.00,'fa-circle','text-primary',0,1,1,66,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',600.00,1200.00,120,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(67,'SRV-0067','SRV-0067','Brake Hose Replacement','Brake',0.00,'fa-circle','text-primary',0,1,1,67,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',850.00,450.00,45,1,NULL,1253,NULL,1800.00,1800.00,'',NULL),(68,'SRV-0068','SRV-0068','Shock Absorber Replacement (Front)','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,68,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3800.00,1500.00,120,2,NULL,1253,NULL,6500.00,6500.00,'',NULL),(69,'SRV-0069','SRV-0069','Shock Absorber Replacement (Rear)','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,69,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3000.00,1000.00,90,1,NULL,1253,NULL,5500.00,5500.00,'',NULL),(70,'SRV-0070','SRV-0070','Coil Spring Replacement','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,70,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',2200.00,1200.00,90,2,NULL,1253,NULL,3500.00,3500.00,'',NULL),(71,'SRV-0071','SRV-0071','Ball Joint Replacement','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,71,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1400.00,800.00,75,1,NULL,1253,NULL,2500.00,2500.00,'',NULL),(72,'SRV-0072','SRV-0072','Stabilizer Link Replacement','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,72,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',950.00,450.00,45,1,NULL,1253,NULL,1800.00,1800.00,'',NULL),(73,'SRV-0073','SRV-0073','Control Arm Replacement','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,73,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',3500.00,1500.00,120,2,NULL,1253,NULL,6000.00,6000.00,'',NULL),(74,'SRV-0074','SRV-0074','Rack End Replacement','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,74,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1200.00,750.00,60,1,NULL,1253,NULL,2200.00,2200.00,'',NULL),(75,'SRV-0075','SRV-0075','Tie Rod End Replacement','Suspension',0.00,'fa-arrows-alt-v','text-primary',0,1,1,75,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1000.00,650.00,60,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(76,'SRV-0076','SRV-0076','Power Steering Fluid Replacement','Steering',0.00,'fa-dharmachakra','text-primary',0,1,1,76,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',650.00,450.00,45,1,NULL,1253,NULL,1200.00,1200.00,'',NULL),(77,'SRV-0077','SRV-0077','Power Steering Flush','Steering',0.00,'fa-dharmachakra','text-primary',0,1,1,77,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',850.00,650.00,60,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(78,'SRV-0078','SRV-0078','Steering Rack Inspection','Steering',0.00,'fa-dharmachakra','text-primary',0,1,1,78,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',300.00,500.00,40,1,NULL,1253,NULL,800.00,800.00,'',NULL),(79,'SRV-0079','SRV-0079','Steering Rack Replacement','Steering',0.00,'fa-dharmachakra','text-primary',0,1,1,79,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',7500.00,3500.00,240,2,NULL,1253,NULL,12000.00,12000.00,'',NULL),(80,'SRV-0080','SRV-0080','Wheel Bearing Replacement','Steering',0.00,'fa-dharmachakra','text-primary',0,1,1,80,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',1600.00,1200.00,90,2,NULL,1253,NULL,2500.00,2500.00,'',NULL),(81,'SRV-0081','SRV-0081','Tire Rotation','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,81,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',150.00,350.00,30,1,NULL,1253,NULL,500.00,500.00,'',NULL),(82,'SRV-0082','SRV-0082','Wheel Alignment','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,82,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',450.00,950.00,45,1,NULL,1253,NULL,1200.00,1200.00,'',NULL),(83,'SRV-0083','SRV-0083','Wheel Balancing','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,83,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',50.00,150.00,20,1,NULL,1253,NULL,250.00,250.00,'/ wheel',NULL),(84,'SRV-0084','SRV-0084','Tire Inflation','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,84,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',50.00,50.00,10,1,NULL,1253,NULL,0.00,0.00,'FREE',NULL),(85,'SRV-0085','SRV-0085','Tire Pressure Check','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,85,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',50.00,50.00,10,1,NULL,1253,NULL,0.00,0.00,'FREE',NULL),(86,'SRV-0086','SRV-0086','Tire Repair (Vulcanizing)','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,86,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',50.00,150.00,25,1,NULL,1253,NULL,250.00,250.00,'',NULL),(87,'SRV-0087','SRV-0087','Tire Mounting','Tire Services',0.00,'fa-compact-disc','text-primary',0,1,1,87,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',150.00,200.00,20,1,NULL,1253,NULL,300.00,300.00,'/ tire',NULL),(88,'SRV-0088','SRV-0088','Battery Health Check','Battery Services',0.00,'fa-battery-three-quarters','text-primary',0,1,1,88,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',100.00,150.00,15,1,NULL,1253,NULL,0.00,0.00,'FREE',NULL),(89,'SRV-0089','SRV-0089','Battery Charging','Battery Services',0.00,'fa-battery-three-quarters','text-primary',0,1,1,89,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',100.00,200.00,120,1,NULL,1253,NULL,500.00,500.00,'',NULL),(90,'SRV-0090','SRV-0090','Battery Replacement Labor','Battery Services',0.00,'fa-battery-three-quarters','text-primary',0,1,1,90,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',100.00,150.00,15,1,NULL,1253,NULL,500.00,500.00,'',NULL),(91,'SRV-0091','SRV-0091','Alternator Testing','Battery Services',0.00,'fa-battery-three-quarters','text-primary',0,1,1,91,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',400.00,250.00,20,1,NULL,1253,NULL,800.00,800.00,'',NULL),(92,'SRV-0092','SRV-0092','Starter Motor Testing','Battery Services',0.00,'fa-battery-three-quarters','text-primary',0,1,1,92,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',200.00,300.00,30,1,NULL,1253,NULL,800.00,800.00,'',NULL),(93,'SRV-0093','SRV-0093','Headlight Bulb Replacement','Electrical',0.00,'fa-bolt','text-primary',0,1,1,93,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',250.00,150.00,15,1,NULL,1253,NULL,350.00,350.00,'',NULL),(94,'SRV-0094','SRV-0094','Taillight Bulb Replacement','Electrical',0.00,'fa-bolt','text-primary',0,1,1,94,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',200.00,150.00,15,1,NULL,1253,NULL,300.00,300.00,'',NULL),(95,'SRV-0095','SRV-0095','Fuse Replacement','Electrical',0.00,'fa-bolt','text-primary',0,1,1,95,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',50.00,100.00,10,1,NULL,1253,NULL,150.00,150.00,'',NULL),(96,'SRV-0096','SRV-0096','Cabin Air Filter Replacement','Air Conditioning',0.00,'fa-fan','text-primary',0,1,1,96,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',650.00,100.00,15,1,NULL,1253,NULL,950.00,950.00,'',NULL),(97,'SRV-0097','aircon_cleaning','Aircon Cleaning','Air Conditioning',0.00,'fa-fan','text-primary',0,1,1,97,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',600.00,1800.00,180,2,NULL,1253,NULL,2500.00,2500.00,'',NULL),(98,'SRV-0098','aircon_gas_recharge','Aircon Gas Recharge','Air Conditioning',0.00,'fa-fan','text-primary',0,1,1,98,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',800.00,800.00,60,1,NULL,1253,NULL,2000.00,2000.00,'',NULL),(99,'SRV-0099','SRV-0099','Computerized ECU Scan','Diagnostics',0.00,'fa-desktop','text-primary',0,1,1,99,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',350.00,650.00,30,1,NULL,1253,NULL,800.00,800.00,'',NULL),(100,'SRV-0100','SRV-0100','Comprehensive Vehicle Safety Inspection','Inspection',0.00,'fa-search','text-primary',0,1,1,100,'approved',NULL,NULL,NULL,'2026-07-01 12:37:33','2026-08-18 15:51:37',450.00,750.00,45,1,NULL,1253,NULL,1000.00,1000.00,'',NULL);
/*!40000 ALTER TABLE `job_order_service_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_orders`
--

DROP TABLE IF EXISTS `job_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_order_number` varchar(50) NOT NULL,
  `station_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `vehicle_plate` varchar(20) DEFAULT NULL,
  `vehicle_type` varchar(50) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `chassis_number` varchar(100) DEFAULT NULL,
  `service_category_id` int(11) DEFAULT NULL,
  `assigned_mechanic_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `service_description` text NOT NULL,
  `has_manual_input` tinyint(1) DEFAULT 0,
  `estimated_duration` int(11) DEFAULT 60,
  `status` enum('Pending','Reviewed','In Progress','Awaiting Parts','Completed','Verified','finalized','Cancelled','Rejected') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `requires_approval` tinyint(1) DEFAULT 0 COMMENT 'Whether job requires admin approval',
  `reviewed_by` int(11) DEFAULT NULL COMMENT 'Admin who reviewed the job',
  `reviewed_at` datetime DEFAULT NULL COMMENT 'When job was reviewed',
  `staff_editable` tinyint(1) DEFAULT 1,
  `approved_by` int(11) DEFAULT NULL COMMENT 'Admin who gave final approval',
  `approved_at` datetime DEFAULT NULL COMMENT 'When job was approved',
  `admin_remarks` text DEFAULT NULL COMMENT 'Admin review remarks',
  `estimated_labor_cost` decimal(10,2) DEFAULT 0.00 COMMENT 'Estimated labor cost',
  `estimated_parts_cost` decimal(10,2) DEFAULT 0.00 COMMENT 'Estimated parts cost',
  `actual_labor_cost` decimal(10,2) DEFAULT 0.00 COMMENT 'Actual labor cost after completion',
  `actual_parts_cost` decimal(10,2) DEFAULT 0.00 COMMENT 'Actual parts cost after completion',
  `total_cost` decimal(10,2) DEFAULT 0.00 COMMENT 'Total job cost',
  `actual_duration` int(11) DEFAULT NULL COMMENT 'Actual duration in minutes',
  `billing_locked` tinyint(1) DEFAULT 0,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `sukli` decimal(10,2) DEFAULT 0.00,
  `payment_status` varchar(50) DEFAULT 'Pending',
  `job_order_id` varchar(50) DEFAULT NULL,
  `validation_status` varchar(50) DEFAULT 'Pending Validation',
  `estimated_cost` decimal(10,2) DEFAULT 0.00,
  `shift_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `service_type` varchar(500) NOT NULL DEFAULT '',
  `required_parts` text DEFAULT NULL,
  `additional_notes` text DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Cash',
  `created_by` int(11) NOT NULL,
  `service_price_details` text DEFAULT NULL,
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `adjustment_reason` text DEFAULT NULL,
  `is_credit` tinyint(1) DEFAULT 0,
  `rejection_reason` text DEFAULT NULL,
  `balance_due` decimal(12,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL COMMENT 'Payment due date for receivables',
  `void_reason` text DEFAULT NULL,
  `manager_remarks` text DEFAULT NULL,
  `vehicle_brand` varchar(100) DEFAULT NULL,
  `vehicle_model` varchar(100) DEFAULT NULL,
  `year_model` varchar(20) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_job_order_number` (`job_order_number`),
  UNIQUE KEY `job_order_id` (`job_order_id`),
  KEY `fk_job_station` (`station_id`),
  KEY `fk_job_customer` (`customer_id`),
  KEY `fk_job_service_category` (`service_category_id`),
  KEY `fk_job_mechanic` (`assigned_mechanic_id`),
  KEY `fk_job_assigner` (`assigned_by`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_requires_approval` (`requires_approval`),
  KEY `idx_station_status` (`station_id`,`status`),
  KEY `fk_job_reviewed_by` (`reviewed_by`),
  KEY `fk_job_approved_by` (`approved_by`),
  KEY `idx_has_manual_input` (`has_manual_input`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_id2` (`id`),
  KEY `idx_id_jo` (`id`),
  KEY `fk_job_orders_created_by` (`created_by`),
  KEY `idx_validated_by` (`validated_by`),
  CONSTRAINT `fk2_job_orders_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jo_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shift_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_job_orders_approved_by_9bc6` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_orders_assigned_mechanic_id` FOREIGN KEY (`assigned_mechanic_id`) REFERENCES `mechanics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_job_orders_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_orders_reviewed_by_9bc6` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_orders_service_category_id` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_orders_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_orders_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_job_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_job_orders_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_orders`
--

LOCK TABLES `job_orders` WRITE;
/*!40000 ALTER TABLE `job_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `labor_sessions`
--

DROP TABLE IF EXISTS `labor_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `labor_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `hours_worked` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `shift_period` varchar(50) DEFAULT NULL,
  `shift_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_labor_user` (`user_id`),
  KEY `fk_labor_station` (`station_id`),
  CONSTRAINT `fk_labor_sessions_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_labor_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `labor_sessions`
--

LOCK TABLES `labor_sessions` WRITE;
/*!40000 ALTER TABLE `labor_sessions` DISABLE KEYS */;
INSERT INTO `labor_sessions` VALUES (1,13,1253,'2026-08-21 13:46:49','2026-08-21 14:02:20',0.25,'2026-08-21 05:46:49','first','First Shift: 6:00 AM - 2:00 PM'),(2,13,1253,'2026-08-21 14:55:00','2026-08-21 15:34:29',0.65,'2026-08-21 06:55:00','first','First Shift: 6:00 AM - 2:00 PM'),(3,13,1253,'2026-08-21 15:34:45',NULL,0.00,'2026-08-21 07:34:45','first','First Shift: 6:00 AM - 2:00 PM');
/*!40000 ALTER TABLE `labor_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  `status` enum('success','failed','locked','blocked') NOT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `attempts_count` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_attempt_time` (`attempt_time`),
  KEY `idx_status` (`status`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_login_attempts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
INSERT INTO `login_attempts` VALUES (1,13,'yyangcabahug@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 13:46:49','success',NULL,1),(2,4,'amda.cabahug.coc@phinmaed.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 14:45:50','success',NULL,1),(3,3,'cabahug.amiedamas@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 14:50:28','success',NULL,1),(4,13,'yyangcabahug@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 14:55:00','success',NULL,1),(5,1,'yangc.developer@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 15:09:31','success',NULL,1),(6,13,'yyangcabahug@gmail.com','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0','2026-08-21 15:34:45','success',NULL,1);
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loyalty_accounts`
--

DROP TABLE IF EXISTS `loyalty_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loyalty_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL DEFAULT 1,
  `card_number` varchar(100) NOT NULL,
  `points_balance` int(11) NOT NULL DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_program` (`customer_id`,`program_id`),
  UNIQUE KEY `uk_card_number` (`card_number`),
  KEY `idx_customer_id` (`customer_id`),
  CONSTRAINT `fk_loyalty_accounts_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loyalty_accounts`
--

LOCK TABLES `loyalty_accounts` WRITE;
/*!40000 ALTER TABLE `loyalty_accounts` DISABLE KEYS */;
INSERT INTO `loyalty_accounts` VALUES (1,1,1,'CUS-1253-202608-001',34,NULL,'active','2026-08-12 22:18:18','2026-08-20 14:46:03'),(2,2,1,'CUS-1253-202608-002',27,NULL,'active','2026-08-12 22:18:18','2026-08-20 14:03:06'),(3,3,1,'CUS-1253-202608-003',3,NULL,'active','2026-08-12 22:18:18','2026-08-14 14:24:08'),(4,4,1,'CUS-1253-202608-004',2,NULL,'active','2026-08-14 14:46:46','2026-08-14 14:48:26'),(5,5,1,'CUS-1253-202608-005',0,NULL,'active','2026-08-15 12:20:38','2026-08-15 12:20:38'),(6,6,1,'CUS-1253-202608-006',0,NULL,'active','2026-08-20 12:16:51','2026-08-20 12:16:51'),(7,7,1,'CUS-1253-202608-007',48,NULL,'active','2026-08-20 14:05:11','2026-08-20 23:20:38'),(8,8,1,'CUS-1253-202608-008',32,NULL,'active','2026-08-20 14:11:08','2026-08-20 14:12:27');
/*!40000 ALTER TABLE `loyalty_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loyalty_programs`
--

DROP TABLE IF EXISTS `loyalty_programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loyalty_programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_name` varchar(100) NOT NULL DEFAULT 'Petron Rewards Card',
  `points_per_amount` decimal(10,2) NOT NULL DEFAULT 100.00 COMMENT 'Amount in pesos for 1 point',
  `minimum_redeem_points` int(11) NOT NULL DEFAULT 1,
  `redemption_value` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Pesos per redeemed point',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loyalty_programs`
--

LOCK TABLES `loyalty_programs` WRITE;
/*!40000 ALTER TABLE `loyalty_programs` DISABLE KEYS */;
INSERT INTO `loyalty_programs` VALUES (1,'Petron Rewards Card',100.00,1,1.00,'active','2026-08-12 22:18:18','2026-08-12 22:18:18');
/*!40000 ALTER TABLE `loyalty_programs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loyalty_transactions`
--

DROP TABLE IF EXISTS `loyalty_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loyalty_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loyalty_account_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `reference_id` varchar(100) DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL DEFAULT 'Merchandise',
  `points_earned` int(11) NOT NULL DEFAULT 0,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `points_balance_after` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_account` (`loyalty_account_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_reference` (`reference_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_loyalty_transactions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_transactions_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loyalty_transactions`
--

LOCK TABLES `loyalty_transactions` WRITE;
/*!40000 ALTER TABLE `loyalty_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `loyalty_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manager_color_config`
--

DROP TABLE IF EXISTS `manager_color_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manager_color_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `color_code` varchar(7) NOT NULL DEFAULT '#dc3545',
  `color_name` varchar(50) DEFAULT 'Red',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_manager_color` (`user_id`),
  CONSTRAINT `manager_color_config_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manager_color_config`
--

LOCK TABLES `manager_color_config` WRITE;
/*!40000 ALTER TABLE `manager_color_config` DISABLE KEYS */;
INSERT INTO `manager_color_config` VALUES (1,3,'#dc3545','Red',1,'2026-05-02 08:26:14','2026-06-09 07:04:08'),(2,4,'#dc3545','Red',1,'2026-05-02 08:26:14','2026-06-09 07:04:08');
/*!40000 ALTER TABLE `manager_color_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_data_requests`
--

DROP TABLE IF EXISTS `master_data_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_data_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) DEFAULT NULL,
  `category` enum('Vehicle','Merchandise Product','Service Type') NOT NULL,
  `source_module` varchar(100) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `station_id` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `data_payload` longtext NOT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `idx_category` (`category`),
  KEY `idx_status` (`status`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `fk_master_data_requests_station_id` (`station_id`),
  KEY `fk_master_data_requests_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_master_data_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_master_data_requests_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_master_data_requests_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_data_requests`
--

LOCK TABLES `master_data_requests` WRITE;
/*!40000 ALTER TABLE `master_data_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `master_data_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mechanics`
--

DROP TABLE IF EXISTS `mechanics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mechanics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `specialization` varchar(100) DEFAULT 'General Mechanic',
  `shift_assignment` varchar(50) DEFAULT 'All Shifts',
  `date_hired` date DEFAULT NULL,
  `contact_no` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `archived` tinyint(1) DEFAULT 0,
  `archive_reason` text DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_full_name` (`full_name`),
  CONSTRAINT `fk_mechanics_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mechanics`
--

LOCK TABLES `mechanics` WRITE;
/*!40000 ALTER TABLE `mechanics` DISABLE KEYS */;
INSERT INTO `mechanics` VALUES (1,'Loloy','','Cabusog','Loloy Cabusog','General Mechanic','All Shifts',NULL,NULL,NULL,'active',0,NULL,1253,'2026-05-08 22:37:34','2026-08-21 13:24:42'),(2,'Jonard',NULL,'Aguada','Jonard Aguada','General Mechanic','All Shifts',NULL,NULL,NULL,'active',0,NULL,1253,'2026-05-08 22:37:34','2026-08-21 13:24:42'),(3,'Danny',NULL,'Parohinog','Danny Parohinog','General Mechanic','All Shifts',NULL,NULL,NULL,'active',0,NULL,1253,'2026-07-22 21:45:27','2026-08-21 13:24:42'),(4,'Jun','','Mant','Jun Mant','General Mechanic','First Shift','2026-07-01','09741254896','','active',0,NULL,1253,'2026-07-22 21:45:27','2026-08-21 13:24:42');
/*!40000 ALTER TABLE `mechanics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchandise_adjustments`
--

DROP TABLE IF EXISTS `merchandise_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `merchandise_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `adjusted_stock` int(11) NOT NULL DEFAULT 0,
  `quantity_change` int(11) NOT NULL DEFAULT 0,
  `adjustment_type` varchar(100) NOT NULL DEFAULT 'Physical Count',
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_status` (`status`),
  KEY `idx_approved_by` (`approved_by`),
  CONSTRAINT `fk_madj_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_madj_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_adjustments_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchandise_adjustments`
--

LOCK TABLES `merchandise_adjustments` WRITE;
/*!40000 ALTER TABLE `merchandise_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchandise_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchandise_batches`
--

DROP TABLE IF EXISTS `merchandise_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `merchandise_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `batch_number` varchar(50) NOT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `remaining_qty` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `supplier` varchar(200) DEFAULT NULL,
  `date_received` date NOT NULL,
  `encoded_by` int(11) DEFAULT NULL,
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `status` enum('active','depleted','cancelled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_station` (`station_id`),
  KEY `fk_merchandise_batches_encoded_by` (`encoded_by`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_validated_by` (`validated_by`),
  CONSTRAINT `fk_merchandise_batches_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_batches_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_batches_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_batches_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchandise_batches`
--

LOCK TABLES `merchandise_batches` WRITE;
/*!40000 ALTER TABLE `merchandise_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchandise_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchandise_stock_in`
--

DROP TABLE IF EXISTS `merchandise_stock_in`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `merchandise_stock_in` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) DEFAULT NULL,
  `po_number` varchar(100) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `qty_ordered` int(11) NOT NULL DEFAULT 0,
  `qty_received` int(11) NOT NULL DEFAULT 0,
  `qty_variance` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `condition_flag` enum('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
  `remarks` text DEFAULT NULL,
  `stock_before` int(11) NOT NULL DEFAULT 0,
  `stock_after` int(11) NOT NULL DEFAULT 0,
  `encoded_by` int(11) NOT NULL,
  `encoded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `batch_ref` varchar(100) DEFAULT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_station` (`station_id`),
  KEY `idx_encoded_at` (`encoded_at`),
  KEY `idx_po_id` (`po_id`),
  KEY `fk_merchandise_stock_in_encoded_by` (`encoded_by`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_merchandise_stock_in_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_stock_in_po_id` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_stock_in_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_stock_in_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchandise_stock_in`
--

LOCK TABLES `merchandise_stock_in` WRITE;
/*!40000 ALTER TABLE `merchandise_stock_in` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchandise_stock_in` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchandise_transaction_audit`
--

DROP TABLE IF EXISTS `merchandise_transaction_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `merchandise_transaction_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction` (`transaction_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_merchandise_transaction_audit_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchandise_transaction_audit`
--

LOCK TABLES `merchandise_transaction_audit` WRITE;
/*!40000 ALTER TABLE `merchandise_transaction_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchandise_transaction_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchandise_transaction_items`
--

DROP TABLE IF EXISTS `merchandise_transaction_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `merchandise_transaction_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `size_variant` varchar(100) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `item_type` varchar(20) NOT NULL DEFAULT 'merchandise',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `merchandise_transaction_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `merchandise_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `merchandise_transaction_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchandise_transaction_items`
--

LOCK TABLES `merchandise_transaction_items` WRITE;
/*!40000 ALTER TABLE `merchandise_transaction_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchandise_transaction_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchandise_transactions`
--

DROP TABLE IF EXISTS `merchandise_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `merchandise_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(64) NOT NULL,
  `station_id` int(11) NOT NULL,
  `item_sku` varchar(200) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `transaction_date` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `validation_status` varchar(30) NOT NULL DEFAULT 'Pending',
  `customer_name` varchar(255) NOT NULL DEFAULT 'Walk-in',
  `payment_method` varchar(50) NOT NULL DEFAULT 'Cash',
  `payment_method_id` int(11) DEFAULT NULL,
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `adjustment_reason` text DEFAULT NULL,
  `credit_customer_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `shift_period` varchar(50) DEFAULT NULL,
  `shift_name` varchar(100) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `amount_tendered` decimal(10,2) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `card_reference` varchar(100) DEFAULT NULL,
  `card_type` varchar(50) DEFAULT NULL,
  `ewallet_reference` varchar(100) DEFAULT NULL,
  `ewallet_provider` varchar(50) DEFAULT NULL,
  `efuel_card_number` varchar(50) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `job_order_id` int(11) DEFAULT NULL,
  `job_order_db_id` int(11) DEFAULT NULL,
  `job_order_service` varchar(500) DEFAULT NULL,
  `job_order_description` text DEFAULT NULL,
  `job_order_vehicle_plate` varchar(20) DEFAULT NULL,
  `job_order_vehicle_type` varchar(50) DEFAULT NULL,
  `job_order_engine_number` varchar(100) DEFAULT NULL,
  `job_order_chassis_number` varchar(100) DEFAULT NULL,
  `job_order_mechanic_id` int(11) DEFAULT NULL,
  `job_order_mechanic_name` varchar(255) DEFAULT NULL,
  `job_order_contact` varchar(50) DEFAULT NULL,
  `subtotal_amount` decimal(10,2) DEFAULT NULL,
  `vat_amount` decimal(10,2) DEFAULT NULL,
  `customer_first_name` varchar(100) DEFAULT NULL,
  `customer_last_name` varchar(100) DEFAULT NULL,
  `transaction_type` varchar(20) NOT NULL DEFAULT 'merchandise',
  `payment_status` varchar(60) DEFAULT 'Unpaid',
  `workflow_status` varchar(60) DEFAULT 'Pending',
  `manager_notes` text DEFAULT NULL,
  `manager_remarks` text DEFAULT NULL,
  `inventory_deducted` tinyint(1) DEFAULT 1,
  `void_reason` text DEFAULT NULL,
  `amount_paid` decimal(12,2) DEFAULT 0.00,
  `balance_due` decimal(12,2) DEFAULT NULL,
  `staff_remarks` text DEFAULT NULL COMMENT 'Staff-entered notes (separate from legacy remarks)',
  `due_date` date DEFAULT NULL COMMENT 'Payment due date for receivables',
  `card_last_four` varchar(4) DEFAULT NULL,
  `efuel_reference` varchar(100) DEFAULT NULL,
  `fleet_card_number` varchar(50) DEFAULT NULL,
  `fleet_company_name` varchar(255) DEFAULT NULL,
  `fleet_auth_number` varchar(50) DEFAULT NULL,
  `credit_company_name` varchar(255) DEFAULT NULL,
  `credit_account_number` varchar(100) DEFAULT NULL,
  `credit_po_number` varchar(50) DEFAULT NULL,
  `credit_due_date` date DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `loyalty_type` varchar(64) DEFAULT NULL,
  `loyalty_card_no` varchar(64) DEFAULT NULL,
  `loyalty_points_earned` int(11) DEFAULT NULL,
  `loyalty_points_redeemed` int(11) DEFAULT NULL,
  `job_order_vehicle_brand` varchar(100) DEFAULT NULL,
  `job_order_vehicle_model` varchar(100) DEFAULT NULL,
  `job_order_year_model` varchar(20) DEFAULT NULL,
  `job_order_estimated_duration` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `idx_station_item` (`station_id`,`item_sku`),
  KEY `idx_staff_date` (`staff_id`,`transaction_date`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `fk_merch_transactions_validated_by` (`validated_by`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_credit_customer_id` (`credit_customer_id`),
  KEY `idx_id2` (`id`),
  KEY `idx_job_order_id_auto` (`job_order_id`),
  KEY `idx_mt_customer_id` (`customer_id`),
  KEY `fk_mt_pm_id` (`payment_method_id`),
  CONSTRAINT `fk2_merchandise_transactions_job_order_id` FOREIGN KEY (`job_order_id`) REFERENCES `job_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_m_tx_staff` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_m_tx_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_merch_transactions_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_merchandise_transactions_credit_customer_id` FOREIGN KEY (`credit_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_merchandise_transactions_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchandise_transactions_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_merchandise_transactions_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_merchandise_transactions_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_mt_pm_id` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_mt_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shift_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchandise_transactions`
--

LOCK TABLES `merchandise_transactions` WRITE;
/*!40000 ALTER TABLE `merchandise_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchandise_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `module_config`
--

DROP TABLE IF EXISTS `module_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `module_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(100) NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `config_type` varchar(20) DEFAULT 'string',
  `config_category` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `is_encrypted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_module_config` (`module_key`,`config_key`),
  CONSTRAINT `module_config_ibfk_1` FOREIGN KEY (`module_key`) REFERENCES `module_settings` (`module_key`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17174 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `module_config`
--

LOCK TABLES `module_config` WRITE;
/*!40000 ALTER TABLE `module_config` DISABLE KEYS */;
INSERT INTO `module_config` VALUES (1,'transactions','auto_pull_enabled','1','boolean','logic','Enable automatic data pulling for transactions',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(2,'transactions','auto_pull_interval','60','integer','logic','Auto-pull interval in seconds',0,'2026-04-14 03:32:51','2026-04-17 07:45:03'),(3,'transactions','computation_formula','(present - previous - calibration) * price_per_liter','string','formula','Fuel transaction computation formula',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(4,'transactions','max_transaction_amount','10000','decimal','validation','Maximum transaction amount allowed',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(5,'transactions','require_manager_approval','0','boolean','approval','Require manager approval for transactions above threshold',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(10,'fuel_management','auto_pull_readings','1','boolean','logic','Enable automatic fuel readings pulling',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(11,'fuel_management','calibration_formula','present - previous','string','formula','Calibration computation formula',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(12,'fuel_management','low_stock_threshold','500','decimal','alert','Low stock alert threshold in liters',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(13,'fuel_management','critical_stock_threshold','200','decimal','alert','Critical stock alert threshold in liters',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(14,'fuel_management','reconciliation_tolerance','5','decimal','validation','Reconciliation tolerance percentage',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(15,'calendar','auto_schedule_shifts','0','boolean','logic','Enable automatic shift scheduling',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(16,'calendar','shift_duration_hours','8','integer','scheduling','Default shift duration in hours',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(17,'calendar','max_shifts_per_day','3','integer','scheduling','Maximum shifts per day',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(18,'calendar','advance_booking_days','30','integer','booking','Days in advance for shift booking',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(19,'reports','auto_generate_reports','1','boolean','automation','Enable automatic report generation',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(20,'reports','report_retention_days','365','integer','retention','Number of days to retain reports',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(21,'reports','audit_log_retention_days','730','integer','retention','Number of days to retain audit logs',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(22,'reports','export_formats','pdf,excel,csv','string','export','Available export formats (comma-separated)',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(23,'reports','compliance_check_enabled','1','boolean','compliance','Enable compliance checking',0,'2026-04-14 03:32:51','2026-04-14 03:32:51'),(3911,'inventory','low_stock_alert_enabled','1','boolean','alert','Enable low stock alerts',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3912,'inventory','low_stock_threshold_qty','10','integer','alert','Low stock alert threshold (units)',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3913,'inventory','stock_request_approval','1','boolean','approval','Require manager approval for stock requests',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3914,'inventory','auto_deduct_on_sale','1','boolean','logic','Automatically deduct inventory on sale',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3915,'inventory','track_inventory_history','1','boolean','logic','Track full inventory change history',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3916,'customers','loyalty_program_enabled','1','boolean','loyalty','Enable customer loyalty program',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3917,'customers','credit_limit_default','5000','decimal','credit','Default customer credit limit',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3918,'customers','require_customer_on_sale','0','boolean','validation','Require customer linkage on every sale',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(3919,'customers','balance_alert_threshold','1000','decimal','alert','Outstanding balance alert threshold',0,'2026-05-06 15:12:51','2026-05-06 15:12:51'),(5189,'transactions','auto_transaction_numbering','1','boolean','General','Auto Transaction Numbering',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5190,'transactions','or_number_format','OR-{YYYY}{MM}{DD}-{6DIGITS}','string','General','Official Receipt (OR) Number Format',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5191,'transactions','job_order_number_format','JO-{YYYY}{MM}{DD}-{6DIGITS}','string','General','Job Order Number Format',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5192,'transactions','default_transaction_status','pending','string','General','Default Transaction Status',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5193,'transactions','enable_void_transaction','1','boolean','General','Enable Void Transaction Option',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5194,'fuel_management','enable_fuel_reconciliation','1','boolean','General','Enable Fuel Reconciliation',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5195,'fuel_management','enable_calibration','1','boolean','General','Enable Calibration Computations',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5196,'fuel_management','enable_meter_reading_validation','1','boolean','General','Enable Meter Reading Validation',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5197,'fuel_management','decimal_precision','3','integer','General','Decimal Precision for Liters',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5198,'fuel_management','default_fuel_unit','Liters','string','General','Default Fuel Measurement Unit',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5199,'inventory','enable_batch_tracking','1','boolean','General','Enable Batch Tracking',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5200,'inventory','enable_expiration_monitoring','1','boolean','General','Enable Expiration Monitoring',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5201,'inventory','enable_fifo','1','boolean','General','Enable FIFO (First In, First Out)',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5202,'inventory','enable_low_stock_alert','1','boolean','General','Enable Low Stock Alert',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5203,'inventory','enable_critical_stock_alert','1','boolean','General','Enable Critical Stock Alert',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5204,'customers','enable_customer_registration','1','boolean','General','Enable Customer Registration',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5205,'customers','enable_vehicle_history','1','boolean','General','Enable Vehicle Service History',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5206,'customers','enable_credit_account','1','boolean','General','Enable Customer Credit Account',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5207,'customers','enable_fleet_card','1','boolean','General','Enable Fleet Card Integration',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5208,'product_pricing','enable_sku_validation','1','boolean','General','Enable SKU Validation',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5209,'product_pricing','enable_barcode','1','boolean','General','Enable Barcode System',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5210,'product_pricing','enable_price_approval_workflow','1','boolean','General','Enable Price Approval Workflow',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5211,'product_pricing','enable_price_history','1','boolean','General','Enable Price History Auditing',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5212,'calendar','enable_holidays','1','boolean','General','Enable Holidays on Calendar',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5213,'calendar','enable_reminder_notifications','1','boolean','General','Enable Reminder Notifications',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5214,'calendar','enable_maintenance_schedule','1','boolean','General','Enable Maintenance Schedule',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5215,'reports','enable_pdf_export','1','boolean','General','Enable PDF Export',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5216,'reports','enable_excel_export','1','boolean','General','Enable Excel Export',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5217,'reports','enable_csv_export','1','boolean','General','Enable CSV Export',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5218,'reports','default_paper_size','A4','string','General','Default Paper Size',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5219,'reports','report_header','PETRON CORPORATION - STATION REPORT','string','General','Report Header Logo/Text',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5220,'reports','report_footer','Thank you for choosing Petron. This is a system-generated report.','string','General','Report Footer Disclaimer',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5221,'notifications','success_banner_duration','5','integer','General','Success Banner Duration (seconds)',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5222,'notifications','error_banner_duration','10','integer','General','Error Banner Duration (seconds)',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5223,'notifications','enable_low_stock_alert','1','boolean','General','Enable Low Stock Alert',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5224,'notifications','enable_approval_alert','1','boolean','General','Enable Approval Alert',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5225,'notifications','enable_backup_alert','1','boolean','General','Enable Backup Alert',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5226,'backup_restore','enable_scheduled_backup','1','boolean','General','Enable Scheduled Backup',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5227,'backup_restore','backup_frequency','Daily','string','General','Backup Frequency (daily, weekly, monthly)',0,'2026-08-06 06:52:46','2026-08-06 13:44:28'),(5228,'backup_restore','storage_location','Local','string','General','Storage Location',0,'2026-08-06 06:52:46','2026-08-06 13:44:28'),(5229,'backup_restore','retention_period','30 days','integer','General','Retention Period (days)',0,'2026-08-06 06:52:46','2026-08-06 13:44:28'),(5230,'backup_restore','auto_cleanup','1','boolean','General','Auto Cleanup Old Backups',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5231,'audit_trail','enable_audit_logs','1','boolean','General','Enable Audit Logs',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5232,'audit_trail','enable_error_logs','1','boolean','General','Enable Error Logs',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5233,'audit_trail','log_retention','365','integer','General','Log Retention Period (days)',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5234,'audit_trail','auto_archive_logs','1','boolean','General','Auto Archive Logs',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5235,'api_integration','api_status','1','boolean','General','API Status (Active)',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5236,'api_integration','api_keys','petron_live_key_9f81a7b0','string','General','API Keys',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5237,'api_integration','webhook_settings','http://localhost/group31petron_system_official4/api/webhook.php','string','General','Webhook URL',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5238,'api_integration','smtp_email_settings','smtp.gmail.com:587','string','General','SMTP Email Settings',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(5239,'api_integration','sms_gateway','Twilio Gateway SMS Integration (future-ready)','string','General','SMS Gateway (future-ready)',0,'2026-08-06 06:52:46','2026-08-06 06:52:46'),(7648,'dashboard','default_landing_page','dashboard','string','General','Default Landing Page',0,'2026-08-06 13:32:55','2026-08-21 07:55:21'),(7649,'dashboard','dashboard_refresh_interval','30','integer','General','Dashboard Refresh Interval (seconds)',0,'2026-08-06 13:32:55','2026-08-21 07:55:21'),(7650,'dashboard','enable_kpi_cards','1','boolean','General','Enable KPI Cards',0,'2026-08-06 13:32:55','2026-08-21 07:55:21'),(7651,'dashboard','enable_charts','1','boolean','General','Enable Real-time Charts',0,'2026-08-06 13:32:55','2026-08-06 13:32:55'),(7652,'dashboard','enable_quick_actions','1','boolean','General','Enable Quick Actions',0,'2026-08-06 13:32:55','2026-08-21 07:55:21'),(9220,'dashboard','enable_calendar_widget','1','boolean','General','Enable Calendar Widget',0,'2026-08-18 06:31:15','2026-08-21 07:55:21'),(9221,'dashboard','enable_notifications_widget','1','boolean','General','Enable Notifications Widget',0,'2026-08-18 06:31:15','2026-08-21 07:55:21'),(9222,'dashboard','enable_search_bar','1','boolean','General','Enable Search Bar',0,'2026-08-18 06:31:15','2026-08-21 07:55:21'),(10742,'dashboard','module_status','enabled','string','General','Module Status',0,'2026-08-21 07:28:25','2026-08-21 07:55:21');
/*!40000 ALTER TABLE `module_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `module_config_audit`
--

DROP TABLE IF EXISTS `module_config_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `module_config_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(100) NOT NULL,
  `config_key` varchar(100) DEFAULT NULL,
  `action_type` enum('enable','disable','update','create') NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_by_role` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_module_key` (`module_key`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_changed_by_auto` (`changed_by`),
  CONSTRAINT `fk2_module_config_audit_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `module_config_audit`
--

LOCK TABLES `module_config_audit` WRITE;
/*!40000 ALTER TABLE `module_config_audit` DISABLE KEYS */;
INSERT INTO `module_config_audit` VALUES (1,'dashboard',NULL,'disable','null','false',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:34:24'),(2,'dashboard',NULL,'disable','null','false',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:35:51'),(3,'dashboard',NULL,'enable','null','true',1,'superadmin','unknown','unknown','2026-08-21 07:43:12'),(4,'dashboard',NULL,'disable','null','false',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:43:21'),(5,'dashboard',NULL,'disable','null','false',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:44:24'),(6,'dashboard',NULL,'enable','null','true',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:44:37'),(7,'dashboard',NULL,'enable','null','true',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:52:19'),(8,'dashboard',NULL,'enable','null','true',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:54:56'),(9,'dashboard',NULL,'disable','null','false',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:55:15'),(10,'dashboard',NULL,'enable','null','true',1,'superadmin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-21 07:55:21');
/*!40000 ALTER TABLE `module_config_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `module_settings`
--

DROP TABLE IF EXISTS `module_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `module_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(100) NOT NULL,
  `module_code` varchar(100) DEFAULT NULL,
  `module_name` varchar(200) NOT NULL,
  `module_description` text DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `user_access` varchar(100) DEFAULT 'Admin,Manager',
  `module_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `version` varchar(20) DEFAULT 'v1.0',
  `last_updated` varchar(50) DEFAULT 'Aug 05, 2026',
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_key` (`module_key`)
) ENGINE=InnoDB AUTO_INCREMENT=3689 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `module_settings`
--

LOCK TABLES `module_settings` WRITE;
/*!40000 ALTER TABLE `module_settings` DISABLE KEYS */;
INSERT INTO `module_settings` VALUES (1,'transactions','TRANSACTIONS','Transactions','Auto numbering formats, POS transaction defaults, and void approval controls.',1,'Admin, Manager, Staff',2,'2026-04-14 03:32:51','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(3,'fuel_management','FUEL MANAGEMENT','Fuel Management','Fuel reconciliation tolerance rules, calibration computation, and meter validations.',1,'Admin, Manager, Staff',3,'2026-04-14 03:32:51','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(4,'calendar','CALENDAR','Calendar','Public holidays config, shift reminder notifications, and equipment schedules.',1,'Admin, Manager, Staff',7,'2026-04-14 03:32:51','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(5,'reports','REPORTS','Reports','PDF, Excel, and CSV export modules, header/footer branding setups.',1,'Admin, Manager, Staff',8,'2026-04-14 03:32:51','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(851,'inventory','INVENTORY','Inventory','FIFO accounting rules, low/critical stock threshold levels, and batch tracking.',1,'Admin, Manager, Staff',4,'2026-05-06 15:12:51','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(852,'customers','CUSTOMERS','Customers','Customer registration setup, vehicle service history logs, credit and fleet cards.',1,'Admin, Manager, Staff',5,'2026-05-06 15:12:51','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(1143,'dashboard','DASHBOARD','Dashboard','System dashboard KPI cards, real-time charts, and quick action configurations.',1,'Admin, Manager, Staff',1,'2026-08-06 06:52:46','2026-08-21 07:55:21','v1.0','Aug 05, 2026'),(1148,'product_pricing','PRODUCT PRICING','Product & Pricing','SKU/Barcode code validation, price change approval workflows, and price histories.',1,'Admin, Manager, Staff',6,'2026-08-06 06:52:46','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(1151,'notifications','NOTIFICATIONS','Notifications','Alert banner durations, low stock alerts, backups, and approval popups.',1,'Admin, Manager, Staff',9,'2026-08-06 06:52:46','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(1152,'backup_restore','BACKUP RESTORE','Backup & Restore','Automated background DB backups, retention policies, and storage cleanup.',1,'Admin, Manager, Staff',10,'2026-08-06 06:52:46','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(1153,'audit_trail','AUDIT TRAIL','Audit Trail','System error monitoring, log levels, automated database log archiving.',1,'Admin, Manager, Staff',11,'2026-08-06 06:52:46','2026-08-06 13:39:10','v1.0','Aug 05, 2026'),(1154,'api_integration','API INTEGRATION','API / Integration','Third-party REST integrations, webhooks, SMTP, and SMS Gateways.',1,'Admin, Manager, Staff',12,'2026-08-06 06:52:46','2026-08-06 13:39:10','v1.0','Aug 05, 2026');
/*!40000 ALTER TABLE `module_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `module_station_config`
--

DROP TABLE IF EXISTS `module_station_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `module_station_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(100) NOT NULL,
  `station_id` int(11) DEFAULT NULL,
  `config_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`config_data`)),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_station` (`module_key`,`station_id`),
  KEY `idx_module_station_config_updated_by` (`updated_by`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_msc_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `module_station_config`
--

LOCK TABLES `module_station_config` WRITE;
/*!40000 ALTER TABLE `module_station_config` DISABLE KEYS */;
INSERT INTO `module_station_config` VALUES (1,'dashboard',1,'{\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"60\",\"enable_kpi_cards\":true,\"enable_charts\":false,\"enable_quick_actions\":true}',1,'2026-08-06 15:00:46'),(8,'backup_restore',0,'{\"user_access[]\":false,\"backup_frequency\":\"Daily\",\"retention_period\":\"30 days\",\"storage_location\":\"Local\"}',1,'2026-08-06 21:44:28'),(11,'dashboard',0,'{\"module_status\":\"enabled\",\"default_landing_page\":\"dashboard\",\"dashboard_refresh_interval\":\"30\",\"enable_kpi_cards\":true,\"enable_quick_actions\":true,\"enable_calendar_widget\":true,\"enable_notifications_widget\":true,\"enable_search_bar\":true}',1,'2026-08-21 15:55:21');
/*!40000 ALTER TABLE `module_station_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `recipient_role` varchar(30) DEFAULT NULL,
  `type` enum('success','warning','error','info') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `event_type` varchar(80) NOT NULL DEFAULT 'general',
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `source_key` varchar(200) DEFAULT NULL,
  `redirect_url` varchar(500) DEFAULT NULL,
  `reference_type` varchar(80) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `shift_period` varchar(20) DEFAULT NULL,
  `status` enum('unread','read','archived') NOT NULL DEFAULT 'unread',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_notification_user` (`user_id`),
  KEY `idx_notif_user_status` (`user_id`,`status`),
  KEY `idx_notif_source_key` (`source_key`(100)),
  KEY `idx_notif_created_at` (`created_at`),
  KEY `idx_notif_event_type` (`event_type`),
  KEY `idx_ref` (`reference_type`,`reference_id`),
  KEY `idx_shift` (`shift_period`),
  KEY `idx_role` (`recipient_role`),
  CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,13,NULL,'error','Low Fuel Alert: Diesel (UGT #1)','Diesel (UGT #1) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_6_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'read','2026-08-21 04:36:57','2026-08-21 05:46:56'),(2,13,NULL,'error','Low Fuel Alert: Turbo Diesel (UGT #5)','Turbo Diesel (UGT #5) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_7_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(3,13,NULL,'error','Low Fuel Alert: Kerosene (UGT #7)','Kerosene (UGT #7) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_10_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(4,13,NULL,'error','Low Fuel Alert: Diesel 2 (UGT #2)','Diesel 2 (UGT #2) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_31_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(5,13,NULL,'error','Low Fuel Alert: XCS Plus (UGT #3)','XCS Plus (UGT #3) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_32_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(6,13,NULL,'error','Low Fuel Alert: Xtra UNL 1 (UGT #4)','Xtra UNL 1 (UGT #4) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_33_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(7,13,NULL,'error','Low Fuel Alert: Xtra UNL 2 (UGT #6)','Xtra UNL 2 (UGT #6) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','fuel_low_34_20260821','staff_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(8,13,NULL,'error','Low Stock Alert: OIL FILTER O-1012 S','Out of stock: OIL FILTER O-1012 S (P5110).','inventory','critical','low_stock_886_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(9,13,NULL,'error','Low Stock Alert: OIL FILTER FES 5640','Out of stock: OIL FILTER FES 5640 (P5092).','inventory','critical','low_stock_868_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(10,13,NULL,'error','Low Stock Alert: OIL FILTER FES 5321','Out of stock: OIL FILTER FES 5321 (P5091).','inventory','critical','low_stock_867_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(11,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-235','Out of stock: VIC FUEL FILTER FC-235 (P5090).','inventory','critical','low_stock_866_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(12,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-234','Out of stock: VIC FUEL FILTER FC-234 (P5089).','inventory','critical','low_stock_865_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(13,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-510','Out of stock: VIC FUEL FILTER FC-510 (P5088).','inventory','critical','low_stock_864_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(14,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-321','Out of stock: VIC FUEL FILTER FC-321 (P5087).','inventory','critical','low_stock_863_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(15,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-319','Out of stock: VIC FUEL FILTER FC-319 (P5086).','inventory','critical','low_stock_862_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(16,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-317','Out of stock: VIC FUEL FILTER FC-317 (P5085).','inventory','critical','low_stock_861_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(17,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-208A','Out of stock: VIC FUEL FILTER FC-208A (P5084).','inventory','critical','low_stock_860_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(18,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER F-193','Out of stock: VIC FUEL FILTER F-193 (P5083).','inventory','critical','low_stock_859_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(19,13,NULL,'error','Low Stock Alert: VIC FUEL FILTER FC-158','Out of stock: VIC FUEL FILTER FC-158 (P5082).','inventory','critical','low_stock_858_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(20,13,NULL,'error','Low Stock Alert: VIC OIL FILTER STAREX / HYUNDAI','Out of stock: VIC OIL FILTER STAREX / HYUNDAI (P5081).','inventory','critical','low_stock_857_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(21,13,NULL,'error','Low Stock Alert: VIC OIL FILTER C-932','Out of stock: VIC OIL FILTER C-932 (P5080).','inventory','critical','low_stock_856_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(22,13,NULL,'error','Low Stock Alert: VIC OIL FILTER O-586','Out of stock: VIC OIL FILTER O-586 (P5079).','inventory','critical','low_stock_855_20260821','staff_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:36:57',NULL),(24,4,'admin','warning','Low Inventory Alert','269 product(s) at or below minimum stock level. Reorder required.','inventory','high','low_inv_1253','admin_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:38:13',NULL),(25,3,NULL,'error','Low Fuel Alert: Diesel (UGT #1)','Diesel (UGT #1) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_6_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(26,3,NULL,'error','Low Fuel Alert: Turbo Diesel (UGT #5)','Turbo Diesel (UGT #5) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_7_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(27,3,NULL,'error','Low Fuel Alert: Kerosene (UGT #7)','Kerosene (UGT #7) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_10_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(28,3,NULL,'error','Low Fuel Alert: Diesel 2 (UGT #2)','Diesel 2 (UGT #2) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_31_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(29,3,NULL,'error','Low Fuel Alert: XCS Plus (UGT #3)','XCS Plus (UGT #3) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_32_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(30,3,NULL,'error','Low Fuel Alert: Xtra UNL 1 (UGT #4)','Xtra UNL 1 (UGT #4) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_33_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(31,3,NULL,'error','Low Fuel Alert: Xtra UNL 2 (UGT #6)','Xtra UNL 2 (UGT #6) is at 0% capacity (0.00L remaining). Refill needed.','fuel_management','critical','mgr_fuel_low_34_20260821','manager_inventory_fuel.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(32,3,NULL,'error','Inventory Alert: OIL FILTER O-1012 S','OIL FILTER O-1012 S (P5110) — Out of stock.','inventory','critical','mgr_inv_low_886_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(33,3,NULL,'error','Inventory Alert: OIL FILTER FES 5640','OIL FILTER FES 5640 (P5092) — Out of stock.','inventory','critical','mgr_inv_low_868_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(34,3,NULL,'error','Inventory Alert: OIL FILTER FES 5321','OIL FILTER FES 5321 (P5091) — Out of stock.','inventory','critical','mgr_inv_low_867_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(35,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-235','VIC FUEL FILTER FC-235 (P5090) — Out of stock.','inventory','critical','mgr_inv_low_866_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(36,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-234','VIC FUEL FILTER FC-234 (P5089) — Out of stock.','inventory','critical','mgr_inv_low_865_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(37,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-510','VIC FUEL FILTER FC-510 (P5088) — Out of stock.','inventory','critical','mgr_inv_low_864_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(38,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-321','VIC FUEL FILTER FC-321 (P5087) — Out of stock.','inventory','critical','mgr_inv_low_863_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(39,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-319','VIC FUEL FILTER FC-319 (P5086) — Out of stock.','inventory','critical','mgr_inv_low_862_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(40,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-317','VIC FUEL FILTER FC-317 (P5085) — Out of stock.','inventory','critical','mgr_inv_low_861_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(41,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-208A','VIC FUEL FILTER FC-208A (P5084) — Out of stock.','inventory','critical','mgr_inv_low_860_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(42,3,NULL,'error','Inventory Alert: VIC FUEL FILTER F-193','VIC FUEL FILTER F-193 (P5083) — Out of stock.','inventory','critical','mgr_inv_low_859_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(43,3,NULL,'error','Inventory Alert: VIC FUEL FILTER FC-158','VIC FUEL FILTER FC-158 (P5082) — Out of stock.','inventory','critical','mgr_inv_low_858_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(44,3,NULL,'error','Inventory Alert: VIC OIL FILTER STAREX / HYUNDAI','VIC OIL FILTER STAREX / HYUNDAI (P5081) — Out of stock.','inventory','critical','mgr_inv_low_857_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(45,3,NULL,'error','Inventory Alert: VIC OIL FILTER C-932','VIC OIL FILTER C-932 (P5080) — Out of stock.','inventory','critical','mgr_inv_low_856_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'unread','2026-08-21 04:40:19',NULL),(46,3,NULL,'error','Inventory Alert: VIC OIL FILTER O-586','VIC OIL FILTER O-586 (P5079) — Out of stock.','inventory','critical','mgr_inv_low_855_20260821','manager_inventory_merchandise.php',NULL,NULL,NULL,'read','2026-08-21 04:40:19','2026-08-21 04:42:40'),(47,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was disable by developer','config_change','medium','module_config_change_1','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:34:24',NULL),(48,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was disable by developer','config_change','medium','module_config_change_2','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:35:51',NULL),(49,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was disable by developer','config_change','medium','module_config_change_4','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:43:21',NULL),(50,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was enable by developer','config_change','medium','module_config_change_3','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:43:21',NULL),(51,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was disable by developer','config_change','medium','module_config_change_5','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:44:24',NULL),(52,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was enable by developer','config_change','medium','module_config_change_6','reports_developer_audit.php',NULL,NULL,NULL,'read','2026-08-21 07:44:37','2026-08-21 08:29:33'),(53,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was enable by developer','config_change','medium','module_config_change_7','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:52:19',NULL),(54,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was enable by developer','config_change','medium','module_config_change_8','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:54:57',NULL),(55,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was disable by developer','config_change','medium','module_config_change_9','reports_developer_audit.php',NULL,NULL,NULL,'unread','2026-08-21 07:55:15',NULL),(56,1,'superadmin','info','Module Config Changed','Module \'dashboard\' —  was enable by developer','config_change','medium','module_config_change_10','reports_developer_audit.php',NULL,NULL,NULL,'read','2026-08-21 07:55:22','2026-08-21 08:29:25');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `token_type` varchar(50) NOT NULL DEFAULT 'reset',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_password_reset_tokens_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_audit_log`
--

DROP TABLE IF EXISTS `payment_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `record_id` int(11) NOT NULL,
  `record_source` varchar(80) NOT NULL DEFAULT 'merchandise_transactions',
  `staff_id` int(11) DEFAULT NULL,
  `station_id` int(11) NOT NULL DEFAULT 0,
  `amount_paid` decimal(12,2) DEFAULT 0.00,
  `payment_method` varchar(60) DEFAULT NULL,
  `balance_due` decimal(12,2) DEFAULT 0.00,
  `payment_status` varchar(60) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `logged_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rec` (`record_id`,`record_source`),
  KEY `idx_sta` (`station_id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_payment_audit_log_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_audit_log_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_audit_log`
--

LOCK TABLES `payment_audit_log` WRITE;
/*!40000 ALTER TABLE `payment_audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_methods`
--

LOCK TABLES `payment_methods` WRITE;
/*!40000 ALTER TABLE `payment_methods` DISABLE KEYS */;
INSERT INTO `payment_methods` VALUES (1,'Cash','Active','2026-08-08 16:35:07'),(2,'Credit Card','Active','2026-08-08 16:35:07'),(3,'Debit Card','Active','2026-08-08 16:35:07'),(4,'GCash','Active','2026-08-08 16:35:07'),(5,'Maya','Active','2026-08-08 16:35:07'),(6,'Petron Fleet Card','Active','2026-08-08 16:35:07'),(7,'Credit Account','Active','2026-08-08 16:35:07');
/*!40000 ALTER TABLE `payment_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pending_price_approvals`
--

DROP TABLE IF EXISTS `pending_price_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pending_price_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `product_type` varchar(50) NOT NULL DEFAULT 'fuel',
  `product_name` varchar(255) DEFAULT '',
  `field_name` varchar(100) DEFAULT 'price',
  `old_value` decimal(12,2) DEFAULT NULL,
  `new_value` decimal(12,2) DEFAULT 0.00,
  `requested_by` int(11) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewer_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `fuel_type_id` int(11) DEFAULT NULL,
  `service_type_id` int(11) DEFAULT NULL,
  `old_cost` decimal(12,2) DEFAULT 0.00,
  `new_cost` decimal(12,2) DEFAULT 0.00,
  `old_price` decimal(12,2) DEFAULT 0.00,
  `new_price` decimal(12,2) DEFAULT 0.00,
  `manager_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ppa_status` (`status`),
  KEY `idx_ppa_station` (`station_id`),
  KEY `idx_ppa_requested_by` (`requested_by`),
  KEY `fk_pending_price_approvals_reviewed_by` (`reviewed_by`),
  KEY `fk_pending_price_approvals_fuel_type_id` (`fuel_type_id`),
  KEY `fk_pending_price_approvals_manager_id` (`manager_id`),
  KEY `fk_pending_price_approvals_admin_id` (`admin_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_pending_price_approvals_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pending_price_approvals_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pending_price_approvals_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pending_price_approvals_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pending_price_approvals_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pending_price_approvals_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pending_price_approvals`
--

LOCK TABLES `pending_price_approvals` WRITE;
/*!40000 ALTER TABLE `pending_price_approvals` DISABLE KEYS */;
/*!40000 ALTER TABLE `pending_price_approvals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ph_regions`
--

DROP TABLE IF EXISTS `ph_regions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ph_regions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ph_regions`
--

LOCK TABLES `ph_regions` WRITE;
/*!40000 ALTER TABLE `ph_regions` DISABLE KEYS */;
INSERT INTO `ph_regions` VALUES (1,'NCR ??? National Capital Region',1),(2,'CAR ??? Cordillera Administrative Region',2),(3,'Region I ??? Ilocos Region',3),(4,'Region II ??? Cagayan Valley',4),(5,'Region III ??? Central Luzon',5),(6,'Region IV-A ??? CALABARZON',6),(7,'Region IV-B ??? MIMAROPA',7),(8,'Region V ??? Bicol Region',8),(9,'Region VI ??? Western Visayas',9),(10,'Region VII ??? Central Visayas',10),(11,'Region VIII ??? Eastern Visayas',11),(12,'Region IX ??? Zamboanga Peninsula',12),(13,'Region X ??? Northern Mindanao',13),(14,'Region XI ??? Davao Region',14),(15,'Region XII ??? SOCCSKSARGEN',15),(16,'Region XIII ??? Caraga',16),(17,'BARMM ??? Bangsamoro Autonomous Region',17);
/*!40000 ALTER TABLE `ph_regions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_categories`
--

DROP TABLE IF EXISTS `product_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_categories`
--

LOCK TABLES `product_categories` WRITE;
/*!40000 ALTER TABLE `product_categories` DISABLE KEYS */;
INSERT INTO `product_categories` VALUES (1,'Fuel Products','All fuel types and gasoline products','2026-02-07 12:14:22'),(2,'Merchandise','Store merchandise and accessories','2026-02-07 12:14:22'),(3,'Services','Vehicle services and maintenance','2026-02-07 12:14:22'),(4,'Oils/Lubes/Grease','Motor oils, gear oils, greases','2026-02-07 12:14:22'),(5,'Car Accessories','Car care and accessories','2026-02-07 12:14:22'),(6,'Filters','Oil and fuel filters','2026-02-07 12:14:22'),(7,'Drinks/Food','Beverages and convenience food','2026-02-07 12:14:22'),(8,'Snacks','Packaged snack items','2026-02-07 12:14:22'),(9,'VIC Filters','VIC brand filters and parts','2026-02-07 12:14:22'),(10,'Others','Other merchandise items','2026-02-17 19:00:02');
/*!40000 ALTER TABLE `product_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_price_history`
--

DROP TABLE IF EXISTS `product_price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_by_name` varchar(255) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Approved',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_approved_by` (`approved_by`),
  CONSTRAINT `fk_product_price_history_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_product_price_history_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_price_history_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_price_history`
--

LOCK TABLES `product_price_history` WRITE;
/*!40000 ALTER TABLE `product_price_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_price_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_status_history`
--

DROP TABLE IF EXISTS `product_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_by_name` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_status_history_station_id` (`station_id`),
  KEY `idx_product_status_history_product_id` (`product_id`),
  KEY `idx_product_status_history_changed_by` (`changed_by`),
  CONSTRAINT `fk_psh_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_psh_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_psh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_status_history`
--

LOCK TABLES `product_status_history` WRITE;
/*!40000 ALTER TABLE `product_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_types`
--

DROP TABLE IF EXISTS `product_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` enum('fuel','merch','service') NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_types`
--

LOCK TABLES `product_types` WRITE;
/*!40000 ALTER TABLE `product_types` DISABLE KEYS */;
INSERT INTO `product_types` VALUES (1,'fuel','Fuel products'),(2,'merch','Merchandise products'),(3,'service','Service products');
/*!40000 ALTER TABLE `product_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `type_id` int(11) DEFAULT NULL,
  `fuel_type_id` int(11) DEFAULT NULL COMMENT 'Link to fuel_types for fuel products',
  `category_id` int(11) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `min_stock_level` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_stock_level` decimal(12,2) DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `current_stock` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(20) DEFAULT 'pcs',
  `capacity` decimal(12,2) DEFAULT 0.00,
  `status` varchar(50) DEFAULT 'Normal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku` (`sku`),
  KEY `fk_product_type` (`type_id`),
  KEY `fk_product_category` (`category_id`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_fuel_type_id` (`fuel_type_id`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_products_category_id` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_fuel_type_id` FOREIGN KEY (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_type` FOREIGN KEY (`type_id`) REFERENCES `product_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_type_id` FOREIGN KEY (`type_id`) REFERENCES `product_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=950 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (749,'P1001',NULL,'HD 10','Petron',NULL,NULL,NULL,4,2658.44,3057.21,'2026-08-03 00:49:10','2026-08-20 23:45:41',24.00,100.00,1253,0.00,'P/18',0.00,'Out of Stock'),(750,'P1002',NULL,'HD 30','Petron',NULL,NULL,NULL,4,159.00,2318.19,'2026-08-03 00:49:10','2026-08-20 23:45:41',24.00,100.00,1253,0.00,'24/1',0.00,'Out of Stock'),(751,'P1003',NULL,'HD 40','Petron',NULL,NULL,NULL,4,185.00,3194.70,'2026-08-03 00:49:10','2026-08-20 23:45:41',24.00,100.00,1253,0.00,'24/1',0.00,'Out of Stock'),(752,'P1004',NULL,'GEP 90','Petron',NULL,NULL,NULL,4,188.00,3684.60,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Critical'),(753,'P1005',NULL,'GEP 140','Petron',NULL,NULL,NULL,4,191.00,3818.00,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Critical'),(754,'P1006',NULL,'MP GREASE','Petron',NULL,NULL,NULL,4,690.85,5520.66,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'2KG',0.00,'Critical'),(755,'P1007',NULL,'HYDROTUR','Petron',NULL,NULL,NULL,4,3028.00,3482.20,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'P/18',0.00,'Low Stock'),(756,'P1008',NULL,'TREKKER','Petron',NULL,NULL,NULL,4,820.73,3472.78,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'6/4',0.00,'Low Stock'),(757,'P1009',NULL,'ULTRON TOURING','Petron',NULL,NULL,NULL,4,200.85,914.66,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Low Stock'),(758,'P1010',NULL,'ULTRON EXTRA','Petron',NULL,NULL,NULL,4,682.00,784.30,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'6/4',0.00,'Low Stock'),(759,'P1011',NULL,'BLAZE RACING FULLY SYNTHETIC','Petron',NULL,NULL,NULL,4,406.00,1530.65,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(760,'P1012',NULL,'2T AUTOLUBE','Petron',NULL,NULL,NULL,4,37.00,50.00,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,10000.00,1253,0.00,'60/200ml',10000.00,'Normal'),(761,'P1013',NULL,'2T POWERBURN','Petron',NULL,NULL,NULL,4,176.00,40.25,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'24/1',0.00,'Normal'),(762,'P1014',NULL,'SPRINT 4T RIDER','Petron',NULL,NULL,NULL,4,186.00,39.56,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(763,'P1015',NULL,'ALL TERRAIN / REV X FULLY SYN','Petron',NULL,NULL,NULL,4,398.99,458.84,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(764,'P1016',NULL,'ALL TERRAIN / REV X SYNTHETIC BLEND','Petron',NULL,NULL,NULL,4,400.00,460.00,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(765,'P1017',NULL,'BLAZE RACING SYNTHETIC BLEND','Petron',NULL,NULL,NULL,4,381.00,438.15,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(766,'P1018',NULL,'BLAZE RACING EXTRA','Petron',NULL,NULL,NULL,4,176.00,202.40,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(767,'P1019',NULL,'REV-X TREKKER','Petron',NULL,NULL,NULL,4,210.00,241.50,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(768,'P1020',NULL,'M O 30','Petron',NULL,NULL,NULL,4,165.00,189.75,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(769,'P1021',NULL,'M O 40','Petron',NULL,NULL,NULL,4,162.00,186.30,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'24/1',0.00,'Normal'),(770,'P1022',NULL,'ATF PREMIUM','Petron',NULL,NULL,NULL,4,200.00,230.00,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'24/1',0.00,'Normal'),(771,'P1023',NULL,'ATF HTF','Petron',NULL,NULL,NULL,4,200.00,230.00,'2026-08-03 00:49:10','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(772,'P1024',NULL,'ENDURO','Petron',NULL,NULL,NULL,4,212.00,243.80,'2026-08-03 00:49:11','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'12/1',0.00,'Normal'),(773,'P1025',NULL,'OIL SAVER','Petron',NULL,NULL,NULL,5,135.03,155.28,'2026-08-03 00:49:11','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'425ml',0.00,'Normal'),(774,'P1026',NULL,'ENGINE FLUSH - PETRON','Petron',NULL,NULL,NULL,5,148.00,170.20,'2026-08-03 00:49:11','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'500ml',0.00,'Normal'),(775,'P1027',NULL,'ENGINE FLUSH - HARDEX','Hardex',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:11','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'440ml',0.00,'Normal'),(776,'P1028',NULL,'BLUE SPRAY WASHER FLUID','Generic',NULL,NULL,NULL,5,85.00,97.75,'2026-08-03 00:49:11','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'100ml',0.00,'Normal'),(777,'P5001',NULL,'HD 10','Petron',NULL,NULL,NULL,4,2658.44,3057.21,'2026-08-03 00:49:58','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'P/18',0.00,'Normal'),(778,'P5002',NULL,'HD 30','Petron',NULL,NULL,NULL,4,2015.82,2318.19,'2026-08-03 00:49:58','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'P/18',0.00,'Normal'),(779,'P5003',NULL,'COOLANT','Generic',NULL,NULL,NULL,5,102.00,117.30,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'500ml',0.00,'Normal'),(780,'P5004',NULL,'COOLANT GREEN','Coolant',NULL,NULL,NULL,5,143.00,200.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'1L',0.00,'active'),(781,'P5005',NULL,'COOLANT PINK','Generic',NULL,NULL,NULL,5,143.00,164.45,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'1L',0.00,'Normal'),(782,'P5006',NULL,'WD 40 191ML','WD-40',NULL,NULL,NULL,5,210.00,241.50,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'6oz',0.00,'Normal'),(783,'P5007',NULL,'SILICON OIL','Generic',NULL,NULL,NULL,5,88.00,101.20,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(784,'P5008',NULL,'PETROMATE PENETRATING OIL','Petron',NULL,NULL,NULL,5,178.00,204.70,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'450ml',0.00,'Normal'),(785,'P5009',NULL,'TIRE BLACK SM.','Generic',NULL,NULL,NULL,5,85.00,97.75,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(786,'P5010',NULL,'TIRE BLACK BIG','Generic',NULL,NULL,NULL,5,185.00,212.75,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(787,'P5011',NULL,'TURTLE WAX HARD SHELL (SOFT PASTE)','Turtle Wax',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(788,'P5012',NULL,'TURTLE WAX HARD LIQUID','Turtle Wax',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(789,'P5013',NULL,'POWER BOOSTER','Generic',NULL,NULL,NULL,5,51.00,58.65,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(790,'P5014',NULL,'CLEAN N SHINE SHAMPOO','Generic',NULL,NULL,NULL,5,71.69,82.44,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(791,'P5015',NULL,'VS 1 PROTECTOR SMALL','VS1',NULL,NULL,NULL,5,140.00,161.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(792,'P5016',NULL,'VS 1 PROTECTOR BIG','VS1',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(793,'P5017',NULL,'ARMOR ALL SM','Armor All',NULL,NULL,NULL,5,140.00,161.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(794,'P5018',NULL,'ARMOR ALL BIG','Armor All',NULL,NULL,NULL,5,0.00,400.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'Piece (pc)',0.00,'active'),(795,'P5019',NULL,'WIPER WASH','Wiper',NULL,NULL,NULL,5,185.00,212.75,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'2L',0.00,'Normal'),(796,'P5020',NULL,'GAS SAVER','Generic',NULL,NULL,NULL,5,57.37,65.98,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(797,'P5021',NULL,'NEO SHALDAN','Shaldan',NULL,NULL,NULL,5,155.00,178.25,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(798,'P5022',NULL,'TOPIAS FRESHENER','Topias',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(799,'P5023',NULL,'LITTLE TREES','Little Trees',NULL,NULL,NULL,5,50.00,57.50,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(800,'P5024',NULL,'CALIFORNIA SCENTS','California Scents',NULL,NULL,NULL,5,195.00,224.25,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(801,'P5025',NULL,'GLADE SPRAY','Glade',NULL,NULL,NULL,5,245.00,281.75,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(802,'P5026',NULL,'BRAKE FLUID 900 ML','Petron',NULL,NULL,NULL,5,268.00,308.20,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'900ml',0.00,'Normal'),(803,'P5027',NULL,'BRAKE FLUID MED','Petron',NULL,NULL,NULL,5,89.00,102.35,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'500ml',0.00,'Normal'),(804,'P5028',NULL,'BRAKE FLUID SM.','Petron',NULL,NULL,NULL,5,59.00,67.85,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'250ml',0.00,'Normal'),(805,'P5029',NULL,'TIRE VALVE RUBBER','Generic',NULL,NULL,NULL,5,12.00,13.80,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(806,'P5030',NULL,'TIRE VALVE STEEL','Generic',NULL,NULL,NULL,5,60.00,69.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(807,'P5031',NULL,'RZ AUTO TIRE SEAL','RZ',NULL,NULL,NULL,5,320.00,368.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(808,'P5032',NULL,'GASKET MAKER','Generic',NULL,NULL,NULL,5,55.00,63.25,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(809,'P5033',NULL,'CHAMOIS / KANEBO','Kanebo',NULL,NULL,NULL,5,330.00,379.50,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(810,'P5034',NULL,'PATCH #11','Generic',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(811,'P5035',NULL,'PATCH #12','Generic',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(812,'P5036',NULL,'BRAKE CLEANER HARDEX','Hardex',NULL,NULL,NULL,5,245.00,281.75,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'400ml',0.00,'Normal'),(813,'P5037',NULL,'VALKARN CEMENT','Valkarn',NULL,NULL,NULL,5,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(814,'P5038',NULL,'MP 1 (MED) PATCH','Generic',NULL,NULL,NULL,5,42.50,48.88,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(815,'P5039',NULL,'MP 2 (LARGE) PATCH','Generic',NULL,NULL,NULL,5,132.00,151.80,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(816,'P5040',NULL,'CT 20 RADIAL PATCH','Generic',NULL,NULL,NULL,5,132.00,151.80,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(817,'P5041',NULL,'SAKURA F-1508','Sakura',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(818,'P5042',NULL,'SAKURA FC-1510','Sakura',NULL,NULL,NULL,6,510.00,586.50,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(819,'P5043',NULL,'OIL FILTER SPARK- 65400','Spark',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:49:59','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(820,'P5044',NULL,'NOMIS OIL FILTER SPARK-NLT 060','Nomis',NULL,NULL,NULL,6,380.00,437.00,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(821,'P5045',NULL,'VIC OIL FILTER C-034','VIC',NULL,NULL,NULL,6,279.00,320.85,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(822,'P5046',NULL,'VIC OIL FILTER C-101','VIC',NULL,NULL,NULL,6,245.00,281.75,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(823,'P5047',NULL,'VIC OIL FILTER C-106','VIC',NULL,NULL,NULL,6,168.00,193.20,'2026-08-03 00:49:59','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(824,'P5048',NULL,'VIC OIL FILTER C-110','VIC',NULL,NULL,NULL,6,155.00,178.25,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(825,'P5049',NULL,'VIC OIL FILTER C-111','VIC',NULL,NULL,NULL,6,168.00,193.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(826,'P5050',NULL,'VIC OIL FILTER C-112','VIC',NULL,NULL,NULL,6,372.00,427.80,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(827,'P5051',NULL,'VIC OIL FILTER C-115','VIC',NULL,NULL,NULL,6,425.00,488.75,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(828,'P5052',NULL,'VIC OIL FILTER O-119','VIC',NULL,NULL,NULL,6,168.00,193.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(829,'P5053',NULL,'VIC OIL FILTER C-204','VIC',NULL,NULL,NULL,6,241.00,277.15,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(830,'P5054',NULL,'VIC OIL FILTER C-206','VIC',NULL,NULL,NULL,6,223.00,256.45,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(831,'P5055',NULL,'VIC OIL FILTER C-207','VIC',NULL,NULL,NULL,6,177.00,203.55,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(832,'P5056',NULL,'VIC OIL FILTER C-209','VIC',NULL,NULL,NULL,6,238.00,273.70,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(833,'P5057',NULL,'VIC OIL FILTER C-226','VIC',NULL,NULL,NULL,6,378.00,434.70,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(834,'P5058',NULL,'VIC OIL FILTER C-303','VIC',NULL,NULL,NULL,6,168.00,193.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(835,'P5059',NULL,'VIC OIL FILTER C-304','VIC',NULL,NULL,NULL,6,166.00,190.90,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(836,'P5060',NULL,'VIC OIL FILTER C-305','VIC',NULL,NULL,NULL,6,410.00,471.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(837,'P5061',NULL,'VIC OIL FILTER C-306','VIC',NULL,NULL,NULL,6,434.00,499.10,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(838,'P5062',NULL,'VIC OIL FILTER C-312','VIC',NULL,NULL,NULL,6,172.00,197.80,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(839,'P5063',NULL,'VIC OIL FILTER C-313','VIC',NULL,NULL,NULL,6,542.00,623.30,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(840,'P5064',NULL,'VIC OIL FILTER C-405','VIC',NULL,NULL,NULL,6,168.00,193.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(841,'P5065',NULL,'VIC OIL FILTER C-406','VIC',NULL,NULL,NULL,6,168.00,193.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(842,'P5066',NULL,'VIC OIL FILTER O-407 A','VIC',NULL,NULL,NULL,6,281.00,323.15,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(843,'P5067',NULL,'VIC OIL FILTER C-412','VIC',NULL,NULL,NULL,6,422.00,485.30,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(844,'P5068',NULL,'VIC OIL FILTER C-415','VIC',NULL,NULL,NULL,6,161.00,185.15,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(845,'P5069',NULL,'VIC OIL FILTER C-502','VIC',NULL,NULL,NULL,6,232.00,266.80,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(846,'P5070',NULL,'VIC OIL FILTER C-503','VIC',NULL,NULL,NULL,6,284.00,326.60,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(847,'P5071',NULL,'VIC OIL FILTER C-506','VIC',NULL,NULL,NULL,6,342.00,393.30,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(848,'P5072',NULL,'VIC OIL FILTER C-512','VIC',NULL,NULL,NULL,6,228.00,262.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(849,'P5073',NULL,'VIC OIL FILTER C-513','VIC',NULL,NULL,NULL,6,190.00,218.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(850,'P5074',NULL,'VIC OIL FILTER C-519','VIC',NULL,NULL,NULL,6,496.00,570.40,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(851,'P5075',NULL,'VIC OIL FILTER C-524','VIC',NULL,NULL,NULL,6,250.00,287.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(852,'P5076',NULL,'VIC OIL FILTER C-526','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(853,'P5077',NULL,'VIC OIL FILTER C-527','VIC',NULL,NULL,NULL,6,208.00,239.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(854,'P5078',NULL,'VIC OIL FILTER C-529','VIC',NULL,NULL,NULL,6,273.00,313.95,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(855,'P5079',NULL,'VIC OIL FILTER O-586','VIC',NULL,NULL,NULL,6,294.00,338.10,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(856,'P5080',NULL,'VIC OIL FILTER C-932','VIC',NULL,NULL,NULL,6,149.00,171.35,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(857,'P5081',NULL,'VIC OIL FILTER STAREX / HYUNDAI','VIC',NULL,NULL,NULL,6,280.00,322.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(858,'P5082',NULL,'VIC FUEL FILTER FC-158','VIC',NULL,NULL,NULL,6,572.00,657.80,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(859,'P5083',NULL,'VIC FUEL FILTER F-193','VIC',NULL,NULL,NULL,6,238.00,273.70,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(860,'P5084',NULL,'VIC FUEL FILTER FC-208A','VIC',NULL,NULL,NULL,6,219.00,251.85,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(861,'P5085',NULL,'VIC FUEL FILTER FC-317','VIC',NULL,NULL,NULL,6,269.00,309.35,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(862,'P5086',NULL,'VIC FUEL FILTER FC-319','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(863,'P5087',NULL,'VIC FUEL FILTER FC-321','VIC',NULL,NULL,NULL,6,657.00,755.55,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(864,'P5088',NULL,'VIC FUEL FILTER FC-510','VIC',NULL,NULL,NULL,6,633.00,727.95,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(865,'P5089',NULL,'VIC FUEL FILTER FC-234','VIC',NULL,NULL,NULL,6,738.00,848.70,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(866,'P5090',NULL,'VIC FUEL FILTER FC-235','VIC',NULL,NULL,NULL,6,676.00,777.40,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(867,'P5091',NULL,'OIL FILTER FES 5321','Fleetmax',NULL,NULL,NULL,6,270.00,310.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(868,'P5092',NULL,'OIL FILTER FES 5640','Fleetmax',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(869,'P5093',NULL,'OIL FILTER (SORENTO) FO-2112/27420','Generic',NULL,NULL,NULL,6,320.00,368.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(870,'P5094',NULL,'OIL FILTER FES 5712','Fleetmax',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(871,'P5095',NULL,'OIL FILTER FES 5342','Fleetmax',NULL,NULL,NULL,6,270.00,310.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(872,'P5096',NULL,'OIL FILTER C-525','VIC',NULL,NULL,NULL,6,1108.00,1274.20,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(873,'P5097',NULL,'FUEL FILTER FFS-1530','Fleetmax',NULL,NULL,NULL,6,520.00,598.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(874,'P5098',NULL,'FUEL FILTER FFS-1501','Fleetmax',NULL,NULL,NULL,6,435.00,500.25,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(875,'P5099',NULL,'HOWO FUEL FILTER VG1560080012','Howo',NULL,NULL,NULL,6,450.00,517.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(876,'P5100',NULL,'HOWO OIL FILTER-186 1012000','Howo',NULL,NULL,NULL,6,520.00,598.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(877,'P5101',NULL,'OIL FILTER C-223','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(878,'P5102',NULL,'OIL FILTER C-509A','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(879,'P5103',NULL,'OIL FILTER C-510A','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(880,'P5104',NULL,'FUEL FILTER FC-322','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(881,'P5105',NULL,'FUEL FILTER FC-326','VIC',NULL,NULL,NULL,6,318.00,365.70,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(882,'P5106',NULL,'OIL FILTER DAI-WA DU 581','Dai-Wa',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(883,'P5107',NULL,'OIL FILTER YO-581','Generic',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(884,'P5108',NULL,'OIL FILTER EO-581','Generic',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(885,'P5109',NULL,'OIL FILTER EO-568','Generic',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(886,'P5110',NULL,'OIL FILTER O-1012 S','Generic',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(887,'P5111',NULL,'OIL FILTER 94797406','Generic',NULL,NULL,NULL,6,350.00,402.50,'2026-08-03 00:50:00','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(888,'P5112',NULL,'FUJIITO 5262313','Fujiito',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(889,'P5113',NULL,'FUJIITO 5266016','Fujiito',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(890,'P5114',NULL,'FUJIITO 5262311','Fujiito',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(891,'P5115',NULL,'FUJIITO 5264870','Fujiito',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(892,'P5116',NULL,'FLEETMAX FES 5715','Fleetmax',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(893,'P5117',NULL,'FLEETMAX FES 5714','Fleetmax',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(894,'P5118',NULL,'FLEETMAX FES 5708','Fleetmax',NULL,NULL,NULL,6,370.00,425.50,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(895,'P5119',NULL,'OIL FILTER FES 5583','Fleetmax',NULL,NULL,NULL,6,350.00,402.50,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(896,'P5120',NULL,'OIL FILTER C-419','VIC',NULL,NULL,NULL,6,921.00,1059.15,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(897,'P5121',NULL,'OIL FILTER C-231','VIC',NULL,NULL,NULL,6,241.00,277.15,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(898,'P5122',NULL,'OIL FILTER C-232','VIC',NULL,NULL,NULL,6,191.00,219.65,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(899,'P5123',NULL,'OIL FILTER C-707','VIC',NULL,NULL,NULL,6,153.00,175.95,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(900,'P5124',NULL,'OIL FILTER O-010','VIC',NULL,NULL,NULL,6,526.00,604.90,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(901,'P5125',NULL,'OIL FILTER O-008','VIC',NULL,NULL,NULL,6,316.00,363.40,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(902,'P5126',NULL,'OIL FILTER C-039','VIC',NULL,NULL,NULL,6,243.00,279.45,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(903,'P5127',NULL,'OIL FILTER FES-5583 CAMRY','Fleetmax',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(904,'P5128',NULL,'OIL FILTER C-117 MG','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(905,'P5129',NULL,'OIL FILTER C -010','VIC',NULL,NULL,NULL,6,110.00,150.00,'2026-08-03 00:50:01','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(906,'P5130',NULL,'FUEL FILTER FC -017','VIC',NULL,NULL,NULL,6,687.00,790.05,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(907,'P5131',NULL,'FUEL FILTER F-197','VIC',NULL,NULL,NULL,6,453.00,520.95,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(908,'P5132',NULL,'FUEL FILTER FFS-1478 (NAVARA)','Fleetmax',NULL,NULL,NULL,6,840.00,966.00,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(909,'P5133',NULL,'OIL FILTER FES -5617','Fleetmax',NULL,NULL,NULL,6,285.00,327.75,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(910,'P5134',NULL,'OIL FILTER C-5614','VIC',NULL,NULL,NULL,6,450.00,517.50,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(911,'P5135',NULL,'Coca-Cola Mismo','Coca-Cola',NULL,NULL,NULL,7,16.75,19.26,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'MISMO',0.00,'Normal'),(912,'P5136',NULL,'Sprite Mismo','Sprite',NULL,NULL,NULL,7,16.75,19.26,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'MISMO',0.00,'Normal'),(913,'P5137',NULL,'Royal Mismo','Royal',NULL,NULL,NULL,7,16.75,19.26,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'MISMO',0.00,'Normal'),(914,'P5138',NULL,'Coca-Cola Swakto','Coca-Cola',NULL,NULL,NULL,7,12.25,14.09,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'SWAKTO',0.00,'Normal'),(915,'P5139',NULL,'Sprite Swakto','Sprite',NULL,NULL,NULL,7,12.25,14.09,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'SWAKTO',0.00,'Normal'),(916,'P5140',NULL,'Royal Swakto','Royal',NULL,NULL,NULL,7,12.25,14.09,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'SWAKTO',0.00,'Normal'),(917,'P5141',NULL,'Coca-Cola (1.5L)','Coca-Cola',NULL,NULL,NULL,7,63.87,73.45,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'1.5LTR',0.00,'Normal'),(918,'P5142',NULL,'Sprite (1.5L)','Sprite',NULL,NULL,NULL,7,63.87,73.45,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'1.5LTR',0.00,'Normal'),(919,'P5143',NULL,'Mineral Water - Nature\'s Spring','Nature\'s Spring',NULL,NULL,NULL,7,8.57,15.99,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'500ML',0.00,'Normal'),(920,'P5144',NULL,'Gatorade','Gatorade',NULL,NULL,NULL,7,41.30,47.50,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(921,'P5145',NULL,'Skyflakes Singles','Skyflakes',NULL,NULL,NULL,8,5.41,6.22,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(922,'P5146',NULL,'Presto Singles','Presto',NULL,NULL,NULL,8,5.84,6.72,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(923,'P5147',NULL,'Presto Slugs','Presto',NULL,NULL,NULL,8,17.75,20.41,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(924,'P5148',NULL,'Butter Coconut Slugs','Monde',NULL,NULL,NULL,8,21.95,25.24,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(925,'P5149',NULL,'Sweetcorn Big','Sweetcorn',NULL,NULL,NULL,8,15.85,18.23,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(926,'P5150',NULL,'Fita Singles','MY San',NULL,NULL,NULL,8,5.94,6.83,'2026-08-03 00:50:01','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(927,'P5151',NULL,'Fita Slugs','MY San',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(928,'P5152',NULL,'Oishi Prawn Crackers','Oishi',NULL,NULL,NULL,8,15.15,17.42,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(929,'P5153',NULL,'Clover Chips Big','Leslie\'s',NULL,NULL,NULL,8,24.15,27.77,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'BIG',0.00,'Normal'),(930,'P5154',NULL,'Piattos Big','Jack \'n Jill',NULL,NULL,NULL,8,33.20,38.18,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(931,'P5155',NULL,'Roller Coaster','Jack \'n Jill',NULL,NULL,NULL,8,23.85,27.43,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(932,'P5156',NULL,'Cheese Ring Big','Regent',NULL,NULL,NULL,8,15.80,18.17,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(933,'P5157',NULL,'Chiz Curls Big','Jack \'n Jill',NULL,NULL,NULL,8,19.55,22.48,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(934,'P5158',NULL,'Breadstix Family','MY San',NULL,NULL,NULL,8,33.90,38.99,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(935,'P5159',NULL,'Potato Fries','Jack \'n Jill',NULL,NULL,NULL,8,14.70,16.91,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(936,'P5160',NULL,'Snacku Big','Regent',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(937,'P5161',NULL,'Snacku Small','Regent',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(938,'P5162',NULL,'Cream O 360g','Universal Robina',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'360g',0.00,'Normal'),(939,'P5163',NULL,'Cream O 330g','Universal Robina',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'330g',0.00,'Normal'),(940,'P5164',NULL,'Eggnog','Monde',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(941,'P5165',NULL,'Mr. Chips Big','Jack \'n Jill',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(942,'P5166',NULL,'Lucky Me Sotanghon','Lucky Me',NULL,NULL,NULL,7,25.80,29.67,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(943,'P5167',NULL,'Lucky Me Jjampong Small','Lucky Me',NULL,NULL,NULL,7,25.75,29.61,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'SMALL',0.00,'Normal'),(944,'P5168',NULL,'Lucky Me Jjampong Big 70g','Lucky Me',NULL,NULL,NULL,7,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'70g',0.00,'Normal'),(945,'P5169',NULL,'Nissin Cup Noodles','Nissin',NULL,NULL,NULL,7,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(946,'P5170',NULL,'Cracklings Big','Oishi',NULL,NULL,NULL,8,14.75,16.96,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(947,'P5171',NULL,'Pringles','Pringles',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(948,'P5172',NULL,'Marty\'s Big','Oishi',NULL,NULL,NULL,8,110.00,150.00,'2026-08-03 00:50:02','2026-08-07 22:08:06',24.00,100.00,1253,0.00,'pcs',0.00,'Normal'),(949,'P5173',NULL,'Nova','Jack \'n Jill',NULL,NULL,NULL,8,34.10,39.22,'2026-08-03 00:50:02','2026-08-21 12:36:17',24.00,100.00,1253,0.00,'pcs',0.00,'Normal');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity_ordered` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `quantity_received` int(11) DEFAULT 0,
  `received_at` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_quantity` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_poi_po` (`po_id`),
  KEY `fk_poi_product` (`product_id`),
  KEY `fk_poi_receiver` (`received_by`),
  CONSTRAINT `fk_purchase_order_items_po_id` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_order_items_product_id` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `po_number` varchar(50) NOT NULL,
  `station_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `status` enum('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received','Cancelled','Pending Admin Validation','Official','Approved PO','Admin Finalized','Rejected by Admin','Completed') DEFAULT 'Pending Admin Validation',
  `expected_delivery_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rejection_reason` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `admin_finalized` tinyint(1) NOT NULL DEFAULT 0,
  `admin_finalized_at` datetime DEFAULT NULL,
  `stock_in_done` tinyint(1) NOT NULL DEFAULT 0,
  `delivery_validated` tinyint(1) NOT NULL DEFAULT 0,
  `delivery_validated_at` datetime DEFAULT NULL,
  `delivery_flag` enum('OK','Short','Damaged','Excess','Mixed') DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `stock_in_at` datetime DEFAULT NULL,
  `stock_in_by` int(11) DEFAULT NULL,
  `batch_id` varchar(100) DEFAULT NULL,
  `delivery_validated_by` int(11) DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `actual_qty_received` int(11) DEFAULT NULL,
  `supplier_name` varchar(200) DEFAULT NULL,
  `expected_delivery` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_po_station` (`station_id`),
  KEY `fk_po_supplier` (`supplier_id`),
  KEY `fk_po_creator` (`created_by`),
  KEY `idx_approved_by_auto` (`approved_by`),
  KEY `idx_request_id_auto` (`request_id`),
  KEY `fk_purchase_orders_admin_id` (`admin_id`),
  CONSTRAINT `fk2_purchase_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_orders_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_orders_approved_by_9bc6` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_orders_request_id_5eb3` FOREIGN KEY (`request_id`) REFERENCES `stock_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_orders_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_purchase_orders_supplier_id` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `restore_logs`
--

DROP TABLE IF EXISTS `restore_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `restore_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_name` varchar(255) NOT NULL,
  `restored_by` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'success',
  `details` text DEFAULT NULL,
  `restored_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_rl_restored_by` (`restored_by`),
  CONSTRAINT `fk_rl_restored_by` FOREIGN KEY (`restored_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `restore_logs`
--

LOCK TABLES `restore_logs` WRITE;
/*!40000 ALTER TABLE `restore_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `restore_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_categories`
--

DROP TABLE IF EXISTS `service_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `default_parts_cost` decimal(10,2) DEFAULT 0.00,
  `default_labor_cost` decimal(10,2) DEFAULT 0.00,
  `default_duration` int(11) DEFAULT 60,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_service_category_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_categories`
--

LOCK TABLES `service_categories` WRITE;
/*!40000 ALTER TABLE `service_categories` DISABLE KEYS */;
INSERT INTO `service_categories` VALUES (1,'Oil Change','Complete oil and filter change',500.00,300.00,45,1,'2026-02-07 12:14:22'),(2,'Tire Rotation','Rotate all four tires',0.00,400.00,30,1,'2026-02-07 12:14:22'),(3,'Car Wash','Exterior and interior cleaning',0.00,250.00,60,1,'2026-02-07 12:14:22'),(4,'Brake Service','Brake pad replacement and service',1500.00,800.00,120,1,'2026-02-07 12:14:22'),(5,'Engine Tune-up','Spark plugs, filters, diagnostics',1200.00,1000.00,90,1,'2026-02-07 12:14:22'),(6,'Battery Replacement','Remove old battery, install new',1800.00,200.00,30,1,'2026-02-07 12:14:22'),(7,'AC Service','AC cleaning and refrigerant recharge',800.00,600.00,60,1,'2026-02-07 12:14:22'),(9,'Change Oil','Engine oil change and filter replacement',500.00,200.00,30,1,'2026-02-09 10:08:09'),(10,'Vulcanizing','Tire repair and patching services',200.00,150.00,20,1,'2026-02-09 10:08:09'),(11,'Battery Check','Battery testing and replacement if needed',1500.00,150.00,20,1,'2026-02-09 10:08:09'),(12,'Air Filter Replacement','Air filter inspection and replacement',300.00,100.00,15,1,'2026-02-09 10:08:09'),(13,'Wheel Alignment','Wheel alignment and balancing',500.00,400.00,60,1,'2026-02-09 10:08:09'),(14,'Transmission Service','Transmission fluid change and inspection',1800.00,1000.00,90,1,'2026-02-09 10:08:09'),(15,'General Inspection','Complete vehicle safety inspection',0.00,300.00,45,1,'2026-02-09 10:08:09');
/*!40000 ALTER TABLE `service_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_fee_history`
--

DROP TABLE IF EXISTS `service_fee_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_fee_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) DEFAULT NULL,
  `change_type` enum('service_fee','labor_fee','created','updated','activated','deactivated') NOT NULL DEFAULT 'service_fee',
  `old_service_fee` decimal(10,2) DEFAULT NULL,
  `new_service_fee` decimal(10,2) DEFAULT NULL,
  `old_labor_fee` decimal(10,2) DEFAULT NULL,
  `new_labor_fee` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_by_name` varchar(100) DEFAULT NULL,
  `changed_by_role` varchar(50) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected','direct') NOT NULL DEFAULT 'direct',
  `approval_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_service_id` (`service_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_changed_by` (`changed_by`),
  CONSTRAINT `fk_service_fee_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sfh_service` FOREIGN KEY (`service_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_fee_history`
--

LOCK TABLES `service_fee_history` WRITE;
/*!40000 ALTER TABLE `service_fee_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_fee_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_parts_mapping`
--

DROP TABLE IF EXISTS `service_parts_mapping`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_parts_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_key` varchar(50) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `default_quantity` int(11) NOT NULL DEFAULT 1,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_service_key` (`service_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_parts_mapping`
--

LOCK TABLES `service_parts_mapping` WRITE;
/*!40000 ALTER TABLE `service_parts_mapping` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_parts_mapping` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_rates`
--

DROP TABLE IF EXISTS `service_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_category_id` int(11) DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `rate_name` varchar(100) NOT NULL,
  `flat_rate` decimal(10,2) NOT NULL,
  `estimated_duration` int(11) DEFAULT 60,
  `is_active` tinyint(1) DEFAULT 1,
  `effective_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_rate_service_category` (`service_category_id`),
  KEY `fk_rate_station` (`station_id`),
  CONSTRAINT `fk_service_rates_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_srates_category` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_rates`
--

LOCK TABLES `service_rates` WRITE;
/*!40000 ALTER TABLE `service_rates` DISABLE KEYS */;
INSERT INTO `service_rates` VALUES (1,1,1253,'Oil Change - Standard',500.00,45,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(2,1,1253,'Oil Change - Premium',800.00,60,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(3,2,1253,'Tire Rotation',400.00,30,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(4,3,1253,'Car Wash - Basic',250.00,60,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(5,3,1253,'Car Wash - Premium',500.00,90,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(6,4,1253,'Brake Service - Front',1500.00,120,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(7,4,1253,'Brake Service - Full',2500.00,180,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(8,5,1253,'Engine Tune-up',1200.00,90,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(9,6,1253,'Battery Replacement',1800.00,30,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(10,7,1253,'AC Service',800.00,60,1,NULL,'2026-02-07 12:14:22','2026-08-18 23:51:37'),(11,1,1253,'Oil Change - Standard',500.00,45,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(12,1,1253,'Oil Change - Premium',800.00,60,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(13,2,1253,'Tire Rotation',400.00,30,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(14,3,1253,'Car Wash - Basic',250.00,60,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(15,3,1253,'Car Wash - Premium',500.00,90,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(16,4,1253,'Brake Service - Front',1500.00,120,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(17,4,1253,'Brake Service - Full',2500.00,180,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(18,5,1253,'Engine Tune-up',1200.00,90,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(19,6,1253,'Battery Replacement',1800.00,30,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37'),(20,7,1253,'AC Service',800.00,60,1,NULL,'2026-02-07 12:14:23','2026-08-18 23:51:37');
/*!40000 ALTER TABLE `service_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shift_periods`
--

DROP TABLE IF EXISTS `shift_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_key` varchar(20) NOT NULL,
  `shift_name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `shift_key` (`shift_key`),
  KEY `idx_shift_key` (`shift_key`),
  KEY `idx_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shift_periods`
--

LOCK TABLES `shift_periods` WRITE;
/*!40000 ALTER TABLE `shift_periods` DISABLE KEYS */;
INSERT INTO `shift_periods` VALUES (1,'first','First Shift: 6:00 AM - 2:00 PM','06:00:00','14:00:00','Morning shift for staff',1,1,'2026-08-07 06:02:12','2026-08-07 06:02:12'),(2,'second','Second Shift: 2:00 PM - 12:00 Midnight','14:00:00','23:59:59','Afternoon shift for staff',1,2,'2026-08-07 06:02:12','2026-08-07 06:02:12');
/*!40000 ALTER TABLE `shift_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shifts`
--

DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shifts`
--

LOCK TABLES `shifts` WRITE;
/*!40000 ALTER TABLE `shifts` DISABLE KEYS */;
INSERT INTO `shifts` VALUES (1,'First Shift (6:00 AM - 2:00 PM)','06:00:00','14:00:00','Morning shift',1,1,'2026-08-07 14:02:12','2026-08-07 14:02:12'),(2,'Second Shift (2:00 PM - 12:00 AM)','14:00:00','23:59:59','Afternoon/Night shift',2,1,'2026-08-07 14:02:12','2026-08-07 14:02:12');
/*!40000 ALTER TABLE `shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_color_config`
--

DROP TABLE IF EXISTS `staff_color_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_color_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `color_code` varchar(7) NOT NULL DEFAULT '#007bff',
  `color_name` varchar(50) DEFAULT 'Blue',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_color` (`user_id`),
  CONSTRAINT `staff_color_config_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_color_config`
--

LOCK TABLES `staff_color_config` WRITE;
/*!40000 ALTER TABLE `staff_color_config` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff_color_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_event_types`
--

DROP TABLE IF EXISTS `staff_event_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_event_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `type_key` varchar(50) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon_class` varchar(50) DEFAULT 'fas fa-calendar',
  `color_class` varchar(50) DEFAULT 'text-primary',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_key` (`type_key`),
  KEY `station_id` (`station_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_sevt_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sevt_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_event_types`
--

LOCK TABLES `staff_event_types` WRITE;
/*!40000 ALTER TABLE `staff_event_types` DISABLE KEYS */;
INSERT INTO `staff_event_types` VALUES (1,NULL,NULL,'staff_shift','Staff Shift','Shift assignment or schedule','fas fa-clock','text-primary',10,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(2,NULL,NULL,'job_order','Job Order','Job order schedule or activity','fas fa-wrench','text-warning',20,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(3,NULL,NULL,'fuel_delivery','Fuel Delivery','Fuel delivery schedule','fas fa-gas-pump','text-danger',30,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(4,NULL,NULL,'merchandise_delivery','Merchandise Delivery','Merchandise delivery schedule','fas fa-box','text-info',40,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(5,NULL,NULL,'fuel_calibration','Fuel Calibration','Fuel calibration task','fas fa-tools','text-secondary',50,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(6,NULL,NULL,'meter_reading','Meter Reading','Meter reading task','fas fa-tachometer-alt','text-secondary',60,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(7,NULL,NULL,'customer_transaction','Customer Transaction','Customer transaction reminder','fas fa-receipt','text-success',70,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(8,NULL,NULL,'payment_collection','Payment Collection','Payment collection reminder','fas fa-money-bill-wave','text-success',80,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(9,NULL,NULL,'maintenance','Maintenance','Maintenance task','fas fa-screwdriver-wrench','text-muted',90,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(10,NULL,NULL,'meeting','Meeting','Meeting schedule','fas fa-users','text-primary',100,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(11,NULL,NULL,'training','Training','Training schedule','fas fa-chalkboard-teacher','text-primary',110,1,'2026-08-21 06:12:36','2026-08-21 06:12:36'),(12,NULL,NULL,'other','Other','General calendar event','fas fa-calendar','text-primary',120,1,'2026-08-21 06:12:36','2026-08-21 06:12:36');
/*!40000 ALTER TABLE `staff_event_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `station_inventory`
--

DROP TABLE IF EXISTS `station_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `station_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `stock_level` decimal(12,2) DEFAULT 0.00,
  `cost` decimal(10,2) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `closing_stock` decimal(12,2) DEFAULT NULL,
  `closing_date` date DEFAULT NULL,
  `closing_shift` varchar(20) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT 0,
  `critical_level` int(11) NOT NULL DEFAULT 10,
  `capacity` decimal(12,2) DEFAULT 10000.00,
  `unit` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `physical_count` decimal(12,2) DEFAULT NULL,
  `variance` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_station_product` (`station_id`,`product_id`),
  KEY `fk_station_product_station` (`station_id`),
  KEY `fk_station_product_product` (`product_id`),
  CONSTRAINT `fk_st_inv_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_station_inventory_product_id` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_station_inventory_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=292 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_inventory`
--

LOCK TABLES `station_inventory` WRITE;
/*!40000 ALTER TABLE `station_inventory` DISABLE KEYS */;
INSERT INTO `station_inventory` VALUES (1,1253,6,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(2,1253,7,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(3,1253,8,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(4,1253,9,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(5,1253,10,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(6,1253,11,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(7,1253,12,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(8,1253,13,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(9,1253,14,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(10,1253,15,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(11,1253,16,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(12,1253,17,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(13,1253,18,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(14,1253,22,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(15,1253,23,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(16,1253,24,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(17,1253,25,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(18,1253,26,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(19,1253,27,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(20,1253,28,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(21,1253,29,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(22,1253,30,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(23,1253,31,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(24,1253,32,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(25,1253,33,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(26,1253,34,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(27,1253,35,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(28,1253,36,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(29,1253,37,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(30,1253,38,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(31,1253,39,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(32,1253,40,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(33,1253,41,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(34,1253,57,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(35,1253,76,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(36,1253,78,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(37,1253,79,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(38,1253,80,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(39,1253,81,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(40,1253,82,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(41,1253,83,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(42,1253,84,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(43,1253,85,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(44,1253,86,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(45,1253,91,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(46,1253,105,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(47,1253,108,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(48,1253,113,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(49,1253,114,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(50,1253,121,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(51,1253,124,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(52,1253,127,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(53,1253,132,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(54,1253,133,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(55,1253,697,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(56,1253,698,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(57,1253,699,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(58,1253,700,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(59,1253,743,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(60,1253,744,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(61,1253,745,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(62,1253,746,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(63,1253,747,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(64,1253,748,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(65,1253,749,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,'P/18','','2026-08-21 12:36:17',0.00,0.00),(66,1253,750,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(67,1253,751,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(68,1253,752,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(69,1253,753,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(70,1253,754,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(71,1253,755,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(72,1253,756,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(73,1253,757,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(74,1253,758,0.00,NULL,NULL,0.00,NULL,NULL,24,10,10000.00,NULL,'','2026-08-21 12:36:17',0.00,0.00),(75,1253,759,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(76,1253,760,0.00,37.00,50.00,0.00,NULL,NULL,24,10,10000.00,'60/200ml','active','2026-08-21 12:36:17',0.00,0.00),(77,1253,761,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(78,1253,762,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(79,1253,763,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(80,1253,764,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(81,1253,765,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(82,1253,766,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(83,1253,767,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(84,1253,768,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(85,1253,769,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(86,1253,770,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(87,1253,771,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(88,1253,772,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(89,1253,773,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(90,1253,774,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(91,1253,775,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(92,1253,776,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(93,1253,777,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(94,1253,778,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(95,1253,779,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(96,1253,780,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(97,1253,781,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(98,1253,782,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(99,1253,783,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(100,1253,784,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(101,1253,785,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(102,1253,786,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(103,1253,787,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(104,1253,788,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(105,1253,789,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(106,1253,790,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(107,1253,791,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(108,1253,792,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(109,1253,793,0.00,150.00,200.00,0.00,NULL,NULL,0,10,10000.00,'pcs','active','2026-08-21 12:36:17',0.00,0.00),(110,1253,794,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(111,1253,795,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(112,1253,796,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(113,1253,797,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(114,1253,798,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(115,1253,799,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(116,1253,800,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(117,1253,801,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(118,1253,802,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(119,1253,803,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(120,1253,804,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(121,1253,805,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(122,1253,806,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(123,1253,807,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(124,1253,808,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(125,1253,809,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(126,1253,810,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(127,1253,811,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(128,1253,812,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(129,1253,813,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(130,1253,814,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(131,1253,815,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(132,1253,816,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(133,1253,817,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(134,1253,818,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(135,1253,819,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(136,1253,820,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(137,1253,821,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(138,1253,822,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(139,1253,823,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(140,1253,824,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(141,1253,825,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(142,1253,826,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(143,1253,827,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(144,1253,828,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(145,1253,829,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(146,1253,830,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(147,1253,831,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(148,1253,832,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(149,1253,833,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(150,1253,834,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(151,1253,835,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(152,1253,836,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(153,1253,837,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(154,1253,838,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(155,1253,839,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(156,1253,840,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(157,1253,841,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(158,1253,842,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(159,1253,843,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(160,1253,844,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(161,1253,845,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(162,1253,846,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(163,1253,847,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(164,1253,848,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(165,1253,849,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(166,1253,850,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(167,1253,851,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(168,1253,852,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(169,1253,853,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(170,1253,854,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(171,1253,855,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(172,1253,856,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(173,1253,857,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(174,1253,858,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(175,1253,859,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(176,1253,860,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(177,1253,861,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(178,1253,862,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(179,1253,863,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(180,1253,864,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(181,1253,865,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(182,1253,866,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(183,1253,867,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(184,1253,868,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(185,1253,869,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(186,1253,870,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(187,1253,871,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(188,1253,872,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(189,1253,873,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(190,1253,874,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(191,1253,875,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(192,1253,876,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(193,1253,877,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(194,1253,878,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(195,1253,879,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(196,1253,880,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(197,1253,881,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(198,1253,882,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(199,1253,883,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(200,1253,884,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(201,1253,885,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(202,1253,886,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(203,1253,887,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(204,1253,888,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(205,1253,889,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(206,1253,890,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(207,1253,891,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(208,1253,892,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(209,1253,893,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(210,1253,894,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(211,1253,895,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(212,1253,896,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(213,1253,897,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(214,1253,898,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(215,1253,899,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(216,1253,900,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(217,1253,901,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(218,1253,902,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(219,1253,903,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(220,1253,904,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(221,1253,905,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(222,1253,906,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(223,1253,907,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(224,1253,908,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(225,1253,909,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(226,1253,910,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(227,1253,911,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(228,1253,912,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(229,1253,913,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(230,1253,914,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(231,1253,915,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(232,1253,916,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(233,1253,917,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(234,1253,918,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(235,1253,919,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(236,1253,920,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(237,1253,921,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(238,1253,922,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(239,1253,923,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(240,1253,924,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(241,1253,925,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(242,1253,926,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(243,1253,927,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(244,1253,928,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(245,1253,929,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(246,1253,930,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(247,1253,931,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(248,1253,932,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(249,1253,933,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(250,1253,934,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(251,1253,935,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(252,1253,936,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(253,1253,937,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(254,1253,938,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(255,1253,939,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(256,1253,940,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(257,1253,941,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(258,1253,942,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(259,1253,943,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(260,1253,944,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(261,1253,945,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(262,1253,946,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(263,1253,947,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(264,1253,948,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(265,1253,949,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00),(266,1253,950,0.00,NULL,NULL,0.00,NULL,NULL,5,10,10000.00,'pcs','active','2026-08-21 12:36:17',0.00,0.00),(272,1253,956,0.00,NULL,NULL,0.00,NULL,NULL,15,10,10000.00,'pcs','active','2026-08-21 12:36:17',0.00,0.00),(273,1253,957,0.00,NULL,NULL,0.00,NULL,NULL,15,10,10000.00,'pcs','active','2026-08-21 12:36:17',0.00,0.00),(281,1253,958,0.00,NULL,NULL,0.00,NULL,NULL,0,10,10000.00,NULL,'active','2026-08-21 12:36:17',0.00,0.00);
/*!40000 ALTER TABLE `station_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stations`
--

DROP TABLE IF EXISTS `stations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `outlet_type` varchar(100) DEFAULT 'SERVICE STATION',
  `region_id` int(10) unsigned DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `vat_tin` varchar(50) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `map_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unique_station` (`name`(190),`outlet_type`,`region`),
  KEY `fk_stations_region` (`region_id`),
  CONSTRAINT `fk_stations_region` FOREIGN KEY (`region_id`) REFERENCES `ph_regions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1414 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stations`
--

LOCK TABLES `stations` WRITE;
/*!40000 ALTER TABLE `stations` DISABLE KEYS */;
INSERT INTO `stations` VALUES (1,'Petron CDO - Kauswagan','Misamis Oriental','Vamenta Blvd., Carmen, City of Cagayan de Oro, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(2,'Daang Maharlika Hi-Way, Pinagbarila, Santo Cristo, Baliuag, Bulacan','Bulacan','Daang Maharlika Hi-Way, Pinagbarila, Santo Cristo, Baliuag, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(3,'Balagtas Bma Rd., Poblacion, San Rafael, Bulacan','Bulacan','Balagtas Bma Rd., Poblacion, San Rafael, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(4,'Cagayan Valley Highway, Bagong Silang, San Miguel, Bulacan','Bulacan','Cagayan Valley Highway, Bagong Silang, San Miguel, Bulacan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(5,'Plaridel - Pulilan Diversion Rd., Santo Cristo, Pulilan, Bulacan','Bulacan','Plaridel - Pulilan Diversion Rd., Santo Cristo, Pulilan, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(6,'Rizal St., San Jose, Baliuag, Bulacan','Bulacan','Rizal St., San Jose, Baliuag, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(7,'DRT Highway, Ulingao, San Rafael, Bulacan','Bulacan','DRT Highway, Ulingao, San Rafael, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(8,'Gen. Alejo G. Santos Highway, Parulan, Plaridel, Bulacan','Bulacan','Gen. Alejo G. Santos Highway, Parulan, Plaridel, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(9,'M. Valte Rd., Pinaod, San Ildefonso, Bulacan','Bulacan','M. Valte Rd., Pinaod, San Ildefonso, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(10,'Maharlika Highway Galas Maasim, Maasim, San Rafael, Bulacan','Bulacan','Maharlika Highway Galas Maasim, Maasim, San Rafael, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(11,'National Highway, Cruz Na Daan, San Rafael, Bulacan','Bulacan','National Highway, Cruz Na Daan, San Rafael, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(12,'Lazaro St., Batia, Bocaue, Bulacan','Bulacan','Lazaro St., Batia, Bocaue, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(13,'Plaridel Bypass Rd., Malamig, Bustos, Bulacan','Bulacan','Plaridel Bypass Rd., Malamig, Bustos, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(14,'General Alejo Santos Road , Donacion, Angat, Bulacan','Bulacan','General Alejo Santos Road , Donacion, Angat, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(15,'Villararit St. Poblacion, Poblacion, Norzagaray, Bulacan','Bulacan','Villararit St. Poblacion, Poblacion, Norzagaray, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(16,'Bypass Rd., Santa Clara, Santa Maria, Bulacan','Bulacan','Bypass Rd., Santa Clara, Santa Maria, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(17,'New Bypass Road, Bagbaguin, Santa Maria, Bulacan','Bulacan','New Bypass Road, Bagbaguin, Santa Maria, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(18,'Purok 1, Tanawan, Bustos, Bulacan','Bulacan','Purok 1, Tanawan, Bustos, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(19,'Landicho St. , Balasing, Santa Maria, Bulacan','Bulacan','Landicho St. , Balasing, Santa Maria, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(20,'Lucero Street, Bagong Bayan, City Of Malolos , Bulacan','Bulacan','Lucero Street, Bagong Bayan, City Of Malolos , Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(21,'Malhakan Road, Malhacan, City Of Meycauayan, Bulacan','Bulacan','Malhakan Road, Malhacan, City Of Meycauayan, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(22,'Main Street, San Juan, City Of Malolos , Bulacan','Bulacan','Main Street, San Juan, City Of Malolos , Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(23,'Mc Arthur Hiway, Wawa (Pob.), Balagtas, Bulacan','Bulacan','Mc Arthur Hiway, Wawa (Pob.), Balagtas, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(24,'Purok 1 Mabini Street, Santisima Trinidad, City Of Malolos , Bulacan','Bulacan','Purok 1 Mabini Street, Santisima Trinidad, City Of Malolos , Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(25,'Bo. Turo, Bi??Ang 2Nd, Bocaue, Bulacan','Bulacan','Bo. Turo, Bi??Ang 2Nd, Bocaue, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(26,'Bunsuran Ii, Cutcut, Guiguinto, Bulacan','Bulacan','Bunsuran Ii, Cutcut, Guiguinto, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(27,'Mc Arthur Hi Way, Calumpang, Calumpit, Bulacan','Bulacan','Mc Arthur Hi Way, Calumpang, Calumpit, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(28,'McArthur Highway, Poblacion, Guiguinto, Bulacan','Bulacan','McArthur Highway, Poblacion, Guiguinto, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(29,'Pan Philippine Highway, Banga I, Plaridel, Bulacan','Bulacan','Pan Philippine Highway, Banga I, Plaridel, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(30,'J. Garcia, Poblacion, Plaridel, Bulacan','Bulacan','J. Garcia, Poblacion, Plaridel, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(31,'McArthur Highway, Dakila, City Of Malolos , Bulacan','Bulacan','McArthur Highway, Dakila, City Of Malolos , Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(32,'Mc Arthur Hiway, Banga, City Of Meycauayan, Bulacan','Bulacan','Mc Arthur Hiway, Banga, City Of Meycauayan, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(33,'McArthur Highway, Guinhawa, City Of Malolos , Bulacan','Bulacan','McArthur Highway, Guinhawa, City Of Malolos , Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(34,'Diversion Road, Bulihan, City Of Malolos , Bulacan','Bulacan','Diversion Road, Bulihan, City Of Malolos , Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(35,'McArthur Highway , Bunlo, Bocaue, Bulacan','Bulacan','McArthur Highway , Bunlo, Bocaue, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(36,'National Road Corner Metro Gate 2, Bahay Pare, City Of Meycauayan, Bulacan','Bulacan','National Road Corner Metro Gate 2, Bahay Pare, City Of Meycauayan, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(37,'Igay Road, Santo Cristo, City Of San Jose Del Monte, Bulacan','Bulacan','Igay Road, Santo Cristo, City Of San Jose Del Monte, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(38,'Acasia St., Tungkong Mangga, City Of San Jose Del Monte, Bulacan','Bulacan','Acasia St., Tungkong Mangga, City Of San Jose Del Monte, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(39,'Quirino Highway, Tungkong Mangga, City Of San Jose Del Monte, Bulacan','Bulacan','Quirino Highway, Tungkong Mangga, City Of San Jose Del Monte, Bulacan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(40,'Quirino Highway, Francisco Homes-Mulawin, City Of San Jose Del Monte, Bulacan','Bulacan','Quirino Highway, Francisco Homes-Mulawin, City Of San Jose Del Monte, Bulacan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(41,'Grotto, Graceville, City Of San Jose Del Monte, Bulacan','Bulacan','Grotto, Graceville, City Of San Jose Del Monte, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(42,'North Luzon South Bound, Taal, Bocaue, Bulacan NCR Bulacan','Bulacan NCR Bulacan','North Luzon South Bound, Taal, Bocaue, Bulacan NCR Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(43,'KM 23 NLEX, Lias, Marilao, Bulacan','Bulacan','KM 23 NLEX, Lias, Marilao, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(44,'KM 42 NLEX Northbound Lane, Santo Ni??O, Plaridel, Bulacan','Bulacan','KM 42 NLEX Northbound Lane, Santo Ni??O, Plaridel, Bulacan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(45,'Deparo Road, Barangay 168, City Of Caloocan, NCR, (Third District)','(Third District)','Deparo Road, Barangay 168, City Of Caloocan, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(46,'Gen. San Miguel St., Barangay 4, City Of Caloocan, NCR, (Third District)','(Third District)','Gen. San Miguel St., Barangay 4, City Of Caloocan, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(47,'C-3 Cor.road Tawilis , Barangay 22, City Of Caloocan, NCR, (Third District)','(Third District)','C-3 Cor.road Tawilis , Barangay 22, City Of Caloocan, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(48,'A. Mabini St., Barangay 5, City Of Caloocan, NCR, (Third District)','(Third District)','A. Mabini St., Barangay 5, City Of Caloocan, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(49,'Tullahan Road Quirino Corner Sta Quiteria, Barangay 162, City Of Caloocan, NCR, (Third District)','(Third District)','Tullahan Road Quirino Corner Sta Quiteria, Barangay 162, City Of Caloocan, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(50,'Real St., Zapote, City Of Las Pi??As, NCR','NCR','Real St., Zapote, City Of Las Pi??As, NCR','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(51,'Crm Avenue, Almanza Dos, City Of Las Pi??As, NCR, Fourth District','Fourth District','Crm Avenue, Almanza Dos, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(52,'Real St., Pamplona Tres, City Of Las Pi??As, NCR','NCR','Real St., Pamplona Tres, City Of Las Pi??As, NCR','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(53,'Marcos Alvarez Ave., Talon Singko, City Of Las Pi??As, NCR','NCR','Marcos Alvarez Ave., Talon Singko, City Of Las Pi??As, NCR','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(54,'Alabang Zapote Road, Talon Tres, City Of Las Pi??As, NCR, Fourth District','Fourth District','Alabang Zapote Road, Talon Tres, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(55,'Lot 2A Daang Hari Corner Daang Reyna, Almanza Dos, City Of Las Pi??As, NCR, Fourth District','Fourth District','Lot 2A Daang Hari Corner Daang Reyna, Almanza Dos, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(56,'J. Aguilar Avenue, Talon Tres, City Of Las Pi??As, NCR, Fourth District','Fourth District','J. Aguilar Avenue, Talon Tres, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(57,'Zapote-Alabang Rd. First Metrogas, Pamplona Uno, City Of Las Pi??As, NCR, Fourth District','Fourth District','Zapote-Alabang Rd. First Metrogas, Pamplona Uno, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(58,'C5 Ext. Cor. S. Marquez St., Manuyo Uno, City Of Las Pi??As, NCR, Fourth District','Fourth District','C5 Ext. Cor. S. Marquez St., Manuyo Uno, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(59,'C5 Ext. Cor. Villaseal St., Manuyo Uno, City Of Las Pi??As, NCR, Fourth District','Fourth District','C5 Ext. Cor. Villaseal St., Manuyo Uno, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(60,'Alabang Zapote Rd., Pamplona Uno, City Of Las Pi??As, NCR, Fourth District','Fourth District','Alabang Zapote Rd., Pamplona Uno, City Of Las Pi??As, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(61,'Zobel Roxas Ave. Cor. Dian St., Palanan, City Of Makati, NCR','NCR','Zobel Roxas Ave. Cor. Dian St., Palanan, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(62,'Kamagong Cor. V. Cruz Ext., San Antonio, City Of Makati, NCR','NCR','Kamagong Cor. V. Cruz Ext., San Antonio, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(63,'Pablo Ocampo St. Cor. Zapote Rd., Santa Cruz, City Of Makati, NCR, Fourth District','Fourth District','Pablo Ocampo St. Cor. Zapote Rd., Santa Cruz, City Of Makati, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(64,'G Puyat Cor. P Tamo Ave., San Antonio, City Of Makati, NCR, Fourth District','Fourth District','G Puyat Cor. P Tamo Ave., San Antonio, City Of Makati, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(65,'Sen. Gil Puyat Ave. Cor. Makati, Bel-Air, City Of Makati, NCR','NCR','Sen. Gil Puyat Ave. Cor. Makati, Bel-Air, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(66,'EDSA Corner Arnaiz Ave., Dasmari??As, City Of Makati, NCR','NCR','EDSA Corner Arnaiz Ave., Dasmari??As, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(67,'Osmena Highway Cor. Calhoun St., Pio Del Pilar, City Of Makati, NCR','NCR','Osmena Highway Cor. Calhoun St., Pio Del Pilar, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(68,'EDSA Cor. Danlig St. Cor. Iran St., Pinagkaisahan, City Of Makati, NCR, Fourth District','Fourth District','EDSA Cor. Danlig St. Cor. Iran St., Pinagkaisahan, City Of Makati, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(69,'Gil Puyat Ave. Near Cor. Dian, San Isidro, City Of Makati, NCR','NCR','Gil Puyat Ave. Near Cor. Dian, San Isidro, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(70,'Boni Ave. Cor. Maysilo St., Plainview, City Of Mandaluyong, NCR, (Second District)','(Second District)','Boni Ave. Cor. Maysilo St., Plainview, City Of Mandaluyong, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(71,'Lot 18 Block 76 Sen. Gil Puyat Ave., Palanan, City Of Makati, NCR','NCR','Lot 18 Block 76 Sen. Gil Puyat Ave., Palanan, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(72,'Evangelista Cor. Gen. Arguelles, Pio Del Pilar, City Of Makati, NCR','NCR','Evangelista Cor. Gen. Arguelles, Pio Del Pilar, City Of Makati, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(73,'Kamagong And Metropolitan Ave. Cor. Epifanio De Los Santos Ave., San Antonio, City Of Makati','City Of Makati','Kamagong And Metropolitan Ave. Cor. Epifanio De Los Santos Ave., San Antonio, City Of Makati','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(74,'Gov. Pascual Ave., Potrero, City Of Malabon, NCR, (Third District)','(Third District)','Gov. Pascual Ave., Potrero, City Of Malabon, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(75,'McArthur Highway Cor. Anonas Road, Potrero, City Of Malabon, NCR, (Third District)','(Third District)','McArthur Highway Cor. Anonas Road, Potrero, City Of Malabon, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(76,'Gen. Luna Cor. Sacristia St., San Agustin, City Of Malabon, NCR, (Third District)','(Third District)','Gen. Luna Cor. Sacristia St., San Agustin, City Of Malabon, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(77,'M.h. Del Pilar, Maysilo, City Of Malabon, NCR, (Third District)','(Third District)','M.h. Del Pilar, Maysilo, City Of Malabon, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(78,'C4 Road Dagat-Dagatan, Longos, City Of Malabon, NCR, (Third District)','(Third District)','C4 Road Dagat-Dagatan, Longos, City Of Malabon, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(79,'Gov. Pascual Ave., Catmon, City Of Malabon, NCR, (Third District)','(Third District)','Gov. Pascual Ave., Catmon, City Of Malabon, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(80,'EDSA Ave. Corner Connecticut St., Wack-Wack Greenhills, City Of Mandaluyong','City Of Mandaluyong','EDSA Ave. Corner Connecticut St., Wack-Wack Greenhills, City Of Mandaluyong','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(81,'Smc Compound San Miguel Avenue, Wack-Wack Greenhills, City Of Mandaluyong','City Of Mandaluyong','Smc Compound San Miguel Avenue, Wack-Wack Greenhills, City Of Mandaluyong','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(82,'Barangka Drive Cor. Ma Clara St., Barangka Drive, City Of Mandaluyong, NCR, (Second District)','(Second District)','Barangka Drive Cor. Ma Clara St., Barangka Drive, City Of Mandaluyong, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(83,'Shaw Blvd. Corner Old Wack-Wack, Wack-Wack Greenhills, City Of Mandaluyong, NCR','NCR','Shaw Blvd. Corner Old Wack-Wack, Wack-Wack Greenhills, City Of Mandaluyong, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(84,'Sierra Madre St. EDSA Ave., Highway Hills, City Of Mandaluyong, NCR, (Second District)','(Second District)','Sierra Madre St. EDSA Ave., Highway Hills, City Of Mandaluyong, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(85,'St. Francis Ave. Wack Wack Green, Wack-Wack Greenhills, City Of Mandaluyong','City Of Mandaluyong','St. Francis Ave. Wack Wack Green, Wack-Wack Greenhills, City Of Mandaluyong','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(86,'R Magsaysay Blvd. Cor. Altura St., Barangay 581, Sampaloc, NCR, City Of Manila','City Of Manila','R Magsaysay Blvd. Cor. Altura St., Barangay 581, Sampaloc, NCR, City Of Manila','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(87,'Old Sta. Mesast., Barangay 591, Sampaloc, NCR, City Of Manila, (Frist District)','(Frist District)','Old Sta. Mesast., Barangay 591, Sampaloc, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(88,'Rizal Avenue Ext, Barangay 196, Tondo I/Ii, NCR, City Of Manila, (Frist District)','(Frist District)','Rizal Avenue Ext, Barangay 196, Tondo I/Ii, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(89,'C.m. Recto Avenue Corner Delpan, Barangay 275, San Nicolas','San Nicolas','C.m. Recto Avenue Corner Delpan, Barangay 275, San Nicolas','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:01'),(90,'Radial Road 10, Barangay 129, Tondo I/Ii, NCR, City Of Manila','City Of Manila','Radial Road 10, Barangay 129, Tondo I/Ii, NCR, City Of Manila','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(91,'Honorio Lopez St. Cor. Juan Luna St., Barangay 148, Tondo I/Ii','Tondo I/Ii','Honorio Lopez St. Cor. Juan Luna St., Barangay 148, Tondo I/Ii','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(92,'Espa??A Cor. Ibarra And Sisa Sts., Barangay 526, Sampaloc, NCR, City Of Manila, (Frist District)','(Frist District)','Espa??A Cor. Ibarra And Sisa Sts., Barangay 526, Sampaloc, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(93,'Rizal Ave. Cor. Malabon, Barangay 336, Santa Cruz, NCR, City Of Manila, (Frist District)','(Frist District)','Rizal Ave. Cor. Malabon, Barangay 336, Santa Cruz, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(94,'M. Dela Fuente Cor. G. Tuazon, Barangay 417, Sampaloc, NCR, City Of Manila, (Frist District)','(Frist District)','M. Dela Fuente Cor. G. Tuazon, Barangay 417, Sampaloc, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(95,'J. Luna St. Cor. Sande, Barangay 61, Tondo I/Ii, NCR, City Of Manila, (Frist District)','(Frist District)','J. Luna St. Cor. Sande, Barangay 61, Tondo I/Ii, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(96,'Paz Guazon Corner Calle Sancianco St., Barangay 829, Paco, NCR, City Of Manila, (Frist District)','(Frist District)','Paz Guazon Corner Calle Sancianco St., Barangay 829, Paco, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(97,'H. Lopez Cor. Kaunlaran, Barangay 124, Tondo I/Ii, NCR, City Of Manila, (Frist District)','(Frist District)','H. Lopez Cor. Kaunlaran, Barangay 124, Tondo I/Ii, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(98,'Dimasalang Cor. Tiago, Barangay 368, Santa Cruz, NCR, City Of Manila, (Frist District)','(Frist District)','Dimasalang Cor. Tiago, Barangay 368, Santa Cruz, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(99,'Pres. Quirino Ave., Barangay 832, Paco, NCR, City Of Manila, (Frist District)','(Frist District)','Pres. Quirino Ave., Barangay 832, Paco, NCR, City Of Manila, (Frist District)','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(100,'Pres. Sergio Osmena Highway, Barangay 747, Santa Ana, NCR, City Of Manila, (Frist District)','(Frist District)','Pres. Sergio Osmena Highway, Barangay 747, Santa Ana, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(101,'United Nations Ave. Cor. Romualdez St., Barangay 674, Paco, NCR, City Of Manila, (Frist District)','(Frist District)','United Nations Ave. Cor. Romualdez St., Barangay 674, Paco, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(102,'Jose Abad Santos Ave., Barangay 206, Tondo I/Ii, NCR, City Of Manila, (Frist District)','(Frist District)','Jose Abad Santos Ave., Barangay 206, Tondo I/Ii, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(103,'Claro M. Recto Cor. Asuncion Sts., Barangay 270, San Nicolas','San Nicolas','Claro M. Recto Cor. Asuncion Sts., Barangay 270, San Nicolas','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(104,'Rizal Ave. Corner Pampanga St., Barangay 382, Santa Cruz, NCR, City Of Manila, (Frist District)','(Frist District)','Rizal Ave. Corner Pampanga St., Barangay 382, Santa Cruz, NCR, City Of Manila, (Frist District)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(105,'V. Mapa Cor. 1St St., Barangay 601, Sampaloc, NCR, City Of Manila, (Frist District)','(Frist District)','V. Mapa Cor. 1St St., Barangay 601, Sampaloc, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(106,'Tanduay Corner Arlegui Sts., Barangay 386, Quiapo, NCR, City Of Manila, (Frist District)','(Frist District)','Tanduay Corner Arlegui Sts., Barangay 386, Quiapo, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(107,'Jesus Cor. Palum Pong, Barangay 834, Pandacan, NCR, City Of Manila, (Frist District)','(Frist District)','Jesus Cor. Palum Pong, Barangay 834, Pandacan, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(108,'A.h. Lacson Avenue, Barangay 343, Santa Cruz, NCR, City Of Manila, (Frist District)','(Frist District)','A.h. Lacson Avenue, Barangay 343, Santa Cruz, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(109,'Pedro Gil Cor. Taft Avenue, Barangay 696, Malate, NCR, City Of Manila, (Frist District)','(Frist District)','Pedro Gil Cor. Taft Avenue, Barangay 696, Malate, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(110,'Bonifacio Drive Cor. Aduan St., Barangay 666, Ermita, NCR, City Of Manila, (Frist District)','(Frist District)','Bonifacio Drive Cor. Aduan St., Barangay 666, Ermita, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(111,'San Marcelino St., Barangay 676, Paco, NCR, City Of Manila, (Frist District)','(Frist District)','San Marcelino St., Barangay 676, Paco, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(112,'Madrid Corner San Fernando Sts., Barangay 284, San Nicolas','San Nicolas','Madrid Corner San Fernando Sts., Barangay 284, San Nicolas','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:01'),(113,'Pedro Gil Corner Tejeron Sts., Barangay 877, Santa Ana, NCR, City Of Manila, (Frist District)','(Frist District)','Pedro Gil Corner Tejeron Sts., Barangay 877, Santa Ana, NCR, City Of Manila, (Frist District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(114,'Tejeron Cor. Syquia Sts., Barangay 874, Santa Ana, NCR, City Of Manila','City Of Manila','Tejeron Cor. Syquia Sts., Barangay 874, Santa Ana, NCR, City Of Manila','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(115,'F.b. Harrison Corner Pablo Ocampo St., Barangay 719, Malate, NCR, City Of Manila','City Of Manila','F.b. Harrison Corner Pablo Ocampo St., Barangay 719, Malate, NCR, City Of Manila','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(116,'Gil Fernando Ave., San Roque, City Of Marikina, NCR, (Second District)','(Second District)','Gil Fernando Ave., San Roque, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(117,'G. Fernando Ave., Santo Ni??O, City Of Marikina, NCR, (Second District)','(Second District)','G. Fernando Ave., Santo Ni??O, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(118,'Gil Fernando St. Cor. Sumulong Hiway, Santo Ni??O, City Of Marikina, NCR, (Second District)','(Second District)','Gil Fernando St. Cor. Sumulong Hiway, Santo Ni??O, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(119,'Gen. Molina St., Parang, City Of Marikina, NCR, (Second District)','(Second District)','Gen. Molina St., Parang, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(120,'Bayan Bayanan Avenue, Concepcion Uno, City Of Marikina, NCR, (Second District)','(Second District)','Bayan Bayanan Avenue, Concepcion Uno, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(121,'J.P. Rizal St. Cor. Bayan-Bayanan, Concepcion Uno, City Of Marikina, NCR, (Second District)','(Second District)','J.P. Rizal St. Cor. Bayan-Bayanan, Concepcion Uno, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(122,'J.P. Rizal Ave. Cor. Spain St., Concepcion Uno, City Of Marikina, NCR, (Second District)','(Second District)','J.P. Rizal Ave. Cor. Spain St., Concepcion Uno, City Of Marikina, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(123,'East Service Road, Alabang, City Of Muntinlupa, NCR','NCR','East Service Road, Alabang, City Of Muntinlupa, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(124,'National Road, Tunasan, City Of Muntinlupa, NCR','NCR','National Road, Tunasan, City Of Muntinlupa, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(125,'Purok 5 M.l. Quezon, Sucat, City Of Muntinlupa, NCR, Fourth District','Fourth District','Purok 5 M.l. Quezon, Sucat, City Of Muntinlupa, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(126,'Pacific Rim Corner Commerce Avenue, Alabang, City Of Muntinlupa, NCR, Fourth District','Fourth District','Pacific Rim Corner Commerce Avenue, Alabang, City Of Muntinlupa, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(127,'KM 70 National Road, Tunasan, City Of Muntinlupa, NCR','NCR','KM 70 National Road, Tunasan, City Of Muntinlupa, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(128,'National Road, Alabang, City Of Muntinlupa, NCR','NCR','National Road, Alabang, City Of Muntinlupa, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(129,'Industrial Building I Lapu-Lapu Street, North Bay Boulevard North, City Of Navotas','City Of Navotas','Industrial Building I Lapu-Lapu Street, North Bay Boulevard North, City Of Navotas','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(130,'Ninoy Aquino Ave., San Dionisio, City Of Para??Aque, NCR, Fourth District','Fourth District','Ninoy Aquino Ave., San Dionisio, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(131,'KM 15 West Service Rd., Sun Valley, City Of Para??Aque, NCR, Fourth District','Fourth District','KM 15 West Service Rd., Sun Valley, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(132,'Quirino Ave., Tambo, City Of Para??Aque, NCR, Fourth District','Fourth District','Quirino Ave., Tambo, City Of Para??Aque, NCR, Fourth District','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(133,'C5 Extension, Brgy. Moonwalk, Para??Aque City, Metro Manila','Metro Manila','C5 Extension, Brgy. Moonwalk, Para??Aque City, Metro Manila','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(134,'Dr. A. Santos Ave., San Antonio, City Of Para??Aque, NCR, Fourth District','Fourth District','Dr. A. Santos Ave., San Antonio, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(135,'Smpi Cmpd Dr. A. Santos. Ave., B. F. Homes, City Of Para??Aque, NCR, Fourth District','Fourth District','Smpi Cmpd Dr. A. Santos. Ave., B. F. Homes, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(136,'Dr. A. Santos Ave., San Isidro, City Of Para??Aque, NCR, Fourth District','Fourth District','Dr. A. Santos Ave., San Isidro, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(137,'Dr. A. Santos Ave., San Dionisio, City Of Para??Aque, NCR, Fourth District','Fourth District','Dr. A. Santos Ave., San Dionisio, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(138,'Blk8 Do??A Soledad Better Living, Don Bosco, City Of Para??Aque, NCR, Fourth District','Fourth District','Blk8 Do??A Soledad Better Living, Don Bosco, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(139,'Quirino Ave. Corner Kabihasnan, San Dionisio, City Of Para??Aque, NCR, Fourth District','Fourth District','Quirino Ave. Corner Kabihasnan, San Dionisio, City Of Para??Aque, NCR, Fourth District','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(140,'Domestic Road Cor. Airport Road, Santo Ni??O, City Of Para??Aque, NCR','NCR','Domestic Road Cor. Airport Road, Santo Ni??O, City Of Para??Aque, NCR','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(141,'Lopes Ave., San Isidro, City Of Para??Aque, NCR, Fourth District','Fourth District','Lopes Ave., San Isidro, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(142,'D. Macapagal/North Perimeter Rd., Tambo, City Of Para??Aque, NCR, Fourth District','Fourth District','D. Macapagal/North Perimeter Rd., Tambo, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(143,'Iba Cor. Narra St. United Hills Village, San Martin De Porres, City Of Para??Aque','City Of Para??Aque','Iba Cor. Narra St. United Hills Village, San Martin De Porres, City Of Para??Aque','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(144,'KM 18 East Service Road, San Martin De Porres, City Of Para??Aque, NCR, Fourth District','Fourth District','KM 18 East Service Road, San Martin De Porres, City Of Para??Aque, NCR, Fourth District','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:16'),(145,'Km21, West Service Road Cor. Cielit, Cupang, Muntinlupa, NCR','NCR','Km21, West Service Road Cor. Cielit, Cupang, Muntinlupa, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(146,'F.b. Harrison Cor. Cuneta Sts., Barangay 75, Pasay City, NCR, Fourth District','Fourth District','F.b. Harrison Cor. Cuneta Sts., Barangay 75, Pasay City, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(147,'Southbound EDSA, Barangay 144, Pasay City, NCR, Fourth District','Fourth District','Southbound EDSA, Barangay 144, Pasay City, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(148,'Fb Harizon St., Barangay 13, Pasay City, NCR, Fourth District','Fourth District','Fb Harizon St., Barangay 13, Pasay City, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(149,'F.b. Harrison Corner San Juan, Barangay 21, Pasay City, NCR, Fourth District','Fourth District','F.b. Harrison Corner San Juan, Barangay 21, Pasay City, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(150,'Mia Road Naia Complex Domestic Road, Barangay 191, Pasay City, NCR','NCR','Mia Road Naia Complex Domestic Road, Barangay 191, Pasay City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(151,'Diosdado Macapagal Corner EDSA Ext, Barangay 76, Pasay City, NCR, Fourth District','Fourth District','Diosdado Macapagal Corner EDSA Ext, Barangay 76, Pasay City, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(152,'Metropolitan Park Roxas Blvd., Barangay 76, Pasay City, NCR, Fourth District','Fourth District','Metropolitan Park Roxas Blvd., Barangay 76, Pasay City, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(153,'Dona Aurora Village Marcos Highway, Santolan, City Of Pasig, NCR, (Second District)','(Second District)','Dona Aurora Village Marcos Highway, Santolan, City Of Pasig, NCR, (Second District)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(154,'Amang Rodriguez Ave., Manggahan, City Of Pasig, NCR, (Second District)','(Second District)','Amang Rodriguez Ave., Manggahan, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(155,'Marcos Highway, Dela Paz, City Of Pasig, NCR, (Second District)','(Second District)','Marcos Highway, Dela Paz, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(156,'Canley Road Cor. Kamagong St., Bagong Ilog, City Of Pasig, NCR','NCR','Canley Road Cor. Kamagong St., Bagong Ilog, City Of Pasig, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(157,'Meralco Ave. B, San Antonio, City Of Pasig, NCR, (Second District)','(Second District)','Meralco Ave. B, San Antonio, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(158,'Eusebio Avenue Eusebio St., Maybunga, City Of Pasig, NCR, (Second District)','(Second District)','Eusebio Avenue Eusebio St., Maybunga, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(159,'Ortigas Ave. Extension, Rosario, City Of Pasig, NCR, (Second District)','(Second District)','Ortigas Ave. Extension, Rosario, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(160,'C. Raymundo, Caniogan, City Of Pasig, NCR, (Second District)','(Second District)','C. Raymundo, Caniogan, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(161,'M. Concepcion St., San Joaquin, City Of Pasig, NCR, (Second District)','(Second District)','M. Concepcion St., San Joaquin, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(162,'Shaw Boulevard, Oranbo, City Of Pasig, NCR, (Second District)','(Second District)','Shaw Boulevard, Oranbo, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(163,'C. Raymundo Avenue, Maybunga, City Of Pasig, NCR, (Second District)','(Second District)','C. Raymundo Avenue, Maybunga, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(164,'Ortigas Avenue Ext., Santo Ni??O, Cainta, Rizal','Rizal','Ortigas Avenue Ext., Santo Ni??O, Cainta, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(165,'F.p. Felix Ave., Dela Paz, City Of Pasig, NCR, (Second District)','(Second District)','F.p. Felix Ave., Dela Paz, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(166,'Pasig Blvd Cor. San Ignacio St., Pineda, City Of Pasig, NCR, (Second District)','(Second District)','Pasig Blvd Cor. San Ignacio St., Pineda, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(167,'E. Rodriguez Ave., Ugong, City Of Pasig, NCR, (Second District)','(Second District)','E. Rodriguez Ave., Ugong, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(168,'C. Raymundo Ave. Greenland , Rosario, City Of Pasig, NCR, (Second District)','(Second District)','C. Raymundo Ave. Greenland , Rosario, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(169,'Mercedes Avenue Cor. Market Ave., Caniogan, City Of Pasig, NCR, (Second District)','(Second District)','Mercedes Avenue Cor. Market Ave., Caniogan, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(170,'Shaw Blvd. Cor. Capitol Drive, Kapitolyo, City Of Pasig, NCR, (Second District)','(Second District)','Shaw Blvd. Cor. Capitol Drive, Kapitolyo, City Of Pasig, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(171,'M. Almeda Cor. Bagong Kalsada, Martires Del 96, Pateros, NCR, Fourth District','Fourth District','M. Almeda Cor. Bagong Kalsada, Martires Del 96, Pateros, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(172,'Regalado Ave., Greater Lagro, Quezon City, NCR, (Second District)','(Second District)','Regalado Ave., Greater Lagro, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(173,'Quirino Highway, Kaligayahan, Quezon City, NCR, (Second District)','(Second District)','Quirino Highway, Kaligayahan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(174,'Regalado Ave., North Fairview, Quezon City, NCR, (Second District)','(Second District)','Regalado Ave., North Fairview, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(175,'North Fairview Commonwealth Ave., Greater Lagro, Quezon City, NCR, (Second District)','(Second District)','North Fairview Commonwealth Ave., Greater Lagro, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(176,'Gen. Luis Corner Ambrosia St., Nagkaisang Nayon, Quezon City, NCR','NCR','Gen. Luis Corner Ambrosia St., Nagkaisang Nayon, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(177,'KM. 21 Quirino Highway, Greater Lagro, Quezon City, NCR','NCR','KM. 21 Quirino Highway, Greater Lagro, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(178,'Old Zabarte Road, Kaligayahan, Quezon City, NCR, (Second District)','(Second District)','Old Zabarte Road, Kaligayahan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(179,'Quirino Highway, Bagbag, Quezon City, NCR, (Second District)','(Second District)','Quirino Highway, Bagbag, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(180,'Mindanao Ave. Ext., Talipapa, Quezon City, NCR, (Second District)','(Second District)','Mindanao Ave. Ext., Talipapa, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(181,'Mindanao Avenue, Project 6, Quezon City, NCR, (Second District)','(Second District)','Mindanao Avenue, Project 6, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(182,'Congressinal Avenue Corner April St., Bahay Toro, Quezon City, NCR, (Second District)','(Second District)','Congressinal Avenue Corner April St., Bahay Toro, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(183,'Quirino Hiway Cor. Tandang Sora, Sangandaan, Quezon City, NCR, (Second District)','(Second District)','Quirino Hiway Cor. Tandang Sora, Sangandaan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(184,'Batasan San Mateo Road, Batasan Hills, Quezon City, NCR, (Second District)','(Second District)','Batasan San Mateo Road, Batasan Hills, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(185,'Payatas Road, Brgy. Commonwealth, Quezon City','Quezon City','Payatas Road, Brgy. Commonwealth, Quezon City','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(186,'Commonwealth Ave., Fairview, Quezon City, NCR, (Second District)','(Second District)','Commonwealth Ave., Fairview, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(187,'Kamuning Road Corner Kalayaan, Malaya, Quezon City, NCR','NCR','Kamuning Road Corner Kalayaan, Malaya, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(188,'Central Avenue , New Era, Quezon City, NCR, (Second District)','(Second District)','Central Avenue , New Era, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(189,'Regalado Ave. Cor. Pontiac St., Fairview, Quezon City, NCR, (Second District)','(Second District)','Regalado Ave. Cor. Pontiac St., Fairview, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(190,'East Avenue Corner Nia Road, Pinyahan, Quezon City, NCR, (Second District)','(Second District)','East Avenue Corner Nia Road, Pinyahan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(191,'Tandang Sora Avenue, Tandang Sora, Quezon City, NCR, (Second District)','(Second District)','Tandang Sora Avenue, Tandang Sora, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(192,'East Avenue, Pinyahan, Quezon City, NCR','NCR','East Avenue, Pinyahan, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(193,'Congerssional Ave. Cor. Virginia St., Bahay Toro, Quezon City, NCR, (Second District)','(Second District)','Congerssional Ave. Cor. Virginia St., Bahay Toro, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(194,'Commonwealth Avenue Corner Arboretum, U.p. Campus, Quezon City, NCR, (Second District)','(Second District)','Commonwealth Avenue Corner Arboretum, U.p. Campus, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(195,'Dalhia Cor. Lilac St., Fairview, Quezon City, NCR, (Second District)','(Second District)','Dalhia Cor. Lilac St., Fairview, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(196,'Comonwealth Av. Cor. Atherthon St., North Fairview, Quezon City, NCR, (Second District)','(Second District)','Comonwealth Av. Cor. Atherthon St., North Fairview, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(197,'Payatas Road, Bagong Silangan, Quezon City, NCR, (Second District)','(Second District)','Payatas Road, Bagong Silangan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:45'),(198,'Kalayaan Ave., Central, Quezon City, NCR','NCR','Kalayaan Ave., Central, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(199,'Don Antonio Hts. Corner Commonwealt, Holy Spirit, Quezon City, NCR, (Second District)','(Second District)','Don Antonio Hts. Corner Commonwealt, Holy Spirit, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(200,'Regalado Avenue, Fairview, Quezon City, NCR, (Second District)','(Second District)','Regalado Avenue, Fairview, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(201,'Visayas Ave. Cor. Danr St. Project 6, Vasra, Quezon City, NCR, (Second District)','(Second District)','Visayas Ave. Cor. Danr St. Project 6, Vasra, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(202,'Commonwealth Ave. Cor. Zuzuarregi St., Matandang Balara, Quezon City, NCR, (Second District)','(Second District)','Commonwealth Ave. Cor. Zuzuarregi St., Matandang Balara, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(203,'Congressional Ave. Ext, Pasong Tamo, Quezon City, NCR, (Second District)','(Second District)','Congressional Ave. Ext, Pasong Tamo, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(204,'Visayas Avenue Corner Congressional Avenue, Bahay Toro, Quezon City, NCR, (Second District)','(Second District)','Visayas Avenue Corner Congressional Avenue, Bahay Toro, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(205,'Tandang Sora Ave., Culiat, Quezon City, NCR, (Second District)','(Second District)','Tandang Sora Ave., Culiat, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(206,'Katipunan Avenue Corner Mangyan St., Pansol, Quezon City, NCR, (Second District)','(Second District)','Katipunan Avenue Corner Mangyan St., Pansol, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(207,'V.luna Avenue Corner Masikap, Pinyahan, Quezon City, NCR','NCR','V.luna Avenue Corner Masikap, Pinyahan, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(208,'Sgt Esguerra Ave. Cor. Timog Ave., South Triangle, Quezon City, NCR','NCR','Sgt Esguerra Ave. Cor. Timog Ave., South Triangle, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(209,'West Avenue Corner Del Monte, Nayong Kanluran, Quezon City, NCR, (Second District)','(Second District)','West Avenue Corner Del Monte, Nayong Kanluran, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(210,'G. Araneta Ave., Tatalon, Quezon City, NCR','NCR','G. Araneta Ave., Tatalon, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(211,'Dapitan Cor. Dr. Alejos Sts., Santa Teresita, Quezon City, NCR','NCR','Dapitan Cor. Dr. Alejos Sts., Santa Teresita, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(212,'Quezon Ave. Corner Apo St., Santa Teresita, Quezon City, NCR','NCR','Quezon Ave. Corner Apo St., Santa Teresita, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(213,'Mayon Cor. Calamba St., San Isidro Labrador, Quezon City, NCR','NCR','Mayon Cor. Calamba St., San Isidro Labrador, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(214,'A.bonifacio Cor.delmonte Ave., San Jose, Quezon City, NCR, (Second District)','(Second District)','A.bonifacio Cor.delmonte Ave., San Jose, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(215,'Kamuning Rd. Cor. Sct. Ybardolaza, Sacred Heart, Quezon City, NCR, (Second District)','(Second District)','Kamuning Rd. Cor. Sct. Ybardolaza, Sacred Heart, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(216,'Nicanor Roxas St. Corner Halcon, Santa Teresita, Quezon City, NCR, (Second District)','(Second District)','Nicanor Roxas St. Corner Halcon, Santa Teresita, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(217,'Quezon Ave. Roxas District, Santa Cruz, Quezon City, NCR, (Second District)','(Second District)','Quezon Ave. Roxas District, Santa Cruz, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(218,'Aurora Blvd Corner Balete Drive, Mariana, Quezon City, NCR','NCR','Aurora Blvd Corner Balete Drive, Mariana, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(219,'Kanlaon Cor. Laon-Laang St., Lourdes, Quezon City, NCR, (Second District)','(Second District)','Kanlaon Cor. Laon-Laang St., Lourdes, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(220,'Quezon Ave. Cor. Sma Ave., Tatalon, Quezon City, NCR, (Second District)','(Second District)','Quezon Ave. Cor. Sma Ave., Tatalon, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(221,'Roosevelt Ave. Cor. Del Monte Ave., Damayan, Quezon City, NCR','NCR','Roosevelt Ave. Cor. Del Monte Ave., Damayan, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(222,'Aurora Blvd. Cor. Dona Juana Rodriguez, Mariana, Quezon City, NCR','NCR','Aurora Blvd. Cor. Dona Juana Rodriguez, Mariana, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(223,'G.araneta Ave., Santo Domingo, Quezon City, NCR','NCR','G.araneta Ave., Santo Domingo, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(224,'Timog Morato Cor. Tomas Morato, Laging Handa, Quezon City, NCR, (Second District)','(Second District)','Timog Morato Cor. Tomas Morato, Laging Handa, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(225,'E Rodriguez Sr. Ave. Cor. T, Kristong Hari, Quezon City, NCR, (Second District)','(Second District)','E Rodriguez Sr. Ave. Cor. T, Kristong Hari, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(226,'1 Unang Hakbang St. Cor. Bayani St., San Isidro, Quezon City, NCR','NCR','1 Unang Hakbang St. Cor. Bayani St., San Isidro, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(227,'A. Bonifacio, Pag-Ibig Sa Nayon, Quezon City, NCR','NCR','A. Bonifacio, Pag-Ibig Sa Nayon, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(228,'EDSA South Triangle (Southbound), South Triangle, Quezon City, NCR, (Second District)','(Second District)','EDSA South Triangle (Southbound), South Triangle, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(229,'E. Rodriguez Street, Tatalon, Quezon City, NCR, (Second District)','(Second District)','E. Rodriguez Street, Tatalon, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(230,'E. Rodriguez Sr. , Immaculate Concepcion, Quezon City, NCR','NCR','E. Rodriguez Sr. , Immaculate Concepcion, Quezon City, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(231,'EDSA Cor. Main Ave., Socorro, Quezon City, NCR, (Second District)','(Second District)','EDSA Cor. Main Ave., Socorro, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(232,'Boni Serrano Cor. 4Th Ave., Bagong Lipunan Ng Crame, Quezon City, NCR, (Second District)','(Second District)','Boni Serrano Cor. 4Th Ave., Bagong Lipunan Ng Crame, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(233,'Aurora Blvd. Corner Katipunan Avenue, Loyola Heights, Quezon City, NCR, (Second District)','(Second District)','Aurora Blvd. Corner Katipunan Avenue, Loyola Heights, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(234,'E. Rodriguez Jr., Bagumbayan, Quezon City, NCR, (Second District)','(Second District)','E. Rodriguez Jr., Bagumbayan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(235,'No. 188 E. Rodriquiez Jr. Avenue, Bagumbayan, Quezon City, NCR, (Second District)','(Second District)','No. 188 E. Rodriquiez Jr. Avenue, Bagumbayan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(236,'N. Domingo Cor. P. Tuazon, Kaunlaran, Quezon City, NCR, (Second District)','(Second District)','N. Domingo Cor. P. Tuazon, Kaunlaran, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(237,'EDSA Corner Main Street, Bagong Lipunan Ng Crame, Quezon City, NCR, (Second District)','(Second District)','EDSA Corner Main Street, Bagong Lipunan Ng Crame, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(238,'Katipunan Cor. Bonny Serrano Ave., Bayanihan, Quezon City, NCR, (Second District)','(Second District)','Katipunan Cor. Bonny Serrano Ave., Bayanihan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(239,'Xavierville Ave., Loyola Heights, Quezon City, NCR, (Second District)','(Second District)','Xavierville Ave., Loyola Heights, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(240,'Aurora Blvd. Cor. Lauan St., Duyan-Duyan, Quezon City, NCR, (Second District)','(Second District)','Aurora Blvd. Cor. Lauan St., Duyan-Duyan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(241,'Don Mariano Marcos Hi-Way , Bagong Silangan, Quezon City, NCR, (Second District)','(Second District)','Don Mariano Marcos Hi-Way , Bagong Silangan, Quezon City, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(242,'Gen. Luna St., Ampid Ii, San Mateo, Rizal','Rizal','Gen. Luna St., Ampid Ii, San Mateo, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(243,'Sumulong Memorial Circumferential Rd., San Isidro (Pob.), City Of Antipolo , Rizal','Rizal','Sumulong Memorial Circumferential Rd., San Isidro (Pob.), City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(244,'Malinta Street, San Roque (Pob.), City Of Antipolo , Rizal','Rizal','Malinta Street, San Roque (Pob.), City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(245,'National Rd., Wawa (Pob.), Pililla, Rizal','Rizal','National Rd., Wawa (Pob.), Pililla, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(246,'National Road, Sipsipin, Jala-Jala, Rizal','Rizal','National Road, Sipsipin, Jala-Jala, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(247,'P. Oliveros St., San Roque (Pob.), City Of Antipolo , Rizal','Rizal','P. Oliveros St., San Roque (Pob.), City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(248,'General Luna Cor. P Oliveros St., Dela Paz (Pob.), City Of Antipolo , Rizal','Rizal','General Luna Cor. P Oliveros St., Dela Paz (Pob.), City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(249,'National Road, San Jose (Pob.), Morong, Rizal','Rizal','National Road, San Jose (Pob.), Morong, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(250,'T. Claudio St., San Pedro (Pob.), Morong, Rizal','Rizal','T. Claudio St., San Pedro (Pob.), Morong, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(251,'Gp Marcos Highway, Santa Cruz, City Of Antipolo , Rizal','Rizal','Gp Marcos Highway, Santa Cruz, City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(252,'Circumferential Road, San Roque (Pob.), City Of Antipolo , Rizal','Rizal','Circumferential Road, San Roque (Pob.), City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(253,'National Highway, Mabini, Baras, Rizal','Rizal','National Highway, Mabini, Baras, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(254,'M.h. Del Pilar St. Cor. Rodriguez, Katipunan-Bayan (Pob.), Tanay, Rizal','Rizal','M.h. Del Pilar St. Cor. Rodriguez, Katipunan-Bayan (Pob.), Tanay, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(255,'Sumulong Highway, Mayamot, City Of Antipolo , Rizal','Rizal','Sumulong Highway, Mayamot, City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(256,'Kasiglahan Village, San Jose, Rodriguez, Rizal','Rizal','Kasiglahan Village, San Jose, Rodriguez, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(257,'J.p. Rizal St. Cor. Daanghari St., Manggahan, Rodriguez, Rizal','Rizal','J.p. Rizal St. Cor. Daanghari St., Manggahan, Rodriguez, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(258,'Manila East Road, Hulo (Pob.), Pililla, Rizal','Rizal','Manila East Road, Hulo (Pob.), Pililla, Rizal','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(259,'A Mabini St., Burgos, Rodriguez, Rizal','Rizal','A Mabini St., Burgos, Rodriguez, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(260,'Marcos Highway, Bagong Nayon, City Of Antipolo , Rizal','Rizal','Marcos Highway, Bagong Nayon, City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(261,'Sumulong Highway, Mambugan, City Of Antipolo , Rizal','Rizal','Sumulong Highway, Mambugan, City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(262,'Sumulong Highway, Santa Cruz, City Of Antipolo , Rizal','Rizal','Sumulong Highway, Santa Cruz, City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(263,'J Sumulong Highway, San Guillermo, Morong, Rizal','Rizal','J Sumulong Highway, San Guillermo, Morong, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(264,'Sumulong Highway , San Isidro, Cainta, Rizal','Rizal','Sumulong Highway , San Isidro, Cainta, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(265,'Sumulong Highway, Dela Paz (Pob.), City Of Antipolo , Rizal','Rizal','Sumulong Highway, Dela Paz (Pob.), City Of Antipolo , Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(266,'Pbwy Commercial Center Evangelistast., San Juan, Taytay, Rizal','Rizal','Pbwy Commercial Center Evangelistast., San Juan, Taytay, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(267,'National Rd., Tayuman, Binangonan, Rizal','Rizal','National Rd., Tayuman, Binangonan, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(268,'Rizal Avenue Corner Palumbarit St., San Isidro, Taytay, Rizal','Rizal','Rizal Avenue Corner Palumbarit St., San Isidro, Taytay, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(269,'Felix Avenue, San Isidro, Cainta, Rizal','Rizal','Felix Avenue, San Isidro, Cainta, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(270,'KM 21 Ortigas Ave. Ext , San Isidro, Taytay, Rizal','Rizal','KM 21 Ortigas Ave. Ext , San Isidro, Taytay, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(271,'Ortigas Ave. Ext, San Juan, Cainta, Rizal','Rizal','Ortigas Ave. Ext, San Juan, Cainta, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(272,'Quezon Ave., Pag-Asa, Tayuman, Angono, Rizal','Rizal','Quezon Ave., Pag-Asa, Tayuman, Angono, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(273,'Ri National Road, Libis (Pob.), Binangonan, Rizal','Rizal','Ri National Road, Libis (Pob.), Binangonan, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(274,'Manila East Road Corner Velasques St., Muzon, Taytay, Rizal','Rizal','Manila East Road Corner Velasques St., Muzon, Taytay, Rizal','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(275,'KM. 20 Ortigas Avenue Extension, Santo Domingo, Cainta, Rizal','Rizal','KM. 20 Ortigas Avenue Extension, Santo Domingo, Cainta, Rizal','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(276,'N Domingo Cor. M Paterno St., Corazon De Jesus, City Of San Juan, NCR, (Second District)','(Second District)','N Domingo Cor. M Paterno St., Corazon De Jesus, City Of San Juan, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(277,'Valenzuela Cor. F. Blumentritt, Batis, City Of San Juan, NCR, (Second District)','(Second District)','Valenzuela Cor. F. Blumentritt, Batis, City Of San Juan, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(278,'N. Domingo Corner San Gabriel, San Perfecto, City Of San Juan, NCR, (Second District)','(Second District)','N. Domingo Corner San Gabriel, San Perfecto, City Of San Juan, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(279,'F Blumentritt Cor. San Luis St., Tibagan, City Of San Juan, NCR, (Second District)','(Second District)','F Blumentritt Cor. San Luis St., Tibagan, City Of San Juan, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(280,'P. Guevarra Cor. V. Cruz, Santa Lucia, City Of San Juan, NCR','NCR','P. Guevarra Cor. V. Cruz, Santa Lucia, City Of San Juan, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(281,'Ortigas Ave. Cor. Santolan St., Greenhills, City Of San Juan, NCR, (Second District)','(Second District)','Ortigas Ave. Cor. Santolan St., Greenhills, City Of San Juan, NCR, (Second District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(282,'Ortigas Avenue Corner Connecticut, Greenhills, San Juan City','San Juan City','Ortigas Avenue Corner Connecticut, Greenhills, San Juan City','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(283,'Dr. Natividad St., Ibayo-Tipas, City Of Taguig, NCR, Fourth District','Fourth District','Dr. Natividad St., Ibayo-Tipas, City Of Taguig, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(284,'C5 Road Mckinley Hill Fort Bonifacio, Fort Bonifacio, City Of Taguig, NCR, Fourth District','Fourth District','C5 Road Mckinley Hill Fort Bonifacio, Fort Bonifacio, City Of Taguig, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(285,'Pasong Tamo Ext. Bonifacio Global C, Fort Bonifacio, City Of Taguig, NCR, Fourth District','Fourth District','Pasong Tamo Ext. Bonifacio Global C, Fort Bonifacio, City Of Taguig, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(286,'M.l. Quezon, Lower Bicutan, City Of Taguig, NCR, Fourth District','Fourth District','M.l. Quezon, Lower Bicutan, City Of Taguig, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(287,'Levi Mariano Ave., Ususan, City Of Taguig, NCR, Fourth District','Fourth District','Levi Mariano Ave., Ususan, City Of Taguig, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(288,'C5 Road, Ususan, City Of Taguig, NCR','NCR','C5 Road, Ususan, City Of Taguig, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(289,'Mayor Tanyag Ave., South Signal Village, City Of Taguig, NCR','NCR','Mayor Tanyag Ave., South Signal Village, City Of Taguig, NCR','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(290,'Hulo St., Bignay, City Of Valenzuela, NCR, (Third District)','(Third District)','Hulo St., Bignay, City Of Valenzuela, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(291,'Paseo De Blas Road, Paso De Blas, City Of Valenzuela, NCR, (Third District)','(Third District)','Paseo De Blas Road, Paso De Blas, City Of Valenzuela, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(292,'McArthur Hi-Way, Marulas, City Of Valenzuela, NCR, (Third District)','(Third District)','McArthur Hi-Way, Marulas, City Of Valenzuela, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(293,'Maysan Road, Maysan, City Of Valenzuela, NCR, (Third District)','(Third District)','Maysan Road, Maysan, City Of Valenzuela, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(294,'Gov. Santiago Mc.arthur Highway, Malinta, City Of Valenzuela, NCR, (Third District)','(Third District)','Gov. Santiago Mc.arthur Highway, Malinta, City Of Valenzuela, NCR, (Third District)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(295,'J. P. Rizal Cor. Reposo Sts., Valenzuela, City Of Makati, NCR, Fourth District','Fourth District','J. P. Rizal Cor. Reposo Sts., Valenzuela, City Of Makati, NCR, Fourth District','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(296,'Cabugao Lune Pudtol Road, Quirino, Luna, Apayao','Apayao','Cabugao Lune Pudtol Road, Quirino, Luna, Apayao','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(297,'National Rd. Corner Batara, Imelda, Pudtol, Apayao','Apayao','National Rd. Corner Batara, Imelda, Pudtol, Apayao','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(298,'San Isidro Sur, Quirino, Luna, Apayao','Apayao','San Isidro Sur, Quirino, Luna, Apayao','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(299,'Provincial Road, Gloria Street, Barangay Ii (Pob.), Baler , Aurora','Aurora','Provincial Road, Gloria Street, Barangay Ii (Pob.), Baler , Aurora','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(300,'Provincial Road, Baler, Dimanpudso, Maria Aurora, Aurora','Aurora','Provincial Road, Baler, Dimanpudso, Maria Aurora, Aurora','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(301,'Harrison Road, Rizal Monument Area, City Of Baguio, Benguet','Benguet','Harrison Road, Rizal Monument Area, City Of Baguio, Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(302,'KM.4.5 Green Valley, Marcos Highway, Dontogan, City Of Baguio, Benguet','Benguet','KM.4.5 Green Valley, Marcos Highway, Dontogan, City Of Baguio, Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(303,'Naguillan Rd. Ferguson R, Quezon Hill Proper, City Of Baguio, Benguet','Benguet','Naguillan Rd. Ferguson R, Quezon Hill Proper, City Of Baguio, Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(304,'Legarda Rd. Camp Proper, Mrr-Queen Of Peace, City Of Baguio, Benguet','Benguet','Legarda Rd. Camp Proper, Mrr-Queen Of Peace, City Of Baguio, Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(305,'Bokawkan Rd., Guisad Central, City Of Baguio, Benguet','Benguet','Bokawkan Rd., Guisad Central, City Of Baguio, Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(306,'Baguio-Bua-Itogon Rd., Gumatdang, City Of Baguio, Benguet','Benguet','Baguio-Bua-Itogon Rd., Gumatdang, City Of Baguio, Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(307,'Halsema Highway, Poblacion, La Trinidad , Benguet','Benguet','Halsema Highway, Poblacion, La Trinidad , Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(308,'Gov. J.j. Linao, National Road, Nagbalayong, Morong, Bataan','Bataan','Gov. J.j. Linao, National Road, Nagbalayong, Morong, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(309,'Sbma - Morong Rd., Un Ave., Sabang, Morong, Bataan','Bataan','Sbma - Morong Rd., Un Ave., Sabang, Morong, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(310,'National Road, Atilano L. Ricardo, Bagac, Bataan','Bataan','National Road, Atilano L. Ricardo, Bagac, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(311,'San Ramon, Kataasan, Dinalupihan, Bataan','Bataan','San Ramon, Kataasan, Dinalupihan, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(312,'Argonaut Highway, Subic Freeport, Mabayo, Morong, Bataan','Bataan','Argonaut Highway, Subic Freeport, Mabayo, Morong, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(313,'Provincial Road Purok 2, San Roque Dau, Lubao, Pampanga','Pampanga','Provincial Road Purok 2, San Roque Dau, Lubao, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(314,'Arellano Avenue, Puksuan, Orani, Bataan','Bataan','Arellano Avenue, Puksuan, Orani, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(315,'Roman Super Highway, Ala-Uli, Pilar, Bataan','Bataan','Roman Super Highway, Ala-Uli, Pilar, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(316,'Roman Highway, Pandatung, Hermosa, Bataan','Bataan','Roman Highway, Pandatung, Hermosa, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(317,'Provincial Highway, Omboy, Abucay, Bataan','Bataan','Provincial Highway, Omboy, Abucay, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(318,'Old Highway, Burgos-Soliman (Pob.), Hermosa, Bataan','Bataan','Old Highway, Burgos-Soliman (Pob.), Hermosa, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(319,'Old Highway, Judge Roman Cruz Sr., Hermosa, Bataan','Bataan','Old Highway, Judge Roman Cruz Sr., Hermosa, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(320,'National Road, Mabatang, Abucay, Bataan','Bataan','National Road, Mabatang, Abucay, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(321,'Roman Highway, Alangan, Limay, Bataan','Bataan','Roman Highway, Alangan, Limay, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(322,'National Highway, Cor. Mindanao Ave., Maligaya, Mariveles, Bataan','Bataan','National Highway, Cor. Mindanao Ave., Maligaya, Mariveles, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(323,'Roman Super Highway, Tuyo, City Of Balanga , Bataan','Bataan','Roman Super Highway, Tuyo, City Of Balanga , Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(324,'J. P. Rizal St. , Talisay, City Of Balanga , Bataan','Bataan','J. P. Rizal St. , Talisay, City Of Balanga , Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(325,'Roman Super Highway, Mulawin Road, Mulawin, Orani, Bataan','Bataan','Roman Super Highway, Mulawin Road, Mulawin, Orani, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(326,'Roman Highway, Bilolo, Orion, Bataan','Bataan','Roman Highway, Bilolo, Orion, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(327,'National Road, Ala-Uli, Pilar, Bataan','Bataan','National Road, Ala-Uli, Pilar, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(328,'Roman Highway, Cor. Tundol, Reformista, Limay, Bataan','Bataan','Roman Highway, Cor. Tundol, Reformista, Limay, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(329,'National Road, Ibaba (Pob.), Samal, Bataan','Bataan','National Road, Ibaba (Pob.), Samal, Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(330,'Rizal St.;, San Fernando (Pob.), Victoria, Tarlac','Tarlac','Rizal St.;, San Fernando (Pob.), Victoria, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(331,'Pico Road KM.5, Pico, La Trinidad , Benguet','Benguet','Pico Road KM.5, Pico, La Trinidad , Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(332,'Ambuklao Rd., Beckel, La Trinidad , Benguet','Benguet','Ambuklao Rd., Beckel, La Trinidad , Benguet','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(333,'Mac Arthur Highway, San Nicolas (Pob.), Minalin, Pampanga','Pampanga','Mac Arthur Highway, San Nicolas (Pob.), Minalin, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(334,'National Highway, Centro Sur (Pob.), Camalaniugan, Cagayan','Cagayan','National Highway, Centro Sur (Pob.), Camalaniugan, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(335,'National Road, Centro (Pob.), Santa Ana, Cagayan','Cagayan','National Road, Centro (Pob.), Santa Ana, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(336,'National Road, Casambalangan, Santa Ana, Cagayan','Cagayan','National Road, Casambalangan, Santa Ana, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(337,'Purok Paraiso, Baua, Gonzaga, Cagayan','Cagayan','Purok Paraiso, Baua, Gonzaga, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(338,'National Highway, Bulo, Allacapan, Cagayan','Cagayan','National Highway, Bulo, Allacapan, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(339,'Dugo San Vicente Road, National Rd., Pattao, Buguey, Cagayan','Cagayan','Dugo San Vicente Road, National Rd., Pattao, Buguey, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(340,'Dugo San Vicente Road, Bulala, Camalaniugan, Cagayan','Cagayan','Dugo San Vicente Road, Bulala, Camalaniugan, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(341,'Poblacion Road, Santa Cruz, Ballesteros, Cagayan','Cagayan','Poblacion Road, Santa Cruz, Ballesteros, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(342,'General Luna St., National Rd., Macanaya, Aparri, Cagayan','Cagayan','General Luna St., National Rd., Macanaya, Aparri, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(343,'Maharlika Highway, Dumpao, Iguig, Cagayan','Cagayan','Maharlika Highway, Dumpao, Iguig, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(344,'Maharlika Highway, Tupang, Alcala, Cagayan','Cagayan','Maharlika Highway, Tupang, Alcala, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(345,'Maharlika Highway, Pengue, Leonarda, Tuguegarao City , Cagayan','Cagayan','Maharlika Highway, Pengue, Leonarda, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(346,'Maharlika Highway, Brgy. Buntun, Tuguegarao City, Cagayan','Cagayan','Maharlika Highway, Brgy. Buntun, Tuguegarao City, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(347,'National Road, Caggay, Tuguegarao City , Cagayan','Cagayan','National Road, Caggay, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(348,'Barrio Capatan , Libag Norte, Tuguegarao City , Cagayan','Cagayan','Barrio Capatan , Libag Norte, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(349,'Maharlika Highway, San Roque St., Pengue, Leonarda, Tuguegarao City , Cagayan','Cagayan','Maharlika Highway, San Roque St., Pengue, Leonarda, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(350,'National Highway, Pata, Tuao, Cagayan','Cagayan','National Highway, Pata, Tuao, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(351,'Bagay Road, Caritan Centro, Caritan Norte, Tuguegarao City , Cagayan','Cagayan','Bagay Road, Caritan Centro, Caritan Norte, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(352,'Maharlika Highway, Centro, Amulung, Cagayan','Cagayan','Maharlika Highway, Centro, Amulung, Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(353,'Buntun Highway, Buntun, Tuguegarao City , Cagayan','Cagayan','Buntun Highway, Buntun, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(354,'Bonifacio St., Centro 8 (Pob.), Tuguegarao City , Cagayan','Cagayan','Bonifacio St., Centro 8 (Pob.), Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(355,'Diversion Road, Caritan Norte, Tuguegarao City , Cagayan','Cagayan','Diversion Road, Caritan Norte, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(356,'Tramo Road, Bagay, Tuguegarao City , Cagayan','Cagayan','Tramo Road, Bagay, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(357,'Maharlika Highway, Carig, Tuguegarao City , Cagayan','Cagayan','Maharlika Highway, Carig, Tuguegarao City , Cagayan','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(358,'National Highway, Mabatobato, Lamut, Ifugao','Ifugao','National Highway, Mabatobato, Lamut, Ifugao','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(359,'National Highway, Bgy. No. 51-A, Nangalisan East, City Of Laoag , Ilocos Norte','Ilocos Norte','National Highway, Bgy. No. 51-A, Nangalisan East, City Of Laoag , Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(360,'Rizal St., Bgy. No. 51-A, Nangalisan East, City Of Laoag , Ilocos Norte','Ilocos Norte','Rizal St., Bgy. No. 51-A, Nangalisan East, City Of Laoag , Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(361,'National Highway, Pan Philippine Highway, Bani, Bacarra, Ilocos Norte','Ilocos Norte','National Highway, Pan Philippine Highway, Bani, Bacarra, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(362,'Valdez Center, Brgy. 2, San Baltazar (Pob.), San Nicolas, Ilocos Norte','Ilocos Norte','Valdez Center, Brgy. 2, San Baltazar (Pob.), San Nicolas, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(363,'National Highway, 20-A, Gabut Norte, Badoc, Ilocos Norte','Ilocos Norte','National Highway, 20-A, Gabut Norte, Badoc, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(364,'National Highway, Pussuac, Santo Domingo, Ilocos Sur','Ilocos Sur','National Highway, Pussuac, Santo Domingo, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(365,'Laoag Bypass Road, Bgy. No. 55-C, Vira, City Of Laoag , Ilocos Norte','Ilocos Norte','Laoag Bypass Road, Bgy. No. 55-C, Vira, City Of Laoag , Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(366,'Gen. Segunda Ave., Bgy. No. 55-B, Salet-Bulangon, City Of Laoag , Ilocos Norte','Ilocos Norte','Gen. Segunda Ave., Bgy. No. 55-B, Salet-Bulangon, City Of Laoag , Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(367,'Gen. Luna St., Cor. Villanueva St., Bgy. No. 16, San Jacinto (Pob.), City Of Laoag , Ilocos Norte No','Ilocos Norte No','Gen. Luna St., Cor. Villanueva St., Bgy. No. 16, San Jacinto (Pob.), City Of Laoag , Ilocos Norte No','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(368,'San Miguel St., Brgy. 10, San Nicolas, Sarrat, Ilocos Norte','Ilocos Norte','San Miguel St., Brgy. 10, San Nicolas, Sarrat, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(369,'Marcos Ave. Cabangaran, Cabagoan, Paoay, Ilocos Norte','Ilocos Norte','Marcos Ave. Cabangaran, Cabagoan, Paoay, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(370,'Aglipay Rd., Santa Maria (Pob.), Vintar, Ilocos Norte','Ilocos Norte','Aglipay Rd., Santa Maria (Pob.), Vintar, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(371,'National Highway, Anao (Pob.), Piddig, Ilocos Norte','Ilocos Norte','National Highway, Anao (Pob.), Piddig, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(372,'National Highway, Arua-Ay, Piddig, Ilocos Norte','Ilocos Norte','National Highway, Arua-Ay, Piddig, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(373,'National Highway, Madamba (Pob.), Dingras, Ilocos Norte','Ilocos Norte','National Highway, Madamba (Pob.), Dingras, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(374,'National Highway, Abaca, Bangui, Ilocos Norte','Ilocos Norte','National Highway, Abaca, Bangui, Ilocos Norte','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(375,'National Highway, Bannuar (Pob.), San Juan, Ilocos Sur','Ilocos Sur','National Highway, Bannuar (Pob.), San Juan, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(376,'National Highway, Salapasap, Cabugao, Ilocos Sur','Ilocos Sur','National Highway, Salapasap, Cabugao, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(377,'Jose Singson St., Barangay Viii, City Of Vigan , Ilocos Sur','Ilocos Sur','Jose Singson St., Barangay Viii, City Of Vigan , Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(378,'National Highway, Barangay 5 (Pob.), Bantay, Ilocos Sur','Ilocos Sur','National Highway, Barangay 5 (Pob.), Bantay, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(379,'National Highway, Santa Monica, Magsingal, Ilocos Sur','Ilocos Sur','National Highway, Santa Monica, Magsingal, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(380,'National Highway, Bagani Camposa, San Juan (Pob.), City Of Candon, Ilocos Sur','Ilocos Sur','National Highway, Bagani Camposa, San Juan (Pob.), City Of Candon, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(381,'National Highway, Poblacion Sur, Santiago, Ilocos Sur','Ilocos Sur','National Highway, Poblacion Sur, Santiago, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(382,'Mac Arthhur Highway, Libtong, Bitalag, Tagudin, Ilocos Sur','Ilocos Sur','Mac Arthhur Highway, Libtong, Bitalag, Tagudin, Ilocos Sur','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(383,'National Highway, Baligatan, City Of Ilagan , Isabela','Isabela','National Highway, Baligatan, City Of Ilagan , Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(384,'Maharlika Highway, Santiago-Tuguegarao Road, San Antonio, Roxas, Isabela','Isabela','Maharlika Highway, Santiago-Tuguegarao Road, San Antonio, Roxas, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(385,'National Road, Imbiao, Roxas, Isabela','Isabela','National Road, Imbiao, Roxas, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(386,'San Bernabe, Luna, Masigun, Roxas, Isabela','Isabela','San Bernabe, Luna, Masigun, Roxas, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(387,'National Highway, Binguang, San Pablo, Isabela','Isabela','National Highway, Binguang, San Pablo, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(388,'National Highway, Masigun, Roxas, Isabela','Isabela','National Highway, Masigun, Roxas, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(389,'National Highway, San Pedro, Mallig, Isabela','Isabela','National Highway, San Pedro, Mallig, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(390,'Santiago-Tuguegarao Road, San Juan, Quezon, Isabela','Isabela','Santiago-Tuguegarao Road, San Juan, Quezon, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(391,'Maharlika Hiway, Nungnungan Ii, City Of Cauayan, Isabela','Isabela','Maharlika Hiway, Nungnungan Ii, City Of Cauayan, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(392,'National Highway, Magdalena, Cabatuan, Isabela','Isabela','National Highway, Magdalena, Cabatuan, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(393,'National Highway, Santa Rita, Aurora, Isabela','Isabela','National Highway, Santa Rita, Aurora, Isabela','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(394,'Maharlika Hiway, Magsaysay (Pob.), Naguilian, Isabela','Isabela','Maharlika Hiway, Magsaysay (Pob.), Naguilian, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(395,'National Highwah, Alicia-San Mateo Road , Magsaysay (Pob.), Alicia, Isabela','Isabela','National Highwah, Alicia-San Mateo Road , Magsaysay (Pob.), Alicia, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(396,'Maharlika Hiway, District Ii (Pob.), City Of Cauayan, Isabela','Isabela','Maharlika Hiway, District Ii (Pob.), City Of Cauayan, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(397,'National Highway, Centro Ii (Pob.), Angadanan, Isabela','Isabela','National Highway, Centro Ii (Pob.), Angadanan, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(398,'Maharlika Hiway, San Fabian, Echague, Isabela','Isabela','Maharlika Hiway, San Fabian, Echague, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(399,'Purok 6, Santiago-San Agustin Rd., Santos, San Agustin, Isabela','Isabela','Purok 6, Santiago-San Agustin Rd., Santos, San Agustin, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(400,'Maharlika Highway, San Antonio, Ramon, Isabela','Isabela','Maharlika Highway, San Antonio, Ramon, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(401,'Santiago-Tuguegarao Road, Maharlika Highway, Burgos, Ramon, Isabela','Isabela','Santiago-Tuguegarao Road, Maharlika Highway, Burgos, Ramon, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(402,'Provincial Road, Rizal, City Of Santiago, Isabela','Isabela','Provincial Road, Rizal, City Of Santiago, Isabela','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(403,'Maharlika Highway, Calao East (Pob.), City Of Santiago, Isabela','Isabela','Maharlika Highway, Calao East (Pob.), City Of Santiago, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(404,'Maharlika Highway, Batal, City Of Santiago, Isabela','Isabela','Maharlika Highway, Batal, City Of Santiago, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(405,'National Road, Rosario, City Of Santiago, Isabela','Isabela','National Road, Rosario, City Of Santiago, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(406,'Rc Miranda Blvd., Centro West (Pob.), City Of Santiago, Isabela','Isabela','Rc Miranda Blvd., Centro West (Pob.), City Of Santiago, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(407,'National Road, Baluarte, City Of Santiago, Isabela','Isabela','National Road, Baluarte, City Of Santiago, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(408,'Maharlika Highway, Quirino, Cordon, Isabela','Isabela','Maharlika Highway, Quirino, Cordon, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(409,'National Highway, Gundaway (Pob.), Cabarroguis , Quirino','Quirino','National Highway, Gundaway (Pob.), Cabarroguis , Quirino','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(410,'Maharlika Hiway, Capirpiriwan, Cordon, Isabela','Isabela','Maharlika Hiway, Capirpiriwan, Cordon, Isabela','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(411,'National Road, Rizal, City Of Santiago, Isabela','Isabela','National Road, Rizal, City Of Santiago, Isabela','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(412,'Provincialroad Cor. Molintas, Dagupan St., Bulanao Norte, City Of Tabuk , Kalinga','Kalinga','Provincialroad Cor. Molintas, Dagupan St., Bulanao Norte, City Of Tabuk , Kalinga','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(413,'Quezon Corner Burgos, Dagupan Centro (Pob.), City Of Tabuk , Kalinga','Kalinga','Quezon Corner Burgos, Dagupan Centro (Pob.), City Of Tabuk , Kalinga','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(414,'Quezon Ave., Catbangen, City Of San Fernando , La Union','La Union','Quezon Ave., Catbangen, City Of San Fernando , La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(415,'National Highway, Suguidan Sur, Naguilian, La Union','La Union','National Highway, Suguidan Sur, Naguilian, La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(416,'By-Pass Road, Tanqui, City Of San Fernando , La Union','La Union','By-Pass Road, Tanqui, City Of San Fernando , La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(417,'National Highway, Quinavite, Bauang, La Union','La Union','National Highway, Quinavite, Bauang, La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(418,'Mac Arthur Highway, Sevilla, City Of San Fernando , La Union','La Union','Mac Arthur Highway, Sevilla, City Of San Fernando , La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(419,'National Highway, Lingsat, City Of San Fernando , La Union','La Union','National Highway, Lingsat, City Of San Fernando , La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(420,'National Highway, Brgy. San Jose Sur, Agoo, La Union','La Union','National Highway, Brgy. San Jose Sur, Agoo, La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(421,'National Highway, Central East (Pob.), Bauang, La Union','La Union','National Highway, Central East (Pob.), Bauang, La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(422,'Provincial Road, Santia, Cabaroan, City Of San Fernando , La Union','La Union','Provincial Road, Santia, Cabaroan, City Of San Fernando , La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(423,'Mabini St., Catbangen, City Of San Fernando , La Union','La Union','Mabini St., Catbangen, City Of San Fernando , La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(424,'Pugo Rosario Rd., San Luis, Pugo, La Union','La Union','Pugo Rosario Rd., San Luis, Pugo, La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(425,'National Highway, Camp 1 Udiao, Camp One, Rosario, La Union','La Union','National Highway, Camp 1 Udiao, Camp One, Rosario, La Union','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(426,'Sub-Ang, Bontoc, Bontoc, Mountain Province','Mountain Province','Sub-Ang, Bontoc, Bontoc, Mountain Province','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(427,'Pan Philippine Highway, Caanawan, San Jose City, Nueva Ecija','Nueva Ecija','Pan Philippine Highway, Caanawan, San Jose City, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(428,'National Road, Linglingay, Science City Of Mu??Oz, Nueva Ecija','Nueva Ecija','National Road, Linglingay, Science City Of Mu??Oz, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(429,'Maharlika Highway Cor. Bonifacio St., Canuto Ramos Pob., San Jose, Nueva Ecija','Nueva Ecija','Maharlika Highway Cor. Bonifacio St., Canuto Ramos Pob., San Jose, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(430,'Maharlika Higway, Malasin, San Jose City, Nueva Ecija','Nueva Ecija','Maharlika Higway, Malasin, San Jose City, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(431,'San Jose City-Rizal Provincial Road, San Agustin, San Jose City, Nueva Ecija','Nueva Ecija','San Jose City-Rizal Provincial Road, San Agustin, San Jose City, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(432,'Maharlikha Highway, Baloc, Santo Domingo, Nueva Ecija','Nueva Ecija','Maharlikha Highway, Baloc, Santo Domingo, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(433,'Maharlika Highway, Abar Ist, San Jose, Nueva Ecija','Nueva Ecija','Maharlika Highway, Abar Ist, San Jose, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(434,'Maharlikha Highway, San Miguel Na Munti, Umangan, Aliaga, Nueva Ecija','Nueva Ecija','Maharlikha Highway, San Miguel Na Munti, Umangan, Aliaga, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(435,'Pangasinan - Nueva Ecija Road, San Roque, Guimba, Nueva Ecija','Nueva Ecija','Pangasinan - Nueva Ecija Road, San Roque, Guimba, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(436,'Provincial Road, Cabiangan, Talugtug, Nueva Ecija','Nueva Ecija','Provincial Road, Cabiangan, Talugtug, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(437,'Purok 3, Baloc, Santo Domingo, Nueva Ecija','Nueva Ecija','Purok 3, Baloc, Santo Domingo, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(438,'Pantabangan - Canili - Basal - Baler Road, Liberty, Pantabangan, Nueva Ecija','Nueva Ecija','Pantabangan - Canili - Basal - Baler Road, Liberty, Pantabangan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(439,'Zone 1, Parista, Cordero, Lupao, Nueva Ecija','Nueva Ecija','Zone 1, Parista, Cordero, Lupao, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(440,'Maharlika Highway, Zamora (Pob.), Santa Rosa, Nueva Ecija','Nueva Ecija','Maharlika Highway, Zamora (Pob.), Santa Rosa, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(441,'Maharlika Highway, Bayanihan, Pan Phil., San Nicolas, City Of Gapan, Nueva Ecija','Nueva Ecija','Maharlika Highway, Bayanihan, Pan Phil., San Nicolas, City Of Gapan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(442,'Sto Domingo-Licab Road, Dulong Bayan, Quezon, Nueva Ecija','Nueva Ecija','Sto Domingo-Licab Road, Dulong Bayan, Quezon, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(443,'National Highway, South Poblacion, Gabaldon, Nueva Ecija','Nueva Ecija','National Highway, South Poblacion, Gabaldon, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(444,'Maharlika Highway, Sumacab Este, City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Maharlika Highway, Sumacab Este, City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(445,'Zulueta Street, Vijandre District (Pob.), City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Zulueta Street, Vijandre District (Pob.), City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(446,'Pirok 7 Emilio Vergara Highway Cor. Mabini St. Extension , San Josef Sur, City Of Cabanatuan, Nueva E','Nueva E','Pirok 7 Emilio Vergara Highway Cor. Mabini St. Extension , San Josef Sur, City Of Cabanatuan, Nueva E','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(447,'Maharlika Highway Del Pilar St. Cor. , Sangitan East, City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Maharlika Highway Del Pilar St. Cor. , Sangitan East, City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(448,'Bangad-Fort Magsaysay Rd.,, Malaca??Ang, Santa Rosa, Nueva Ecija','Nueva Ecija','Bangad-Fort Magsaysay Rd.,, Malaca??Ang, Santa Rosa, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(449,'Victoria-Licab Road, Poblacion Norte, Villarosa, Licab, Nueva Ecija','Nueva Ecija','Victoria-Licab Road, Poblacion Norte, Villarosa, Licab, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(450,'Maharlikha Highway, Mayapyap Sur, City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Maharlikha Highway, Mayapyap Sur, City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(451,'Gen. Luna St., General Luna (Pob.), City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Gen. Luna St., General Luna (Pob.), City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(452,'Emilio Vergara Boulevard Cor. A Mabini St. , Santa Arcadia, City Of Cabanatuan, Nueva Ecija North Lu','Nueva Ecija North Lu','Emilio Vergara Boulevard Cor. A Mabini St. , Santa Arcadia, City Of Cabanatuan, Nueva Ecija North Lu','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(453,'Maharlikha Highway, Magsaysay District, Maria Theresa, City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Maharlikha Highway, Magsaysay District, Maria Theresa, City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(454,'Maharlikha Highway, Hermogenes C. Concepcion, Sr., City Of Cabanatuan, Nueva Ecija','Nueva Ecija','Maharlikha Highway, Hermogenes C. Concepcion, Sr., City Of Cabanatuan, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(455,'Emilio Vergara Hiway, Cabanatuan City, Nueva Ecija','Nueva Ecija','Emilio Vergara Hiway, Cabanatuan City, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(456,'Maharlika Highway, Banggot (Pob.), Bambang, Nueva Vizcaya','Nueva Vizcaya','Maharlika Highway, Banggot (Pob.), Bambang, Nueva Vizcaya','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(457,'National Road, Salvacion, Bayombong , Nueva Vizcaya','Nueva Vizcaya','National Road, Salvacion, Bayombong , Nueva Vizcaya','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(458,'National Highway, Roxas, Solano, Nueva Vizcaya','Nueva Vizcaya','National Highway, Roxas, Solano, Nueva Vizcaya','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(459,'National Highway, Poblacion, Aritao, Nueva Vizcaya','Nueva Vizcaya','National Highway, Poblacion, Aritao, Nueva Vizcaya','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(460,'Barrio Maddiangat, Maddiangat, Quezon, Nueva Vizcaya','Nueva Vizcaya','Barrio Maddiangat, Maddiangat, Quezon, Nueva Vizcaya','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(461,'Atihan Road National Highway Aniingw, Matain, Subic, Pampanga','Pampanga','Atihan Road National Highway Aniingw, Matain, Subic, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(462,'Gapan-Olongapo Road, San Matias, Guagua, Pampanga','Pampanga','Gapan-Olongapo Road, San Matias, Guagua, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(463,'Provincial Road, San Matias, Santa Rita, Pampanga','Pampanga','Provincial Road, San Matias, Santa Rita, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(464,'Provincial Road, Mabical, Fortuna, Floridablanca, Pampanga','Pampanga','Provincial Road, Mabical, Fortuna, Floridablanca, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(465,'Jasa Road, Prado Siongco, Lubao, Pampanga','Pampanga','Jasa Road, Prado Siongco, Lubao, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(466,'National Road, Tenejero, City Of Balanga , Bataan','Bataan','National Road, Tenejero, City Of Balanga , Bataan','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(467,'Olongapo-Gapan Road, San Antonio, Guagua, Pampanga','Pampanga','Olongapo-Gapan Road, San Antonio, Guagua, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(468,'Gapan-San Fernando-Olongapo Road, San Matias, Guagua, Pampanga','Pampanga','Gapan-San Fernando-Olongapo Road, San Matias, Guagua, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(469,'J. Gonzales Blvd., Virgen De Los Remedios, City Of Angeles, Pampanga','Pampanga','J. Gonzales Blvd., Virgen De Los Remedios, City Of Angeles, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(470,'Macarthur Highway, Dau, Lakandula, Mabalacat City, Pampanga','Pampanga','Macarthur Highway, Dau, Lakandula, Mabalacat City, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(471,'McArthur Highway (Northbound), Balibago, City Of Angeles, Pampanga','Pampanga','McArthur Highway (Northbound), Balibago, City Of Angeles, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(472,'Clark Perimeter Rd., Don Juico Ave., Malabanias, City Of Angeles, Pampanga','Pampanga','Clark Perimeter Rd., Don Juico Ave., Malabanias, City Of Angeles, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(473,'Macarthur Highway, Calumpang, Mabalacat City, Pampanga','Pampanga','Macarthur Highway, Calumpang, Mabalacat City, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(474,'Town Center D, Clark, Poblacion, Mabalacat City, Pampanga','Pampanga','Town Center D, Clark, Poblacion, Mabalacat City, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(475,'San Francisco St. Cor. Arayat Blvd, Pampang, City Of Angeles, Pampanga','Pampanga','San Francisco St. Cor. Arayat Blvd, Pampang, City Of Angeles, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(476,'Roque Lane Sunset Estate, Pulung Maragul, City Of Angeles, Pampanga','Pampanga','Roque Lane Sunset Estate, Pulung Maragul, City Of Angeles, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(477,'Macarthur Highway, Sto. Domingo, Telabastagan, City Of San Fernando , Pampanga','Pampanga','Macarthur Highway, Sto. Domingo, Telabastagan, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(478,'Mc Arthur Highway, Sto. Cristo, Pulungbulu, City Of Angeles, Pampanga','Pampanga','Mc Arthur Highway, Sto. Cristo, Pulungbulu, City Of Angeles, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(479,'Purok 6 Sta. Ines Interchange, San Joaquin, Mabalacat City, Pampanga','Pampanga','Purok 6 Sta. Ines Interchange, San Joaquin, Mabalacat City, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(480,'Cor. Clark South Interchange & Ma. Roxas St., Poblacion, Mabalacat City, Pampanga','Pampanga','Cor. Clark South Interchange & Ma. Roxas St., Poblacion, Mabalacat City, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(481,'Gen. Luna St., Cangatba, Porac, Pampanga','Pampanga','Gen. Luna St., Cangatba, Porac, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(482,'Mac Arthur Highway, San Matias, Moras De La Paz, Santo Tomas, Pampanga','Pampanga','Mac Arthur Highway, San Matias, Moras De La Paz, Santo Tomas, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(483,'Robinson\'s Starmills, Lagundi, San Jose, City Of San Fernando , Pampanga','Pampanga','Robinson\'s Starmills, Lagundi, San Jose, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(484,'Mac Arthur Highway, San Vicente, Apalit, Pampanga','Pampanga','Mac Arthur Highway, San Vicente, Apalit, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(485,'Mac Arthur Highway, Sindalan, City Of San Fernando , Pampanga','Pampanga','Mac Arthur Highway, Sindalan, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(486,'Mac Arthur Highway, San Isidro, City Of San Fernando , Pampanga','Pampanga','Mac Arthur Highway, San Isidro, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(487,'Mac Arthur Highway, Tagulud, Del Pilar, City Of San Fernando , Pampanga','Pampanga','Mac Arthur Highway, Tagulud, Del Pilar, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(488,'Mac Arthur Highway, Del Rosario, City Of San Fernando , Pampanga','Pampanga','Mac Arthur Highway, Del Rosario, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(489,'Jasa Road, Dolores, City Of San Fernando , Pampanga','Pampanga','Jasa Road, Dolores, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(490,'Castor Cor., Mackinley St., San Agustin (Pob.), Candaba, Pampanga','Pampanga','Castor Cor., Mackinley St., San Agustin (Pob.), Candaba, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(491,'Calulut Road, Bulaon, City Of San Fernando , Pampanga','Pampanga','Calulut Road, Bulaon, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(492,'Provincial Road, San Pedro Ii, Magalang, Pampanga','Pampanga','Provincial Road, San Pedro Ii, Magalang, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(493,'Provincial Road, San Roque, Magalang, Pampanga','Pampanga','Provincial Road, San Roque, Magalang, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(494,'KM 71, NLEX, Lagu, Panipuan, Mexico, Pampanga','Pampanga','KM 71, NLEX, Lagu, Panipuan, Mexico, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(495,'Candaba Baliuag Rd., Pulong Palazan, Candaba, Pampanga','Pampanga','Candaba Baliuag Rd., Pulong Palazan, Candaba, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(496,'Candaba Baliuag Rd., Mangga, Candaba, Pampanga','Pampanga','Candaba Baliuag Rd., Mangga, Candaba, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(497,'Olongapo-Gapan Rd., San Jose Mesulo, Arayat, Pampanga','Pampanga','Olongapo-Gapan Rd., San Jose Mesulo, Arayat, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(498,'Gapan-Olongapo Rd., Natividad South (Pob.), Cabiao, Nueva Ecija','Nueva Ecija','Gapan-Olongapo Rd., Natividad South (Pob.), Cabiao, Nueva Ecija','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(499,'Jasa Rd., Lagundi, Mexico, Pampanga','Pampanga','Jasa Rd., Lagundi, Mexico, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(500,'Mac Arthur Highway, Dolores, Juliana, City Of San Fernando , Pampanga','Pampanga','Mac Arthur Highway, Dolores, Juliana, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(501,'Capitol Blvd., Santo Ni??O, City Of San Fernando , Pampanga','Pampanga','Capitol Blvd., Santo Ni??O, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(502,'Lazatin Blvd., Dolores, City Of San Fernando , Pampanga','Pampanga','Lazatin Blvd., Dolores, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(503,'Centro 3 San Juan, Santa Cruz, Mexico, Pampanga','Pampanga','Centro 3 San Juan, Santa Cruz, Mexico, Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(504,'New Barrio Road, Calulut, City Of San Fernando , Pampanga','Pampanga','New Barrio Road, Calulut, City Of San Fernando , Pampanga','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(505,'San Jacinto-Manaoag Road, Babasit, Manaoag, Pangasinan','Pangasinan','San Jacinto-Manaoag Road, Babasit, Manaoag, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(506,'Macarthur Highway, Carmen East, Rosales, Pangasinan','Pangasinan','Macarthur Highway, Carmen East, Rosales, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(507,'Urdaneta-Manaoag Road, Lelemaan, Manaoag, Pangasinan','Pangasinan','Urdaneta-Manaoag Road, Lelemaan, Manaoag, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(508,'L. Soloria, Poblacion East, Asingan, Pangasinan','Pangasinan','L. Soloria, Poblacion East, Asingan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(509,'National Highway, Pugot, Santa Maria, Pangasinan','Pangasinan','National Highway, Pugot, Santa Maria, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(510,'Mc Arthur Maharlika Highway, Puelay, Villasis, Pangasinan','Pangasinan','Mc Arthur Maharlika Highway, Puelay, Villasis, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(511,'National Highway Sta. Maria Sur, Canarvacanan, Binalonan, Pangasinan','Pangasinan','National Highway Sta. Maria Sur, Canarvacanan, Binalonan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(512,'National Highway, Asan Sur, Sison, Pangasinan','Pangasinan','National Highway, Asan Sur, Sison, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(513,'Purok 3 Macarthur Highway, Nancayasan, City Of Urdaneta, Pangasinan','Pangasinan','Purok 3 Macarthur Highway, Nancayasan, City Of Urdaneta, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(514,'Macarthur Highway, San Vicente, Poblacion, City Of Urdaneta, Pangasinan','Pangasinan','Macarthur Highway, San Vicente, Poblacion, City Of Urdaneta, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(515,'Magilas Trail, Toboy, Asingan, Pangasinan','Pangasinan','Magilas Trail, Toboy, Asingan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(516,'Rizal St., Asingan Bypass Road, Bantog, Asingan, Pangasinan','Pangasinan','Rizal St., Asingan Bypass Road, Bantog, Asingan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(517,'Dulong Norte 1, Payar, Malasiqui, Pangasinan','Pangasinan','Dulong Norte 1, Payar, Malasiqui, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(518,'National Highway, Carmen West, Rosales, Pangasinan','Pangasinan','National Highway, Carmen West, Rosales, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(519,'Macarthur Highway, Anonas, City Of Urdaneta, Pangasinan','Pangasinan','Macarthur Highway, Anonas, City Of Urdaneta, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(520,'Binaloan-Dagupan Highway, Pao, Manaoag, Pangasinan','Pangasinan','Binaloan-Dagupan Highway, Pao, Manaoag, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(521,'Rizal St., Poblacion Zone I, San Quintin, Pangasinan','Pangasinan','Rizal St., Poblacion Zone I, San Quintin, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(522,'Romulo Highway, Bocboc West, Aguilar, Pangasinan','Pangasinan','Romulo Highway, Bocboc West, Aguilar, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(523,'National Highway, Don Matias, Tambacan, Burgos, Pangasinan','Pangasinan','National Highway, Don Matias, Tambacan, Burgos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(524,'National Highway, Arwas, Bani, Pangasinan','Pangasinan','National Highway, Arwas, Bani, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(525,'Calasiao-Dagupan Road, Mh Del Pilar St., Mayombo, City Of Dagupan, Pangasinan','Pangasinan','Calasiao-Dagupan Road, Mh Del Pilar St., Mayombo, City Of Dagupan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(526,'Balingasay Road, Balingasay, Bolinao, Pangasinan','Pangasinan','Balingasay Road, Balingasay, Bolinao, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(527,'Olongapo-Bugallon Road, Palamis, City Of Alaminos, Pangasinan','Pangasinan','Olongapo-Bugallon Road, Palamis, City Of Alaminos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(528,'Olongapo-Bugallon Road, Magsaysay, City Of Alaminos, Pangasinan','Pangasinan','Olongapo-Bugallon Road, Magsaysay, City Of Alaminos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(529,'East A.b. Fernadez Ave., Tambac, City Of Dagupan, Pangasinan','Pangasinan','East A.b. Fernadez Ave., Tambac, City Of Dagupan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(530,'Nable St. Arellano Street, Gueset, City Of Dagupan, Pangasinan','Pangasinan','Nable St. Arellano Street, Gueset, City Of Dagupan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(531,'Quibaol-Nansangaan Road, Lomboy, Binmaley, Pangasinan','Pangasinan','Quibaol-Nansangaan Road, Lomboy, Binmaley, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(532,'National Highway, Liwa-Liwa, Bolinao, Pangasinan','Pangasinan','National Highway, Liwa-Liwa, Bolinao, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(533,'Quezon Ave., Poblacion, City Of Alaminos, Pangasinan','Pangasinan','Quezon Ave., Poblacion, City Of Alaminos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(534,'Dagupan-Binmaley Road, Lucao, City Of Dagupan, Pangasinan','Pangasinan','Dagupan-Binmaley Road, Lucao, City Of Dagupan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(535,'Labrador-Sual Highway, Tobuan, Uyong, Labrador, Pangasinan','Pangasinan','Labrador-Sual Highway, Tobuan, Uyong, Labrador, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(536,'Perez Blvd., Malued, City Of Dagupan, Pangasinan','Pangasinan','Perez Blvd., Malued, City Of Dagupan, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(537,'McArthur Highway, Nalsian, Calasiao, Pangasinan','Pangasinan','McArthur Highway, Nalsian, Calasiao, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(538,'Avenida Rizal East, Libsong West, Lingayen , Pangasinan','Pangasinan','Avenida Rizal East, Libsong West, Lingayen , Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(539,'Poblacion East, Tandoc, Quintong, City Of San Carlos, Pangasinan','Pangasinan','Poblacion East, Tandoc, Quintong, City Of San Carlos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(540,'San Carlos-Calasiao Road, Cruz, City Of San Carlos, Pangasinan','Pangasinan','San Carlos-Calasiao Road, Cruz, City Of San Carlos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(541,'Provincial Road, Andangin, Baracbac, Mangatarem, Pangasinan','Pangasinan','Provincial Road, Andangin, Baracbac, Mangatarem, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(542,'Provincial Road, Aponit, City Of San Carlos, Pangasinan','Pangasinan','Provincial Road, Aponit, City Of San Carlos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(543,'Malasiqui-San Carlos Road, Magtaking, City Of San Carlos, Pangasinan','Pangasinan','Malasiqui-San Carlos Road, Magtaking, City Of San Carlos, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(544,'Carmen-Alcala Road, Poblacion East, Alcala, Pangasinan','Pangasinan','Carmen-Alcala Road, Poblacion East, Alcala, Pangasinan','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(545,'National Highway, Rizal (Pob.), Saguday, Quirino','Quirino','National Highway, Rizal (Pob.), Saguday, Quirino','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(546,'Cordon-Diffun, Maddela Road, Aurora West (Pob.), Diffun, Quirino','Quirino','Cordon-Diffun, Maddela Road, Aurora West (Pob.), Diffun, Quirino','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(547,'Calabtangan Road, Poblacion Sur, Mayantoc, Tarlac','Tarlac','Calabtangan Road, Poblacion Sur, Mayantoc, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(548,'Romulo Highway, Purok 1, Poblacion East, Santa Ignacia, Tarlac','Tarlac','Romulo Highway, Purok 1, Poblacion East, Santa Ignacia, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(549,'McArthur Highway, Poblacion 2, Moncada, Tarlac','Tarlac','McArthur Highway, Poblacion 2, Moncada, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(550,'McArthur Highway, Samput, Paniqui, Tarlac','Tarlac','McArthur Highway, Samput, Paniqui, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(551,'KM 134 TPLEX Northbound, San Francisco, Victoria, Tarlac','Tarlac','KM 134 TPLEX Northbound, San Francisco, Victoria, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(552,'McArthur Highway, Abagon, Poblacion 3, Gerona, Tarlac','Tarlac','McArthur Highway, Abagon, Poblacion 3, Gerona, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(553,'Quezon Ave., Pao 1St, Camiling, Tarlac','Tarlac','Quezon Ave., Pao 1St, Camiling, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(554,'Romulo Highway, Daldalayap, San Clemente, Tarlac','Tarlac','Romulo Highway, Daldalayap, San Clemente, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(555,'Romulo Highway, Surgui 2Nd, Camiling, Tarlac','Tarlac','Romulo Highway, Surgui 2Nd, Camiling, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(556,'Bayambang-Camiling Road, Bilad, Caniag, Camiling, Tarlac','Tarlac','Bayambang-Camiling Road, Bilad, Caniag, Camiling, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(557,'Don Basilio San Tiago St., Poblacion 1, Gerona, Tarlac','Tarlac','Don Basilio San Tiago St., Poblacion 1, Gerona, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(558,'KM 134 TPLEX Southbound, San Francisco, Victoria, Tarlac','Tarlac','KM 134 TPLEX Southbound, San Francisco, Victoria, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(559,'Lapaz-Tarlac Road, Rizal, La Paz, Tarlac','Tarlac','Lapaz-Tarlac Road, Rizal, La Paz, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(560,'Macarthur Highway,, San Francisco, City Of Tarlac , Tarlac','Tarlac','Macarthur Highway,, San Francisco, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(561,'Macarthur Highway, San Roque St., San Vicente, City Of Tarlac , Tarlac','Tarlac','Macarthur Highway, San Roque St., San Vicente, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(562,'Santa Rosa - Tarlac Road, La Paz-Za, Binauganan, City Of Tarlac , Tarlac','Tarlac','Santa Rosa - Tarlac Road, La Paz-Za, Binauganan, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(563,'Southern Bypass Road, San Vicente, City Of Tarlac , Tarlac','Tarlac','Southern Bypass Road, San Vicente, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(564,'Macarthur Highway, San Nicolas (Pob.), Bamban, Tarlac','Tarlac','Macarthur Highway, San Nicolas (Pob.), Bamban, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(565,'Macarthur Highway, San Rafael, San Vicente, City Of Tarlac , Tarlac','Tarlac','Macarthur Highway, San Rafael, San Vicente, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(566,'Romulo Highway, San Pablo, City Of Tarlac , Tarlac','Tarlac','Romulo Highway, San Pablo, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(567,'Romulo Highway, Sapang Maragul, City Of Tarlac , Tarlac','Tarlac','Romulo Highway, Sapang Maragul, City Of Tarlac , Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(568,'Concepcion-Lapaz Rd., Santo Domingo 1St, Capas, Tarlac','Tarlac','Concepcion-Lapaz Rd., Santo Domingo 1St, Capas, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(569,'L. Cortez St., San Nicolas (Pob.), Concepcion, Tarlac','Tarlac','L. Cortez St., San Nicolas (Pob.), Concepcion, Tarlac','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(570,'Finones St., Amagna (Pob.), San Felipe, Zambales','Zambales','Finones St., Amagna (Pob.), San Felipe, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(571,'Rizal Avenue Cor. West 1St, Asinan, City Of Olongapo, Zambales','Zambales','Rizal Avenue Cor. West 1St, Asinan, City Of Olongapo, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(572,'Purok 1 National Highway, Baraca-Camachile (Pob.), Subic, Zambales','Zambales','Purok 1 National Highway, Baraca-Camachile (Pob.), Subic, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(573,'National Highway, West Dirita, San Antonio, Zambales','Zambales','National Highway, West Dirita, San Antonio, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(574,'Rizal Highway, Asinan, City Of Olongapo, Zambales','Zambales','Rizal Highway, Asinan, City Of Olongapo, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(575,'Rizal Blvd. Corner Argonaut Highway, Mabayo, Morong, Zambales','Zambales','Rizal Blvd. Corner Argonaut Highway, Mabayo, Morong, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(576,'Halfmoon Beach Nat\'l Highway, Barreto, City Of Olongapo, Zambales','Zambales','Halfmoon Beach Nat\'l Highway, Barreto, City Of Olongapo, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(577,'National Highway, Brgy. Del Pilar, Castillejos, Zambales','Zambales','National Highway, Brgy. Del Pilar, Castillejos, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(578,'Rizal St., Iraya, Guinobatan, Albay','Albay','Rizal St., Iraya, Guinobatan, Albay','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(579,'Ziga Avenue Basud, Divino Rostro (Pob.), City Of Tabaco, Albay','Albay','Ziga Avenue Basud, Divino Rostro (Pob.), City Of Tabaco, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(580,'Aguinaldo St., Barangay 14 (Pob.), Bacacay, Albay','Albay','Aguinaldo St., Barangay 14 (Pob.), Bacacay, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(581,'Pier Site, Santo Cristo (Pob.), City Of Tabaco, Albay','Albay','Pier Site, Santo Cristo (Pob.), City Of Tabaco, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(582,'Prado Verde Corporation Property, Bgy. 49 - Bigaa, City Of Legazpi , Albay','Albay','Prado Verde Corporation Property, Bgy. 49 - Bigaa, City Of Legazpi , Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(583,'National Road, Lidong, Santo Domingo, Albay','Albay','National Road, Lidong, Santo Domingo, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(584,'Ziga Avenue, San Juan (Pob.), City Of Tabaco, Albay','Albay','Ziga Avenue, San Juan (Pob.), City Of Tabaco, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(585,'National Rd. Gajo St., Coro-Coro, Tiwi, Albay','Albay','National Rd. Gajo St., Coro-Coro, Tiwi, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(586,'Pan Philippine Highway, Santa Cruz (Pob.), City Of Ligao, Albay','Albay','Pan Philippine Highway, Santa Cruz (Pob.), City Of Ligao, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(587,'Lakandula Drive, Bgy. 39 - Bonot (Pob.), City Of Legazpi , Albay','Albay','Lakandula Drive, Bgy. 39 - Bonot (Pob.), City Of Legazpi , Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(588,'Maharlika Highway, Namantao, Daraga, Albay','Albay','Maharlika Highway, Namantao, Daraga, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(589,'P3 Rizal St., Bgy. 23 - Imperial Court Subd. (Pob.), City Of Legazpi , Albay','Albay','P3 Rizal St., Bgy. 23 - Imperial Court Subd. (Pob.), City Of Legazpi , Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(590,'Rizal Cor. Regidor St., Sagpon, Daraga, Albay','Albay','Rizal Cor. Regidor St., Sagpon, Daraga, Albay','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(591,'Binitayan Corner Lakandula St., Binitayan, Daraga, Albay','Albay','Binitayan Corner Lakandula St., Binitayan, Daraga, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(592,'Maharlika Highway, Bascaran, Daraga, Albay','Albay','Maharlika Highway, Bascaran, Daraga, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(593,'Washington Drive, Bgy. 8 - Bagumbayan (Pob.), City Of Legazpi , Albay','Albay','Washington Drive, Bgy. 8 - Bagumbayan (Pob.), City Of Legazpi , Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(594,'Pan Philipne Highway, Busay, Daraga, Albay','Albay','Pan Philipne Highway, Busay, Daraga, Albay','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(595,'KM 72 National Highway, Kaylaway, Nasugbu, Batangas','Batangas','KM 72 National Highway, Kaylaway, Nasugbu, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(596,'Pan-Philippine Highway St. Lazarus Village, Santiago, City Of Sto. Tomas, Batangas','Batangas','Pan-Philippine Highway St. Lazarus Village, Santiago, City Of Sto. Tomas, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(597,'Balete Road, Sambat, City Of Tanauan, Batangas','Batangas','Balete Road, Sambat, City Of Tanauan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(598,'KM 79 Star Tollway Northbound, Tibig, City Of Lipa, Batangas','Batangas','KM 79 Star Tollway Northbound, Tibig, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(599,'Tiaong-Lipa Road P Torres St., Antipolo Del Norte, City Of Lipa, Batangas','Batangas','Tiaong-Lipa Road P Torres St., Antipolo Del Norte, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(600,'J.p. Laurel National Highway, Mataas Na Lupa, City Of Lipa, Batangas','Batangas','J.p. Laurel National Highway, Mataas Na Lupa, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(601,'Provincial Rd. Cor. Hi Wood St., Bagong Pook, City Of Lipa, Batangas','Batangas','Provincial Rd. Cor. Hi Wood St., Bagong Pook, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(602,'KM 86 Star Tollway, Brgy. Aya San Jose, Batangas','Batangas','KM 86 Star Tollway, Brgy. Aya San Jose, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(603,'Jose P. Laurel Hi-Way Purok 3, Sico, City Of Lipa, Batangas','Batangas','Jose P. Laurel Hi-Way Purok 3, Sico, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(604,'Provincial Road, Banaybanay, City Of Lipa, Batangas','Batangas','Provincial Road, Banaybanay, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(605,'Ayala Highway, Mataas Na Lupa, City Of Lipa, Batangas','Batangas','Ayala Highway, Mataas Na Lupa, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(606,'Talisay - Tanauan Rd., Santor, City Of Tanauan, Batangas','Batangas','Talisay - Tanauan Rd., Santor, City Of Tanauan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(607,'National Road, Barangay Ii (Pob.), City Of Sto. Tomas, Batangas','Batangas','National Road, Barangay Ii (Pob.), City Of Sto. Tomas, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(608,'KM 75 Star Tollways, San Andres, Malvar, Batangas','Batangas','KM 75 Star Tollways, San Andres, Malvar, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(609,'Mahogany St. Cor. Lipa-Alaminos Rd., Dagatan, City Of Lipa, Batangas','Batangas','Mahogany St. Cor. Lipa-Alaminos Rd., Dagatan, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(610,'KM 82 President Laurel Highway, Barangay 12 (Pob.), City Of Lipa, Batangas','Batangas','KM 82 President Laurel Highway, Barangay 12 (Pob.), City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(611,'Pan Philippine Highway, Santa Anastacia, City Of Sto. Tomas, Batangas','Batangas','Pan Philippine Highway, Santa Anastacia, City Of Sto. Tomas, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(612,'Provincial Road, Poblacion, Padre Garcia, Batangas','Batangas','Provincial Road, Poblacion, Padre Garcia, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(613,'San Juan-Laiya Road, Mabalanoy, San Juan, Batangas','Batangas','San Juan-Laiya Road, Mabalanoy, San Juan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:05'),(614,'KM. 81 Gen. Luna St., Sabang, City Of Lipa, Batangas','Batangas','KM. 81 Gen. Luna St., Sabang, City Of Lipa, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(615,'National Highway, Taysan, San Jose, Batangas','Batangas','National Highway, Taysan, San Jose, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(616,'Gov. Carpio Rd., Gulod Itaas, Batangas City , Batangas','Batangas','Gov. Carpio Rd., Gulod Itaas, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(617,'New By Pass Road Sampaga, Batangas City, Batangas','Batangas','New By Pass Road Sampaga, Batangas City, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(618,'Talaibon National Road, Poblacion, Ibaan, Batangas','Batangas','Talaibon National Road, Poblacion, Ibaan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(619,'Palipandan Road, Palindan, Ibaan, Batangas','Batangas','Palipandan Road, Palindan, Ibaan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(620,'Diversion Road, Bolbok, Batangas City , Batangas','Batangas','Diversion Road, Bolbok, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(621,'Pastor Ave., Barangay Cuta, Batangas City , Batangas','Batangas','Pastor Ave., Barangay Cuta, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(622,'Kumintang Ialaya, Batangas City , Batangas','Batangas','Kumintang Ialaya, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(623,'National Highway Cor. P. Burgos St., Batangas City , Batangas','Batangas','National Highway Cor. P. Burgos St., Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(624,'KM 103 P. Burgos St., Bolbok, Batangas City , Batangas','Batangas','KM 103 P. Burgos St., Bolbok, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(625,'Caltex Road, Banaba Ibaba, Batangas City , Batangas','Batangas','Caltex Road, Banaba Ibaba, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(626,'National Highway, Balagtas, Batangas City , Batangas','Batangas','National Highway, Balagtas, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(627,'Sitio 7 Balagtas, Banaba Kanluran, Batangas City , Batangas','Batangas','Sitio 7 Balagtas, Banaba Kanluran, Batangas City , Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(628,'San Jose - Ibaan - Batangas Road, Palindan, Ibaan, Batangas','Batangas','San Jose - Ibaan - Batangas Road, Palindan, Ibaan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(629,'Batangas - Tabangao - Lobo Rd., Fabrica, Lobo, Batangas','Batangas','Batangas - Tabangao - Lobo Rd., Fabrica, Lobo, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(630,'Gov. Antonio Carpio Rd., Mapulo, Taysan, Batangas','Batangas','Gov. Antonio Carpio Rd., Mapulo, Taysan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(631,'Purok 4 Sta. Rita Karsada Bauan-Batangas Road, Santa Rita Karsada, Batangas City , Batangas South Lu','Batangas South Lu','Purok 4 Sta. Rita Karsada Bauan-Batangas Road, Santa Rita Karsada, Batangas City , Batangas South Lu','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(632,'Nasugbu - Ternate Highway, Wawa, Nasugbu, Batangas','Batangas','Nasugbu - Ternate Highway, Wawa, Nasugbu, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(633,'Calaca - Lemery Highway, Sangalang, Lemery, Batangas','Batangas','Calaca - Lemery Highway, Sangalang, Lemery, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(634,'National Highway Mangobos St., Barangay I (Pob.), Bauan, Batangas','Batangas','National Highway Mangobos St., Barangay I (Pob.), Bauan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(635,'National Highway, Camastilisan, Calaca, Batangas','Batangas','National Highway, Camastilisan, Calaca, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(636,'Ilustre Ave., Brgy. District Ii, Lemery, Batangas','Batangas','Ilustre Ave., Brgy. District Ii, Lemery, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:46'),(637,'Km94 National Highway, Barangay 1 (Pob.), Cuenca, Batangas','Batangas','Km94 National Highway, Barangay 1 (Pob.), Cuenca, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(638,'Paz Corner Antorcha, Barangay 12 (Pob.), Balayan, Batangas','Batangas','Paz Corner Antorcha, Barangay 12 (Pob.), Balayan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(639,'National Highway Palico-Balayan-Batangas Rd., Caloocan, Balayan, Batangas','Batangas','National Highway Palico-Balayan-Batangas Rd., Caloocan, Balayan, Batangas','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(640,'National Highway, San Roque, Bauan, Batangas','Batangas','National Highway, San Roque, Bauan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(641,'Calatagan-Lian Highway, Binubusan, Lian, Batangas','Batangas','Calatagan-Lian Highway, Binubusan, Lian, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(642,'J.p. Rizal St., San Diego, Lian, Batangas','Batangas','J.p. Rizal St., San Diego, Lian, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(643,'National Rd., Labac, Cuenca, Batangas','Batangas','National Rd., Labac, Cuenca, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(644,'Makalintal Ave., Sambat, San Pascual, Batangas','Batangas','Makalintal Ave., Sambat, San Pascual, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(645,'National Highway, J.P. Laurel St., Bungahan, Lian, Batangas','Batangas','National Highway, J.P. Laurel St., Bungahan, Lian, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(646,'Palico - Balayan - Batangas Rd., Muzon, San Luis, Batangas','Batangas','Palico - Balayan - Batangas Rd., Muzon, San Luis, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(647,'J.j.zobel St., Barangay 2 (Pob.), Calatagan, Batangas','Batangas','J.j.zobel St., Barangay 2 (Pob.), Calatagan, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(648,'Taal - San Luis Rd., Butong, Taal, Batangas','Batangas','Taal - San Luis Rd., Butong, Taal, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(649,'National Roadvinsons Ave., Barangay Iv (Pob.), Daet , Camarines Norte','Camarines Norte','National Roadvinsons Ave., Barangay Iv (Pob.), Daet , Camarines Norte','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(650,'Vinzons Ave., Barangay Ii (Pob.), Vinzons, Camarines Norte','Camarines Norte','Vinzons Ave., Barangay Ii (Pob.), Vinzons, Camarines Norte','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(651,'National Road, Poblacion Norte, Paracale, Camarines Norte','Camarines Norte','National Road, Poblacion Norte, Paracale, Camarines Norte','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(652,'Regino Dias St. National Road, Santa Elena (Pob.), Santa Elena, Camarines Norte','Camarines Norte','Regino Dias St. National Road, Santa Elena (Pob.), Santa Elena, Camarines Norte','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(653,'National Road Pimentel Ave., Barangay Vi (Pob.), Daet , Camarines Norte','Camarines Norte','National Road Pimentel Ave., Barangay Vi (Pob.), Daet , Camarines Norte','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(654,'Roxas Ave. Diversion Rd. Pan Philippine Highway, Tabuco, City Of Naga, Camarines Sur','Camarines Sur','Roxas Ave. Diversion Rd. Pan Philippine Highway, Tabuco, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(655,'Roxas Ave. Corner Ninoy And Cory Aquino, Triangulo, City Of Naga, Camarines Sur','Camarines Sur','Roxas Ave. Corner Ninoy And Cory Aquino, Triangulo, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(656,'Almeda Highwa , Concepcion Pequena, City Of Naga, Camarines Sur','Camarines Sur','Almeda Highwa , Concepcion Pequena, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(657,'National Road, Tara, Sipocot, Camarines Sur','Camarines Sur','National Road, Tara, Sipocot, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(658,'Panganiban Street, Lerma, City Of Naga, Camarines Sur','Camarines Sur','Panganiban Street, Lerma, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(659,'Magsaysay Ave., Concepcion Pequena, City Of Naga, Camarines Sur','Camarines Sur','Magsaysay Ave., Concepcion Pequena, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(660,'National Road, Concepcion Pequena, City Of Naga, Camarines Sur','Camarines Sur','National Road, Concepcion Pequena, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(661,'Panganiban Drive, Tinago, City Of Naga, Camarines Sur','Camarines Sur','Panganiban Drive, Tinago, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(662,'Maharlika Highway, Tambo, Pamplona, Camarines Sur','Camarines Sur','Maharlika Highway, Tambo, Pamplona, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(663,'Zone 6 Maharlika Highway, Del Rosario, City Of Naga, Camarines Sur','Camarines Sur','Zone 6 Maharlika Highway, Del Rosario, City Of Naga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(664,'National Road, San Agustin, Pili , Camarines Sur','Camarines Sur','National Road, San Agustin, Pili , Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(665,'Zone 3 National Highway Cor. Pili Diversion Rd., San Agustin, Pili , Camarines Sur','Camarines Sur','Zone 3 National Highway Cor. Pili Diversion Rd., San Agustin, Pili , Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(666,'Guevarra St., San Francisco (Pob.), City Of Iriga, Camarines Sur','Camarines Sur','Guevarra St., San Francisco (Pob.), City Of Iriga, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(667,'National Road, Bagumbayan Peque??O (Pob.), Goa, Camarines Sur','Camarines Sur','National Road, Bagumbayan Peque??O (Pob.), Goa, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(668,'National Road, Talojongon, Tigaon, Camarines Sur','Camarines Sur','National Road, Talojongon, Tigaon, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(669,'Sagnay Provincial Rd., Mabca, Sag??Ay, Camarines Sur','Camarines Sur','Sagnay Provincial Rd., Mabca, Sag??Ay, Camarines Sur','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(670,'Binakayan, Covelandia Rd., Kawit, Cavite','Cavite','Binakayan, Covelandia Rd., Kawit, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(671,'Smypc E. Aguinaldo Highway, Anabu Ii-F, City Of Imus, Cavite','Cavite','Smypc E. Aguinaldo Highway, Anabu Ii-F, City Of Imus, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(672,'L4967B Molino Blvd., Ligas Ii, Bacoor City, Cavite','Cavite','L4967B Molino Blvd., Ligas Ii, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(673,'KM 17 Aguinaldo Highway, Palico Iv, City Of Imus, Cavite','Cavite','KM 17 Aguinaldo Highway, Palico Iv, City Of Imus, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(674,'KM. 16 Aguinaldo Highway, Niog I, Bacoor City, Cavite','Cavite','KM. 16 Aguinaldo Highway, Niog I, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(675,'Evangelista St., Kaingin (Pob.), Bacoor City, Cavite','Cavite','Evangelista St., Kaingin (Pob.), Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(676,'Cor. Pedro Reyes St., Alapan Ii-B, City Of Imus, Cavite','Cavite','Cor. Pedro Reyes St., Alapan Ii-B, City Of Imus, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(677,'Daang Hari Road, Pasong Buaya I, City Of Imus, Cavite','Cavite','Daang Hari Road, Pasong Buaya I, City Of Imus, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(678,'Tejero-Bacao Diversion Rd., Tejero, City Of General Trias, Cavite','Cavite','Tejero-Bacao Diversion Rd., Tejero, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(679,'KM 23 Aguinaldo Highway Bucal, Sampaloc Ii, City Of Dasmari??As, Cavite','Cavite','KM 23 Aguinaldo Highway Bucal, Sampaloc Ii, City Of Dasmari??As, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(680,'Aguinaldo Highway, San Agustin Ii, City Of Dasmari??As, Cavite','Cavite','Aguinaldo Highway, San Agustin Ii, City Of Dasmari??As, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(681,'Molino Boulevard, Molino Ii, Bacoor City, Cavite','Cavite','Molino Boulevard, Molino Ii, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(682,'Daang Hari Rd., Molino Iv, Bacoor City, Cavite','Cavite','Daang Hari Rd., Molino Iv, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(683,'Buhay Na Tubig St., Buhay Na Tubig, City Of Imus, Cavite','Cavite','Buhay Na Tubig St., Buhay Na Tubig, City Of Imus, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(684,'Aguinaldo Highway Panapaan, P.f. Espiritu I, City Of Bacoor, Cavite','Cavite','Aguinaldo Highway Panapaan, P.f. Espiritu I, City Of Bacoor, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(685,'National Road Las Pi??As Bound, Zapote V, Bacoor City, Cavite','Cavite','National Road Las Pi??As Bound, Zapote V, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(686,'P Burgos Ave. Caridad, Barangay 34, City Of Cavite, Cavite','Cavite','P Burgos Ave. Caridad, Barangay 34, City Of Cavite, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(687,'New Diversion Rd., Magdalo, Kawit, Cavite','Cavite','New Diversion Rd., Magdalo, Kawit, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(688,'New Bypass Road, Bacao Ii, City Of General Trias, Cavite','Cavite','New Bypass Road, Bacao Ii, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(689,'Kalayaan Road, San Sebastian, Kawit, Cavite','Cavite','Kalayaan Road, San Sebastian, Kawit, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(690,'Gen. Trias Drive, Tejeros Convention, Rosario, Cavite','Cavite','Gen. Trias Drive, Tejeros Convention, Rosario, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(691,'Tirona Highway, Habay Ii, Bacoor City, Cavite','Cavite','Tirona Highway, Habay Ii, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(692,'Centennial Rd., Gahak, Kawit, Cavite','Cavite','Centennial Rd., Gahak, Kawit, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(693,'Marcella St., Salcedo Ii, Noveleta, Cavite','Cavite','Marcella St., Salcedo Ii, Noveleta, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(694,'Molino Rd., Molino Iii, Bacoor City, Cavite','Cavite','Molino Rd., Molino Iii, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(695,'Manila Cavite Dahalican, Barangay 8, City Of Cavite, Cavite','Cavite','Manila Cavite Dahalican, Barangay 8, City Of Cavite, Cavite','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(696,'Aguinaldo Highway, Zone I-B, City Of Dasmari??As, Cavite','Cavite','Aguinaldo Highway, Zone I-B, City Of Dasmari??As, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(697,'Molino Paliparan Rd., Molino Iv, Bacoor City, Cavite','Cavite','Molino Paliparan Rd., Molino Iv, Bacoor City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(698,'Trece Tanza Rd., De Ocampo, City Of Trece Martires , Cavite','Cavite','Trece Tanza Rd., De Ocampo, City Of Trece Martires , Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(699,'Governor\'s Drive Cor. Dasmari??As , San Francisco, City Of General Trias, Cavite','Cavite','Governor\'s Drive Cor. Dasmari??As , San Francisco, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(700,'Governor\'s Drive, Cabilang Baybay, Carmona, Cavite','Cavite','Governor\'s Drive, Cabilang Baybay, Carmona, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(701,'F-1 A. Soriano Highway, Daang Amaya Iii, Tanza, Cavite','Cavite','F-1 A. Soriano Highway, Daang Amaya Iii, Tanza, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(702,'A. Soriano Highway Be Sampaguita St., Daang Amaya Iii, Tanza, Cavite','Cavite','A. Soriano Highway Be Sampaguita St., Daang Amaya Iii, Tanza, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(703,'Bi??An-Carmona Rd., Maduya, Carmona, Cavite','Cavite','Bi??An-Carmona Rd., Maduya, Carmona, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(704,'Jm Loyola St., Barangay 8 (Pob.), Carmona, Cavite','Cavite','Jm Loyola St., Barangay 8 (Pob.), Carmona, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(705,'Governor\'s Drive Cor. Arnaldo Highway, San Francisco, City Of General Trias, Cavite','Cavite','Governor\'s Drive Cor. Arnaldo Highway, San Francisco, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(706,'Mancilla Property Gov. Ferrer Drive, Manggahan, City Of General Trias, Cavite','Cavite','Mancilla Property Gov. Ferrer Drive, Manggahan, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(707,'Munting Ilog St., Iba, Silang, Cavite','Cavite','Munting Ilog St., Iba, Silang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(708,'Gorvernor\'s Drive, Mabuhay, Carmona, Cavite','Cavite','Gorvernor\'s Drive, Mabuhay, Carmona, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(709,'Aguinaldo Highway, Sabutan, Silang, Cavite','Cavite','Aguinaldo Highway, Sabutan, Silang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(710,'Gov. Drive Cor. Congressional Ave., Ramon Cruz, Gen. Mariano Alvarez, Cavite','Cavite','Gov. Drive Cor. Congressional Ave., Ramon Cruz, Gen. Mariano Alvarez, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(711,'Aguinaldo Highway, Lalaan I, Silang, Cavite','Cavite','Aguinaldo Highway, Lalaan I, Silang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(712,'Governor\'s Drive, Paliparan I, City Of Dasmari??As, Cavite','Cavite','Governor\'s Drive, Paliparan I, City Of Dasmari??As, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(713,'P3 Brookside Lane, San Francisco, City Of General Trias, Cavite','Cavite','P3 Brookside Lane, San Francisco, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(714,'Arnaldo Highway, Santiago, City Of General Trias, Cavite','Cavite','Arnaldo Highway, Santiago, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(715,'Arnaldo Highway, Pasong Camachile Ii, City Of General Trias, Cavite','Cavite','Arnaldo Highway, Pasong Camachile Ii, City Of General Trias, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(716,'KM 29 South Luzon Expressway, San Antonio, City Of San Pedro, Laguna','Laguna','KM 29 South Luzon Expressway, San Antonio, City Of San Pedro, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(717,'Gov. Drive Dasmarinas Cavite','Gov. Drive Dasmarinas Cavite','Gov. Drive Dasmarinas Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(718,'Purok 3 Brgy. Pangil , Banaybanay, Amadeo, Cavite','Cavite','Purok 3 Brgy. Pangil , Banaybanay, Amadeo, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(719,'KM 668 Burgos Cor. De Ocampo, Barangay 4 (Pob.), Indang, Cavite','Cavite','KM 668 Burgos Cor. De Ocampo, Barangay 4 (Pob.), Indang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(720,'North Bound Smc Training Center, Kaylaway, Nasugbu, Batangas','Batangas','North Bound Smc Training Center, Kaylaway, Nasugbu, Batangas','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(721,'National Road, Alulod, Indang, Cavite','Cavite','National Road, Alulod, Indang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(722,'Trece Indang Rd., Inocencio, City Of Trece Martires , Cavite','Cavite','Trece Indang Rd., Inocencio, City Of Trece Martires , Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(723,'Purok 3, Alulod, Indang, Cavite','Cavite','Purok 3, Alulod, Indang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(724,'Aguinaldo Highway, Mendez Crossing East, City Of Tagaytay, Cavite','Cavite','Aguinaldo Highway, Mendez Crossing East, City Of Tagaytay, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(725,'Crisanto M. De Los Reyes Ave., Banaybanay, Amadeo, Cavite','Cavite','Crisanto M. De Los Reyes Ave., Banaybanay, Amadeo, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(726,'Kaytitinga - Magallanes Rd., Barangay 5 (Pob.), Magallanes, Cavite','Cavite','Kaytitinga - Magallanes Rd., Barangay 5 (Pob.), Magallanes, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(727,'Governors Drive, Garita I A, Maragondon, Cavite','Cavite','Governors Drive, Garita I A, Maragondon, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(728,'Luksuhin Ibaba St., Luksuhin Ilaya, Alfonso, Cavite','Cavite','Luksuhin Ibaba St., Luksuhin Ilaya, Alfonso, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(729,'Crisanto M. Delos Reyes, Gen. Trias City, Cavite','Cavite','Crisanto M. Delos Reyes, Gen. Trias City, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(730,'Marahan-Alfonso Rd., Marahan Ii, Alfonso, Cavite','Cavite','Marahan-Alfonso Rd., Marahan Ii, Alfonso, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(731,'C Bayani St., Barangay Vii (Pob.), Amadeo, Cavite','Cavite','C Bayani St., Barangay Vii (Pob.), Amadeo, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(732,'Naic-Tanza By-Pass Rd., Ibayo Estacion, Naic, Cavite','Cavite','Naic-Tanza By-Pass Rd., Ibayo Estacion, Naic, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(733,'Aguinaldo Highway, Maharlika East, City Of Tagaytay, Cavite','Cavite','Aguinaldo Highway, Maharlika East, City Of Tagaytay, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(734,'Crisanto M. De Los Reyes Ave., Galicia Iii, Mendez, Cavite','Cavite','Crisanto M. De Los Reyes Ave., Galicia Iii, Mendez, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(735,'Tagaytay-Sta Rosa Road, Tartaria, Silang, Cavite','Cavite','Tagaytay-Sta Rosa Road, Tartaria, Silang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(736,'Tagaytay-Sta.rosa Rd. Cor. Tahibo St., Puting Kahoy, Silang, Cavite','Cavite','Tagaytay-Sta.rosa Rd. Cor. Tahibo St., Puting Kahoy, Silang, Cavite','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(737,'Malvar St. J.p. Laurel, Tubigan, City Of Bi??An, Laguna','Laguna','Malvar St. J.p. Laurel, Tubigan, City Of Bi??An, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(738,'Timbao Road, Timbao, City Of Bi??An, Laguna','Laguna','Timbao Road, Timbao, City Of Bi??An, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(739,'KM 44 Northbound, Mapagong, City Of Calamba, Laguna','Laguna','KM 44 Northbound, Mapagong, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(740,'KM 44 Southbound, Canlubang, City Of Calamba, Laguna','Laguna','KM 44 Southbound, Canlubang, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(741,'Purok 1 South City Drive, Zapote, City Of Bi??An, Laguna','Laguna','Purok 1 South City Drive, Zapote, City Of Bi??An, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(742,'Halang Rd. Southwoods City, San Francisco, City Of Bi??An, Laguna','Laguna','Halang Rd. Southwoods City, San Francisco, City Of Bi??An, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(743,'Walk 3 Eton City, Malitlit, City Of Santa Rosa, Laguna','Laguna','Walk 3 Eton City, Malitlit, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(744,'Sta. Rosa Tagaytay Rd. Laguna Bel Air, Don Jose, City Of Santa Rosa, Laguna','Laguna','Sta. Rosa Tagaytay Rd. Laguna Bel Air, Don Jose, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(745,'Santa Rosa -Tagaytay Rd., Pulong Santa Cruz, City Of Santa Rosa, Laguna','Laguna','Santa Rosa -Tagaytay Rd., Pulong Santa Cruz, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(746,'Smc Complex, Pulong Santa Cruz, City Of Santa Rosa, Laguna','Laguna','Smc Complex, Pulong Santa Cruz, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(747,'Tatlong Hari St., Aplaya, City Of Santa Rosa, Laguna','Laguna','Tatlong Hari St., Aplaya, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(748,'Pulo Diezmo Rd., Diezmo, Cabuyao City, Laguna','Laguna','Pulo Diezmo Rd., Diezmo, Cabuyao City, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(749,'C.a. Yulo Avenue Silangan Industrial Park Rd., Canlubang, City Of Calamba, Laguna','Laguna','C.a. Yulo Avenue Silangan Industrial Park Rd., Canlubang, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(750,'J.P. Rizal Ave., Sala, Cabuyao City, Laguna','Laguna','J.P. Rizal Ave., Sala, Cabuyao City, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(751,'National Highway, Landayan, City Of San Pedro, Laguna','Laguna','National Highway, Landayan, City Of San Pedro, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(752,'Old National Highway, Parian, City Of Calamba, Laguna','Laguna','Old National Highway, Parian, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(753,'Old National Highway, Real, City Of Calamba, Laguna','Laguna','Old National Highway, Real, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(754,'Don Bosco Ave., Mayapa, City Of Calamba, Laguna','Laguna','Don Bosco Ave., Mayapa, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(755,'Brgy. Bunggo, Calamba, Laguna','Laguna','Brgy. Bunggo, Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(756,'National Highway, Bucal, City Of Calamba, Laguna','Laguna','National Highway, Bucal, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(757,'Old National Highway, Santo Ni??O, City Of Bi??An, Laguna','Laguna','Old National Highway, Santo Ni??O, City Of Bi??An, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(758,'Old National Highway, De La Paz, City Of Bi??An, Laguna','Laguna','Old National Highway, De La Paz, City Of Bi??An, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(759,'Old National Highway, Tagapo, City Of Santa Rosa, Laguna','Laguna','Old National Highway, Tagapo, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(760,'Maharlika Highway, Turbina, City Of Calamba, Laguna','Laguna','Maharlika Highway, Turbina, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(761,'Chipeco Avenue, Barangay 3 (Pob.), City Of Calamba, Laguna','Laguna','Chipeco Avenue, Barangay 3 (Pob.), City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(762,'Maharlika Highway Northbound, Makiling, City Of Calamba, Laguna','Laguna','Maharlika Highway Northbound, Makiling, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(763,'National Highway, Real, City Of Calamba, Laguna','Laguna','National Highway, Real, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(764,'Manila S Rd., Banaybanay, Cabuyao City, Laguna','Laguna','Manila S Rd., Banaybanay, Cabuyao City, Laguna','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(765,'National Highway, Bagong Kalsada, City Of Calamba, Laguna','Laguna','National Highway, Bagong Kalsada, City Of Calamba, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(766,'F. Reyes St. Balibago Road, Balibago, City Of Santa Rosa, Laguna','Laguna','F. Reyes St. Balibago Road, Balibago, City Of Santa Rosa, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(767,'National Highway, Bulilan Norte (Pob.), Pila, Laguna','Laguna','National Highway, Bulilan Norte (Pob.), Pila, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(768,'Maharlika Highway, San Agustin (Pob.), Bay, Laguna','Laguna','Maharlika Highway, San Agustin (Pob.), Bay, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(769,'National Highway, Longos, Kalayaan, Laguna','Laguna','National Highway, Longos, Kalayaan, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(770,'National Highway, Maahas, Los Ba??Os, Laguna','Laguna','National Highway, Maahas, Los Ba??Os, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(771,'Cpdo Cmpd Up Los Ba??Os, Batong Malake, Los Ba??Os, Laguna','Laguna','Cpdo Cmpd Up Los Ba??Os, Batong Malake, Los Ba??Os, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(772,'National Highway, Masiit, Calauan, Laguna','Laguna','National Highway, Masiit, Calauan, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(773,'National Highway, Maytalang I, Lumban, Laguna','Laguna','National Highway, Maytalang I, Lumban, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(774,'National Highway, Masapang, Victoria, Laguna','Laguna','National Highway, Masapang, Victoria, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(775,'Siniloan-Famy-Real Infanta Rd., Mendiola, Siniloan, Laguna','Laguna','Siniloan-Famy-Real Infanta Rd., Mendiola, Siniloan, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(776,'National Hway Bi??An, Pagsawitan, Santa Cruz , Laguna','Laguna','National Hway Bi??An, Pagsawitan, Santa Cruz , Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(777,'National Road, Bagumbayan, Santa Cruz , Laguna','Laguna','National Road, Bagumbayan, Santa Cruz , Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(778,'G. Redor Street, G. Redor (Pob.), Siniloan, Laguna','Laguna','G. Redor Street, G. Redor (Pob.), Siniloan, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(779,'National Road Casa Real, Mabato-Azufre, Pangil, Laguna','Laguna','National Road Casa Real, Mabato-Azufre, Pangil, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(780,'Purok 3 Maharlika Highway, San Francisco, City Of San Pablo, Laguna','Laguna','Purok 3 Maharlika Highway, San Francisco, City Of San Pablo, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(781,'Maharlika Highway, San Roque, City Of San Pablo, Laguna','Laguna','Maharlika Highway, San Roque, City Of San Pablo, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(782,'Maharlika Highway Cor. Calabarzon , San Agustin, Alaminos, Laguna','Laguna','Maharlika Highway Cor. Calabarzon , San Agustin, Alaminos, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(783,'National Road, Malinao, Nagcarlan, Laguna','Laguna','National Road, Malinao, Nagcarlan, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(784,'National Highway 45 Bungkol St., Halayhayin, Magdalena, Laguna','Laguna','National Highway 45 Bungkol St., Halayhayin, Magdalena, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(785,'Maharlika Hi-Way, San Juan, Alaminos, Laguna','Laguna','Maharlika Hi-Way, San Juan, Alaminos, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(786,'Colago Avenue, San Roque, City Of San Pablo, Laguna','Laguna','Colago Avenue, San Roque, City Of San Pablo, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(787,'Rizal Ave., Bagong Pook Vi-C (Pob.), City Of San Pablo, Laguna','Laguna','Rizal Ave., Bagong Pook Vi-C (Pob.), City Of San Pablo, Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(788,'Kasilang St., Mataas Na Bayan (Pob.), Boac , Marinduque','Marinduque','Kasilang St., Mataas Na Bayan (Pob.), Boac , Marinduque','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(789,'Quezon St., Anapog-Sibucao, Mogpog, Marinduque','Marinduque','Quezon St., Anapog-Sibucao, Mogpog, Marinduque','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(790,'National Hiway, Katipunan, Placer, Masbate','Masbate','National Hiway, Katipunan, Placer, Masbate','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(791,'National Rd., Tugbo, City Of Masbate , Masbate','Masbate','National Rd., Tugbo, City Of Masbate , Masbate','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(792,'National Road, Poblacion, Balud, Masbate','Masbate','National Road, Poblacion, Balud, Masbate','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(793,'Governor Ignacio St., Camilmil, City Of Calapan , Oriental Mindoro','Oriental Mindoro','Governor Ignacio St., Camilmil, City Of Calapan , Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(794,'Nautical Highway, Santa Isabel, City Of Calapan , Oriental Mindoro','Oriental Mindoro','Nautical Highway, Santa Isabel, City Of Calapan , Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(795,'Quezon Drive , Calero (Pob.), City Of Calapan , Oriental Mindoro','Oriental Mindoro','Quezon Drive , Calero (Pob.), City Of Calapan , Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(796,'Western Nautical Highway, Malaya, Naujan, Oriental Mindoro','Oriental Mindoro','Western Nautical Highway, Malaya, Naujan, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(797,'Strong Republic Nautical Highway , Barcenaga, Naujan, Oriental Mindoro','Oriental Mindoro','Strong Republic Nautical Highway , Barcenaga, Naujan, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(798,'National Road, New Dagupan, Calintaan, Occidental Mindoro','Occidental Mindoro','National Road, New Dagupan, Calintaan, Occidental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(799,'Sitio Crossing, Poblacion, San Teodoro, Oriental Mindoro','Oriental Mindoro','Sitio Crossing, Poblacion, San Teodoro, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(800,'Western Nautical Highway, Poblacion, Baco, Oriental Mindoro','Oriental Mindoro','Western Nautical Highway, Poblacion, Baco, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(801,'J.p. Rizal St., San Vicente South (Pob.), City Of Calapan , Oriental Mindoro','Oriental Mindoro','J.p. Rizal St., San Vicente South (Pob.), City Of Calapan , Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(802,'National Road, Bgy. 3, Poblacion 3, Mamburao , Occidental Mindoro','Occidental Mindoro','National Road, Bgy. 3, Poblacion 3, Mamburao , Occidental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(803,'National Road Hondura St., Poblacion, Puerto Galera, Oriental Mindoro','Oriental Mindoro','National Road Hondura St., Poblacion, Puerto Galera, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(804,'Western Nautical Hiway Manila North Road, San Isidro, Puerto Galera, Oriental Mindoro','Oriental Mindoro','Western Nautical Hiway Manila North Road, San Isidro, Puerto Galera, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(805,'National Highway, Maligaya (Pob.), Gloria, Oriental Mindoro','Oriental Mindoro','National Highway, Maligaya (Pob.), Gloria, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(806,'Naujan Rd., Santiago, Naujan, Oriental Mindoro','Oriental Mindoro','Naujan Rd., Santiago, Naujan, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(807,'Strong Republic Nautical Highway, Palayan, Pinamalayan, Oriental Mindoro','Oriental Mindoro','Strong Republic Nautical Highway, Palayan, Pinamalayan, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(808,'Puerto Princesa South Road, Santa Monica, City Of Puerto Princesa , Palawan','Palawan','Puerto Princesa South Road, Santa Monica, City Of Puerto Princesa , Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(809,'Purok United Homeowners, Tiniguiban, City Of Puerto Princesa , Palawan','Palawan','Purok United Homeowners, Tiniguiban, City Of Puerto Princesa , Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(810,'National Rd., Barangay Vi (Pob.), Coron, Palawan','Palawan','National Rd., Barangay Vi (Pob.), Coron, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(811,'Taytay-El Nido National Highway, Villa Libertad, El Nido, Palawan','Palawan','Taytay-El Nido National Highway, Villa Libertad, El Nido, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(812,'Natiol Road, San Pedro, City Of Puerto Princesa , Palawan','Palawan','Natiol Road, San Pedro, City Of Puerto Princesa , Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(813,'Rizal Avenue, Bancao-Bancao, City Of Puerto Princesa , Palawan','Palawan','Rizal Avenue, Bancao-Bancao, City Of Puerto Princesa , Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(814,'Malvar St., Matahimik (Pob.), City Of Puerto Princesa , Palawan','Palawan','Malvar St., Matahimik (Pob.), City Of Puerto Princesa , Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(815,'Puerto Princesa North Road, Barangay Ii (Pob.), Roxas, Palawan','Palawan','Puerto Princesa North Road, Barangay Ii (Pob.), Roxas, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(816,'Taytay Rd., Poblacion, Taytay, Palawan','Palawan','Taytay Rd., Poblacion, Taytay, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(817,'National Highway, Sandoval, Narra, Palawan','Palawan','National Highway, Sandoval, Narra, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(818,'National Hi-Way, Marangas (Pob.), Bataraza, Palawan','Palawan','National Hi-Way, Marangas (Pob.), Bataraza, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(819,'National Highway, Narra (Pob.), Narra, Palawan','Palawan','National Highway, Narra (Pob.), Narra, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(820,'National Highway, Alfonso Xiii (Pob.), Quezon, Palawan','Palawan','National Highway, Alfonso Xiii (Pob.), Quezon, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(821,'Quezon - Punta Baja Rd., Alfonso Xiii (Pob.), Quezon, Palawan','Palawan','Quezon - Punta Baja Rd., Alfonso Xiii (Pob.), Quezon, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(822,'Poblacion - Long Beach Rd., New Agutaya, San Vicente, Palawan','Palawan','Poblacion - Long Beach Rd., New Agutaya, San Vicente, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(823,'Sandoval Street, Barangay Ii (Pob.), Roxas, Palawan','Palawan','Sandoval Street, Barangay Ii (Pob.), Roxas, Palawan','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(824,'National Road, Batican, Infanta, Quezon','Quezon','National Road, Batican, Infanta, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(825,'Ungos-Cawayan Rd., Ungos, Real, Quezon','Quezon','Ungos-Cawayan Rd., Ungos, Real, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:17'),(826,'Lucban - Tayabas Road, Tinamnan, Lucban, Quezon','Quezon','Lucban - Tayabas Road, Tinamnan, Lucban, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(827,'Maharlika Road, Calumpang, City Of Tayabas, Quezon','Quezon','Maharlika Road, Calumpang, City Of Tayabas, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(828,'Pan Philippine Highway, Mangilag Sur, Candelaria, Quezon','Quezon','Pan Philippine Highway, Mangilag Sur, Candelaria, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(829,'National Road, Lalig, Tiaong, Quezon','Quezon','National Road, Lalig, Tiaong, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(830,'National Highway, Bukal Sur, Candelaria, Quezon','Quezon','National Highway, Bukal Sur, Candelaria, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(831,'National Highway, Abang, Lucban, Quezon','Quezon','National Highway, Abang, Lucban, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(832,'An Philippine Highway, Talisay, Tiaong, Quezon','Quezon','An Philippine Highway, Talisay, Tiaong, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(833,'National Road, Paiisa, Tiaong, Quezon','Quezon','National Road, Paiisa, Tiaong, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(834,'Pan Philippine Highway Gov. Rodriguez St., Barangay 4 (Pob.), Sariaya, Quezon','Quezon','Pan Philippine Highway Gov. Rodriguez St., Barangay 4 (Pob.), Sariaya, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:17'),(835,'Maharlika Highway, Calantipayan, Lopez, Quezon','Quezon','Maharlika Highway, Calantipayan, Lopez, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(836,'Maharlika Highway, Santa Maria, Calauag, Quezon','Quezon','Maharlika Highway, Santa Maria, Calauag, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(837,'Gulang-Gulang Avenue, Gulang-Gulang, City Of Lucena , Quezon','Quezon','Gulang-Gulang Avenue, Gulang-Gulang, City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(838,'Merchan Cor. Juarez St., Barangay 6 (Pob.), City Of Lucena , Quezon','Quezon','Merchan Cor. Juarez St., Barangay 6 (Pob.), City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(839,'Old Manila South Road, Ibabang Dupay, City Of Lucena , Quezon','Quezon','Old Manila South Road, Ibabang Dupay, City Of Lucena , Quezon','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(840,'Maharlika Highway, Brgy. Panikihan, Gumaca, Quezon Province','Quezon Province','Maharlika Highway, Brgy. Panikihan, Gumaca, Quezon Province','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(841,'Tayabas-Mauban Roa , Polo, Mauban, Quezon','Quezon','Tayabas-Mauban Roa , Polo, Mauban, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(842,'Quezon Avenue. Cor. Zamora St., Barangay 7 (Pob.), City Of Lucena , Quezon','Quezon','Quezon Avenue. Cor. Zamora St., Barangay 7 (Pob.), City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(843,'Ml Tagarao Ave., Ilayang Iyam, City Of Lucena , Quezon','Quezon','Ml Tagarao Ave., Ilayang Iyam, City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(844,'Maharlika Highway, Butaguin, Gumaca, Quezon','Quezon','Maharlika Highway, Butaguin, Gumaca, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(845,'Lucena-Tayabas Road, Gulang-Gulang, City Of Lucena , Quezon','Quezon','Lucena-Tayabas Road, Gulang-Gulang, City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(846,'Maharlika Highway, Rosario, Gumaca, Quezon','Quezon','Maharlika Highway, Rosario, Gumaca, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(847,'Pfda, Dalahican, City Of Lucena , Quezon','Quezon','Pfda, Dalahican, City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(848,'Maharlika Highway, Bukal, Pagbilao, Quezon','Quezon','Maharlika Highway, Bukal, Pagbilao, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(849,'Pan Philippine Highway, Mayao Silangan, City Of Lucena , Quezon','Quezon','Pan Philippine Highway, Mayao Silangan, City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(850,'Gomez Ext., Ibabang Dupay, City Of Lucena , Quezon','Quezon','Gomez Ext., Ibabang Dupay, City Of Lucena , Quezon','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(851,'Rolando R. Andaya Highway, Santa Cecilia, Tagkawayan, Quezon','Quezon','Rolando R. Andaya Highway, Santa Cecilia, Tagkawayan, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(852,'Purok Centro, Dinahican, Infanta, Quezon','Quezon','Purok Centro, Dinahican, Infanta, Quezon','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:03'),(853,'Maharlika Highway, San Pedro, Irosin, Sorsogon','Sorsogon','Maharlika Highway, San Pedro, Irosin, Sorsogon','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(854,'Rizal St.burabod, Talisay (Pob.), City Of Sorsogon , Sorsogon','Sorsogon','Rizal St.burabod, Talisay (Pob.), City Of Sorsogon , Sorsogon','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(855,'Sorsogon Diversion Road, Cabid-An, City Of Sorsogon , Sorsogon','Sorsogon','Sorsogon Diversion Road, Cabid-An, City Of Sorsogon , Sorsogon','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(856,'Caticlan, Malay, Aklan','Aklan','Caticlan, Malay, Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(857,'L.magnabijon St., Poblacion, Numancia, Aklan','Aklan','L.magnabijon St., Poblacion, Numancia, Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(858,'Osme??A Avenue, Estancia, Kalibo , Aklan','Aklan','Osme??A Avenue, Estancia, Kalibo , Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(859,'Western Nautical Highway, Laguinbanua East, Numancia, Aklan','Aklan','Western Nautical Highway, Laguinbanua East, Numancia, Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(860,'National Highway, Poblacion, Ibajay, Aklan','Aklan','National Highway, Poblacion, Ibajay, Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(861,'Sitio Cagman Highway, Manoc-Manoc, Malay, Aklan','Aklan','Sitio Cagman Highway, Manoc-Manoc, Malay, Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(862,'Roxas Ave. Corner Mabini St., Poblacion, Kalibo , Aklan','Aklan','Roxas Ave. Corner Mabini St., Poblacion, Kalibo , Aklan','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(863,'Tobias - Fornier - Anini Y Rd., Villavert-Jimenez, Hamtic, Antique','Antique','Tobias - Fornier - Anini Y Rd., Villavert-Jimenez, Hamtic, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(864,'National Highway, Caridad, Hamtic, Antique','Antique','National Highway, Caridad, Hamtic, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(865,'National Highway, Importante, Tibiao, Antique','Antique','National Highway, Importante, Tibiao, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(866,'Real St., Padang, Patnongon, Antique','Antique','Real St., Padang, Patnongon, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(867,'National Highway Patnongon Rd., Magsaysay, Patnongon, Antique','Antique','National Highway Patnongon Rd., Magsaysay, Patnongon, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(868,'Provincial Road Rizal St., Atabay, Tobias Fornier, Antique','Antique','Provincial Road Rizal St., Atabay, Tobias Fornier, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(869,'National Road, Ilaures, Bugasong, Antique','Antique','National Road, Ilaures, Bugasong, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(870,'Pc Barracks Rd., Santa Fe, Pandan, Antique','Antique','Pc Barracks Rd., Santa Fe, Pandan, Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(871,'Corner T.a Fornier And San Antonio Sts., Barangay 1 (Pob.), San Jose , Antique','Antique','Corner T.a Fornier And San Antonio Sts., Barangay 1 (Pob.), San Jose , Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(872,'General Fullon Street, Barangay 8 (Pob.), San Jose , Antique','Antique','General Fullon Street, Barangay 8 (Pob.), San Jose , Antique','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(873,'National Highway, San Roque (Pob.), Biliran, Biliran','Biliran','National Highway, San Roque (Pob.), Biliran, Biliran','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(874,'San Isidro Street, Culaba Central (Pob.), Culaba, Biliran','Biliran','San Isidro Street, Culaba Central (Pob.), Culaba, Biliran','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(875,'J.a. Clarin St., Dampas, City Of Tagbilaran , Bohol','Bohol','J.a. Clarin St., Dampas, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(876,'Son-Oc St. , Poblacion, Ubay, Bohol','Bohol','Son-Oc St. , Poblacion, Ubay, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(877,'Cpg Avenue, Cogon, City Of Tagbilaran , Bohol','Bohol','Cpg Avenue, Cogon, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(878,'Tagbilaran North Road, Lucob, Calape, Bohol','Bohol','Tagbilaran North Road, Lucob, Calape, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(879,'Provincial Highway Cor.t.l. Rulida St., Poblacion, Catigbian, Bohol','Bohol','Provincial Highway Cor.t.l. Rulida St., Poblacion, Catigbian, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(880,'Cpg North Avenue, Ubujan, City Of Tagbilaran , Bohol','Bohol','Cpg North Avenue, Ubujan, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(881,'Provincial Highway Cor. Salazar St., Moto Norte (Pob.), Loon, Bohol','Bohol','Provincial Highway Cor. Salazar St., Moto Norte (Pob.), Loon, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(882,'Bohol-North Circumferential Road, Potohan, Tubigon, Bohol','Bohol','Bohol-North Circumferential Road, Potohan, Tubigon, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(883,'Ma. Clara Street, Cogon, City Of Tagbilaran , Bohol','Bohol','Ma. Clara Street, Cogon, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(884,'J.s. Torralba Street, Poblacion Ii, City Of Tagbilaran , Bohol','Bohol','J.s. Torralba Street, Poblacion Ii, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(885,'National Highway, Del Carmen Sur (Pob.), Balilihan, Bohol','Bohol','National Highway, Del Carmen Sur (Pob.), Balilihan, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(886,'National Highway, Taguihon, Baclayon, Bohol','Bohol','National Highway, Taguihon, Baclayon, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(887,'Tagbilaran North Road C.p. Garcia Ave., Booy, City Of Tagbilaran , Bohol','Bohol','Tagbilaran North Road C.p. Garcia Ave., Booy, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(888,'New Tagbilaran Integrated Bus Terminal J.a. Clarin, Dampas, City Of Tagbilaran , Bohol','Bohol','New Tagbilaran Integrated Bus Terminal J.a. Clarin, Dampas, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(889,'Brunei St., Dao, City Of Tagbilaran , Bohol','Bohol','Brunei St., Dao, City Of Tagbilaran , Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(890,'National Highway J.a. Clarin St., Sambog, Corella, Bohol','Bohol','National Highway J.a. Clarin St., Sambog, Corella, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(891,'Cpg Avenue, Taguihon, Baclayon, Bohol','Bohol','Cpg Avenue, Taguihon, Baclayon, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(892,'National Highway, Desamparados (Pob.), Calape, Bohol','Bohol','National Highway, Desamparados (Pob.), Calape, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(893,'National Highway Tagbilaran East Rd., Taongon Cabatuan, Dimiao, Bohol','Bohol','National Highway Tagbilaran East Rd., Taongon Cabatuan, Dimiao, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(894,'National Highway Corella Balilihan Road, Poblacion, Corella, Bohol','Bohol','National Highway Corella Balilihan Road, Poblacion, Corella, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(895,'Provincial Highway, Cabulihan, Tubigon, Bohol','Bohol','Provincial Highway, Cabulihan, Tubigon, Bohol','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(896,'Calle Revolucion St., Poblacion Ilaya, Panay, Capiz','Capiz','Calle Revolucion St., Poblacion Ilaya, Panay, Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(897,'National Road, Poblacion, Jamindan, Capiz','Capiz','National Road, Poblacion, Jamindan, Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(898,'Roxas Avenue, Poblacion Ix, City Of Roxas , Capiz','Capiz','Roxas Avenue, Poblacion Ix, City Of Roxas , Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(899,'Hughes Corner Burgos Street, Tanque, City Of Roxas , Capiz','Capiz','Hughes Corner Burgos Street, Tanque, City Of Roxas , Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(900,'Sitio Pook, Culasi, City Of Roxas , Capiz','Capiz','Sitio Pook, Culasi, City Of Roxas , Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(901,'KM 1 National Road, Lawa-An, City Of Roxas , Capiz','Capiz','KM 1 National Road, Lawa-An, City Of Roxas , Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(902,'Corner M.h Del Pilar & M.l. Roxas Sts., Poblacion Norte, Ivisan, Capiz','Capiz','Corner M.h Del Pilar & M.l. Roxas Sts., Poblacion Norte, Ivisan, Capiz','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(903,'Provincial Highway Pooc, Poblacion, Santa Fe, Cebu','Cebu','Provincial Highway Pooc, Poblacion, Santa Fe, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(904,'National Road, Binaobao (Pob.), Bantayan, Cebu','Cebu','National Road, Binaobao (Pob.), Bantayan, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(905,'National Highway Osme??Ia St., Poblacion, Daanbantayan, Cebu','Cebu','National Highway Osme??Ia St., Poblacion, Daanbantayan, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(906,'De La Vi??A St. Cor. New Bogo Mrk, Gairan, City Of Bogo, Cebu','Cebu','De La Vi??A St. Cor. New Bogo Mrk, Gairan, City Of Bogo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(907,'North Road, Labogon, City Of Mandaue, Cebu','Cebu','North Road, Labogon, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(908,'North Road, Basak, City Of Mandaue, Cebu','Cebu','North Road, Basak, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(909,'Cebu North Coastal Road, Pakna-An, City Of Mandaue, Cebu','Cebu','Cebu North Coastal Road, Pakna-An, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(910,'Aliwanay Rd., Santa Cruz-Santo Nino (Pob.), Balamban, Cebu','Cebu','Aliwanay Rd., Santa Cruz-Santo Nino (Pob.), Balamban, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(911,'Antonio Y De Pio National Highway, Buanoy, Balamban, Cebu','Cebu','Antonio Y De Pio National Highway, Buanoy, Balamban, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(912,'National Highway Sitio Fermina, Maya, Daanbantayan, Cebu','Cebu','National Highway Sitio Fermina, Maya, Daanbantayan, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(913,'Cebu North Hagnaya Wharf Rd., Polambato, City Of Bogo, Cebu','Cebu','Cebu North Hagnaya Wharf Rd., Polambato, City Of Bogo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(914,'National Road, Poblacion Occidental, Consolacion, Cebu','Cebu','National Road, Poblacion Occidental, Consolacion, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(915,'Consolacion-Tayud-Liloan Rd., Cansaga, Consolacion, Cebu','Cebu','Consolacion-Tayud-Liloan Rd., Cansaga, Consolacion, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(916,'Basak-Buaya St., Basak, City Of Lapu-Lapu, Cebu','Cebu','Basak-Buaya St., Basak, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(917,'Ouano Ave. North Reclamation Area, Tipolo, City Of Mandaue, Cebu','Cebu','Ouano Ave. North Reclamation Area, Tipolo, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(918,'National Hiway Ml Quezon Ave., Pusok, City Of Lapu-Lapu, Cebu','Cebu','National Hiway Ml Quezon Ave., Pusok, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(919,'M.v. Patalinghud Jr Ave., Basak, City Of Lapu-Lapu, Cebu','Cebu','M.v. Patalinghud Jr Ave., Basak, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(920,'M.v. Patalinghug Jr. Ave., Pajo, City Of Lapu-Lapu, Cebu','Cebu','M.v. Patalinghug Jr. Ave., Pajo, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(921,'A.s. Fortuna St., Bakilid, City Of Mandaue, Cebu','Cebu','A.s. Fortuna St., Bakilid, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(922,'Gov. M. Cuenco Street, Talamban, City Of Lapu-Lapu, Cebu','Cebu','Gov. M. Cuenco Street, Talamban, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(923,'Gov. M. Cuenco Ave. Borbajo St., Talamban, City Of Cebu , Cebu','Cebu','Gov. M. Cuenco Ave. Borbajo St., Talamban, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(924,'National Highway H. Abellana St., Canduman, City Of Mandaue, Cebu','Cebu','National Highway H. Abellana St., Canduman, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(925,'Mini Market, Dapitan, Cordova, Cebu','Cebu','Mini Market, Dapitan, Cordova, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(926,'M.l.quezon, Maguikay, City Of Mandaue, Cebu','Cebu','M.l.quezon, Maguikay, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(927,'Ml Quezon Street, Casuntingan, City Of Mandaue, Cebu','Cebu','Ml Quezon Street, Casuntingan, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(928,'M. L. Quezon National Highway, Pajo, City Of Lapu-Lapu, Cebu','Cebu','M. L. Quezon National Highway, Pajo, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(929,'S.osme??A Blvd. T. Padilla St., Tejero, City Of Cebu , Cebu','Cebu','S.osme??A Blvd. T. Padilla St., Tejero, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(930,'National Road Osme??A St., Looc, City Of Lapu-Lapu, Cebu','Cebu','National Road Osme??A St., Looc, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(931,'National Highway Sitio Hawayan Uno, Marigondon, City Of Lapu-Lapu, Cebu','Cebu','National Highway Sitio Hawayan Uno, Marigondon, City Of Lapu-Lapu, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(932,'G. Lopez Jaena Ave., Subangdaku, City Of Mandaue, Cebu','Cebu','G. Lopez Jaena Ave., Subangdaku, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(933,'G. Lopes Jaena Street, Tipolo, City Of Mandaue, Cebu','Cebu','G. Lopes Jaena Street, Tipolo, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(934,'National Highway, Tipolo, City Of Mandaue, Cebu','Cebu','National Highway, Tipolo, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(935,'Nbt Area M Logarta Ave., Subangdaku, City Of Mandaue, Cebu','Cebu','Nbt Area M Logarta Ave., Subangdaku, City Of Mandaue, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(936,'F. Cabahug St., Mabolo, City Of Cebu , Cebu','Cebu','F. Cabahug St., Mabolo, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(937,'Gov. M. Cuenco Avenue, Kasambagan, City Of Cebu , Cebu','Cebu','Gov. M. Cuenco Avenue, Kasambagan, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(938,'National Highway, Lawaan I, City Of Talisay, Cebu','Cebu','National Highway, Lawaan I, City Of Talisay, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(939,'South Road Properties Corner Laray, San Roque, City Of Talisay, Cebu','Cebu','South Road Properties Corner Laray, San Roque, City Of Talisay, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(940,'Escario St., Camputhaw (Pob.), City Of Cebu , Cebu','Cebu','Escario St., Camputhaw (Pob.), City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(941,'Vicente Rama Avenue, Calamba, City Of Cebu , Cebu','Cebu','Vicente Rama Avenue, Calamba, City Of Cebu , Cebu','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(942,'V. Rama Avenue, Guadalupe, City Of Cebu , Cebu','Cebu','V. Rama Avenue, Guadalupe, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(943,'Srp Entry Road, Mambaling, City Of Cebu , Cebu','Cebu','Srp Entry Road, Mambaling, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(944,'B Rodriguez S.t, Capitol Site (Pob.), City Of Cebu , Cebu','Cebu','B Rodriguez S.t, Capitol Site (Pob.), City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(945,'Southroad National Highway, Calajo-An, Minglanilla, Cebu','Cebu','Southroad National Highway, Calajo-An, Minglanilla, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(946,'Southroad National Highway, Poblacion Ward Iii, Minglanilla, Cebu','Cebu','Southroad National Highway, Poblacion Ward Iii, Minglanilla, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(947,'Cebu South Road, Tunghaan, Minglanilla, Cebu','Cebu','Cebu South Road, Tunghaan, Minglanilla, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(948,'Cebu South Rd., Tunghaan, Minglanilla, Cebu','Cebu','Cebu South Rd., Tunghaan, Minglanilla, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(949,'F.llamas St., Tisa, City Of Cebu , Cebu','Cebu','F.llamas St., Tisa, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(950,'Archbishop Reyes Ave. Juan Luna Cor., Luz, City Of Cebu , Cebu','Cebu','Archbishop Reyes Ave. Juan Luna Cor., Luz, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(951,'A. Apostol St., Tulay, Minglanilla, Cebu','Cebu','A. Apostol St., Tulay, Minglanilla, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(952,'Katipunan Street, Tisa, City Of Cebu , Cebu','Cebu','Katipunan Street, Tisa, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(953,'Southroad National Highway, Inayagan, City Of Naga, Cebu','Cebu','Southroad National Highway, Inayagan, City Of Naga, Cebu','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(954,'N. Bacalso Avenue, Labangon, City Of Cebu , Cebu','Cebu','N. Bacalso Avenue, Labangon, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(955,'Natalio B. Bacalso Avenue, Mambaling, City Of Cebu , Cebu','Cebu','Natalio B. Bacalso Avenue, Mambaling, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(956,'Candido Padilla Corner T. Abella St. , Taboan, Cebu City, Cebu','Cebu','Candido Padilla Corner T. Abella St. , Taboan, Cebu City, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(957,'N. Bacalso Ave. , Tabunoc, City Of Talisay, Cebu','Cebu','N. Bacalso Ave. , Tabunoc, City Of Talisay, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(958,'R. Duterte St., Guadalupe, City Of Cebu , Cebu','Cebu','R. Duterte St., Guadalupe, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(959,'N. Bacalso Ave., San Nicolas Central, City Of Cebu , Cebu','Cebu','N. Bacalso Ave., San Nicolas Central, City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(960,'Rafael C Rabaya St., Tabunoc, City Of Talisay, Cebu','Cebu','Rafael C Rabaya St., Tabunoc, City Of Talisay, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(961,'Legaspi St. Corner Jakosalem, Central (Pob.), City Of Cebu , Cebu','Cebu','Legaspi St. Corner Jakosalem, Central (Pob.), City Of Cebu , Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(962,'Salinas Dr., Apas, Cebu City, Cebu','Cebu','Salinas Dr., Apas, Cebu City, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(963,'National Highway, Bitoon, Dumanjug, Cebu','Cebu','National Highway, Bitoon, Dumanjug, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(964,'Provincial Highway, Poblacion, Ginatilan, Cebu','Cebu','Provincial Highway, Poblacion, Ginatilan, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(965,'Southroad National Hiway Patrocinio St., Poblacion, Boljoon, Cebu','Cebu','Southroad National Hiway Patrocinio St., Poblacion, Boljoon, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(966,'National Highway, Poblacion, Alcoy, Cebu','Cebu','National Highway, Poblacion, Alcoy, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(967,'Southroad National Highway, Poblacion, Santander, Cebu','Cebu','Southroad National Highway, Poblacion, Santander, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(968,'Southroad National Highway Sitio Pajo, Poblacion, Samboan, Cebu','Cebu','Southroad National Highway Sitio Pajo, Poblacion, Samboan, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(969,'Dr. Jose Rizal St., Poblacion, Carcar City, Cebu','Cebu','Dr. Jose Rizal St., Poblacion, Carcar City, Cebu','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(970,'Provincial Highway Sitio Latid, Poblacion, Pinamungajan, Cebu','Cebu','Provincial Highway Sitio Latid, Poblacion, Pinamungajan, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(971,'Provincial Highway Sitio Latid, Bato, City Of Toledo, Cebu','Cebu','Provincial Highway Sitio Latid, Bato, City Of Toledo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(972,'S. Osme??A St., Sangi, City Of Toledo, Cebu','Cebu','S. Osme??A St., Sangi, City Of Toledo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:47'),(973,'Cebu-Toledo Wharf Rd., Juan Climaco, Sr., City Of Toledo, Cebu','Cebu','Cebu-Toledo Wharf Rd., Juan Climaco, Sr., City Of Toledo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(974,'Diosdado Macapagal Highway, Luray Ii, City Of Toledo, Cebu','Cebu','Diosdado Macapagal Highway, Luray Ii, City Of Toledo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(975,'National Highway, Poblacion, City Of Toledo, Cebu','Cebu','National Highway, Poblacion, City Of Toledo, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(976,'National Highway, Poblacion East, Moalboal, Cebu','Cebu','National Highway, Poblacion East, Moalboal, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(977,'National Highway, Poblacion Central, Dumanjug, Cebu','Cebu','National Highway, Poblacion Central, Dumanjug, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(978,'Southroad National Highway, Tinaan, City Of Naga, Cebu','Cebu','Southroad National Highway, Tinaan, City Of Naga, Cebu','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(979,'National Highway Bo. Awayan, Valladolid, City Of Carcar, Cebu','Cebu','National Highway Bo. Awayan, Valladolid, City Of Carcar, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(980,'National Highway, Talo-Ot, Argao, Cebu','Cebu','National Highway, Talo-Ot, Argao, Cebu','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(981,'National Highway, Barangay 8 (Pob.), Salcedo, Eastern Samar','Eastern Samar','National Highway, Barangay 8 (Pob.), Salcedo, Eastern Samar','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(982,'National Highway Alibhon, San Miguel, Jordan , Guimaras','Guimaras','National Highway Alibhon, San Miguel, Jordan , Guimaras','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(983,'National Road Tupas St., Tan Pael, Tigbauan, Iloilo','Iloilo','National Road Tupas St., Tan Pael, Tigbauan, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(984,'National Highway, Igtuba, Miagao, Iloilo','Iloilo','National Highway, Igtuba, Miagao, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(985,'National Highway, Cabatac, Maasin, Iloilo','Iloilo','National Highway, Cabatac, Maasin, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(986,'Old Iloilo-Capiz Rd., Barangay Zone I (Pob.), Santa Barbara, Iloilo','Iloilo','Old Iloilo-Capiz Rd., Barangay Zone I (Pob.), Santa Barbara, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(987,'Panay News Compound, Mali-Ao, Pavia, Iloilo','Iloilo','Panay News Compound, Mali-Ao, Pavia, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(988,'Circumferential Rd. 1, Pandac, Pavia, Iloilo','Iloilo','Circumferential Rd. 1, Pandac, Pavia, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(989,'Iloilo City- Aleosan Rd., Guzman-Jesena, City Of Iloilo , Iloilo','Iloilo','Iloilo City- Aleosan Rd., Guzman-Jesena, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(990,'National Highway, Ungka Ii, Pavia, Iloilo','Iloilo','National Highway, Ungka Ii, Pavia, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(991,'National Highway Rizal Ilawod St., Zone Ix Pob., Cabatuan, Iloilo','Iloilo','National Highway Rizal Ilawod St., Zone Ix Pob., Cabatuan, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(992,'Old Iloilo-Capiz Rd., Ayaman, Cabatuan, Iloilo','Iloilo','Old Iloilo-Capiz Rd., Ayaman, Cabatuan, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(993,'Corner Luna & Huervana Sts., Luna, City Of Iloilo , Iloilo','Iloilo','Corner Luna & Huervana Sts., Luna, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(994,'M.h. Del Pilar St., Taal, City Of Iloilo , Iloilo','Iloilo','M.h. Del Pilar St., Taal, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(995,'National Highway Abeto St., Abeto Mirasol Taft South, City Of Iloilo , Iloilo','Iloilo','National Highway Abeto St., Abeto Mirasol Taft South, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(996,'Diversion Road Aquino Avenue, Buhang Taft North, City Of Iloilo , Iloilo','Iloilo','Diversion Road Aquino Avenue, Buhang Taft North, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(997,'R. Mapa St., Tabucan, City Of Iloilo , Iloilo','Iloilo','R. Mapa St., Tabucan, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(998,'Corner Sta Isabel & Lopez Jaena Sts., Arguelles, City Of Iloilo , Iloilo','Iloilo','Corner Sta Isabel & Lopez Jaena Sts., Arguelles, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(999,'Donato Pison Avenue, Tabucan, City Of Iloilo , Iloilo','Iloilo','Donato Pison Avenue, Tabucan, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1000,'West Diversion Road, Bolilao, City Of Iloilo , Iloilo','Iloilo','West Diversion Road, Bolilao, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1001,'Timawa Street, West Timawa, City Of Iloilo , Iloilo','Iloilo','Timawa Street, West Timawa, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1002,'National Highway Jc Zulueta St., Poblacion West, Oton, Iloilo','Iloilo','National Highway Jc Zulueta St., Poblacion West, Oton, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1003,'Luna St., Rizal, City Of Iloilo , Iloilo','Iloilo','Luna St., Rizal, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1004,'National Highway, Quintin Salas, City Of Iloilo , Iloilo','Iloilo','National Highway, Quintin Salas, City Of Iloilo , Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1005,'Corner Arimas - Melliza Sts., Jalaud Norte, Zarraga, Iloilo','Iloilo','Corner Arimas - Melliza Sts., Jalaud Norte, Zarraga, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1006,'National Higghway??, Burgos-Regidor (Pob.), Dumangas, Iloilo','Iloilo','National Higghway??, Burgos-Regidor (Pob.), Dumangas, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1007,'Barotac Nuevo - Dumangas Rd., Pd Monfort North, Dumangas, Iloilo','Iloilo','Barotac Nuevo - Dumangas Rd., Pd Monfort North, Dumangas, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1008,'Barotac Nuevo-Zarraga Rd., Ilaya Poblacion, Barotac Nuevo, Iloilo','Iloilo','Barotac Nuevo-Zarraga Rd., Ilaya Poblacion, Barotac Nuevo, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1009,'National Road, Tabucan, Barotac Nuevo, Iloilo','Iloilo','National Road, Tabucan, Barotac Nuevo, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1010,'National Road, Rumbang, Pototan, Iloilo','Iloilo','National Road, Rumbang, Pototan, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1011,'National Highway, Buntatala, Leganes, Iloilo','Iloilo','National Highway, Buntatala, Leganes, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1012,'National Highway, Poblacion Ilawod, Lambunao, Iloilo','Iloilo','National Highway, Poblacion Ilawod, Lambunao, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1013,'Don Victorino Salcedo Sts., Poblacion Market, Sara, Iloilo','Iloilo','Don Victorino Salcedo Sts., Poblacion Market, Sara, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1014,'Iloilo Radial Bypass Road 4, Buntatala, Leganes, Iloilo','Iloilo','Iloilo Radial Bypass Road 4, Buntatala, Leganes, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1015,'National Highway Gustilo St., Guihaman, Leganes, Iloilo','Iloilo','National Highway Gustilo St., Guihaman, Leganes, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1016,'F. Palmares Sr. St., Poblacion Ilawod, City Of Passi, Iloilo','Iloilo','F. Palmares Sr. St., Poblacion Ilawod, City Of Passi, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1017,'National Road, Macalbang, Concepcion, Iloilo','Iloilo','National Road, Macalbang, Concepcion, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1018,'E. Reyes Ave., Poblacion Zone Ii, Estancia, Iloilo','Iloilo','E. Reyes Ave., Poblacion Zone Ii, Estancia, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1019,'Coastal Road, Brgy. Camangay, Leganes, Iloilo','Iloilo','Coastal Road, Brgy. Camangay, Leganes, Iloilo','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1020,'Real St., Barangay 50, City Of Tacloban , Leyte','Leyte','Real St., Barangay 50, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1021,'Rizal Corner Avenida Veteranos, Barangay 43, City Of Tacloban , Leyte','Leyte','Rizal Corner Avenida Veteranos, Barangay 43, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1022,'Justice Romualdez St. Corner Paterno St., Barangay 25, City Of Tacloban , Leyte','Leyte','Justice Romualdez St. Corner Paterno St., Barangay 25, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1023,'National Highway Hollywood Subd., Nula-Tula, City Of Tacloban , Leyte','Leyte','National Highway Hollywood Subd., Nula-Tula, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1024,'Tabuan National Highway, Barangay 79, City Of Tacloban , Leyte','Leyte','Tabuan National Highway, Barangay 79, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1025,'Maharlika Highway, Barangay 92, City Of Tacloban , Leyte','Leyte','Maharlika Highway, Barangay 92, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1026,'Eastern Nautical Highway, Picas Norte, Javier, Leyte','Leyte','Eastern Nautical Highway, Picas Norte, Javier, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1027,'National Highway, Barangay 96, City Of Tacloban , Leyte','Leyte','National Highway, Barangay 96, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1028,'Sagkahan - San Jose Junction, Barangay 83-C, City Of Tacloban , Leyte','Leyte','Sagkahan - San Jose Junction, Barangay 83-C, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1029,'Maharlika Highway Purok Iv, Barangay 91, City Of Tacloban , Leyte','Leyte','Maharlika Highway Purok Iv, Barangay 91, City Of Tacloban , Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1030,'Cor.campetic - Pawing St., Campetik, Palo, Leyte','Leyte','Cor.campetic - Pawing St., Campetik, Palo, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1031,'Real St. Cor. Osme??A St. , Barangay 19 (Pob.), Ormoc City, Leyte','Leyte','Real St. Cor. Osme??A St. , Barangay 19 (Pob.), Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1032,'Lilia Ave., Cogon Combado, Ormoc City, Leyte','Leyte','Lilia Ave., Cogon Combado, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1033,'D. Veloso St., Cogon Combado, Ormoc City, Leyte','Leyte','D. Veloso St., Cogon Combado, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1034,'National Road, San Jose, Sogod, Southern Leyte','Southern Leyte','National Road, San Jose, Sogod, Southern Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1035,'Jose P. Rizal St., Tinago District (Pob.), Bato, Leyte','Leyte','Jose P. Rizal St., Tinago District (Pob.), Bato, Leyte','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1036,'Palo - Carigara - Ormoc City Rd., Valencia, Ormoc City, Leyte','Leyte','Palo - Carigara - Ormoc City Rd., Valencia, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1037,'R.v. Fulache Street, Eastern Barangay (Pob.), Hilongos, Leyte','Leyte','R.v. Fulache Street, Eastern Barangay (Pob.), Hilongos, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1038,'Osme??A St., Libertad, Ormoc City, Leyte','Leyte','Osme??A St., Libertad, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1039,'National Highway, Danhug, Ormoc City, Leyte','Leyte','National Highway, Danhug, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1040,'National Highway Sitio Panali-An, Danhug, Ormoc City, Leyte','Leyte','National Highway Sitio Panali-An, Danhug, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1041,'Corner Magsaysay Ave. & Trese Marteres St., Poblacion Zone 15, City Of Baybay, Leyte','Leyte','Corner Magsaysay Ave. & Trese Marteres St., Poblacion Zone 15, City Of Baybay, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1042,'National Highway, Santo Rosario, City Of Baybay, Leyte','Leyte','National Highway, Santo Rosario, City Of Baybay, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1043,'National Highway Pasay Road, Pasay, City Of Maasin , Southern Leyte','Southern Leyte','National Highway Pasay Road, Pasay, City Of Maasin , Southern Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1044,'Real St., Cayare, San Miguel, Leyte','Leyte','Real St., Cayare, San Miguel, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1045,'National Highway, San Pedro, Tunga, Leyte','Leyte','National Highway, San Pedro, Tunga, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1046,'National Road, San Pablo, Ormoc City, Leyte','Leyte','National Road, San Pablo, Ormoc City, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1047,'Maharlika Highway, Campetik Palo, Leyte','Leyte','Maharlika Highway, Campetik Palo, Leyte','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1048,'Mabini St., Bata, City Of Bacolod , Negros Occidental','Negros Occidental','Mabini St., Bata, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1049,'Araneta St., Tangub, City Of Bacolod , Negros Occidental','Negros Occidental','Araneta St., Tangub, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1050,'Bs Aquino Drive, Barangay 5 (Pob.), City Of Bacolod , Negros Occidental','Negros Occidental','Bs Aquino Drive, Barangay 5 (Pob.), City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1051,'The Shophouse Heritage Bs Aquino Drive, Villamonte, City Of Bacolod , Negros Occidental','Negros Occidental','The Shophouse Heritage Bs Aquino Drive, Villamonte, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1052,'Circumferential Road, Villamonte, City Of Bacolod , Negros Occidental','Negros Occidental','Circumferential Road, Villamonte, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1053,'Araneta Corner Lizares St., Barangay 39 (Pob.), City Of Bacolod , Negros Occidental','Negros Occidental','Araneta Corner Lizares St., Barangay 39 (Pob.), City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1054,'General Luna St., Poblacion, City Of Bago, Negros Occidental','Negros Occidental','General Luna St., Poblacion, City Of Bago, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1055,'Libertad Extension Corner Vista Alegre, Mansilingan, City Of Bacolod , Negros Occidental','Negros Occidental','Libertad Extension Corner Vista Alegre, Mansilingan, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1056,'National Highway Alijis Murcia Road, Alijis, City Of Bacolod , Negros Occidental','Negros Occidental','National Highway Alijis Murcia Road, Alijis, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1057,'Lopez Jaena St., Barangay 27 (Pob.), City Of Bacolod , Negros Occidental','Negros Occidental','Lopez Jaena St., Barangay 27 (Pob.), City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1058,'KM 8 88 Araneta St. Sum Ag Road, Sum-Ag, City Of Bacolod , Negros Occidental','Negros Occidental','KM 8 88 Araneta St. Sum Ag Road, Sum-Ag, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1059,'Buena Park Subdivision Burgos Ave., Villamonte, City Of Bacolod , Negros Occidental','Negros Occidental','Buena Park Subdivision Burgos Ave., Villamonte, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1060,'Montelibano Ave., Villamonte, City Of Bacolod , Negros Occidental','Negros Occidental','Montelibano Ave., Villamonte, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1061,'Cor. Lacson - Magsaysay Rd., Taculing, City Of Bacolod , Negros Occidental','Negros Occidental','Cor. Lacson - Magsaysay Rd., Taculing, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1062,'Circumferential Road, Bata, City Of Bacolod , Negros Occidental','Negros Occidental','Circumferential Road, Bata, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1063,'Ngc Grounds Circumferential Rd., Villamonte, City Of Bacolod , Negros Occidental','Negros Occidental','Ngc Grounds Circumferential Rd., Villamonte, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1064,'Rizal Cor. Locsin Sts., Barangay 11 (Pob.), City Of Bacolod , Negros Occidental','Negros Occidental','Rizal Cor. Locsin Sts., Barangay 11 (Pob.), City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1065,'National Highway Araneta St., Sum-Ag, City Of Bacolod , Negros Occidental','Negros Occidental','National Highway Araneta St., Sum-Ag, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1066,'13.5 KM. Negros South Road, Taloc, City Of Bago, Negros Occidental','Negros Occidental','13.5 KM. Negros South Road, Taloc, City Of Bago, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1067,'16Th St. Cor. Lacson St., Barangay 4 (Pob.), City Of Bacolod , Negros Occidental','Negros Occidental','16Th St. Cor. Lacson St., Barangay 4 (Pob.), City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1068,'National Hi-Way Alijis Rd., Mansilingan, City Of Bacolod , Negros Occidental','Negros Occidental','National Hi-Way Alijis Rd., Mansilingan, City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1069,'24Th Lacson, Barangay 5 (Pob.), City Of Bacolod , Negros Occidental','Negros Occidental','24Th Lacson, Barangay 5 (Pob.), City Of Bacolod , Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1070,'Rizal Street, Barangay 7 (Pob.), City Of Kabankalan, Negros Occidental','Negros Occidental','Rizal Street, Barangay 7 (Pob.), City Of Kabankalan, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1071,'Corner Broce - Carmona St., Barangay Iii (Pob.), City Of San Carlos, Negros Occidental','Negros Occidental','Corner Broce - Carmona St., Barangay Iii (Pob.), City Of San Carlos, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1072,'Jesus Perez Cor. Guanzon Sts., Barangay 2 (Pob.), City Of Kabankalan, Negros Occidental','Negros Occidental','Jesus Perez Cor. Guanzon Sts., Barangay 2 (Pob.), City Of Kabankalan, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1073,'National Road, Gargato, Hinigaran, Negros Occidental','Negros Occidental','National Road, Gargato, Hinigaran, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1074,'Poblacion, Himamaylan City Negros Occidental','Himamaylan City Negros Occidental','Poblacion, Himamaylan City Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1075,'National Road Mabinay St., Bato, Mabinay, Negros Oriental','Negros Oriental','National Road Mabinay St., Bato, Mabinay, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1076,'National Road, Zone 12 (Pob.), Enrique B. Magalona, Negros Occidental','Negros Occidental','National Road, Zone 12 (Pob.), Enrique B. Magalona, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1077,'National Road Rizal St., Barangay Ii (Pob.), City Of Silay, Negros Occidental','Negros Occidental','National Road Rizal St., Barangay Ii (Pob.), City Of Silay, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1078,'National Road Hda Amigos 3, Tinampa-An, City Of Cadiz, Negros Occidental','Negros Occidental','National Road Hda Amigos 3, Tinampa-An, City Of Cadiz, Negros Occidental','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1079,'Jose Romero Rd. Cor. Lamberto Macias Rd., Tabuctubig, City Of Dumaguete , Negros Oriental','Negros Oriental','Jose Romero Rd. Cor. Lamberto Macias Rd., Tabuctubig, City Of Dumaguete , Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1080,'National Highway, West Poblacion, Bacong, Negros Oriental','Negros Oriental','National Highway, West Poblacion, Bacong, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1081,'National Road, San Miguel, Bacong, Negros Oriental','Negros Oriental','National Road, San Miguel, Bacong, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1082,'National Road, Poblacion, San Jose, Negros Oriental','Negros Oriental','National Road, Poblacion, San Jose, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1083,'Purok 4 National Highway, Poblacion, Santa Catalina, Negros Oriental','Negros Oriental','Purok 4 National Highway, Poblacion, Santa Catalina, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1084,'National Road, Mangnao-Canal, City Of Dumaguete , Negros Oriental','Negros Oriental','National Road, Mangnao-Canal, City Of Dumaguete , Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1085,'National Road, Poblacion Iii, Dauin, Negros Oriental','Negros Oriental','National Road, Poblacion Iii, Dauin, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1086,'National Highway Quezon St., Tamogong, City Of Bais, Negros Oriental','Negros Oriental','National Highway Quezon St., Tamogong, City Of Bais, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1087,'Real Corner San Jose Sts., Poblacion No. 7, City Of Dumaguete , Negros Oriental','Negros Oriental','Real Corner San Jose Sts., Poblacion No. 7, City Of Dumaguete , Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1088,'S Villegas Corner Magsaysay Blvd., Poblacion, City Of Guihulngan, Negros Oriental','Negros Oriental','S Villegas Corner Magsaysay Blvd., Poblacion, City Of Guihulngan, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1089,'National Road Corner Divinagracia St., Poblacion, Sibulan, Negros Oriental','Negros Oriental','National Road Corner Divinagracia St., Poblacion, Sibulan, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1090,'National Hghway, Tinago (Pob.), City Of Bayawan, Negros Oriental','Negros Oriental','National Hghway, Tinago (Pob.), City Of Bayawan, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1091,'National Road, Agan-An, Sibulan, Negros Oriental','Negros Oriental','National Road, Agan-An, Sibulan, Negros Oriental','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1092,'Del Rosario Street, Poblacion 8, City Of Catbalogan , Samar','Samar','Del Rosario Street, Poblacion 8, City Of Catbalogan , Samar','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1093,'National Road, Macagtas, Catarman , Northern Samar','Northern Samar','National Road, Macagtas, Catarman , Northern Samar','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1094,'National Highway, San Jorge Ii (Pob.), San Jorge, Samar (Western Samar)','Samar (Western Samar)','National Highway, San Jorge Ii (Pob.), San Jorge, Samar (Western Samar)','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1095,'Pan Philippine Highway, Tinambacan Norte, City Of Calbayog, Samar','Samar','Pan Philippine Highway, Tinambacan Norte, City Of Calbayog, Samar','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1096,'National Road, Trinidad, City Of Calbayog, Samar','Samar','National Road, Trinidad, City Of Calbayog, Samar','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1097,'Maharlika Highway, Capoocan, City Of Calbayog, Samar','Samar','Maharlika Highway, Capoocan, City Of Calbayog, Samar','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1098,'Allen Ave.cor San Francisco St., Guindaponan, City Of Catbalogan , Samar (Western Samar)','Samar (Western Samar)','Allen Ave.cor San Francisco St., Guindaponan, City Of Catbalogan , Samar (Western Samar)','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1099,'National Highway, Can-Abong, City Of Borongan , Eastern Samar','Eastern Samar','National Highway, Can-Abong, City Of Borongan , Eastern Samar','Region VIII','SERVICE STATION',11,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1100,'National Highway, Poblacion, San Juan, Siquijor','Siquijor','National Highway, Poblacion, San Juan, Siquijor','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1101,'Sayre Highway, Linabo, City Of Malaybalay , Bukidnon','Bukidnon','Sayre Highway, Linabo, City Of Malaybalay , Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1102,'Sayre Highway Fortich St., Barangay 1 (Pob.), City Of Malaybalay , Bukidnon','Bukidnon','Sayre Highway Fortich St., Barangay 1 (Pob.), City Of Malaybalay , Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1103,'Recto Avenue-Osme??A St., Barangay 24 (Pob.), City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Recto Avenue-Osme??A St., Barangay 24 (Pob.), City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1104,'Cor. Quezon Ave. & Echavez St., Central (Pob.), City Of Dipolog , Zamboanga Del Norte','Zamboanga Del Norte','Cor. Quezon Ave. & Echavez St., Central (Pob.), City Of Dipolog , Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1105,'Cor. Araullo & Jamisola Sts., Gatas (Pob.), City Of Pagadian , Zamboanga Del Sur','Zamboanga Del Sur','Cor. Araullo & Jamisola Sts., Gatas (Pob.), City Of Pagadian , Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1106,'Purok 4 Apokon Road Cor.timog Ave., Apokon, City Of Tagum , Davao Del Norte','Davao Del Norte','Purok 4 Apokon Road Cor.timog Ave., Apokon, City Of Tagum , Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1107,'National Highway Baan, Mahay, City Of Butuan , Agusan Del Norte','Agusan Del Norte','National Highway Baan, Mahay, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1108,'J. C. Aquino Ave. Cor. Doongan Road, Bonbon, City Of Butuan , Agusan Del Norte','Agusan Del Norte','J. C. Aquino Ave. Cor. Doongan Road, Bonbon, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1109,'Purok 2 Butuan-Cagayan De Oro-Iligan Rd., Barangay 19 (Pob.), City Of Gingoog, Misamis Oriental Mind','Misamis Oriental Mind','Purok 2 Butuan-Cagayan De Oro-Iligan Rd., Barangay 19 (Pob.), City Of Gingoog, Misamis Oriental Mind','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1110,'Salvador Calo St., Libertad, City Of Butuan , Agusan Del Norte','Agusan Del Norte','Salvador Calo St., Libertad, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1111,'National Highway, Bancasi, City Of Butuan , Agusan Del Norte','Agusan Del Norte','National Highway, Bancasi, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1112,'Villanueva St. Cor. G. Flores Ave., Villa Kananga, City Of Butuan , Agusan Del Norte','Agusan Del Norte','Villanueva St. Cor. G. Flores Ave., Villa Kananga, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1113,'National Highway Purok 4 Ampayon , Lemon, City Of Butuan , Agusan Del Norte','Agusan Del Norte','National Highway Purok 4 Ampayon , Lemon, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1114,'S. Calo St., Villa Kananga, City Of Butuan , Agusan Del Norte','Agusan Del Norte','S. Calo St., Villa Kananga, City Of Butuan , Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1115,'Jt Domingo Cor. Romulo Rosales(Langihan) St., Bayanihan Pob., City Of Butuan , Agusan Del Norte Mind','Agusan Del Norte Mind','Jt Domingo Cor. Romulo Rosales(Langihan) St., Bayanihan Pob., City Of Butuan , Agusan Del Norte Mind','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1116,'National Highway, Kauswagan, City Of Cabadbaran, Agusan Del Norte','Agusan Del Norte','National Highway, Kauswagan, City Of Cabadbaran, Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1117,'Purok 5 National Highway, Mabini, City Of Cabadbaran, Agusan Del Norte','Agusan Del Norte','Purok 5 National Highway, Mabini, City Of Cabadbaran, Agusan Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1118,'National Highway Purok Gumamela, Santa Cruz, Rosario, Agusan Del Sur','Agusan Del Sur','National Highway Purok Gumamela, Santa Cruz, Rosario, Agusan Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1119,'National Highway, Barangay 4 (Pob.), San Francisco, Agusan Del Sur','Agusan Del Sur','National Highway, Barangay 4 (Pob.), San Francisco, Agusan Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1120,'Pan Philippine Highway Purok 5, Cuevas, Trento, Agusan Del Sur','Agusan Del Sur','Pan Philippine Highway Purok 5, Cuevas, Trento, Agusan Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1121,'National Highway, Barangay 3 (Pob.), San Francisco, Agusan Del Sur','Agusan Del Sur','National Highway, Barangay 3 (Pob.), San Francisco, Agusan Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1122,'National Highway, Camp I, Maramag, Bukidnon','Bukidnon','National Highway, Camp I, Maramag, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1123,'Sayre Highway, East Kibawe (Pob.), Kibawe, Bukidnon','Bukidnon','Sayre Highway, East Kibawe (Pob.), Kibawe, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1124,'Purok 12 Sayre Highway, Poblacion, City Of Valencia, Bukidnon','Bukidnon','Purok 12 Sayre Highway, Poblacion, City Of Valencia, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1125,'Purok 17 C Sayre Highway, Poblacion, City Of Valencia, Bukidnon','Bukidnon','Purok 17 C Sayre Highway, Poblacion, City Of Valencia, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1126,'National Road, Barangay 4 (Pob.), Talakag, Bukidnon','Bukidnon','National Road, Barangay 4 (Pob.), Talakag, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1127,'National Highway, Barangay 2 (Pob.), Talakag, Bukidnon','Bukidnon','National Highway, Barangay 2 (Pob.), Talakag, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1128,'Camp Philips, Agusan Canyon, Manolo Fortich, Bukidnon','Bukidnon','Camp Philips, Agusan Canyon, Manolo Fortich, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1129,'Diversion Road Crossing Landing, Barangay 4 (Pob.), City Of Malaybalay , Bukidnon','Bukidnon','Diversion Road Crossing Landing, Barangay 4 (Pob.), City Of Malaybalay , Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1130,'Purok 5Sayre National Highway, San Jose, City Of Malaybalay , Bukidnon','Bukidnon','Purok 5Sayre National Highway, San Jose, City Of Malaybalay , Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1131,'Sayre National Highway P-1, Lumbo, City Of Valencia, Bukidnon','Bukidnon','Sayre National Highway P-1, Lumbo, City Of Valencia, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1132,'National Road Sayre Highway, Bagontaas, City Of Valencia, Bukidnon','Bukidnon','National Road Sayre Highway, Bagontaas, City Of Valencia, Bukidnon','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1133,'Cataluna St., Kadi, Sen. Ninoy Aquino, Sultan Kudarat','Sultan Kudarat','Cataluna St., Kadi, Sen. Ninoy Aquino, Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1134,'Public Market Area, Bual, Tulunan, Cotabato','Cotabato','Public Market Area, Bual, Tulunan, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1135,'National Highway, Poblacion, Carmen, Cotabato','Cotabato','National Highway, Poblacion, Carmen, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1136,'National Highway, New Culasi, Tulunan, Cotabato','Cotabato','National Highway, New Culasi, Tulunan, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1137,'National Highway Matalam-Sibsib Rd., Lika, M\'lang, Cotabato','Cotabato','National Highway Matalam-Sibsib Rd., Lika, M\'lang, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1138,'National Highway, Poblacion, Kabacan, Cotabato','Cotabato','National Highway, Poblacion, Kabacan, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1139,'National Hway Cor. Zamora St., Poblacion, Kabacan, Cotabato','Cotabato','National Hway Cor. Zamora St., Poblacion, Kabacan, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1140,'Blk 9, Rosary Heights 9 National Highway, Rosary Heights Vi, City Of Cotabato, Cotabato City Mindana','Cotabato City Mindana','Blk 9, Rosary Heights 9 National Highway, Rosary Heights Vi, City Of Cotabato, Cotabato City Mindana','BARMM','SERVICE STATION',17,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1141,'By-Pass Road Bo. Malagapas, Poblacion Viii, City Of Cotabato, Cotabato','Cotabato','By-Pass Road Bo. Malagapas, Poblacion Viii, City Of Cotabato, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1142,'National Highway, Poblacion, Midsayap, Cotabato','Cotabato','National Highway, Poblacion, Midsayap, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1143,'Sinsuat Ave., Rosary Heights Vii, City Of Cotabato, Cotabato','Cotabato','Sinsuat Ave., Rosary Heights Vii, City Of Cotabato, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1144,'Cor. Sinsuat & Don Sero Rosary Heights, Rosary Heights Ii, City Of Cotabato, Cotabato','Cotabato','Cor. Sinsuat & Don Sero Rosary Heights, Rosary Heights Ii, City Of Cotabato, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1145,'National Highway, Poblacion, President Roxas, Cotabato','Cotabato','National Highway, Poblacion, President Roxas, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1146,'National Highway, Lanao, City Of Kidapawan , Cotabato','Cotabato','National Highway, Lanao, City Of Kidapawan , Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1147,'National Highway, Poblacion, Makilala, Cotabato','Cotabato','National Highway, Poblacion, Makilala, Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1148,'Mc Arthur Highway, Matina Crossing, City Of Davao, Davao Del Sur','Davao Del Sur','Mc Arthur Highway, Matina Crossing, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1149,'National Highway, Poblacion, Monkayo, Davao De Oro','Davao De Oro','National Highway, Poblacion, Monkayo, Davao De Oro','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1150,'National Highway Purok 7, Poblacion, Nabunturan , Davao De Oro','Davao De Oro','National Highway Purok 7, Poblacion, Nabunturan , Davao De Oro','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1151,'National Highway Purok 2, Poblacion, Nabunturan , Davao De Oro','Davao De Oro','National Highway Purok 2, Poblacion, Nabunturan , Davao De Oro','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1152,'Montevista - Compostela - Mati Boundary Rd., Bagongon, Compostela, Davao De Oro','Davao De Oro','Montevista - Compostela - Mati Boundary Rd., Bagongon, Compostela, Davao De Oro','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1153,'National Highway, Little Panay, City Of Panabo, Davao Del Norte','Davao Del Norte','National Highway, Little Panay, City Of Panabo, Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1154,'Panabo Wharf Road, J.p. Laurel, City Of Panabo, Davao Del Norte','Davao Del Norte','Panabo Wharf Road, J.p. Laurel, City Of Panabo, Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1155,'National Highway, Tagpore, City Of Panabo, Davao Del Norte','Davao Del Norte','National Highway, Tagpore, City Of Panabo, Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1156,'National Highway, Sindaton, City Of Panabo, Davao Del Norte','Davao Del Norte','National Highway, Sindaton, City Of Panabo, Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1157,'National Highway Purok Cattleya, Visayan Village, City Of Tagum , Davao Del Norte','Davao Del Norte','National Highway Purok Cattleya, Visayan Village, City Of Tagum , Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1158,'Capitol Rd., Mankilam, City Of Tagum , Davao Del Norte','Davao Del Norte','Capitol Rd., Mankilam, City Of Tagum , Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1159,'Gazmen Compound Purok Talisay Capitol Circumferential Rd., Magugpo West, City Of Tagum , Davao Del N','Davao Del N','Gazmen Compound Purok Talisay Capitol Circumferential Rd., Magugpo West, City Of Tagum , Davao Del N','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1160,'Purok 13 Capitol Road, San Miguel, City Of Tagum , Davao Del Norte','Davao Del Norte','Purok 13 Capitol Road, San Miguel, City Of Tagum , Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1161,'National Highway Purok 6, Ising (Pob.), Carmen, Davao Del Norte','Davao Del Norte','National Highway Purok 6, Ising (Pob.), Carmen, Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1162,'National Highway Cor. North Circumferential Rd., Magugpo North, City Of Tagum , Davao Del Norte Minda','Davao Del Norte Minda','National Highway Cor. North Circumferential Rd., Magugpo North, City Of Tagum , Davao Del Norte Minda','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1163,'National Highway, Magugpo Poblacion, City Of Tagum , Davao Del Norte','Davao Del Norte','National Highway, Magugpo Poblacion, City Of Tagum , Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1164,'National Highway Purok Narra , Visayan Village, City Of Tagum , Davao Del Norte','Davao Del Norte','National Highway Purok Narra , Visayan Village, City Of Tagum , Davao Del Norte','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1165,'Mc Arthur Highway, Barangay 5-A (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Mc Arthur Highway, Barangay 5-A (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1166,'National Highway, Mintal, City Of Davao, Davao Del Sur','Davao Del Sur','National Highway, Mintal, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1167,'McArthur National Highway, Toril (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','McArthur National Highway, Toril (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1168,'Pan Philippine Highway McArthur Highway, Toril (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Pan Philippine Highway McArthur Highway, Toril (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1169,'Julian Rodriguez Sr. Ave. Ma-A , Matina Crossing, City Of Davao, Davao Del Sur','Davao Del Sur','Julian Rodriguez Sr. Ave. Ma-A , Matina Crossing, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1170,'Daang Maharlika Highway KM 9, Sasa, City Of Davao, Davao Del Sur','Davao Del Sur','Daang Maharlika Highway KM 9, Sasa, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1171,'Tigatto Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Tigatto Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1172,'KM 8 McArthur Highway Ulas, Talomo (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','KM 8 McArthur Highway Ulas, Talomo (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1173,'KM 5 Buhangin Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','KM 5 Buhangin Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1174,'Quimpo Blvd. Cor. Acacia Ecoland, Bucana, City Of Davao, Davao Del Sur','Davao Del Sur','Quimpo Blvd. Cor. Acacia Ecoland, Bucana, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1176,'National Highway Poblacion, Calinan (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','National Highway Poblacion, Calinan (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1177,'Pan-Philippine Highway Libby Road Puan, Bago Gallera, City Of Davao, Davao Del Sur','Davao Del Sur','Pan-Philippine Highway Libby Road Puan, Bago Gallera, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1178,'Purok 4 Rambutan St., Tugbok (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Purok 4 Rambutan St., Tugbok (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1179,'Purok 10 Calinan-Cawayan Cor. Calinan-Wangan Rd., Subasta, City Of Davao, Davao Del Sur','Davao Del Sur','Purok 10 Calinan-Cawayan Cor. Calinan-Wangan Rd., Subasta, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1180,'J. P. Laurel Ave. Bajada, Barangay 20-B (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','J. P. Laurel Ave. Bajada, Barangay 20-B (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1181,'McArthur Highway Bangkal, Talomo (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','McArthur Highway Bangkal, Talomo (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1182,'Catalunan Grande Rd., Catalunan Grande, City Of Davao, Davao Del Sur','Davao Del Sur','Catalunan Grande Rd., Catalunan Grande, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1183,'Magysaysay Ave. Cor. Leon Guerrero St., Barangay 30-C (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Magysaysay Ave. Cor. Leon Guerrero St., Barangay 30-C (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1184,'Dava0-Panabo City Rd. KM 10, Sasa, City Of Davao, Davao Del Sur','Davao Del Sur','Dava0-Panabo City Rd. KM 10, Sasa, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1185,'Purok 5 San Juan, Tibungco, City Of Davao, Davao Del Sur','Davao Del Sur','Purok 5 San Juan, Tibungco, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1186,'R. Catillo St., Gov. Vicente Duterte, City Of Davao, Davao Del Sur','Davao Del Sur','R. Catillo St., Gov. Vicente Duterte, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1187,'Cor. Gemesaw & Monteverde Sts., Barangay 27-C (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Cor. Gemesaw & Monteverde Sts., Barangay 27-C (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1188,'Diversion Road Buhangin, Communal, City Of Davao, Davao Del Sur','Davao Del Sur','Diversion Road Buhangin, Communal, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1189,'J.P. Laurel Avenue Lanang San Antonio, Rafael Castillo, City Of Davao, Davao Del Sur','Davao Del Sur','J.P. Laurel Avenue Lanang San Antonio, Rafael Castillo, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1190,'Talisay Cor. R. Castillo St., San Antonio, City Of Davao, Davao Del Sur','Davao Del Sur','Talisay Cor. R. Castillo St., San Antonio, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1191,'Km13 Diversion Road, Panacan, City Of Davao, Davao Del Sur','Davao Del Sur','Km13 Diversion Road, Panacan, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1192,'Tigatto Diversion Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Tigatto Diversion Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1193,'Km18 National Highway, Tibungco, City Of Davao, Davao Del Sur','Davao Del Sur','Km18 National Highway, Tibungco, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1194,'Bacaca Road, Barangay 19-B (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Bacaca Road, Barangay 19-B (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1195,'Mandug Rd. Buhangin District, Mandug, City Of Davao, Davao Del Sur','Davao Del Sur','Mandug Rd. Buhangin District, Mandug, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1196,'Cabantian Diversion Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Davao Del Sur','Cabantian Diversion Road, Buhangin (Pob.), City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1197,'KM 13 Davao - Agusan Rd., Bunawan, City Of Davao, Davao Del Sur','Davao Del Sur','KM 13 Davao - Agusan Rd., Bunawan, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1198,'Countryville Executive Homes, Cabantian, City Of Davao, Davao Del Sur','Davao Del Sur','Countryville Executive Homes, Cabantian, City Of Davao, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1199,'National Highway, Balutakay, Hagonoy, Davao Del Sur','Davao Del Sur','National Highway, Balutakay, Hagonoy, Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1200,'Jose Abad Santos St., Zone 3 (Pob.), City Of Digos , Davao Del Sur','Davao Del Sur','Jose Abad Santos St., Zone 3 (Pob.), City Of Digos , Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1201,'J.p. Rizal Ave.national Highway, Zone 3 (Pob.), City Of Digos , Davao Del Sur','Davao Del Sur','J.p. Rizal Ave.national Highway, Zone 3 (Pob.), City Of Digos , Davao Del Sur','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1202,'National Highway, Kinanga, Don Marcelino, Davao Occidental','Davao Occidental','National Highway, Kinanga, Don Marcelino, Davao Occidental','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1203,'Demoloc - Little Baguio - Alabel Rd., Poblacion, Malita , Davao Occidental','Davao Occidental','Demoloc - Little Baguio - Alabel Rd., Poblacion, Malita , Davao Occidental','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1204,'National Highway, Dahican, City Of Mati , Davao Oriental','Davao Oriental','National Highway, Dahican, City Of Mati , Davao Oriental','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1205,'Quezon Ave. Cor. Sinsuat Ave., Poblacion, City Of Kidapawan , Cotabato','Cotabato','Quezon Ave. Cor. Sinsuat Ave., Poblacion, City Of Kidapawan , Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1206,'National Highway Quezon Blvd., Sudapin, City Of Kidapawan , Cotabato','Cotabato','National Highway Quezon Blvd., Sudapin, City Of Kidapawan , Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1207,'Nat\'ional Highway, Maranding, Lala, Lanao Del Norte','Lanao Del Norte','Nat\'ional Highway, Maranding, Lala, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1208,'National Highway, Sagadan , Baroy, Lanao Del Norte','Lanao Del Norte','National Highway, Sagadan , Baroy, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1209,'National Highway, Villa Verde, City Of Iligan, Lanao Del Norte','Lanao Del Norte','National Highway, Villa Verde, City Of Iligan, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1210,'National Highway, Tubod, City Of Iligan, Lanao Del Norte','Lanao Del Norte','National Highway, Tubod, City Of Iligan, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1211,'National Highway Cor. Vicenta Sheak, San Miguel, City Of Iligan, Lanao Del Norte','Lanao Del Norte','National Highway Cor. Vicenta Sheak, San Miguel, City Of Iligan, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1212,'J. Luna - Gen. Lluch Sts., Poblacion, City Of Iligan, Lanao Del Norte','Lanao Del Norte','J. Luna - Gen. Lluch Sts., Poblacion, City Of Iligan, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1213,'R. Jeffrey Rd. Ext. Villa Quezon Ave., Palao, City Of Iligan, Lanao Del Norte','Lanao Del Norte','R. Jeffrey Rd. Ext. Villa Quezon Ave., Palao, City Of Iligan, Lanao Del Norte','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1214,'Tomas Cabili, Cenauri Village, Tominobo Proper Purok 5-A, Iligan City','Iligan City','Tomas Cabili, Cenauri Village, Tominobo Proper Purok 5-A, Iligan City','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1215,'National Highway, Brgy. Tominobo, Iligan City','Iligan City','National Highway, Brgy. Tominobo, Iligan City','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1216,'National Highway, Poblacion, Lugait, Misamis Oriental','Misamis Oriental','National Highway, Poblacion, Lugait, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1217,'J.P. Lim Cor. Vilo Sts., Calsada, Sultan Kudarat, Maguindanao','Maguindanao','J.P. Lim Cor. Vilo Sts., Calsada, Sultan Kudarat, Maguindanao','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1218,'National Highway, Gadunganpedpandaran, Parang, Maguindanao','Maguindanao','National Highway, Gadunganpedpandaran, Parang, Maguindanao','BARMM','SERVICE STATION',17,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1219,'Hayes-Vicente Roa Sts., Nazareth, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Hayes-Vicente Roa Sts., Nazareth, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1220,'Cor. Luna & Washington Sts., Poblacion Ii, City Of Oroquieta , Misamis Occidental','Misamis Occidental','Cor. Luna & Washington Sts., Poblacion Ii, City Of Oroquieta , Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1221,'Capitol Drive Cor. Sen.j.ozamis St., Lamac Lower, City Of Oroquieta , Misamis Occidental','Misamis Occidental','Capitol Drive Cor. Sen.j.ozamis St., Lamac Lower, City Of Oroquieta , Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,17.64752670,121.76037110,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1222,'National Highway, Talic, City Of Oroquieta , Misamis Occidental','Misamis Occidental','National Highway, Talic, City Of Oroquieta , Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1223,'National Highway, Poblacion, Bonifacio, Misamis Occidental','Misamis Occidental','National Highway, Poblacion, Bonifacio, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1224,'National Highway, Libertad Bajo, Sinacaban, Misamis Occidental','Misamis Occidental','National Highway, Libertad Bajo, Sinacaban, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1225,'National Highway, Gango, City Of Ozamiz, Misamis Occidental','Misamis Occidental','National Highway, Gango, City Of Ozamiz, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1226,'National Highway, Santa Maria, City Of Tangub, Misamis Occidental','Misamis Occidental','National Highway, Santa Maria, City Of Tangub, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1227,'National Highway Purok 1, Santa Cruz (Pob.), Jimenez, Misamis Occidental','Misamis Occidental','National Highway Purok 1, Santa Cruz (Pob.), Jimenez, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1228,'National Highway, Sinonoc, Sinacaban, Misamis Occidental','Misamis Occidental','National Highway, Sinonoc, Sinacaban, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1229,'National Highway, Lam-An, City Of Ozamiz, Misamis Occidental','Misamis Occidental','National Highway, Lam-An, City Of Ozamiz, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1230,'National Highway Rizal Avenue P-1 , Lam-An, City Of Ozamiz, Misamis Occidental','Misamis Occidental','National Highway Rizal Avenue P-1 , Lam-An, City Of Ozamiz, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1231,'Circumferential Road, Bacolod, City Of Ozamiz, Misamis Occidental','Misamis Occidental','Circumferential Road, Bacolod, City Of Ozamiz, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1232,'Rizal Avenue Corner Burgos St., 50Th District (Pob.), City Of Ozamiz, Misamis Occidental','Misamis Occidental','Rizal Avenue Corner Burgos St., 50Th District (Pob.), City Of Ozamiz, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1233,'National Highway, Taguima, Tudela, Misamis Occidental','Misamis Occidental','National Highway, Taguima, Tudela, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1234,'National Highway. Bernad Ave., Bacolod, City Of Ozamiz, Misamis Occidental','Misamis Occidental','National Highway. Bernad Ave., Bacolod, City Of Ozamiz, Misamis Occidental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1235,'National Highway, Quezon, Gitagum, Misamis Oriental','Misamis Oriental','National Highway, Quezon, Gitagum, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1236,'Butuan Cagayan De Oro Iligan Rd., Poblacion, Manticao, Misamis Oriental','Misamis Oriental','Butuan Cagayan De Oro Iligan Rd., Poblacion, Manticao, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1237,'National Hiway Butuan-Cagayan De Oro-Iligan Rd. P3, Poblacion, Naawan, Misamis Oriental','Misamis Oriental','National Hiway Butuan-Cagayan De Oro-Iligan Rd. P3, Poblacion, Naawan, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1238,'National Highway, Bulua, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway, Bulua, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1239,'Zone 3 Butuan-Cagayan De Oro-Iligan Rd., Taboc, Opol, Misamis Oriental','Misamis Oriental','Zone 3 Butuan-Cagayan De Oro-Iligan Rd., Taboc, Opol, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1240,'National Road Aluba, Macasandig, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Road Aluba, Macasandig, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1241,'Jr Borja Extension, Gusa, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Jr Borja Extension, Gusa, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1242,'Fr Masterson Ave. Xavier States, Balulang, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Fr Masterson Ave. Xavier States, Balulang, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1243,'National Highway, Puerto, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway, Puerto, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1244,'National Highway Ala-E, Puerto, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway Ala-E, Puerto, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1245,'National Highway Purok 4, Tablon, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway Purok 4, Tablon, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1246,'National Highway Kinasanghan St., Iponan, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway Kinasanghan St., Iponan, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1247,'SM City Area, Balulang, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','SM City Area, Balulang, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1248,'National Highway, Cugman, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway, Cugman, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1249,'Zayaz Road, Carmen, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Zayaz Road, Carmen, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1250,'P-3 Nha Highway Zone 5, Kauswagan, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','P-3 Nha Highway Zone 5, Kauswagan, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1251,'Vamenta Blvd.cor. Max Suniel St., Carmen, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Vamenta Blvd.cor. Max Suniel St., Carmen, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1252,'National Highway, Gusa, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','National Highway, Gusa, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1253,'Vamenta Blvd., Carmen, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Vamenta Blvd., Carmen, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,8.47702120,124.63781790,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1254,'National Highway Zone 11, Poblacion, Laguindingan, Misamis Oriental','Misamis Oriental','National Highway Zone 11, Poblacion, Laguindingan, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1255,'Zone 7 , Carmen, City Of Cagayan De Oro , Misamis Oriental','Misamis Oriental','Zone 7 , Carmen, City Of Cagayan De Oro , Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1256,'Lapasan Gaabucayan St. Extension District, Puntod, City Of Cagayan De Oro , Misamis Oriental Mindana','Misamis Oriental Mindana','Lapasan Gaabucayan St. Extension District, Puntod, City Of Cagayan De Oro , Misamis Oriental Mindana','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1257,'National Highway, San Alonzo, Balingoan, Misamis Oriental','Misamis Oriental','National Highway, San Alonzo, Balingoan, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1258,'Purok Rosas, Poblacion, Kiamba, Sarangani','Sarangani','Purok Rosas, Poblacion, Kiamba, Sarangani','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1259,'Cor. Bulaong Terminal, City Heights, City Of General Santos, South Cotabato','South Cotabato','Cor. Bulaong Terminal, City Heights, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1260,'Nlsa Road Purok Bayanihan, San Isidro, City Of General Santos, South Cotabato','South Cotabato','Nlsa Road Purok Bayanihan, San Isidro, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1261,'Nlsa Road Cor. Villareal St., Lagao, City Of General Santos, South Cotabato','South Cotabato','Nlsa Road Cor. Villareal St., Lagao, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1262,'National Highway, City Heights, City Of General Santos, South Cotabato','South Cotabato','National Highway, City Heights, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1263,'Pan Philippine Highway, City Heights, City Of General Santos, South Cotabato','South Cotabato','Pan Philippine Highway, City Heights, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1264,'Purok 13 Manuhay Rd., San Isidro, City Of General Santos, South Cotabato','South Cotabato','Purok 13 Manuhay Rd., San Isidro, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1265,'Santiago Ave. Cor. J. Catolico Sr., Lagao, City Of General Santos, South Cotabato','South Cotabato','Santiago Ave. Cor. J. Catolico Sr., Lagao, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1266,'Leon Llido St., Lagao, City Of General Santos, South Cotabato','South Cotabato','Leon Llido St., Lagao, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1267,'J. Catolico Avenue, Lagao, City Of General Santos, South Cotabato','South Cotabato','J. Catolico Avenue, Lagao, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1268,'P. Acharon Blvd., Dadiangas East (Pob.), City Of General Santos, South Cotabato','South Cotabato','P. Acharon Blvd., Dadiangas East (Pob.), City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1269,'Sinawal Road, Sinawal, City Of General Santos, South Cotabato','South Cotabato','Sinawal Road, Sinawal, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1270,'National Hihway Labos St., City Heights, City Of General Santos, South Cotabato','South Cotabato','National Hihway Labos St., City Heights, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1271,'National Highway Cor. Rivera St., Calumpang, City Of General Santos, South Cotabato','South Cotabato','National Highway Cor. Rivera St., Calumpang, City Of General Santos, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:48'),(1272,'Avd Zone 5, Libertad (Pob.), Surallah, South Cotabato','South Cotabato','Avd Zone 5, Libertad (Pob.), Surallah, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1273,'National Highway Sampaguita St. , Poblacion, Polomolok, South Cotabato','South Cotabato','National Highway Sampaguita St. , Poblacion, Polomolok, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1274,'National Highway, San Isidro, Santo Ni??O, South Cotabato','South Cotabato','National Highway, San Isidro, Santo Ni??O, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1275,'G.p. Santos St., Poblacion, Norala, South Cotabato','South Cotabato','G.p. Santos St., Poblacion, Norala, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1276,'National Highway, Paraiso, City Of Koronadal , South Cotabato','South Cotabato','National Highway, Paraiso, City Of Koronadal , South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1277,'National Highway, Linan, Tupi, South Cotabato','South Cotabato','National Highway, Linan, Tupi, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1278,'Purok San Miguel, Poblacion, Polomolok, South Cotabato','South Cotabato','Purok San Miguel, Poblacion, Polomolok, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1279,'Gensan Drive Zone 3 , General Paulino Santos, City Of Koronadal , South Cotabato','South Cotabato','Gensan Drive Zone 3 , General Paulino Santos, City Of Koronadal , South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1280,'Purok 10 National Highway, Poblacion, Tupi, South Cotabato','South Cotabato','Purok 10 National Highway, Poblacion, Tupi, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1281,'Market Area, Poblacion, Esperanza, Sultan Kudarat','Sultan Kudarat','Market Area, Poblacion, Esperanza, Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1282,'National Highway, Dansuli, Isulan , Sultan Kudarat','Sultan Kudarat','National Highway, Dansuli, Isulan , Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1283,'National Highway, Chua, Bagumbayan, Sultan Kudarat','Sultan Kudarat','National Highway, Chua, Bagumbayan, Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1284,'National Highway, Upper Katungal, City Of Tacurong, Sultan Kudarat','Sultan Kudarat','National Highway, Upper Katungal, City Of Tacurong, Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1285,'Alunan Highway, Poblacion, City Of Tacurong, Sultan Kudarat','Sultan Kudarat','Alunan Highway, Poblacion, City Of Tacurong, Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1286,'National Highway, Kalawag Iii (Pob.), Isulan , Sultan Kudarat','Sultan Kudarat','National Highway, Kalawag Iii (Pob.), Isulan , Sultan Kudarat','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1287,'National Highway Km4, Luna, City Of Surigao , Surigao Del Norte','Surigao Del Norte','National Highway Km4, Luna, City Of Surigao , Surigao Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1288,'Cor. Navarro & Spina Sts., Canlanipa, City Of Surigao , Surigao Del Norte','Surigao Del Norte','Cor. Navarro & Spina Sts., Canlanipa, City Of Surigao , Surigao Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1289,'National Highway, Bad-As, Placer, Surigao Del Norte','Surigao Del Norte','National Highway, Bad-As, Placer, Surigao Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1290,'National Highway, Washington (Pob.), City Of Surigao , Surigao Del Norte','Surigao Del Norte','National Highway, Washington (Pob.), City Of Surigao , Surigao Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1291,'Dapa-General Luna Road Rizal St., San Jose (Pob.), Del Carmen, Surigao Del Norte','Surigao Del Norte','Dapa-General Luna Road Rizal St., San Jose (Pob.), Del Carmen, Surigao Del Norte','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1292,'National Highway, Mangagoy, City Of Bislig, Surigao Del Sur','Surigao Del Sur','National Highway, Mangagoy, City Of Bislig, Surigao Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1293,'Cabrera St. Cor. Surigao-Davao Coastal Rd., Bagong Lungsod (Pob.), City Of Tandag , Surigao Del Sur','Surigao Del Sur','Cabrera St. Cor. Surigao-Davao Coastal Rd., Bagong Lungsod (Pob.), City Of Tandag , Surigao Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1294,'National Highway, Linibonan, Madrid, Surigao Del Sur','Surigao Del Sur','National Highway, Linibonan, Madrid, Surigao Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1295,'National Highway, Purisima (Pob.), Tago, Surigao Del Sur','Surigao Del Sur','National Highway, Purisima (Pob.), Tago, Surigao Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1296,'National Highway, Saca (Pob.), Carrascal, Surigao Del Sur','Surigao Del Sur','National Highway, Saca (Pob.), Carrascal, Surigao Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1297,'National Hgihway, Mabua, City Of Tandag , Surigao Del Sur','Surigao Del Sur','National Hgihway, Mabua, City Of Tandag , Surigao Del Sur','Region XIII','SERVICE STATION',16,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1298,'National Highway, Barangay Uno (Pob.), Katipunan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway, Barangay Uno (Pob.), Katipunan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1299,'National Highway Punta St., Daanglungsod, Katipunan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway Punta St., Daanglungsod, Katipunan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1300,'National Highway Don Jose Carreon St., Polo, City Of Dapitan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway Don Jose Carreon St., Polo, City Of Dapitan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1301,'Dipolog - Polanco -Oroquieta Rd., Villahermosa, Polanco, Zamboanga Del Norte','Zamboanga Del Norte','Dipolog - Polanco -Oroquieta Rd., Villahermosa, Polanco, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1302,'Dipolog - Zamboanga Highway, Santa Filomena, City Of Dipolog , Zamboanga Del Norte','Zamboanga Del Norte','Dipolog - Zamboanga Highway, Santa Filomena, City Of Dipolog , Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1303,'Hospicio Ochoterena St., Bagting (Pob.), City Of Dapitan, Zamboanga Del Norte','Zamboanga Del Norte','Hospicio Ochoterena St., Bagting (Pob.), City Of Dapitan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1304,'National Highway, Obay, Polanco, Zamboanga Del Norte','Zamboanga Del Norte','National Highway, Obay, Polanco, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1305,'National Highway, Turno, City Of Dipolog , Zamboanga Del Norte','Zamboanga Del Norte','National Highway, Turno, City Of Dipolog , Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1306,'Carpitanos Bros. Rd. Cor. Santa Isabel, Santa Filomena, City Of Dipolog , Zamboanga Del Norte Mindan','Zamboanga Del Norte Mindan','Carpitanos Bros. Rd. Cor. Santa Isabel, Santa Filomena, City Of Dipolog , Zamboanga Del Norte Mindan','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1307,'National Highway, Brgy. Disud, Sindangan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway, Brgy. Disud, Sindangan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1308,'National Highway, Bantayan, Sindangan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway, Bantayan, Sindangan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1309,'National Highway, La Roche San Miguel, Sindangan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway, La Roche San Miguel, Sindangan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1310,'National Highway, Bacungan (Pob.), Bacungan, Zamboanga Del Norte','Zamboanga Del Norte','National Highway, Bacungan (Pob.), Bacungan, Zamboanga Del Norte','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1311,'National Highway, Buenavista, City Of Pagadian , Zamboanga Del Sur','Zamboanga Del Sur','National Highway, Buenavista, City Of Pagadian , Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1312,'National Highway Rizal St. Purok 5, Poblacion B, Midsalip, Zamboanga Del Sur','Zamboanga Del Sur','National Highway Rizal St. Purok 5, Poblacion B, Midsalip, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1313,'National Highway Purok 9 Alang Alang, Poblacion, Ramon Magsaysay, Zamboanga Del Sur','Zamboanga Del Sur','National Highway Purok 9 Alang Alang, Poblacion, Ramon Magsaysay, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1314,'Pagadian City - Zamboanga City Rd., New Labangan, Labangan, Zamboanga Del Sur','Zamboanga Del Sur','Pagadian City - Zamboanga City Rd., New Labangan, Labangan, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1315,'Purok Rosas Cor. Sabellano & Pulm, San Pedro (Pob.), City Of Pagadian , Zamboanga Del Sur','Zamboanga Del Sur','Purok Rosas Cor. Sabellano & Pulm, San Pedro (Pob.), City Of Pagadian , Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1316,'National Highway Purok 1, Lower Salug Daku, Mahayag, Zamboanga Del Sur','Zamboanga Del Sur','National Highway Purok 1, Lower Salug Daku, Mahayag, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1317,'National Highway, Bolobolo, City Of El Salvador, Misamis Oriental','Misamis Oriental','National Highway, Bolobolo, City Of El Salvador, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1318,'Gov. Ramos Ave., Sta Maria, Zamboanga City, Zamboanga Del Sur','Zamboanga Del Sur','Gov. Ramos Ave., Sta Maria, Zamboanga City, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1319,'Governor Ramos St. Sta Maria , Canelar, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Governor Ramos St. Sta Maria , Canelar, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1320,'National Highway, Lower Taway, Ipil , Zamboanga Sibugay','Zamboanga Sibugay','National Highway, Lower Taway, Ipil , Zamboanga Sibugay','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1321,'National Highway Gov. Camins Ave., Barangay Zone Iii (Pob.), City Of Zamboanga, Zamboanga Del Sur Mi','Zamboanga Del Sur Mi','National Highway Gov. Camins Ave., Barangay Zone Iii (Pob.), City Of Zamboanga, Zamboanga Del Sur Mi','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1322,'Cadena De Amor St., Tetuan, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Cadena De Amor St., Tetuan, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1323,'Mcll Highway, Sangali, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Mcll Highway, Sangali, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1324,'Mercedes Rd., Tetuan, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Mercedes Rd., Tetuan, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1325,'National Highway Sutterville St., Campo Islam, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','National Highway Sutterville St., Campo Islam, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1326,'San Jose Road Zone 2 Corner G.e Ladesma St.& Buenavista St., Santo Ni??O, City Of Zamboanga, Zamboan','Zamboan','San Jose Road Zone 2 Corner G.e Ladesma St.& Buenavista St., Santo Ni??O, City Of Zamboanga, Zamboan','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1327,'Gov. Lim Avenue, Barangay Zone Iv (Pob.), City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Gov. Lim Avenue, Barangay Zone Iv (Pob.), City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1328,'Nunez Extension, Barangay Zone I (Pob.), City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Nunez Extension, Barangay Zone I (Pob.), City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1329,'Mcll Highway, Tetuan, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Mcll Highway, Tetuan, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1330,'National Road, Cawit, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','National Road, Cawit, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1331,'Veterans Ave. Corner Don Toribio St., Santa Barbara, City Of Zamboanga, Zamboanga Del Sur','Zamboanga Del Sur','Veterans Ave. Corner Don Toribio St., Santa Barbara, City Of Zamboanga, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1332,'Purok 5 National Highway Purok Lilang, Poblacion, Sominot, Zamboanga Del Sur','Zamboanga Del Sur','Purok 5 National Highway Purok Lilang, Poblacion, Sominot, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1333,'National Highway, Riverside (Pob.), Tambulig, Zamboanga Del Sur','Zamboanga Del Sur','National Highway, Riverside (Pob.), Tambulig, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1334,'E Rodriguez Jr Ave. Ugong Pasig City 1604 (Treats Store)','E Rodriguez Jr Ave. Ugong Pasig City 1604 (Treats Store)','E Rodriguez Jr Ave. Ugong Pasig City 1604 (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1335,'Shaw Boulevard Brgy. Wack Wack Mandaluyong City (Treats Store)','Shaw Boulevard Brgy. Wack Wack Mandaluyong City (Treats Store)','Shaw Boulevard Brgy. Wack Wack Mandaluyong City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1336,'KM 44 Northbound Brgy. Mapagong Calamba Laguna (Mates) (Treats Store)','KM 44 Northbound Brgy. Mapagong Calamba Laguna (Mates) (Treats Store)','KM 44 Northbound Brgy. Mapagong Calamba Laguna (Mates) (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1337,'KM 44 Southbound Brgy. Mapagong Calamba Laguna (Pncc) (Treats Store)','KM 44 Southbound Brgy. Mapagong Calamba Laguna (Pncc) (Treats Store)','KM 44 Southbound Brgy. Mapagong Calamba Laguna (Pncc) (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1338,'KM. 22 North Diversion Road, Lias Marilao, Bulacan (Treats Store)','Bulacan (Treats Store)','KM. 22 North Diversion Road, Lias Marilao, Bulacan (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1339,'La Vista Katipunan Ave. Corner Mangyan Rd., Quezon City (Treats Store)','Quezon City (Treats Store)','La Vista Katipunan Ave. Corner Mangyan Rd., Quezon City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1340,'Makati Ave., Corner Sen. Gil Puyat Ave., Makati City (Treats Store)','Makati City (Treats Store)','Makati Ave., Corner Sen. Gil Puyat Ave., Makati City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1341,'Pacific Rim Corner Commerce Avenue Filinvest, Muntinlupa (Treats Store)','Muntinlupa (Treats Store)','Pacific Rim Corner Commerce Avenue Filinvest, Muntinlupa (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1342,'Pasong Tamo Extension, Makati City (Treats Store)','Makati City (Treats Store)','Pasong Tamo Extension, Makati City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1343,'Petron Gov. Ferrer Pinagtipunan Gen. Trias Cavite (Treats Store)','Petron Gov. Ferrer Pinagtipunan Gen. Trias Cavite (Treats Store)','Petron Gov. Ferrer Pinagtipunan Gen. Trias Cavite (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1344,'Petron J.P. Rizal, J.P. Rizal Cor. Spain St. Concepcion Uno Marikina City (Treats Store)','J.P. Rizal Cor. Spain St. Concepcion Uno Marikina City (Treats Store)','Petron J.P. Rizal, J.P. Rizal Cor. Spain St. Concepcion Uno Marikina City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1345,'Petron Violago Homes Parkwood Area B Litex Road Payatas Qc (Treats Store)','Petron Violago Homes Parkwood Area B Litex Road Payatas Qc (Treats Store)','Petron Violago Homes Parkwood Area B Litex Road Payatas Qc (Treats Store)','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:18'),(1346,'Smc Hoc 40 San Miguel Ave. Mandaluyong City (Treats Store)','Smc Hoc 40 San Miguel Ave. Mandaluyong City (Treats Store)','Smc Hoc 40 San Miguel Ave. Mandaluyong City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1347,'South Expressway, San Antonio, San Pedro, Laguna (Treats Store)','Laguna (Treats Store)','South Expressway, San Antonio, San Pedro, Laguna (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1348,'Treats Naia, Termindanaoal 3 Barangay 184, Pasay City (Treats Store)','Pasay City (Treats Store)','Treats Naia, Termindanaoal 3 Barangay 184, Pasay City (Treats Store)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1349,'30Km Southbound Lane, Bocaue, Bulacan (Treats Store)','Bulacan (Treats Store)','30Km Southbound Lane, Bocaue, Bulacan (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1350,'Clark Freeport Zone (Treats Store)','Clark Freeport Zone (Treats Store)','Clark Freeport Zone (Treats Store)','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:18'),(1351,'Gimikan-Portion Of Former Gimikan, Freeport Area, Rizal Highway, Central Business District North Luz','Central Business District North Luz','Gimikan-Portion Of Former Gimikan, Freeport Area, Rizal Highway, Central Business District North Luz','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1352,'Harrison Road, Baguio City (Treats Store)','Baguio City (Treats Store)','Harrison Road, Baguio City (Treats Store)','CAR','SERVICE STATION',2,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1353,'KM 42 Brgy. Sto Nino NLEX,Plaridel Bulacan (Treats Store)','Plaridel Bulacan (Treats Store)','KM 42 Brgy. Sto Nino NLEX,Plaridel Bulacan (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1354,'KM 71 North Luzon Expressway, Mexico Pampanga (Treats Store)','Mexico Pampanga (Treats Store)','KM 71 North Luzon Expressway, Mexico Pampanga (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1355,'Maharlika Highway, Brgy. Caanawan, San Jose City, Nueva Ecija (Treats Store)','Nueva Ecija (Treats Store)','Maharlika Highway, Brgy. Caanawan, San Jose City, Nueva Ecija (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1356,'National Highway, Brgy. Sison, Rosario, La Union (Treats Store)','La Union (Treats Store)','National Highway, Brgy. Sison, Rosario, La Union (Treats Store)','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1357,'Pbr Roman Hi-Way, Alangan Limay, Bataan (Treats Store)','Bataan (Treats Store)','Pbr Roman Hi-Way, Alangan Limay, Bataan (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1358,'Petron Malasin, Maharlika Rd. Malasin, San Jose City (Treats Store)','San Jose City (Treats Store)','Petron Malasin, Maharlika Rd. Malasin, San Jose City (Treats Store)','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:18'),(1359,'Petron Mc Arthur Hiway Tabun Mabalacat Pampanga (Treats Store)','Petron Mc Arthur Hiway Tabun Mabalacat Pampanga (Treats Store)','Petron Mc Arthur Hiway Tabun Mabalacat Pampanga (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1360,'Petron TPLEX KM 134 South Bound, Poroc Pura Tarlac (Treats Store)','Poroc Pura Tarlac (Treats Store)','Petron TPLEX KM 134 South Bound, Poroc Pura Tarlac (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1361,'Petron TPLEX North Bound, Poroc Pura Tarlac (Treats Store)','Poroc Pura Tarlac (Treats Store)','Petron TPLEX North Bound, Poroc Pura Tarlac (Treats Store)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1362,'Rizal Blvd. Cor. Argonaut Highway, Subic Bay Freeport Zone, Olongapo, Zambales','Zambales','Rizal Blvd. Cor. Argonaut Highway, Subic Bay Freeport Zone, Olongapo, Zambales','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1363,'San Fernando City La Union (Treats Store)','San Fernando City La Union (Treats Store)','San Fernando City La Union (Treats Store)','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1364,'Brgy. Tibig, Star Tollways, Lipa City, Batangas (Treats Store)','Batangas (Treats Store)','Brgy. Tibig, Star Tollways, Lipa City, Batangas (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1365,'Kaybagal South, Tagaytay City, Cavite (Treats Store)','Cavite (Treats Store)','Kaybagal South, Tagaytay City, Cavite (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1366,'Petron Concepcion, National Hiway Concepcion Naga Pequena Naga C (Treats Store)','National Hiway Concepcion Naga Pequena Naga C (Treats Store)','Petron Concepcion, National Hiway Concepcion Naga Pequena Naga C (Treats Store)','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1367,'Petron Daang Hari, Lot 1 Versailles Subd Daang Hari Rd. Almanza Dos Las Pi??As City','Lot 1 Versailles Subd Daang Hari Rd. Almanza Dos Las Pi??As City','Petron Daang Hari, Lot 1 Versailles Subd Daang Hari Rd. Almanza Dos Las Pi??As City','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:18'),(1368,'Petron Eton City, Blk 11 Lot 1 Walk 3 Eton City Malitlit Sta. Rosa City Laguna','Blk 11 Lot 1 Walk 3 Eton City Malitlit Sta. Rosa City Laguna','Petron Eton City, Blk 11 Lot 1 Walk 3 Eton City Malitlit Sta. Rosa City Laguna','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1369,'Petron KM 74-75 Star Tollway San Andres Malvar Batangas (Treats Store)','Petron KM 74-75 Star Tollway San Andres Malvar Batangas (Treats Store)','Petron KM 74-75 Star Tollway San Andres Malvar Batangas (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1370,'Petron Naga, Magsaysay Ave., Concepcion Pequena Naga City (Treats Store)','Concepcion Pequena Naga City (Treats Store)','Petron Naga, Magsaysay Ave., Concepcion Pequena Naga City (Treats Store)','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1371,'Petron Naga, Panganiban Drive Tinago Naga City (Treats Store)','Panganiban Drive Tinago Naga City (Treats Store)','Petron Naga, Panganiban Drive Tinago Naga City (Treats Store)','Region V','SERVICE STATION',8,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1372,'Petron San Pablo, Km81 Maharlika Highway Brgy. San Roque San Pablo (Treats Store)','Km81 Maharlika Highway Brgy. San Roque San Pablo (Treats Store)','Petron San Pablo, Km81 Maharlika Highway Brgy. San Roque San Pablo (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1373,'Star Tollways, San Jose, Batangas (Treats Store)','Batangas (Treats Store)','Star Tollways, San Jose, Batangas (Treats Store)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1374,'Caticlan Airport Interim Building, Caticlan, Malay, Aklan (Treats Store)','Aklan (Treats Store)','Caticlan Airport Interim Building, Caticlan, Malay, Aklan (Treats Store)','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1375,'Petron Dampas Tagbilaran City 6300 (Treats Store)','Petron Dampas Tagbilaran City 6300 (Treats Store)','Petron Dampas Tagbilaran City 6300 (Treats Store)','Region VII','SERVICE STATION',10,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1376,'50Th District Ozamiz City Misc Occ 7200 (Treats Store)','50Th District Ozamiz City Misc Occ 7200 (Treats Store)','50Th District Ozamiz City Misc Occ 7200 (Treats Store)','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1377,'Xavierville Ave., Loyola Hights, Quezon City (Car Care Center)','Quezon City (Car Care Center)','Xavierville Ave., Loyola Hights, Quezon City (Car Care Center)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1378,'P-3 Nha Highway, Zone 5, Kauswagan, Cagayan De Oro City (Car Care Center)','Cagayan De Oro City (Car Care Center)','P-3 Nha Highway, Zone 5, Kauswagan, Cagayan De Oro City (Car Care Center)','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:20:06'),(1379,'Covered Parking Area 3, SM City, San Miguel St., Lagao, Gen. Santos City, South Cotabato','South Cotabato','Covered Parking Area 3, SM City, San Miguel St., Lagao, Gen. Santos City, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1380,'Brgy. Boquig, Bantay, Ilocos Sur (Car Care Center)','Ilocos Sur (Car Care Center)','Brgy. Boquig, Bantay, Ilocos Sur (Car Care Center)','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1381,'Maharlika Highway, Batal, Santiago City, Isabela (Car Care Center)','Isabela (Car Care Center)','Maharlika Highway, Batal, Santiago City, Isabela (Car Care Center)','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1382,'Abangan Norte, Marilao, Bulacan (Car Care Center)','Bulacan (Car Care Center)','Abangan Norte, Marilao, Bulacan (Car Care Center)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1383,'Quirino Highway, Sjdm, Bulacan (Car Care Center)','Bulacan (Car Care Center)','Quirino Highway, Sjdm, Bulacan (Car Care Center)','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1384,'Fmc-Lto Cmpd. Talon 1, Las Pi??As City (Car Care Center)','Las Pi??As City (Car Care Center)','Fmc-Lto Cmpd. Talon 1, Las Pi??As City (Car Care Center)','NCR','SERVICE STATION',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-05 14:50:18'),(1385,'Brgy. Tigayon, Kalibo, Aklan (Car Care Center)','Aklan (Car Care Center)','Brgy. Tigayon, Kalibo, Aklan (Car Care Center)','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1386,'13Th Cor. Hilado St., Bacolod City, Negros Occidental (Car Care Center)','Negros Occidental (Car Care Center)','13Th Cor. Hilado St., Bacolod City, Negros Occidental (Car Care Center)','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1387,'Jocson St., Brgy. Dulonan, Arevalo, Iloilo City (Car Care Center)','Iloilo City (Car Care Center)','Jocson St., Brgy. Dulonan, Arevalo, Iloilo City (Car Care Center)','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1388,'C.p. Garcia Highway, Buhangin, Davao City (Car Care Center)','Davao City (Car Care Center)','C.p. Garcia Highway, Buhangin, Davao City (Car Care Center)','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1389,'National Highway, Brgy. City Heights, Gen. Santos City, South Cotabato','South Cotabato','National Highway, Brgy. City Heights, Gen. Santos City, South Cotabato','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1390,'Buntun Highway, Tuguegarao City, Cagayan (Car Care Center)','Cagayan (Car Care Center)','Buntun Highway, Tuguegarao City, Cagayan (Car Care Center)','Region II','SERVICE STATION',4,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1391,'Mangobo St.manghinao Bauan, Batangas (Car Care Center)','Batangas (Car Care Center)','Mangobo St.manghinao Bauan, Batangas (Car Care Center)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1392,'9092 Gen. Tirona Highway, Bacoor City, Cavite (Car Care Center)','Cavite (Car Care Center)','9092 Gen. Tirona Highway, Bacoor City, Cavite (Car Care Center)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1393,'National Highway, Brgy. Barcenaga, Naujan, Oriental Mindoro','Oriental Mindoro','National Highway, Brgy. Barcenaga, Naujan, Oriental Mindoro','Region IV-B','SERVICE STATION',7,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1394,'123 McArthur Higway, Brgy. Matina Crossing Davao City (Car Care Center)','Brgy. Matina Crossing Davao City (Car Care Center)','123 McArthur Higway, Brgy. Matina Crossing Davao City (Car Care Center)','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1395,'Fs Dizon St., El Rio, Bacaca, Davao City (Car Care Center)','Davao City (Car Care Center)','Fs Dizon St., El Rio, Bacaca, Davao City (Car Care Center)','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1396,'National Highway, Brgy. Bo. Lanao, Kidapawan (Car Care Center)','Kidapawan (Car Care Center)','National Highway, Brgy. Bo. Lanao, Kidapawan (Car Care Center)','Region XII','SERVICE STATION',15,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1397,'Buhay Na Tubig, Imus Cavite (Car Care Center)','Imus Cavite (Car Care Center)','Buhay Na Tubig, Imus Cavite (Car Care Center)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1398,'Calasiao-Dagupan Road, Brgy. Nalsian, Calasiao, Pangasinan (Car Care Center)','Pangasinan (Car Care Center)','Calasiao-Dagupan Road, Brgy. Nalsian, Calasiao, Pangasinan (Car Care Center)','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1399,'Un Ave. Cor. Romualdez St., Paco, Manila (Car Care Center)','Manila (Car Care Center)','Un Ave. Cor. Romualdez St., Paco, Manila (Car Care Center)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1400,'Maharlika East, Tagaytay City, Cavite (Car Care Center)','Cavite (Car Care Center)','Maharlika East, Tagaytay City, Cavite (Car Care Center)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1401,'Curvada, National Highway, Tagum City, Davao Del Norte (Car Care Center)','Davao Del Norte (Car Care Center)','Curvada, National Highway, Tagum City, Davao Del Norte (Car Care Center)','Region XI','SERVICE STATION',14,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1402,'Brgy. Dela Paz, Marcos Highway, Pasig City (Car Care Center)','Pasig City (Car Care Center)','Brgy. Dela Paz, Marcos Highway, Pasig City (Car Care Center)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1403,'Diversion Hiway, Brgy. Bangkusay, San Fernando, La Union (Car Care Center)','La Union (Car Care Center)','Diversion Hiway, Brgy. Bangkusay, San Fernando, La Union (Car Care Center)','Region I','SERVICE STATION',3,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1404,'354 Sta. Rita Rd., Sta. Rita, Olongapo City, Zambales (Car Care Center)','Zambales (Car Care Center)','354 Sta. Rita Rd., Sta. Rita, Olongapo City, Zambales (Car Care Center)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1405,'Rotonda, Dinalupihan, Bataan (Car Care Center)','Bataan (Car Care Center)','Rotonda, Dinalupihan, Bataan (Car Care Center)','Region III','SERVICE STATION',5,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1406,'69 D. Tuazon St. Lourdes 1, Quezon City (Car Care Center)','Quezon City (Car Care Center)','69 D. Tuazon St. Lourdes 1, Quezon City (Car Care Center)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1407,'Pasig Blvd/San Ignacio,Capitol 8 Subd, Pasig City (Car Care Center)','Pasig City (Car Care Center)','Pasig Blvd/San Ignacio,Capitol 8 Subd, Pasig City (Car Care Center)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1408,'Metrowalk Complex, Meralco Ave., Brgy. Ugong, Pasig City (Car Care Center)','Pasig City (Car Care Center)','Metrowalk Complex, Meralco Ave., Brgy. Ugong, Pasig City (Car Care Center)','NCR','SERVICE STATION',1,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1409,'Elijah Petron Service Station, Molino Blvd., Bacoor, Cavite (Car Care Center)','Cavite (Car Care Center)','Elijah Petron Service Station, Molino Blvd., Bacoor, Cavite (Car Care Center)','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1410,'Molino ??? Paliparan Road, Promenade South, Brgy. Salawag, Dasmarinas City','Dasmarinas City','Molino ??? Paliparan Road, Promenade South, Brgy. Salawag, Dasmarinas City','Region IV-A','SERVICE STATION',6,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1411,'Corner Hughes And Burgos St., Roxas City, Capiz (Car Care Center)','Capiz (Car Care Center)','Corner Hughes And Burgos St., Roxas City, Capiz (Car Care Center)','Region VI','SERVICE STATION',9,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1412,'New Villanueva Commercial District Corp., National Highway, Villanueva, Misamis Oriental','Misamis Oriental','New Villanueva Commercial District Corp., National Highway, Villanueva, Misamis Oriental','Region X','SERVICE STATION',13,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49'),(1413,'Fs Pajares Ave., San Jose District, Pagadian City, Zamboanga Del Sur','Zamboanga Del Sur','Fs Pajares Ave., San Jose District, Pagadian City, Zamboanga Del Sur','Region IX','SERVICE STATION',12,NULL,NULL,NULL,NULL,NULL,'active','2026-02-07 12:14:22','2026-08-18 13:18:49');
/*!40000 ALTER TABLE `stations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_request_audit`
--

DROP TABLE IF EXISTS `stock_request_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_request_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stock_request_id` int(11) DEFAULT NULL,
  `request_id` int(11) DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_by_role` varchar(50) DEFAULT NULL,
  `old_status` varchar(100) DEFAULT NULL,
  `new_status` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stock_req_id` (`stock_request_id`),
  KEY `idx_req_id` (`request_id`),
  KEY `idx_request_id` (`request_id`),
  CONSTRAINT `fk_stock_request_audit_request_id` FOREIGN KEY (`request_id`) REFERENCES `stock_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_request_audit`
--

LOCK TABLES `stock_request_audit` WRITE;
/*!40000 ALTER TABLE `stock_request_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_request_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_requests`
--

DROP TABLE IF EXISTS `stock_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_sku` varchar(100) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_category` varchar(100) NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `requested_quantity` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(100) DEFAULT 'Pending Manager Review',
  `approved_quantity` int(11) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_manager_id` (`manager_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_id_sr` (`id`),
  CONSTRAINT `fk_stock_req_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_requests_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_requests_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_requests_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_requests`
--

LOCK TABLES `stock_requests` WRITE;
/*!40000 ALTER TABLE `stock_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `registration_details` text DEFAULT NULL,
  `delivery_terms` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supplier_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'Petron Corporation','Petron CDO Sales & Supply Manager','(088) 856-4321 / +63 917 800 7387','cdo.orders@petron.com / contactus@petron.com','Petron Regional Depot & Sales Office, Zone 4, Carmen, Cagayan de Oro City, Misamis Oriental, 9000 Philippines','SEC Reg. No. 31171 | TIN: 000-168-801-000 | CDO Regional Branch','FOB Destination / Net 30 Days / CDO Local Tanker Lorry & Container Delivery','2026-07-01 20:13:52');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sys_health_report_log`
--

DROP TABLE IF EXISTS `sys_health_report_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sys_health_report_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `recorded_date` date NOT NULL,
  `server_status` varchar(20) DEFAULT 'Online',
  `database_status` varchar(20) DEFAULT 'Connected',
  `system_uptime` decimal(5,2) DEFAULT 99.98,
  `cpu_usage` int(11) DEFAULT 22,
  `memory_usage` int(11) DEFAULT 48,
  `disk_usage` int(11) DEFAULT 36,
  `overall_status` varchar(20) DEFAULT 'Healthy',
  `recorded_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_rec_date` (`recorded_date`),
  KEY `station_id` (`station_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `fk_shr_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_shr_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sys_health_report_log`
--

LOCK TABLES `sys_health_report_log` WRITE;
/*!40000 ALTER TABLE `sys_health_report_log` DISABLE KEYS */;
INSERT INTO `sys_health_report_log` VALUES (1,NULL,NULL,'2026-08-21','Online','Connected',99.90,18,40,30,'Healthy','2026-08-21 16:22:46'),(2,NULL,NULL,'2026-08-20','Online','Connected',99.91,21,44,32,'Healthy','2026-08-21 16:22:46'),(3,NULL,NULL,'2026-08-19','Online','Connected',99.92,24,48,34,'Healthy','2026-08-21 16:22:46'),(4,NULL,NULL,'2026-08-18','Online','Connected',99.93,27,52,36,'Healthy','2026-08-21 16:22:46'),(5,NULL,NULL,'2026-08-17','Online','Connected',99.94,30,56,38,'Healthy','2026-08-21 16:22:46'),(6,NULL,NULL,'2026-08-16','Online','Connected',99.95,33,60,40,'Healthy','2026-08-21 16:22:46'),(7,NULL,NULL,'2026-08-15','Online','Connected',99.96,36,64,42,'Healthy','2026-08-21 16:22:46'),(8,NULL,NULL,'2026-08-14','Online','Connected',99.97,39,68,44,'Healthy','2026-08-21 16:22:46'),(9,NULL,NULL,'2026-08-13','Online','Connected',99.98,42,42,31,'Healthy','2026-08-21 16:22:46'),(10,NULL,NULL,'2026-08-12','Online','Connected',99.90,20,46,33,'Healthy','2026-08-21 16:22:46');
/*!40000 ALTER TABLE `sys_health_report_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_config`
--

DROP TABLE IF EXISTS `system_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`),
  KEY `idx_config_key` (`config_key`),
  KEY `fk_syscfg_station` (`station_id`),
  CONSTRAINT `fk_syscfg_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_config`
--

LOCK TABLES `system_config` WRITE;
/*!40000 ALTER TABLE `system_config` DISABLE KEYS */;
INSERT INTO `system_config` VALUES (1,NULL,'backup_frequency','manual','Database backup frequency setting','2026-07-05 18:17:42','2026-08-21 08:52:01'),(2,NULL,'storage_location','local','Backup storage location','2026-07-05 18:17:42','2026-07-05 18:17:42'),(3,NULL,'retention_period','30','Backup retention period in days','2026-07-05 18:17:42','2026-07-05 18:17:42'),(16,NULL,'backup_scheduled_time','02:00',NULL,'2026-08-05 08:20:49','2026-08-21 08:52:01'),(17,NULL,'backup_type','Full Backup',NULL,'2026-08-05 08:20:49','2026-08-21 08:52:01'),(18,NULL,'backup_compression','ZIP',NULL,'2026-08-05 08:20:49','2026-08-21 08:52:01'),(19,NULL,'backup_retention_days','30',NULL,'2026-08-05 08:20:49','2026-08-21 08:52:01');
/*!40000 ALTER TABLE `system_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_error_logs`
--

DROP TABLE IF EXISTS `system_error_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_error_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `error_type` varchar(80) NOT NULL DEFAULT 'General',
  `severity` enum('low','medium','high','critical','warning') NOT NULL DEFAULT 'medium',
  `message` text NOT NULL,
  `stack_trace` text DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_severity_created` (`severity`,`created_at`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_system_error_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_error_logs`
--

LOCK TABLES `system_error_logs` WRITE;
/*!40000 ALTER TABLE `system_error_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_error_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL DEFAULT 0 COMMENT '0=global',
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','json','color') DEFAULT 'text',
  `category` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_station_key` (`station_id`,`setting_key`),
  KEY `idx_category` (`category`),
  KEY `idx_updated_by_auto` (`updated_by`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_system_settings_updated_by_9bc6` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (167,0,'system_name','Petron Station Management System','text','general',NULL,0,1,'2026-08-21 16:22:20'),(168,0,'company_logo','../assets/img/Petron Logo.png','text','general',NULL,0,1,'2026-08-21 16:22:20'),(169,0,'system_version','v1.0.0','text','general',NULL,0,1,'2026-08-21 16:22:20'),(170,0,'timezone','Asia/Manila (UTC+8)','text','regional',NULL,0,1,'2026-08-21 16:22:20'),(171,0,'date_format','YYYY-MM-DD','text','regional',NULL,0,1,'2026-08-21 16:22:20'),(172,0,'time_format','12H','text','regional',NULL,0,1,'2026-08-21 16:22:20'),(173,0,'currency_symbol','PHP (₱)','text','regional',NULL,0,1,'2026-08-21 16:22:20'),(174,0,'theme','Light','text','appearance',NULL,0,1,'2026-08-21 16:22:20'),(175,0,'system_accent_color','#002F6C','text','appearance',NULL,0,1,'2026-08-21 16:22:20'),(176,0,'sidebar_mode','Expanded','text','appearance',NULL,0,1,'2026-08-21 16:22:20'),(177,0,'dashboard_auto_refresh','30','text','appearance',NULL,0,1,'2026-08-21 16:22:20'),(178,0,'session_timeout','30','text','security',NULL,0,1,'2026-08-21 16:22:20'),(179,0,'min_password_length','8','text','security',NULL,0,1,'2026-08-21 16:22:20'),(180,0,'max_login_attempts','5','text','security',NULL,0,1,'2026-08-21 16:22:20'),(181,0,'require_uppercase','1','text','security',NULL,0,1,'2026-08-21 16:22:20'),(182,0,'require_numbers','1','text','security',NULL,0,1,'2026-08-21 16:22:20'),(183,0,'require_special_chars','1','text','security',NULL,0,1,'2026-08-21 16:22:20'),(184,0,'banner_duration','5','text','notification',NULL,0,1,'2026-08-21 16:22:20'),(185,0,'enable_system_notifications','1','text','notification',NULL,0,1,'2026-08-21 16:22:20'),(186,0,'enable_error_notifications','1','text','notification',NULL,0,1,'2026-08-21 16:22:20'),(187,0,'default_paper_size','A4','text','reports',NULL,0,1,'2026-08-21 16:22:20'),(188,0,'default_orientation','Portrait','text','reports',NULL,0,1,'2026-08-21 16:22:20'),(189,0,'show_company_logo_reports','1','text','reports',NULL,0,1,'2026-08-21 16:22:20'),(190,0,'show_report_footer','1','text','reports',NULL,0,1,'2026-08-21 16:22:20'),(191,0,'maintenance_mode','0','text','maintenance',NULL,0,1,'2026-08-21 16:22:20'),(192,0,'system_status','Online','text','maintenance',NULL,0,1,'2026-08-21 16:22:20'),(193,0,'last_system_update','2026-08-06 22:30:00','text','maintenance',NULL,0,1,'2026-08-21 16:22:20');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings_audit`
--

DROP TABLE IF EXISTS `system_settings_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_by_name` varchar(120) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_key_created` (`setting_key`,`created_at`),
  KEY `idx_changed_by` (`changed_by`),
  CONSTRAINT `fk_system_settings_audit_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings_audit`
--

LOCK TABLES `system_settings_audit` WRITE;
/*!40000 ALTER TABLE `system_settings_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_settings_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction_adjustments`
--

DROP TABLE IF EXISTS `transaction_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaction_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(50) NOT NULL,
  `transaction_type` enum('job_order','merchandise','combined') NOT NULL DEFAULT 'merchandise',
  `customer_name` varchar(255) DEFAULT NULL,
  `original_amount` decimal(10,2) NOT NULL,
  `updated_amount` decimal(10,2) NOT NULL,
  `amount_difference` decimal(10,2) NOT NULL,
  `adjustment_reason` varchar(255) NOT NULL,
  `manager_remarks` text DEFAULT NULL,
  `adjusted_by` int(11) NOT NULL,
  `adjustment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `station_id` int(11) NOT NULL,
  `fields_changed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields_changed`)),
  PRIMARY KEY (`id`),
  KEY `idx_adj_txn` (`transaction_id`),
  KEY `idx_adj_date` (`adjustment_date`),
  KEY `idx_adj_station` (`station_id`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_transaction_adjustments_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction_adjustments`
--

LOCK TABLES `transaction_adjustments` WRITE;
/*!40000 ALTER TABLE `transaction_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `transaction_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction_requests`
--

DROP TABLE IF EXISTS `transaction_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaction_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL,
  `transaction_id` varchar(255) NOT NULL,
  `record_source` varchar(60) NOT NULL DEFAULT 'merchandise_transactions',
  `request_type` enum('Adjustment','Void') NOT NULL,
  `request_reason` text DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime DEFAULT current_timestamp(),
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `new_amount` decimal(12,2) DEFAULT NULL,
  `correction_field` varchar(100) DEFAULT NULL,
  `current_value` text DEFAULT NULL,
  `requested_value` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `station_id` (`station_id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `status` (`status`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_transaction_requests_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_requests_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction_requests`
--

LOCK TABLES `transaction_requests` WRITE;
/*!40000 ALTER TABLE `transaction_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `transaction_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ui_config`
--

DROP TABLE IF EXISTS `ui_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ui_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `config_type` varchar(20) DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`),
  KEY `fk_uicfg_station` (`station_id`),
  CONSTRAINT `fk_uicfg_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2969 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ui_config`
--

LOCK TABLES `ui_config` WRITE;
/*!40000 ALTER TABLE `ui_config` DISABLE KEYS */;
INSERT INTO `ui_config` VALUES (1,NULL,'modal_max_width','600','string','Maximum width of modal dialogs in pixels','2026-04-14 03:15:32','2026-04-14 03:15:32'),(2,NULL,'modal_max_height_vh','90','string','Maximum height of modal dialogs in viewport height units','2026-04-14 03:15:32','2026-04-14 03:15:32'),(3,NULL,'station_selector_max_height','300','string','Maximum height of station selector in pixels','2026-04-14 03:15:32','2026-04-14 03:15:32'),(4,NULL,'station_selector_padding','12','string','Padding for station selector in pixels','2026-04-14 03:15:32','2026-04-14 03:15:32'),(5,NULL,'station_selector_gap','8','string','Gap between station selector items in pixels','2026-04-14 03:15:32','2026-04-14 03:15:32'),(6,NULL,'typeahead_max_height','220','string','Maximum height of typeahead suggestions in pixels','2026-04-14 03:15:32','2026-04-14 03:15:32'),(7,NULL,'modal_body_padding','24px 20px','string','Padding for modal body','2026-04-14 03:15:32','2026-04-14 03:15:32'),(8,NULL,'modal_footer_height_offset','140','string','Height offset for modal footer calculations','2026-04-14 03:15:32','2026-04-14 03:15:32');
/*!40000 ALTER TABLE `ui_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_form_drafts`
--

DROP TABLE IF EXISTS `user_form_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_form_drafts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `station_id` int(11) DEFAULT NULL,
  `module_key` varchar(100) NOT NULL,
  `draft_key` varchar(150) NOT NULL,
  `form_data` longtext NOT NULL,
  `status` enum('draft','submitted','discarded') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_module` (`user_id`,`module_key`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_station_module` (`station_id`,`module_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_user_form_drafts_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_form_drafts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=637 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_form_drafts`
--

LOCK TABLES `user_form_drafts` WRITE;
/*!40000 ALTER TABLE `user_form_drafts` DISABLE KEYS */;
INSERT INTO `user_form_drafts` VALUES (2,13,1253,'form_staff_transactions_hub_merchandise_updateStatusForm','draft_13_form_staff_transactions_hub_merchandise_updateStatusForm','{\"jo_action\":\"update_status\",\"updateStatusJOId\":\"\",\"updateStatusJOSource\":\"\",\"updateStatusSelect\":\"\",\"updateStatusRemarks\":\"\"}','draft','2026-08-21 12:37:08','2026-08-21 15:34:29'),(3,13,1253,'form_staff_transactions_hub_merchandise_adjustJobOrderForm','draft_13_form_staff_transactions_hub_merchandise_adjustJobOrderForm','{\"jo_action\":\"adjust_job_order\",\"adjustJOId\":\"\",\"adjustJOSource\":\"\",\"adjustJOCustomer\":\"\",\"adjustJOPlate\":\"\",\"adjustJOType\":\"\",\"adjustJOService\":\"\",\"adjustJODescription\":\"\",\"adjustJOMechanic\":\"\",\"adjustJOCost\":\"\",\"adjustJODuration\":\"\"}','draft','2026-08-21 12:37:08','2026-08-21 15:34:29'),(10,13,1253,'fuel_meter_readings_fuel','draft_13_fuel_meter_readings_fuel','{\"action\":\"encode_reading\",\"api_token\":\"5258eece10c310ff681d44ae4f1460cce95a24915b8ea059e86c83c49296a3a8\",\"auth_user_id\":\"13\",\"shift_id\":\"0\",\"staff_id\":\"13\",\"station_id\":\"1253\",\"fuel_type\":\"Xtra UNL 1 (UGT #4)\",\"tanker_number\":\"4\",\"pump_label\":\"XTRA UNL 2 - 4\",\"shift_period\":\"first\",\"shift_name\":\"First Shift: 6:00 AM - 2:00 PM\",\"reading_date\":\"2026-08-21\",\"beginning_fuel_Diesel_1_0_t1\":\"\",\"ending_fuel_Diesel_1_0_t1\":\"\",\"cal_fuel_Diesel_1_0_t1\":\"0.00\",\"price_fuel_Diesel_1_0_t1\":\"89.00\",\"volume_fuel_Diesel_1_0_t1\":\"0.00\",\"volume_value_fuel_Diesel_1_0_t1\":\"0.00\",\"amount_fuel_Diesel_1_0_t1\":\"₱0.00\",\"amount_value_fuel_Diesel_1_0_t1\":\"0.00\",\"beginning_fuel_Diesel_1_0_t2\":\"\",\"ending_fuel_Diesel_1_0_t2\":\"\",\"cal_fuel_Diesel_1_0_t2\":\"0.00\",\"price_fuel_Diesel_1_0_t2\":\"89.00\",\"volume_fuel_Diesel_1_0_t2\":\"0.00\",\"volume_value_fuel_Diesel_1_0_t2\":\"0.00\",\"amount_fuel_Diesel_1_0_t2\":\"₱0.00\",\"amount_value_fuel_Diesel_1_0_t2\":\"0.00\",\"beginning_fuel_Diesel_1_0_t3\":\"\",\"ending_fuel_Diesel_1_0_t3\":\"\",\"cal_fuel_Diesel_1_0_t3\":\"0.00\",\"price_fuel_Diesel_1_0_t3\":\"89.00\",\"volume_fuel_Diesel_1_0_t3\":\"0.00\",\"volume_value_fuel_Diesel_1_0_t3\":\"0.00\",\"amount_fuel_Diesel_1_0_t3\":\"₱0.00\",\"amount_value_fuel_Diesel_1_0_t3\":\"0.00\",\"beginning_fuel_Diesel_1_0_t4\":\"\",\"ending_fuel_Diesel_1_0_t4\":\"\",\"cal_fuel_Diesel_1_0_t4\":\"0.00\",\"price_fuel_Diesel_1_0_t4\":\"89.00\",\"volume_fuel_Diesel_1_0_t4\":\"0.00\",\"volume_value_fuel_Diesel_1_0_t4\":\"0.00\",\"amount_fuel_Diesel_1_0_t4\":\"₱0.00\",\"amount_value_fuel_Diesel_1_0_t4\":\"0.00\",\"beginning_fuel_Diesel_2_0_t5\":\"\",\"ending_fuel_Diesel_2_0_t5\":\"\",\"cal_fuel_Diesel_2_0_t5\":\"0.00\",\"price_fuel_Diesel_2_0_t5\":\"89.00\",\"volume_fuel_Diesel_2_0_t5\":\"0.00\",\"volume_value_fuel_Diesel_2_0_t5\":\"0.00\",\"amount_fuel_Diesel_2_0_t5\":\"₱0.00\",\"amount_value_fuel_Diesel_2_0_t5\":\"0.00\",\"beginning_fuel_Diesel_2_0_t6\":\"\",\"ending_fuel_Diesel_2_0_t6\":\"\",\"cal_fuel_Diesel_2_0_t6\":\"0.00\",\"price_fuel_Diesel_2_0_t6\":\"89.00\",\"volume_fuel_Diesel_2_0_t6\":\"0.00\",\"volume_value_fuel_Diesel_2_0_t6\":\"0.00\",\"amount_fuel_Diesel_2_0_t6\":\"₱0.00\",\"amount_value_fuel_Diesel_2_0_t6\":\"0.00\",\"beginning_fuel_Kerosene_2_t1\":\"\",\"ending_fuel_Kerosene_2_t1\":\"\",\"cal_fuel_Kerosene_2_t1\":\"0.00\",\"price_fuel_Kerosene_2_t1\":\"91.00\",\"volume_fuel_Kerosene_2_t1\":\"0.00\",\"volume_value_fuel_Kerosene_2_t1\":\"0.00\",\"amount_fuel_Kerosene_2_t1\":\"₱0.00\",\"amount_value_fuel_Kerosene_2_t1\":\"0.00\",\"beginning_fuel_Turbo_Diesel_3_t1\":\"\",\"ending_fuel_Turbo_Diesel_3_t1\":\"\",\"cal_fuel_Turbo_Diesel_3_t1\":\"0.00\",\"price_fuel_Turbo_Diesel_3_t1\":\"80.00\",\"volume_fuel_Turbo_Diesel_3_t1\":\"0.00\",\"volume_value_fuel_Turbo_Diesel_3_t1\":\"0.00\",\"amount_fuel_Turbo_Diesel_3_t1\":\"₱0.00\",\"amount_value_fuel_Turbo_Diesel_3_t1\":\"0.00\",\"beginning_fuel_Turbo_Diesel_3_t2\":\"\",\"ending_fuel_Turbo_Diesel_3_t2\":\"\",\"cal_fuel_Turbo_Diesel_3_t2\":\"0.00\",\"price_fuel_Turbo_Diesel_3_t2\":\"80.00\",\"volume_fuel_Turbo_Diesel_3_t2\":\"0.00\",\"volume_value_fuel_Turbo_Diesel_3_t2\":\"0.00\",\"amount_fuel_Turbo_Diesel_3_t2\":\"₱0.00\",\"amount_value_fuel_Turbo_Diesel_3_t2\":\"0.00\",\"beginning_fuel_XCS_Plus_4_t1\":\"\",\"ending_fuel_XCS_Plus_4_t1\":\"\",\"cal_fuel_XCS_Plus_4_t1\":\"0.00\",\"price_fuel_XCS_Plus_4_t1\":\"82.00\",\"volume_fuel_XCS_Plus_4_t1\":\"0.00\",\"volume_value_fuel_XCS_Plus_4_t1\":\"0.00\",\"amount_fuel_XCS_Plus_4_t1\":\"₱0.00\",\"amount_value_fuel_XCS_Plus_4_t1\":\"0.00\",\"beginning_fuel_XCS_Plus_4_t2\":\"\",\"ending_fuel_XCS_Plus_4_t2\":\"\",\"cal_fuel_XCS_Plus_4_t2\":\"0.00\",\"price_fuel_XCS_Plus_4_t2\":\"82.00\",\"volume_fuel_XCS_Plus_4_t2\":\"0.00\",\"volume_value_fuel_XCS_Plus_4_t2\":\"0.00\",\"amount_fuel_XCS_Plus_4_t2\":\"₱0.00\",\"amount_value_fuel_XCS_Plus_4_t2\":\"0.00\",\"beginning_fuel_XCS_Plus_4_t3\":\"\",\"ending_fuel_XCS_Plus_4_t3\":\"\",\"cal_fuel_XCS_Plus_4_t3\":\"0.00\",\"price_fuel_XCS_Plus_4_t3\":\"82.00\",\"volume_fuel_XCS_Plus_4_t3\":\"0.00\",\"volume_value_fuel_XCS_Plus_4_t3\":\"0.00\",\"amount_fuel_XCS_Plus_4_t3\":\"₱0.00\",\"amount_value_fuel_XCS_Plus_4_t3\":\"0.00\",\"beginning_fuel_XCS_Plus_4_t4\":\"\",\"ending_fuel_XCS_Plus_4_t4\":\"\",\"cal_fuel_XCS_Plus_4_t4\":\"0.00\",\"price_fuel_XCS_Plus_4_t4\":\"82.00\",\"volume_fuel_XCS_Plus_4_t4\":\"0.00\",\"volume_value_fuel_XCS_Plus_4_t4\":\"0.00\",\"amount_fuel_XCS_Plus_4_t4\":\"₱0.00\",\"amount_value_fuel_XCS_Plus_4_t4\":\"0.00\",\"beginning_fuel_XTRA_UNL_1_5_t1\":\"\",\"ending_fuel_XTRA_UNL_1_5_t1\":\"\",\"cal_fuel_XTRA_UNL_1_5_t1\":\"0.00\",\"price_fuel_XTRA_UNL_1_5_t1\":\"72.00\",\"volume_fuel_XTRA_UNL_1_5_t1\":\"0.00\",\"volume_value_fuel_XTRA_UNL_1_5_t1\":\"0.00\",\"amount_fuel_XTRA_UNL_1_5_t1\":\"₱0.00\",\"amount_value_fuel_XTRA_UNL_1_5_t1\":\"0.00\",\"beginning_fuel_XTRA_UNL_1_5_t2\":\"\",\"ending_fuel_XTRA_UNL_1_5_t2\":\"\",\"cal_fuel_XTRA_UNL_1_5_t2\":\"0.00\",\"price_fuel_XTRA_UNL_1_5_t2\":\"72.00\",\"volume_fuel_XTRA_UNL_1_5_t2\":\"0.00\",\"volume_value_fuel_XTRA_UNL_1_5_t2\":\"0.00\",\"amount_fuel_XTRA_UNL_1_5_t2\":\"₱0.00\",\"amount_value_fuel_XTRA_UNL_1_5_t2\":\"0.00\",\"beginning_fuel_XTRA_UNL_2_5_t3\":\"\",\"ending_fuel_XTRA_UNL_2_5_t3\":\"\",\"cal_fuel_XTRA_UNL_2_5_t3\":\"0.00\",\"price_fuel_XTRA_UNL_2_5_t3\":\"72.00\",\"volume_fuel_XTRA_UNL_2_5_t3\":\"0.00\",\"volume_value_fuel_XTRA_UNL_2_5_t3\":\"0.00\",\"amount_fuel_XTRA_UNL_2_5_t3\":\"₱0.00\",\"amount_value_fuel_XTRA_UNL_2_5_t3\":\"0.00\",\"beginning_fuel_XTRA_UNL_2_5_t4\":\"\",\"ending_fuel_XTRA_UNL_2_5_t4\":\"\",\"cal_fuel_XTRA_UNL_2_5_t4\":\"0.00\",\"price_fuel_XTRA_UNL_2_5_t4\":\"72.00\",\"volume_fuel_XTRA_UNL_2_5_t4\":\"0.00\",\"volume_value_fuel_XTRA_UNL_2_5_t4\":\"0.00\",\"amount_fuel_XTRA_UNL_2_5_t4\":\"₱0.00\",\"amount_value_fuel_XTRA_UNL_2_5_t4\":\"0.00\"}','draft','2026-08-21 12:37:15','2026-08-21 12:50:58'),(20,13,1253,'transaction_adjustment','draft_13_transaction_adjustment','{\"adj_product_id\":\"\",\"adj_type\":\"\",\"adj_action\":\"Decrease\",\"adj_manual_direction\":\"Decrease\",\"adj_quantity\":\"\",\"adj_reason\":\"\",\"adj_remarks\":\"\"}','draft','2026-08-21 12:37:34','2026-08-21 13:06:20'),(32,3,1253,'form_manager_inventory_merchandise_0','draft_3_form_manager_inventory_merchandise_0','{\"action\":\"approve_request\",\"modalApproveId\":\"\",\"modalApproveQty\":\"\",\"manager_notes\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(33,3,1253,'form_manager_inventory_merchandise_2','draft_3_form_manager_inventory_merchandise_2','{\"action\":\"update_product\",\"editProdId\":\"\",\"editProdName\":\"\",\"editProdCategory\":\"\",\"editProdUnit\":\"\",\"editProdReorder\":\"\",\"editProdCritical\":\"\",\"editProdCapacity\":\"\",\"editProdPrice\":\"\",\"editProdCost\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(34,3,1253,'form_manager_inventory_merchandise_5','draft_3_form_manager_inventory_merchandise_5','{\"action\":\"reject_merchandise_adjustment\",\"rejectAdjId\":\"\",\"rejection_reason\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(35,3,1253,'form_manager_inventory_merchandise_6','draft_3_form_manager_inventory_merchandise_6','{\"action\":\"approve_request\",\"approveReqId\":\"\",\"approveReqQty\":\"\",\"manager_notes\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(36,3,1253,'form_manager_inventory_merchandise_7','draft_3_form_manager_inventory_merchandise_7','{\"action\":\"reject_request\",\"rejectReqId\":\"\",\"manager_notes\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(37,3,1253,'form_manager_inventory_merchandise_8','draft_3_form_manager_inventory_merchandise_8','{\"action\":\"validate_delivery\",\"validatePoId\":\"\",\"validateActualQty\":\"\",\"delivery_flag\":\"OK\",\"delivery_notes\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(38,3,1253,'form_manager_inventory_merchandise_9','draft_3_form_manager_inventory_merchandise_9','{\"action\":\"flag_delivery_issue\",\"flagPoId\":\"\",\"delivery_flag\":\"Short\",\"delivery_notes\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(39,3,1253,'form_manager_inventory_merchandise_10','draft_3_form_manager_inventory_merchandise_10','{\"action\":\"create_stock_request\",\"srProductId\":\"\",\"srRequestedQty\":\"\",\"remarks\":\"\"}','draft','2026-08-21 12:42:42','2026-08-21 12:42:45'),(112,3,1253,'form_manager_mechanics_management_[object HTMLInputElement]','draft_3_form_manager_mechanics_management_[object HTMLInputElement]','{\"formAction\":\"edit\",\"formId\":\"16\",\"field_mechanic_id\":\"MEC-0016\",\"field_first_name\":\"Jun\",\"field_middle_name\":\"\",\"field_last_name\":\"Mant\",\"field_contact\":\"09741254896\",\"field_date_hired\":\"2026-07-01\",\"field_address\":\"\",\"field_specialty\":\"General Mechanic\",\"field_shift\":\"First Shift\",\"field_status\":\"active\"}','draft','2026-08-21 13:06:23','2026-08-21 13:35:14'),(436,3,1253,'form_manager_fuel_transaction_validation_[object HTMLInputElement]','draft_3_form_manager_fuel_transaction_validation_[object HTMLInputElement]','{\"action\":\"reject\",\"rej_id_field\":\"\",\"remarks\":\"\"}','draft','2026-08-21 13:40:25','2026-08-21 13:47:32'),(442,3,1253,'form_manager_fuel_pump_master_[object HTMLInputElement]','draft_3_form_manager_fuel_pump_master_[object HTMLInputElement]','{\"action\":\"reject\",\"reject_tx_id\":\"\",\"remarks\":\"\"}','draft','2026-08-21 13:40:26','2026-08-21 13:49:51'),(472,4,1253,'user_creation_form','draft_4_user_creation_form','{\"action\":\"add_user\",\"add_first_name\":\"\",\"add_last_name\":\"\",\"add_contact_number\":\"\",\"add_email\":\"\",\"add_username\":\"\",\"user_role_add\":\"\",\"add_assigned_shift\":\"\",\"new_password\":\"\"}','draft','2026-08-21 13:48:53','2026-08-21 13:55:33'),(480,4,1253,'form_users_2','draft_4_form_users_2','{\"action\":\"reset_password\",\"reset_user_id\":\"\",\"reset_password_field\":\"\"}','draft','2026-08-21 13:51:57','2026-08-21 13:55:29'),(505,4,1253,'form_users_archived_2','draft_4_form_users_archived_2','{\"action\":\"reset_password\",\"reset_user_id\":\"\",\"reset_password_field\":\"\"}','draft','2026-08-21 13:55:31','2026-08-21 13:55:32'),(514,4,1253,'form_users_active_2','draft_4_form_users_active_2','{\"action\":\"reset_password\",\"reset_user_id\":\"\",\"reset_password_field\":\"\"}','draft','2026-08-21 13:55:32','2026-08-21 13:55:33'),(520,4,1253,'form_admin_set_prices_fuel_rejectForm','draft_4_form_admin_set_prices_fuel_rejectForm','{\"action\":\"reject_price\",\"rejectApprovalId\":\"\",\"rejectActiveTab\":\"fuel\",\"remarks\":\"\"}','draft','2026-08-21 14:08:52','2026-08-21 14:08:53'),(521,4,1253,'form_admin_set_prices_fuel_[object HTMLInputElement]','draft_4_form_admin_set_prices_fuel_[object HTMLInputElement]','{\"action\":\"admin_edit_fuel_direct\",\"active_tab\":\"fuel\",\"aef_fuel_id\":\"6\",\"aef_ugt_no\":\"UGT #1\",\"aef_fuel_name\":\"Diesel\",\"aef_price\":\"89.00\",\"aef_capacity\":\"14000\",\"aef_critical\":\"2500\",\"aef_reorder\":\"5000\",\"aef_status_active\":\"active\"}','draft','2026-08-21 14:08:52','2026-08-21 14:08:53'),(522,4,1253,'form_admin_set_prices_fuel_5','draft_4_form_admin_set_prices_fuel_5','{\"action\":\"reject_price\",\"active_tab\":\"fuel\",\"adminRejectApprovalId\":\"\",\"adminRejectRemarks\":\"\"}','draft','2026-08-21 14:08:52','2026-08-21 14:08:53'),(547,3,1253,'add_merchandise_product_modal','draft_3_add_merchandise_product_modal','{\"newMerchName\":\"\",\"newMerchSku\":\"\",\"newMerchCategory\":\"\",\"newMerchBrand\":\"\",\"newMerchSize\":\"\",\"newMerchBarcode\":\"\",\"newMerchPrice\":\"\",\"newMerchReorder\":\"24\",\"newMerchCritical\":\"10\"}','draft','2026-08-21 14:15:37','2026-08-21 14:44:35'),(548,3,1253,'add_service_modal','draft_3_add_service_modal','{\"addSvcName\":\"\",\"addSvcCategory\":\"\",\"addSvcCustomCategory\":\"\",\"addSvcServiceFee\":\"\",\"addSvcLaborFee\":\"\",\"addSvcDuration\":\"60\",\"addSvcMechanics\":\"1\",\"addSvcDescription\":\"\"}','draft','2026-08-21 14:15:37','2026-08-21 14:44:35'),(549,3,1253,'edit_service_modal','draft_3_edit_service_modal','{\"editSvcId\":\"97\",\"editSvcName\":\"Aircon Cleaning\",\"editSvcCategory\":\"Air Conditioning\",\"editSvcCustomCategory\":\"\",\"editSvcServiceFee\":\"600.00\",\"editSvcLaborFee\":\"1800.00\",\"editSvcDuration\":\"180\",\"editSvcMechanics\":\"2\",\"editSvcDescription\":\"\",\"editSvcActive\":\"1\"}','draft','2026-08-21 14:15:37','2026-08-21 14:44:34'),(556,4,1253,'form_admin_set_prices_merch_5','draft_4_form_admin_set_prices_merch_5','{\"action\":\"reject_price\",\"active_tab\":\"fuel\",\"adminRejectApprovalId\":\"\",\"adminRejectRemarks\":\"\"}','draft','2026-08-21 14:44:34','2026-08-21 14:44:34'),(558,4,1253,'form_admin_set_prices_merch_[object HTMLInputElement]','draft_4_form_admin_set_prices_merch_[object HTMLInputElement]','{\"action\":\"admin_edit_fuel_direct\",\"active_tab\":\"fuel\",\"aef_fuel_id\":\"\",\"aef_ugt_no\":\"\",\"aef_fuel_name\":\"\",\"aef_price\":\"\",\"aef_capacity\":\"\",\"aef_critical\":\"\",\"aef_reorder\":\"\",\"aef_status_active\":\"active\"}','draft','2026-08-21 14:44:35','2026-08-21 14:44:35'),(561,4,1253,'form_admin_set_prices_merch_rejectForm','draft_4_form_admin_set_prices_merch_rejectForm','{\"action\":\"reject_price\",\"rejectApprovalId\":\"\",\"rejectActiveTab\":\"fuel\",\"remarks\":\"\"}','draft','2026-08-21 14:44:35','2026-08-21 14:44:35'),(562,13,1253,'form_profile_editProfileForm','draft_13_form_profile_editProfileForm','{\"action\":\"update_profile\",\"first_name\":\"Yyeng\",\"last_name\":\"C.\",\"phone\":\"09565232510\"}','draft','2026-08-21 15:03:33','2026-08-21 15:03:38'),(570,13,1253,'pos_merchandise_joborder_merchandise','draft_13_pos_merchandise_joborder_merchandise','{\"searchInput\":\"\",\"joCustomerTypeRegistered\":\"registered\",\"joFirstName\":\"\",\"joLastName\":\"\",\"joContactNumber\":\"\",\"joVehicleType\":\"\",\"joVehiclePlate\":\"\",\"joVehicleBrand\":\"\",\"joVehicleModel\":\"\",\"joYearModel\":\"\",\"joOdometer\":\"\",\"joEngineNumber\":\"\",\"joChassisNumber\":\"\",\"joNumber\":\"\",\"joDate\":\"2026-08-21\",\"joPriorityNormal\":\"Normal\",\"joExpectedRelease\":\"\",\"joInspect_Engine\":false,\"joInspect_Battery\":false,\"joInspect_Tires\":false,\"joInspect_Brakes\":false,\"joInspect_Lights\":false,\"joInspect_Cooling_System\":false,\"joInspect_Suspension\":false,\"joInspect_Transmission_Fluid\":false,\"joInspect_Air_Filter\":false,\"joInspect_Wipers___Washers\":false,\"joInspect_Belts___Hoses\":false,\"joInspect_Steering_System\":false,\"joInspect_Exhaust_System\":false,\"joInspectionRemarks\":\"\",\"joCustomerComplaint\":\"\",\"joRepairRecommendation\":\"\",\"joServiceType\":\"\",\"joServiceTypeValue\":\"\",\"joServicePrice\":\"\",\"joLaborCharge\":\"\",\"joServiceCategory\":\"\",\"joMechanic\":\"\",\"joMechanicId\":\"\",\"joMechanicName\":\"\",\"joEstimatedDuration\":\"\",\"joNotes\":\"\",\"merchCustomerTypeRegistered\":\"registered\",\"merchFirstName\":\"\",\"merchLastName\":\"\",\"merchContactNumber\":\"\",\"productSearch\":\"\",\"productSelect\":\"\",\"itemQty\":\"1\",\"itemSku\":\"\",\"itemCategory\":\"\",\"itemUnitPrice\":\"\",\"itemStock\":\"\",\"section\":\"merchandise\",\"mh_open\":\"1\",\"mh_type\":\"all\",\"mh_start_date\":\"\",\"mh_end_date\":\"\",\"mh_category\":\"\",\"mh_product\":\"\",\"mh_status\":\"\",\"paymentMethod\":\"\",\"amountTendered\":\"\",\"changeAmount\":\"\",\"cashBalanceDue\":\"\",\"ccAmount\":\"\",\"ccType\":\"Visa\",\"ccLastFour\":\"\",\"ccRefNumber\":\"\",\"dcAmount\":\"\",\"dcType\":\"Visa\",\"dcRefNumber\":\"\",\"ewAmount\":\"\",\"ewProvider\":\"GCash\",\"ewRefNumber\":\"\",\"fcAmount\":\"\",\"fcNumber\":\"\",\"fcCompanyName\":\"\",\"fcAuthNumber\":\"\",\"efAmount\":\"\",\"efCardNumber\":\"\",\"efRefNumber\":\"\",\"creditCustomer\":\"\",\"creditCompanyName\":\"\",\"creditAccountNumber\":\"\",\"creditPoNumber\":\"\",\"creditDueDate\":\"\",\"generalBalanceDue\":\"\",\"loyaltyProgram\":\"No Loyalty\",\"loyaltyCardNo\":\"\",\"loyaltyPointsBalance\":\"0\",\"loyaltyPointsEarned\":\"0\",\"loyaltyPointsRedeemed\":\"0\",\"loyaltyPointsAfter\":\"0\",\"newServiceName\":\"\",\"newServiceCategory\":\"Others\",\"newServicePrice\":\"\",\"newServiceDuration\":\"\",\"newServiceDescription\":\"\",\"newServiceReason\":\"\",\"newVehicleBrand\":\"\",\"newVehicleModel\":\"\",\"newVehicleType\":\"\",\"newVehicleFuelType\":\"Gasoline\",\"newVehicleRemarks\":\"\",\"newProductCategory\":\"\",\"newProductName\":\"\",\"newProductSKU\":\"\",\"newProductUnit\":\"\",\"newProductPrice\":\"\",\"newProductReason\":\"\",\"joSearchInput\":\"\",\"joFilterType\":\"all\",\"joFilterStartDate\":\"\",\"joFilterEndDate\":\"\",\"joFilterStatus\":\"all\",\"joFilterMechanic\":\"\",\"joFilterServiceType\":\"\",\"jo_action\":\"adjust_job_order\",\"pmJoId\":\"\",\"pmJoSource\":\"\",\"pmRedirectTab\":\"tracker\",\"pmMarkComplete\":\"\",\"pmMethodHidden\":\"Cash\",\"pmAmountInput\":\"\",\"pmTendered\":\"\",\"pmRefInput\":\"\",\"pmRemarks\":\"\",\"updateStatusJOId\":\"\",\"updateStatusJOSource\":\"\",\"updateStatusSelect\":\"\",\"updateStatusRemarks\":\"\",\"adjustJOId\":\"\",\"adjustJOSource\":\"\",\"adjustJOCustomer\":\"\",\"adjustJOPlate\":\"\",\"adjustJOType\":\"\",\"adjustJOService\":\"\",\"adjustJODescription\":\"\",\"adjustJOMechanic\":\"\",\"adjustJOCost\":\"\",\"adjustJODuration\":\"\",\"reqAdjTxnId\":\"\",\"reqAdjRecordSource\":\"\",\"reqAdjType\":\"Adjustment\",\"reqAdjCorrectionField\":\"Labor Fee\",\"reqAdjCurrentValue\":\"\",\"reqAdjRequestedValue\":\"\",\"reqAdjReason\":\"\",\"reqAdjRemarks\":\"\",\"reqVoidTxnId\":\"\",\"reqVoidRecordSource\":\"\",\"reqVoidType\":\"Void\",\"reqVoidReasonSelect\":\"Duplicate Transaction\",\"reqVoidRemarks\":\"\",\"txnRequestTxnId\":\"\",\"txnRequestRecordSource\":\"\",\"txnRequestType\":\"\",\"txnRequestNewAmount\":\"\",\"txnRequestReason\":\"\"}','draft','2026-08-21 15:03:44','2026-08-21 15:34:29'),(576,1,1253,'form_database_management_backup_0','draft_1_form_database_management_backup_0','{\"tab\":\"security\",\"action\":\"save_backup_config\",\"backup_frequency\":\"manual\",\"scheduled_time\":\"02:00\",\"backup_type\":\"Full Backup\",\"compression\":\"ZIP\",\"retention_days\":\"30\"}','draft','2026-08-21 15:54:59','2026-08-21 16:52:09'),(577,1,1253,'form_database_management_backup_2','draft_1_form_database_management_backup_2','{\"action\":\"apply_migration\",\"table_name\":\"\",\"migration_action\":\"add_column\",\"column_name\":\"\",\"data_type\":\"VARCHAR(255)\",\"description\":\"\"}','draft','2026-08-21 15:54:59','2026-08-21 16:52:09'),(578,1,1253,'form_database_management_backup_4','draft_1_form_database_management_backup_4','{\"tab\":\"restore\",\"action\":\"restore\",\"restore_backup_id\":\"\",\"restore_backup_display\":\"\",\"restore_confirm_text\":\"yangc.developer@gmail.com\"}','draft','2026-08-21 15:55:00','2026-08-21 16:52:09');
/*!40000 ALTER TABLE `user_form_drafts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_preferences`
--

DROP TABLE IF EXISTS `user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `preference_key` varchar(100) NOT NULL,
  `preference_value` varchar(200) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_pref` (`user_id`,`preference_key`),
  UNIQUE KEY `uq_user_pref` (`user_id`,`preference_key`),
  CONSTRAINT `fk_user_preferences_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_preferences`
--

LOCK TABLES `user_preferences` WRITE;
/*!40000 ALTER TABLE `user_preferences` DISABLE KEYS */;
INSERT INTO `user_preferences` VALUES (1,13,'badge_seen_staff_new_transaction','2026-08-21 15:03:42','2026-08-21 04:37:01','2026-08-21 07:03:42'),(9,3,'badge_seen_validated_transactions_manager','2026-08-21 13:21:27','2026-08-21 04:51:07','2026-08-21 05:21:27'),(10,3,'badge_seen_manager_request_data_management','2026-08-21 12:54:23','2026-08-21 04:54:22','2026-08-21 04:54:23'),(15,3,'badge_seen_fuel_transactions','2026-08-21 13:47:30','2026-08-21 05:35:13','2026-08-21 05:47:30'),(16,3,'badge_seen_fuel_transactions_validation','2026-08-21 13:47:30','2026-08-21 05:35:13','2026-08-21 05:47:30'),(27,4,'badge_seen_admin_fuel_transactions_oversight','2026-08-21 14:12:33','2026-08-21 06:07:07','2026-08-21 06:12:33');
/*!40000 ALTER TABLE `user_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(20) DEFAULT NULL COMMENT 'Employee ID with role prefix (ADM-001, MGR-001, STF-001)',
  `first_name` varchar(100) NOT NULL DEFAULT '',
  `last_name` varchar(100) NOT NULL DEFAULT '',
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','manager','staff') NOT NULL DEFAULT 'staff',
  `assigned_shift` enum('Shift 1','Shift 2','Shift 3','All Shifts') DEFAULT NULL COMMENT 'Assigned shift for staff members',
  `shift_assignment` varchar(50) DEFAULT NULL,
  `shift_start_time` time DEFAULT NULL COMMENT 'Shift start time (e.g., 06:00:00)',
  `shift_end_time` time DEFAULT NULL COMMENT 'Shift end time (e.g., 14:00:00)',
  `email` varchar(150) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `name` varchar(255) GENERATED ALWAYS AS (trim(concat(coalesce(`first_name`,''),' ',coalesce(`last_name`,'')))) STORED,
  `profile_picture` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_employee_id` (`employee_id`),
  KEY `fk_user_station` (`station_id`),
  KEY `idx_id_u` (`id`),
  KEY `idx_assigned_shift` (`assigned_shift`),
  CONSTRAINT `fk_users_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='User accounts with role-based access control and shift assignment for staff';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'SA-001','Yang','','developer','$2y$10$peTyaBouEq7TZYXUt.9Rtefr/v3E8LQcglr3xanwtWF9K8qOmI1x2','superadmin',NULL,NULL,NULL,NULL,'yangc.developer@gmail.com','',1253,'Active','2026-02-16 16:04:29','2026-08-21 15:09:31','Yang','uploads/profiles/profile_1_1783275664.jpg',NULL),(3,'MGR-001','Edgar','Eslit','Edgar','$2y$10$elC6zR8bhKIzk.I79LENVuHw.gEDjOUTpk3Aflf3LfBV.DY6D2s3m','manager','All Shifts','All Shifts',NULL,NULL,'cabahug.amiedamas@gmail.com','',1253,'Active','2026-02-27 12:47:29','2026-08-21 14:50:29','Edgar Eslit','uploads/profiles/profile_3_1783273496.jpg',NULL),(4,'ADM-001','Romeca Katherine Jane','Tello Pepito','pepito','$2y$10$83qOKdC3LJOp0YK6WmLpfOyba3dD96HIXdgzBP8hTtCQHmwXG1En6','admin','All Shifts','All Shifts',NULL,NULL,'amda.cabahug.coc@phinmaed.com','+63 917 791 8140',1253,'Active','2026-03-09 14:41:02','2026-08-21 14:45:50','Romeca Katherine Jane Tello Pepito','uploads/profiles/profile_4_1786900100.jpg',NULL),(9,'STF-003','Judy','Lastimosa','judy','$2y$10$xKm/2yfoyFcY6tux7wv9O.1v0C770UhozgfLC4gEmJ0sziAMWKDp.','staff','Shift 2','Shift 2','14:00:00','00:00:00','amiecabahug2020@gmail.com','09452136587',1253,'Active','2026-06-30 21:55:43','2026-08-20 23:46:45','Judy Lastimosa','uploads/profiles/profile_9_1787063034.jpg',NULL),(13,'STF-004','Yyeng','C.','yengsss','$2y$10$DwGj6EoH3.d/Jhvww/BV4uWB/iQyPcYVZJaG2QdqODHTqr/kzeaTu','staff','Shift 1','Shift 1',NULL,NULL,'yyangcabahug@gmail.com','09565232510',1253,'Active','2026-07-28 15:58:37','2026-08-21 15:34:45','Yyeng C.','uploads/profiles/profile_13_1787295814.jpg',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `variance_alerts`
--

DROP TABLE IF EXISTS `variance_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `variance_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL DEFAULT 'Merchandise',
  `item_identifier` varchar(255) DEFAULT NULL,
  `variance_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','investigating','resolved','escalated') NOT NULL DEFAULT 'open',
  `investigation_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station_status` (`station_id`,`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_station_id` (`station_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_variance_alerts_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_variance_alerts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `variance_alerts`
--

LOCK TABLES `variance_alerts` WRITE;
/*!40000 ALTER TABLE `variance_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `variance_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vehicle_inspection_items`
--

DROP TABLE IF EXISTS `vehicle_inspection_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_inspection_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_id` int(11) DEFAULT NULL,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_name` (`item_name`),
  KEY `station_id` (`station_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_vii_station` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_vii_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vehicle_inspection_items`
--

LOCK TABLES `vehicle_inspection_items` WRITE;
/*!40000 ALTER TABLE `vehicle_inspection_items` DISABLE KEYS */;
INSERT INTO `vehicle_inspection_items` VALUES (1,NULL,'Engine','General',1,NULL,'2026-07-29 06:45:14'),(2,NULL,'Battery','General',1,NULL,'2026-07-29 06:45:14'),(3,NULL,'Tires','General',1,NULL,'2026-07-29 06:45:14'),(4,NULL,'Brakes','General',1,NULL,'2026-07-29 06:45:14'),(5,NULL,'Lights','General',1,NULL,'2026-07-29 06:45:14'),(6,NULL,'Cooling System','General',1,NULL,'2026-07-29 06:45:14'),(7,NULL,'Suspension','General',1,NULL,'2026-07-29 06:45:14'),(8,NULL,'Transmission Fluid','General',1,NULL,'2026-07-29 06:45:14'),(9,NULL,'Air Filter','General',1,NULL,'2026-07-29 06:45:14'),(10,NULL,'Wipers & Washers','General',1,NULL,'2026-07-29 06:45:14'),(11,NULL,'Belts & Hoses','General',1,NULL,'2026-07-29 06:45:14'),(12,NULL,'Steering System','General',1,NULL,'2026-07-29 06:45:14'),(13,NULL,'Exhaust System','General',1,NULL,'2026-07-29 06:45:14');
/*!40000 ALTER TABLE `vehicle_inspection_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vehicle_types`
--

DROP TABLE IF EXISTS `vehicle_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(100) NOT NULL,
  `vehicle_name` varchar(150) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  `submitted_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_name` (`vehicle_name`),
  KEY `idx_submitted_by_auto` (`submitted_by`),
  KEY `idx_reviewed_by_auto` (`reviewed_by`),
  CONSTRAINT `fk_vehicle_types_reviewed_by_9bc6` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vehicle_types_submitted_by_9bc6` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vehicle_types`
--

LOCK TABLES `vehicle_types` WRITE;
/*!40000 ALTER TABLE `vehicle_types` DISABLE KEYS */;
INSERT INTO `vehicle_types` VALUES (1,'Sedans / Hatchbacks','Toyota Vios',1,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(2,'Sedans / Hatchbacks','Honda City',2,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(3,'Sedans / Hatchbacks','Mitsubishi Mirage',3,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(4,'Sedans / Hatchbacks','Honda Civic',4,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(5,'Sedans / Hatchbacks','Toyota Corolla Altis',5,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(6,'Sedans / Hatchbacks','Mazda 3',6,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(7,'Sedans / Hatchbacks','Hyundai Accent',7,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(8,'Sedans / Hatchbacks','Kia Rio',8,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(9,'Sedans / Hatchbacks','Suzuki Swift',9,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(10,'Sedans / Hatchbacks','Nissan Almera',10,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(11,'SUVs','Toyota Fortuner',11,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(12,'SUVs','Mitsubishi Montero',12,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(13,'SUVs','Ford Everest',13,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(14,'SUVs','Isuzu MU-X',14,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(15,'SUVs','Nissan Terra',15,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(16,'SUVs','Chevrolet Trailblazer',16,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(17,'SUVs','Hyundai Tucson',17,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(18,'SUVs','Kia Sportage',18,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(19,'Pickups','Toyota Hilux',19,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(20,'Pickups','Mitsubishi Strada',20,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(21,'Pickups','Ford Ranger',21,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(22,'Pickups','Isuzu D-Max',22,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(23,'Pickups','Nissan Navara',23,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(24,'Pickups','Mazda BT-50',24,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(25,'Vans','Toyota Hiace',25,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(26,'Vans','Nissan Urvan',26,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(27,'Vans','Hyundai Starex',27,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(28,'Vans','Kia Carnival',28,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(29,'Vans','Mitsubishi L300',29,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(30,'Light Trucks / Utility','Isuzu Elf',30,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(31,'Light Trucks / Utility','Mitsubishi Canter',31,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(32,'Light Trucks / Utility','Suzuki Multicab',32,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(33,'Light Trucks / Utility','Jeepney',33,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(34,'Motorcycles','Honda Click',34,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(35,'Motorcycles','Yamaha Mio',35,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(36,'Motorcycles','Honda Wave',36,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(37,'Motorcycles','Suzuki Raider',37,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(38,'Motorcycles','Kawasaki Rouser',38,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(39,'Motorcycles','Honda Beat',39,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(40,'Motorcycles','Yamaha Aerox',40,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(41,'Motorcycles','Honda ADV',41,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15'),(42,'Other','Other (Manual Input)',99,'approved',NULL,NULL,NULL,1,'2026-05-13 06:50:05','2026-05-13 06:56:15');
/*!40000 ALTER TABLE `vehicle_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `voided_transactions`
--

DROP TABLE IF EXISTS `voided_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `voided_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchandise_txn_id` int(11) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `transaction_type` varchar(60) DEFAULT 'merchandise',
  `amount` decimal(12,2) DEFAULT 0.00,
  `void_reason` text DEFAULT NULL,
  `manager_remarks` text DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_by_name` varchar(255) DEFAULT NULL,
  `station_id` int(11) NOT NULL DEFAULT 0,
  `void_date` datetime DEFAULT current_timestamp(),
  `fields_changed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields_changed`)),
  `job_order_no` varchar(100) DEFAULT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vt_txn` (`transaction_id`),
  KEY `idx_vt_station` (`station_id`),
  KEY `idx_voided_by` (`voided_by`),
  KEY `idx_station_id` (`station_id`),
  CONSTRAINT `fk_voided_transactions_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_voided_transactions_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `voided_transactions`
--

LOCK TABLES `voided_transactions` WRITE;
/*!40000 ALTER TABLE `voided_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `voided_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'petron_pos_db_secure
'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-21 16:52:12
