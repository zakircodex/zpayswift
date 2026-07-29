(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  const dialog = document.querySelector('#authDialog');
  const form = document.querySelector('#authForm');
  const credentialFields = document.querySelector('#credentialFields');
  const otpFields = document.querySelector('#otpFields');
  const title = document.querySelector('#authTitle');
  const description = document.querySelector('#authDescription');
  const submit = document.querySelector('#authSubmit');
  const error = document.querySelector('#authError');
  const sessionButton = document.querySelector('#sessionButton');

  if (!config || !ApiClient || !dialog || !form || !credentialFields || !otpFields || !submit) return;

  const api = new ApiClient(config);
  let quickMode = false;

  function text(value) {
    return String(value ?? '').trim();
  }

  function maskPhone(value) {
    const phone = text(value).replace(/\D+/g, '');
    if (phone.length <= 4) return phone;
    return `${phone.slice(0, 3)}${'•'.repeat(Math.max(3, phone.length - 6))}${phone.slice(-3)}`;
  }

  function setDisabled(root, disabled) {
    root.querySelectorAll('input, select, button').forEach((field) => {
      field.disabled = disabled;
    });
  }

  function setBusy(busy) {
    submit.disabled = busy;
    submit.textContent = busy ? 'Signing in…' : (quickMode ? 'Sign in with PIN' : 'Continue');
    const pin = document.querySelector('#authQuickPin');
    if (pin) pin.disabled = busy;
  }

  function ensureQuickFields() {
    let container = document.querySelector('#quickPinFields');
    if (container) return container;

    container = document.createElement('div');
    container.id = 'quickPinFields';
    container.className = 'stack-form compact-form';
    container.hidden = true;
    container.innerHTML = `
      <div class="quick-account-card">
        <strong id="quickAccountName">Saved Z-Pay account</strong>
        <span id="quickAccountPhone"></span>
      </div>
      <label>PIN
        <input id="authQuickPin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="12">
      </label>
      <button id="useFullLogin" class="ghost-button full" type="button">Use password and OTP instead</button>`;
    otpFields.insertAdjacentElement('afterend', container);
    document.querySelector('#useFullLogin')?.addEventListener('click', () => showFullLogin('Use your password and PIN. OTP is only required when the saved login has expired.'));
    return container;
  }

  function prefillSavedAccount() {
    const profile = api.getSavedProfile();
    const phone = text(profile.phone || profile.PHONE);
    const country = text(profile.phone_country || profile.country || profile.COUNTRY).toUpperCase();
    const phoneInput = document.querySelector('#authPhone');
    const countryInput = document.querySelector('#authCountry');
    if (phoneInput && phone) phoneInput.value = phone;
    if (countryInput && ['MY', 'BD'].includes(country)) countryInput.value = country;
  }

  function showFullLogin(message = '') {
    quickMode = false;
    const quickFields = ensureQuickFields();
    quickFields.hidden = true;
    credentialFields.hidden = false;
    otpFields.hidden = true;
    setDisabled(credentialFields, false);
    form.removeAttribute('novalidate');
    title.textContent = 'Sign in to Z News';
    description.textContent = message || 'Use your existing Z-Pay phone number, password and PIN.';
    submit.textContent = 'Continue';
    submit.disabled = false;
    prefillSavedAccount();
    document.querySelector('#authPassword')?.focus();
  }

  function showQuickLogin() {
    if (api.isAuthenticated() || !api.hasSavedQuickLogin()) return;

    quickMode = true;
    const quickFields = ensureQuickFields();
    const profile = api.getSavedProfile();
    const name = text(profile.name || profile.NAME || 'Z-Pay user');
    const phone = text(profile.phone || profile.PHONE);

    credentialFields.hidden = true;
    otpFields.hidden = true;
    setDisabled(credentialFields, true);
    quickFields.hidden = false;
    form.setAttribute('novalidate', 'novalidate');
    title.textContent = `Welcome back${name ? `, ${name}` : ''}`;
    description.textContent = 'Enter your Z-Pay PIN. OTP is not required on this saved device.';
    document.querySelector('#quickAccountName').textContent = name || 'Saved Z-Pay account';
    document.querySelector('#quickAccountPhone').textContent = phone ? maskPhone(phone) : 'Saved account';
    submit.textContent = 'Sign in with PIN';
    submit.disabled = false;
    error.hidden = true;
    const pin = document.querySelector('#authQuickPin');
    if (pin) {
      pin.value = '';
      window.setTimeout(() => pin.focus(), 50);
    }
  }

  async function submitQuickLogin(event) {
    if (!quickMode) return;
    event.preventDefault();
    event.stopImmediatePropagation();

    const pin = text(document.querySelector('#authQuickPin')?.value);
    if (!pin) {
      error.textContent = 'Enter your Z-Pay PIN.';
      error.hidden = false;
      return;
    }

    error.hidden = true;
    setBusy(true);
    try {
      const profile = api.getSavedProfile();
      const result = await api.pinLogin({
        pin,
        phone: text(profile.phone || profile.PHONE),
        phone_country: text(profile.phone_country || profile.country || profile.COUNTRY),
        device_id: api.getDeviceId(),
        device_name: 'Z News Web',
        app_version: 'znews-web-2'
      });
      const sessionToken = text(result.data?.session_token);
      if (!sessionToken) {
        throw new window.ZNewsApiError('PIN login did not return a session token.', {
          code: 'MALFORMED_RESPONSE'
        });
      }
      api.setSession(sessionToken, result.data?.user || profile);
      window.location.reload();
    } catch (requestError) {
      const code = text(requestError?.code);
      if (['SESSION_EXPIRED', 'DEVICE_REPLACED', 'ACCOUNT_NOT_FOUND'].includes(code)) {
        api.clearExpiredSession();
        showFullLogin('Saved login expired. Sign in once with password, PIN and OTP to trust this device again.');
      }
      error.textContent = requestError?.message || 'PIN login failed.';
      error.hidden = false;
    } finally {
      setBusy(false);
    }
  }

  function refreshButtonLabel() {
    if (sessionButton && !api.isAuthenticated() && api.hasSavedQuickLogin()) {
      sessionButton.textContent = 'Unlock';
    }
  }

  form.addEventListener('submit', submitQuickLogin, true);

  const observer = new MutationObserver(() => {
    refreshButtonLabel();
    if (dialog.open) showQuickLogin();
  });
  observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
  if (sessionButton) {
    observer.observe(sessionButton, { childList: true, subtree: true });
  }

  refreshButtonLabel();
  if (dialog.open) showQuickLogin();
})();
