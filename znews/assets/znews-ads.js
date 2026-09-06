(() => {
  'use strict';

  const SUPPORTED_SIZES = new Set(['160x300', '160x600', '300x250', '320x50', '468x60', '728x90']);
  const cleanupBySlot = new WeakMap();

  function clearSlot(slot) {
    if (!(slot instanceof HTMLElement)) return;
    const cleanup = cleanupBySlot.get(slot);
    if (typeof cleanup === 'function') cleanup();
    cleanupBySlot.delete(slot);
    slot.replaceChildren();
    slot.classList.remove('ad-slot-live', 'ad-slot-loading', 'ad-slot-native');
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
    const creativeFormat = String(delivery.creative_format || 'banner').trim().toLowerCase();
    const width = Number(delivery.width || 0);
    const height = Number(delivery.height || 0);
    const size = `${width}x${height}`;
    if (slot !== expectedSlot || slot !== 'post_reader') return null;

    let resizeChannel = '';
    if (creativeFormat === 'native_banner') {
      resizeChannel = String(delivery.resize_channel || '').trim();
      if (!/^[a-f0-9]{24}$/.test(resizeChannel) || height < 160 || height > 640) return null;
    } else if (creativeFormat !== 'banner' || !SUPPORTED_SIZES.has(size)) {
      return null;
    }

    try {
      const frameUrl = new URL(String(delivery.frame_url || ''), window.location.origin);
      if (frameUrl.origin !== window.location.origin
        || frameUrl.pathname !== '/api/znews/public/ad_frame.php'
        || !frameUrl.searchParams.get('permit')) {
        return null;
      }
      return { frameUrl: frameUrl.toString(), slot, creativeFormat, width, height, resizeChannel };
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
    slot.classList.toggle('ad-slot-native', safe.creativeFormat === 'native_banner');
    slot.dataset.adDeliveryUrl = safe.frameUrl;

    const label = document.createElement('span');
    label.className = 'ad-label';
    label.textContent = 'Sponsored';

    const frame = document.createElement('iframe');
    frame.className = 'ad-slot-frame';
    frame.title = 'Advertisement';
    frame.height = String(safe.height);
    if (safe.creativeFormat === 'native_banner') {
      frame.style.width = '100%';
      const resize = (event) => {
        const data = event.data;
        if (event.source !== frame.contentWindow
          || !data
          || data.type !== 'znews:adsterra-native-size'
          || data.channel !== safe.resizeChannel) return;
        const reportedHeight = Math.ceil(Number(data.height));
        if (!Number.isFinite(reportedHeight) || reportedHeight < 1) return;
        const nextHeight = Math.max(90, Math.min(1600, reportedHeight));
        frame.height = String(nextHeight);
        frame.style.height = `${nextHeight}px`;
        slot.classList.remove('ad-slot-loading');
      };
      window.addEventListener('message', resize);
      cleanupBySlot.set(slot, () => window.removeEventListener('message', resize));
    } else {
      frame.width = String(safe.width);
    }
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
      detail: {
        provider: 'ADSTERRA',
        slot: safe.slot,
        creative_format: safe.creativeFormat,
        width: safe.width,
        height: safe.height
      }
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
