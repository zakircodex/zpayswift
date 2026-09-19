# Digital Birthday Universe

Digital Birthday Universe is a self-service Z Sky 24 feature. An anonymous
visitor can create, preview and publish a birthday page; the result receives a
non-enumerable public slug, fictional personal star ID, recovery code, public
URL and downloadable QR code. Creating a page never requires manual admin work.

## Public routes

- `/birthday`
- `/birthday/create`
- `/birthday/preview/{draftId}`
- `/birthday/templates`
- `/birthday/manage/{slug}`
- `/u/{slug}`

The feature uses the existing PHP/Firebase runtime, protected Z-Pay account
handoff, private upload storage and signed Adsterra frame delivery. Advertising
is non-blocking. No click or impression is fabricated and generation continues
when advertising is unavailable.

## Private configuration

Copy the Birthday Universe constants from `api/config.example.php` into the
private deployment configuration. The defaults are:

```php
define('BIRTHDAY_UNIVERSE_RETENTION_DAYS', 90);
define('BIRTHDAY_UNIVERSE_RENEWAL_DAYS', 90);
define('BIRTHDAY_UNIVERSE_GENERATION_PER_HOUR', 5);
define('BIRTHDAY_UNIVERSE_DRAFT_TTL_SECONDS', 86400);
define('BIRTHDAY_UNIVERSE_PHOTO_MAX_BYTES', 5 * 1024 * 1024);
define('BIRTHDAY_UNIVERSE_AUDIO_MAX_BYTES', 10 * 1024 * 1024);
define('BIRTHDAY_UNIVERSE_AUDIO_MAX_DURATION_SECONDS', 30);
define('BIRTHDAY_UNIVERSE_STORAGE_DIR', '');
```

Keep `ZNEWS_HANDOFF_ENCRYPTION_KEY` and `ZNEWS_AD_DELIVERY_SIGNING_KEY` private.
No secrets belong in browser JavaScript or this directory.

## Storage

The default private path is `private/uploads/znews/birthday`. Uploaded photos
are signature-checked, decoded and re-encoded into an optimized derivative.
The complete image composition and aspect ratio are preserved; no crop is
performed. Media is served only through the protected endpoint. New media uses
an atomic private filesystem write with size/hash read-back verification, while the old
`ZNEWS_BIRTHDAY_MEDIA_BLOBS` namespace remains readable only for legacy pages.
This avoids Firebase payload-size failures on normal phone photos. Direct client
access remains denied, and cleanup removes both current files and any legacy
fallback record. When the host provides GD, images are resized and re-encoded;
when GD is unavailable, a fully validated file up to 5 MB is retained without
cropping rather than rejecting an otherwise valid phone photo.

Creators may use the built-in procedural cosmic ambience, silence, a licensed
platform track, or one MP3/OGG/M4A file up to 30 seconds. Custom audio is checked
by MIME type, file signature and parsed media duration on the server, requires a
rights confirmation, and is delivered from private storage. Browser autoplay
rules are respected: sound starts only after the recipient presses **Enter
Birthday Universe**.

## Immersive renderer

Public and preview pages use a self-hosted Three.js `0.186.0` renderer with an
adaptive GPU star field, opening celebration, comet, satellite and textured
rotating decorative moon. Device memory and CPU hints reduce particle density
and pixel ratio on lower-end phones. `prefers-reduced-motion` disables continuous
motion without hiding any content. The renderer has a static CSS/image fallback
when WebGL is unavailable.

The generated moon texture is documented in `assets/images/README.md`. Three.js
is vendored in `assets/lib` with its MIT license so cPanel deployment does not
depend on a CDN or third-party runtime request.

For custom audio uploads, configure PHP `upload_max_filesize` to at least `10M`
and `post_max_size` to at least `12M`. The application still enforces its own
stricter MIME, signature, byte-size and 30-second duration checks.

The bundled QR renderer is stored under `assets/lib` with its MIT license so it
is included in a fresh checkout and cPanel package. QR images are rendered
locally as PNG files and contain only the public Universe URL.

## Local development

Use the existing project PHP/Apache environment and open `/birthday` on the Z
Sky 24 host. The Birthday APIs use the same private Firebase configuration as
the rest of Z Sky 24; no browser-facing secret is required. For an isolated UI
and full-flow check, run:

```powershell
$env:NODE_PATH='C:\path\to\node_modules'
node tests/znews_birthday_universe_browser_test.js
php tests/znews_birthday_universe_test.php
```

The browser test uses a local deterministic API fixture and exercises create,
preview, generation, public rendering, sharing, message reveal and PNG QR
generation at mobile and desktop sizes.

## Admin setup

Open **Admin Dashboard → Birthday Universe** to:

- inspect usage and storage metrics;
- block, restore or delete abusive Universes;
- activate deployed templates and adjust their public metadata/colors;
- upload or deactivate licensed music;
- resolve reports;
- configure retention, renewal, generation limits and indexing.

Templates are code-owned in `birthday_template_registry()` and rendered through
the standardized data contract in `birthday-templates.js`. To add one, register
its ID and metadata server-side, add its renderer class, then add scoped CSS.
Admin settings can activate it but cannot inject executable markup.

Current Adsterra placements are reused through the isolated `AdService`
contract. The configured ads are non-blocking and not rewarded. Capability
checks are recorded as offers; delivery is recorded only after the public ad
frame is mounted. Never add click incentives or fabricate reward completion.

## Database and cleanup

Deploy the indexes in `database.rules.json`. Direct Firebase client reads and
writes remain denied; all access is through authenticated server endpoints.
Configure the cleanup cron described in `docs/birthday-universe-cleanup.md`.

## Production verification

1. Run the PHP, JavaScript and repository tests.
2. Create a Universe with and without a photo and music.
3. Verify anonymous preview, generation, `/u/{slug}`, sharing and QR download.
4. Sign in through Z-Pay, claim with the recovery code, edit, renew and delete.
5. Confirm unlisted pages emit `noindex` and public pages emit canonical/OG data.
6. Confirm ad failure never blocks generation or public rendering.
7. Run cleanup in `--dry-run` mode before enabling the cron.
