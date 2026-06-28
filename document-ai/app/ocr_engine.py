from __future__ import annotations

from functools import lru_cache
from typing import Any

from .security import get_settings


@lru_cache(maxsize=1)
def get_ocr() -> Any:
    from paddleocr import PaddleOCR

    settings = get_settings()
    return PaddleOCR(use_angle_cls=True, lang="en", show_log=False, use_gpu=settings.paddle_use_gpu)


def run_ocr(image_path: str) -> list[dict[str, Any]]:
    result = get_ocr().ocr(image_path, cls=True)
    items: list[dict[str, Any]] = []
    if not result:
        return items

    for page in result:
        if not page:
            continue
        for entry in page:
            try:
                box = entry[0]
                text = str(entry[1][0]).strip()
                confidence = float(entry[1][1])
            except (IndexError, TypeError, ValueError):
                continue
            if text:
                items.append({"text": text, "confidence": confidence, "box": box})
    return items
