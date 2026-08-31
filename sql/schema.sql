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
-- NOTE: email is NOT unique — multiple accounts may use the same address.
--       Full name and username are globally unique.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120)    NOT NULL UNIQUE,
  username      VARCHAR(60)     NULL UNIQUE,
  email         VARCHAR(190)    NOT NULL,           -- NOT unique on purpose
  password_hash VARCHAR(255)    NOT NULL,
  role          ENUM('admin','provider','client') NOT NULL,
  status        ENUM('active','needs_setup','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade databases created with the former unique email index. These statements
-- are conditional, so schema.sql remains safe to run again.
SET @drop_email_index := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'users'
        AND index_name = 'email' AND non_unique = 0
    ),
    'ALTER TABLE users DROP INDEX email',
    'SELECT 1'
  )
);
PREPARE schema_statement FROM @drop_email_index;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

SET @add_full_name_index := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'users'
        AND index_name = 'uq_users_full_name'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD CONSTRAINT uq_users_full_name UNIQUE (full_name)'
  )
);
PREPARE schema_statement FROM @add_full_name_index;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHILD TABLE: admins
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
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_providers (
  user_id          INT UNSIGNED    NOT NULL PRIMARY KEY,
  phone            VARCHAR(40)     NULL,
  location         VARCHAR(120)    NULL,
  service_category VARCHAR(80)     NULL,
  skills           TEXT            NULL,
  qualifications   TEXT            NULL,
  about            TEXT            NULL,
  experience_years INT UNSIGNED    NULL,
  CONSTRAINT fk_provider_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add qualifications when upgrading an older database created before this field.
SET @add_qualifications := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'service_providers'
        AND column_name = 'qualifications'
    ),
    'SELECT 1',
    'ALTER TABLE service_providers ADD COLUMN qualifications TEXT NULL AFTER skills'
  )
);
PREPARE schema_statement FROM @add_qualifications;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

-- Provider evidence. A provider needs at least one portfolio item and one
-- testimonial, alongside their profile details, before becoming bookable.
CREATE TABLE IF NOT EXISTS provider_portfolio (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED    NOT NULL,
  media_type  ENUM('image','video') NOT NULL,
  file_path   VARCHAR(255)    NOT NULL,
  caption     VARCHAR(180)    NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_portfolio_provider
    FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_testimonials (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED    NOT NULL,
  client_name VARCHAR(120)    NOT NULL,
  testimonial TEXT            NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_testimonial_provider
    FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A provider's sellable services or products. Each listing can have several
-- images and/or videos so clients can understand exactly what is offered.
CREATE TABLE IF NOT EXISTS provider_products (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED    NOT NULL,
  title       VARCHAR(140)    NOT NULL,
  description TEXT            NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_provider
    FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_product_media (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED    NOT NULL,
  media_type  ENUM('image','video') NOT NULL,
  file_path   VARCHAR(255)    NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_media_product
    FOREIGN KEY (product_id) REFERENCES provider_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer/provider negotiations, agreed bookings, and payment records.
CREATE TABLE IF NOT EXISTS service_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT UNSIGNED NOT NULL,
  provider_id    INT UNSIGNED NOT NULL,
  service_title  VARCHAR(140) NOT NULL,
  details        TEXT NULL,
  proposed_amount DECIMAL(12,2) NULL,
  agreed_amount  DECIMAL(12,2) NULL,
  status         ENUM('negotiating','awaiting_payment','payment_pending','booked','cancelled') NOT NULL DEFAULT 'negotiating',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_request_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_request_messages (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id   INT UNSIGNED NOT NULL,
  sender_id    INT UNSIGNED NOT NULL,
  message      TEXT NOT NULL,
  message_type ENUM('text','price_offer','price_accepted','payment_confirmed')
               NOT NULL DEFAULT 'text',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_message_request
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_request_message_sender
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add message_type to existing databases created before this field existed.
SET @add_msg_type := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name   = 'service_request_messages'
        AND column_name  = 'message_type'
    ),
    'SELECT 1',
    'ALTER TABLE service_request_messages
       ADD COLUMN message_type
         ENUM(''text'',''price_offer'',''price_accepted'',''payment_confirmed'')
         NOT NULL DEFAULT ''text''
       AFTER message'
  )
);
PREPARE schema_statement FROM @add_msg_type;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

CREATE TABLE IF NOT EXISTS service_payments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id  INT UNSIGNED NOT NULL,
  charge_id   VARCHAR(120) NOT NULL UNIQUE,
  amount      DECIMAL(12,2) NOT NULL,
  phone       VARCHAR(40) NOT NULL,
  status      ENUM('pending','successful','failed') NOT NULL DEFAULT 'pending',
  gateway_data JSON NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHILD TABLE: customers
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

INSERT IGNORE INTO admins (user_id, phone, location)
SELECT id, '+265 999 000 001', 'Lilongwe' FROM users WHERE username = 'RoiAdmin';

INSERT IGNORE INTO admins (user_id, phone, location)
SELECT id, '+265 999 000 002', 'Blantyre'  FROM users WHERE username = 'Admin1';
