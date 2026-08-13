from __future__ import annotations

from fastapi import FastAPI
from fastapi.responses import JSONResponse

app = FastAPI(title="Z-Pay Swift Document AI (Retired)", version="1.1.0")


@app.get("/health")
def health() -> dict[str, object]:
    return {
        "success": True,
        "code": "DOCUMENT_AI_RETIRED",
        "message": "Document AI verification is retired.",
        "data": {"service": "document-ai", "status": "RETIRED"},
    }


@app.post("/v1/document/verify")
def verify_document() -> JSONResponse:
    return JSONResponse(
        status_code=410,
        content={
            "success": False,
            "code": "DOCUMENT_AI_RETIRED",
            "message": "Document AI verification is no longer available.",
            "data": {},
        },
    )
