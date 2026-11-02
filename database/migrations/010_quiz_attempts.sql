-- One student's sitting of one quiz. Retakes create new rows.
CREATE TABLE quiz_attempts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id            INT UNSIGNED NOT NULL,
  student_id         INT UNSIGNED NOT NULL,
  attempt_no         TINYINT UNSIGNED NOT NULL,
  status             ENUM('in_progress','submitted','graded','voided') NOT NULL DEFAULT 'in_progress',
  submit_reason      ENUM('student','timeout','teacher') NULL,
  started_at         DATETIME NOT NULL,
  deadline_at        DATETIME NOT NULL,
  last_seen_at       DATETIME NULL,
  submitted_at       DATETIME NULL,
  score              DECIMAL(6,2) NULL,
  score_released_at  DATETIME NULL,
  review_released_at DATETIME NULL,
  UNIQUE KEY uq_attempt (quiz_id, student_id, attempt_no),
  KEY idx_status_deadline (status, deadline_at),
  KEY idx_student (student_id),
  FOREIGN KEY (quiz_id)    REFERENCES quizzes(id),
  FOREIGN KEY (student_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
