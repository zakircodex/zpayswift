(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const releaseInitialLoad = window.UserShell?.holdPageLoad?.('Loading referral details...') || (() => {});
  const state = {
    data: null,
    history: [],
    nextBefore: 0,
    hasMore: false,
    loadingMore: false,
    claiming: false
  };

  function money(amount, currency) {
    const value = Number(amount || 0).toFixed(2);
    return String(currency || '').toUpperCase() === 'MYR' ? `RM ${value}` : `BDT ${value}`;
  }

  function dateText(timestamp) {
    let value = Number(timestamp || 0);
    if (!value) return '-';
    if (value < 100000000000) value *= 1000;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString([], {
      year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
    });
  }

  function safeMessage(error, fallback) {
    const message = String(error?.message || fallback || 'Please try again.').trim();
    return message && message.length <= 220 && !/firebase|stack|exception|session.token|app.key|\/api\//i.test(message)
      ? message
      : String(fallback || 'Please try again.');
  }

  function showStatus(message, type) {
    const node = $('referralStatusMessage');
    if (!node) return;
    node.textContent = String(message || '');
    node.className = `referral-status-message ${type || ''}`.trim();
    node.classList.toggle('hidden', !message);
  }

  function setButtonBusy(button, busy, label) {
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.textContent;
      button.textContent = label || 'Please wait...';
      button.disabled = true;
    } else {
      button.textContent = button.dataset.originalText || button.textContent;
      button.disabled = false;
      delete button.dataset.originalText;
    }
  }

  function renderRules(data) {
    const rules = data.rules || {};
    const container = $('referralRules');
    if (!container) return;
    if (data.country === 'BD') {
      container.innerHTML = [
        `<div class="referral-rule-row"><span>One-time reward</span><strong>${money(rules.bd_reward_bdt, 'BDT')}</strong></div>`,
        '<div class="referral-rule-row"><span>Reward timing</span><strong>After Android verification</strong></div>',
        `<div class="referral-rule-row"><span>Claim period</span><strong>${Number(rules.claim_window_days || 7)} days</strong></div>`
      ].join('');
      return;
    }
    const role = String(window.userState?.user?.role || 'USER').toUpperCase() === 'RETAILER' ? 'RETAILER' : 'USER';
    const tiers = rules.my_commissions?.[role] || {};
    container.innerHTML = [
      `<div class="referral-rule-row"><span>BDT 500 - 50,000 success</span><strong>${money(tiers.TIER1, 'MYR')}</strong></div>`,
      `<div class="referral-rule-row"><span>BDT 50,000.01 - 70,000 success</span><strong>${money(tiers.TIER2, 'MYR')}</strong></div>`,
      `<div class="referral-rule-row"><span>BDT 70,000.01 - 100,000 success</span><strong>${money(tiers.TIER3, 'MYR')}</strong></div>`,
      '<div class="referral-rule-row"><span>Eligible services</span><strong>bKash &amp; Nagad</strong></div>'
    ].join('');
  }

  function historyTitle(item) {
    if (String(item.payout_type || '').toUpperCase() === 'BD_ONETIME') return 'Bangladesh referral reward';
    const provider = String(item.provider || '').toUpperCase();
    return `${provider === 'NAGAD' ? 'Nagad' : 'bKash'} success commission`;
  }

  function renderHistory() {
    const list = $('referralHistoryList');
    if (!list) return;
    if (!state.history.length) {
      list.innerHTML = '<div class="referral-empty">No referral rewards yet.</div>';
    } else {
      list.innerHTML = state.history.map((item) => `
        <article class="referral-history-item">
          <div>
            <h3>${window.UserShell.escapeHtml(historyTitle(item))}</h3>
            <p>${window.UserShell.escapeHtml(item.referred_name_masked || 'Z-Pay user')} &middot; ${window.UserShell.escapeHtml(dateText(item.completed_at || item.created_at))}</p>
          </div>
          <strong class="referral-history-amount">${window.UserShell.escapeHtml(money(item.amount, item.currency))}</strong>
        </article>
      `).join('');
    }
    $('referralSeeMore')?.classList.toggle('hidden', !state.hasMore);
    if ($('referralSeeMore')) $('referralSeeMore').disabled = state.loadingMore;
  }

  function render(data, appendHistory) {
    state.data = data;
    const history = data.history || {};
    const items = Array.isArray(history.items) ? history.items : [];
    state.history = appendHistory ? state.history.concat(items) : items;
    state.hasMore = Boolean(history.has_more);
    state.nextBefore = Number(history.next_before || 0);

    $('referralTotalEarned').textContent = money(data.total_earned, data.reward_currency);
    $('referralCount').textContent = String(Number(data.referred_count || 0));
    $('referralRewardedCount').textContent = String(Number(data.rewarded_count || 0));
    $('referralCode').textContent = String(data.referral_code || 'Unavailable');
    $('referralHistoryCurrency').textContent = String(data.reward_currency || '');

    const relation = data.relation || {};
    const claim = data.claim || {};
    $('referralClaimPanel')?.classList.toggle('hidden', !claim.eligible || Object.keys(relation).length > 0);
    $('referralDevicePanel')?.classList.toggle('hidden', !data.device_verification_required);
    if (String(relation.status || '').toUpperCase() === 'BLOCKED_DEVICE') {
      showStatus('This device is already linked to another account, so this account will not receive referral rewards.', 'error');
    }
    renderRules(data);
    renderHistory();
    $('referralSection')?.setAttribute('aria-busy', 'false');
  }

  async function loadStatus(appendHistory) {
    const params = { limit: 10 };
    if (appendHistory && state.nextBefore) params.before = state.nextBefore;
    const data = await window.proxyGet('referral_status', params, appendHistory ? 'Loading more rewards...' : 'Loading referral details...', { busy: !appendHistory });
    render(data, appendHistory);
  }

  async function copyText(value, successMessage) {
    const text = String(value || '');
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      showStatus(successMessage, 'success');
    } catch (_) {
      showStatus('Copy was not available. Please select and copy the code manually.', 'error');
    }
  }

  async function claimReferral(event) {
    event.preventDefault();
    if (state.claiming) return;
    const code = String($('referralClaimCode')?.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (code.length < 8 || code.length > 20) {
      showStatus('Enter a valid referral code.', 'error');
      return;
    }
    state.claiming = true;
    setButtonBusy($('referralClaimButton'), true, 'Applying...');
    try {
      const data = await window.proxyPost('referral_claim', { referral_code: code }, 'Linking referral...');
      showStatus('Referral linked. Open this account in the Android app to activate rewards.', 'success');
      await loadStatus(false);
      if (data?.relation) $('referralDevicePanel')?.classList.remove('hidden');
    } catch (error) {
      showStatus(safeMessage(error, 'Referral code could not be linked.'), 'error');
    } finally {
      state.claiming = false;
      setButtonBusy($('referralClaimButton'), false);
    }
  }

  function bind() {
    $('referralCopyCode')?.addEventListener('click', () => copyText(state.data?.referral_code, 'Referral code copied.'));
    $('referralCopyLink')?.addEventListener('click', () => copyText(state.data?.share_url, 'Referral link copied.'));
    $('referralShare')?.addEventListener('click', async () => {
      const url = String(state.data?.share_url || '');
      const text = 'Join Z-Pay Swift with my referral code ' + String(state.data?.referral_code || '') + '.';
      if (navigator.share) {
        try {
          await navigator.share({ title: 'Z-Pay Swift Refer & Earn', text, url });
          return;
        } catch (error) {
          if (String(error?.name || '') === 'AbortError') return;
        }
      }
      await copyText(url, 'Referral link copied.');
    });
    $('referralClaimForm')?.addEventListener('submit', claimReferral);
    $('referralSeeMore')?.addEventListener('click', async () => {
      if (state.loadingMore || !state.hasMore) return;
      state.loadingMore = true;
      setButtonBusy($('referralSeeMore'), true, 'Loading...');
      try {
        await loadStatus(true);
      } catch (error) {
        showStatus(safeMessage(error, 'More rewards could not be loaded.'), 'error');
      } finally {
        state.loadingMore = false;
        setButtonBusy($('referralSeeMore'), false);
      }
    });
  }

  async function init() {
    try {
      await window.UserShell.ready;
      bind();
      const pendingCode = String($('referralSection')?.dataset.pendingCode || '').toUpperCase();
      if (pendingCode && $('referralClaimCode')) $('referralClaimCode').value = pendingCode;
      await loadStatus(false);
    } catch (error) {
      $('referralSection')?.setAttribute('aria-busy', 'false');
      showStatus(safeMessage(error, 'Referral details could not be loaded.'), 'error');
    } finally {
      releaseInitialLoad();
    }
  }

  init();
})();
