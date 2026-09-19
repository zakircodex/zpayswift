(() => {
  'use strict';

  const api = window.BirthdayApi;
  const templates = window.BirthdayTemplates;
  const slug = String(document.body.dataset.slug || '').trim().toLowerCase();
  const $ = selector => document.querySelector(selector);
  let publicLocale = document.documentElement.lang === 'bn' ? 'bn' : 'en';
  const tr = (english, bangla) => publicLocale === 'bn' ? bangla : english;

  function toast(message) {
    const node = $('#birthdayToast');
    if (!node) return;
    node.textContent = String(message || '');
    node.hidden = false;
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => { node.hidden = true; }, 3200);
  }

  function record(type, metadata = {}) {
    if (!slug) return Promise.resolve();
    return api.event(slug, type, metadata).catch(() => undefined);
  }

  function shareText(universe) {
    return publicLocale === 'bn' ? `${universe.name}-এর Birthday Universe` : `${universe.name}'s Birthday Universe`;
  }

  function shareUrl() {
    return `${window.location.origin}/u/${encodeURIComponent(slug)}`;
  }

  function setShareLinks(universe) {
    const url = shareUrl();
    const text = shareText(universe);
    const encodedUrl = encodeURIComponent(url);
    const encodedText = encodeURIComponent(`${text} ${url}`);
    const channels = {
      whatsappShare: `https://wa.me/?text=${encodedText}`,
      telegramShare: `https://t.me/share/url?url=${encodedUrl}&text=${encodeURIComponent(text)}`,
      facebookShare: `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`
    };
    Object.entries(channels).forEach(([id, href]) => {
      const link = document.getElementById(id);
      if (!link) return;
      link.href = href;
      link.target = '_blank';
      link.addEventListener('click', () => { void record('SHARE_CLICKED', { channel: id.replace('Share', '') }); });
    });

    const nativeShare = async () => {
      if (navigator.share) {
        try {
          await navigator.share({ title: text, text: tr(`A special digital birthday universe created for ${universe.name}.`, `${universe.name}-এর জন্য তৈরি একটি বিশেষ ডিজিটাল Birthday Universe।`), url });
          await record('SHARE_CLICKED', { channel: 'native' });
          return;
        } catch (error) {
          if (error?.name === 'AbortError') return;
        }
      }
      await copyLink(url);
    };
    $('#nativeShare')?.addEventListener('click', nativeShare);
    $('#publicShareTop')?.addEventListener('click', nativeShare);
    $('#copyPublicLink')?.addEventListener('click', () => { void copyLink(url); });
  }

  async function copyLink(url) {
    try {
      await navigator.clipboard.writeText(url);
    } catch (_error) {
      const input = document.createElement('textarea');
      input.value = url;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.append(input);
      input.select();
      document.execCommand('copy');
      input.remove();
    }
    toast(tr('Link copied.', 'লিংক কপি হয়েছে।'));
    await record('SHARE_CLICKED', { channel: 'copy' });
  }

  function renderQr() {
    const target = $('#qrCode');
    if (!target || typeof window.qrcode !== 'function') return;
    try {
      const code = window.qrcode(0, 'M');
      code.addData(shareUrl(), 'Byte');
      code.make();
      const modules = code.getModuleCount();
      const quietZone = 4;
      const cellSize = 7;
      const canvas = document.createElement('canvas');
      canvas.width = (modules + quietZone * 2) * cellSize;
      canvas.height = canvas.width;
      const context = canvas.getContext('2d', { alpha: false });
      if (!context) throw new Error('Canvas is unavailable.');
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.fillStyle = '#000000';
      for (let row = 0; row < modules; row += 1) {
        for (let column = 0; column < modules; column += 1) {
          if (code.isDark(row, column)) {
            context.fillRect((column + quietZone) * cellSize, (row + quietZone) * cellSize, cellSize, cellSize);
          }
        }
      }
      const image = new Image();
      image.alt = 'QR code containing the public Birthday Universe URL';
      image.width = 196;
      image.height = 196;
      image.src = canvas.toDataURL('image/png');
      target.replaceChildren(image);
      $('#downloadQr')?.addEventListener('click', () => {
        const link = document.createElement('a');
        link.download = `birthday-universe-${slug}.png`;
        link.href = image.src;
        document.body.append(link);
        link.click();
        link.remove();
        void record('QR_DOWNLOADED', { channel: 'png' });
      });
    } catch (_error) {
      target.textContent = tr('QR code is unavailable.', 'QR code পাওয়া যাচ্ছে না।');
      $('#downloadQr')?.setAttribute('disabled', '');
    }
  }

  function trustedAdFrame(rawUrl) {
    try {
      const url = new URL(String(rawUrl || ''), window.location.origin);
      const pageHost = window.location.hostname.toLowerCase();
      const allowedHosts = pageHost === 'zsky24.com'
        ? new Set(['www.zsky24.com'])
        : pageHost === 'www.zsky24.com'
          ? new Set(['zsky24.com'])
          : new Set([pageHost]);
      return allowedHosts.has(url.hostname.toLowerCase())
        && url.pathname === '/api/znews/public/ad_frame.php'
        && Boolean(url.searchParams.get('permit')) ? url.toString() : '';
    } catch (_error) {
      return '';
    }
  }

  function mountAd(container, delivery) {
    const frameUrl = trustedAdFrame(delivery?.frame_url);
    if (!container || delivery?.enabled !== true || !frameUrl) return false;
    const frame = document.createElement('iframe');
    frame.src = frameUrl;
    frame.title = 'Advertisement';
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.setAttribute('credentialless', '');
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation');
    frame.width = String(delivery.width || '100%');
    frame.height = String(delivery.height || 300);
    const channel = String(delivery.resize_channel || '');
    if (channel) {
      const resize = event => {
        if (event.source !== frame.contentWindow || event.data?.type !== 'znews:adsterra-native-size' || event.data?.channel !== channel) return;
        const height = Math.max(90, Math.min(1600, Math.ceil(Number(event.data.height || 0))));
        if (Number.isFinite(height)) frame.height = String(height);
      };
      window.addEventListener('message', resize);
    }
    container.append(frame);
    container.hidden = false;
    void record('AD_DELIVERED', { channel: 'adsterra' });
    return true;
  }

  async function loadAd() {
    const slot = $('#birthdayPublicAd');
    if (!slot) return;
    try {
      const result = await window.BirthdayAdService.capability({ context: 'public', slug });
      if (!mountAd(slot, result.delivery)) slot.hidden = true;
    } catch (_error) {
      slot.hidden = true;
    }
  }

  function wireReport() {
    const dialog = $('#reportDialog');
    const form = $('#reportForm');
    $('#reportUniverse')?.addEventListener('click', () => dialog?.showModal());
    form?.addEventListener('submit', async event => {
      event.preventDefault();
      if (event.submitter?.value === 'cancel') {
        dialog.close();
        return;
      }
      const button = event.submitter;
      if (button) button.disabled = true;
      try {
        await api.report(slug, $('#reportReason')?.value || 'OTHER', $('#reportDetails')?.value || '');
        form.reset();
        dialog.close();
        toast(tr('Thank you. The report was submitted.', 'ধন্যবাদ। রিপোর্ট জমা হয়েছে।'));
      } catch (error) {
        toast(error.message || tr('The report could not be submitted.', 'রিপোর্ট জমা দেওয়া যায়নি।'));
      } finally {
        if (button) button.disabled = false;
      }
    });
  }

  async function init() {
    if (document.body.dataset.available !== 'true' || !slug || !api || !templates) return;
    try {
      const result = await api.universe(slug);
      const universe = result.universe;
      publicLocale = universe.locale === 'bn' ? 'bn' : 'en';
      templates.render($('#publicUniverse'), universe, {
        onMusicPlay: musicId => { void record('MUSIC_PLAYED', { music_id: musicId }); }
      });
      setShareLinks(universe);
      renderQr();
      wireReport();
      void record('VIEWED', { template_id: universe.template?.id || 'cosmic' });
      void loadAd();
    } catch (error) {
      const mount = $('#publicUniverse');
      if (mount) {
        mount.replaceChildren();
        const state = document.createElement('div');
        state.className = 'loading-state';
        state.textContent = error.message || 'This Birthday Universe could not be opened.';
        mount.append(state);
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => { void init(); });
})();
