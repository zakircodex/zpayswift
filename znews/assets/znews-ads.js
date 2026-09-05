(() => {
  'use strict';

  const SUPPORTED_SIZES = new Set(['160x300', '160x600', '300x250', '320x50', '468x60', '728x90']);

  function clearSlot(slot) {
    if (!(slot instanceof HTMLElement)) return;
    slot.replaceChildren();
    slot.classList.remove('ad-slot-live', 'ad-slot-loading');
    delete slot.dataset.adDeliveryUrl;
  }

  function hide(slot) {
    clearSlot(slot);
    slot.hidden = true;
    slot.setAttribute('aria-hidden', 'true');
  }

  function authenticatedCreator() {
    return window.ZNEWS_AUTH_VERIFIED === true;
  }

  function androidApp() {
    return /^zpayswift-android-znews\//i.test(String(navigator.userAgent || '').trim());
  }

  function safeDelivery(delivery, expectedSlot) {
    if (!delivery || delivery.enabled !== true || String(delivery.provider || '').toUpperCase() !== 'ADSTERRA') {
      return null;
    }
    const slot = String(delivery.slot || '').trim();
    const width = Number(delivery.width || 0);
    const height = Number(delivery.height || 0);
    const size = `${width}x${height}`;
    if (slot !== expectedSlot || slot !== 'post_reader' || !SUPPORTED_SIZES.has(size)) return null;

    try {
      const frameUrl = new URL(String(delivery.frame_url || ''), window.location.origin);
      if (frameUrl.origin !== window.location.origin
        || frameUrl.pathname !== '/api/znews/public/ad_frame.php'
        || !frameUrl.searchParams.get('permit')) {
        return null;
      }
      return { frameUrl: frameUrl.toString(), slot, width, height };
    } catch (_error) {
      return null;
    }
  }

  function mount(slot, delivery) {
    if (!(slot instanceof HTMLElement)) return false;
    const expectedSlot = String(slot.dataset.znewsAdSlot || '').trim();
    const safe = safeDelivery(delivery, expectedSlot);
    if (!safe || authenticatedCreator() || androidApp()) {
      hide(slot);
      return false;
    }
    if (slot.dataset.adDeliveryUrl === safe.frameUrl && slot.querySelector('iframe')) return true;

    clearSlot(slot);
    slot.hidden = false;
    slot.removeAttribute('aria-hidden');
    slot.classList.add('ad-slot-live', 'ad-slot-loading');
    slot.dataset.adDeliveryUrl = safe.frameUrl;

    const label = document.createElement('span');
    label.className = 'ad-label';
    label.textContent = 'Sponsored';

    const frame = document.createElement('iframe');
    frame.className = 'ad-slot-frame';
    frame.title = 'Advertisement';
    frame.width = String(safe.width);
    frame.height = String(safe.height);
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.setAttribute('credentialless', '');
    frame.setAttribute(
      'sandbox',
      'allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation'
    );
    frame.addEventListener('load', () => slot.classList.remove('ad-slot-loading'), { once: true });
    frame.addEventListener('error', () => hide(slot), { once: true });
    frame.src = safe.frameUrl;

    slot.append(label, frame);
    window.dispatchEvent(new CustomEvent('znews:ad-mounted', {
      detail: { provider: 'ADSTERRA', slot: safe.slot, width: safe.width, height: safe.height }
    }));
    return true;
  }

  function hideAll(root = document) {
    root.querySelectorAll('[data-znews-ad-slot]').forEach((slot) => hide(slot));
  }

  window.ZNewsAds = Object.freeze({
    provider: 'ADSTERRA',
    mount,
    hide,
    hideAll
  });
})();
