ALTER TABLE luzverde_participants
  ADD COLUMN IF NOT EXISTS armed TINYINT NOT NULL DEFAULT 0 AFTER last_motion_score,
  ADD COLUMN IF NOT EXISTS armed_at TIMESTAMP NULL DEFAULT NULL AFTER armed,
  ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMP NULL DEFAULT NULL AFTER armed_at,
  ADD COLUMN IF NOT EXISTS offline_eliminated TINYINT NOT NULL DEFAULT 0 AFTER last_seen_at,
  ADD COLUMN IF NOT EXISTS eliminated_reason VARCHAR(40) NULL DEFAULT NULL AFTER eliminated_round;

ALTER TABLE luzverde_participants
  ADD INDEX IF NOT EXISTS idx_luzverde_participants_armed_alive (session_id, armed, eliminated_at),
  ADD INDEX IF NOT EXISTS idx_luzverde_participants_last_seen (session_id, last_seen_at);
