// Z-Pay Swift admin dashboard presentation layer
(function(){
  'use strict';

  var sectionMeta={
    dashboardSection:['Admin Dashboard','Secure operations overview for topup, bundle and wallet activity.'],
    topupSection:['Topup Requests','Review pending, claimed, processing and completed topup requests.'],
    bundleSection:['Bundle Requests','Manage pending bundle requests and completion actions.'],
    bundleOffersSection:['Bundle Offers','Create and maintain the bundle offers shown across the platform.'],
    usersSection:['Users & Wallets','Manage user accounts, balances, ledger history and API access.'],
    operatorsSection:['Operators','Configure operator templates, availability and secure settings.']
  };

  function addCss(){
    if(document.getElementById('zpayAdminUxCss')||document.querySelector('link[href*="assets/admin-ux.css"]'))return;
    var link=document.createElement('link');
    link.id='zpayAdminUxCss';
    link.rel='stylesheet';
    link.href='assets/admin-ux.css?v=2';
    document.head.appendChild(link);
  }

  function brand(){
    document.title='Z-Pay Swift Admin Dashboard';
    document.querySelectorAll('.brand h1,.sidebar-brand h1').forEach(function(node){
      node.textContent='Z-Pay Swift Admin';
    });
  }

  function addMfsLinks(){
    var nav=document.querySelector('.sidebar .nav');
    if(nav&&!document.getElementById('zpayAdminMfsNav')){
      var link=document.createElement('a');
      link.id='zpayAdminMfsNav';
      link.className='nav-btn zpay-admin-mfs-link';
      link.href='mfs.php';
      link.innerHTML='bKash / Nagad <span>&rsaquo;</span>';
      var bundleButton=nav.querySelector('[data-section="bundleSection"]');
      if(bundleButton&&bundleButton.nextSibling)nav.insertBefore(link,bundleButton.nextSibling);
      else nav.appendChild(link);
    }

    var actions=document.querySelector('.topbar .actions');
    if(actions&&!document.getElementById('zpayAdminMfsTopLink')){
      var topLink=document.createElement('a');
      topLink.id='zpayAdminMfsTopLink';
      topLink.className='btn brand zpay-admin-mfs-toplink';
      topLink.href='mfs.php';
      topLink.textContent='bKash / Nagad';
      actions.insertBefore(topLink,actions.firstChild);
    }
  }

  function cleanVisibleArrows(){
    document.querySelectorAll('.sidebar .nav-btn span').forEach(function(node){
      node.textContent='\u203a';
    });
  }

  function setSidebar(open){
    document.body.classList.toggle('admin-sidebar-open',Boolean(open));
    var toggle=document.getElementById('adminSidebarToggle');
    if(toggle)toggle.setAttribute('aria-expanded',open?'true':'false');
  }

  function setupSidebar(){
    if(document.body.dataset.adminSidebarReady==='1')return;
    document.body.dataset.adminSidebarReady='1';

    var toggle=document.getElementById('adminSidebarToggle');
    var backdrop=document.getElementById('adminSidebarBackdrop');

    if(toggle){
      toggle.addEventListener('click',function(){
        setSidebar(!document.body.classList.contains('admin-sidebar-open'));
      });
    }

    if(backdrop){
      backdrop.addEventListener('click',function(){setSidebar(false);});
    }

    document.querySelectorAll('.sidebar .nav-btn').forEach(function(node){
      node.addEventListener('click',function(){setSidebar(false);});
    });

    document.addEventListener('keydown',function(event){
      if(event.key==='Escape')setSidebar(false);
    });
  }

  function setPageHeading(sectionId){
    var meta=sectionMeta[sectionId]||sectionMeta.dashboardSection;
    var title=document.getElementById('adminPageTitle')||document.querySelector('.topbar h2');
    var subtitle=document.getElementById('adminPageSubtitle')||document.querySelector('.topbar p');
    if(title)title.textContent=meta[0];
    if(subtitle)subtitle.textContent=meta[1];
  }

  function setupSectionHeadings(){
    if(document.body.dataset.adminHeadingReady==='1')return;
    document.body.dataset.adminHeadingReady='1';

    document.querySelectorAll('.sidebar .nav-btn[data-section]').forEach(function(button){
      button.addEventListener('click',function(){
        setPageHeading(button.getAttribute('data-section')||'dashboardSection');
      });
    });

    var active=document.querySelector('.sidebar .nav-btn.active[data-section]');
    setPageHeading(active?active.getAttribute('data-section'):'dashboardSection');
  }

  function init(){
    addCss();
    brand();
    addMfsLinks();
    cleanVisibleArrows();
    setupSidebar();
    setupSectionHeadings();
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
  else init();
})();
