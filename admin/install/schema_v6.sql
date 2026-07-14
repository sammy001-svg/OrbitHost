-- ============================================================
--  Orbit Cloud — Schema v6 Migration
--  Live chat (website widget ↔ admin inbox)
--
--  NOTE: api/chat.php creates these automatically on the first
--  visitor message. Import manually only if that fails.
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement.
-- ============================================================

CREATE TABLE IF NOT EXISTS chat_conversations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_token VARCHAR(64)  NOT NULL,
    name          VARCHAR(100) DEFAULT NULL,
    email         VARCHAR(150) DEFAULT NULL,
    page          VARCHAR(255) DEFAULT NULL,
    status        ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cc_token (visitor_token),
    INDEX idx_cc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender          ENUM('visitor','admin') NOT NULL,
    sender_name     VARCHAR(100) DEFAULT NULL,
    message         TEXT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_read      TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_cm_conv (conversation_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
