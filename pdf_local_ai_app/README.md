# App: Resumen de PDF con IA local

Esta app permite subir un PDF y devolver un resumen corto usando un modelo de IA que corre localmente (por ejemplo, con **Ollama**).

## Requisitos

- Python 3.10+
- Un modelo local activo en Ollama (`ollama serve`)

## Instalación

```bash
cd pdf_local_ai_app
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

## Configuración opcional

- `OLLAMA_URL` (por defecto `http://127.0.0.1:11434/api/generate`)
- `OLLAMA_MODEL` (por defecto `llama3.1:8b`)

## Ejecutar

```bash
python app.py
```

Abrir en `http://localhost:5050`.

## Flujo

1. Subes un PDF.
2. Se extrae el texto con `pypdf`.
3. Se envía un prompt breve al modelo local.
4. Se retorna un resumen de 4 a 6 viñetas.
