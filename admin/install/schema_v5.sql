-- ============================================================
--  OrbitHost — Schema v5 Migration
--  TLD pricing + widen domain_registrations.registrar
--
--  NOTE: The "TLD Pricing" admin page (Integrations › Domains ›
--  TLD Pricing) applies this automatically on first visit if the DB
--  user has CREATE/ALTER privileges (cPanel users normally do).
--  Import manually only if that page shows a warning.
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement.
-- ============================================================

-- Sellable domain extensions with retail prices (set by admin)
-- and wholesale costs (synced from the registrar).
CREATE TABLE IF NOT EXISTS domain_tlds (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tld            VARCHAR(32)   NOT NULL UNIQUE,          -- e.g. com, co.ke
    provider       VARCHAR(50)   DEFAULT NULL,             -- registrar that fulfils it
    currency       VARCHAR(10)   NOT NULL DEFAULT 'USD',
    register_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,    -- retail
    transfer_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    renew_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    register_cost  DECIMAL(10,2) DEFAULT NULL,             -- wholesale (synced)
    transfer_cost  DECIMAL(10,2) DEFAULT NULL,
    renew_cost     DECIMAL(10,2) DEFAULT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 0,        -- shown in public search
    sort_order     INT          NOT NULL DEFAULT 100,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Allow any registrar key (was ENUM limited to namecheap/godaddy/manual)
ALTER TABLE domain_registrations
  MODIFY COLUMN registrar VARCHAR(50) NOT NULL DEFAULT 'manual';
