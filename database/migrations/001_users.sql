-- Everyone who can log in: admins, teachers and students.
CREATE TABLE users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(40)  NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(190) NULL,
  password_hash        VARCHAR(255) NOT NULL,
  role                 ENUM('admin','teacher','student') NOT NULL,
  status               ENUM('active','disabled') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at           DATETIME NOT NULL,
  last_login_at        DATETIME NULL,
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_email (email),
  KEY idx_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
