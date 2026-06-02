// Z-Pay Swift Admin MFS panel
(function(){
  'use strict';

  var state = { tab:'pending', rows:[], csrf:'', successRequestId:'' };

  function el(id){ return document.getElementById(id); }
  function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g,function(s){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];}); }
  function money(v){ var n=Number(v||0); return Number.isFinite(n)?n.toFixed(2):'0.00'; }
  function ts(v){ var n=Number(v||0); if(!n)return '-'; var d=new Date(String(Math.trunc(n)).length<=10?n*1000:n); return isNaN(d.getTime())?'-':d.toLocaleString(); }
  function msg(text,type){ var box=el('mfsPageMsg'); if(!box)return; box.className='mfs-msg '+(type||''); box.textContent=String(text||''); }
  function statusPill(v){ var t=String(v||'-').toUpperCase(); var cls='info'; if(['SUCCESS','SUCCESSFUL','DONE','COMPLETED'].indexOf(t)>=0)cls='success'; else if(['FAILED','CANCELLED'].indexOf(t)>=0)cls='danger'; else if(['PENDING','PROCESSING','WAITING_ADMIN'].indexOf(t)>=0)cls='warning'; return '<span class="pill '+cls+'">'+esc(t)+'</span>'; }
  async function readJson(res){ var text=await res.text(); var json={}; try{json=JSON.parse(text);}catch(e){throw new Error(text||'Invalid server response');} if(!res.ok||!json.ok){var er=new Error(json.message||'Request failed'); er.code=json.code||'ERROR'; er.data=json.data||{}; throw er;} return json.data||{}; }
  async function get(action,params){ var qs=new URLSearchParams(params||{}).toString(); var res=await fetch('proxy.php?action='+encodeURIComponent(action)+(qs?'&'+qs:''),{credentials:'same-origin',headers:{Accept:'application/json','Cache-Control':'no-cache'}}); return readJson(res); }
  async function post(action,body){ var headers={'Content-Type':'application/json',Accept:'application/json','Cache-Control':'no-cache'}; if(state.csrf)headers['X-CSRF-TOKEN']=state.csrf; var res=await fetch('proxy.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:headers,body:JSON.stringify(body||{})}); return readJson(res); }
  function actionForTab(){ return state.tab==='processing'?'mfs_processing':state.tab==='done'?'mfs_done':'mfs_pending'; }
  function normalizeRows(data){ return Array.isArray(data.items)?data.items:Array.isArray(data.rows)?data.rows:Array.isArray(data.requests)?data.requests:[]; }
  function totalRows(data){ var total=Number(data&&data.pagination&&data.pagination.total); return Number.isFinite(total)?total:normalizeRows(data||{}).length; }
  function rowNumber(r){ return r.receiver_number||r.number||r.mfs_number||r.to_number||'-'; }
  function rowAmount(r){ var c=String(r.wallet_currency||'BDT').toUpperCase(); if(c==='MYR') return 'RM '+money(r.total_debit||r.amount_rm||0); return 'BDT '+money(r.total_debit||r.amount_bdt||r.amount||0); }
  function filterRows(rows){ var q=String(el('mfsSearch').value||'').toLowerCase().trim(); if(!q)return rows; return rows.filter(function(r){ return JSON.stringify(r).toLowerCase().indexOf(q)>=0; }); }
  function actionButtons(r){ var id=String(r.request_id||''); var status=String(r.status||'').toUpperCase(); var html='<div class="admin-mfs-actions"><button class="mini-btn" type="button" data-act="view" data-id="'+esc(id)+'">View</button>'; if(status!=='PROCESSING'&&status!=='SUCCESSFUL'&&status!=='SUCCESS'&&status!=='FAILED') html+='<button class="mini-btn blue" type="button" data-act="processing" data-id="'+esc(id)+'">Processing</button>'; if(status!=='SUCCESSFUL'&&status!=='SUCCESS'&&status!=='FAILED') html+='<button class="mini-btn success" type="button" data-act="success" data-id="'+esc(id)+'">Success</button><button class="mini-btn danger" type="button" data-act="failed" data-id="'+esc(id)+'">Failed</button>'; return html+'</div>'; }

  function render(){
    var rows=filterRows(state.rows);
    var body=el('mfsTableBody');
    var mobile=el('mfsMobileList');
    if(!rows.length){
      body.innerHTML='<tr><td colspan="8" class="empty">No MFS requests found.</td></tr>';
      mobile.innerHTML='<div class="empty">No MFS requests found.</div>';
      return;
    }
    body.innerHTML=rows.map(function(r){
      return '<tr><td><b>'+esc(r.request_id||'-')+'</b><br><small>'+esc(r.request_source||r.source||'-')+'</small></td><td>'+esc(r.uid||'-')+'<br><small>'+esc(r.user_phone||'-')+'</small></td><td>'+esc(r.provider_name||r.provider||'-')+'<br><small>'+esc(r.service_type||'-')+'</small></td><td><code>'+esc(rowNumber(r))+'</code></td><td>'+esc(rowAmount(r))+'</td><td>'+statusPill(r.status||'-')+'</td><td>'+esc(ts(r.created_at||r.updated_at))+'</td><td>'+actionButtons(r)+'</td></tr>';
    }).join('');
    mobile.innerHTML=rows.map(function(r){
      return '<div class="admin-mfs-card"><div class="admin-mfs-card-top"><div><h3 class="admin-mfs-card-title">'+esc(r.provider_name||r.provider||'MFS')+' &bull; '+esc(rowNumber(r))+'</h3><p class="admin-mfs-card-sub">'+esc(r.request_id||'-')+'</p></div>'+statusPill(r.status||'-')+'</div><div class="admin-mfs-card-grid"><div class="admin-mfs-kv"><label>User</label><strong>'+esc(r.uid||'-')+'</strong></div><div class="admin-mfs-kv"><label>Receiver</label><strong><code>'+esc(rowNumber(r))+'</code></strong></div><div class="admin-mfs-kv"><label>Amount</label><strong>'+esc(rowAmount(r))+'</strong></div><div class="admin-mfs-kv"><label>Service</label><strong>'+esc(r.service_type||'-')+'</strong></div><div class="admin-mfs-kv"><label>Phone</label><strong>'+esc(r.user_phone||'-')+'</strong></div><div class="admin-mfs-kv"><label>Time</label><strong>'+esc(ts(r.created_at||r.updated_at))+'</strong></div></div>'+actionButtons(r)+'</div>';
    }).join('');
  }

  async function loadDoneSummary(){
    var page=1;
    var total=0;
    var rows=[];
    var hasMore=false;
    do{
      var data=await get('mfs_done',{page:page,limit:500});
      if(page===1)total=totalRows(data);
      rows=rows.concat(normalizeRows(data));
      hasMore=Boolean(data.pagination&&data.pagination.has_more);
      page++;
    }while(hasMore&&page<=20);
    return {total:total,rows:rows};
  }

  async function loadSummary(){
    try{
      var data=await Promise.all([
        get('mfs_pending',{page:1,limit:1}),
        get('mfs_processing',{page:1,limit:1}),
        loadDoneSummary()
      ]);
      var failed=data[2].rows.filter(function(r){ return String(r.status||'').toUpperCase()==='FAILED'; }).length;
      el('mfsSummaryPending').textContent=String(totalRows(data[0]));
      el('mfsSummaryProcessing').textContent=String(totalRows(data[1]));
      el('mfsSummaryDone').textContent=String(data[2].total);
      el('mfsSummaryFailed').textContent=String(failed);
    }catch(e){
      ['mfsSummaryPending','mfsSummaryProcessing','mfsSummaryDone','mfsSummaryFailed'].forEach(function(id){ if(el(id))el(id).textContent='-'; });
    }
  }

  async function load(){
    msg('Loading '+state.tab+' MFS requests...');
    try{
      var data=await get(actionForTab(),{page:1,limit:100,service:el('mfsService').value||'',uid:el('mfsUid').value||'',number:el('mfsNumber').value||''});
      state.rows=normalizeRows(data);
      render();
      msg('Loaded '+state.rows.length+' request(s).','good');
    }catch(e){
      msg(e.message||'Failed to load MFS requests','bad');
    }
    loadSummary();
  }

  async function ensureCsrf(){ if(state.csrf)return; try{ var data=await get('me',{}); state.csrf=data.csrf||''; }catch(e){} }
  async function createRequest(e){ e.preventDefault(); await ensureCsrf(); var amount=Number(el('mfsCreateAmountBdt').value||0); if(!Number.isFinite(amount)||amount<500||amount>50000){ msg('Amount must be between BDT 500 and BDT 50000.','bad'); return; } var body={uid:String(el('mfsCreateUid').value||'').trim(),provider:el('mfsCreateProvider').value,service_type:'SEND_MONEY',account_type:'PERSONAL',receiver_number:String(el('mfsCreateReceiver').value||'').trim(),amount_bdt:amount,currency:'BDT',reference:String(el('mfsCreateReference').value||'').trim(),note:String(el('mfsCreateNote').value||'').trim()}; try{ var data=await post('mfs_create',body); msg('Created MFS request: '+String(data.request_id||''),'good'); el('mfsCreateForm').reset(); state.tab='pending'; document.querySelectorAll('.admin-mfs-tab').forEach(function(btn){btn.classList.toggle('active',btn.getAttribute('data-mfs-tab')==='pending');}); await load(); }catch(err){ msg(err.message||'Failed to create MFS request','bad'); } }
  function findRow(id){ return state.rows.find(function(r){return String(r.request_id||'')===String(id);})||{}; }
  async function viewRow(id){ var row=findRow(id); try{ var data=await get('mfs_get',{request_id:id}); row=data.item||data.row||data.request||data||row; }catch(e){} alert(JSON.stringify(row,null,2)); }
  async function markProcessing(id){ await ensureCsrf(); if(!confirm('Mark processing?\n'+id))return; try{ await post('mfs_mark_processing',{request_id:id,message:'MFS request is processing'}); msg('Marked processing: '+id,'good'); await load(); }catch(e){ msg(e.message||'Failed to mark processing','bad'); } }
  async function markFailed(id){ await ensureCsrf(); var reason=prompt('Failure message','MFS request failed'); if(reason===null)return; try{ await post('mfs_failed',{request_id:id,message:reason||'MFS request failed'}); msg('Marked failed: '+id,'good'); await load(); }catch(e){ msg(e.message||'Failed to mark failed','bad'); } }
  async function markSuccess(id){ await ensureCsrf(); state.successRequestId=String(id||''); el('mfsSuccessSenderDetails').value=''; el('mfsSuccessTrxid').value=''; el('mfsSuccessMessage').value=''; el('mfsSuccessModal').classList.remove('hidden'); el('mfsSuccessSenderDetails').focus(); }
  function closeSuccess(){ state.successRequestId=''; el('mfsSuccessModal').classList.add('hidden'); }
  async function submitSuccess(){ var id=state.successRequestId; var details=String(el('mfsSuccessSenderDetails').value||'').trim(); if(!id)return; if(!details){ alert('Sender details required. Multiple numbers, amounts and text are allowed.'); return; } var trxid=String(el('mfsSuccessTrxid').value||'').trim(); var message=String(el('mfsSuccessMessage').value||'').trim()||'Transaction successful. Sender details: '+details; try{ await post('mfs_success',{request_id:id,trxid:trxid,sender_details:details,message:message}); closeSuccess(); msg('Marked successful: '+id,'good'); await load(); }catch(e){ msg(e.message||'Failed to mark success','bad'); } }
  function onAction(e){ var btn=e.target.closest('[data-act]'); if(!btn)return; var id=btn.getAttribute('data-id')||''; var act=btn.getAttribute('data-act')||''; if(act==='view')viewRow(id); if(act==='processing')markProcessing(id); if(act==='failed')markFailed(id); if(act==='success')markSuccess(id); }
  function toggleSidebar(open){ document.body.classList.toggle('sidebar-open',Boolean(open)); el('mfsSidebarToggle').setAttribute('aria-expanded',open?'true':'false'); }

  function bind(){
    document.querySelectorAll('.admin-mfs-tab').forEach(function(btn){btn.addEventListener('click',function(){document.querySelectorAll('.admin-mfs-tab').forEach(function(b){b.classList.remove('active');}); btn.classList.add('active'); state.tab=btn.getAttribute('data-mfs-tab')||'pending'; load();});});
    el('mfsCreateForm').addEventListener('submit',createRequest);
    el('mfsReloadBtn').addEventListener('click',load);
    el('mfsApplyFilterBtn').addEventListener('click',load);
    el('mfsSearch').addEventListener('input',render);
    el('mfsTableBody').addEventListener('click',onAction);
    el('mfsMobileList').addEventListener('click',onAction);
    el('mfsSuccessCancelBtn').addEventListener('click',closeSuccess);
    el('mfsSuccessSaveBtn').addEventListener('click',submitSuccess);
    el('mfsSuccessModal').addEventListener('click',function(e){if(e.target===el('mfsSuccessModal'))closeSuccess();});
    el('mfsSidebarToggle').addEventListener('click',function(){toggleSidebar(!document.body.classList.contains('sidebar-open'));});
    el('mfsSidebarBackdrop').addEventListener('click',function(){toggleSidebar(false);});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeSuccess();toggleSidebar(false);}});
  }

  bind();
  ensureCsrf().finally(load);
})();
