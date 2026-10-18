-- SNAPSMACK_EOF_HEADER
CREATE TABLE IF NOT EXISTS troll_devices (
  device_id varchar(80) NOT NULL,
  token_hash char(64) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  tools_json mediumtext NOT NULL,
  last_seen_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS troll_enrolments (
  code_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (code_hash),
  KEY idx_enrolment_expiry (expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS troll_jobs (
  id char(36) NOT NULL,
  request_id varchar(100) NOT NULL,
  actor varchar(24) NOT NULL,
  tool_name varchar(160) NOT NULL,
  arguments_json mediumtext NOT NULL,
  status enum('queued','claimed','done','failed','expired') NOT NULL DEFAULT 'queued',
  device_id varchar(80) DEFAULT NULL,
  result_json mediumtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL,
  claimed_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_job_queue (status, expires_at, created_at),
  KEY idx_job_request (actor, request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS troll_audit (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  occurred_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor varchar(80) NOT NULL,
  event_name varchar(80) NOT NULL,
  subject varchar(190) DEFAULT NULL,
  outcome varchar(80) NOT NULL,
  remote_hash char(64) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== SNAPSMACK EOF =====
