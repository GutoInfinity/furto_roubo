(()=>{'use strict';
/* PDF "Relatório de Furto / Roubo" (WebAssist) → ocorrências no sistema → FOR 024 no mesmo arquivo do cliente. */
const $=s=>document.querySelector(s);
const STATUS={nova:'Nova ocorrência',ja_importada:'Já importada'};
let ctx=null,preview=null;
function pdfForm(){return '<form id="for024-pdf-form"><label class="upload-zone"><i class="fas fa-file-pdf"></i><strong>Selecione o PDF “Relatório de Furto / Roubo”</strong><span>PDF gerado pelo WebAssist · até 10 MB</span><input type="file" name="file" accept=".pdf,application/pdf" required aria-label="Selecionar PDF do relatório"></label><div class="d-flex align-items-center justify-content-between mt-3 gap-3"><span class="small text-secondary">Nada é gravado antes da conferência.</span><button class="btn btn-primary" type="submit">Ler PDF <i class="fas fa-arrow-right"></i></button></div></form><div id="for024-pdf-error" class="alert alert-danger mt-3" role="alert" hidden></div>';}
function card(title,body){return '<article class="card"><header class="card-header"><h3 class="card-title">'+title+'</h3></header><div class="card-body">'+body+'</div></article>';}
async function render(context){
 ctx=context;const host=$('#for024-panel');if(!host)return;let info;
 try{info=await ctx.request('for024');}catch(e){host.innerHTML='';ctx.toast(e.message,true);return;}
 if(!ctx.current()||!$('#for024-panel'))return;
 const template=info.template?'<p class="small mb-2">Modelo em uso: anos <strong>'+info.template.years.map(ctx.esc).join(', ')+'</strong> · atualizado em '+ctx.esc(ctx.date(info.template.updated_at))+'</p>':'<div class="alert alert-warning">Envie a planilha FOR 024 atual uma vez. Ela será o modelo: o sistema acrescenta as novas linhas no mês certo e mantém todo o resto do arquivo.</div>';
 const last=info.last_export?lastExport(info.last_export):'';
 host.innerHTML='<div class="import-grid for024-grid">'
  +card('<i class="fas fa-file-pdf"></i> Relatório PDF do WebAssist',pdfForm()+'<p class="small text-secondary mt-3 mb-0">Para editar cidade e UF e escolher o tipo de Excel, use o <a href="#painel/monitoramento">Painel de monitoramento</a>.</p>')
  +card('<i class="fas fa-file-excel"></i> FOR 024 · Controle de Roubos e Furtos',template+'<p class="small text-secondary">'+ctx.num(info.imported)+' ocorrência(s) do FOR 024 no sistema (PDF e histórico). Cidade, UF, rastreador e furto/roubo não vêm no PDF: ficam em branco até serem preenchidos no painel de monitoramento.</p><div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" id="for024-export"><i class="fas fa-download"></i> Exportar FOR 024</button><label class="btn btn-outline-secondary mb-0" for="for024-template-file"><i class="fas fa-upload"></i> '+(info.template?'Atualizar modelo':'Enviar modelo FOR 024')+'</label><input id="for024-template-file" type="file" accept=".xlsx" hidden></div>'+last)
  +'</div>'+importsList(info.imports||[])+'<div id="for024-preview" class="mt-4"></div>';
 bindImports(()=>render(ctx));$('#for024-pdf-form').onsubmit=e=>readPdf(e,()=>render(ctx));$('#for024-export').onclick=e=>!info.template?(ctx.toast('Envie primeiro a planilha FOR 024 que será usada como modelo. Depois clique em exportar de novo.',true),$('#for024-template-file').click()):exportFile({modo:'historico'},e.currentTarget,()=>render(ctx));$('#for024-template-file').onchange=e=>uploadTemplate(e,()=>render(ctx));
}
function lastExport(r){
 const skipped=r.skipped||[];return '<details class="mt-3"><summary>Última exportação · '+ctx.esc(ctx.date(r.at))+' · '+ctx.num((r.added||[]).length)+' linha(s) nova(s), '+ctx.num(skipped.length)+' fora</summary>'
  +((r.added||[]).length?'<p class="small mb-1">Acrescentadas: '+r.added.map(a=>ctx.esc(a.placa)+' ('+ctx.esc(ctx.date(a.date))+')').join(', ')+'</p>':'')
  +(skipped.length?'<ul class="small mb-1">'+skipped.map(s=>'<li><span class="plate">'+ctx.esc(s.placa)+'</span> '+ctx.esc(ctx.date(s.date))+' · '+ctx.esc(s.motivo)+'</li>').join('')+'</ul>':'')
  +((r.warnings||[]).length?'<div class="alert alert-warning small mb-0">'+r.warnings.map(ctx.esc).join('<br>')+'</div>':'')+'</details>';
}
async function readPdf(e,onDone){
 e.preventDefault();const form=e.currentTarget,button=form.querySelector('[type=submit]');button.disabled=true;button.textContent='Lendo PDF…';$('#for024-pdf-error').hidden=true;
 try{preview=await ctx.request('pdf_preview',{method:'POST',form:new FormData(form)});if(!ctx.current())return;showPreview(preview,onDone);}
 catch(error){if($('#for024-pdf-error')){$('#for024-pdf-error').textContent=error.message;$('#for024-pdf-error').hidden=false;}}
 finally{if(button.isConnected){button.disabled=false;button.innerHTML='Ler PDF <i class="fas fa-arrow-right"></i>';}}
}
function showPreview(p,onDone){
 const host=$('#for024-preview');const fresh=p.rows.filter(r=>r.status==='nova').length,repeat=p.rows.filter(r=>r.nota).length;
 host.innerHTML='<article class="card"><header class="card-header"><h3 class="card-title">Conferência do PDF · '+ctx.esc(p.filename)+'</h3></header><div class="card-body"><div class="mini-summary"><span><strong>'+ctx.num(p.rows.length)+'</strong>ocorrências no PDF</span><span><strong>'+ctx.num(fresh)+'</strong>novas</span><span><strong>'+ctx.num(p.rows.length-fresh)+'</strong>já importadas</span><span><strong>'+ctx.num(repeat)+'</strong>mesmo veículo</span></div>'
  +'<p class="small text-secondary">'+(p.generated?'Relatório gerado em '+ctx.esc(p.generated)+'. ':'')+'Tipo de veículo previsto pelo histórico da planilha. “Mesmo veículo” entra no sistema, mas não repete no FOR 024, como na planilha manual.</p>'
  +((p.warnings||[]).length?'<div class="alert alert-warning">'+p.warnings.map(ctx.esc).join('<br>')+'</div>':'')
  +'<div id="for024-confirm-error" class="alert alert-danger" hidden role="alert"></div><button class="btn btn-primary" id="for024-confirm" '+(fresh?'':'disabled')+'><i class="fas fa-check"></i> Importar '+ctx.num(fresh)+' ocorrência(s)</button></div>'
  +'<div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Data</th><th>Cliente</th><th>Placa</th><th>Veículo</th><th>Beneficiário</th><th>Protocolo</th><th>Tipo</th><th>Conferência</th></tr></thead><tbody>'
  +p.rows.map(r=>'<tr><td>'+r.line+'</td><td>'+ctx.esc(r.data)+'</td><td>'+ctx.esc(r.cliente)+'</td><td class="plate">'+ctx.esc(r.placa)+'</td><td>'+ctx.esc(r.montadora+' / '+r.modelo)+'</td><td>'+ctx.esc(r.beneficiario)+'</td><td>'+ctx.esc(r.protocolo)+'</td><td>'+ctx.esc(r.tipo_veiculo)+'</td><td>'+ctx.tag(STATUS[r.status]||r.status)+(r.nota?'<small class="d-block text-secondary">'+ctx.esc(r.nota)+'</small>':'')+'</td></tr>').join('')
  +'</tbody></table></div></article>';
 host.scrollIntoView({behavior:'smooth',block:'start'});
 $('#for024-confirm').onclick=async()=>{const button=$('#for024-confirm');button.disabled=true;$('#for024-confirm-error').hidden=true;
  try{const result=await ctx.request('pdf_import',{method:'POST',data:{token:p.token}});ctx.toast(result.inserted+' ocorrência(s) importada(s) do PDF.');preview=null;await onDone(result,p);}
  catch(e){if($('#for024-confirm-error')){$('#for024-confirm-error').textContent=e.message;$('#for024-confirm-error').hidden=false;button.disabled=false;}}};
}
/** params: {modo:'historico'|'sistema'} ou {ids:[…]} (somente as selecionadas). */
async function exportFile(params,button,after=null){
 if(!button)return;if(params.ids&&!params.ids.length)return;
 // Sem modelo no servidor: explica e já abre a escolha da planilha (o botão nunca fica "morto").
 if(monitorData&&!monitorData.template){ctx.toast('Envie primeiro a planilha FOR 024 que será usada como modelo. Depois clique em exportar de novo.',true);$('#monitor-template-file')?.click();return;}
 button.disabled=true;const label=button.innerHTML;button.innerHTML='<i class="fas fa-circle-notch fa-spin"></i> Gerando…';
 const name={historico:'CONTROLE_DE_ROUBOS_E_FURTOS',sistema:'SISTEMA_COMPLETO'}[params.modo]||'SELECIONADAS';
 try{const response=await fetch('api.php?'+new URLSearchParams(params.ids?{action:'for024_export',ids:params.ids.join(',')}:{action:'for024_export',modo:params.modo}),{credentials:'same-origin'});if(!response.ok){let message='Não foi possível exportar o FOR 024.';try{message=(await response.json()).error||message;}catch(e){}throw Error(message);}
  const added=Number(response.headers.get('X-For024-Added')||0),skipped=Number(response.headers.get('X-For024-Skipped')||0);
  const blob=await response.blob(),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='FOR_024_'+name+'_'+new Date().toISOString().slice(0,10)+'.xlsx';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),3000);
  ctx.toast(params.modo==='historico'?'FOR 024 pronto: '+added+' linha(s) nova(s) no modelo completo, '+skipped+' fora (já lançadas ou mesmo veículo).':'Excel pronto com '+added+' ocorrência(s).');if(after)await after();}
 catch(e){ctx.toast(e.message,true);}finally{if(button.isConnected){button.disabled=false;button.innerHTML=label;}}
}
async function uploadTemplate(e,after){
 const input=e.currentTarget;if(!input.files.length)return;const form=new FormData();form.append('file',input.files[0]);
 try{const result=await ctx.request('for024_template',{method:'POST',form});ctx.toast('Modelo FOR 024 atualizado (anos '+result.years.join(', ')+').');await after();}
 catch(error){ctx.toast(error.message,true);}finally{input.value='';}
}

