# LUCAS - Gestor de Proyectos Multiusuario

Aplicación web para gestión de proyectos con PHP + MySQL + JS vanilla, ahora con autenticación multiusuario y administración centralizada de cuentas.

## Qué incluye

- Login con opción **¡Recordarme!**.
- Registro online de nuevos usuarios con aprobación manual.
- Superusuario `lucas` capaz de aceptar usuarios, borrarlos y resetear sus contraseñas.
- Todos los proyectos, tareas y rutinas viven dentro de la base general `lucas_projects`, aislados por `user_id`.
- Migración automática: si ya había datos cargados, pasan a pertenecer al usuario `lucas`.

## Credenciales iniciales

- Usuario: `lucas`
- Contraseña inicial: `12345678`

## Estructura

```
LUCAS/
  config/database.php
  includes/
    auth.php
    functions.php
    project_functions.php
    task_functions.php
    recurring_task_functions.php
    header.php
    footer.php
  public/
    index.php
    login.php
    register.php
    logout.php
    user_admin.php
    dashboard.php
    projects.php
    project_create.php
    project_edit.php
    project_delete.php
    project_view.php
    task_create.php
    task_edit.php
    task_delete.php
    recurring_tasks.php
  assets/css/style.css
  assets/js/app.js
  sql/schema.sql
  sql/seed.sql
```

## Instalación (XAMPP/Laragon/hosting compartido)

1. Copiar la carpeta `LUCAS` al directorio web (`htdocs` o `www`).
2. Crear la base con `LUCAS/sql/schema.sql`.
3. Cargar datos demo con `LUCAS/sql/seed.sql` (opcional).
4. Editar `LUCAS/config/database.php` con tus credenciales.
5. Abrir en navegador: `http://localhost/LUCAS/public/`.

## Flujo de usuarios

1. Un usuario nuevo se registra desde `register.php`.
2. Queda en estado `pendiente`.
3. `lucas` entra a `user_admin.php` y puede:
   - aceptar usuarios,
   - resetear contraseñas,
   - borrar usuarios y todos sus datos.
4. Cada usuario ve únicamente sus propios proyectos, tareas y rutinas.

## Notas técnicas

- PDO con sentencias preparadas.
- `password_hash` / `password_verify` para contraseñas.
- Cookie persistente para “Recordarme”.
- Bootstrapping automático del esquema para agregar tablas/columnas faltantes en instalaciones existentes.
- Escape de salida con `htmlspecialchars`.
