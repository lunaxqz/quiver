-- A class belongs to one teacher. Archived, never deleted.
CREATE TABLE classes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id   INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  subject      VARCHAR(120) NULL,
  section      VARCHAR(60)  NULL,
  school_year  VARCHAR(20)  NOT NULL,
  join_code    VARCHAR(8)   NOT NULL,
  code_enabled TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL,
  archived_at  DATETIME NULL,
  UNIQUE KEY uq_join_code (join_code),
  KEY idx_teacher (teacher_id, archived_at),
  FOREIGN KEY (teacher_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
