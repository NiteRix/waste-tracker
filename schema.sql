-- run this file in phpMyAdmin or MySQL CLI to set up the database

CREATE DATABASE IF NOT EXISTS waste_tracker CHARACTER SET utf8mb4;
USE waste_tracker;

-- dashboard login users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- default account: username = admin | password = admin123
INSERT INTO users (username, password) VALUES
('admin', '$2b$12$Y/xrDngzKG0lMM4d5857iOHZNP54cFtV9PS78/vUTPDLRSu36zWXq');

-- waste events coming from the smart bin (ESP32) - starts empty
CREATE TABLE IF NOT EXISTS waste_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_code VARCHAR(20) NOT NULL,
    weight DECIMAL(6,2) NOT NULL,
    event_type ENUM('waste', 'emptying') NOT NULL DEFAULT 'waste',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- live status of each smart bin device, refreshed by its heartbeat.
-- one row per device; the dashboard uses last_seen to decide if it is online.
CREATE TABLE IF NOT EXISTS device_status (
    device_id VARCHAR(40) PRIMARY KEY,
    line_code VARCHAR(20) NOT NULL,
    current_weight DECIMAL(7,2) NOT NULL DEFAULT 0,
    rssi INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
