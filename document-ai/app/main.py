from __future__ import annotations

import asyncio
import os

from fastapi import Depends, FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

from .security import get_settings, validate_document_type, validate_upload, verify_ai_key

app = FastAPI(title="Z-Pay Swift Document AI", version="1.0.0")


@app.get("/health")
def health() -> dict[str, object]:
    return {
        "success": True,
        "code": "OK",
        "message": "Document AI service healthy.",
        "data": {"service": "document-ai"},
    }


def _cleanup_files(paths: list[str], keep: bool = False) -> None:
    if keep:
        return
    for path in paths:
        try:
            if path and os.path.isfile(path):
                os.remove(path)
        except OSError:
            pass


def _ocr_unavailable_response() -> JSONResponse:
    return JSONResponse(
        status_code=503,
        content={
            "success": False,
            "code": "OCR_ENGINE_UNAVAILABLE",
            "message": "Document OCR engine is temporarily unavailable.",
            "data": {},
        },
    )


@app.post("/v1/document/verify")
async def verify_document(
    image: UploadFile = File(...),
    document_type: str = Form(...),
    _: None = Depends(verify_ai_key),
) -> dict[str, object]:
    settings = get_settings()
    normalized_type = validate_document_type(document_type)
    data = await validate_upload(image, settings.max_image_bytes)
    ext = ".png" if (image.content_type or "").lower().endswith("png") else ".jpg"
    paths: list[str] = []

    try:
        # Keep Passenger /health lightweight: load cv2, PaddleOCR, and parsers only for real OCR requests.
        from .bd_document_parser import parse_document
        from .image_preprocess import preprocess_document, write_temp_image
        from .ocr_engine import run_ocr

        input_path = write_temp_image(data, settings.temp_dir, ext)
        paths.append(input_path)
        processed_path, crop_used, warnings = preprocess_document(input_path, settings.temp_dir)
        paths.append(processed_path)
        try:
            ocr_items = await asyncio.wait_for(
                asyncio.to_thread(run_ocr, processed_path),
                timeout=settings.ocr_timeout_seconds,
            )
        except (asyncio.TimeoutError, Exception):
            return _ocr_unavailable_response()
        parsed = parse_document(normalized_type, ocr_items, crop_used, warnings)
        code = "DOCUMENT_LOW_CONFIDENCE" if parsed["needs_manual_review"] else "DOCUMENT_PARSED"
        message = (
            "Could not read clearly. Please review and correct manually."
            if parsed["needs_manual_review"]
            else "Document parsed successfully."
        )
        return {"success": True, "code": code, "message": message, "data": parsed}
    except Exception:
        return _ocr_unavailable_response()
    finally:
        _cleanup_files(list(dict.fromkeys(paths)), keep=settings.debug_keep_files)
