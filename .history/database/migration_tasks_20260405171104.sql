-- Migration: Tasks & Workflows
-- Task management with message/thread references, assignments, due dates, and reminders

USE cro_chat;

-- ── Tasks ──────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tasks (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id          INT UNSIGNED NOT NULL,
    channel_id        INT UNSIGNED NULL,
    title             VARCHAR(500) NOT NULL,
    description       TEXT NULL,
    status            ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
    priority          ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    created_by        INT UNSIGNED NOT NULL,
    source_message_id INT UNSIGNED NULL         COMMENT 'Message this task was created from',
    thread_id         INT UNSIGNED NULL         COMMENT 'Linked thread',
    due_date          DATETIME NULL,
    completed_at      DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_status (space_id, status),
    INDEX idx_space_due (space_id, due_date),
    INDEX idx_channel (channel_id),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (space_id)          REFERENCES spaces(id)   ON DELETE CASCADE,
    FOREIGN KEY (channel_id)        REFERENCES channels(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)        REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (source_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    FOREIGN KEY (thread_id)         REFERENCES threads(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Task Assignees ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS task_assignees (
    task_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, user_id),
    INDEX idx_user_tasks (user_id),
    FOREIGN KEY (task_id)     REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Task Comments ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS task_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task (task_id, created_at),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Task Reminders ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS task_reminders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    remind_at   DATETIME NOT NULL,
    sent_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pending (sent_at, remind_at),
    INDEX idx_task (task_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Task Activity Log ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS task_activity (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    action     VARCHAR(50) NOT NULL          COMMENT 'created, status_changed, assigned, unassigned, commented, due_changed, reminder_set',
    old_value  VARCHAR(200) NULL,
    new_value  VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task (task_id, created_at),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ══════════════════════════════════════════════════════════════════════════════
-- Apply same schema to test database
-- ══════════════════════════════════════════════════════════════════════════════
USE cro_chat_test;

CREATE TABLE IF NOT EXISTS tasks (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id          INT UNSIGNED NOT NULL,
    channel_id        INT UNSIGNED NULL,
    title             VARCHAR(500) NOT NULL,
    description       TEXT NULL,
    status            ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
    priority          ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    created_by        INT UNSIGNED NOT NULL,
    source_message_id INT UNSIGNED NULL,
    thread_id         INT UNSIGNED NULL,
    due_date          DATETIME NULL,
    completed_at      DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_status (space_id, status),
    INDEX idx_space_due (space_id, due_date),
    INDEX idx_channel (channel_id),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (space_id)          REFERENCES spaces(id)   ON DELETE CASCADE,
    FOREIGN KEY (channel_id)        REFERENCES channels(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)        REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (source_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    FOREIGN KEY (thread_id)         REFERENCES threads(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_assignees (
    task_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, user_id),
    INDEX idx_user_tasks (user_id),
    FOREIGN KEY (task_id)     REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task (task_id, created_at),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_reminders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    remind_at   DATETIME NOT NULL,
    sent_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pending (sent_at, remind_at),
    INDEX idx_task (task_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_activity (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    action     VARCHAR(50) NOT NULL,
    old_value  VARCHAR(200) NULL,
    new_value  VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task (task_id, created_at),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
