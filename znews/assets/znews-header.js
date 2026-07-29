(() => {
  'use strict';

  const header = document.querySelector('#appHeader');
  const search = document.querySelector('#headerSearch');
  const searchToggle = document.querySelector('#searchToggle');
  const searchForm = document.querySelector('#storySearchForm');
  const searchInput = document.querySelector('#storySearchInput');
  const searchClear = document.querySelector('#storySearchClear');
  const searchStatus = document.querySelector('#searchStatus');
  const feedList = document.querySelector('#feedList');
  const menuToggle = document.querySelector('#menuToggle');
  const menuDrawer = document.querySelector('#menuDrawer');
  const menuClose = document.querySelector('#menuClose');
  const menuBackdrop = document.querySelector('#menuBackdrop');

  if (!header || !search || !searchToggle || !searchForm || !searchInput
    || !feedList || !menuToggle || !menuDrawer || !menuClose || !menuBackdrop) return;

  const access = window.ZNewsAccess || { authenticated: false };
  let lastFocused = null;
  let backdropTimer = 0;
  let searchHistoryArmed = false;

  function feedRouteButton() {
    return document.querySelector('.desktop-nav [data-route="feed"]')
      || document.querySelector('[data-route="feed"]');
  }

  function openFeed() {
    if (document.querySelector('[data-view="feed"]')?.classList.contains('active')) return;
    feedRouteButton()?.click();
  }

  function setSearchOpen(open, { focus = true } = {}) {
    const expanded = open === true;
    search.classList.toggle('is-open', expanded);
    header.classList.toggle('search-open', expanded);
    searchToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    searchForm.setAttribute('aria-hidden', expanded ? 'false' : 'true');
    if (expanded && focus) window.setTimeout(() => searchInput.focus(), 60);
  }

  function searchableCards() {
    return [...feedList.querySelectorAll(':scope > [data-post-id]')];
  }

  function applySearch() {
    const query = searchInput.value.trim().toLocaleLowerCase();
    const cards = searchableCards();
    let matches = 0;

    cards.forEach((card) => {
      const haystack = card.textContent.toLocaleLowerCase();
      const visible = !query || haystack.includes(query);
      card.hidden = !visible;
      if (visible) matches += 1;
    });

    feedList.querySelectorAll(':scope > .ad-slot').forEach((slot) => {
      slot.hidden = query !== '';
    });

    if (searchClear) searchClear.hidden = query === '';
    if (!searchStatus) return;

    if (!query) {
      searchStatus.hidden = true;
      searchStatus.textContent = '';
      return;
    }

    searchStatus.hidden = false;
    searchStatus.textContent = matches === 0
      ? `No loaded stories match “${searchInput.value.trim()}”.`
      : `${matches} loaded ${matches === 1 ? 'story' : 'stories'} found.`;
  }

  function clearSearchValue() {
    searchInput.value = '';
    applySearch();
  }

  function openSearch() {
    if (search.classList.contains('is-open')) return;
    openFeed();
    history.pushState({ ...(history.state || {}), znewsOverlay: 'search' }, '', window.location.href);
    searchHistoryArmed = true;
    setSearchOpen(true);
  }

  function closeSearch({ restoreFocus = true, useHistory = true } = {}) {
    if (!search.classList.contains('is-open')) return;
    clearSearchValue();

    if (useHistory && searchHistoryArmed) {
      history.back();
      return;
    }

    searchHistoryArmed = false;
    setSearchOpen(false, { focus: false });
    if (restoreFocus) searchToggle.focus();
  }

  function openMenu() {
    window.clearTimeout(backdropTimer);
    lastFocused = document.activeElement;
    menuBackdrop.hidden = false;
    document.body.classList.add('znews-menu-open');
    menuDrawer.classList.add('is-open');
    menuBackdrop.classList.add('is-open');
    menuDrawer.setAttribute('aria-hidden', 'false');
    menuToggle.setAttribute('aria-expanded', 'true');
    window.setTimeout(() => menuClose.focus(), 50);
  }

  function closeMenu({ restoreFocus = true } = {}) {
    document.body.classList.remove('znews-menu-open');
    menuDrawer.classList.remove('is-open');
    menuBackdrop.classList.remove('is-open');
    menuDrawer.setAttribute('aria-hidden', 'true');
    menuToggle.setAttribute('aria-expanded', 'false');
    backdropTimer = window.setTimeout(() => { menuBackdrop.hidden = true; }, 220);
    if (restoreFocus && lastFocused instanceof HTMLElement) lastFocused.focus();
  }

  function applyMenuAccess() {
    menuDrawer.querySelectorAll('[data-guest-only]').forEach((element) => {
      element.hidden = access.authenticated === true;
    });
  }

  function syncDrawerRoute() {
    const activeRoute = document.querySelector('.desktop-nav [data-route].active')?.dataset.route || 'feed';
    menuDrawer.querySelectorAll('[data-menu-route]').forEach((item) => {
      item.classList.toggle('active', item.dataset.menuRoute === activeRoute);
    });
  }

  searchToggle.addEventListener('click', () => {
    if (search.classList.contains('is-open')) closeSearch();
    else openSearch();
  });

  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    openFeed();
    applySearch();
  });

  searchInput.addEventListener('input', () => {
    openFeed();
    applySearch();
  });

  searchClear?.addEventListener('click', () => {
    clearSearchValue();
    searchInput.focus();
  });
  menuToggle.addEventListener('click', openMenu);
  menuClose.addEventListener('click', () => closeMenu());
  menuBackdrop.addEventListener('click', () => closeMenu());

  menuDrawer.querySelectorAll('[data-menu-route]').forEach((item) => {
    item.addEventListener('click', () => {
      const route = item.dataset.menuRoute;
      closeMenu({ restoreFocus: false });
      const routeButton = document.querySelector(`.desktop-nav [data-route="${route}"]`)
        || document.querySelector(`[data-route="${route}"]`);
      routeButton?.click();
      syncDrawerRoute();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (menuDrawer.classList.contains('is-open')) {
      event.preventDefault();
      closeMenu();
      return;
    }
    if (search.classList.contains('is-open')) {
      event.preventDefault();
      closeSearch();
    }
  });

  document.addEventListener('click', (event) => {
    if (!search.classList.contains('is-open')) return;
    if (event.target instanceof Node && search.contains(event.target)) return;
    if (searchInput.value.trim() === '') closeSearch({ restoreFocus: false });
  });

  window.addEventListener('popstate', () => {
    if (!search.classList.contains('is-open')) return;
    searchHistoryArmed = false;
    clearSearchValue();
    setSearchOpen(false, { focus: false });
  });

  const feedObserver = new MutationObserver(() => applySearch());
  feedObserver.observe(feedList, { childList: true });

  const routeObserver = new MutationObserver(syncDrawerRoute);
  document.querySelectorAll('[data-route]').forEach((item) => {
    routeObserver.observe(item, { attributes: true, attributeFilter: ['class'] });
  });

  applyMenuAccess();
  syncDrawerRoute();
  setSearchOpen(false, { focus: false });

  window.ZNewsHeader = Object.freeze({
    closeSearch: () => closeSearch({ useHistory: false }),
    isSearchOpen: () => search.classList.contains('is-open')
  });
})();
