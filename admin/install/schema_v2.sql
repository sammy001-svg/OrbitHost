-- ============================================================
--  OrbitHost — Schema v2 Migration
--  Run AFTER schema.sql:  mysql -u root -p orbithost_admin < install/schema_v2.sql
-- ============================================================

USE orbithost_admin;

-- ── Client portal authentication ─────────────────────────────
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS portal_password VARCHAR(255)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS email_verified  TINYINT(1)    NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS reset_token     VARCHAR(64)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reset_expires   DATETIME      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS portal_login    DATETIME      DEFAULT NULL;

-- ── Integration settings (WHM, domain providers) ─────────────
CREATE TABLE IF NOT EXISTS integration_settings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider   VARCHAR(50)  NOT NULL UNIQUE,
    settings   JSON         NOT NULL,
    is_active  TINYINT(1)  NOT NULL DEFAULT 0,
    updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default provider stubs
INSERT IGNORE INTO integration_settings (provider, settings, is_active) VALUES
('whm',      '{"host":"","user":"root","token":"","ssl_verify":false}', 0),
('namecheap','{"api_user":"","api_key":"","sandbox":true}', 0),
('godaddy',  '{"api_key":"","api_secret":"","sandbox":true}', 0),
('smtp',     '{"host":"smtp.gmail.com","port":587,"user":"","pass":"","from_name":"OrbitHost","from_email":"noreply@orbithost.com"}', 0);

-- ── WHM provisioned cPanel accounts ──────────────────────────
CREATE TABLE IF NOT EXISTS whm_accounts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    client_id    INT UNSIGNED NOT NULL,
    cpanel_user  VARCHAR(50)  NOT NULL UNIQUE,
    domain       VARCHAR(255) NOT NULL,
    package      VARCHAR(100) DEFAULT 'default',
    server_host  VARCHAR(255),
    disk_used_mb INT UNSIGNED DEFAULT 0,
    disk_limit_mb INT UNSIGNED DEFAULT 0,
    bw_used_mb   BIGINT UNSIGNED DEFAULT 0,
    status       ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active',
    provisioned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id)  REFERENCES orders(id)   ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Domain registrations ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS domain_registrations (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id         INT UNSIGNED NOT NULL,
    order_id          INT UNSIGNED DEFAULT NULL,
    domain            VARCHAR(255) NOT NULL UNIQUE,
    registrar         ENUM('namecheap','godaddy','manual') NOT NULL DEFAULT 'manual',
    registrar_txid    VARCHAR(150) DEFAULT NULL,
    registration_date DATE,
    expiry_date       DATE,
    auto_renew        TINYINT(1)  NOT NULL DEFAULT 1,
    nameservers       TEXT,
    status            ENUM('active','expired','transferred','cancelled','pending') NOT NULL DEFAULT 'pending',
    epp_code          VARCHAR(64)  DEFAULT NULL,
    created_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)  ON DELETE CASCADE,
    FOREIGN KEY (order_id)  REFERENCES orders(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Portal invite tokens ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS portal_invites (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id  INT UNSIGNED NOT NULL UNIQUE,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)  NOT NULL DEFAULT 0,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
