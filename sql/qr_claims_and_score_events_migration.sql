CREATE TABLE IF NOT EXISTS qr_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  qr_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_claims_once (qr_id, player_id),
  KEY idx_qr_claims_qr (qr_id),
  KEY idx_qr_claims_player (player_id),
  CONSTRAINT fk_qr_claims_qr FOREIGN KEY (qr_id) REFERENCES qr_codes(id) ON DELETE CASCADE,
  CONSTRAINT fk_qr_claims_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db_name = DATABASE();

-- Garantiza índice por qr_id/player_id aun si la tabla existía sin ellos.
SET @has_idx_qr := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND INDEX_NAME = 'idx_qr_claims_qr'
);
SET @sql := IF(@has_idx_qr = 0, 'ALTER TABLE qr_claims ADD KEY idx_qr_claims_qr (qr_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_player := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'qr_claims' AND INDEX_NAME = 'idx_qr_claims_player'
);
SET @sql := IF(@has_idx_player = 0, 'ALTER TABLE qr_claims ADD KEY idx_qr_claims_player (player_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- score_events.secret_qr_id apunta a qr_codes (opción mínima, sin renombrar columna).
SET @old_fk := (
  SELECT k.CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE k
  WHERE k.TABLE_SCHEMA = @db_name
    AND k.TABLE_NAME = 'score_events'
    AND k.COLUMN_NAME = 'secret_qr_id'
    AND k.REFERENCED_TABLE_NAME = 'secret_qrs'
  LIMIT 1
);
SET @sql := IF(@old_fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE score_events DROP FOREIGN KEY ', @old_fk));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @new_fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE k
  WHERE k.TABLE_SCHEMA = @db_name
    AND k.TABLE_NAME = 'score_events'
    AND k.COLUMN_NAME = 'secret_qr_id'
    AND k.REFERENCED_TABLE_NAME = 'qr_codes'
);
SET @sql := IF(
  @new_fk_exists = 0,
  'ALTER TABLE score_events ADD CONSTRAINT fk_score_events_secret_qr_codes FOREIGN KEY (secret_qr_id) REFERENCES qr_codes(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
