const {chromium}=require('../../tmp/amcharts-tests/node_modules/playwright');
const {execFileSync,spawn}=require('node:child_process');
const path=require('node:path');
const assert=require('node:assert/strict');
const http=require('node:http');
const fs=require('node:fs');
const os=require('node:os');
const php='C:/xampp/php/php.exe',root=path.resolve(__dirname,'..');
(async()=>{
 const fixture=JSON.parse(execFileSync(php,[path.join(__dirname,'users.php'),'--keep'],{encoding:'utf8'}));
 const sessions=fs.mkdtempSync(path.join(os.tmpdir(),'fb-user-sessions-'));
 const server=spawn(php,['-d','session.save_path='+sessions,'-S','127.0.0.1:8974','-t',root],{cwd:root,env:{...process.env,DB_NAME:fixture.database},windowsHide:true,stdio:'ignore'});
 let browser;const base='http://127.0.0.1:8974/';const errors=[];
 try{
  for(let i=0;i<30;i++){const ready=await new Promise(resolve=>{http.get(base,res=>{res.resume();resolve(res.statusCode===200);}).on('error',()=>resolve(false));});if(ready)break;await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
  async function login(email,ready='#chart-months'){const context=await browser.newContext();const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(base);await page.locator('#login-email').fill(email);await page.locator('#login-password').fill(fixture.password);await page.locator('#login-form button').click();await page.locator(ready).waitFor();return {context,page};}
  async function api(context,action,data){const session=await (await context.request.get(base+'api.php?action=session')).json();return data===undefined?context.request.get(base+'api.php?action='+action):context.request.post(base+'api.php?action='+action,{data,headers:{'X-CSRF-Token':session.csrf}});}
  const admin=await login('admin@test.invalid');assert.match(await admin.page.locator('.overview-welcome h2').innerText(),/Administrador de teste/);
  await admin.page.goto(base+'#painel/monitoramento');await admin.page.locator('#monitor-table tbody tr').waitFor();assert.match(await admin.page.locator('.overview-welcome p').innerText(),new RegExp('Monitoramento.*'+new Date().getFullYear()));assert.match(await admin.page.locator('.overview-welcome-metrics').innerText(),/Localizados/);assert.equal(await admin.page.locator('.overview-summary').count(),2);
  for(const width of [1440,390]){await admin.page.setViewportSize({width,height:900});await admin.page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth,{},{timeout:5000});assert(await admin.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),JSON.stringify(await admin.page.evaluate(()=>[...document.querySelectorAll('body *')].filter(el=>el.getBoundingClientRect().right>innerWidth+1&&getComputedStyle(el).display!=='none').slice(0,15).map(el=>({tag:el.tagName,cls:el.className,width:el.getBoundingClientRect().width})))));}
  await admin.page.locator('[data-monitor-records]').first().click();assert.match(admin.page.url(),/#painel\/monitoramento$/);
  await admin.page.goto(base+'#usuarios');await admin.page.locator('#user-new').click();
  const form=admin.page.locator('#user-form');await form.locator('[name=name]').fill('Novo usuário');await form.locator('[name=email]').fill('new@test.invalid');await form.locator('[name=password]').fill(fixture.password);await form.locator('[name=role]').selectOption('viewer');await form.locator('[type=submit]').click();await admin.page.getByRole('strong').filter({hasText:'Novo usuário'}).waitFor();
  const listed=await (await api(admin.context,'users')).json();const created=listed.rows.find(u=>u.email==='new@test.invalid');assert(created);assert.deepEqual(created.permissions,['dashboard','monitor','read']);
  await admin.page.locator('[data-user-edit="'+created.id+'"]').click();await form.locator('[name=permission][value=dashboard]').uncheck();await form.locator('[name=permission][value=read]').uncheck();await form.locator('[type=submit]').click();await admin.page.locator('#user-editor').waitFor({state:'hidden'});
  const restricted=await login('new@test.invalid','#monitor-table tbody tr');assert.match(restricted.page.url(),/#painel\/monitoramento$/);assert.equal((await api(restricted.context,'stats')).status(),403);assert.equal((await api(restricted.context,'list')).status(),403);
  const viewer=await login('viewer@test.invalid');assert.equal(await viewer.page.locator('[data-route=usuarios]').isVisible(),false);assert.equal(await viewer.page.locator('#global-create').isVisible(),false);assert.equal(await viewer.page.locator('#global-export').isVisible(),false);
  await viewer.page.goto(base+'#painel/monitoramento');await viewer.page.locator('#monitor-table tbody tr').waitFor();assert.equal(await viewer.page.locator('.monitor-edit').count(),0);assert.equal(await viewer.page.locator('#for024-pdf-form').isVisible(),false);
  for(const [action,data] of [['save',{}],['for024_update',{}],['user_save',{}],['settings',{}],['appearance',{}],['pdf_import_delete',{}],['pdf_import',{}],['password',{current_password:'wrong',new_password:fixture.password}]]){const response=await api(viewer.context,action,data);assert.equal(response.status(),action==='password'?422:403,action);}
  assert.equal((await api(viewer.context,'export')).status(),403);assert.equal((await api(viewer.context,'users')).status(),403);
  await viewer.page.goto(base+'#configuracoes');await viewer.page.locator('#password-form').waitFor();assert.equal(await viewer.page.locator('#password-form').isVisible(),true);assert.equal(await viewer.page.locator('#ia-settings').isVisible(),false);
  const operator=await login('operator@test.invalid');assert.equal((await api(operator.context,'users')).status(),403);assert.equal((await api(operator.context,'pdf_import_delete',{})).status(),403);
  await operator.page.goto(base+'#painel/monitoramento');await operator.page.locator('.monitor-edit').first().waitFor();assert.equal(await operator.page.locator('#for024-pdf-form').isVisible(),true);
  const operatorUser=listed.rows.find(u=>u.email==='operator@test.invalid');let response=await api(admin.context,'user_save',{...operatorUser,active:false});assert.equal(response.status(),200);assert.equal((await api(operator.context,'list')).status(),401);
  const viewerUser=listed.rows.find(u=>u.email==='viewer@test.invalid');response=await api(admin.context,'user_save',{...viewerUser,password:fixture.password+'X'});assert.equal(response.status(),200);assert.equal((await api(viewer.context,'list')).status(),401);
  const monitor=await login('monitor@test.invalid','#monitor-table tbody tr');assert.match(monitor.page.url(),/#painel\/monitoramento$/);
  await monitor.page.locator('[data-nav-toggle]').click();assert.equal(await monitor.page.locator('[data-subroute=monitoramento]').isVisible(),true);
  assert.equal(await monitor.page.locator('[data-subroute=geral]').isVisible(),false);assert.equal(await monitor.page.locator('[data-route=ocorrencias]').isVisible(),false);assert.equal(await monitor.page.locator('[data-route=importacao]').isVisible(),false);assert.equal(await monitor.page.locator('#global-create').isVisible(),false);assert.equal(await monitor.page.locator('#global-export').isVisible(),true);assert.equal(await monitor.page.locator('#for024-pdf-form').isVisible(),false);assert(await monitor.page.locator('.monitor-edit').count()>0);
  for(const [action,data] of [['stats'],['list'],['get&id=1'],['export'],['save',{}],['preview',{}],['pdf_preview',{}],['chat',{}]])assert.equal((await api(monitor.context,action,data)).status(),403,action);
  assert.equal((await api(monitor.context,'for024_update',{id:1,field:'cidade',value:'Cidade de teste'})).status(),200);
  await monitor.page.goto(base+'#painel');await monitor.page.locator('#page-content .alert-danger').waitFor();assert.equal(await monitor.page.locator('#chart-months').count(),0);
  const main=await login('main@test.invalid');assert.equal(await main.page.locator('[data-subroute=monitoramento]').isVisible(),false);assert.equal(await main.page.locator('[data-route=ocorrencias]').isVisible(),false);assert.equal((await api(main.context,'stats')).status(),200);assert.equal((await api(main.context,'for024_monitor')).status(),403);assert.equal((await api(main.context,'for024_export')).status(),403);
  await main.page.locator('[data-nav-toggle]').click();assert.equal(await main.page.locator('[data-subroute=geral]').isVisible(),true);assert.equal(await main.page.locator('[data-subroute=monitoramento]').isVisible(),false);
  await main.page.goto(base+'#painel/monitoramento');await main.page.locator('#page-content .alert-danger').waitFor();assert.equal(await main.page.locator('#monitor-table').count(),0);
  const both=await login('both@test.invalid');await both.page.goto(base+'#painel/monitoramento');await both.page.locator('#monitor-table tbody tr').waitFor();assert.equal(await both.page.locator('.monitor-edit').count(),0);assert.equal((await api(both.context,'list')).status(),403);
  const records=await login('records@test.invalid','#list-section tbody tr');assert.match(records.page.url(),/#ocorrencias$/);assert.equal(await records.page.locator('[data-nav-toggle]').isVisible(),false);assert.equal((await api(records.context,'stats')).status(),403);assert.equal((await api(records.context,'for024_monitor')).status(),403);
  const noCsrf=await admin.context.request.post(base+'api.php?action=user_save',{data:{}});assert.equal(noCsrf.status(),403);
  await admin.page.goto(base+'#usuarios');await admin.page.locator('#user-new').waitFor();for(const width of [1440,390]){await admin.page.setViewportSize({width,height:900});await admin.page.locator('#user-new').click();assert(await admin.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));}
  assert.deepEqual(errors,[]);console.log(`${fixture.checks} verificações de banco; navegador: cadastro, perfis, bloqueios API, CSRF, revogação de sessão e celular aprovados.`);
 }finally{
  if(browser)await browser.close();server.kill();
  const cleanup="require $argv[1].'/app/bootstrap.php'; $name=$argv[2]; if(!preg_match('/^fb_users_test_[a-f0-9]{12}$/D',$name))exit(1); $db=new PDO('mysql:host='.fb_env('DB_HOST','127.0.0.1').';port='.fb_env('DB_PORT','3306'),fb_env('DB_USER'),fb_env('DB_PASS')); $db->exec('DROP DATABASE `'.$name.'`');";
  execFileSync(php,['-r',cleanup,root,fixture.database],{stdio:'pipe'});
  if(path.dirname(sessions)===path.resolve(os.tmpdir())&&path.basename(sessions).startsWith('fb-user-sessions-'))fs.rmSync(sessions,{recursive:true});
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
