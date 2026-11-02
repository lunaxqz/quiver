-- History for time extensions and voided attempts (used on Day 18).
CREATE TABLE attempt_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id      BIGINT UNSIGNED NOT NULL,
  kind            ENUM('time_extended','voided') NOT NULL,
  old_deadline_at DATETIME NULL,
  new_deadline_at DATETIME NULL,
  note            VARCHAR(255) NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL,
  KEY idx_attempt (attempt_id),
  FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
