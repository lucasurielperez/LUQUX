const form = document.getElementById("summarize-form");
const result = document.getElementById("result");

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  result.textContent = "Procesando PDF...";

  const formData = new FormData(form);

  try {
    const response = await fetch("/api/summarize", {
      method: "POST",
      body: formData,
    });

    const payload = await response.json();

    if (!response.ok) {
      result.textContent = payload.error || "Error al generar resumen.";
      return;
    }

    result.textContent = payload.summary;
  } catch (error) {
    result.textContent = `Error de red: ${error.message}`;
  }
});
