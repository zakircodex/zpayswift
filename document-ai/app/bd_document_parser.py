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

NON_CRITICAL_WARNINGS = {
    "DOCUMENT_BOUNDARY_NOT_FOUND",
    "LOW_RESOLUTION_IMAGE",
}

NID_LABELS = (
    "ID NO",
    "IDNO",
    "ID NUMBER",
    "NID",
    "NID NO",
    "NIDNO",
    "IDENTITY NO",
    "NATIONAL ID NO",
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
        has_field_hint = re.search(
            r"\b(NAME|DATE\s+OF\s+BIRTH|DOB|ID\s*NO|IDNO|NID\s*NO|NIDNO|NATIONAL\s+ID\s+NO|PASSPORT\s+(NO|NUMBER))\b",
            upper,
        )
        if any(part in upper for part in IGNORED_HEADER_PARTS) and not has_field_hint:
            continue
        cleaned.append(line)
    return cleaned


def _label_key(value: str) -> str:
    key = _clean_line(value).upper()
    key = key.replace("1D", "ID").replace("I D", "ID")
    key = key.replace("N0", "NO").replace("N O", "NO")
    key = re.sub(r"[^A-Z0-9]+", " ", key)
    return _clean_line(key)


def _raw_label_pattern(label: str) -> str:
    token_patterns: list[str] = []
    for token in _label_key(label).split():
        chars: list[str] = []
        for char in token:
            if char == "I":
                chars.append("[I1]")
            elif char == "O":
                chars.append("[O0]")
            else:
                chars.append(re.escape(char))
        token_patterns.append(r"\s*".join(chars))
    return r"\b" + r"\s*".join(token_patterns) + r"\b"


def _has_label(line: str, labels: tuple[str, ...]) -> bool:
    return any(re.search(_raw_label_pattern(label), line, flags=re.IGNORECASE) for label in labels)


def _value_after_label(line: str, labels: tuple[str, ...]) -> str:
    for label in labels:
        match = re.search(rf"{_raw_label_pattern(label)}\s*[:;,\-#]?\s*", line, flags=re.IGNORECASE)
        if match:
            return _clean_line(line[match.end() :])
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
    labels = ("NAME",)
    for index, line in enumerate(lines):
        if not _has_label(line, labels):
            continue

        candidates: list[tuple[str, str]] = []
        same_line = _value_after_label(line, labels)
        if same_line:
            candidates.append((same_line, line))
        for offset in (1, 2):
            if index + offset < len(lines):
                next_line = lines[index + offset]
                if not _has_label(next_line, ("DATE OF BIRTH", "DOB", *NID_LABELS)):
                    candidates.append((next_line, next_line))

        for candidate, source_line in candidates:
            candidate = re.sub(r"^[^A-Za-z]+", "", candidate)
            if _valid_name(candidate):
                return _clean_line(candidate).upper(), max(0.78, _line_confidence(source_line, items)), []

    return "", 0.0, ["NAME_NOT_FOUND"]


def _normalize_date(value: str) -> str:
    value = _clean_line(value)
    value = value.replace(",", " ")
    value = re.sub(r"\s+", " ", value)
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
    labels = ("DATE OF BIRTH", "DOB", "BIRTH DATE")
    date_pattern = re.compile(
        r"\b(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}|\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})\b"
    )
    for index, line in enumerate(lines):
        if not _has_label(line, labels):
            continue

        candidates: list[tuple[str, str]] = []
        same_line = _value_after_label(line, labels)
        if same_line:
            candidates.append((same_line, line))
        if index + 1 < len(lines):
            candidates.append((lines[index + 1], lines[index + 1]))

        for candidate, source_line in candidates:
            match = date_pattern.search(candidate)
            if not match:
                continue
            normalized = _normalize_date(match.group(1))
            if normalized:
                return normalized, max(0.78, _line_confidence(source_line, items)), []

    return "", 0.0, ["DATE_OF_BIRTH_NOT_FOUND"]


def _digit_candidates(value: str) -> list[str]:
    candidates: list[str] = []
    for match in re.finditer(r"(?:\d[\s.\-]*){10,17}", value):
        digits = re.sub(r"\D+", "", match.group(0))
        if len(digits) in {10, 13, 17}:
            candidates.append(digits)
    return candidates


def parse_nid_number(lines: list[str], items: list[dict[str, Any]]) -> tuple[str, float, list[str]]:
    for index, line in enumerate(lines):
        if not _has_label(line, NID_LABELS):
            continue

        candidates: list[tuple[str, str]] = []
        same_line = _value_after_label(line, NID_LABELS)
        if same_line:
            candidates.append((same_line, line))
        else:
            candidates.append((line, line))
        for offset in (1, 2):
            if index + offset < len(lines):
                candidates.append((lines[index + offset], lines[index + offset]))

        for candidate, source_line in candidates:
            for digits in _digit_candidates(candidate):
                return digits, max(0.78, _line_confidence(source_line, items)), []

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
        candidate = _value_after_label(line, labels)
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


def _field_overall_confidence(field_scores: list[float], engine_conf: float) -> float:
    present_scores = [score for score in field_scores if score > 0.0]
    if not present_scores:
        return 0.0
    # Strong labelled fields should dominate harmless OCR/crop noise.
    return max(mean(present_scores), mean(present_scores + [engine_conf]))


def parse_document(
    document_type: str,
    ocr_items: list[dict[str, Any]],
    crop_used: bool,
    quality_warnings: list[str] | None = None,
) -> dict[str, Any]:
    normalized_type = (document_type or "").upper()
    lines = clean_lines(ocr_items)
    warnings: list[str] = list(quality_warnings or [])

    name, name_conf, name_warnings = parse_name(lines, ocr_items)
    dob, dob_conf, dob_warnings = parse_date_of_birth(lines, ocr_items)
    warnings.extend(name_warnings)
    warnings.extend(dob_warnings)

    if normalized_type == "PASSPORT":
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
    overall = round(max(0.0, min(1.0, _field_overall_confidence(field_scores, engine_conf))), 2)

    if not ocr_items:
        warnings.append("OCR_TEXT_NOT_FOUND")
    if overall < 0.6:
        warnings.append("LOW_CONFIDENCE")

    warnings = sorted(set(warnings))
    required_missing = not name or not dob or not number
    critical_warnings = [warning for warning in warnings if warning not in NON_CRITICAL_WARNINGS]
    needs_manual = required_missing or "LOW_CONFIDENCE" in critical_warnings or "LOW_QUALITY_IMAGE" in critical_warnings
    return {
        "document_type": normalized_type,
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
