from io import BytesIO

import pytest

from app import app


@pytest.fixture
def client():
    app.config["TESTING"] = True
    with app.test_client() as client:
        yield client


def test_requires_pdf_file(client):
    response = client.post("/api/summarize", data={})
    assert response.status_code == 400


def test_invalid_extension_returns_400(client):
    response = client.post(
        "/api/summarize",
        data={"pdf": (BytesIO(b"not-pdf"), "demo.txt")},
        content_type="multipart/form-data",
    )
    assert response.status_code == 400
