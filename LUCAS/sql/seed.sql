USE lucas_projects;

INSERT INTO users (username, display_name, password_hash, rol, estado)
VALUES ('lucas', 'Lucas', '$2y$12$CQjeQfivxXqnsrQhN5gV7eaIA1.QkpSLsck8wPx5zFSdCrPq9.A5i', 'superusuario', 'activo')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), rol = VALUES(rol), estado = VALUES(estado);

SET @lucas_user_id = (SELECT id FROM users WHERE username = 'lucas' LIMIT 1);

INSERT INTO projects (user_id, nombre, descripcion, prioridad_personal, prioridad_real, estado, salud, porcentaje_avance, fecha_limite_precision, fecha_limite_anio, fecha_limite_mes, fecha_limite_dia)
VALUES
(@lucas_user_id, 'Lanzar portafolio 2027', 'Nueva versión de portafolio con casos, métricas y sección blog.', 5, 4, 'en_progreso', 'saludable', 50, 'month', 2027, 3, NULL),
(@lucas_user_id, 'Curso de IA aplicado', 'Completar 8 módulos y publicar proyecto final en GitHub.', 4, 5, 'pendiente', 'en_riesgo', 20, 'day', 2026, 12, 12),
(@lucas_user_id, 'Canal YouTube semanal', 'Sistema de producción para 1 video por semana.', 3, 3, 'pausado', 'trabado', 10, 'year', 2027, NULL, NULL),
(@lucas_user_id, 'Automatizar finanzas', 'Dashboard personal de gastos e ingresos.', 5, 5, 'en_progreso', 'atrasado', 40, 'day', 2025, 1, 20);

INSERT INTO tasks (proyecto_id, user_id, titulo, descripcion, estado, prioridad, fecha_limite, orden_manual) VALUES
(1, @lucas_user_id, 'Definir arquitectura de contenido', 'Mapa de páginas y bloques.', 'hecha', 'alta', '2026-11-20', 1),
(1, @lucas_user_id, 'Diseñar landing principal', 'Wireframe + diseño final.', 'en_progreso', 'alta', '2026-12-05', 2),
(1, @lucas_user_id, 'Cargar estudios de caso', NULL, 'pendiente', 'media', '2027-02-20', 3),
(2, @lucas_user_id, 'Completar módulos 1-4', NULL, 'hecha', 'alta', '2026-09-30', 1),
(2, @lucas_user_id, 'Completar módulos 5-8', NULL, 'pendiente', 'alta', '2026-11-15', 2),
(2, @lucas_user_id, 'Proyecto final y publicación', NULL, 'pendiente', 'alta', '2026-12-10', 3),
(3, @lucas_user_id, 'Definir temas del mes', NULL, 'pendiente', 'media', NULL, 1),
(3, @lucas_user_id, 'Grabar lote de 3 videos', NULL, 'pendiente', 'alta', NULL, 2),
(4, @lucas_user_id, 'Conectar datos bancarios', NULL, 'hecha', 'media', '2025-01-05', 1),
(4, @lucas_user_id, 'Crear panel de KPIs', NULL, 'pendiente', 'alta', '2025-01-15', 2);

INSERT INTO recurring_tasks (user_id, titulo, descripcion, frecuencia, estado, prioridad) VALUES
(@lucas_user_id, 'Chequear mails', 'Inbox general y respuestas urgentes.', 'diaria', 'pendiente', 'alta'),
(@lucas_user_id, 'Revisión semanal de objetivos', 'Mirar el avance de todos los proyectos.', 'semanal', 'pendiente', 'alta'),
(@lucas_user_id, 'Cierre mensual administrativo', 'Facturación y reportes del mes.', 'mensual', 'pendiente', 'media');
