-- Rate limiting for login and class-code joins.
CREATE TABLE login_attempts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind       ENUM('login','join') NOT NULL,
  identifier VARCHAR(100) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  succeeded  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_identifier (kind, identifier, created_at),
  KEY idx_ip (kind, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
