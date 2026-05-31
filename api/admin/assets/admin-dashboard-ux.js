// Z-Pay Swift admin dashboard UX injector
(function(){
  'use strict';
  function addCss(){
    if(document.getElementById('zpayAdminUxCss')) return;
    var link=document.createElement('link');
    link.id='zpayAdminUxCss';
    link.rel='stylesheet';
    link.href='assets/admin-ux.css?v=1';
    document.head.appendChild(link);
  }
  function brand(){
    document.title='Z-Pay Swift Admin Dashboard';
    document.querySelectorAll('.brand h1,.sidebar-brand h1').forEach(function(n){n.textContent='Z-Pay Swift Admin';});
    var topTitle=document.querySelector('.topbar h2');
    if(topTitle) topTitle.textContent='Z-Pay Swift Admin Dashboard';
    var topSub=document.querySelector('.topbar p');
    if(topSub) topSub.textContent='Secure operations panel for topup, bundle and bKash/Nagad.';
  }
  function addMfsLinks(){
    var nav=document.querySelector('.sidebar .nav');
    if(nav && !document.getElementById('zpayAdminMfsNav')){
      var a=document.createElement('a');
      a.id='zpayAdminMfsNav';
      a.className='nav-btn zpay-admin-mfs-link';
      a.href='mfs.php';
      a.innerHTML='bKash / Nagad <span>›</span>';
      var bundleBtn=nav.querySelector('[data-section="bundleSection"]');
      if(bundleBtn && bundleBtn.nextSibling) nav.insertBefore(a,bundleBtn.nextSibling); else nav.appendChild(a);
    }
    var actions=document.querySelector('.topbar .actions');
    if(actions && !document.getElementById('zpayAdminMfsTopLink')){
      var top=document.createElement('a');
      top.id='zpayAdminMfsTopLink';
      top.className='btn brand zpay-admin-mfs-toplink';
      top.href='mfs.php';
      top.textContent='bKash / Nagad';
      actions.insertBefore(top, actions.firstChild);
    }
  }
  function init(){ addCss(); brand(); addMfsLinks(); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init); else init();
  setTimeout(init,500);
  setTimeout(init,1500);
})();
