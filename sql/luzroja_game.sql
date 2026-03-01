INSERT INTO games (code, name, is_active, base_points)
SELECT 'luzroja', 'No te quedes quieto', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM games WHERE code = 'luzroja');

CREATE TABLE IF NOT EXISTS luzroja_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  is_active TINYINT NOT NULL DEFAULT 1,
  state VARCHAR(16) NOT NULL DEFAULT 'WAITING',
  round_no INT NOT NULL DEFAULT 0,
  round_started_at TIMESTAMP NULL DEFAULT NULL,
  min_threshold_current DOUBLE NOT NULL DEFAULT 1.2,
  base_min_threshold DOUBLE NOT NULL DEFAULT 1.2,
  difficulty_step DOUBLE NOT NULL DEFAULT 0.3,
  difficulty_cap DOUBLE NOT NULL DEFAULT 6,
  still_window_ms INT NOT NULL DEFAULT 1500,
  base_points INT NOT NULL DEFAULT 10,
  rest_seconds INT NOT NULL DEFAULT 60,
  round_max_ms INT NOT NULL DEFAULT 25000,
  rest_ends_at TIMESTAMP NULL DEFAULT NULL,
  round_alive_start INT NOT NULL DEFAULT 0,
  round_eliminated_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_luzroja_sessions_active (is_active),
  KEY idx_luzroja_sessions_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS luzroja_participants (
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
  low_motion_ms INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_luzroja_session_player (session_id, player_id),
  KEY idx_luzroja_participants_session (session_id),
  KEY idx_luzroja_participants_eliminated_at (eliminated_at),
  CONSTRAINT fk_luzroja_participants_session FOREIGN KEY (session_id) REFERENCES luzroja_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_luzroja_participants_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
