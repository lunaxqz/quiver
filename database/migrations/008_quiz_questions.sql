-- A quiz's OWN copy of each question. source_question_id is provenance only and is never read for grading.
CREATE TABLE quiz_questions (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id            INT UNSIGNED NOT NULL,
  source_question_id INT UNSIGNED NULL,
  position           SMALLINT UNSIGNED NOT NULL,
  type               ENUM('single','true_false','multi','short','essay') NOT NULL,
  prompt             TEXT NOT NULL,
  points             DECIMAL(5,2) NOT NULL,
  rubric             TEXT NULL,
  UNIQUE KEY uq_position (quiz_id, position),
  KEY idx_source (source_question_id),
  FOREIGN KEY (quiz_id) REFERENCES quizzes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
