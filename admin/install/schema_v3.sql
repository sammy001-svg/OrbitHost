-- ============================================================
--  OrbitHost — Schema v3 Migration
--  Service lifecycle + multi-provider integrations + payments
--
--  Run AFTER schema.sql and schema_v2.sql
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement manually.
--
--  CLI (local/root): mysql -u root -p your_db_name < schema_v3.sql
--
--  Note: the existing `services` table is the PRODUCT/PLAN catalogue.
--  Provisioned services for clients live in `client_services` below.
-- ============================================================

-- ── Provisioned services (the service lifecycle) ─────────────
CREATE TABLE IF NOT EXISTS client_services (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id         INT UNSIGNED NOT NULL,
    service_id        INT UNSIGNED DEFAULT NULL,          -- FK to services (plan catalogue)
    order_id          INT UNSIGNED DEFAULT NULL,          -- originating order, if any
    label             VARCHAR(150) NOT NULL,              -- friendly display name
    domain            VARCHAR(255) DEFAULT NULL,
    category          ENUM('hosting','vps','reseller','domain','ssl','email','other') NOT NULL DEFAULT 'hosting',

    -- Provider binding (which integration provisions/manages this service)
    provider_category ENUM('panel','registrar','none') NOT NULL DEFAULT 'panel',
    provider_key      VARCHAR(50)  DEFAULT NULL,          -- e.g. whm, plesk, directadmin, namecheap
    remote_id         VARCHAR(191) DEFAULT NULL,          -- cPanel username / registrar order id
    username          VARCHAR(100) DEFAULT NULL,
    server_host       VARCHAR(255) DEFAULT NULL,
    package           VARCHAR(100) DEFAULT NULL,

    -- Billing snapshot
    billing_cycle     ENUM('monthly','annual','one_time') NOT NULL DEFAULT 'monthly',
    amount            DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    -- Usage (synced from provider)
    disk_used_mb      INT UNSIGNED DEFAULT 0,
    disk_limit_mb     INT UNSIGNED DEFAULT 0,
    bw_used_mb        BIGINT UNSIGNED DEFAULT 0,

    -- Lifecycle
    status            ENUM('pending','provisioning','active','suspended','terminated','failed','cancelled')
                          NOT NULL DEFAULT 'pending',
    start_date        DATE DEFAULT NULL,
    next_due_date     DATE DEFAULT NULL,
    last_synced_at    DATETIME DEFAULT NULL,
    notes             TEXT,
    meta              JSON DEFAULT NULL,

    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_cs_client   (client_id),
    INDEX idx_cs_status   (status),
    INDEX idx_cs_provider (provider_key),
    FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Service lifecycle audit trail ────────────────────────────
CREATE TABLE IF NOT EXISTS service_actions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    service_id  INT UNSIGNED NOT NULL,
    admin_id    INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(50)  NOT NULL,   -- provision, suspend, unsuspend, terminate, change_package, sync, password
    status      ENUM('success','failed','pending') NOT NULL DEFAULT 'pending',
    message     TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sa_service (service_id),
    FOREIGN KEY (service_id) REFERENCES client_services(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id)   REFERENCES admin_users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payment gateway transactions ─────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id   INT UNSIGNED DEFAULT NULL,
    client_id    INT UNSIGNED NOT NULL,
    gateway      VARCHAR(50)  NOT NULL,      -- stripe, paypal, mpesa, flutterwave, manual
    gateway_ref  VARCHAR(191) DEFAULT NULL,  -- charge id / checkout request id / txn id
    amount       DECIMAL(10,2) NOT NULL,
    currency     VARCHAR(10)  NOT NULL DEFAULT 'USD',
    status       ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    raw          JSON DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pay_client  (client_id),
    INDEX idx_pay_invoice (invoice_id),
    INDEX idx_pay_gateway (gateway),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed integration_settings rows for the expanded provider set ──
--  The provider registry (PHP) supplies each provider's config-field
--  schema and metadata; these rows just hold saved config + on/off.
INSERT IGNORE INTO integration_settings (provider, settings, is_active) VALUES
-- Hosting control panels
('whm',         '{}', 0),
('plesk',       '{}', 0),
('directadmin', '{}', 0),
-- Domain registrars
('namecheap',   '{}', 0),
('godaddy',     '{}', 0),
('enom',        '{}', 0),
('resellerclub','{}', 0),
('cloudflare',  '{}', 0),
-- Payment gateways
('stripe',      '{}', 0),
('paypal',      '{}', 0),
('mpesa',       '{}', 0),
('flutterwave', '{}', 0),
-- Email
('smtp',        '{}', 0);
