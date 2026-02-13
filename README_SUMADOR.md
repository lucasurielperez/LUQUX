# Juego de prueba: Sumador

## Archivos
- `admin/sumador.html`: UI del juego para jugadores.
- `admin/api.php`: endpoints públicos `sumador_start` y `sumador_finish`.
- `sql/game_plays.sql`: crea tabla `game_plays` e inserta juego `sumador` si no existe.

## Cómo probar
1. Ejecutar `sql/game_plays.sql` en la base de datos.
2. Abrir: `admin/sumador.html?player_id=123`.
3. Presionar **Comenzar**, jugar 10 segundos y esperar confirmación final.
4. Ir a `admin/admin.html` y revisar leaderboard/eventos.
5. Reabrir `sumador.html?player_id=123` e intentar jugar de nuevo: debe mostrar **"Ya jugaste este juego"**.
