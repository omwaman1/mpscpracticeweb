-- MPSC Practice Web Database Schema
-- Database: BANK

SET FOREIGN_KEY_CHECKS=0;

-- Table structure for `tbl_questions`
DROP TABLE IF EXISTS `tbl_questions`;
CREATE TABLE `tbl_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `testbook_id` varchar(64) DEFAULT NULL,
  `test_series_slug` varchar(128) DEFAULT 'mpsc-state-service',
  `test_id` varchar(64) DEFAULT NULL,
  `test_title` varchar(255) DEFAULT NULL,
  `subject_name` varchar(255) DEFAULT '',
  `category_name` varchar(255) DEFAULT '',
  `topic_name` varchar(255) DEFAULT '',
  `subtopic_name` varchar(255) DEFAULT '',
  `question_mr` text DEFAULT NULL,
  `question_en` text DEFAULT NULL,
  `opt1_mr` text DEFAULT NULL,
  `opt1_en` text DEFAULT NULL,
  `opt2_mr` text DEFAULT NULL,
  `opt2_en` text DEFAULT NULL,
  `opt3_mr` text DEFAULT NULL,
  `opt3_en` text DEFAULT NULL,
  `opt4_mr` text DEFAULT NULL,
  `opt4_en` text DEFAULT NULL,
  `correct_option` tinyint(4) DEFAULT NULL,
  `solution_mr` longtext DEFAULT NULL,
  `solution_en` longtext DEFAULT NULL,
  `positive_marks` float DEFAULT 1,
  `negative_marks` float DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `subject_tags` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `testbook_id` (`testbook_id`),
  KEY `idx_hierarchy` (`category_name`(50),`subject_name`(50),`topic_name`(50)),
  KEY `idx_slug` (`test_series_slug`),
  KEY `idx_test_id` (`test_id`),
  KEY `idx_exam_cat_sub` (`test_series_slug`,`category_name`(50),`subject_name`(50)),
  KEY `idx_subtopic` (`subtopic_name`(50)),
  KEY `idx_q_mr_prefix` (`question_mr`(100)),
  KEY `idx_q_en_prefix` (`question_en`(100)),
  KEY `idx_q_mr_sub` (`subject_name`,`category_name`),
  KEY `idx_topic_name` (`topic_name`),
  KEY `idx_test_title` (`test_title`),
  KEY `idx_testbook_id` (`testbook_id`),
  KEY `idx_exam_cat_sub_top` (`test_series_slug`(50),`category_name`(100),`subject_name`(100),`topic_name`(100)),
  KEY `idx_cat_sub_top` (`category_name`(100),`subject_name`(100),`topic_name`(100)),
  KEY `idx_subject_tags` (`subject_tags`),
  FULLTEXT KEY `ft_search` (`question_mr`,`question_en`,`topic_name`,`subject_name`,`test_title`)
) ENGINE=InnoDB AUTO_INCREMENT=1107180 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for `tbl_coaching_practice_map`
DROP TABLE IF EXISTS `tbl_coaching_practice_map`;
CREATE TABLE `tbl_coaching_practice_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `goal_slug` varchar(128) NOT NULL DEFAULT 'mpsc-combined-group-c-2026',
  `subject_name` varchar(128) NOT NULL,
  `chapter_name` varchar(128) NOT NULL,
  `topic_name` varchar(128) NOT NULL,
  `subtopic_name` varchar(255) NOT NULL,
  `practice_id` varchar(64) NOT NULL,
  `testbook_id` varchar(64) NOT NULL,
  `question_order` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_practice_q` (`practice_id`,`testbook_id`),
  KEY `idx_subject_topic` (`subject_name`,`topic_name`),
  KEY `idx_testbook_id` (`testbook_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22950 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for `tbl_coaching_practice_questions`
DROP TABLE IF EXISTS `tbl_coaching_practice_questions`;
CREATE TABLE `tbl_coaching_practice_questions` (
  `testbook_id` varchar(64) NOT NULL,
  `question_mr` longtext DEFAULT NULL,
  `question_en` longtext DEFAULT NULL,
  `opt1_mr` text DEFAULT NULL,
  `opt1_en` text DEFAULT NULL,
  `opt2_mr` text DEFAULT NULL,
  `opt2_en` text DEFAULT NULL,
  `opt3_mr` text DEFAULT NULL,
  `opt3_en` text DEFAULT NULL,
  `opt4_mr` text DEFAULT NULL,
  `opt4_en` text DEFAULT NULL,
  `correct_option` int(11) DEFAULT 0,
  `solution_mr` longtext DEFAULT NULL,
  `solution_en` longtext DEFAULT NULL,
  `positive_marks` decimal(4,2) DEFAULT 1.00,
  `negative_marks` decimal(4,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`testbook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for `tbl_app_users`
DROP TABLE IF EXISTS `tbl_app_users`;
CREATE TABLE `tbl_app_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `google_id` varchar(100) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_active` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for `tbl_app_subscriptions`
DROP TABLE IF EXISTS `tbl_app_subscriptions`;
CREATE TABLE `tbl_app_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(150) NOT NULL,
  `plan_name` varchar(100) DEFAULT '1 Month Unlimited',
  `amount` decimal(10,2) DEFAULT 199.00,
  `status` enum('active','inactive','pending') DEFAULT 'pending',
  `activated_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_status` (`user_email`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for `tbl_trial_ips`
DROP TABLE IF EXISTS `tbl_trial_ips`;
CREATE TABLE `tbl_trial_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(64) NOT NULL,
  `started_at` datetime NOT NULL,
  `last_active` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for `tbl_visitor_analytics`
DROP TABLE IF EXISTS `tbl_visitor_analytics`;
CREATE TABLE `tbl_visitor_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(64) NOT NULL,
  `user_email` varchar(150) DEFAULT NULL,
  `user_name` varchar(150) DEFAULT NULL,
  `device_type` varchar(32) DEFAULT 'Desktop',
  `total_seconds` int(11) NOT NULL DEFAULT 0,
  `questions_viewed` int(11) NOT NULL DEFAULT 0,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `last_seen` (`last_seen`),
  KEY `user_email` (`user_email`),
  KEY `total_seconds` (`total_seconds`)
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;
