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

## Juego Virus 🦠

### Migración de base
Ejecutar también:
- `sql/virus_game.sql`

### Configuración
En `admin/config.php` configurar:
- `virus_qr_secret`: secreto largo para firma HMAC de QR.

### Flujo admin
- `admin/admin.html`:
  - **Encender Virus**: crea sesión nueva, asigna roles 50/50, resetea power=1.
  - **Apagar Virus**: cierra sesión y guarda snapshot congelado de leaderboard.
  - **Reset virus session**: reinicia sesión para pruebas.

### Flujo jugador
- `index.html` redirige a `admin/virus.html`.
- En `admin/virus.html` el jugador puede:
  - mostrar su QR firmado,
  - escanear QR de otro,
  - ver oponentes pendientes.
- Después de cada escaneo válido se muestra un overlay de 1 segundo con roles, power pre/post y resultado.

### Endpoints Virus (action en `admin/api.php`)
- Jugador:
  - `virus_status`
  - `virus_my_qr`
  - `virus_scan`
- Admin:
  - `admin_virus_toggle`
  - `admin_virus_reset_session`
  - `admin_virus_leaderboard`


### Prueba manual de overlays Virus (3s + fondo animado)
1. Iniciar sesión Virus desde `admin/admin.html`.
2. Preparar 2 jugadores activos (A y B) con `admin/virus.html`.
3. Forzar **VV**: iniciar una sesión donde ambos tengan rol virus (o repetir hasta obtenerlo), escanear A→B y validar fondo verde con burbujas, iconos grandes y marcador `antes → después`.
4. Forzar **AA**: ambos antídoto, escanear y validar fondo azul con cruces/sparkles.
5. Forzar **VA**: virus vs antídoto, escanear y validar fondo dividido + impacto central breve (`✨`).
6. Repetir el mismo par A↔B para provocar `409 ALREADY_INTERACTED` y validar overlay de advertencia con texto “YA INTERACTUARON” y handles.
7. Probar errores genéricos (QR inválido, expirado, self scan, juego inactivo) y validar overlay de error con scan-lines suaves.
8. Activar en el sistema `prefers-reduced-motion: reduce` y verificar que los overlays siguen mostrando contenido pero sin animaciones.
