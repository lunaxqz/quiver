-- Tags let teachers filter the bank (used on Day 7).
CREATE TABLE question_tags (
  question_id INT UNSIGNED NOT NULL,
  tag         VARCHAR(40)  NOT NULL,
  PRIMARY KEY (question_id, tag),
  KEY idx_tag (tag),
  FOREIGN KEY (question_id) REFERENCES questions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