function importsList(rows){
 if(!rows.length)return '';
 return '<article class="card mt-4"><header class="card-header"><h3 class="card-title">Importações do FOR 024</h3></header><div class="table-responsive"><table class="table"><thead><tr><th>Arquivo</th><th>Data</th><th>No arquivo</th><th>Importadas</th><th>No sistema</th><th></th></tr></thead><tbody>'
  +rows.map(r=>'<tr><td><i class="fas fa-'+(r.tipo==='pdf'?'file-pdf':'file-excel')+' text-secondary"></i> '+ctx.esc(r.filename)+'<small>'+ctx.esc(r.user_name)+'</small></td><td>'+ctx.esc(ctx.date(r.created_at))+'</td><td>'+ctx.num(r.total)+'</td><td>'+ctx.num(r.inserted)+'</td><td>'+ctx.num(r.ativas)+'</td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-delete-import="'+Number(r.id)+'" data-count="'+Number(r.ativas)+'"><i class="fas fa-trash-can"></i> Excluir</button></td></tr>').join('')
  +'</tbody></table></div><div class="card-body pt-0"><p class="small text-secondary mb-0">Excluir remove as ocorrências que vieram desse arquivo (inclusive as editadas depois) e libera o reenvio. O FOR 024 já baixado não é alterado.</p></div></article>';
}
function bindImports(after){
 document.querySelectorAll('[data-delete-import]').forEach(button=>{button.onclick=async()=>{
  // Primeiro clique pede confirmação; o segundo exclui.
  if(button.dataset.confirm!=='1'){document.querySelectorAll('[data-delete-import][data-confirm="1"]').forEach(b=>{b.dataset.confirm='';b.innerHTML='<i class="fas fa-trash-can"></i> Excluir';b.classList.replace('btn-danger','btn-outline-danger');});
   button.dataset.confirm='1';button.classList.replace('btn-outline-danger','btn-danger');button.innerHTML='<i class="fas fa-triangle-exclamation"></i> Confirmar: excluir '+ctx.num(Number(button.dataset.count))+' ocorrência(s)';return;}
  button.disabled=true;
  try{const result=await ctx.request('pdf_import_delete',{method:'POST',data:{id:Number(button.dataset.deleteImport)}});ctx.toast(result.deleted+' ocorrência(s) excluída(s). O arquivo pode ser enviado novamente.');await after();}
  catch(e){ctx.toast(e.message,true);button.disabled=false;}
 };});
}

