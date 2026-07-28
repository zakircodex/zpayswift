(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG?.ads || {};
  const renderers = new Map();

  function clearSlot(slot) {
    while (slot.firstChild) slot.removeChild(slot.firstChild);
  }

  function renderTestSlot(slot, slotName) {
    clearSlot(slot);
    slot.hidden = false;
    slot.classList.add('ad-slot-test');

    const badge = document.createElement('span');
    badge.className = 'ad-label';
    badge.textContent = 'Sponsored • Test placement';

    const title = document.createElement('strong');
    title.textContent = 'InMobi placement ready';

    const note = document.createElement('small');
    note.textContent = `${slotName} will show a live ad after publisher review and placement configuration.`;

    slot.append(badge, title, note);
  }

  function hideSlot(slot) {
    clearSlot(slot);
    slot.hidden = true;
  }

  async function mount(slot) {
    if (!(slot instanceof HTMLElement)) return;

    const slotName = String(slot.dataset.znewsAdSlot || '').trim();
    const placementId = String(config.placements?.[slotName] || '').trim();
    const mode = String(config.mode || 'TEST').toUpperCase();

    if (mode === 'TEST') {
      renderTestSlot(slot, slotName || 'unnamed_slot');
      return;
    }

    if (config.enabled !== true || !slotName || !placementId) {
      hideSlot(slot);
      return;
    }

    const renderer = renderers.get('INMOBI');
    if (typeof renderer !== 'function') {
      hideSlot(slot);
      console.warn('Z News: InMobi renderer is not registered.');
      return;
    }

    clearSlot(slot);
    slot.hidden = false;

    try {
      await renderer({
        element: slot,
        slotName,
        placementId,
        format: String(slot.dataset.format || 'mobile_banner')
      });
    } catch (error) {
      hideSlot(slot);
      console.warn('Z News: ad placement could not be rendered.', error);
    }
  }

  function mountAll(root = document) {
    root.querySelectorAll('[data-znews-ad-slot]').forEach((slot) => mount(slot));
  }

  function registerProviderRenderer(provider, renderer) {
    const name = String(provider || '').trim().toUpperCase();
    if (name !== 'INMOBI' || typeof renderer !== 'function') {
      throw new TypeError('A valid InMobi renderer is required.');
    }
    renderers.set(name, renderer);
  }

  /*
   * The publisher-specific WebX tag/renderer must call this registration
   * function after InMobi enables the website and provides placement tags.
   * Provider secrets, reported value and creator settlement never belong in
   * browser code; those remain in the signed server ingestion pipeline.
   */
  window.ZNewsAds = Object.freeze({
    provider: 'INMOBI',
    mount,
    mountAll,
    registerProviderRenderer
  });
})();
