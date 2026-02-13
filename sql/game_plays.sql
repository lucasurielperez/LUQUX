CREATE TABLE IF NOT EXISTS game_plays (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  duration_ms INT NULL,
  attempts INT NULL,
  score INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_game_plays_player_game (player_id, game_id),
  KEY idx_game_plays_game (game_id),
  CONSTRAINT fk_game_plays_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_plays_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO games (code, name, is_active, base_points)
SELECT 'sumador', 'Sumador', 1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM games WHERE code = 'sumador'
);
