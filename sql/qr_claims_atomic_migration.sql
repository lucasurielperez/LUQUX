-- Migration: atomic QR secret claims + idempotency support.
-- Safe to run multiple times.

SET @db_name := DATABASE();

-- qr_claims.status
SET @has_status := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND COLUMN_NAME = 'status'
);
SET @sql := IF(
  @has_status = 0,
  "ALTER TABLE qr_claims ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER applied_points",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- qr_claims.applied_at
SET @has_applied_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND COLUMN_NAME = 'applied_at'
);
SET @sql := IF(
  @has_applied_at = 0,
  "ALTER TABLE qr_claims ADD COLUMN applied_at TIMESTAMP NULL DEFAULT NULL AFTER status",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- qr_claims.error
SET @has_error := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND COLUMN_NAME = 'error'
);
SET @sql := IF(
  @has_error = 0,
  "ALTER TABLE qr_claims ADD COLUMN `error` VARCHAR(200) NULL DEFAULT NULL AFTER applied_at",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for cleanup/monitoring (prefer created_at if present, fallback to claimed_at).
SET @has_created_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND COLUMN_NAME = 'created_at'
);
SET @has_claimed_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND COLUMN_NAME = 'claimed_at'
);
SET @has_idx_status_created := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND INDEX_NAME = 'idx_status_created'
);
SET @sql := IF(
  @has_idx_status_created > 0,
  'SELECT 1',
  IF(
    @has_created_at > 0,
    'ALTER TABLE qr_claims ADD KEY idx_status_created (status, created_at)',
    IF(
      @has_claimed_at > 0,
      'ALTER TABLE qr_claims ADD KEY idx_status_created (status, claimed_at)',
      'SELECT 1'
    )
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- score_events.idempotency_key
SET @has_idempotency_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'score_events' AND COLUMN_NAME = 'idempotency_key'
);
SET @sql := IF(
  @has_idempotency_col = 0,
  "ALTER TABLE score_events ADD COLUMN idempotency_key VARCHAR(120) NULL DEFAULT NULL AFTER note",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique key for QR-secret idempotency.
SET @has_uq_idempotency := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'score_events' AND INDEX_NAME = 'uq_score_events_idempotency'
);
SET @sql := IF(
  @has_uq_idempotency = 0,
  'ALTER TABLE score_events ADD UNIQUE KEY uq_score_events_idempotency (idempotency_key)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
