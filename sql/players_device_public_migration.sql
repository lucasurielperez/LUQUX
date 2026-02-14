-- Migración: agrega identificación por dispositivo y código público para jugadores.
ALTER TABLE players
  ADD COLUMN IF NOT EXISTS device_fingerprint VARCHAR(255) NULL AFTER display_name,
  ADD COLUMN IF NOT EXISTS public_code VARCHAR(20) NULL AFTER device_fingerprint;

-- Completa códigos públicos faltantes con formato único derivado del id.
UPDATE players
SET public_code = CONCAT('P', LPAD(CONV(id, 10, 36), 7, '0'))
WHERE public_code IS NULL OR public_code = '';

ALTER TABLE players
  MODIFY COLUMN public_code VARCHAR(20) NOT NULL,
  ADD UNIQUE KEY uq_players_public_code (public_code),
  ADD UNIQUE KEY uq_players_device_fingerprint (device_fingerprint);
