(function () {
  'use strict';

  const backButton = document.getElementById('informationBackButton');
  if (!backButton || backButton.dataset.bound === '1') return;
  backButton.dataset.bound = '1';

  backButton.addEventListener('click', (event) => {
    let previousUserPage = false;
    try {
      const referrer = document.referrer ? new URL(document.referrer) : null;
      previousUserPage = Boolean(
        referrer
        && referrer.origin === window.location.origin
        && referrer.pathname.startsWith('/user/')
        && referrer.pathname !== window.location.pathname
      );
    } catch (_) {
      previousUserPage = false;
    }

    if (!previousUserPage) return;
    event.preventDefault();
    window.history.back();
  });
})();
