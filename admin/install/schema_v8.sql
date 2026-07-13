-- ============================================================
--  OrbitHost — Schema v8 Migration
--  Notifications (in-app + email) and reminder de-duplication
--
--  NOTE: Notifier::ensureTables() creates these automatically on first
--  use. Import manually only if that page shows a warning.
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement.
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    audience     ENUM('client','admin') NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,      -- clients.id or admin_users.id
    type         VARCHAR(50)  NOT NULL,
    title        VARCHAR(255) NOT NULL,
    message      TEXT,
    link         VARCHAR(255) DEFAULT NULL,
    is_read      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_recipient (audience, recipient_id, is_read),
    INDEX idx_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prevents duplicate cron reminders (e.g. sending the same "7 days
-- left" domain notice every day the cron happens to run).
CREATE TABLE IF NOT EXISTS reminder_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(30)  NOT NULL,   -- 'domain' | 'service' | 'invoice'
    entity_id   INT UNSIGNED NOT NULL,
    milestone   VARCHAR(20)  NOT NULL,   -- '30d','14d','7d','3d','1d','0d','overdue'
    sent_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reminder (entity_type, entity_id, milestone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
