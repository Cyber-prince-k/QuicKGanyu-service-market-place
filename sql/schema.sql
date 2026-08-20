-- QuickGanyu – Account creation & provider approval schema
CREATE DATABASE IF NOT EXISTS quickganyu
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE quickganyu;

-- ---------------------------------------------------------------------------
-- users: every account (admin, client, provider) lives here.
-- username is UNIQUE so no two users can share the same account name.
-- Providers approved but not yet set up have username = NULL and status = needs_setup.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name         VARCHAR(120)    NOT NULL,
  username          VARCHAR(60)     NULL UNIQUE,
  email             VARCHAR(190)    NULL UNIQUE,
  password_hash     VARCHAR(255)    NOT NULL,
  role              ENUM('admin','provider','client') NOT NULL DEFAULT 'client',
  status            ENUM('active','needs_setup','suspended') NOT NULL DEFAULT 'active',
  phone             VARCHAR(40)     NULL,
  location          VARCHAR(120)    NULL,
  service_category  VARCHAR(80)     NULL,
  skills            TEXT            NULL,
  about             TEXT            NULL,
  experience_years  INT UNSIGNED    NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- provider_requests: "Offer services" applications submitted for admin review.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_requests (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name          VARCHAR(120)   NOT NULL,
  email              VARCHAR(190)   NOT NULL,
  phone              VARCHAR(40)    NULL,
  location           VARCHAR(120)   NULL,
  service_category   VARCHAR(80)    NOT NULL,
  experience_years   INT UNSIGNED   NULL,
  skills             TEXT           NULL,
  about              TEXT           NULL,
  agreed_to_terms    TINYINT(1)     NOT NULL DEFAULT 0,
  status             ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  temp_password_hash VARCHAR(255)   NULL,
  user_id            INT UNSIGNED   NULL,
  admin_note         VARCHAR(255)   NULL,
  created_at         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at         DATETIME       NULL,
  CONSTRAINT fk_provider_request_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed the two default administrators.
--   RoiAdmin  / Pk711908
--   Admin1    / PK711909
-- Passwords are stored as bcrypt hashes (password_hash / password_verify).
-- INSERT IGNORE keeps this script safe to run more than once.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO users
  (full_name, username, email, password_hash, role, status, phone, location)
VALUES
  ('Roi Admin', 'RoiAdmin', 'roiadmin@quickganyu.com',
   '$2y$10$2LtNOKxK2UnsJNQymWppSeRmRfwgwg8xRDrdCWrUYJhEQIZhi4w2m',
   'admin', 'active', '+265 999 000 001', 'Lilongwe'),
  ('Admin One', 'Admin1', 'admin1@quickganyu.com',
   '$2y$10$GFZk/5eV1V75IDq6l7CpG.6f53LBypIlz3m3B7r5qdTwINO8BU2hC',
   'admin', 'active', '+265 999 000 002', 'Blantyre');