/* ---------- Painel de monitoramento ---------- */
let monitorData=null,selected=new Set(),search='',onlyOpen=false,noCity=false,year=null,charts=[],shown=100;
const FIELD_LABEL={cidade:'Cidade',estado:'UF',tipo:'Tipo',rastreador:'Rastreador',localizado:'Localizado',evento:'Furto/Roubo',recuperado:'Recuperado'};
function metric(label,value,note,icon){return '<article class="card metric"><span class="metric-label">'+ctx.esc(label)+'</span><span class="metric-value">'+ctx.num(value)+'</span><small>'+ctx.esc(note)+'</small><span class="metric-icon"><i class="fas fa-'+icon+'"></i></span></article>';}
/** Quadro do FOR 024 recolhido ou aberto (lembrado neste navegador). */
let stepsCollapsed=false;try{stepsCollapsed=localStorage.getItem('fb_for024_steps')==='min';}catch(e){}
function stepsToggle(){return '<button type="button" class="btn btn-sm btn-ghost-secondary ms-auto" id="monitor-steps-toggle" aria-controls="monitor-steps-body" aria-expanded="'+!stepsCollapsed+'"><i class="fas fa-chevron-'+(stepsCollapsed?'down':'up')+'"></i> '+(stepsCollapsed?'Expandir':'Minimizar')+'</button>';}
function bindStepsToggle(){const b=$('#monitor-steps-toggle');if(!b)return;b.onclick=()=>{stepsCollapsed=!stepsCollapsed;try{localStorage.setItem('fb_for024_steps',stepsCollapsed?'min':'max');}catch(e){}$('#monitor-steps-body').hidden=stepsCollapsed;b.outerHTML=stepsToggle();bindStepsToggle();};}
function monitorActions(){
 const tpl=monitorData&&monitorData.template;
 return '<article class="card monitor-steps"><header class="card-header"><h3 class="card-title"><i class="fas fa-file-excel"></i> FOR 024 · Controle de Roubos e Furtos</h3>'+stepsToggle()+'</header><div class="card-body" id="monitor-steps-body"'+(stepsCollapsed?' hidden':'')+'><div class="monitor-step-grid">'
  +'<section><h4><span class="step-number">1</span> Enviar o PDF do WebAssist</h4>'+pdfForm()+'</section>'
  +'<section><h4><span class="step-number">2</span> Preencher no sistema</h4><p class="small text-secondary">Cidade, UF, rastreador e furto/roubo não vêm no PDF: ficam em branco. Preencha direto na tabela abaixo; cada alteração é salva na hora e vai para o Excel.</p>'
  +'<label class="btn btn-sm btn-outline-secondary mb-0" for="monitor-history-file"><i class="fas fa-file-import"></i> Importar histórico do Excel</label><input id="monitor-history-file" type="file" accept=".xlsx" hidden><p class="small text-secondary mt-2 mb-0">Traz para o sistema as linhas já lançadas no FOR 024 (todas as abas por ano).</p></section>'
  +'<section><h4><span class="step-number">3</span> Exportar o Excel</h4><div class="d-grid gap-2">'
  +'<button type="button" class="btn btn-primary" data-for024-export="historico" title="Planilha modelo inteira, com o histórico dela, mais as ocorrências do PDF ainda não lançadas"><i class="fas fa-layer-group"></i> Modelo completo com histórico</button>'
  +'<button type="button" class="btn btn-outline-primary" data-for024-export="sistema" title="Excel novo com o visual do FOR 024, só com os dados do sistema (histórico importado + PDFs, com as edições)"><i class="fas fa-file-circle-plus"></i> Excel novo com os dados do sistema</button>'
  +'<button type="button" class="btn btn-outline-primary" id="monitor-export-selected" disabled><i class="fas fa-square-check"></i> Somente as selecionadas</button></div>'
  +'<label class="btn btn-sm '+(tpl?'btn-outline-secondary':'btn-warning')+' mt-2 mb-0" for="monitor-template-file" title="Planilha FOR 024 usada como modelo na exportação"><i class="fas fa-upload"></i> '+(tpl?'Atualizar modelo FOR 024':'Enviar modelo FOR 024')+'</label>'+(tpl?'':'<p class="small text-secondary mt-1 mb-0">Necessário uma vez para exportar.</p>')+'<input id="monitor-template-file" type="file" accept=".xlsx" hidden></section>'
  +'</div><div id="for024-preview" class="mt-3"></div></div></article>';
}
async function monitor(context){
 ctx=context;const host=$('#monitor-panel');if(!host)return;
 try{monitorData=await ctx.request('for024_monitor',{query:year?{ano:year}:{}});}catch(e){host.innerHTML='<div class="alert alert-danger">'+ctx.esc(e.message)+'</div>';return;}
 if(!ctx.current()||!$('#monitor-panel'))return;year=monitorData.ano;
 const ids=new Set(monitorData.rows.map(r=>r.id));selected=new Set([...selected].filter(id=>ids.has(id)));
 const t=monitorData.totais,lastPdf=monitorData.imports.find(i=>i.tipo==='pdf');charts.forEach(c=>{try{c.destroy();}catch(e){}});charts=[];
 const years=monitorData.anos.length>1?'<label class="small monitor-year">Ano <select id="monitor-year" class="form-select form-select-sm">'+monitorData.anos.map(y=>'<option'+(y===year?' selected':'')+'>'+y+'</option>').join('')+'</select></label>':'';
 host.innerHTML=monitorActions()
  +(t.total?'<div class="monitor-heading"><h3>Ocorrências de '+year+'</h3>'+years+'</div><div class="metric-grid">'
  +metric('Ocorrências',t.total,ctx.num(t.pdf)+' do PDF · '+ctx.num(t.planilha)+' do histórico','car-burst')
  +metric('Não localizados',t.nao_localizados,t.mais_30?ctx.num(t.mais_30)+' há mais de 30 dias':'Veículo localizado: NÃO','magnifying-glass-location')
  +metric('Localizados',t.localizados,'Veículo localizado ou recuperado','circle-check')
  +metric('Sem cidade',t.sem_cidade,'Preencha na tabela antes de exportar','location-dot')
  +'</div>'
  +'<div class="dashboard-grid"><article class="card"><header class="card-header"><h3 class="card-title">Ocorrências por mês</h3></header><div class="card-body"><div class="plot"><canvas id="monitor-months" aria-label="Ocorrências por mês"></canvas></div></div></article>'
  +'<article class="card"><header class="card-header"><h3 class="card-title">Tipo de veículo</h3></header><div class="card-body"><div class="plot"><canvas id="monitor-types" aria-label="Tipo de veículo"></canvas></div></div></article></div>'
  +'<div class="dashboard-grid"><article class="card"><header class="card-header"><h3 class="card-title">Clientes com mais ocorrências</h3></header><div class="card-body"><div class="plot"><canvas id="monitor-clients" aria-label="Clientes com mais ocorrências"></canvas></div></div></article>'
  +'<article class="card"><header class="card-header"><h3 class="card-title">Cidades</h3></header><div class="card-body"><div class="plot"><canvas id="monitor-cities" aria-label="Cidades com mais ocorrências"></canvas></div></div></article></div>'
  +'<article class="card"><header class="card-header monitor-toolbar"><h3 class="card-title">Ocorrências · '+year+'</h3><div class="monitor-actions"><input id="monitor-search" type="search" class="form-control form-control-sm" placeholder="Placa, cliente, cidade, protocolo…" aria-label="Buscar ocorrência" value="'+ctx.esc(search)+'"><label class="small"><input type="checkbox" id="monitor-open" '+(onlyOpen?'checked':'')+'> Só não localizados</label><label class="small"><input type="checkbox" id="monitor-nocity" '+(noCity?'checked':'')+'> Só sem cidade</label></div></header><div id="monitor-table"></div><div class="monitor-selection" id="monitor-selection" hidden><span id="monitor-selection-text"></span><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="monitor-clear"><i class="fas fa-xmark"></i> Limpar seleção</button><button type="button" class="btn btn-sm btn-success" id="monitor-export-bar"><i class="fas fa-file-excel"></i> Exportar Excel FOR 024</button></div></div></article>'
  :'<article class="card"><div class="card-body text-center py-5"><i class="fas fa-file-pdf fa-2x mb-3 text-secondary"></i><h3 class="card-title">Nenhuma ocorrência do FOR 024 ainda</h3><p class="text-secondary mb-0">Envie o PDF do WebAssist ou importe o histórico do Excel no quadro acima.</p></div></article>')
  +importsList(monitorData.imports);
 bindImports(()=>monitor(ctx));
 $('#for024-pdf-form').onsubmit=e=>readPdf(e,afterMonitorImport);
 bindStepsToggle();$('#monitor-history-file').onchange=uploadHistory;$('#monitor-template-file').onchange=e=>uploadTemplate(e,()=>monitor(ctx));
 host.querySelectorAll('[data-for024-export]').forEach(b=>b.onclick=()=>exportFile({modo:b.dataset.for024Export},b,()=>monitor(ctx)));
 $('#monitor-export-selected').onclick=()=>exportFile({ids:[...selected]},$('#monitor-export-selected'));
 if(!t.total){updateSelected();return;}
 if($('#monitor-year'))$('#monitor-year').onchange=e=>{year=Number(e.target.value);selected.clear();monitor(ctx);};
 drawCharts();drawTable();
 $('#monitor-export-bar').onclick=e=>exportFile({ids:[...selected]},e.currentTarget);$('#monitor-clear').onclick=()=>{selected.clear();drawTable();};
 $('#monitor-search').oninput=e=>{search=e.target.value;shown=100;drawTable();};$('#monitor-open').onchange=e=>{onlyOpen=e.target.checked;shown=100;drawTable();};$('#monitor-nocity').onchange=e=>{noCity=e.target.checked;shown=100;drawTable();};
}
/** Depois de importar o PDF: escolher entre o modelo completo e só as ocorrências deste PDF. */
async function afterMonitorImport(result,p){
 await monitor(ctx);const host=$('#for024-preview');if(!host)return;
 const ids=result.ids||[];selected=new Set(ids);drawTable();
 host.innerHTML='<div class="alert alert-success monitor-choice"><div><strong>'+ctx.num(result.inserted)+' ocorrência(s) importada(s) de '+ctx.esc(p.filename)+'.</strong> Já estão marcadas na tabela. Preencha cidade e UF se quiser e escolha como gerar o Excel:</div><div class="d-flex flex-wrap gap-2 mt-2">'
  +'<button type="button" class="btn btn-primary btn-sm" data-choice="historico"><i class="fas fa-layer-group"></i> Modelo completo com histórico</button>'
  +'<button type="button" class="btn btn-outline-primary btn-sm" data-choice="pdf" '+(ids.length?'':'disabled')+'><i class="fas fa-file-pdf"></i> Somente as deste PDF ('+ctx.num(ids.length)+')</button></div></div>';
 host.querySelector('[data-choice="historico"]').onclick=e=>exportFile({modo:'historico'},e.currentTarget);
 host.querySelector('[data-choice="pdf"]').onclick=e=>exportFile({ids},e.currentTarget);
}
async function uploadHistory(e){
 const input=e.currentTarget;if(!input.files.length)return;const form=new FormData();form.append('file',input.files[0]);const label=input.previousElementSibling,old=label.innerHTML;label.innerHTML='<i class="fas fa-circle-notch fa-spin"></i> Importando histórico…';
 try{const r=await ctx.request('for024_history',{method:'POST',form});ctx.toast(ctx.num(r.inserted)+' linha(s) do histórico importada(s)'+(r.merged?', '+ctx.num(r.merged)+' completaram ocorrências do PDF':'')+(r.skipped?', '+ctx.num(r.skipped)+' já estavam no sistema':'')+'.'+(r.template_salvo?' A planilha também passou a ser o modelo.':''));year=null;await monitor(ctx);}
 catch(error){ctx.toast(error.message,true);if(label.isConnected)label.innerHTML=old;}finally{input.value='';}
}
function drawCharts(){
 if(!window.Chart)return;const css=getComputedStyle(document.documentElement),muted=css.getPropertyValue('--fb-muted').trim(),border=css.getPropertyValue('--fb-border').trim(),palette=['#4263eb','#36a68b','#f0ba62','#d27a86','#83a1f1','#b5bed2','#7c5cc4','#2ea3c7'];
 const add=c=>{charts.push(c);ctx.addChart(c);};
 const axes={x:{grid:{display:false},ticks:{color:muted,font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0,color:muted,font:{size:10}},grid:{color:border}}};
 add(new Chart($('#monitor-months'),{type:'bar',data:{labels:monitorData.por_mes.map(r=>r.label.slice(0,3)),datasets:[{label:'Ocorrências',data:monitorData.por_mes.map(r=>r.total),backgroundColor:'#526fe2',borderRadius:4,maxBarThickness:32}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:axes}}));
 add(new Chart($('#monitor-types'),{type:'doughnut',data:{labels:monitorData.por_tipo.map(r=>r.label),datasets:[{data:monitorData.por_tipo.map(r=>r.total),backgroundColor:palette,borderWidth:0,hoverOffset:5}]},options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{position:'right',labels:{color:muted,usePointStyle:true,pointStyle:'circle',padding:15,font:{size:11}}}}}}));
 const hbar=(id,rows,color)=>add(new Chart($(id),{type:'bar',data:{labels:rows.map(r=>r.label),datasets:[{label:'Ocorrências',data:rows.map(r=>r.total),backgroundColor:color,borderRadius:4,maxBarThickness:22}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0,color:muted,font:{size:10}},grid:{color:border}},y:{grid:{display:false},ticks:{color:muted,font:{size:10}}}}}}));
 hbar('#monitor-clients',monitorData.por_cliente,'#36a68b');hbar('#monitor-cities',monitorData.por_cidade,'#f0ba62');
}
function visibleRows(){const q=search.trim().toLocaleLowerCase('pt-BR');return monitorData.rows.filter(r=>(!onlyOpen||r.em_busca)&&(!noCity||!r.cidade)&&(!q||[r.placa,r.cliente,r.beneficiario,r.protocolo,r.registro,r.veiculo,r.cidade,r.estado,r.tipo].join(' ').toLocaleLowerCase('pt-BR').includes(q)));}
function editor(r,field){
 const options=monitorData.editaveis[field]||[],value=r[field]||'',label=FIELD_LABEL[field]+' de '+r.placa;
 if(!options.length)return '<input class="form-control form-control-sm monitor-edit" data-edit="'+field+'" data-id="'+r.id+'" value="'+ctx.esc(value)+'" maxlength="120" placeholder="—" aria-label="'+ctx.esc(label)+'">';
 const list=options.includes(value)||!value?options:[value,...options];
 return '<select class="form-select form-select-sm monitor-edit" data-edit="'+field+'" data-id="'+r.id+'" aria-label="'+ctx.esc(label)+'"><option value="">—</option>'+list.map(o=>'<option'+(o===value?' selected':'')+'>'+ctx.esc(o)+'</option>').join('')+'</select>';
}
function updateSelected(){const bar=$('#monitor-selection');if(bar){bar.hidden=!selected.size;$('#monitor-selection-text').innerHTML='<strong>'+ctx.num(selected.size)+'</strong> ocorrência(s) selecionada(s) · Excel no modelo FOR 024 (mesmo visual, cabeçalho e estilos)';const b=$('#monitor-export-bar');b.disabled=false;b.title=monitorData.template?'':'Envie o modelo FOR 024 para exportar';}const b=$('#monitor-export-selected');if(b){b.disabled=!selected.size;b.innerHTML='<i class="fas fa-square-check"></i> Somente as selecionadas'+(selected.size?' ('+ctx.num(selected.size)+')':'');}}
function drawTable(){
 const host=$('#monitor-table');if(!host){updateSelected();return;}const visible=visibleRows(),rows=visible.slice(0,shown),all=visible.length&&visible.every(r=>selected.has(r.id));
 host.innerHTML=rows.length?'<div class="table-responsive"><table class="table table-hover monitor-table"><thead><tr><th class="monitor-check"><input type="checkbox" id="monitor-select-all" aria-label="Selecionar todas as ocorrências visíveis" '+(all?'checked':'')+'></th><th>Registro / placa</th><th>Data</th><th>Cliente / beneficiário</th><th>Veículo</th><th>Cidade</th><th>UF</th><th>Tipo</th><th>Rastreador</th><th>Localizado</th><th>Furto/Roubo</th></tr></thead><tbody>'
  +rows.map(r=>'<tr class="'+(selected.has(r.id)?'table-active':'')+'"><td class="monitor-check"><input type="checkbox" data-select="'+r.id+'" aria-label="Selecionar '+ctx.esc(r.placa)+'" '+(selected.has(r.id)?'checked':'')+'></td><td><a href="#ocorrencias" class="record-link" data-open="'+r.id+'">'+ctx.esc(r.registro)+'</a><small><span class="plate">'+ctx.esc(r.placa)+'</span> · '+ctx.esc(r.origem)+(r.protocolo?' · '+ctx.esc(r.protocolo):'')+'</small></td><td>'+(r.data?ctx.esc(ctx.date(r.data)):ctx.esc(r.data_texto||'—'))+(r.em_busca&&r.dias>30?'<small class="text-danger">'+ctx.num(r.dias)+' dias</small>':'')+'</td><td>'+ctx.esc(r.cliente)+'<small>'+ctx.esc(r.beneficiario)+'</small></td><td>'+ctx.esc(r.veiculo)+'</td>'
   +['cidade','estado','tipo','rastreador','localizado','evento'].map(f=>'<td class="monitor-cell-'+f+'">'+editor(r,f)+'</td>').join('')+'</tr>').join('')
  +'</tbody></table></div>'+(visible.length>rows.length?'<div class="card-body pt-2 text-center"><button type="button" class="btn btn-sm btn-outline-secondary" id="monitor-more">Mostrar mais ('+ctx.num(visible.length-rows.length)+' restantes)</button></div>':''):'<div class="card-body text-secondary">Nenhuma ocorrência com esse filtro.</div>';
 updateSelected();if($('#monitor-more'))$('#monitor-more').onclick=()=>{shown+=100;drawTable();};
 host.querySelectorAll('[data-select]').forEach(c=>c.onchange=()=>{const id=Number(c.dataset.select);if(c.checked)selected.add(id);else selected.delete(id);c.closest('tr').classList.toggle('table-active',c.checked);const head=$('#monitor-select-all');if(head)head.checked=visibleRows().every(r=>selected.has(r.id));updateSelected();});
 const head=$('#monitor-select-all');if(head)head.onchange=()=>{for(const r of visibleRows()){if(head.checked)selected.add(r.id);else selected.delete(r.id);}drawTable();};
 host.querySelectorAll('[data-edit]').forEach(el=>el.onchange=()=>saveField(el));
}
async function saveField(el){
 const id=Number(el.dataset.id),field=el.dataset.edit,row=monitorData.rows.find(r=>r.id===id);if(!row)return;const value=el.value.trim().toLocaleUpperCase('pt-BR');
 el.classList.remove('is-invalid','is-valid');el.disabled=true;
 try{const r=await ctx.request('for024_update',{method:'POST',data:{id,field,value}});Object.assign(row,{cidade:r.planilha.cidade,estado:r.planilha.estado,tipo:r.planilha.tipo,rastreador:r.planilha.rastreador,localizado:r.planilha.localizado,evento:r.planilha.evento,recuperado:r.planilha.recuperado});row.em_busca=!(row.localizado==='SIM'||row.recuperado);
  if(el.tagName==='INPUT')el.value=row[field]||'';el.classList.add('is-valid');setTimeout(()=>el.classList.remove('is-valid'),1500);
  const loc=el.closest('tr')?.querySelector('[data-edit="localizado"]');if(loc&&field!=='localizado')loc.value=row.localizado||'';}
 catch(e){el.classList.add('is-invalid');el.value=row[field]||'';ctx.toast(e.message,true);}finally{el.disabled=false;}
}
window.InfinityFor024={render,monitor};
})();
