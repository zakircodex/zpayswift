# Z Sky 24 controlled ad-impression test

This CLI-only utility submits one signed BDT 0.01-0.03 test impression against an existing valid post view. It never settles or transfers creator credit.

## Preconditions

1. Use a dedicated creator account and test post.
2. Complete a real public reader session and retain its `view_id`.
3. Configure a random test secret of at least 32 characters in `/home/zedpayhe/private/zpayswift/config.php` (the existing legacy fallback is `/home/zedpayhe/private/zawtopup/config.php`):

```php
define('ZNEWS_AD_NETWORK_SECRETS', [
    'INMOBI_TEST' => 'replace-with-a-random-secret-at-least-32-characters',
]);
```

4. Confirm the cPanel clock is accurate and PHP cURL is enabled.

To obtain the valid view after reading and closing the test post, open Firebase Realtime Database and inspect `ZNEWS_VIEW_SESSIONS`. Use the latest record whose `post_id` matches the test post and whose `result` is `VALID`; copy its `view_id` value (or its `ZNV...` node key). Do not copy `view_token`, hashes or visitor data.

## Run from cPanel Terminal

```bash
cd /home/zedpayhe/repositories/zpayswift
php tools/zsky24_controlled_ad_test.php \
  --base-url=https://zpayswift.com \
  --network=INMOBI_TEST \
  --post-id=REAL_TEST_POST_ID \
  --view-id=REAL_VALID_VIEW_ID \
  --revenue-micros=20000 \
  --confirm-live-test
```

Enter the test secret at the hidden prompt. Never put it in screenshots, chat, GitHub, URLs or browser JavaScript.

Expected: HTTP 201 and `ZNEWS_AD_IMPRESSION_INGESTED`. `VERIFIED` appears under Creator credits; `PENDING_VIEW` or `REVIEW` appears under Ad verification. Creator balance and the Z-Pay wallet must remain unchanged until a separate admin settlement action.
