-- Append-only history of every grade change. Undo inserts a new event, never deletes.
CREATE TABLE grade_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id BIGINT UNSIGNED NOT NULL,
  points      DECIMAL(5,2) NOT NULL,
  feedback    TEXT NULL,
  graded_by   INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_response (response_id),
  FOREIGN KEY (response_id) REFERENCES responses(id),
  FOREIGN KEY (graded_by)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
