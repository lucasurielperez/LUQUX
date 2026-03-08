import os
from typing import Iterable

import requests
from flask import Flask, jsonify, render_template, request
from pypdf import PdfReader


DEFAULT_OLLAMA_URL = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434/api/generate")
DEFAULT_MODEL = os.getenv("OLLAMA_MODEL", "llama3.1:8b")
MAX_CHARS = 12000

app = Flask(__name__)


def extract_pdf_text(file_storage) -> str:
    """Extract plain text from all pages of an uploaded PDF file."""
    reader = PdfReader(file_storage)
    pages: Iterable[str] = (
        (page.extract_text() or "").strip() for page in reader.pages
    )
    text = "\n\n".join(page for page in pages if page)
    if not text:
        raise ValueError("No se pudo extraer texto del PDF.")
    return text


def request_summary(text: str, model: str = DEFAULT_MODEL) -> str:
    """Send prompt to local Ollama and return a short summary."""
    shortened = text[:MAX_CHARS]
    prompt = (
        "Resume el siguiente documento en español en 4-6 viñetas cortas. "
        "Debe ser claro para alguien no técnico.\n\n"
        f"DOCUMENTO:\n{shortened}"
    )

    response = requests.post(
        DEFAULT_OLLAMA_URL,
        json={
            "model": model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "temperature": 0.2,
                "num_predict": 180,
            },
        },
        timeout=120,
    )
    response.raise_for_status()
    payload = response.json()
    summary = (payload.get("response") or "").strip()
    if not summary:
        raise ValueError("El modelo local no devolvió un resumen.")
    return summary


@app.get("/")
def index():
    return render_template("index.html", default_model=DEFAULT_MODEL)


@app.post("/api/summarize")
def summarize_pdf():
    file = request.files.get("pdf")
    model = request.form.get("model", DEFAULT_MODEL)

    if not file or not file.filename.lower().endswith(".pdf"):
        return jsonify({"error": "Sube un archivo PDF válido."}), 400

    try:
        text = extract_pdf_text(file)
        summary = request_summary(text, model=model)
        return jsonify({"summary": summary})
    except requests.RequestException as exc:
        return (
            jsonify(
                {
                    "error": "No fue posible contactar al modelo local.",
                    "details": str(exc),
                }
            ),
            502,
        )
    except ValueError as exc:
        return jsonify({"error": str(exc)}), 400
    except Exception as exc:  # pragma: no cover
        return jsonify({"error": "Error inesperado.", "details": str(exc)}), 500


if __name__ == "__main__":
    app.run(debug=True, host="0.0.0.0", port=5050)
