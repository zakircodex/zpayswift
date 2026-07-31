# Z News InMobi integration contract

## Decision

Z News uses a provider-neutral ad interface with **InMobi** as the first provider for Android and mobile web. The browser and Android clients only render placements and report non-financial UI events. They never receive webhook secrets, decide reported value, or credit a creator balance.

## Required publisher setup

1. Create the publisher account and complete payment/tax details.
2. Add the Z-Pay Swift Android app. Use the unpublished/private-app onboarding path until a public store listing exists.
3. Add `zpayswift.com` as a mobile website and complete domain review.
4. Ask InMobi to enable the WebX/mobile-web integration and create web placement tags.
5. Create separate Android placement IDs for feed and post-reader placements.
6. Obtain server reporting/callback credentials. Store them only in the private server configuration.

## Placement map

| Z News placement | Web | Android | Initial format |
| --- | --- | --- | --- |
| `feed_sidebar` | desktop/mobile web | not used | mobile banner |
| `post_inline` | feed between posts | feed between posts | native/banner |
| `post_reader` | full post reader | full post reader | native/banner |

Production placement IDs must not be committed. Web public placement IDs may be injected at deploy time. Private API/reporting credentials must remain in `/home/zedpayhe/private/zpayswift/`.

## Web adapter

`znews/assets/znews-ads.js` exposes:

```js
ZNewsAds.registerProviderRenderer('INMOBI', async ({
  element,
  slotName,
  placementId,
  format
}) => {
  // Mount the publisher-specific WebX tag supplied by InMobi.
});
```

Until publisher approval and tag delivery, `znews-config.js` remains in `TEST` mode. Production mode hides an unconfigured slot rather than displaying a fake ad.

## Android adapter

The Android implementation must expose a small interface independent of the SDK:

```kotlin
interface ZNewsAdProvider {
    fun loadFeedPlacement(host: ViewGroup, placement: ZNewsAdPlacement)
    fun loadReaderPlacement(host: ViewGroup, placement: ZNewsAdPlacement)
    fun destroy(host: ViewGroup)
}
```

`InMobiZNewsAdProvider` owns SDK initialization, consent state, lifecycle cleanup, test-device configuration and impression callbacks. UI screens depend only on `ZNewsAdProvider`.

## Financial trust boundary

- Client impression callbacks are not settlement evidence.
- Actual value comes from the provider's authenticated server report/callback.
- The backend binds a provider event to an eligible completed Z News view.
- Duplicate event, nonce, slot and view rules run before verification.
- A verified impression remains `NOT_SETTLED` until an authorised settlement action.
- Settlement uses integer micros. The creator base share is 50% of authenticated provider-reported revenue; BDT creator payout is rounded down to whole paisa and capped at the configured maximum of BDT 0.01–0.03 per verified ad. The platform receives the remainder.
- Main Z-Pay wallet remains isolated until a valid Z News transfer request is approved.

## Launch gates

- Publisher app and website approved.
- Test ads render on both platforms without accidental clicks.
- Consent/privacy text reviewed for Malaysia and Bangladesh audiences.
- Provider report values reconcile against Z News impression records.
- Invalid traffic and duplicate tests pass.
- Successful minimum-balance transfer test passes before enabling transfers for all users.
