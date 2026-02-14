-- Permite hasta 2 jugadores por dispositivo usando slots fijos (1 y 2).
ALTER TABLE players
  ADD COLUMN device_slot TINYINT NOT NULL DEFAULT 1 AFTER device_fingerprint;

UPDATE players
SET device_slot = 1
WHERE device_slot IS NULL OR device_slot NOT IN (1, 2);

ALTER TABLE players
  DROP INDEX uq_players_device_fingerprint;

ALTER TABLE players
  ADD UNIQUE KEY uq_players_device_slot (device_fingerprint, device_slot),
  ADD KEY idx_players_device_fingerprint (device_fingerprint);
