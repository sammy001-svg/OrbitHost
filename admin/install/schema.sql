-- ============================================================
--  OrbitHost Admin Panel — Database Schema
--  Run: mysql -u root -p < install/schema.sql
--  Default login: admin@orbithost.com / Admin@1234
--  ⚠  Change password immediately after first login.
-- ============================================================

CREATE DATABASE IF NOT EXISTS orbithost_admin
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE orbithost_admin;

-- ── Admin users ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('super_admin','admin','support') NOT NULL DEFAULT 'support',
    last_login DATETIME,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Clients ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clients (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    phone      VARCHAR(30),
    company    VARCHAR(150),
    country    VARCHAR(100) NOT NULL DEFAULT 'Kenya',
    status     ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
    notes      TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Services catalogue ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS services (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)  NOT NULL,
    category      ENUM('shared','vps','dedicated','cloud','wordpress','reseller','ssl','email','domain') NOT NULL,
    billing_cycle ENUM('monthly','annual','one_time') NOT NULL DEFAULT 'monthly',
    price         DECIMAL(10,2) NOT NULL,
    setup_fee     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO services (name, category, billing_cycle, price) VALUES
('Shared Starter',      'shared',    'monthly',  2.99),
('Shared Business',     'shared',    'monthly',  5.99),
('Shared Pro',          'shared',    'monthly',  9.99),
('VPS Starter',         'vps',       'monthly', 15.99),
('VPS Business',        'vps',       'monthly', 29.99),
('VPS Pro',             'vps',       'monthly', 59.99),
('Dedicated Essential', 'dedicated', 'monthly', 89.99),
('Dedicated Business',  'dedicated', 'monthly',149.99),
('Dedicated Enterprise','dedicated', 'monthly',249.99),
('Cloud Starter',       'cloud',     'monthly', 25.00),
('Cloud Business',      'cloud',     'monthly', 75.00),
('Cloud Enterprise',    'cloud',     'monthly',149.00),
('WordPress Starter',   'wordpress', 'monthly',  3.99),
('WordPress Business',  'wordpress', 'monthly',  7.99),
('WordPress Pro',       'wordpress', 'monthly', 12.99),
('Reseller Starter',    'reseller',  'monthly', 19.99),
('Reseller Business',   'reseller',  'monthly', 39.99),
('Reseller Pro',        'reseller',  'monthly', 69.99),
('OV SSL',              'ssl',       'one_time', 49.99),
('EV SSL',              'ssl',       'one_time',149.99),
('OrbitMail',           'email',     'monthly',  1.99),
('Microsoft 365',       'email',     'monthly',  6.00),
('Google Workspace',    'email',     'monthly',  6.00);

-- ── Orders / subscriptions ───────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id     INT UNSIGNED  NOT NULL,
    service_id    INT UNSIGNED,
    service_name  VARCHAR(150),
    domain        VARCHAR(255),
    amount        DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly','annual','one_time') NOT NULL DEFAULT 'monthly',
    status        ENUM('active','pending','suspended','cancelled','expired') NOT NULL DEFAULT 'pending',
    start_date    DATE,
    next_due      DATE,
    notes         TEXT,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Invoices ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoices (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30)   NOT NULL UNIQUE,
    client_id      INT UNSIGNED  NOT NULL,
    subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_rate       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    tax_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status         ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    due_date       DATE,
    paid_date      DATE,
    payment_method VARCHAR(50),
    notes          TEXT,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id  INT UNSIGNED  NOT NULL,
    description VARCHAR(255)  NOT NULL,
    quantity    INT           NOT NULL DEFAULT 1,
    unit_price  DECIMAL(10,2) NOT NULL,
    total       DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Support tickets ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tickets (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(20)  NOT NULL UNIQUE,
    client_id     INT UNSIGNED,
    subject       VARCHAR(255) NOT NULL,
    department    ENUM('sales','billing','technical','general') NOT NULL DEFAULT 'general',
    priority      ENUM('low','medium','high','urgent')          NOT NULL DEFAULT 'medium',
    status        ENUM('open','pending','answered','closed')    NOT NULL DEFAULT 'open',
    assigned_to   INT UNSIGNED,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)   REFERENCES clients(id)     ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_replies (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    sender_type ENUM('client','admin') NOT NULL,
    sender_name VARCHAR(100),
    message     TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Activity log ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id   INT UNSIGNED,
    description TEXT,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Default super admin (password: Admin@1234 — CHANGE NOW) ──
-- Hash generated with: password_hash('Admin@1234', PASSWORD_BCRYPT)
-- Run install/setup.php in your browser to set a real password.
INSERT IGNORE INTO admin_users (name, email, password, role)
VALUES ('Super Admin', 'admin@orbithost.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'super_admin');
