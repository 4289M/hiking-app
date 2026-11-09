-- =====================================================================
-- Hiking GPS Tracker — โครงสร้างฐานข้อมูล
-- MySQL 8.0+ / MariaDB 10.4+ (XAMPP) , utf8mb4
-- เวลาทั้งหมดในฐานข้อมูลเก็บเป็น UTC (แอปตั้ง time_zone = '+00:00' ทุกครั้งที่เชื่อมต่อ)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS hiking_app
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hiking_app;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- ผู้ใช้
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  password    VARCHAR(255) NOT NULL COMMENT 'password_hash() เท่านั้น ห้ามเก็บ plain text',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- กิจกรรมเดินป่า
-- status: recording = กำลังบันทึก, paused = พักชั่วคราว, finished = จบแล้ว
-- duration_seconds = เวลาเดินจริง (ไม่รวมเวลาพัก)
-- paused_seconds   = เวลาพักรวม
-- current_segment  = เพิ่มขึ้นทุกครั้งที่ Resume (ไม่นับระยะทางข้ามช่วงพัก)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hikes (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED  NOT NULL,
  hike_name         VARCHAR(150)  NOT NULL,
  started_at        DATETIME(3)   NOT NULL,
  ended_at          DATETIME(3)   NULL,
  paused_at         DATETIME(3)   NULL COMMENT 'เวลาที่เริ่มพักครั้งล่าสุด (NULL ถ้าไม่ได้พัก)',
  duration_seconds  INT UNSIGNED  NOT NULL DEFAULT 0,
  paused_seconds    INT UNSIGNED  NOT NULL DEFAULT 0,
  distance_meters   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  max_speed_mps     DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  min_altitude      DECIMAL(8,2)  NULL,
  max_altitude      DECIMAL(8,2)  NULL,
  point_count       INT UNSIGNED  NOT NULL DEFAULT 0,
  current_segment   INT UNSIGNED  NOT NULL DEFAULT 1,
  status            ENUM('recording','paused','finished') NOT NULL DEFAULT 'recording',
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hikes_user_started (user_id, started_at),
  KEY idx_hikes_user_status (user_id, status),
  CONSTRAINT fk_hikes_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- พิกัด GPS ของแต่ละกิจกรรม
-- UNIQUE (hike_id, sequence_number) และ (hike_id, client_point_id)
-- ใช้กันข้อมูลซ้ำเมื่อ client ส่งซ้ำตอน sync offline
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hike_points (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hike_id          INT UNSIGNED    NOT NULL,
  sequence_number  INT UNSIGNED    NOT NULL,
  segment          INT UNSIGNED    NOT NULL DEFAULT 1,
  latitude         DECIMAL(10,7)   NOT NULL,
  longitude        DECIMAL(10,7)   NOT NULL,
  altitude         DECIMAL(8,2)    NULL,
  accuracy         DECIMAL(8,2)    NOT NULL,
  speed            DECIMAL(6,2)    NULL COMMENT 'm/s จากอุปกรณ์ (ถ้ามี)',
  recorded_at      DATETIME(3)     NOT NULL,
  client_point_id  VARCHAR(64)     NOT NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_points_hike_seq (hike_id, sequence_number),
  UNIQUE KEY uq_points_hike_client (hike_id, client_point_id),
  KEY idx_points_hike_time (hike_id, recorded_at),
  CONSTRAINT fk_points_hike FOREIGN KEY (hike_id) REFERENCES hikes (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
