ALTER TABLE game_plays
  ADD COLUMN is_practice TINYINT(1) NOT NULL DEFAULT 0 AFTER game_id;

ALTER TABLE game_plays
  DROP INDEX uq_game_plays_player_game;

ALTER TABLE game_plays
  ADD INDEX idx_gameplays_player_game_practice (player_id, game_id, is_practice);
