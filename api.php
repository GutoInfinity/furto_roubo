<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Appearance.php';
try{
 $method=$_SERVER['REQUEST_METHOD'];$action=(string)($_GET['action']??'');
 if($action==='session'&&$method==='GET')fb_json(['success'=>true,'user'=>$_SESSION['fb_user']??null,'csrf'=>fb_csrf()]);
 if($action==='login'&&$method==='POST'){
  fb_verify_csrf();$data=fb_input();$db=fb_db();$email=mb_strtolower(trim((string)($data['email']??'')));$q=$db->prepare('SELECT * FROM fb_users WHERE email=?');$q->execute([$email]);$user=$q->fetch();
  if($user&&!empty($user['locked_until'])&&strtotime($user['locked_until'])>time())fb_json(['error'=>'Muitas tentativas. Aguarde 15 minutos para tentar novamente.'],429);
  $password=(string)($data['password']??'');if(strlen($password)>1000||!$user||!password_verify($password,$user['password_hash']??'$2y$10$X5mbz2lP1z5UPVi0VEcK9OL1kUnRz/5NvxSyrB3DcQpHKHSDh.h9K')){
   if($user){$attempts=(int)$user['failed_attempts']+1;$q=$db->prepare('UPDATE fb_users SET failed_attempts=?,locked_until=? WHERE id=?');$q->execute([$attempts,$attempts>=8?date('Y-m-d H:i:s',time()+900):null,$user['id']]);}fb_json(['error'=>'E-mail ou senha incorretos.'],401);
  }
  $db->prepare('UPDATE fb_users SET failed_attempts=0,locked_until=NULL WHERE id=?')->execute([$user['id']]);session_regenerate_id(true);$_SESSION['fb_user']=['id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email']];unset($_SESSION['fb_csrf']);fb_json(['success'=>true,'user'=>$_SESSION['fb_user'],'csrf'=>fb_csrf()]);
 }
 $user=fb_user();$db=fb_db();if($method==='POST')fb_verify_csrf();elseif($method!=='GET')fb_json(['error'=>'Método não permitido.'],405);
 if($action==='logout'&&$method==='POST'){$_SESSION=[];session_regenerate_id(true);fb_json(['success'=>true]);}
 if($action==='list'&&$method==='GET'){$page=max(1,(int)($_GET['page']??1));fb_json(Records::list($db,$_GET,25,($page-1)*25)+['page'=>$page,'per_page'=>25]);}
 if($action==='stats'&&$method==='GET')fb_json(Records::stats($db,$_GET));
 if($action==='options'&&$method==='GET')fb_json(Records::options($db));
 if($action==='appearance'&&$method==='GET')fb_json(['appearance'=>Appearance::get($db),'defaults'=>Appearance::defaults()]);
 if($action==='appearance'&&$method==='POST'){$clean=Appearance::validate(fb_input());$q=$db->prepare('INSERT INTO fb_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['appearance',json_encode($clean,JSON_THROW_ON_ERROR)]);fb_json(['success'=>true,'appearance'=>$clean]);}
 if($action==='get'&&$method==='GET'){$id=(int)($_GET['id']??0);$record=Records::get($db,$id);$record['original']=json_decode($record['original_json']??'null',true);unset($record['original_json']);fb_json(['record'=>$record,'history'=>Records::history($db,$id)]);}
 if($action==='save'&&$method==='POST'){$data=fb_input();$id=!empty($data['id'])?(int)$data['id']:null;fb_json(['success'=>true,'record'=>Records::save($db,$data,$user['id'],$id,isset($data['revision'])?(int)$data['revision']:null)]);}
 if($action==='preview'&&$method==='POST'){
  $upload=$_FILES['file']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Selecione uma planilha válida. Confira também o limite de upload da hospedagem.');$name=basename($upload['name']);$parsed=Spreadsheets::read($upload['tmp_name'],$name);$hash=hash_file('sha256',$upload['tmp_name']);$plan=Spreadsheets::plan($db,$parsed,$hash);$token=bin2hex(random_bytes(16));$plan['name']=$name;$plan['user']=$user['id'];$plan['expires']=time()+7200;
  if(!move_uploaded_file($upload['tmp_name'],FB_ROOT.'/storage/imports/'.$token.'.'.strtolower(pathinfo($name,PATHINFO_EXTENSION))))throw new RuntimeException('Não foi possível guardar a planilha.');
  if(file_put_contents(FB_ROOT.'/storage/imports/'.$token.'.json',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Não foi possível preparar a conferência.');
  $_SESSION['fb_imports'][$token]=true;$samples=[];foreach(array_slice($plan['rows'],0,25) as $r)$samples[]=['line'=>$r['line'],'sheet'=>$r['sheet'],'data'=>$r['data'],'already'=>$r['already'],'duplicate'=>$r['duplicate'],'system_id'=>$r['system_id'],'invalid_id'=>$r['invalid_id']??false];
  fb_json(['token'=>$token,'filename'=>$name,'total'=>count($plan['rows']),'errors'=>array_slice($plan['errors'],0,100),'error_count'=>count($plan['errors']),'duplicate_count'=>$plan['duplicates'],'skipped'=>$plan['skipped'],'updates'=>$plan['updates'],'warning_count'=>count(array_filter($plan['rows'],static fn($r)=>$r['data']['avisos_json']!=='[]')),'rows'=>$samples]);
 }
 if($action==='import'&&$method==='POST'){$data=fb_input();$token=(string)($data['token']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$token)||empty($_SESSION['fb_imports'][$token]))throw new DomainException('Prévia de importação inválida.');$path=FB_ROOT.'/storage/imports/'.$token.'.json';$plan=is_file($path)?json_decode(file_get_contents($path),true):null;if(!$plan||$plan['user']!==$user['id']||$plan['expires']<time())throw new InvalidArgumentException('A prévia expirou. Envie a planilha novamente.');$result=Spreadsheets::commit($db,$plan,$user['id'],$plan['name'],!empty($data['update']),!empty($data['duplicates']));unset($_SESSION['fb_imports'][$token]);unlink($path);fb_json(['success'=>true]+$result);}
 if($action==='imports'&&$method==='GET')fb_json(['rows'=>$db->query('SELECT i.*,u.name user_name FROM fb_imports i JOIN fb_users u ON u.id=i.user_id ORDER BY i.id DESC LIMIT 30')->fetchAll()]);
 if($action==='export'&&$method==='GET'){
  $format=($_GET['format']??'xlsx')==='csv'?'csv':'xlsx';$template=($_GET['template']??'')==='1';$records=[];if(!$template){$list=Records::list($db,$_GET,50000);if($list['total']>50000)throw new InvalidArgumentException('Refine os filtros para exportar até 50.000 registros.');$records=$list['rows'];}$file=tempnam(sys_get_temp_dir(),'fb_export_');
  try{Spreadsheets::export($records,$format,$file,$template);header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=utf-8'));header('Content-Disposition: attachment; filename="'.($template?'modelo-furto-roubo':'ocorrencias-'.date('Ymd-His')).'.'.$format.'"');header('Content-Length: '.filesize($file));readfile($file);}finally{unlink($file);}exit;
 }
 if($action==='chat'&&$method==='POST'){$data=fb_input();$scope=is_array($data['filters']??null)?$data['filters']:[];$conversation=!empty($data['conversation_id'])?(int)$data['conversation_id']:null;if($conversation)Conversations::find($db,$user['id'],$conversation);session_write_close();fb_json(Assistant::ask($db,$user['id'],(string)($data['question']??''),$scope,$conversation));}
 if($action==='conversations'&&$method==='GET')fb_json(['rows'=>Conversations::list($db,$user['id'])]);
 if($action==='conversation'&&$method==='GET')fb_json(Conversations::history($db,$user['id'],(int)($_GET['id']??0)));
 if($action==='conversation_delete'&&$method==='POST'){$data=fb_input();Conversations::delete($db,$user['id'],(int)($data['id']??0));fb_json(['success'=>true]);}
 if($action==='chat_history'&&$method==='GET'){$q=$db->prepare('SELECT id,question,answer,created_at FROM fb_chat WHERE user_id=? ORDER BY id DESC LIMIT 30');$q->execute([$user['id']]);$rows=array_reverse($q->fetchAll());foreach($rows as &$row)$row['answer']=json_decode($row['answer'],true);fb_json(['rows'=>$rows]);}
 if($action==='settings'&&$method==='GET')fb_json(['ia_configured'=>Assistant::configured($db),'model'=>Assistant::model($db),'database'=>fb_env('DB_NAME'),'user'=>$user]);
 if($action==='settings'&&$method==='POST'){$data=fb_input();$model=trim((string)($data['model']??''));if($model!==''&&!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$model))throw new InvalidArgumentException('Nome do modelo inválido.');$db->beginTransaction();try{if(array_key_exists('model',$data)){$q=$db->prepare('INSERT INTO fb_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['assistant_model',$model]);}if(trim((string)($data['key']??''))!==''){$key=trim((string)$data['key']);if(strlen($key)>500||preg_match('/[\r\n]/',$key))throw new InvalidArgumentException('Chave inválida.');$q=$db->prepare('INSERT INTO fb_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['assistant_key',Assistant::encrypt($key)]);}
   if(!empty($data['new_password'])){$q=$db->prepare('SELECT password_hash FROM fb_users WHERE id=?');$q->execute([$user['id']]);if(!password_verify((string)($data['current_password']??''),$q->fetchColumn()))throw new InvalidArgumentException('A senha atual está incorreta.');$password=(string)$data['new_password'];if(strlen($password)<6||strlen($password)>200)throw new InvalidArgumentException('Use uma senha de 6 a 200 caracteres.');$db->prepare('UPDATE fb_users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]);session_regenerate_id(true);}$db->commit();fb_json(['success'=>true]);}catch(Throwable $e){$db->rollBack();throw $e;}}
 // ---- PDF do WebAssist → sistema → FOR 024 ----
 if($action==='pdf_preview'&&$method==='POST'){
  $upload=$_FILES['file']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Selecione o PDF do relatório. Confira também o limite de upload da hospedagem.');
  $name=basename((string)$upload['name']);if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf')throw new InvalidArgumentException('Envie o relatório em PDF.');if($upload['size']>10*1024*1024)throw new InvalidArgumentException('O PDF deve ter até 10 MB.');
  $plan=For024::preview($db,$upload['tmp_name'],$name);$token=bin2hex(random_bytes(16));$plan['user']=$user['id'];$plan['expires']=time()+7200;$plan['hash']=hash_file('sha256',$upload['tmp_name']);
  if(file_put_contents(FB_ROOT.'/storage/imports/'.$token.'.pdf.json',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Não foi possível preparar a conferência.');
  $_SESSION['fb_pdf_imports'][$token]=true;fb_json(['token'=>$token]+$plan);
 }
 if($action==='pdf_import'&&$method==='POST'){
  $data=fb_input();$token=(string)($data['token']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$token)||empty($_SESSION['fb_pdf_imports'][$token]))throw new DomainException('Prévia do PDF inválida.');
  $path=FB_ROOT.'/storage/imports/'.$token.'.pdf.json';$plan=is_file($path)?json_decode((string)file_get_contents($path),true):null;if(!$plan||$plan['user']!==$user['id']||$plan['expires']<time())throw new InvalidArgumentException('A prévia expirou. Envie o PDF novamente.');
  $result=For024::commit($db,$plan,$user['id'],$plan['hash']);unset($_SESSION['fb_pdf_imports'][$token]);unlink($path);fb_json(['success'=>true]+$result);
 }
 if($action==='for024'&&$method==='GET'){
  $info=null;if(ControlWorkbook::hasTemplate()){try{$info=ControlWorkbook::inspect(ControlWorkbook::templatePath());}catch(Throwable $e){$info=null;}}
  $rows=For024::rows($db);$last=$db->query("SELECT setting_value FROM fb_settings WHERE setting_key='for024_last_export'")->fetchColumn();
  fb_json(['template'=>$info?['years'=>array_keys($info['years']),'updated_at'=>date('c',(int)filemtime(ControlWorkbook::templatePath()))]:null,'imported'=>count($rows['rows']),'last_export'=>$last?json_decode((string)$last,true):null,'imports'=>For024::imports($db)]);
 }
 if($action==='for024_template'&&$method==='POST'){
  $upload=$_FILES['file']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Selecione a planilha FOR 024 (.xlsx).');
  if(strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION))!=='xlsx')throw new InvalidArgumentException('Envie a planilha FOR 024 em formato .xlsx.');if($upload['size']>20*1024*1024)throw new InvalidArgumentException('A planilha deve ter até 20 MB.');
  $info=ControlWorkbook::saveTemplate($upload['tmp_name']);fb_json(['success'=>true,'years'=>array_keys($info['years'])]);
 }
 if($action==='pdf_import_delete'&&$method==='POST'){$data=fb_input();$id=(int)($data['id']??0);if($id<1)throw new InvalidArgumentException('Importação inválida.');fb_json(['success'=>true]+For024::deleteImport($db,$id));}
 if($action==='for024_monitor'&&$method==='GET')fb_json(For024::monitor($db,isset($_GET['ano'])?(int)$_GET['ano']:null));
 if($action==='for024_update'&&$method==='POST'){$data=fb_input();fb_json(['success'=>true]+For024::update($db,(int)($data['id']??0),(string)($data['field']??''),(string)($data['value']??''),$user['id']));}
 if($action==='for024_history'&&$method==='POST'){
  // Histórico já lançado no FOR 024 → sistema. Sem modelo instalado, o arquivo também passa a ser o modelo.
  $upload=$_FILES['file']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Selecione a planilha FOR 024 (.xlsx).');
  $name=basename((string)$upload['name']);if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx')throw new InvalidArgumentException('Envie a planilha FOR 024 em formato .xlsx.');if($upload['size']>20*1024*1024)throw new InvalidArgumentException('A planilha deve ter até 20 MB.');
  ControlWorkbook::inspect($upload['tmp_name']);$template=false;if(!ControlWorkbook::hasTemplate()){ControlWorkbook::saveTemplate($upload['tmp_name']);$template=true;}
  fb_json(['success'=>true,'template_salvo'=>$template]+For024::importHistory($db,$upload['tmp_name'],$name,$user['id']));
 }
 if($action==='for024_export'&&$method==='GET'){
  // ids=1,2,3 exporta somente as ocorrências selecionadas no painel de monitoramento.
  $only=null;if(isset($_GET['ids'])){$raw=(string)$_GET['ids'];if(strlen($raw)>60000||!preg_match('/^\d+(?:,\d+)*$/D',$raw))throw new InvalidArgumentException('Seleção inválida.');$only=array_values(array_unique(array_map('intval',explode(',',$raw))));if(count($only)>5000)throw new InvalidArgumentException('Selecione até 5.000 ocorrências.');}
  // modo=historico: planilha modelo completa (com o histórico dela) + ocorrências do PDF ainda não lançadas.
  // modo=sistema: Excel novo, só com os dados do sistema (histórico importado + PDF, com as edições). ids: só as selecionadas.
  $mode=$only!==null?'selecionadas':(($_GET['modo']??'')==='sistema'?'sistema':'historico');
  $data=$mode==='historico'?For024::rows($db,null,[For024::ORIGIN]):For024::rows($db,$only);if($mode!=='historico'&&!$data['rows'])throw new InvalidArgumentException('Nenhuma ocorrência do FOR 024 para exportar.');$file=tempnam(sys_get_temp_dir(),'fb_for024_');
  try{$result=$mode==='historico'?ControlWorkbook::export($data['rows'],$file):ControlWorkbook::exportOnly($data['rows'],$file);
   $summary=['at'=>date('c'),'added'=>array_map(fn($r)=>['placa'=>$r['placa'],'date'=>$r['date']??'','aba'=>$r['aba']??''],$result['added']),'skipped'=>array_map(fn($r)=>['placa'=>$r['placa'],'date'=>$r['date']??'','protocolo'=>$r['protocolo']??'','motivo'=>$r['motivo']],$result['skipped']),'warnings'=>array_merge($data['warnings'],$result['warnings'])];
   if($mode==='historico'){$q=$db->prepare('INSERT INTO fb_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['for024_last_export',json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="FOR_024_'.['selecionadas'=>'SELECIONADAS','sistema'=>'SISTEMA_COMPLETO','historico'=>'CONTROLE_DE_ROUBOS_E_FURTOS'][$mode].'_'.date('Y-m-d').'.xlsx"');header('Content-Length: '.filesize($file));header('X-For024-Added: '.count($result['added']));header('X-For024-Skipped: '.count($result['skipped']));readfile($file);
  }finally{@unlink($file);}exit;
 }
 fb_json(['error'=>'Operação não encontrada.'],404);
}catch(DomainException $e){fb_json(['error'=>$e->getMessage()],empty($_SESSION['fb_user'])?401:403);}
catch(OutOfBoundsException $e){fb_json(['error'=>$e->getMessage()],404);}
catch(UnexpectedValueException $e){fb_json(['error'=>$e->getMessage()],409);}
catch(InvalidArgumentException $e){fb_json(['error'=>$e->getMessage()],422);}
catch(Throwable $e){fb_log($e);fb_json(['error'=>'Não foi possível concluir. Confira a conexão e as permissões do novo sistema.'],500);}
