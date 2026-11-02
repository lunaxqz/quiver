-- A student's answer to one question, with its points and feedback.
CREATE TABLE responses (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id       BIGINT UNSIGNED NOT NULL,
  quiz_question_id INT UNSIGNED NOT NULL,
  answer_text      TEXT NULL,
  revision         INT UNSIGNED NOT NULL DEFAULT 0,
  auto_points      DECIMAL(5,2) NULL,
  points_awarded   DECIMAL(5,2) NULL,
  feedback         TEXT NULL,
  graded_by        INT UNSIGNED NULL,
  graded_at        DATETIME NULL,
  UNIQUE KEY uq_response (attempt_id, quiz_question_id),
  FOREIGN KEY (attempt_id)       REFERENCES quiz_attempts(id),
  FOREIGN KEY (quiz_question_id) REFERENCES quiz_questions(id),
  FOREIGN KEY (graded_by)        REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
