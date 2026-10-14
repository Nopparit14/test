-- Database creation
CREATE DATABASE IF NOT EXISTS `ogios_license` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ogios_license`;

-- Admins table
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Licenses table
CREATE TABLE IF NOT EXISTS `licenses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `license_key` VARCHAR(64) NOT NULL UNIQUE,
    `duration_days` INT NOT NULL DEFAULT 30, -- 0 means lifetime
    `status` ENUM('unused', 'active', 'expired', 'banned') NOT NULL DEFAULT 'unused',
    `device_id` VARCHAR(128) DEFAULT NULL,
    `device_name` VARCHAR(128) DEFAULT NULL,
    `activated_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_license_key` (`license_key`),
    INDEX `idx_device_id` (`device_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity Logs table
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `license_key` VARCHAR(64) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `device_id` VARCHAR(128) DEFAULT NULL,
    `device_name` VARCHAR(128) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_log_key` (`license_key`),
    INDEX `idx_log_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin if not exists (username: admin, pass: admin123)
INSERT IGNORE INTO `admins` (`id`, `username`, `password`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
