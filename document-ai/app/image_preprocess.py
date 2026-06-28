from __future__ import annotations

import os
import uuid
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageEnhance, ImageOps


def write_temp_image(data: bytes, temp_dir: str, suffix: str = ".jpg") -> str:
    Path(temp_dir).mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(temp_dir, 0o700)
    except OSError:
        pass
    path = os.path.join(temp_dir, f"doc_{uuid.uuid4().hex}{suffix}")
    with open(path, "wb") as handle:
        handle.write(data)
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass
    return path


def fix_exif_orientation(input_path: str, output_path: str) -> str:
    with Image.open(input_path) as image:
        fixed = ImageOps.exif_transpose(image).convert("RGB")
        fixed.save(output_path, quality=96, optimize=True)
    return output_path


def _quality_warnings(image: np.ndarray) -> list[str]:
    warnings: list[str] = []
    height, width = image.shape[:2]
    if width < 900 or height < 550:
        warnings.append("LOW_RESOLUTION_IMAGE")

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blur_score = cv2.Laplacian(gray, cv2.CV_64F).var()
    if blur_score < 80:
        warnings.append("LOW_QUALITY_IMAGE")
    return warnings


def _order_points(points: np.ndarray) -> np.ndarray:
    rect = np.zeros((4, 2), dtype="float32")
    sums = points.sum(axis=1)
    diffs = np.diff(points, axis=1)
    rect[0] = points[np.argmin(sums)]
    rect[2] = points[np.argmax(sums)]
    rect[1] = points[np.argmin(diffs)]
    rect[3] = points[np.argmax(diffs)]
    return rect


def _find_document_contour(image: np.ndarray) -> np.ndarray | None:
    ratio = image.shape[0] / 700.0
    resized = cv2.resize(image, (int(image.shape[1] / ratio), 700))
    gray = cv2.cvtColor(resized, cv2.COLOR_BGR2GRAY)
    gray = cv2.GaussianBlur(gray, (5, 5), 0)
    edged = cv2.Canny(gray, 50, 160)
    contours, _ = cv2.findContours(edged.copy(), cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    contours = sorted(contours, key=cv2.contourArea, reverse=True)[:8]

    for contour in contours:
        perimeter = cv2.arcLength(contour, True)
        approx = cv2.approxPolyDP(contour, 0.02 * perimeter, True)
        area = cv2.contourArea(approx)
        if len(approx) == 4 and area > resized.shape[0] * resized.shape[1] * 0.15:
            return approx.reshape(4, 2) * ratio
    return None


def _four_point_transform(image: np.ndarray, points: np.ndarray) -> np.ndarray:
    rect = _order_points(points)
    (tl, tr, br, bl) = rect
    width_a = np.linalg.norm(br - bl)
    width_b = np.linalg.norm(tr - tl)
    max_width = int(max(width_a, width_b))
    height_a = np.linalg.norm(tr - br)
    height_b = np.linalg.norm(tl - bl)
    max_height = int(max(height_a, height_b))
    destination = np.array(
        [[0, 0], [max_width - 1, 0], [max_width - 1, max_height - 1], [0, max_height - 1]],
        dtype="float32",
    )
    matrix = cv2.getPerspectiveTransform(rect, destination)
    return cv2.warpPerspective(image, matrix, (max_width, max_height))


def _light_enhance(image_path: str) -> None:
    with Image.open(image_path) as image:
        image = image.convert("RGB")
        image = ImageEnhance.Contrast(image).enhance(1.12)
        image = ImageEnhance.Sharpness(image).enhance(1.08)
        image.save(image_path, quality=96, optimize=True)


def preprocess_document(input_path: str, temp_dir: str) -> tuple[str, bool, list[str]]:
    oriented_path = os.path.join(temp_dir, f"oriented_{uuid.uuid4().hex}.jpg")
    fix_exif_orientation(input_path, oriented_path)
    image = cv2.imread(oriented_path)
    if image is None:
        raise ValueError("Unable to decode image.")

    warnings = _quality_warnings(image)
    contour = _find_document_contour(image)
    if contour is None:
        _light_enhance(oriented_path)
        return oriented_path, False, warnings + ["DOCUMENT_BOUNDARY_NOT_FOUND"]

    warped = _four_point_transform(image, contour)
    crop_path = os.path.join(temp_dir, f"crop_{uuid.uuid4().hex}.jpg")
    cv2.imwrite(crop_path, warped, [int(cv2.IMWRITE_JPEG_QUALITY), 96])
    _light_enhance(crop_path)
    return crop_path, True, warnings


def cleanup_files(paths: list[str], keep: bool = False) -> None:
    if keep:
        return
    for path in paths:
        try:
            if path and os.path.isfile(path):
                os.remove(path)
        except OSError:
            pass
