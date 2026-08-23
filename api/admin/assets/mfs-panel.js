// Z-Pay Swift Admin MFS panel
(function(){
  'use strict';

  var state = {
    tab:'pending',
    section:'manage',
    rows:[],
    csrf:'',
    loading:false,
    mutating:false,
    busyCount:0,
    successRequestId:'',
    confirmAction:null,
    createReview:null,
    feedbackAction:null,
    feedbackReceiptUrl:'',
    sessionRedirecting:false,
    settings:null,
    rate:null,
    rateLoaded:false,
    feesLoaded:false,
    rateLoading:false,
    feesLoading:false,
    rateMutating:false,
    feesMutating:false,
    tierFeesMutating:false,
    viewReceiptUrl:'',
    pages:{
      pending:{page:1,cursor:'',next_cursor:'',has_more:false,history:['']},
      processing:{page:1,cursor:'',next_cursor:'',has_more:false,history:['']},
      done:{page:1,cursor:'',next_cursor:'',has_more:false,history:['']},
      failed:{page:1,cursor:'',next_cursor:'',has_more:false,history:['']}
    }
  };
  var mfsSearchTimer=null;

  function el(id){ return document.getElementById(id); }
  function all(selector){ return Array.prototype.slice.call(document.querySelectorAll(selector)); }
  function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g,function(s){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];}); }
  function money(v){ var n=Number(v||0); return Number.isFinite(n)?n.toFixed(2):'0.00'; }
  function ts(v){ var n=Number(v||0); if(!n)return '-'; var d=new Date(String(Math.trunc(n)).length<=10?n*1000:n); return isNaN(d.getTime())?'-':d.toLocaleString(); }
  function msg(text,type){ var box=el('mfsPageMsg'); if(!box)return; box.className='mfs-msg '+(type||''); box.textContent=String(text||''); }
  function statusPill(v){
    var status=String(v||'-').toUpperCase();
    var cls='info';
    var label=status;
    if(['SUCCESS','SUCCESSFUL','DONE','COMPLETED'].indexOf(status)>=0){cls='success';label='Successful';}
    else if(['FAILED','CANCELLED','REJECTED','REFUNDED'].indexOf(status)>=0){cls='danger';label='Failed';}
    else if(['PROCESSING','CLAIMED','DIALING'].indexOf(status)>=0){cls='processing';label='Processing';}
    else if(['PENDING','WAITING_ADMIN','WAITING_APPROVAL','ADMIN_PENDING'].indexOf(status)>=0){cls='warning';label='Pending';}
    return '<span class="pill mfs-status-pill '+cls+' status-'+esc(status.toLowerCase())+'">'+esc(label)+'</span>';
  }
  function normalizeRows(data){ return Array.isArray(data.items)?data.items:Array.isArray(data.rows)?data.rows:Array.isArray(data.requests)?data.requests:[]; }
  function currentPage(){ return state.pages[state.tab]||state.pages.pending; }
  function resetAllPages(){ Object.keys(state.pages).forEach(function(tab){var p=state.pages[tab];p.page=1;p.cursor='';p.next_cursor='';p.has_more=false;p.history=[''];}); }
  function applyPage(data){
    var p=currentPage();var incoming=data&&data.pagination?data.pagination:{};
    p.page=Math.max(1,Number(incoming.page||p.page||1));
    p.cursor=String(incoming.cursor===undefined?p.cursor:incoming.cursor||'');
    p.next_cursor=String(incoming.next_cursor||'');
    p.has_more=!!incoming.has_more;
    if(!Array.isArray(p.history)||!p.history.length)p.history=[''];
  }
  function renderPagination(){
    var p=currentPage();
    el('mfsPaginationText').textContent='Page '+p.page+' • '+state.rows.length+' requests';
    el('mfsPrevBtn').disabled=state.loading||p.page<=1;
    el('mfsNextBtn').disabled=state.loading||!p.has_more||!p.next_cursor;
  }
  function rowNumber(r){ return r.receiver_number||r.number||r.mfs_number||r.to_number||'-'; }
  function isRemittance(r){ return String(r.service_mode||'').toUpperCase()==='REMITTANCE'||String(r.country_code||r.country||'').toUpperCase()==='MY'||Number(r.amount_rm||r.amount_myr||0)>0; }
  function rmAmount(r){
    var rm=Number(r.amount_rm||r.amount_myr||0);
    var rate=Number(r.exchange_rate||r.rate_myr_to_bdt||0);
    if(rm<=0&&rate>0&&Number(r.amount_bdt||0)>0)rm=Number(r.amount_bdt||0)/rate;
    return rm;
  }
  function rmFee(r){
    var fee=Number(r.fee_rm||r.fee_myr||0);
    var rate=Number(r.exchange_rate||r.rate_myr_to_bdt||0);
    if(fee<=0&&String(r.fee_currency||'').toUpperCase()==='MYR')fee=Number(r.fee_amount||0);
    if(fee<=0&&rate>0&&Number(r.fee_bdt||0)>0)fee=Number(r.fee_bdt||0)/rate;
    return fee;
  }
  function rmTotal(r){
    var total=Number(r.total_debit_rm||r.total_pay_myr||0);
    var rate=Number(r.exchange_rate||r.rate_myr_to_bdt||0);
    if(total<=0)total=rmAmount(r)+rmFee(r);
    if(total<=0&&String(r.wallet_currency||'').toUpperCase()==='MYR')total=Number(r.total_debit||r.total_pay||0);
    if(total<=0&&rate>0&&Number(r.total_debit||r.total_pay||0)>0)total=Number(r.total_debit||r.total_pay||0)/rate;
    return total;
  }
  function bdtTotal(r){
    var total=Number(r.total_debit_bdt||r.total_pay_bdt||0);
    if(total<=0)total=Number(r.total_debit||r.total_pay||0);
    if(total<=0)total=Number(r.amount_bdt||r.amount||0)+Number(r.fee_bdt||0);
    return total;
  }
  function rowAmount(r){
    if(isRemittance(r)){
      return 'Received: BDT '+money(r.amount_bdt||0)+' | Send: RM '+money(rmAmount(r))+' | Fee: RM '+money(rmFee(r))+' | Total Paid: RM '+money(rmTotal(r));
    }
    return 'Amount: BDT '+money(r.amount_bdt||r.amount||0)+' | Fee: BDT '+money(r.fee_bdt||0)+' | Total Paid: BDT '+money(bdtTotal(r));
  }
  function canonicalWalletDebitText(r){
    var amount=r.wallet_debit_amount;
    if(amount===undefined||amount===null||amount==='')amount=r.debit_amount;
    if(amount===undefined||amount===null||amount==='')amount=r.wallet_debit;
    if(amount===undefined||amount===null||amount==='')return '';
    var currency=normalizeCurrency(r.wallet_debit_currency||r.wallet_currency||r.currency);
    return (currency==='MYR'?'RM ':'BDT ')+money(amount);
  }
  function canonicalRateText(r){
    var value=r.exchange_rate;
    if(value===undefined||value===null||value==='')value=r.rate_myr_to_bdt;
    if(value===undefined||value===null||value==='')return '';
    return '1 RM = BDT '+money(value);
  }
  function canonicalFeeText(r){
    if(isRemittance(r))return 'RM '+money(rmFee(r));
    return 'BDT '+money(r.fee_bdt||r.fee_amount||0);
  }
  function rowAmountMarkup(r){
    var html='<span class="admin-mfs-amount-primary">BDT '+money(r.amount_bdt||r.amount||0)+'</span>';
    if(isRemittance(r))html+='<small>Send: RM '+money(rmAmount(r))+'</small><small>Fee: RM '+money(rmFee(r))+'</small><small>Total: RM '+money(rmTotal(r))+'</small>';
    else html+='<small>Fee: BDT '+money(r.fee_bdt||0)+'</small><small>Total: BDT '+money(bdtTotal(r))+'</small>';
    var walletDebit=canonicalWalletDebitText(r);
    if(walletDebit)html+='<small>Wallet: '+esc(walletDebit)+'</small>';
    return '<span class="admin-mfs-amount-stack">'+html+'</span>';
  }
  function detailItem(label,value,wide){
    if(value===undefined||value===null||String(value).trim()==='')return '';
    return '<div class="admin-mfs-detail-item'+(wide?' admin-mfs-detail-wide':'')+'"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>';
  }
  function mfsViewDetailsHtml(row){
    var walletDebit=canonicalWalletDebitText(row);
    var rate=canonicalRateText(row);
    var html='<section class="admin-mfs-detail-section"><h3>Request</h3><div class="admin-mfs-detail-grid">';
    html+=detailItem('Status',String(row.status||'-').replace(/_/g,' '));
    html+=detailItem('Request ID',row.request_id||'-',true);
    html+=detailItem('User / UID',row.user_name||row.uid||'-');
    html+=detailItem('User Phone',row.user_phone||row.phone||'-');
    html+=detailItem('Provider',row.provider_name||row.provider||'-');
    html+=detailItem('Service',row.service_type||row.service_mode||'-');
    html+=detailItem('Receiver',rowNumber(row));
    html+='</div></section><section class="admin-mfs-detail-section"><h3>Financial Display</h3><div class="admin-mfs-detail-grid">';
    html+=detailItem('Amount BDT','BDT '+money(row.amount_bdt||row.amount||0));
    if(isRemittance(row))html+=detailItem('Amount RM','RM '+money(rmAmount(row)));
    html+=detailItem('Fee',canonicalFeeText(row));
    if(isRemittance(row))html+=detailItem('Total Paid','RM '+money(rmTotal(row)));
    else html+=detailItem('Total Paid','BDT '+money(bdtTotal(row)));
    html+=detailItem('Wallet Debit',walletDebit);
    html+=detailItem('Rate',rate);
    html+='</div></section><section class="admin-mfs-detail-section"><h3>Processing</h3><div class="admin-mfs-detail-grid">';
    html+=detailItem('Reference',row.reference||row.reference_no||row.customer_reference,true);
    html+=detailItem('TRX ID',row.trx_id||row.trxid||row.transaction_id,true);
    html+=detailItem('Sender Number',row.sender_number||row.sender_phone);
    html+=detailItem('Sender Name',row.sender_name);
    html+=detailItem('Created',ts(row.created_at));
    html+=detailItem('Updated',ts(row.updated_at));
    html+=detailItem('Message',row.message||row.failure_reason||row.status_message,true);
    html+='</div></section>';
    return html;
  }
  function num(id){ var n=Number(el(id)&&el(id).value||0); return Number.isFinite(n)?n:0; }
  function numDefault(id,def){ var node=el(id); if(!node)return def; var raw=String(node.value||'').trim(); if(raw==='')return def; var n=Number(raw); return Number.isFinite(n)?n:def; }
  function fee(settings,country,provider){ return (((settings||{}).fees||{})[country]||{})[provider]||{}; }
  function setVal(id,value){ var node=el(id); if(node)node.value=money(value); }
  function roleFee(row,role,def){
    row=row||{};
    var value=row[role];
    if(value&&typeof value==='object')value=value.fee_rm||value.fixed||value.amount||value.rm;
    if((value===undefined||value===null||value==='')&&role==='USER')value=row.fee_rm||row.fixed||row.amount;
    var n=Number(value);
    return Number.isFinite(n)?n:def;
  }
  function tierRoleFee(tiers,tier,role,def){
    return roleFee((tiers||{})[tier]||{},role,def);
  }

  function countryName(value){
    var country=String(value||'').toUpperCase();
    if(country==='MY')return 'Malaysia';
    if(country==='BD')return 'Bangladesh';
    return value||'-';
  }

  function modeName(value){
    var mode=String(value||'').toUpperCase();
    if(mode==='REMITTANCE')return 'Remittance';
    if(mode==='LOCAL')return 'Local';
    return value||'-';
  }

  function normalizeCurrency(value){
    var currency=String(value||'').toUpperCase().trim();
    if(['MYR','RM','MY'].indexOf(currency)>=0)return 'MYR';
    if(['BDT','BD','TK'].indexOf(currency)>=0)return 'BDT';
    return '';
  }

  function currencyPrefix(currency){
    return normalizeCurrency(currency)==='MYR'?'RM':'BDT';
  }

  function reviewCurrency(data,remittance){
    var candidates=[
      data&&data.display_currency,
      data&&data.wallet_currency,
      data&&data.currency,
      data&&data.wallet&&data.wallet.display_currency,
      data&&data.wallet&&data.wallet.wallet_currency,
      data&&data.wallet&&data.wallet.currency
    ];
    for(var i=0;i<candidates.length;i++){
      var normalized=normalizeCurrency(candidates[i]);
      if(normalized)return normalized;
    }
    var country=String(data&&data.country_code||data&&data.country||'').toUpperCase();
    if(country==='MY'||remittance)return 'MYR';
    return 'BDT';
  }

  function firstNumber(){
    for(var i=0;i<arguments.length;i++){
      var value=arguments[i];
      if(value===undefined||value===null||value==='')continue;
      var n=Number(value);
      if(Number.isFinite(n))return n;
    }
    return NaN;
  }

  function reviewAvailable(data,currency){
    if(currency==='MYR'){
      return firstNumber(
        String(data.display_currency||'').toUpperCase()==='MYR'?data.display_available_balance:undefined,
        data.available_balance_myr,
        data.wallet&&String(data.wallet.display_currency||'').toUpperCase()==='MYR'?data.wallet.display_available_balance:undefined,
        data.wallet&&data.wallet.available_balance_myr,
        data.available_balance,
        data.wallet_balance
      );
    }
    return firstNumber(
      String(data.display_currency||'').toUpperCase()==='BDT'?data.display_available_balance:undefined,
      data.available_balance_bdt,
      data.wallet&&String(data.wallet.display_currency||'').toUpperCase()==='BDT'?data.wallet.display_available_balance:undefined,
      data.wallet&&data.wallet.available_balance_bdt,
      data.available_balance,
      data.wallet_balance
    );
  }

  function reviewDebit(data,currency){
    if(currency==='MYR'){
      return firstNumber(
        String(data.display_currency||'').toUpperCase()==='MYR'?data.display_total_pay:undefined,
        data.total_pay_myr,
        data.total_debit_rm,
        String(data.wallet_currency||'').toUpperCase()==='MYR'?data.wallet_hold_amount:undefined,
        String(data.wallet_currency||'').toUpperCase()==='MYR'?data.total_pay:undefined,
        String(data.wallet_currency||'').toUpperCase()==='MYR'?data.total_debit:undefined
      );
    }
    return firstNumber(
      String(data.display_currency||'').toUpperCase()==='BDT'?data.display_total_pay:undefined,
      data.total_pay_bdt,
      data.total_debit_bdt,
      String(data.wallet_currency||'').toUpperCase()==='BDT'?data.wallet_hold_amount:undefined,
      String(data.wallet_currency||'').toUpperCase()==='BDT'?data.total_pay:undefined,
      String(data.wallet_currency||'').toUpperCase()==='BDT'?data.total_debit:undefined
    );
  }

  async function readJson(res){
    var text=await res.text();
    var json={};
    try{json=JSON.parse(text);}catch(e){throw new Error('Invalid response from server');}
    if(!res.ok||!json.ok){
      var er=new Error(json.message||'Request failed');
      er.code=json.code||'ERROR';
      er.data=json.data||{};
      er.status=res.status;
      throw er;
    }
    return json.data||{};
  }

  async function get(action,params){
    var qs=new URLSearchParams(params||{}).toString();
    var proxyUrl=window.ADMIN_MFS_PROXY_URL||window.ADMIN_PROXY_URL||'/api/admin/proxy.php';
    var res=await fetch(proxyUrl+'?action='+encodeURIComponent(action)+(qs?'&'+qs:''),{
      credentials:'same-origin',
      headers:{Accept:'application/json','Cache-Control':'no-cache'}
    });
    return readJson(res);
  }

  async function post(action,body){
    var headers={'Content-Type':'application/json',Accept:'application/json','Cache-Control':'no-cache'};
    if(state.csrf)headers['X-CSRF-TOKEN']=state.csrf;
    var proxyUrl=window.ADMIN_MFS_PROXY_URL||window.ADMIN_PROXY_URL||'/api/admin/proxy.php';
    var res=await fetch(proxyUrl+'?action='+encodeURIComponent(action),{
      method:'POST',
      credentials:'same-origin',
      headers:headers,
      body:JSON.stringify(body||{})
    });
    return readJson(res);
  }

  function friendlyError(err){
    var code=String(err&&err.code||'').toUpperCase();
    var message=String(err&&err.message||'API or network error');
    if(code==='INSUFFICIENT_BALANCE'){
      var data=err.data||{};
      return 'Insufficient balance. Available: '+money(data.available_balance)+' '+String(data.currency||'')+'. Required: '+money(data.required_amount)+' '+String(data.currency||'')+'.';
    }
    if(code==='USER_NOT_FOUND'||code==='NOT_FOUND') return message||'Target user not found.';
    if(code==='VALIDATION_ERROR') return message||'Please check the form fields and try again.';
    return message;
  }

  function isSessionExpiredError(err){
    var code=String(err&&err.code||'').toUpperCase();
    var message=String(err&&err.message||'').toLowerCase();
    var status=Number(err&&err.status||0);
    return status===401
      || ['AUTH_ERROR','UNAUTHORIZED','FORBIDDEN','SESSION_EXPIRED','ADMIN_SESSION_EXPIRED'].indexOf(code)>=0
      || message.indexOf('session expired')>=0
      || message.indexOf('session not found')>=0
      || message.indexOf('not found or expired')>=0
      || message.indexOf('please login')>=0;
  }

  function redirectAdminLogin(){
    window.location.href=window.ADMIN_DASHBOARD_URL||'/admin/';
  }

  function handleSessionExpired(err){
    if(!isSessionExpiredError(err))return false;
    if(state.sessionRedirecting)return true;
    state.sessionRedirecting=true;
    showFeedback('error','Session expired','Session expired. Please login again.',redirectAdminLogin);
    window.setTimeout(redirectAdminLogin,1500);
    return true;
  }

  function showFeedback(type,title,message,onOk,options){
    options=options||{};
    state.feedbackAction=typeof onOk==='function'?onOk:null;
    state.feedbackReceiptUrl=String(options.link||'');
    var isError=type==='error';
    el('mfsFeedbackCard').classList.toggle('feedback-error',isError);
    el('mfsFeedbackKicker').textContent=isError?'Error':'Success';
    el('mfsFeedbackTitle').textContent=String(title||'Notice');
    el('mfsFeedbackMessage').textContent=String(message||'');
    var details=el('mfsFeedbackDetails');
    if(details){
      details.textContent=String(options.details||'');
      details.classList.toggle('hidden',!options.details);
    }
    var receiptActions=el('mfsFeedbackReceiptActions');
    var receiptOpen=el('mfsFeedbackReceiptOpen');
    if(receiptActions)receiptActions.classList.toggle('hidden',!state.feedbackReceiptUrl);
    if(receiptOpen)receiptOpen.href=state.feedbackReceiptUrl||'#';
    el('mfsFeedbackModal').classList.remove('hidden');
    el('mfsFeedbackOkBtn').focus();
  }

  function closeFeedback(){
    el('mfsFeedbackModal').classList.add('hidden');
    var action=state.feedbackAction;
    state.feedbackAction=null;
    state.feedbackReceiptUrl='';
    if(action)action();
  }

  async function copyFeedbackReceipt(){
    if(!state.feedbackReceiptUrl)return;
    try{
      await navigator.clipboard.writeText(state.feedbackReceiptUrl);
      el('mfsFeedbackMessage').textContent='Receipt / tracking link copied to clipboard.';
    }catch(err){
      el('mfsFeedbackMessage').textContent=state.feedbackReceiptUrl;
    }
  }

  function setButtonBusy(button,busy,label){
    if(!button)return;
    if(busy){
      if(!button.dataset.mfsOriginalText)button.dataset.mfsOriginalText=button.textContent;
      button.disabled=true;
      button.textContent=label||'Loading...';
    }else{
      if(button.dataset.mfsOriginalText)button.textContent=button.dataset.mfsOriginalText;
      button.disabled=false;
    }
  }

  function setPageBusy(busy,label){
    state.busyCount=Math.max(0,state.busyCount+(busy?1:-1));
    var active=state.busyCount>0;
    document.body.classList.toggle('mfs-busy',active);
    el('mfsPageLoader').classList.toggle('hidden',!active);
    el('mfsPageLoaderText').textContent=String(label||'Loading MFS requests...');
  }

  function setLoadControlsBusy(busy){
    all('.admin-mfs-tab').forEach(function(button){button.disabled=busy;});
    el('mfsReloadBtn').disabled=busy;
    el('mfsApplyFilterBtn').disabled=busy;
    el('mfsPrevBtn').disabled=busy||currentPage().page<=1;
    el('mfsNextBtn').disabled=busy||!currentPage().has_more||!currentPage().next_cursor;
  }

  async function ensureCsrf(){
    if(state.csrf)return;
    var data=await get('me',{});
    state.csrf=String(data.csrf||'');
    if(!state.csrf)throw new Error('Admin security token is missing. Please refresh and login again.');
  }

  function actionForTab(){
    return state.tab==='processing'?'mfs_processing':state.tab==='done'||state.tab==='failed'?'mfs_done':'mfs_pending';
  }

  function listParams(){
    var page=currentPage();
    var params={
      page:page.page,
      cursor:page.cursor,
      limit:10,
      query:el('mfsSearch').value||'',
      service_type:el('mfsService').value||'',
      uid:el('mfsUid').value||'',
      number:el('mfsNumber').value||''
    };
    if(state.tab==='done')params.status='SUCCESSFUL';
    if(state.tab==='failed')params.status='FAILED';
    return params;
  }

  function filterRows(rows){
    return rows;
  }

  function actionButtons(r){
    var id=String(r.request_id||'');
    var status=String(r.status||'').toUpperCase();
    var html='<div class="admin-mfs-actions"><button class="mini-btn" type="button" data-act="view" data-id="'+esc(id)+'">View</button>';
    if(r.receipt_url)html+='<button class="mini-btn success" type="button" data-act="receipt" data-url="'+esc(r.receipt_url)+'">Receipt</button>';
    if(status!=='PROCESSING'&&status!=='SUCCESSFUL'&&status!=='SUCCESS'&&status!=='FAILED')html+='<button class="mini-btn blue" type="button" data-act="processing" data-id="'+esc(id)+'">Processing</button>';
    if(status!=='SUCCESSFUL'&&status!=='SUCCESS'&&status!=='FAILED')html+='<button class="mini-btn success" type="button" data-act="success" data-id="'+esc(id)+'">Success</button><button class="mini-btn danger" type="button" data-act="failed" data-id="'+esc(id)+'">Failed</button>';
    return html+'</div>';
  }

  function render(){
    var rows=filterRows(state.rows);
    var body=el('mfsTableBody');
    var mobile=el('mfsMobileList');
    renderPagination();
    if(!rows.length){
      body.innerHTML='<tr><td colspan="8" class="empty">No MFS requests found.</td></tr>';
      mobile.innerHTML='<div class="empty">No MFS requests found.</div>';
      return;
    }
    body.innerHTML=rows.map(function(r){
      return '<tr class="admin-mfs-request-row"><td data-label="Request"><b class="admin-mfs-request-id">'+esc(r.request_id||'-')+'</b><small>'+esc(r.request_source||r.source||'-')+'</small></td><td data-label="User"><span class="admin-mfs-user-cell"><b>'+esc(r.user_name||r.uid||'-')+'</b><small>'+esc(r.uid||'-')+'</small><small>'+esc(r.user_phone||'-')+'</small></span></td><td data-label="Provider"><span class="admin-mfs-provider-cell"><b>'+esc(r.provider_name||r.provider||'-')+'</b><small>'+esc(r.service_type||r.service_mode||'-')+'</small></span></td><td data-label="Receiver"><code>'+esc(rowNumber(r))+'</code></td><td data-label="Amount">'+rowAmountMarkup(r)+'</td><td data-label="Status">'+statusPill(r.status||'-')+'</td><td data-label="Created"><time>'+esc(ts(r.created_at||r.updated_at))+'</time></td><td data-label="Actions">'+actionButtons(r)+'</td></tr>';
    }).join('');
    mobile.innerHTML=rows.map(function(r){
      var walletDebit=canonicalWalletDebitText(r);
      var reference=r.reference||r.reference_no||r.trx_id||r.trxid||'';
      return '<article class="admin-mfs-card"><div class="admin-mfs-card-top"><div><p class="admin-mfs-card-kicker">'+esc(r.service_type||r.service_mode||'MFS Request')+'</p><h3 class="admin-mfs-card-title">'+esc(r.provider_name||r.provider||'MFS')+' &bull; '+esc(rowNumber(r))+'</h3><p class="admin-mfs-card-sub">'+esc(r.request_id||'-')+'</p></div>'+statusPill(r.status||'-')+'</div><div class="admin-mfs-card-grid"><div class="admin-mfs-kv"><label>User / UID</label><strong>'+esc(r.user_name||r.uid||'-')+'</strong><small>'+esc(r.uid||'')+'</small></div><div class="admin-mfs-kv"><label>Phone</label><strong>'+esc(r.user_phone||'-')+'</strong></div><div class="admin-mfs-kv admin-mfs-kv-wide"><label>Amount</label>'+rowAmountMarkup(r)+'</div><div class="admin-mfs-kv"><label>Wallet Debit</label><strong>'+esc(walletDebit||'-')+'</strong></div><div class="admin-mfs-kv"><label>Created</label><strong>'+esc(ts(r.created_at||r.updated_at))+'</strong></div>'+(reference?'<div class="admin-mfs-kv admin-mfs-kv-wide"><label>Reference</label><strong>'+esc(reference)+'</strong></div>':'')+'</div>'+actionButtons(r)+'</article>';
    }).join('');
  }

  function renderListLoading(){
    el('mfsTableBody').innerHTML='<tr><td colspan="8" class="empty">Loading '+esc(state.tab)+' requests...</td></tr>';
    el('mfsMobileList').innerHTML='<div class="empty">Loading '+esc(state.tab)+' requests...</div>';
  }

  async function loadList(){
    renderListLoading();
    try{
      var data=await get(actionForTab(),listParams());
      state.rows=normalizeRows(data);
      applyPage(data);
      render();
      msg('Loaded '+state.rows.length+' '+state.tab+' request(s).','good');
    }catch(err){
      state.rows=[];
      render();
      throw err;
    }
  }

  async function loadSummary(){
    ['mfsSummaryPending','mfsSummaryProcessing','mfsSummaryDone','mfsSummaryFailed'].forEach(function(id){el(id).textContent='...';});
    try{
      var data=await get('mfs_status_counts',{});
      var counts=data&&data.counts?data.counts:{};
      el('mfsSummaryPending').textContent=String(Number(counts.pending||0));
      el('mfsSummaryProcessing').textContent=String(Number(counts.processing||0));
      el('mfsSummaryDone').textContent=String(Number(counts.done||0));
      el('mfsSummaryFailed').textContent=String(Number(counts.failed||0));
    }catch(err){
      ['mfsSummaryPending','mfsSummaryProcessing','mfsSummaryDone','mfsSummaryFailed'].forEach(function(id){el(id).textContent='-';});
      throw err;
    }
  }

  async function load(button,notifyError){
    if(state.loading)return;
    state.loading=true;
    setButtonBusy(button,true,'Loading...');
    setLoadControlsBusy(true);
    setPageBusy(true,'Loading MFS summary and '+state.tab+' requests...');
    try{
      var results=await Promise.allSettled([loadList(),loadSummary()]);
      var failed=results.find(function(result){return result.status==='rejected';});
      if(failed){
        var error=failed.reason||new Error('Failed to load MFS requests');
        msg(friendlyError(error),'bad');
        if(handleSessionExpired(error))return;
        if(notifyError!==false)showFeedback('error','Unable to load requests',friendlyError(error));
      }
    }finally{
      setPageBusy(false);
      setLoadControlsBusy(false);
      setButtonBusy(button,false);
      state.loading=false;
    }
  }

  function setActiveTab(tab){
    state.tab=String(tab||'pending');
    all('.admin-mfs-tab').forEach(function(button){
      button.classList.toggle('active',button.getAttribute('data-mfs-tab')===state.tab);
    });
    renderPagination();
  }

  function setSection(section){
    state.section=section==='create'?'create':(section==='settings'?'settings':'manage');
    all('[data-mfs-view]').forEach(function(view){
      view.classList.toggle('hidden',view.getAttribute('data-mfs-view')!==state.section);
    });
    all('[data-mfs-view-target]').forEach(function(button){
      button.classList.toggle('active',button.getAttribute('data-mfs-view-target')===state.section);
    });
    if(state.section==='settings'){
      if(!state.rateLoaded)loadRate(null,false);
      if(!state.feesLoaded)loadFees(null,false);
    }
    toggleSidebar(false);
  }

  function populateRate(rateState){
    rateState=rateState||{};
    var rate=Number(rateState.rate_myr_bdt||rateState.rate||0);
    if(!Number.isFinite(rate)||rate<=0)return;
    state.rate={
      rate_myr_bdt:rate,
      updated_at:Number(rateState.updated_at||0),
      updated_source:String(rateState.updated_source||'').trim()
    };
    state.rateLoaded=true;
    state.settings=state.settings||{};
    state.settings.rate_myr_bdt=rate;
    el('mfsRateMyrBdt').value=money(rate);
    el('mfsLiveRateValue').textContent='RM 1 = BDT '+money(rate);
    var updated=state.rate.updated_at>0?'Last updated '+ts(state.rate.updated_at):'Last update unavailable';
    if(state.rate.updated_source)updated+=' • '+state.rate.updated_source.replace(/_/g,' ');
    el('mfsRateUpdatedAt').textContent=updated;
    updateCreatePreview();
  }

  function populateFees(settings){
    settings=settings||{};
    var fees=settings.fees||settings||{};
    state.settings=state.settings||{};
    state.settings.fees=fees;
    state.feesLoaded=true;
    var bd=fees.BD||{};
    var my=fees.MY||{};
    var tiers=my.TIERS||my.tiers||{};
    [
      ['Tier1','TIER1',{USER:5,RETAILER:2,SUBADMIN:2}],
      ['Tier2','TIER2',{USER:7,RETAILER:3,SUBADMIN:3}],
      ['Tier3','TIER3',{USER:10,RETAILER:4,SUBADMIN:4}]
    ].forEach(function(entry){
      ['USER','RETAILER','SUBADMIN'].forEach(function(role){
        var suffix=role==='USER'?'User':role==='RETAILER'?'Retailer':'Subadmin';
        setVal('mfsMy'+entry[0]+suffix+'Fee',tierRoleFee(tiers,entry[1],role,entry[2][role]));
      });
    });
    [['Bkash','BKASH'],['Nagad','NAGAD']].forEach(function(pair){
      var suffix=pair[0], provider=pair[1], row=bd[provider]||{};
      el('mfsBd'+suffix+'Type').value=String(row.type||'fixed').toLowerCase()==='percent'?'percent':'fixed';
      el('mfsBd'+suffix+'Fixed').value=money(row.fixed||0);
      el('mfsBd'+suffix+'Percent').value=money(row.percent||0);
      el('mfsBd'+suffix+'Min').value=money(row.min_fee||0);
      el('mfsBd'+suffix+'Max').value=money(row.max_fee||0);
    });
    updateCreatePreview();
  }

  async function loadRate(button,notify){
    if(state.rateLoading)return;
    state.rateLoading=true;
    setButtonBusy(button,true,'Loading...');
    try{
      var data=await get('mfs_rate_get',{});
      populateRate(data.rate||data);
      if(notify)showFeedback('success','Rate loaded',"Today's live rate was reloaded.");
    }catch(err){
      if(handleSessionExpired(err))return;
      if(notify!==false)showFeedback('error','Unable to load rate',friendlyError(err));
    }finally{
      setButtonBusy(button,false);
      state.rateLoading=false;
    }
  }

  async function loadFees(button,notify){
    if(state.feesLoading)return;
    state.feesLoading=true;
    setButtonBusy(button,true,'Loading...');
    try{
      var data=await get('mfs_fees_get',{});
      populateFees(data.settings||{fees:data.fees||{}});
      if(notify)showFeedback('success','Fees loaded','MFS fee settings were reloaded.');
    }catch(err){
      if(handleSessionExpired(err))return;
      if(notify!==false)showFeedback('error','Unable to load fees',friendlyError(err));
    }finally{
      setButtonBusy(button,false);
      state.feesLoading=false;
    }
  }

  function feesPayload(){
    return {
      fees:{
        BD:{
          BKASH:{type:el('mfsBdBkashType').value,fixed:num('mfsBdBkashFixed'),percent:num('mfsBdBkashPercent'),min_fee:num('mfsBdBkashMin'),max_fee:num('mfsBdBkashMax')},
          NAGAD:{type:el('mfsBdNagadType').value,fixed:num('mfsBdNagadFixed'),percent:num('mfsBdNagadPercent'),min_fee:num('mfsBdNagadMin'),max_fee:num('mfsBdNagadMax')}
        }
      }
    };
  }

  function tierFeesPayload(){
    return {fees:{MY:{TIERS:{
      TIER1:{USER:numDefault('mfsMyTier1UserFee',5),RETAILER:numDefault('mfsMyTier1RetailerFee',2),SUBADMIN:numDefault('mfsMyTier1SubadminFee',2)},
      TIER2:{USER:numDefault('mfsMyTier2UserFee',7),RETAILER:numDefault('mfsMyTier2RetailerFee',3),SUBADMIN:numDefault('mfsMyTier2SubadminFee',3)},
      TIER3:{USER:numDefault('mfsMyTier3UserFee',10),RETAILER:numDefault('mfsMyTier3RetailerFee',4),SUBADMIN:numDefault('mfsMyTier3SubadminFee',4)}
    }}}};
  }

  async function saveRate(e){
    e.preventDefault();
    if(state.rateMutating)return;
    var button=el('mfsRateSaveBtn');
    state.rateMutating=true;
    setButtonBusy(button,true,'Updating...');
    try{
      await ensureCsrf();
      var data=await post('mfs_rate_save',{rate_myr_bdt:num('mfsRateMyrBdt')});
      populateRate(data.rate||{});
      showFeedback('success','Rate updated',"Today's live MYR/BDT rate was updated.");
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to update rate',friendlyError(err));
    }finally{
      setButtonBusy(button,false);
      state.rateMutating=false;
    }
  }

  async function saveFeeSettings(e){
    e.preventDefault();
    if(state.feesMutating)return;
    var button=el('mfsSettingsSaveBtn');
    state.feesMutating=true;
    setButtonBusy(button,true,'Saving...');
    try{
      await ensureCsrf();
      var data=await post('mfs_fees_save',feesPayload());
      populateFees(data.settings||{fees:data.fees||{}});
      showFeedback('success','Fees saved','MFS fee settings were saved.');
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to save fees',friendlyError(err));
    }finally{
      setButtonBusy(button,false);
      state.feesMutating=false;
    }
  }

  async function saveTierFees(e){
    e.preventDefault();
    if(state.tierFeesMutating)return;
    var button=el('mfsTierFeesSaveBtn');
    state.tierFeesMutating=true;
    setButtonBusy(button,true,'Saving...');
    try{
      await ensureCsrf();
      var data=await post('mfs_my_fee_tiers_save',tierFeesPayload());
      populateFees({fees:data.fees||{MY:{TIERS:data.tiers||{}}}});
      showFeedback('success','Fee tiers saved','Malaysia remittance fee tiers were saved.');
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to save fee tiers',friendlyError(err));
    }finally{
      setButtonBusy(button,false);
      state.tierFeesMutating=false;
    }
  }

  function bdFeeFor(provider,amount){
    var row=fee(state.settings,'BD',provider);
    var fixed=Number(row.fixed||0);
    var percent=Number(row.percent||0);
    var minFee=Number(row.min_fee||0);
    var maxFee=Number(row.max_fee||0);
    var value=fixed+(amount*percent/100);
    if(minFee>0&&value<minFee)value=minFee;
    if(maxFee>0&&value>maxFee)value=maxFee;
    return value;
  }

  function updateCreatePreview(){
    var box=el('mfsCreatePreview');
    if(!box)return;
    var settings=state.settings||{};
    var rate=Number(settings.rate_myr_bdt||31);
    var provider=String(el('mfsCreateProvider')&&el('mfsCreateProvider').value||'BKASH').toUpperCase();
    var amountBdt=num('mfsCreateAmountBdt');
    var amountRm=num('mfsCreateAmountRm');
    if(amountBdt<=0&&amountRm>0)amountBdt=amountRm*rate;
    if(amountRm<=0&&amountBdt>0)amountRm=amountBdt/rate;
    var bdFee=bdFeeFor(provider,amountBdt);
    box.textContent=[
      'Backend will use the target account country/currency for the final hold.',
      'BD target: LOCAL, Amount BDT '+money(amountBdt)+', Fee BDT '+money(bdFee)+', Total Paid BDT '+money(amountBdt+bdFee),
      'MY target: REMITTANCE, Rate RM 1 = BDT '+money(rate)+'. The backend will resolve the target account role and amount tier.',
      'Create Request will open a review modal before any wallet hold.'
    ].join('\n');
  }

  function createRequestPayload(){
    var amount=Number(el('mfsCreateAmountBdt').value||0);
    var amountRm=Number(el('mfsCreateAmountRm').value||0);
    return {
      uid:String(el('mfsCreateUid').value||'').trim(),
      provider:el('mfsCreateProvider').value,
      service_type:'SEND_MONEY',
      account_type:'PERSONAL',
      receiver_number:String(el('mfsCreateReceiver').value||'').trim(),
      amount_bdt:amount,
      amount_rm:amountRm,
      amount_myr:amountRm,
      reference:String(el('mfsCreateReference').value||'').trim(),
      note:String(el('mfsCreateNote').value||'').trim()
    };
  }

  function reviewMoney(data,remittance){
    var currency=String(data.wallet_currency||'BDT').toUpperCase();
    if(remittance)return 'RM '+money(data.total_pay_myr||data.total_debit_rm||((Number(data.amount_rm||data.amount_myr||0))+(Number(data.fee_rm||data.fee_myr||0))));
    if(currency==='MYR')return 'RM '+money(data.total_pay_myr||data.total_debit_rm||data.total_pay||0);
    return 'BDT '+money(data.total_pay_bdt||data.total_debit_bdt||data.total_pay||0);
  }

  function reviewFeeText(data,remittance){
    var currency=String(data.wallet_currency||'BDT').toUpperCase();
    if(remittance)return 'RM '+money(data.fee_rm||data.fee_myr||0);
    return String(data.fee_currency||currency)+' '+money(data.fee_amount||0);
  }

  function formatCreateReview(data,body){
    var remittance=String(data.service_mode||'').toUpperCase()==='REMITTANCE'||String(data.country_code||'').toUpperCase()==='MY'||Number(data.amount_rm||data.amount_myr||0)>0;
    var currency=reviewCurrency(data,remittance);
    var rate=Number(data.exchange_rate||data.rate_myr_to_bdt||0);
    var totalPay=reviewDebit(data,currency);
    var available=reviewAvailable(data,currency);
    var responseAfter=firstNumber(String(data.display_currency||'').toUpperCase()===currency?data.display_balance_after:undefined);
    var after=Number.isFinite(responseAfter)?responseAfter:(Number.isFinite(available)&&Number.isFinite(totalPay)?available-totalPay:NaN);
    var rows=[
      {label:'Provider',value:String(data.provider_name||body.provider||'-'),className:'admin-mfs-review-highlight'},
      {label:'Receiver Number',value:String(data.receiver_number||body.receiver_number||'-')},
      {label:'Country',value:countryName(data.country_code||data.country)},
      {label:'Mode',value:modeName(data.service_mode)},
      remittance&&rate>0?{label:'Rate',value:'RM 1 = BDT '+money(rate)}:null,
      {label:remittance?'Received Amount':'Amount',value:'BDT '+money(data.amount_bdt||body.amount_bdt||0)},
      remittance?{label:'Send Amount',value:'RM '+money(data.amount_rm||data.amount_myr||body.amount_rm||0)}:null,
      {label:'Fee',value:reviewFeeText(data,remittance)},
      {label:'Total Pay',value:reviewMoney(data,remittance),className:'admin-mfs-review-total'},
      Number.isFinite(available)?{label:'Available Balance',value:currencyPrefix(currency)+' '+money(available),className:'admin-mfs-review-highlight'}:null,
      Number.isFinite(after)?{label:'Balance After',value:currencyPrefix(currency)+' '+money(after),className:'admin-mfs-review-highlight'}:null,
      {label:'Reference',value:String(body.reference||'-'),className:'admin-mfs-review-wide'}
    ];
    return rows.filter(Boolean).map(function(row){
      return '<div class="admin-mfs-review-item '+esc(row.className||'')+'"><span class="admin-mfs-review-label">'+esc(row.label)+'</span><strong class="admin-mfs-review-value">'+esc(row.value||'-')+'</strong></div>';
    }).join('');
  }

  function formatCreateSuccess(row,body){
    row=row||{};
    body=body||{};
    var receiver=rowNumber(row);
    if(receiver==='-')receiver=body.receiver_number||'-';
    var remittance=isRemittance(row)||Number(row.amount_rm||row.amount_myr||body.amount_rm||0)>0;
    var rate=Number(row.exchange_rate||row.rate_myr_to_bdt||0);
    var feeText=remittance?'RM '+money(rmFee(row)):('BDT '+money(row.fee_bdt||row.fee_amount||0));
    var totalText=remittance?'RM '+money(rmTotal(row)):('BDT '+money(bdtTotal(row)));
    var lines=[
      'Request ID: '+String(row.request_id||'-'),
      'Provider: '+String(row.provider_name||body.provider||'-'),
      'Receiver Number: '+String(receiver),
      'Amount BDT: BDT '+money(row.amount_bdt||body.amount_bdt||0),
      remittance?'Amount RM: RM '+money(rmAmount(row)||body.amount_rm||0):'',
      remittance&&rate>0?'Rate: RM 1 = BDT '+money(rate):'',
      'Fee: '+feeText,
      'Total Pay/Hold: '+totalText,
      'Status: '+String(row.status||'PENDING'),
      'Receipt / Tracking Link: '+String(row.receipt_url||row.tracking_url||row.request_url||'')
    ];
    return lines.filter(Boolean).join('\n');
  }

  function clearCreateFormAfterSuccess(){
    var provider=el('mfsCreateProvider')&&el('mfsCreateProvider').value;
    if(el('mfsCreateForm'))el('mfsCreateForm').reset();
    if(provider&&el('mfsCreateProvider'))el('mfsCreateProvider').value=provider;
    ['mfsCreateUid','mfsCreateReceiver','mfsCreateAmountBdt','mfsCreateAmountRm','mfsCreateReference','mfsCreateNote'].forEach(function(id){
      if(el(id))el(id).value='';
    });
    updateCreatePreview();
  }

  async function openCreateReview(e){
    e.preventDefault();
    if(state.mutating)return;
    var validation=validateCreateForm();
    if(validation){
      msg('', '');
      showFeedback('error','Validation error',validation);
      return;
    }
    var button=el('mfsCreateSubmitBtn');
    var body=createRequestPayload();
    setButtonBusy(button,true,'Checking...');
    setPageBusy(true,'Loading target MFS fee preview...');
    try{
      await ensureCsrf();
      var data=await post('mfs_preview',body);
      state.createReview={body:body,preview:data};
      el('mfsCreateReviewDetails').innerHTML=formatCreateReview(data,body);
      el('mfsCreateReviewModal').classList.remove('hidden');
      el('mfsCreateReviewConfirmBtn').focus();
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to preview request',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
    }
  }

  function closeCreateReview(){
    if(state.mutating)return;
    state.createReview=null;
    el('mfsCreateReviewModal').classList.add('hidden');
  }

  function validateCreateForm(){
    var target=String(el('mfsCreateUid').value||'').trim();
    var provider=String(el('mfsCreateProvider').value||'').toUpperCase();
    var receiver=String(el('mfsCreateReceiver').value||'').trim();
    var amount=Number(el('mfsCreateAmountBdt').value||0);
    var amountRm=Number(el('mfsCreateAmountRm').value||0);
    if(!target)return 'User / Subadmin UID or registered phone is required.';
    if(['BKASH','NAGAD'].indexOf(provider)<0)return 'Please select bKash or Nagad.';
    if(!/^01[3-9]\d{8}$/.test(receiver))return 'Receiver number must be a valid 11 digit BD mobile number.';
    if((!Number.isFinite(amount)||amount<=0)&&(!Number.isFinite(amountRm)||amountRm<=0))return 'Amount BDT or Amount RM is required.';
    if(Number.isFinite(amount)&&amount>0&&(amount<500||amount>100000))return 'Amount BDT must be between BDT 500 and BDT 100,000.';
    return '';
  }

  async function confirmCreateRequest(){
    if(state.mutating||!state.createReview)return;
    var body=state.createReview.body;
    var button=el('mfsCreateReviewConfirmBtn');
    state.mutating=true;
    setButtonBusy(button,true,'Submitting...');
    setPageBusy(true,'Creating MFS request and holding target wallet balance...');
    try{
      await ensureCsrf();
      var data=await post('mfs_create',body);
      var row=data.request||data.item||data.row||data;
      var receiptUrl=String(row.receipt_url||row.tracking_url||row.request_url||'');
      state.createReview=null;
      el('mfsCreateReviewModal').classList.add('hidden');
      showFeedback('success','Request Created Successfully','Your send money request has been submitted securely.',null,{
        details:formatCreateSuccess(row,body),
        link:receiptUrl
      });
      clearCreateFormAfterSuccess();
      await load(null,false);
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to create request',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
      state.mutating=false;
    }
  }

  function findRow(id){
    return state.rows.find(function(row){return String(row.request_id||'')===String(id);})||{};
  }

  async function viewRow(id,button){
    if(state.mutating)return;
    state.mutating=true;
    setButtonBusy(button,true,'Loading...');
    setPageBusy(true,'Loading request details...');
    try{
      var data=await get('mfs_get',{request_id:id});
      var row=data.item||data.row||data.request||data||findRow(id);
      el('mfsViewTitle').textContent='MFS Request '+String(id||'');
      el('mfsViewDetails').innerHTML=mfsViewDetailsHtml(row);
      state.viewReceiptUrl=String(row.receipt_url||'');
      var actions=el('mfsViewReceiptActions');
      var open=el('mfsViewReceiptOpen');
      if(actions)actions.classList.toggle('hidden',!state.viewReceiptUrl);
      if(open)open.href=state.viewReceiptUrl||'#';
      el('mfsViewModal').classList.remove('hidden');
      el('mfsViewCloseBtn').focus();
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to load request',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
      state.mutating=false;
    }
  }

  function closeView(){
    state.viewReceiptUrl='';
    var actions=el('mfsViewReceiptActions');
    if(actions)actions.classList.add('hidden');
    el('mfsViewModal').classList.add('hidden');
  }

  async function copyViewReceipt(){
    if(!state.viewReceiptUrl){
      showFeedback('error','Receipt unavailable','This request does not have a receipt link yet.');
      return;
    }
    try{
      await navigator.clipboard.writeText(state.viewReceiptUrl);
      showFeedback('success','Receipt copied','Receipt link copied to clipboard.');
    }catch(err){
      showFeedback('success','Receipt link',state.viewReceiptUrl);
    }
  }

  function openConfirm(options){
    state.confirmAction=options;
    el('mfsConfirmKicker').textContent=options.kicker||'Confirm Action';
    el('mfsConfirmTitle').textContent=options.title||'Confirm MFS Action';
    el('mfsConfirmMessage').textContent=options.message||'';
    el('mfsConfirmInputWrap').classList.toggle('hidden',!options.input);
    el('mfsConfirmInput').value=options.inputValue||'';
    el('mfsConfirmSaveBtn').textContent=options.buttonText||'Confirm';
    el('mfsConfirmSaveBtn').className='btn '+(options.buttonClass||'brand');
    el('mfsConfirmModal').classList.remove('hidden');
    (options.input?el('mfsConfirmInput'):el('mfsConfirmSaveBtn')).focus();
  }

  function closeConfirm(){
    if(state.mutating)return;
    state.confirmAction=null;
    el('mfsConfirmModal').classList.add('hidden');
  }

  async function submitConfirm(){
    if(state.mutating||!state.confirmAction)return;
    var options=state.confirmAction;
    var message=options.input?String(el('mfsConfirmInput').value||'').trim():String(options.postMessage||'');
    if(options.input&&!message){
      showFeedback('error','Validation error','Failure message is required.');
      return;
    }
    var button=el('mfsConfirmSaveBtn');
    state.mutating=true;
    setButtonBusy(button,true,'Updating...');
    setPageBusy(true,'Updating MFS request...');
    try{
      await ensureCsrf();
      await post(options.action,{request_id:options.requestId,message:message});
      state.confirmAction=null;
      el('mfsConfirmModal').classList.add('hidden');
      showFeedback('success',options.successTitle,options.successMessage);
      await load(null,false);
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to update request',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
      state.mutating=false;
    }
  }

  function markProcessing(id){
    openConfirm({
      requestId:id,
      action:'mfs_mark_processing',
      title:'Mark request as processing?',
      message:'Request '+id+' will move to the Processing queue.',
      postMessage:'MFS request is processing',
      buttonText:'Mark Processing',
      buttonClass:'blue',
      successTitle:'Processing updated',
      successMessage:'Request '+id+' is now processing.'
    });
  }

  function markFailed(id){
    openConfirm({
      requestId:id,
      action:'mfs_failed',
      kicker:'Refund Request',
      title:'Mark request as failed?',
      message:'Request '+id+' will be marked failed and its target wallet hold will be refunded.',
      input:true,
      inputValue:'MFS request failed',
      buttonText:'Fail & Refund',
      buttonClass:'red',
      successTitle:'Request failed/refunded',
      successMessage:'Request '+id+' was marked failed and its target wallet hold was released.'
    });
  }

  function markSuccess(id){
    if(state.mutating)return;
    state.successRequestId=String(id||'');
    el('mfsSuccessSenderDetails').value='';
    el('mfsSuccessTrxid').value='';
    el('mfsSuccessMessage').value='';
    el('mfsSuccessModal').classList.remove('hidden');
    el('mfsSuccessSenderDetails').focus();
  }

  function closeSuccess(){
    if(state.mutating)return;
    state.successRequestId='';
    el('mfsSuccessModal').classList.add('hidden');
  }

  async function submitSuccess(){
    if(state.mutating)return;
    var id=state.successRequestId;
    var details=String(el('mfsSuccessSenderDetails').value||'').trim();
    if(!id)return;
    if(!details){
      showFeedback('error','Validation error','Sender details are required. Multiple numbers, amounts and text are allowed.');
      return;
    }
    var trxid=String(el('mfsSuccessTrxid').value||'').trim();
    var message=String(el('mfsSuccessMessage').value||'').trim()||'Transaction successful. Sender details: '+details;
    var button=el('mfsSuccessSaveBtn');
    state.mutating=true;
    setButtonBusy(button,true,'Updating...');
    setPageBusy(true,'Settling target wallet hold and completing request...');
    try{
      await ensureCsrf();
      var data=await post('mfs_success',{request_id:id,trxid:trxid,sender_details:details,message:message});
      state.successRequestId='';
      el('mfsSuccessModal').classList.add('hidden');
      showFeedback('success','Request successful','Request '+id+' completed successfully.');
      await load(null,false);
    }catch(err){
      if(handleSessionExpired(err))return;
      showFeedback('error','Unable to complete request',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
      state.mutating=false;
    }
  }

  function onAction(e){
    var button=e.target.closest('[data-act]');
    if(!button||state.mutating)return;
    var id=button.getAttribute('data-id')||'';
    var action=button.getAttribute('data-act')||'';
    if(action==='view')viewRow(id,button);
    if(action==='processing')markProcessing(id);
    if(action==='failed')markFailed(id);
    if(action==='success')markSuccess(id);
    if(action==='receipt'){
      var url=button.getAttribute('data-url')||'';
      if(url)window.open(url,'_blank','noopener');
    }
  }

  function toggleSidebar(open){
    document.body.classList.toggle('sidebar-open',Boolean(open));
    el('mfsSidebarToggle').setAttribute('aria-expanded',open?'true':'false');
  }

  function bind(){
    all('.admin-mfs-tab').forEach(function(button){
      button.addEventListener('click',function(){
        if(state.loading||state.mutating)return;
        setActiveTab(button.getAttribute('data-mfs-tab')||'pending');
        load(button,true);
      });
    });
    all('[data-mfs-view-target]').forEach(function(button){
      button.addEventListener('click',function(){setSection(button.getAttribute('data-mfs-view-target')||'manage');});
    });
    el('mfsCreateForm').addEventListener('submit',openCreateReview);
    el('mfsCreateReviewCancelBtn').addEventListener('click',closeCreateReview);
    el('mfsCreateReviewConfirmBtn').addEventListener('click',confirmCreateRequest);
    el('mfsRateForm').addEventListener('submit',saveRate);
    el('mfsRateReloadBtn').addEventListener('click',function(){loadRate(el('mfsRateReloadBtn'),true);});
    el('mfsTierFeesForm').addEventListener('submit',saveTierFees);
    el('mfsTierFeesReloadBtn').addEventListener('click',function(){loadFees(el('mfsTierFeesReloadBtn'),true);});
    el('mfsSettingsForm').addEventListener('submit',saveFeeSettings);
    ['mfsCreateUid','mfsCreateProvider','mfsCreateReceiver','mfsCreateAmountBdt','mfsCreateAmountRm'].forEach(function(id){el(id).addEventListener('input',updateCreatePreview); el(id).addEventListener('change',updateCreatePreview);});
    el('mfsReloadBtn').addEventListener('click',function(){load(el('mfsReloadBtn'),true);});
    el('mfsApplyFilterBtn').addEventListener('click',function(){resetAllPages();load(el('mfsApplyFilterBtn'),true);});
    el('mfsSearch').addEventListener('input',function(){
      clearTimeout(mfsSearchTimer);
      mfsSearchTimer=setTimeout(function(){resetAllPages();load(null,true);},350);
    });
    el('mfsPrevBtn').addEventListener('click',function(){
      var p=currentPage();if(p.page<=1||state.loading)return;
      p.history.pop();p.page=Math.max(1,p.page-1);p.cursor=String(p.history[p.history.length-1]||'');load(el('mfsPrevBtn'),true);
    });
    el('mfsNextBtn').addEventListener('click',function(){
      var p=currentPage();if(!p.has_more||!p.next_cursor||state.loading)return;
      p.history.push(p.next_cursor);p.page+=1;p.cursor=p.next_cursor;load(el('mfsNextBtn'),true);
    });
    el('mfsTableBody').addEventListener('click',onAction);
    el('mfsMobileList').addEventListener('click',onAction);
    el('mfsSuccessCancelBtn').addEventListener('click',closeSuccess);
    el('mfsSuccessSaveBtn').addEventListener('click',submitSuccess);
    el('mfsConfirmCancelBtn').addEventListener('click',closeConfirm);
    el('mfsConfirmSaveBtn').addEventListener('click',submitConfirm);
    el('mfsViewCloseBtn').addEventListener('click',closeView);
    el('mfsViewReceiptCopy').addEventListener('click',copyViewReceipt);
    el('mfsFeedbackOkBtn').addEventListener('click',closeFeedback);
    el('mfsFeedbackReceiptCopy').addEventListener('click',copyFeedbackReceipt);
    el('mfsSidebarToggle').addEventListener('click',function(){toggleSidebar(!document.body.classList.contains('sidebar-open'));});
    el('mfsSidebarBackdrop').addEventListener('click',function(){toggleSidebar(false);});
    el('mfsSuccessModal').addEventListener('click',function(e){if(e.target===el('mfsSuccessModal'))closeSuccess();});
    el('mfsConfirmModal').addEventListener('click',function(e){if(e.target===el('mfsConfirmModal'))closeConfirm();});
    el('mfsCreateReviewModal').addEventListener('click',function(e){if(e.target===el('mfsCreateReviewModal'))closeCreateReview();});
    el('mfsViewModal').addEventListener('click',function(e){if(e.target===el('mfsViewModal'))closeView();});
    el('mfsFeedbackModal').addEventListener('click',function(e){if(e.target===el('mfsFeedbackModal'))closeFeedback();});
    document.addEventListener('keydown',function(e){
      if(e.key!=='Escape')return;
      closeFeedback();
      closeView();
      closeConfirm();
      closeCreateReview();
      closeSuccess();
      toggleSidebar(false);
    });
  }

  function init(){
    bind();
    setSection('manage');
    setActiveTab('pending');
    loadRate(null,false);
    loadFees(null,false);
    load(null,true);
    ensureCsrf().catch(function(){});
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  }else{
    init();
  }
})();
