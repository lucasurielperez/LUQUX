USE lucas_projects;

INSERT INTO projects (nombre, descripcion, prioridad_personal, prioridad_real, estado, salud, porcentaje_avance, fecha_limite_precision, fecha_limite_anio, fecha_limite_mes, fecha_limite_dia)
VALUES
('Lanzar portafolio 2027', 'Nueva versión de portafolio con casos, métricas y sección blog.', 5, 4, 'en_progreso', 'saludable', 50, 'month', 2027, 3, NULL),
('Curso de IA aplicado', 'Completar 8 módulos y publicar proyecto final en GitHub.', 4, 5, 'pendiente', 'en_riesgo', 20, 'day', 2026, 12, 12),
('Canal YouTube semanal', 'Sistema de producción para 1 video por semana.', 3, 3, 'pausado', 'trabado', 10, 'year', 2027, NULL, NULL),
('Automatizar finanzas', 'Dashboard personal de gastos e ingresos.', 5, 5, 'en_progreso', 'atrasado', 40, 'day', 2025, 1, 20);

INSERT INTO tasks (proyecto_id, titulo, descripcion, estado, prioridad, fecha_limite, orden_manual) VALUES
(1, 'Definir arquitectura de contenido', 'Mapa de páginas y bloques.', 'hecha', 'alta', '2026-11-20', 1),
(1, 'Diseñar landing principal', 'Wireframe + diseño final.', 'en_progreso', 'alta', '2026-12-05', 2),
(1, 'Cargar estudios de caso', NULL, 'pendiente', 'media', '2027-02-20', 3),
(2, 'Completar módulos 1-4', NULL, 'hecha', 'alta', '2026-09-30', 1),
(2, 'Completar módulos 5-8', NULL, 'pendiente', 'alta', '2026-11-15', 2),
(2, 'Proyecto final y publicación', NULL, 'pendiente', 'alta', '2026-12-10', 3),
(3, 'Definir temas del mes', NULL, 'pendiente', 'media', NULL, 1),
(3, 'Grabar lote de 3 videos', NULL, 'pendiente', 'alta', NULL, 2),
(4, 'Conectar datos bancarios', NULL, 'hecha', 'media', '2025-01-05', 1),
(4, 'Crear panel de KPIs', NULL, 'pendiente', 'alta', '2025-01-15', 2);
