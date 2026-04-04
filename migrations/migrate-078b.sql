-- SnapSmack migrate-079.sql
-- Adds one-time recovery code and forced password change flag to snap_users.
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.

-- Add recovery_code_hash column (stores bcrypt hash of one-time code)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'snap_users'
      AND COLUMN_NAME  = 'recovery_code_hash'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE snap_users ADD COLUMN recovery_code_hash VARCHAR(255) NULL DEFAULT NULL AFTER email',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add force_password_change flag (set after recovery code login)
SET @col2_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'snap_users'
      AND COLUMN_NAME  = 'force_password_change'
);
SET @sql2 = IF(@col2_exists = 0,
    'ALTER TABLE snap_users ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER recovery_code_hash',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
