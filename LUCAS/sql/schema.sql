CREATE DATABASE IF NOT EXISTS lucas_projects CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lucas_projects;

CREATE TABLE IF NOT EXISTS projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NOT NULL,
    prioridad_personal TINYINT UNSIGNED NOT NULL,
    prioridad_real TINYINT UNSIGNED NOT NULL,
    estado ENUM('idea','pendiente','en_progreso','pausado','completado','cancelado') NOT NULL DEFAULT 'idea',
    salud ENUM('saludable','en_riesgo','atrasado','trabado') NOT NULL DEFAULT 'saludable',
    porcentaje_avance TINYINT UNSIGNED NOT NULL DEFAULT 0,
    fecha_limite_precision ENUM('year','month','day') NULL,
    fecha_limite_anio SMALLINT UNSIGNED NULL,
    fecha_limite_mes TINYINT UNSIGNED NULL,
    fecha_limite_dia TINYINT UNSIGNED NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_prioridad_personal CHECK (prioridad_personal BETWEEN 1 AND 5),
    CONSTRAINT chk_prioridad_real CHECK (prioridad_real BETWEEN 1 AND 5),
    CONSTRAINT chk_avance CHECK (porcentaje_avance BETWEEN 0 AND 100),
    CONSTRAINT chk_fecha_mes CHECK (fecha_limite_mes IS NULL OR fecha_limite_mes BETWEEN 1 AND 12),
    CONSTRAINT chk_fecha_dia CHECK (fecha_limite_dia IS NULL OR fecha_limite_dia BETWEEN 1 AND 31)
);

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proyecto_id BIGINT UNSIGNED NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    descripcion TEXT NULL,
    estado ENUM('pendiente','en_progreso','hecha') NOT NULL DEFAULT 'pendiente',
    prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
    fecha_limite DATE NULL,
    orden_manual INT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_project FOREIGN KEY (proyecto_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_tasks_project_estado (proyecto_id, estado),
    INDEX idx_tasks_fecha_limite (fecha_limite),
    INDEX idx_tasks_prioridad (prioridad)
);

CREATE INDEX idx_projects_estado ON projects(estado);
CREATE INDEX idx_projects_salud ON projects(salud);
CREATE INDEX idx_projects_prioridad_real ON projects(prioridad_real);
CREATE INDEX idx_projects_prioridad_personal ON projects(prioridad_personal);
CREATE INDEX idx_projects_fecha_limite ON projects(fecha_limite_anio, fecha_limite_mes, fecha_limite_dia);
CREATE INDEX idx_projects_actualizacion ON projects(fecha_actualizacion);
