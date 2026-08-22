-- QuickGanyu – 4-table schema with table-per-type inheritance
-- Run this script once on a fresh database.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.

CREATE DATABASE IF NOT EXISTS quickganyu
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE quickganyu;

-- ─────────────────────────────────────────────────────────────────────────────
-- BASE TABLE: users
-- Holds the shared identity for every account regardless of role.
-- Child tables (admins, service_providers, customers) all foreign-key into this.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120)    NOT NULL,
  username      VARCHAR(60)     NULL UNIQUE,
  email         VARCHAR(190)    NOT NULL UNIQUE,
  password_hash VARCHAR(255)    NOT NULL,
  role          ENUM('admin','provider','client') NOT NULL,
  status        ENUM('active','needs_setup','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHILD TABLE: admins
-- Inherits from users WHERE role = 'admin'.
-- Stores admin-specific data (office location, contact phone, etc.).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admins (
  user_id       INT UNSIGNED    NOT NULL PRIMARY KEY,
  phone         VARCHAR(40)     NULL,
  location      VARCHAR(120)    NULL,
  CONSTRAINT fk_admin_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHILD TABLE: service_providers
-- Inherits from users WHERE role = 'provider'.
-- Holds the professional profile data for each provider.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_providers (
  user_id          INT UNSIGNED    NOT NULL PRIMARY KEY,
  phone            VARCHAR(40)     NULL,
  location         VARCHAR(120)    NULL,
  service_category VARCHAR(80)     NULL,
  skills           TEXT            NULL,
  about            TEXT            NULL,
  experience_years INT UNSIGNED    NULL,
  CONSTRAINT fk_provider_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHILD TABLE: customers
-- Inherits from users WHERE role = 'client'.
-- Stores customer-specific contact and location info.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
  user_id       INT UNSIGNED    NOT NULL PRIMARY KEY,
  phone         VARCHAR(40)     NULL,
  location      VARCHAR(120)    NULL,
  CONSTRAINT fk_customer_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHILD TABLE: provider_requests
-- Applications from people who want to become service providers.
-- Admin reviews these and either approves (creating a provider account) or declines.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS provider_requests (
  id                 INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  full_name          VARCHAR(120)    NOT NULL,
  email              VARCHAR(190)    NOT NULL,
  phone              VARCHAR(40)     NULL,
  location           VARCHAR(120)    NULL,
  service_category   VARCHAR(80)     NOT NULL,
  experience_years   INT UNSIGNED    NULL,
  skills             TEXT            NULL,
  about              TEXT            NULL,
  agreed_to_terms    TINYINT(1)      NOT NULL DEFAULT 0,
  status             ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  temp_password_hash VARCHAR(255)    NULL,
  user_id            INT UNSIGNED    NULL,
  admin_note         VARCHAR(255)    NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at         DATETIME        NULL,
  CONSTRAINT fk_provider_request_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- SEED: two default administrators
--   RoiAdmin  / Pk711908
--   Admin1    / PK711909
-- INSERT IGNORE keeps this safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO users
  (full_name, username, email, password_hash, role, status)
VALUES
  ('Roi Admin', 'RoiAdmin', 'roiadmin@quickganyu.com',
   '$2y$10$2LtNOKxK2UnsJNQymWppSeRmRfwgwg8xRDrdCWrUYJhEQIZhi4w2m',
   'admin', 'active'),
  ('Admin One', 'Admin1', 'admin1@quickganyu.com',
   '$2y$10$GFZk/5eV1V75IDq6l7CpG.6f53LBypIlz3m3B7r5qdTwINO8BU2hC',
   'admin', 'active');

-- Seed admin child rows (use subquery to resolve ids safely).
INSERT IGNORE INTO admins (user_id, phone, location)
SELECT id, '+265 999 000 001', 'Lilongwe' FROM users WHERE username = 'RoiAdmin';

INSERT IGNORE INTO admins (user_id, phone, location)
SELECT id, '+265 999 000 002', 'Blantyre' FROM users WHERE username = 'Admin1';
