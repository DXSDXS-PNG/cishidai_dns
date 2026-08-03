SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `access_logs`;
DROP TABLE IF EXISTS `admin_sessions`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `alerts`;
DROP TABLE IF EXISTS `domain_access_logs`;
DROP TABLE IF EXISTS `domains`;
DROP TABLE IF EXISTS `logs`;
DROP TABLE IF EXISTS `monitors`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `platform_accounts`;
DROP TABLE IF EXISTS `policies`;
DROP TABLE IF EXISTS `records`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `tenant_credit_logs`;
DROP TABLE IF EXISTS `tenant_credits`;
DROP TABLE IF EXISTS `tenant_users`;

CREATE TABLE `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(32) NOT NULL,
  `salt` varchar(16) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `role` enum('super_admin','admin') DEFAULT 'admin',
  `status` enum('enabled','disabled') DEFAULT 'enabled',
  `login_ip` varchar(45) DEFAULT NULL,
  `login_at` datetime DEFAULT NULL,
  `login_count` int unsigned DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `admins` (`id`,`username`,`password`,`salt`,`name`,`role`,`status`,`login_count`,`created_at`,`updated_at`)
VALUES (1,'admin','dc0ada742b083569fc93fbdf4dfbe33a','opensrc20260804','管理员','super_admin','enabled',0,NOW(),NOW());

CREATE TABLE `admin_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `platform_accounts` (
  `id` varchar(30) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `platform` enum('aliyun','tencent') DEFAULT NULL,
  `access_key` varchar(200) DEFAULT NULL,
  `secret_key` varchar(200) DEFAULT NULL,
  `status` enum('enabled','disabled') DEFAULT 'enabled',
  `health` enum('normal','warning','error','unchecked') DEFAULT 'unchecked',
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `domains` (
  `id` varchar(30) NOT NULL,
  `domain` varchar(190) NOT NULL,
  `platform_account_id` varchar(30) DEFAULT NULL,
  `platform` enum('aliyun','tencent','local') DEFAULT NULL,
  `owner` varchar(100) DEFAULT NULL,
  `group` varchar(100) DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `status` enum('active','paused','expired') DEFAULT 'active',
  `name_servers` varchar(500) DEFAULT NULL,
  `price_per_month` decimal(10,2) DEFAULT 29.00,
  `remark` text,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_platform_account_id` (`platform_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `records` (
  `id` varchar(30) NOT NULL,
  `domain_id` varchar(30) NOT NULL,
  `host` varchar(100) DEFAULT NULL,
  `type` varchar(10) DEFAULT NULL,
  `line` varchar(50) DEFAULT 'default',
  `value` varchar(500) DEFAULT NULL,
  `ttl` int DEFAULT 600,
  `weight` int DEFAULT 100,
  `status` enum('enabled','paused','deleted') DEFAULT 'enabled',
  `rented_to` varchar(100) DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `platform_record_id` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `orders` (
  `id` varchar(30) NOT NULL,
  `trade_no` varchar(50) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `customer` varchar(100) DEFAULT NULL,
  `contact` varchar(200) DEFAULT NULL,
  `scenario` varchar(50) DEFAULT NULL,
  `domain_id` varchar(30) DEFAULT NULL,
  `host` varchar(100) DEFAULT NULL,
  `plan` enum('starter','business','vip') DEFAULT NULL,
  `months` int DEFAULT 1,
  `record_type` varchar(10) DEFAULT NULL,
  `record_value` varchar(500) DEFAULT NULL,
  `record_id` varchar(30) DEFAULT NULL,
  `provisioned_at` datetime DEFAULT NULL,
  `pay_url` text,
  `pay_type` varchar(20) DEFAULT 'alipay',
  `created_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tenant_users` (
  `id` varchar(40) NOT NULL,
  `username` varchar(80) NOT NULL,
  `password` varchar(32) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `contact` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'enabled',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tenant_credits` (
  `username` varchar(80) NOT NULL,
  `credits` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tenant_credit_logs` (
  `id` varchar(30) NOT NULL,
  `username` varchar(80) NOT NULL,
  `trade_no` varchar(50) NOT NULL,
  `type` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
('systemName','DNS 二级域名分发系统'),
('adminLogoText','DNS'),
('adminBrandName','域名分发'),
('defaultAvatarUrl',''),
('syncIntervalSeconds','300'),
('epay','{\"pid\":\"\",\"key\":\"\",\"gateway\":\"https://pay.example.com/submit.php\",\"notifyIpWhitelist\":\"127.0.0.1,::1\"}');

CREATE TABLE `logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `target` varchar(255) DEFAULT NULL,
  `result` enum('success','failed') DEFAULT 'success',
  `detail` text,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `domain_access_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `host` varchar(190) NOT NULL,
  `path` varchar(1000) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `status` int DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `referer` varchar(500) DEFAULT NULL,
  `upstream` varchar(255) DEFAULT NULL,
  `visited_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_host_time` (`host`,`visited_at`),
  KEY `idx_path` (`path`(191)),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `access_logs` (
  `id` varchar(30) NOT NULL,
  `record_id` varchar(30) DEFAULT NULL,
  `domain_id` varchar(30) DEFAULT NULL,
  `full_domain` varchar(255) DEFAULT NULL,
  `url` text,
  `path` varchar(600) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `status_code` int DEFAULT NULL,
  `raw_log` mediumtext,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_record_id` (`record_id`),
  KEY `idx_full_domain` (`full_domain`(191)),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `policies` (
  `id` varchar(30) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `domain_id` varchar(30) DEFAULT NULL,
  `mode` varchar(50) DEFAULT NULL,
  `targets` text,
  `failover` tinyint(1) DEFAULT 0,
  `status` enum('enabled','disabled') DEFAULT 'enabled',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `monitors` (
  `id` varchar(30) NOT NULL,
  `target` varchar(255) DEFAULT NULL,
  `type` enum('record','provider') DEFAULT NULL,
  `status` enum('normal','warning','down') DEFAULT 'normal',
  `latency` int DEFAULT 0,
  `message` varchar(500) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `alerts` (
  `id` varchar(30) NOT NULL,
  `level` enum('info','warning','error') DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text,
  `status` enum('open','closed') DEFAULT 'open',
  `created_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
