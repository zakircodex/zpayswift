# Z Sky 24 Adsterra Web integration

## Trust boundaries

Ad display and monthly settlement are separate systems:

- The approved public banner tag renders inside a sandboxed, same-origin frame.
- The Adsterra Publisher API token stays in the private server configuration and is used only for monthly statistics.
- Browser events never submit revenue or credit a creator.
- Authenticated creators and the Android app never receive an ad-delivery permit.
- A guest receives a short-lived reader permit only after `views/start.php` returns `ad_eligible=true`.

The first production placement is intentionally limited to `post_reader`. Feed cards do not currently create a verified view session, so feed/sidebar ads remain disabled until they can be bound to the same server policy without changing view semantics.

## Publisher setup

1. Add and approve `https://zsky24.com` in the Adsterra publisher dashboard.
2. Create a Banner unit for the post reader. Prefer `300x250` for the current mobile reader width.
3. Open **Websites -> Z Sky 24 -> Get code** and copy the approved tag exactly.
4. From that tag, record only its public `key`, HTTPS `invoke.js` URL, and size.
5. Keep the Publisher API token separate. It must never appear in HTML, JavaScript, URLs, screenshots, or Git.

Adsterra's current publisher instructions require an approved website and the generated code from the publisher dashboard before ads can render: [Adsterra banner setup](https://adsterra.com/banner-ads/).

## Private production configuration

Add the following to `/home/zedpayhe/private/zpayswift/config.php`. Values shown here are placeholders:

```php
define('ZNEWS_AD_DELIVERY_SIGNING_KEY', 'generate-a-new-random-secret-of-at-least-32-characters');
define('ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS', 120);

define('ADSTERRA_ZSKY24_WEB_ADS_ENABLED', true);
define('ADSTERRA_ZSKY24_POST_READER_KEY', 'PUBLIC_KEY_FROM_APPROVED_BANNER_TAG');
define('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL', 'https://EXACT_APPROVED_HOST/PATH/invoke.js');
define('ADSTERRA_ZSKY24_POST_READER_SIZE', '300x250');
define('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS', 'EXACT_APPROVED_HOST');
```

The script hostname must exactly match the host in the approved Adsterra tag. The application rejects HTTP, credentials in URLs, fragments, nonstandard ports, unknown sizes, unapproved hosts, and non-`invoke.js` paths.

## Runtime flow

1. The post reader loads public content without waiting for ads.
2. `views/start.php` applies the canonical guest/creator/Android/spam policy.
3. An eligible guest receives a signed, short-lived `post_reader` frame URL.
4. The browser waits for authentication resolution and the Z Sky request scheduler before mounting the frame.
5. The frame validates the permit and private placement configuration, then runs the approved banner tag in a sandbox without same-origin access.
6. Invalid, expired, tampered, creator, Android, and spam-blocked requests fail closed with no blank ad gap.

## Production gates

- Private placement config validates without printing its values.
- Guest `views/start.php` returns `ad_delivery.enabled=true` only when `ad_policy.ad_eligible=true`.
- Creator, Android and blocked guest responses return `enabled=false`.
- The frame URL is same-origin and its permit expires in at most five minutes.
- The frame CSP, sandbox, referrer policy and popup restrictions remain present.
- The reader remains usable when the ad host is slow or blocked.
- Feed latency, progressive rendering and the priority scheduler remain unchanged.
- Adsterra monthly statistics reconcile the configured domain/placement before revenue locking.

Do not enable production ads until the real approved tag is available and cold mobile verification passes.
