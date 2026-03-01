ALTER TABLE luzverde_sessions
  ADD COLUMN round_started_at TIMESTAMP NULL DEFAULT NULL AFTER round_no,
  ADD COLUMN round_max_ms INT NOT NULL DEFAULT 25000 AFTER rest_seconds,
  ADD COLUMN base_sensitivity TINYINT NOT NULL DEFAULT 15 AFTER sensitivity_level,
  ADD COLUMN difficulty_step TINYINT NOT NULL DEFAULT 2 AFTER base_sensitivity,
  ADD COLUMN difficulty_cap TINYINT NOT NULL DEFAULT 40 AFTER difficulty_step,
  ADD COLUMN still_window_ms INT NOT NULL DEFAULT 6000 AFTER difficulty_cap,
  ADD COLUMN still_grace_ms INT NOT NULL DEFAULT 2000 AFTER still_window_ms,
  ADD COLUMN still_min DOUBLE NOT NULL DEFAULT 0.015 AFTER still_grace_ms;

ALTER TABLE luzverde_participants
  ADD COLUMN still_since_at TIMESTAMP NULL DEFAULT NULL AFTER last_motion_score,
  ADD COLUMN device_multiplier DOUBLE NOT NULL DEFAULT 1 AFTER still_since_at,
  ADD COLUMN calibration_samples INT NOT NULL DEFAULT 0 AFTER device_multiplier,
  ADD COLUMN calibration_sum DOUBLE NOT NULL DEFAULT 0 AFTER calibration_samples,
  ADD COLUMN calibration_sum_sq DOUBLE NOT NULL DEFAULT 0 AFTER calibration_sum,
  ADD COLUMN calibration_stddev DOUBLE NULL DEFAULT NULL AFTER calibration_sum_sq,
  ADD COLUMN calibration_valid TINYINT NOT NULL DEFAULT 1 AFTER calibration_stddev;
