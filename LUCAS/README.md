# LUCAS - Gestor de Proyectos Personales

Aplicación web simple para gestión personal de proyectos con PHP + MySQL + JS vanilla.

## Estructura

```
LUCAS/
  config/database.php
  includes/
    functions.php
    project_functions.php
    task_functions.php
    header.php
    footer.php
    project_form.php
    task_form.php
  public/
    index.php
    dashboard.php
    projects.php
    project_create.php
    project_edit.php
    project_delete.php
    project_view.php
    task_create.php
    task_edit.php
    task_delete.php
    kanban.php
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

## Prueba rápida

- Crear proyecto desde `+ Nuevo`.
- Cargar tareas desde detalle del proyecto.
- Ver dashboard, filtros y kanban.

## Notas técnicas

- PDO con sentencias preparadas.
- Escape de salida con `htmlspecialchars`.
- Cálculo automático de avance y salud del proyecto.
- Fecha límite con precisión parcial (`year`, `month`, `day`).
