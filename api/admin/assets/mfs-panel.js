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
    settings:null,
    settingsLoading:false
  };

  function el(id){ return document.getElementById(id); }
  function all(selector){ return Array.prototype.slice.call(document.querySelectorAll(selector)); }
  function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g,function(s){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];}); }
  function money(v){ var n=Number(v||0); return Number.isFinite(n)?n.toFixed(2):'0.00'; }
  function ts(v){ var n=Number(v||0); if(!n)return '-'; var d=new Date(String(Math.trunc(n)).length<=10?n*1000:n); return isNaN(d.getTime())?'-':d.toLocaleString(); }
  function msg(text,type){ var box=el('mfsPageMsg'); if(!box)return; box.className='mfs-msg '+(type||''); box.textContent=String(text||''); }
  function statusPill(v){ var t=String(v||'-').toUpperCase(); var cls='info'; if(['SUCCESS','SUCCESSFUL','DONE','COMPLETED'].indexOf(t)>=0)cls='success'; else if(['FAILED','CANCELLED'].indexOf(t)>=0)cls='danger'; else if(['PENDING','PROCESSING','WAITING_ADMIN'].indexOf(t)>=0)cls='warning'; return '<span class="pill '+cls+'">'+esc(t)+'</span>'; }
  function normalizeRows(data){ return Array.isArray(data.items)?data.items:Array.isArray(data.rows)?data.rows:Array.isArray(data.requests)?data.requests:[]; }
  function totalRows(data){ var total=Number(data&&data.pagination&&data.pagination.total); return Number.isFinite(total)?total:normalizeRows(data||{}).length; }
  function rowNumber(r){ return r.receiver_number||r.number||r.mfs_number||r.to_number||'-'; }
  function isRemittance(r){ return String(r.service_mode||'').toUpperCase()==='REMITTANCE'||String(r.country_code||r.country||'').toUpperCase()==='MY'||Number(r.amount_rm||r.amount_myr||0)>0; }
  function rowAmount(r){
    var c=String(r.wallet_currency||'BDT').toUpperCase();
    if(isRemittance(r)){
      var hold=c==='MYR'?'RM '+money(r.total_debit_rm||r.total_debit||r.amount_rm||0):'BDT '+money(r.total_debit_bdt||r.total_debit||r.amount_bdt||r.amount||0);
      return 'BDT '+money(r.amount_bdt||0)+' / RM '+money(r.amount_rm||r.amount_myr||0)+' | Hold '+hold;
    }
    if(c==='MYR') return 'RM '+money(r.total_debit||r.amount_rm||0);
    return 'BDT '+money(r.total_debit||r.amount_bdt||r.amount||0);
  }
  function num(id){ var n=Number(el(id)&&el(id).value||0); return Number.isFinite(n)?n:0; }
  function fee(settings,country,provider){ return (((settings||{}).fees||{})[country]||{})[provider]||{}; }

  async function readJson(res){
    var text=await res.text();
    var json={};
    try{json=JSON.parse(text);}catch(e){throw new Error(text||'Invalid server response');}
    if(!res.ok||!json.ok){
      var er=new Error(json.message||'Request failed');
      er.code=json.code||'ERROR';
      er.data=json.data||{};
      throw er;
    }
    return json.data||{};
  }

  async function get(action,params){
    var qs=new URLSearchParams(params||{}).toString();
    var res=await fetch('proxy.php?action='+encodeURIComponent(action)+(qs?'&'+qs:''),{
      credentials:'same-origin',
      headers:{Accept:'application/json','Cache-Control':'no-cache'}
    });
    return readJson(res);
  }

  async function post(action,body){
    var headers={'Content-Type':'application/json',Accept:'application/json','Cache-Control':'no-cache'};
    if(state.csrf)headers['X-CSRF-TOKEN']=state.csrf;
    var res=await fetch('proxy.php?action='+encodeURIComponent(action),{
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

  function showFeedback(type,title,message){
    var isError=type==='error';
    el('mfsFeedbackCard').classList.toggle('feedback-error',isError);
    el('mfsFeedbackKicker').textContent=isError?'Error':'Success';
    el('mfsFeedbackTitle').textContent=String(title||'Notice');
    el('mfsFeedbackMessage').textContent=String(message||'');
    el('mfsFeedbackModal').classList.remove('hidden');
    el('mfsFeedbackOkBtn').focus();
  }

  function closeFeedback(){
    el('mfsFeedbackModal').classList.add('hidden');
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
    var params={
      page:1,
      limit:100,
      service_type:el('mfsService').value||'',
      uid:el('mfsUid').value||'',
      number:el('mfsNumber').value||''
    };
    if(state.tab==='done')params.status='SUCCESSFUL';
    if(state.tab==='failed')params.status='FAILED';
    return params;
  }

  function filterRows(rows){
    var q=String(el('mfsSearch').value||'').toLowerCase().trim();
    if(!q)return rows;
    return rows.filter(function(r){return JSON.stringify(r).toLowerCase().indexOf(q)>=0;});
  }

  function actionButtons(r){
    var id=String(r.request_id||'');
    var status=String(r.status||'').toUpperCase();
    var html='<div class="admin-mfs-actions"><button class="mini-btn" type="button" data-act="view" data-id="'+esc(id)+'">View</button>';
    if(status!=='PROCESSING'&&status!=='SUCCESSFUL'&&status!=='SUCCESS'&&status!=='FAILED')html+='<button class="mini-btn blue" type="button" data-act="processing" data-id="'+esc(id)+'">Processing</button>';
    if(status!=='SUCCESSFUL'&&status!=='SUCCESS'&&status!=='FAILED')html+='<button class="mini-btn success" type="button" data-act="success" data-id="'+esc(id)+'">Success</button><button class="mini-btn danger" type="button" data-act="failed" data-id="'+esc(id)+'">Failed</button>';
    return html+'</div>';
  }

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

  function renderListLoading(){
    el('mfsTableBody').innerHTML='<tr><td colspan="8" class="empty">Loading '+esc(state.tab)+' requests...</td></tr>';
    el('mfsMobileList').innerHTML='<div class="empty">Loading '+esc(state.tab)+' requests...</div>';
  }

  async function loadList(){
    renderListLoading();
    try{
      var data=await get(actionForTab(),listParams());
      state.rows=normalizeRows(data);
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
      var data=await Promise.all([
        get('mfs_pending',{page:1,limit:1}),
        get('mfs_processing',{page:1,limit:1}),
        get('mfs_done',{page:1,limit:1,status:'SUCCESSFUL'}),
        get('mfs_done',{page:1,limit:1,status:'FAILED'})
      ]);
      el('mfsSummaryPending').textContent=String(totalRows(data[0]));
      el('mfsSummaryProcessing').textContent=String(totalRows(data[1]));
      el('mfsSummaryDone').textContent=String(totalRows(data[2]));
      el('mfsSummaryFailed').textContent=String(totalRows(data[3]));
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
  }

  function setSection(section){
    state.section=section==='create'?'create':(section==='settings'?'settings':'manage');
    all('[data-mfs-view]').forEach(function(view){
      view.classList.toggle('hidden',view.getAttribute('data-mfs-view')!==state.section);
    });
    all('[data-mfs-view-target]').forEach(function(button){
      button.classList.toggle('active',button.getAttribute('data-mfs-view-target')===state.section);
    });
    if(state.section==='settings'&&!state.settings)loadSettings(null,false);
    toggleSidebar(false);
  }

  function populateSettings(settings){
    settings=settings||{};
    state.settings=settings;
    var fees=settings.fees||{};
    var bd=fees.BD||{};
    var my=fees.MY||{};
    el('mfsRateMyrBdt').value=money(settings.rate_myr_bdt||31);
    el('mfsMyBkashFee').value=money((my.BKASH||{}).fee_rm||(my.BKASH||{}).fixed||3);
    el('mfsMyNagadFee').value=money((my.NAGAD||{}).fee_rm||(my.NAGAD||{}).fixed||3);
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

  async function loadSettings(button,notify){
    if(state.settingsLoading)return;
    state.settingsLoading=true;
    setButtonBusy(button,true,'Loading...');
    try{
      var data=await get('mfs_settings_get',{});
      populateSettings(data.settings||data.raw||{});
      if(notify)showFeedback('success','Settings loaded','MFS fee and rate settings loaded.');
    }catch(err){
      if(notify!==false)showFeedback('error','Unable to load settings',friendlyError(err));
    }finally{
      setButtonBusy(button,false);
      state.settingsLoading=false;
    }
  }

  function settingsPayload(){
    return {
      rate_myr_bdt:num('mfsRateMyrBdt')||31,
      fees:{
        MY:{
          BKASH:{type:'fixed',fixed:num('mfsMyBkashFee'),fee_rm:num('mfsMyBkashFee')},
          NAGAD:{type:'fixed',fixed:num('mfsMyNagadFee'),fee_rm:num('mfsMyNagadFee')}
        },
        BD:{
          BKASH:{type:el('mfsBdBkashType').value,fixed:num('mfsBdBkashFixed'),percent:num('mfsBdBkashPercent'),min_fee:num('mfsBdBkashMin'),max_fee:num('mfsBdBkashMax')},
          NAGAD:{type:el('mfsBdNagadType').value,fixed:num('mfsBdNagadFixed'),percent:num('mfsBdNagadPercent'),min_fee:num('mfsBdNagadMin'),max_fee:num('mfsBdNagadMax')}
        }
      }
    };
  }

  async function saveSettings(e){
    e.preventDefault();
    if(state.mutating)return;
    var button=el('mfsSettingsSaveBtn');
    state.mutating=true;
    setButtonBusy(button,true,'Saving...');
    setPageBusy(true,'Saving MFS fee and rate settings...');
    try{
      await ensureCsrf();
      var data=await post('mfs_settings_save',settingsPayload());
      populateSettings(data.settings||{});
      showFeedback('success','Settings saved','MFS fee and MYR/BDT rate settings saved.');
    }catch(err){
      showFeedback('error','Unable to save settings',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
      state.mutating=false;
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
    var myFee=Number((fee(settings,'MY',provider)||{}).fee_rm||(fee(settings,'MY',provider)||{}).fixed||3);
    box.textContent=[
      'Backend will use the target account country/currency for the final hold.',
      'BD target: LOCAL, Amount BDT '+money(amountBdt)+', Fee BDT '+money(bdFee)+', Total Hold BDT '+money(amountBdt+bdFee),
      'MY target: REMITTANCE, Rate RM 1 = BDT '+money(rate)+', Amount RM '+money(amountRm)+', Fee RM '+money(myFee)+', Hold RM wallet RM '+money(amountRm+myFee)+' or BDT wallet BDT '+money(amountBdt+(myFee*rate))
    ].join('\n');
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
    if(Number.isFinite(amount)&&amount>0&&(amount<500||amount>50000))return 'Amount BDT must be between BDT 500 and BDT 50,000.';
    return '';
  }

  async function createRequest(e){
    e.preventDefault();
    if(state.mutating)return;
    var validation=validateCreateForm();
    if(validation){
      msg(validation,'bad');
      showFeedback('error','Validation error',validation);
      return;
    }
    var button=el('mfsCreateSubmitBtn');
    var amount=Number(el('mfsCreateAmountBdt').value||0);
    var amountRm=Number(el('mfsCreateAmountRm').value||0);
    var body={
      uid:String(el('mfsCreateUid').value||'').trim(),
      provider:el('mfsCreateProvider').value,
      service_type:'SEND_MONEY',
      account_type:'PERSONAL',
      receiver_number:String(el('mfsCreateReceiver').value||'').trim(),
      amount_bdt:amount,
      amount_rm:amountRm,
      reference:String(el('mfsCreateReference').value||'').trim(),
      note:String(el('mfsCreateNote').value||'').trim()
    };
    state.mutating=true;
    setButtonBusy(button,true,'Creating...');
    setPageBusy(true,'Creating MFS request and holding target wallet balance...');
    try{
      await ensureCsrf();
      var data=await post('mfs_create',body);
      el('mfsCreateForm').reset();
      setActiveTab('pending');
      setSection('manage');
      showFeedback('success','Request created successfully','Request '+String(data.request_id||'')+' was created. Balance was held from the target account.');
      await load(null,false);
    }catch(err){
      msg(friendlyError(err),'bad');
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
      el('mfsViewDetails').textContent=JSON.stringify(row,null,2);
      el('mfsViewModal').classList.remove('hidden');
      el('mfsViewCloseBtn').focus();
    }catch(err){
      showFeedback('error','Unable to load request',friendlyError(err));
    }finally{
      setPageBusy(false);
      setButtonBusy(button,false);
      state.mutating=false;
    }
  }

  function closeView(){
    el('mfsViewModal').classList.add('hidden');
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
      await post('mfs_success',{request_id:id,trxid:trxid,sender_details:details,message:message});
      state.successRequestId='';
      el('mfsSuccessModal').classList.add('hidden');
      showFeedback('success','Request successful','Request '+id+' was completed successfully.');
      await load(null,false);
    }catch(err){
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
    el('mfsCreateForm').addEventListener('submit',createRequest);
    el('mfsSettingsForm').addEventListener('submit',saveSettings);
    el('mfsSettingsReloadBtn').addEventListener('click',function(){loadSettings(el('mfsSettingsReloadBtn'),true);});
    ['mfsCreateProvider','mfsCreateAmountBdt','mfsCreateAmountRm'].forEach(function(id){el(id).addEventListener('input',updateCreatePreview); el(id).addEventListener('change',updateCreatePreview);});
    el('mfsReloadBtn').addEventListener('click',function(){load(el('mfsReloadBtn'),true);});
    el('mfsApplyFilterBtn').addEventListener('click',function(){load(el('mfsApplyFilterBtn'),true);});
    el('mfsSearch').addEventListener('input',render);
    el('mfsTableBody').addEventListener('click',onAction);
    el('mfsMobileList').addEventListener('click',onAction);
    el('mfsSuccessCancelBtn').addEventListener('click',closeSuccess);
    el('mfsSuccessSaveBtn').addEventListener('click',submitSuccess);
    el('mfsConfirmCancelBtn').addEventListener('click',closeConfirm);
    el('mfsConfirmSaveBtn').addEventListener('click',submitConfirm);
    el('mfsViewCloseBtn').addEventListener('click',closeView);
    el('mfsFeedbackOkBtn').addEventListener('click',closeFeedback);
    el('mfsSidebarToggle').addEventListener('click',function(){toggleSidebar(!document.body.classList.contains('sidebar-open'));});
    el('mfsSidebarBackdrop').addEventListener('click',function(){toggleSidebar(false);});
    el('mfsSuccessModal').addEventListener('click',function(e){if(e.target===el('mfsSuccessModal'))closeSuccess();});
    el('mfsConfirmModal').addEventListener('click',function(e){if(e.target===el('mfsConfirmModal'))closeConfirm();});
    el('mfsViewModal').addEventListener('click',function(e){if(e.target===el('mfsViewModal'))closeView();});
    el('mfsFeedbackModal').addEventListener('click',function(e){if(e.target===el('mfsFeedbackModal'))closeFeedback();});
    document.addEventListener('keydown',function(e){
      if(e.key!=='Escape')return;
      closeFeedback();
      closeView();
      closeConfirm();
      closeSuccess();
      toggleSidebar(false);
    });
  }

  function init(){
    bind();
    setSection('manage');
    setActiveTab('pending');
    loadSettings(null,false);
    load(null,true);
    ensureCsrf().catch(function(){});
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  }else{
    init();
  }
})();
