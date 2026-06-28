# Z-Pay Swift Document AI

Private local OCR service for reading BD NID/passport document photos. This is not government verification. It only reads, parses, and quality-checks the uploaded photo. The main PHP backend must still enforce duplicate rules such as one NID/passport per account.

## Run on Windows

```powershell
cd C:\Projects\zpayswift\document-ai
py -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
uvicorn app.main:app --host 127.0.0.1 --port 8010
```

PaddleOCR may require a matching `paddlepaddle` CPU wheel for your Python version. Install it from the official PaddlePaddle instructions if `paddleocr` cannot load the OCR runtime.

## Environment

```env
DOCUMENT_AI_KEY=change-this-private-key
DEBUG_KEEP_FILES=false
MAX_IMAGE_SIZE_MB=8
TEMP_DIR=
PADDLE_USE_GPU=false
```

Keep `DOCUMENT_AI_KEY` private. Do not put the real key in Git.

## API

### Health

```powershell
curl http://127.0.0.1:8010/health
```

### Verify Document

```powershell
curl -X POST http://127.0.0.1:8010/v1/document/verify `
  -H "X-AI-KEY: change-this-private-key" `
  -F "document_type=NID" `
  -F "image=@C:\path\to\nid.jpg"
```

Request:

- Header: `X-AI-KEY`
- Multipart file: `image`
- Form field: `document_type=NID` or `document_type=PASSPORT`

Response codes:

- `DOCUMENT_PARSED`: data was read with acceptable confidence.
- `DOCUMENT_LOW_CONFIDENCE`: OCR was weak or fields are missing; user should review/correct manually.

## PHP Bridge

Android should call the PHP backend, not this private service directly:

```text
POST /api/auth/document_ai_verify.php
```

Required:

- Header: `X-APP-KEY`
- Multipart file: `image`
- Form field: `document_type=NID` or `PASSPORT`

Configure the PHP backend private config or environment:

```php
define('DOCUMENT_AI_URL', 'http://127.0.0.1:8010/v1/document/verify');
define('DOCUMENT_AI_KEY', 'change-this-private-key');
```

## Limitations

- OCR is a helper only, not official identity verification.
- Low quality, glare, blur, cropped-off edges, or non-English text can reduce accuracy.
- NID/passport duplicate checks must stay in the PHP backend/database.
- The service never returns full raw OCR text and should not log document numbers or image paths.
