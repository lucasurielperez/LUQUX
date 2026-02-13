# Juego de prueba: Sumador

## Archivos
- `admin/sumador.html`: UI del juego para jugadores.
- `admin/api.php`: endpoints públicos `resolve_player`, `sumador_start` y `sumador_finish`.
- `sql/game_plays.sql`: crea tabla `game_plays` e inserta juego `sumador` si no existe.
- `sql/players_token_migration.sql`: agrega `players.player_token` único y rellena tokens faltantes.

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
3. Obtener token del jugador (query SQL o endpoint `resolve_player`).
4. Abrir: `admin/sumador.html?token=<token>`.
   - Legacy: `admin/sumador.html?player_id=123` también funciona.
5. Presionar **Comenzar**, jugar 10 segundos y esperar confirmación final.
6. Ir a `admin/admin.html` y revisar leaderboard/eventos.
7. Reabrir `sumador.html?token=<token>` e intentar jugar de nuevo: debe mostrar **"Ya jugaste este juego"**.
