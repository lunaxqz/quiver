-- The editable question bank. Quizzes get their own copies later.
CREATE TABLE questions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT UNSIGNED NOT NULL,
  type        ENUM('single','true_false','multi','short','essay') NOT NULL,
  prompt      TEXT NOT NULL,
  points      DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  rubric      TEXT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  archived_at DATETIME NULL,
  KEY idx_teacher (teacher_id, archived_at, type),
  FOREIGN KEY (teacher_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
