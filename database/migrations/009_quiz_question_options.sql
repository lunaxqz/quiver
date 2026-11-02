-- The quiz's copy of the answer choices, including which are correct (teacher-side only).
CREATE TABLE quiz_question_options (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_question_id INT UNSIGNED NOT NULL,
  label            VARCHAR(500) NOT NULL,
  is_correct       TINYINT(1) NOT NULL DEFAULT 0,
  position         SMALLINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_qq_option_position (quiz_question_id, position),
  FOREIGN KEY (quiz_question_id) REFERENCES quiz_questions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
