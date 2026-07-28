# Z News Android UI parity contract

The Android client and `/znews/` web app use the same backend records, status transitions, pagination cursors and financial trust boundaries.

## Navigation

Add a `Z News` entry to the authenticated Z-Pay Swift home navigation. Z News contains four primary destinations:

1. Feed
2. Create
3. My posts
4. Creator balance

A public post link opens the post reader. Authentication is required for post creation, likes, comments, recorded shares, creator history and transfer requests.

## Screens

### Feed

- Facebook-style post cards.
- Creator name/photo, timestamp, text and optional image.
- Like, comment and share actions.
- Cursor-based loading.
- Provider-neutral ad placements after a controlled number of posts.

### Post reader

- Full post content and secure media URL.
- Public approved comments.
- Comment composer for authenticated users.
- View start, heartbeat and completion tied to screen visibility and lifecycle.
- Reader ad placement that does not cover content or invite accidental clicks.

### Create post

- Text, one JPEG/PNG/WebP image, or both.
- Image preview and file-size validation.
- Media upload first, then post creation with the returned `media_id`.
- Clear review-pending confirmation.

### My posts

- REVIEW/PENDING, ACTIVE/APPROVED and BLOCKED states.
- Edit/delete controls use current `updated_at` for optimistic concurrency.
- Editing an approved post returns it to review.

### Creator balance

- Separate Z News balances and ledger.
- Minimum BDT 500 transfer disclosure.
- Transfer button enabled only when the backend-reported available BDT equivalent meets the threshold.
- Transfer request status; no client-side wallet mutation.

## Shared API requirements

- Base URL: `https://zpayswift.com/api`
- App key comes from Android secure build configuration, not UI text.
- Session token uses the existing Z-Pay Swift session store.
- API envelope: `ok`, `success`, `code`, `message`, `data`.
- Idempotency key generated for every mutation.
- Public feed/post/comment/view routes do not assume a user session.
- Authenticated routes always use the logged-in user; no client-supplied UID.

## Android module shape

```text
feature/znews/
  data/
    ZNewsApi.kt
    ZNewsRepository.kt
    model/
  domain/
    ZNewsUseCases.kt
  ui/
    feed/
    post/
    create/
    mine/
    balance/
  ads/
    ZNewsAdProvider.kt
    InMobiZNewsAdProvider.kt
```

Use the Android app's existing networking, authentication, image loading, theme and navigation stack. Do not create a second login system or duplicate wallet code.

## Error mapping

Map stable backend codes to readable UI messages, including:

- `SESSION_EXPIRED`
- `ZNEWS_POST_NOT_FOUND`
- `ZNEWS_POST_NOT_PUBLIC`
- `ZNEWS_COMMENT_CREATED`
- `ZNEWS_TRANSFER_MINIMUM_NOT_MET`
- `ZNEWS_TRANSFER_INSUFFICIENT_BALANCE`
- `ZNEWS_TRANSFER_REQUESTED`
- network failure
- malformed response

## Android repository requirement

Implementation must be committed in the actual `zpayswift-android` repository. The backend/web repository must not contain a second Android project.
