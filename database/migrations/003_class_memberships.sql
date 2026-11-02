-- Which students are in which classes. Many to many.
CREATE TABLE class_memberships (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id   INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  joined_at  DATETIME NOT NULL,
  removed_at DATETIME NULL,
  UNIQUE KEY uq_membership (class_id, student_id),
  KEY idx_student (student_id),
  FOREIGN KEY (class_id)   REFERENCES classes(id),
  FOREIGN KEY (student_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
