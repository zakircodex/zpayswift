import os
import sys
from typing import Callable

APP_ROOT = os.path.dirname(__file__)
if APP_ROOT not in sys.path:
    sys.path.insert(0, APP_ROOT)

_asgi_wsgi_app = None


def _json_response(start_response: Callable, status: str, body: bytes):
    start_response(
        status,
        [
            ("Content-Type", "application/json; charset=utf-8"),
            ("Content-Length", str(len(body))),
            ("Cache-Control", "no-store"),
        ],
    )
    return [body]


def _is_health_request(environ: dict) -> bool:
    path_info = str(environ.get("PATH_INFO", "") or "")
    request_uri = str(environ.get("REQUEST_URI", "") or "")
    clean_uri = request_uri.split("?", 1)[0].rstrip("/")
    clean_path = path_info.split("?", 1)[0].rstrip("/")
    return (
        clean_path in {"", "/", "/health"}
        or clean_uri.endswith("/api/document-ai")
        or clean_uri.endswith("/api/document-ai/health")
    )


def _load_asgi_wsgi_app():
    global _asgi_wsgi_app
    if _asgi_wsgi_app is None:
        from a2wsgi import ASGIMiddleware
        from app.main import app as fastapi_app

        _asgi_wsgi_app = ASGIMiddleware(fastapi_app)
    return _asgi_wsgi_app


def application(environ, start_response):
    # Keep cPanel Passenger health checks ultra-light. This path intentionally
    # avoids importing FastAPI, PaddleOCR, OpenCV, or any OCR pipeline modules.
    if _is_health_request(environ):
        return _json_response(
            start_response,
            "200 OK",
            b'{"success":true,"code":"OK","message":"Document AI service healthy.","data":{"service":"document-ai","entrypoint":"passenger_wsgi"}}',
        )

    try:
        return _load_asgi_wsgi_app()(environ, start_response)
    except Exception:
        return _json_response(
            start_response,
            "503 Service Unavailable",
            b'{"success":false,"code":"OCR_ENGINE_UNAVAILABLE","message":"Document OCR engine is temporarily unavailable.","data":{}}',
        )
