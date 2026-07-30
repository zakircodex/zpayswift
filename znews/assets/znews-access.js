(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  if (!config || !ApiClient) return;

  const api = new ApiClient(config);
  const authenticated = api.isAuthenticated();
  const registerUrl = config.zpayRegisterUrl;

  function goToRegister() {
    window.location.assign(registerUrl);
  }

  function isGuestLockedRoute(target) {
    const route = target?.closest?.('[data-route]')?.dataset.route || '';
    return !authenticated && ['create', 'mine', 'balance'].includes(route);
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element) || authenticated) return;

    if (isGuestLockedRoute(target)) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      goToRegister();
      return;
    }

    if (target.closest('[data-action="like"]')) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
    }
  }, true);

  function setHidden(element, hidden) {
    if (!element) return;
    element.hidden = hidden;
    element.setAttribute('aria-hidden', hidden ? 'true' : 'false');
  }

  function applyStaticAccessUi() {
    document.documentElement.dataset.znewsAccess = authenticated ? 'creator' : 'guest';

    document.querySelectorAll('[data-auth-only]').forEach((element) => {
      setHidden(element, !authenticated);
    });

    const authDialog = document.querySelector('#authDialog');
    if (authDialog && typeof authDialog.close === 'function' && authDialog.open) {
      authDialog.close();
    }
    setHidden(authDialog, true);
    setHidden(document.querySelector('#sessionButton'), true);
    setHidden(document.querySelector('#refreshButton'), true);
    setHidden(document.querySelector('#feedRefreshInline'), true);

    const commentForm = document.querySelector('#commentForm');
    setHidden(commentForm, !authenticated);

    const mobileBalance = document.querySelector('.mobile-nav [data-route="balance"]');
    const desktopBalance = document.querySelector('.desktop-nav [data-route="balance"]');
    if (!authenticated) {
      if (mobileBalance) {
        mobileBalance.innerHTML = '<span>＋</span><small>Join Z-Pay</small>';
        mobileBalance.setAttribute('aria-label', 'Create a Z-Pay account');
      }
      if (desktopBalance) {
        desktopBalance.innerHTML = '<span>＋</span>Join Z-Pay';
        desktopBalance.setAttribute('aria-label', 'Create a Z-Pay account');
      }
    }

    const handoff = window.ZNEWS_HANDOFF_RESULT;
    const announcement = document.querySelector('#announcement');
    if (handoff?.ok === false && announcement) {
      announcement.hidden = false;
      announcement.textContent = 'Creator access expired. Open Z Sky 24 again from your Z-Pay dashboard.';
    }
  }

  function applyDynamicAccessUi(root = document) {
    if (authenticated) return;
    root.querySelectorAll?.('[data-action="like"]').forEach((button) => button.remove());
    root.querySelectorAll?.('.composer-card').forEach((card) => setHidden(card, true));
  }

  applyStaticAccessUi();
  applyDynamicAccessUi();

  const observer = new MutationObserver((records) => {
    records.forEach((record) => {
      record.addedNodes.forEach((node) => {
        if (node instanceof Element) applyDynamicAccessUi(node);
      });
    });
  });
  observer.observe(document.body, { childList: true, subtree: true });

  window.ZNewsAccess = Object.freeze({
    authenticated,
    mode: authenticated ? 'CREATOR' : 'GUEST',
    registerUrl
  });
})();
