# Juego de prueba: Sumador

## Archivos
- `index.html`: pantalla pública de bienvenida/registro para el cumple.
- `welcome.js`: lógica de identidad por dispositivo (`localStorage` + API pública).
- `admin/sumador.html`: UI del juego para jugadores.
- `admin/api.php`: endpoints públicos para jugadores (`player_register`, `player_me`, `player_rename`) y juego (`resolve_player`, `sumador_start`, `sumador_finish`).
- `sql/game_plays.sql`: crea tabla `game_plays` e inserta juego `sumador` si no existe.
- `sql/players_token_migration.sql`: agrega `players.player_token` único y rellena tokens faltantes.
- `sql/players_device_public_migration.sql`: asegura `players.device_fingerprint` y `players.public_code` como únicos.

## Seguridad de identidad (player_token)
- Ahora el juego acepta `token` en la URL para evitar spoof de `player_id`.
- Formato recomendado: `admin/sumador.html?token=<player_token>`.
- Retrocompatibilidad: si no hay token, el backend todavía acepta `player_id`.

### Cómo generar tokens para jugadores
1. Ejecutar `sql/players_token_migration.sql` (genera token para todos los jugadores sin token).
2. Para regenerar uno puntual:
   ```sql
   UPDATE players
   SET player_token = LOWER(SHA2(CONCAT(UUID(), '-', id, '-', RAND()), 256))
   WHERE id = 123;
   ```
3. Para resolver identidad desde token (público):
   - `GET admin/api.php?action=resolve_player&player_token=<token>`

## Cómo probar
1. Ejecutar `sql/game_plays.sql` en la base de datos.
2. Ejecutar `sql/players_token_migration.sql` en la base de datos.
3. Ejecutar `sql/players_device_public_migration.sql` en la base de datos.
4. Abrir `index.html` y registrar un nombre.
5. Verificar que redirige a `admin/sumador.html?player_id=...`.
6. Volver a `index.html` y refrescar: debe reconocer el mismo dispositivo y mostrar "Hola <nombre>".
7. Ir a `admin/admin.html` y revisar leaderboard/eventos.
