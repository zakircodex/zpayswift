from __future__ import annotations

import os
import secrets
import tempfile
from dataclasses import dataclass

from dotenv import load_dotenv
from fastapi import Header, HTTPException, UploadFile

load_dotenv()


@dataclass(frozen=True)
class Settings:
    ai_key: str
    max_image_bytes: int
    debug_keep_files: bool
    temp_dir: str
    paddle_use_gpu: bool
    ocr_timeout_seconds: int


def _bool_env(name: str, default: bool = False) -> bool:
    value = os.getenv(name, str(default)).strip().lower()
    return value in {"1", "true", "yes", "on"}


def get_settings() -> Settings:
    temp_dir = os.getenv("TEMP_DIR", "").strip()
    if not temp_dir:
        temp_dir = os.path.join(tempfile.gettempdir(), "zpayswift-document-ai")

    max_image_bytes = os.getenv("MAX_IMAGE_BYTES", "").strip()
    if max_image_bytes:
        max_bytes = int(max_image_bytes)
    else:
        max_mb = float(os.getenv("MAX_IMAGE_SIZE_MB", "8").strip() or "8")
        max_bytes = int(max_mb * 1024 * 1024)

    return Settings(
        ai_key=os.getenv("DOCUMENT_AI_KEY", "").strip(),
        max_image_bytes=max(1024, max_bytes),
        debug_keep_files=_bool_env("DEBUG_KEEP_FILES", False),
        temp_dir=temp_dir,
        paddle_use_gpu=_bool_env("PADDLE_USE_GPU", False),
        ocr_timeout_seconds=max(5, int(os.getenv("OCR_TIMEOUT_SECONDS", "45"))),
    )


def verify_ai_key(x_ai_key: str | None = Header(default=None, alias="X-AI-KEY")) -> None:
    settings = get_settings()
    if not settings.ai_key:
        raise HTTPException(status_code=503, detail="Document AI key is not configured.")
    if not x_ai_key or not secrets.compare_digest(x_ai_key.strip(), settings.ai_key):
        raise HTTPException(status_code=401, detail="Invalid AI key.")


def validate_document_type(document_type: str) -> str:
    normalized = (document_type or "").strip().upper()
    if normalized not in {"NID", "PASSPORT"}:
        raise HTTPException(status_code=422, detail="document_type must be NID or PASSPORT.")
    return normalized


async def validate_upload(file: UploadFile, max_bytes: int) -> bytes:
    content_type = (file.content_type or "").lower().strip()
    if content_type not in {"image/jpeg", "image/jpg", "image/png"}:
        raise HTTPException(status_code=422, detail="Only JPG/JPEG/PNG images are allowed.")

    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Image file is required.")
    if len(data) > max_bytes:
        raise HTTPException(status_code=413, detail="Image file is too large.")
    if not (
        data.startswith(b"\xff\xd8\xff")
        or data.startswith(b"\x89PNG\r\n\x1a\n")
    ):
        raise HTTPException(status_code=422, detail="Only valid JPG/JPEG/PNG images are allowed.")
    return data
