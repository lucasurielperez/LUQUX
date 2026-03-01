INSERT INTO games (code, name, is_active, base_points)
SELECT 'luzverde2', 'Muévete Luz Verde 2', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM games WHERE code = 'luzverde2');

CREATE TABLE IF NOT EXISTS luzverde2_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  is_active TINYINT NOT NULL DEFAULT 1,
  state VARCHAR(16) NOT NULL DEFAULT 'WAITING',
  round_no INT NOT NULL DEFAULT 0,
  sensitivity_level TINYINT NOT NULL DEFAULT 5,
  base_points INT NOT NULL DEFAULT 10,
  rest_seconds INT NOT NULL DEFAULT 60,
  rest_ends_at TIMESTAMP NULL DEFAULT NULL,
  round_alive_start INT NOT NULL DEFAULT 0,
  round_eliminated_count INT NOT NULL DEFAULT 0,
  calibration_enabled TINYINT NOT NULL DEFAULT 1,
  calibration_factor_min DOUBLE NOT NULL DEFAULT 0.8,
  calibration_factor_max DOUBLE NOT NULL DEFAULT 1.2,
  calibration_noise_floor DOUBLE NOT NULL DEFAULT 0.005,
  still_window_ms INT NOT NULL DEFAULT 6000,
  still_min_threshold DOUBLE NOT NULL DEFAULT 0.08,
  still_grace_ms INT NOT NULL DEFAULT 2000,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_luzverde2_sessions_active (is_active),
  KEY idx_luzverde2_sessions_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS luzverde2_participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  eliminated_at TIMESTAMP NULL DEFAULT NULL,
  eliminated_order INT NULL DEFAULT NULL,
  eliminated_round INT NULL DEFAULT NULL,
  eliminated_reason VARCHAR(32) NULL DEFAULT NULL,
  armed TINYINT NOT NULL DEFAULT 0,
  armed_at TIMESTAMP NULL DEFAULT NULL,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  last_motion_score DOUBLE NULL DEFAULT NULL,
  last_normalized_score DOUBLE NULL DEFAULT NULL,
  calibration_factor DOUBLE NOT NULL DEFAULT 1.0,
  calibrated_at TIMESTAMP NULL DEFAULT NULL,
  calibration_invalid TINYINT NOT NULL DEFAULT 0,
  too_still_ms INT NOT NULL DEFAULT 0,
  strikes INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_luzverde2_session_player (session_id, player_id),
  KEY idx_luzverde2_participants_session (session_id),
  KEY idx_luzverde2_participants_eliminated_at (eliminated_at),
  CONSTRAINT fk_luzverde2_participants_session FOREIGN KEY (session_id) REFERENCES luzverde2_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_luzverde2_participants_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
