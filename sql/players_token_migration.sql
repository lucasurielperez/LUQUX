-- Migración: agrega token público para identificar jugadores sin exponer player_id.
ALTER TABLE players
  ADD COLUMN IF NOT EXISTS player_token VARCHAR(128) NULL AFTER public_code;

-- Completa tokens faltantes (64 chars hex) para jugadores existentes.
UPDATE players
SET player_token = LOWER(SHA2(CONCAT(UUID(), '-', id, '-', RAND()), 256))
WHERE player_token IS NULL OR player_token = '';

ALTER TABLE players
  MODIFY COLUMN player_token VARCHAR(128) NOT NULL;

ALTER TABLE players
  ADD UNIQUE KEY uq_players_player_token (player_token);

-- Ejemplo para set manual de token para un jugador puntual.
-- UPDATE players
-- SET player_token = LOWER(SHA2(CONCAT(UUID(), '-', id, '-', RAND()), 256))
-- WHERE id = 123;
