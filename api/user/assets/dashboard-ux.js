// Z-Pay Swift user dashboard UX helper.
// This file only adds a mobile-friendly quick action card and does not change backend logic.
(function(){
  'use strict';

  function byId(id){
    return document.getElementById(id);
  }

  function openDashboardSection(sectionId){
    if (typeof window.openSection === 'function') {
      window.openSection(sectionId);
      return;
    }

    document.querySelectorAll('.page-section').forEach(function(node){
      node.classList.remove('active');
    });

    document.querySelectorAll('.side-btn,.bottom-btn').forEach(function(node){
      node.classList.toggle('active', node.getAttribute('data-page-section') === sectionId);
    });

    var section = byId(sectionId);
    if (section) section.classList.add('active');
  }

  function createButton(icon, label, sectionId){
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'zpay-service-btn';
    btn.setAttribute('data-page-section', sectionId);
    btn.innerHTML = '<span class="zpay-service-icon">' + icon + '</span><span class="zpay-service-name">' + label + '</span>';
    btn.addEventListener('click', function(){
      openDashboardSection(sectionId);
    });
    return btn;
  }

  function ensureQuickActions(){
    if (byId('zpayQuickActions')) return;

    var hero = document.querySelector('.hero-card');
    if (!hero || !hero.parentNode) return;

    var card = document.createElement('div');
    card.id = 'zpayQuickActions';
    card.className = 'zpay-quick-card';

    var head = document.createElement('div');
    head.className = 'zpay-quick-head';
    head.innerHTML = '<div><h3 class="zpay-quick-title">Quick Services</h3><p class="zpay-quick-sub">Fast access to the most used Z-Pay Swift services</p></div><div class="zpay-rate-chip">Fast • Secure</div>';

    var grid = document.createElement('div');
    grid.className = 'zpay-service-grid';
    grid.appendChild(createButton('↗', 'Topup', 'topupSection'));
    grid.appendChild(createButton('৳', 'bKash/Nagad', 'mfsSection'));
    grid.appendChild(createButton('▣', 'Bundle', 'bundleSection'));
    grid.appendChild(createButton('⌕', 'History', 'historySection'));

    card.appendChild(head);
    card.appendChild(grid);
    hero.insertAdjacentElement('afterend', card);
  }

  function init(){
    ensureQuickActions();

    var observer = new MutationObserver(function(){
      if (document.body.classList.contains('user-authenticated')) {
        ensureQuickActions();
      }
    });

    observer.observe(document.body, { attributes:true, attributeFilter:['class'] });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
