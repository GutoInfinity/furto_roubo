(()=>{'use strict';
const root=document.documentElement,panel=document.querySelector('#appearance-dialog'),status=document.querySelector('#appearance-status');
const colors={indigo:'#4263eb',blue:'#066fd1',azure:'#4299e1',purple:'#ae3ec9',pink:'#d6336c',red:'#d63939',orange:'#f76707',yellow:'#f59f00',lime:'#74b816',green:'#2fb344',teal:'#0ca678',cyan:'#17a2b8',black:'#000000',graphite:'#343a40',gray:'#6c757d',navy:'#15345b',violet:'#7048e8',wine:'#8b1e3f',brown:'#795548',gold:'#b8860b'};
let state={},defaults={},csrf='',timer,queue=Promise.resolve(),version=0,ready=false,returnFocus;
const os=matchMedia('(prefers-color-scheme:dark)'),set=(k,v)=>root.style.setProperty(k,v);
function rgb(hex){return [1,3,5].map(i=>parseInt(hex.slice(i,i+2),16));}
function readable(hex){const c=rgb(hex).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});return .2126*c[0]+.7152*c[1]+.0722*c[2]>.179?'#182637':'#ffffff';}
const company={primaria:'#4263eb',sidebar:'#243746',texto:'#182637'};
function mix(a,b,n){return '#'+rgb(a).map((v,i)=>Math.round(v*(1-n)+rgb(b)[i]*n).toString(16).padStart(2,'0')).join('');}
function apply(){if(!ready)return;
 const dark=['dark','dark-colored'].includes(state.scheme)||(state.scheme==='auto'&&os.matches)||(['default','company'].includes(state.scheme)&&root.dataset.bsTheme==='dark'),primary=state.custom_primary||(state.primary==='inverted'?(dark?'#f4f6fa':'#182433'):state.primary==='company'?company.primaria:colors[state.primary]);
 const base={slate:['#f4f6fa','#182637','#718096','#e3e8ef','#17212f','#1e2a3a'],gray:['#f5f6f8','#1f2937','#6b7280','#e5e7eb','#181e28','#252d3a'],zinc:['#f5f5f6','#27272a','#71717a','#e4e4e7','#18181b','#27272a'],neutral:['#f5f5f5','#262626','#737373','#e5e5e5','#171717','#262626'],stone:['#f6f5f4','#292524','#78716c','#e7e5e4','#1c1917','#292524']}[state.base];
 const activeBase=[...(base||['#f4f6fa','#182637','#718096','#e3e8ef','#17212f','#1e2a3a'])];
 const seed={slate:'#64748b',gray:'#6b7280',zinc:'#71717a',neutral:'#737373',stone:'#78716c'}[state.base];
 if(seed){activeBase[0]=mix('#ffffff',seed,.045);activeBase[1]=mix('#101828',seed,.1);activeBase[5]=mix('#0c1425',seed,.18);}
 else if(!['company','default'].includes(state.scheme)){activeBase[0]='#f6f8fb';activeBase[1]='#182433';activeBase[5]='#1d293b';}
 if(dark&&!['company','default'].includes(state.scheme))activeBase[4]='#0f1729';
 root.dataset.bsTheme=dark?'dark':'light';
 for(const key of ['position','width','navbar','navstyle','panel','sidebar','size'])root.dataset['fb'+key[0].toUpperCase()+key.slice(1)]=state[key];
 const nav=state.custom_sidebar||(['colored','dark-colored'].includes(state.scheme)?(dark?mix(primary,'#000000',.35):primary):['company','default'].includes(state.scheme)?company.sidebar:state.scheme==='dark-nav'||dark?'#1d293b':'#ffffff');
 set('--fb-primary',primary);set('--tblr-primary',primary);set('--tblr-primary-rgb',rgb(primary).join(','));set('--fb-primary-text',readable(primary));set('--fb-navy',nav);set('--fb-nav-text',readable(nav));
 set('--fb-bg',dark?activeBase[4]:activeBase[0]);set('--fb-surface',dark?activeBase[5]:'#ffffff');set('--fb-ink',dark?'#e7edf5':activeBase[1]);set('--fb-muted',dark?'#a4b2c4':activeBase[2]);set('--fb-border',dark?'#3c4654':activeBase[3]);
 set('--fb-radius',state.radius+'px');set('--fb-sidebar-width',state.sidewidth+'px');set('--fb-font',{sans:'Inter,system-ui,-apple-system,"Segoe UI",sans-serif',serif:'Georgia,"Times New Roman",serif',mono:'ui-monospace,Consolas,monospace',comic:'"Comic Sans MS",cursive'}[state.font]);set('--fb-scale',{small:'.92',normal:'1',large:'1.18'}[state.size]);
 document.querySelector('#theme-toggle i').className='fas '+(dark?'fa-sun':'fa-moon');
 for(const input of panel.querySelectorAll('[data-appearance]')){const key=input.dataset.appearance;if(input.type==='radio')input.checked=state[key]===input.value;else input.value=state[key]||(key==='custom_primary'?primary:nav);}
 panel.querySelector('[data-appearance-group=sidebar]').hidden=state.position!=='vertical';panel.querySelector('[data-appearance-group=navbar]').hidden=state.position==='vertical';
 requestAnimationFrame(()=>window.dispatchEvent(new Event('resize')));
}
async function request(method='GET',data){const response=await fetch('api.php?action=appearance',{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},...(data?{body:JSON.stringify(data)}:{})});const result=await response.json();if(!response.ok)throw Error(result.error||'Não foi possível salvar.');return result;}
function save(){clearTimeout(timer);const revision=++version,snapshot={...state};status.textContent='Salvando…';document.querySelector('#appearance-retry').hidden=true;
 queue=queue.catch(()=>{}).then(async()=>{try{await request('POST',snapshot);if(revision===version)status.textContent='Personalização salva.';}catch(e){if(revision===version){status.textContent=e.message;document.querySelector('#appearance-retry').hidden=false;}}});
}
panel.addEventListener('change',e=>{const key=e.target.dataset.appearance;if(!key||!ready)return;state[key]=e.target.value;if(key==='primary')state.custom_primary='';apply();save();});
document.querySelector('#appearance-open').onclick=()=>{returnFocus=document.activeElement;panel.showModal();};
document.querySelector('#appearance-close').onclick=()=>panel.close();panel.addEventListener('close',()=>returnFocus?.focus());
panel.addEventListener('click',e=>{if(e.target===panel){const r=panel.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)panel.close();}});
document.querySelector('#appearance-reset').onclick=()=>{if(!ready)return;state={...defaults};apply();save();};
document.querySelector('#appearance-retry').onclick=()=>{if(ready)save();else load();};
for(const link of document.querySelectorAll('.main-nav>a')){link.title=link.textContent.trim();link.setAttribute('aria-label',link.title);const label=document.createElement('span');for(const child of [...link.childNodes])if(child.nodeType===Node.TEXT_NODE)label.append(child);link.append(label);}
 for(const preview of panel.querySelectorAll('[data-preview-key]')){
  const k=preview.dataset.previewKey,v=preview.dataset.previewValue;
  if(k==='theme-primary'){preview.style.background=colors[v]||(v==='inverted'?'var(--text-primary)':company.primaria);}
  else if(k==='theme-font'){preview.textContent='Aa';preview.style.fontFamily={'sans-serif':'system-ui',serif:'Georgia',monospace:'Consolas',comic:'"Comic Sans MS"'}[v];}
  else if(k==='theme-radius'){preview.innerHTML='<span class="tabler-radius-shape"></span>';preview.firstChild.style.borderRadius=Number(v)*8+'px';}
  else if(k==='theme-base'){const seed={slate:'#64748b',gray:'#6b7280',zinc:'#71717a',neutral:'#737373',stone:'#78716c',company:company.texto}[v];preview.innerHTML=[.05,.25,.5,.75,.95].map(n=>'<i style="background:'+mix('#ffffff',seed,n)+'"></i>').join('');}
  else{const vertical=k==='navbar-position'&&v==='vertical'||k==='sidebar',preset=k==='scheme',pageFill=preset?(['dark','dark-colored'].includes(v)?'#101827':v==='company'?'var(--surface-soft)':'#f6f8fb'):'var(--surface-soft)',navFill=preset?(v==='dark'||v==='dark-nav'?'#27354a':['colored','dark-colored'].includes(v)?'var(--association-primaria)':v==='company'?'var(--association-sidebar)':'#ffffff'):'var(--association-primaria)';let shapes='';
   if(k==='layout')shapes='<rect x="'+(v==='fluid'?5:v==='boxed'?18:12)+'" y="9" width="'+(v==='fluid'?86:v==='boxed'?60:72)+'" height="46" rx="3" fill="var(--bg-card)" stroke="var(--border-color)"/>';
   else if(k==='offcanvas-style')shapes='<rect x="'+(v==='floating'?54:58)+'" y="'+(v==='floating'?6:1)+'" width="'+(v==='floating'?36:37)+'" height="'+(v==='floating'?52:62)+'" rx="'+(v==='floating'?4:0)+'" fill="var(--bg-card)" stroke="var(--border-color)"/>';
   else shapes='<rect x="'+(v==='floating'?6:1)+'" y="'+(v==='floating'?6:1)+'" width="'+(vertical?(v==='folded'||v==='folded-hover'?14:28):v==='floating'?84:94)+'" height="'+(vertical?62:16)+'" rx="'+(v==='floating'?4:0)+'" fill="'+navFill+'"/>';
   preview.innerHTML='<svg viewBox="0 0 96 64" focusable="false"><rect x="1" y="1" width="94" height="62" rx="5" fill="'+pageFill+'" stroke="var(--border-color)"/>'+shapes+'<rect x="'+(vertical?38:12)+'" y="28" width="46" height="5" rx="2" fill="'+(preset&&v==='light'?'#94a3b8':'var(--text-secondary)')+'"/><rect x="'+(vertical?38:12)+'" y="40" width="'+(vertical?42:72)+'" height="5" rx="2" fill="var(--border-color)"/></svg>';}
 }

async function load(){try{const result=await request();state=result.appearance;defaults=result.defaults;ready=true;apply();status.textContent='Personalização salva.';document.querySelector('#appearance-retry').hidden=true;}catch(e){status.textContent=e.message;document.querySelector('#appearance-retry').hidden=false;}}
window.addEventListener('fb-auth',e=>{csrf=e.detail.csrf;load();});
if(!document.querySelector('#app-shell').hidden)fetch('api.php?action=session',{credentials:'same-origin'}).then(r=>r.json()).then(r=>{if(r.user&&!csrf){csrf=r.csrf;load();}}).catch(()=>{});
os.addEventListener('change',()=>{if(state.scheme==='auto')apply();});
// The existing theme shortcut remains available and uses the same saved preferences.
document.querySelector('#theme-toggle').addEventListener('click',()=>{if(!ready)return;state.scheme=root.dataset.bsTheme==='dark'?'dark':'light';apply();save();});
})();
