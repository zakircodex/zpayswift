(function(){
const SK='z_builder_owner_session';
let timer=null,percent=0;
function session(){return localStorage.getItem(SK)||'';}
function insertBox(){let box=document.querySelector('[data-build-progress-box]');if(box)return box;const title=[...document.querySelectorAll('h2')].find(x=>x.textContent.trim()==='Build Status');if(!title)return null;box=document.createElement('div');box.setAttribute('data-build-progress-box','');box.style.margin='14px 0';box.innerHTML='<div style="display:flex;justify-content:space-between;margin-bottom:8px"><span data-build-progress-label>Preparing</span><b data-build-progress-percent>0%</b></div><div style="height:14px;border-radius:999px;background:rgba(255,255,255,.12);overflow:hidden"><span data-build-progress-bar style="display:block;height:100%;width:0%;border-radius:999px;background:linear-gradient(135deg,#0b5cff,#01d6c9)"></span></div><p data-build-progress-note style="margin-top:10px;color:var(--muted)">Auto checking build status...</p>';title.insertAdjacentElement('afterend',box);return box;}
function show(p,label,note){const box=insertBox();if(!box)return;p=Math.max(0,Math.min(100,Math.round(p)));box.querySelector('[data-build-progress-percent]').textContent=p+'%';box.querySelector('[data-build-progress-bar]').style.width=p+'%';box.querySelector('[data-build-progress-label]').textContent=label||'Building APK';box.querySelector('[data-build-progress-note]').textContent=note||'Auto checking build status...';}
function estimate(app){const s=String(app&& (app.build_status||app.status)||'').toUpperCase();if(s==='ARTIFACT_READY'||s==='APK_READY')return 100;if(s==='BUILD_FAILED')return 0;if(s==='BUILD_QUEUED')return Math.max(percent,15);if(s==='READY_TO_BUILD')return Math.max(percent,5);return 0;}
async function check(){try{const r=await fetch('/api/my_site/worker_status.php',{cache:'no-store',headers:{'Accept':'application/json','X-ZBUILDER-SESSION':session()}});const j=await r.json();if(!j||!j.ok)return;const list=(j.data&& (j.data.apps||j.data.workers))||[];const app=list[0];if(!app)return;const p=estimate(app);const status=String(app.build_status||app.status||'');if(p>0&&p<100){percent=Math.min(95,Math.max(percent,p)+3);show(percent,'Building APK','Auto checking every few seconds...');start();}else if(p===100){show(100,'APK ready','Download button is ready.');stop();}else if(status==='BUILD_FAILED'){show(0,'Build failed','Open GitHub Actions and check error.');stop();}}
catch(e){}
}
function start(){if(timer)return;timer=setInterval(check,8000);}
function stop(){if(timer){clearInterval(timer);timer=null;}}
window.addEventListener('load',function(){check();document.querySelector('[data-refresh-worker]')?.addEventListener('click',function(){setTimeout(check,500);});});
})();
