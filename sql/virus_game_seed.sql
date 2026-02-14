INSERT INTO games (code, name, is_active, base_points)
SELECT 'virus', 'Virus', 0, 0
WHERE NOT EXISTS (
  SELECT 1 FROM games WHERE code = 'virus'
);
