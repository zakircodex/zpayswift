from __future__ import annotations

import re
from datetime import datetime
from statistics import mean
from typing import Any

IGNORED_HEADER_PARTS = (
    "GOVERNMENT",
    "PEOPLE",
    "REPUBLIC",
    "BANGLADESH",
    "NATIONAL ID CARD",
    "NATIONAL ID",
    "IDENTITY CARD",
    "গণপ্রজাতন্ত্রী",
    "বাংলাদেশ সরকার",
)


def _clean_line(value: str) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def _line_confidence(line: str, items: list[dict[str, Any]]) -> float:
    needle = _clean_line(line).upper()
    for item in items:
        text = _clean_line(str(item.get("text", ""))).upper()
        if text == needle:
            return max(0.0, min(1.0, float(item.get("confidence", 0.0) or 0.0)))
    return 0.0


def clean_lines(items: list[dict[str, Any]]) -> list[str]:
    cleaned: list[str] = []
    for item in items:
        line = _clean_line(str(item.get("text", "")))
        if not line:
            continue
        upper = line.upper()
        if any(part in upper for part in IGNORED_HEADER_PARTS):
            continue
        cleaned.append(line)
    return cleaned


def _after_label(line: str, labels: tuple[str, ...]) -> str:
    for label in labels:
        pattern = re.compile(rf"^.*\b{re.escape(label)}\b\s*[:\-#]?\s*", re.IGNORECASE)
        value = pattern.sub("", line).strip()
        if value != line:
            return value
    return ""


def _valid_name(value: str) -> bool:
    value = _clean_line(value)
    upper = value.upper()
    if len(value) < 5 or len(value) > 56:
        return False
    if re.search(r"\d", value):
        return False
    blocked = (
        "DATE",
        "BIRTH",
        "SEX",
        "COUNTRY",
        "FATHER",
        "MOTHER",
        "PASSPORT",
        "NATIONAL",
        "IDENTITY",
        "CARD",
        "ID NO",
        "NID",
        "SIGNATURE",
    )
    if any(word in upper for word in blocked):
        return False
    return re.fullmatch(r"[A-Z .'\-]+", upper) is not None


def parse_name(lines: list[str], items: list[dict[str, Any]]) -> tuple[str, float, list[str]]:
    for index, line in enumerate(lines):
        if not re.search(r"\bNAME\b", line, flags=re.IGNORECASE):
            continue

        candidate = _after_label(line, ("NAME",))
        source_line = line
        if not candidate and index + 1 < len(lines):
            candidate = lines[index + 1]
            source_line = lines[index + 1]
        if _valid_name(candidate):
            return _clean_line(candidate).upper(), max(0.75, _line_confidence(source_line, items)), []

    return "", 0.0, ["NAME_NOT_FOUND"]


