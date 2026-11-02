-- Which choices a student ticked. Composite primary key stops duplicates.
CREATE TABLE response_options (
  response_id             BIGINT UNSIGNED NOT NULL,
  quiz_question_option_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (response_id, quiz_question_option_id),
  FOREIGN KEY (response_id)             REFERENCES responses(id),
  FOREIGN KEY (quiz_question_option_id) REFERENCES quiz_question_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
