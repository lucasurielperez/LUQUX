INSERT INTO games (code, name, is_active, base_points)
SELECT 'luzverde', 'Muévete Luz Verde', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM games WHERE code = 'luzverde');

CREATE TABLE IF NOT EXISTS luzverde_sessions (
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
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_luzverde_sessions_active (is_active),
  KEY idx_luzverde_sessions_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS luzverde_participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  eliminated_at TIMESTAMP NULL DEFAULT NULL,
  eliminated_order INT NULL DEFAULT NULL,
  eliminated_round INT NULL DEFAULT NULL,
  last_motion_score DOUBLE NULL DEFAULT NULL,
  strikes INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_luzverde_session_player (session_id, player_id),
  KEY idx_luzverde_participants_session (session_id),
  KEY idx_luzverde_participants_eliminated_at (eliminated_at),
  CONSTRAINT fk_luzverde_participants_session FOREIGN KEY (session_id) REFERENCES luzverde_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_luzverde_participants_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