def _normalize_date(value: str) -> str:
    value = _clean_line(value)
    formats = (
        "%d/%m/%Y",
        "%d-%m-%Y",
        "%Y/%m/%d",
        "%Y-%m-%d",
        "%d.%m.%Y",
        "%d %b %Y",
        "%d %B %Y",
    )
    for fmt in formats:
        try:
            return datetime.strptime(value, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return ""


def parse_date_of_birth(lines: list[str], items: list[dict[str, Any]]) -> tuple[str, float, list[str]]:
    date_pattern = re.compile(
        r"\b(\d{2}[\/\-.]\d{2}[\/\-.]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})\b"
    )
    for index, line in enumerate(lines):
        upper = line.upper()
        if "DATE OF BIRTH" not in upper and "DOB" not in upper:
            continue
        candidate = _after_label(line, ("DATE OF BIRTH", "DOB"))
        source_line = line
        if not candidate and index + 1 < len(lines):
            candidate = lines[index + 1]
            source_line = lines[index + 1]
        match = date_pattern.search(candidate)
        if match:
            normalized = _normalize_date(match.group(1))
            if normalized:
                return normalized, max(0.75, _line_confidence(source_line, items)), []

    return "", 0.0, ["DATE_OF_BIRTH_NOT_FOUND"]


def parse_nid_number(lines: list[str], items: list[dict[str, Any]]) -> tuple[str, float, list[str]]:
    labels = ("ID NO", "NID", "NID NO", "IDENTITY NO", "NATIONAL ID NO")
    for index, line in enumerate(lines):
        upper = line.upper()
        if not any(label in upper for label in labels):
            continue
        candidate = _after_label(line, labels)
        source_line = line
        if not candidate and index + 1 < len(lines):
            candidate = lines[index + 1]
            source_line = lines[index + 1]
        digits = re.sub(r"\D+", "", candidate)
        if len(digits) in {10, 13, 17}:
            return digits, max(0.72, _line_confidence(source_line, items)), []

    return "", 0.0, ["DOCUMENT_NUMBER_NOT_FOUND"]


def _parse_mrz(lines: list[str]) -> dict[str, str]:
    mrz_lines = [line.upper().replace(" ", "") for line in lines if "<" in line and len(line) >= 25]
    if len(mrz_lines) < 2:
        return {}

    first = mrz_lines[-2]
    second = mrz_lines[-1]
    data: dict[str, str] = {}

    if len(second) >= 9:
        data["document_number"] = second[:9].replace("<", "")
    if len(first) > 5 and "<<" in first:
        name_part = first.split("<<", 1)[1]
        data["name"] = _clean_line(name_part.replace("<", " ")).upper()
    if len(second) >= 20:
        dob_raw = second[13:19]
        if re.fullmatch(r"\d{6}", dob_raw):
            year = int(dob_raw[:2])
            century = 1900 if year > 30 else 2000
            try:
                data["date_of_birth"] = datetime(century + year, int(dob_raw[2:4]), int(dob_raw[4:6])).strftime("%Y-%m-%d")
            except ValueError:
                pass
    return data


def parse_passport_number(lines: list[str], items: list[dict[str, Any]]) -> tuple[str, float, list[str]]:
    labels = ("PASSPORT NO", "PASSPORT NUMBER", "PASSPORT")
    for index, line in enumerate(lines):
        upper = line.upper()
        if not ("PASSPORT NO" in upper or "PASSPORT NUMBER" in upper):
            continue
        candidate = _after_label(line, labels)
        source_line = line
        if not candidate and index + 1 < len(lines):
            candidate = lines[index + 1]
            source_line = lines[index + 1]
        match = re.search(r"\b[A-Z]{1,2}[0-9]{6,9}\b|\b[A-Z0-9]{7,12}\b", candidate.upper())
        if match:
            return match.group(0), max(0.72, _line_confidence(source_line, items)), []

    mrz = _parse_mrz(lines)
    number = re.sub(r"[^A-Z0-9]", "", mrz.get("document_number", ""))
    if number:
        return number, 0.72, ["PASSPORT_NUMBER_FROM_MRZ"]

    return "", 0.0, ["DOCUMENT_NUMBER_NOT_FOUND"]


def parse_document(
    document_type: str,
    ocr_items: list[dict[str, Any]],
    crop_used: bool,
    quality_warnings: list[str] | None = None,
) -> dict[str, Any]:
    lines = clean_lines(ocr_items)
    warnings: list[str] = list(quality_warnings or [])

    name, name_conf, name_warnings = parse_name(lines, ocr_items)
    dob, dob_conf, dob_warnings = parse_date_of_birth(lines, ocr_items)
    warnings.extend(name_warnings)
    warnings.extend(dob_warnings)

    if document_type == "PASSPORT":
        number, number_conf, number_warnings = parse_passport_number(lines, ocr_items)
        mrz = _parse_mrz(lines)
        if not name and _valid_name(mrz.get("name", "")):
            name = mrz["name"]
            name_conf = 0.68
            warnings.append("NAME_FROM_MRZ")
        if not dob and mrz.get("date_of_birth"):
            dob = mrz["date_of_birth"]
            dob_conf = 0.68
            warnings.append("DATE_OF_BIRTH_FROM_MRZ")
    else:
        number, number_conf, number_warnings = parse_nid_number(lines, ocr_items)
    warnings.extend(number_warnings)

    ocr_confidences = [float(item.get("confidence", 0.0)) for item in ocr_items if item.get("confidence") is not None]
    engine_conf = mean(ocr_confidences) if ocr_confidences else 0.0
    field_scores = [name_conf, dob_conf, number_conf]
    overall = round(max(0.0, min(1.0, mean(field_scores + [engine_conf]))), 2)

    if not ocr_items:
        warnings.append("OCR_TEXT_NOT_FOUND")
    if overall < 0.6:
        warnings.append("LOW_CONFIDENCE")

    warnings = sorted(set(warnings))
    needs_manual = bool(warnings) or not name or not dob or not number
    return {
        "document_type": document_type,
        "name": name,
        "date_of_birth": dob,
        "document_number": number,
        "overall_confidence": overall,
        "fields_confidence": {
            "name": round(name_conf, 2),
            "date_of_birth": round(dob_conf, 2),
            "document_number": round(number_conf, 2),
        },
        "needs_manual_review": needs_manual,
        "warnings": warnings,
        "crop_used": crop_used,
    }
