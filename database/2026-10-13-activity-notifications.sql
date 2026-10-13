-- Capture the actor's administrative role at the time of the event.
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS notification_admin TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (notification_admin IN (0,1));

CREATE TABLE IF NOT EXISTS notification_reads (
    user_id BIGINT UNSIGNED NOT NULL,
    audit_log_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id,audit_log_id),
    KEY idx_notification_reads_event (audit_log_id),
    CONSTRAINT fk_notification_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_reads_event FOREIGN KEY (audit_log_id) REFERENCES audit_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
