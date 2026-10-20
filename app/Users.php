<?php
declare(strict_types=1);
final class Users {
 public const PERMISSIONS=['dashboard'=>'Acessar o Painel principal','monitor'=>'Acessar o Monitoramento','read'=>'Acessar a base geral de ocorrências','write'=>'Cadastrar e editar na base geral','import'=>'Importar planilhas na base geral','export'=>'Exportar a base geral','monitor_write'=>'Editar campos do Monitoramento','monitor_import'=>'Importar PDFs, histórico e modelo FOR 024','monitor_export'=>'Exportar o Monitoramento / FOR 024','delete_import'=>'Excluir importações do Monitoramento','assistant'=>'Usar IA com acesso à base geral','settings'=>'Configurar IA e aparência','users'=>'Administrar usuários e permissões'];
 public const GROUPS=['Painéis'=>['dashboard','monitor'],'Base geral de ocorrências'=>['read','write','import','export','assistant'],'Monitoramento / FOR 024'=>['monitor_write','monitor_import','monitor_export','delete_import'],'Administração'=>['settings','users']];
 public const ROLES=['admin'=>'Administrador','operator'=>'Operador','viewer'=>'Consulta'];
 public static function defaults(string $role):array {
  if($role==='admin')return array_keys(self::PERMISSIONS);
  return $role==='operator'?['dashboard','monitor','read','write','import','export','monitor_write','monitor_import','monitor_export','assistant']:['dashboard','monitor','read'];
 }
 public static function present(array $row):array {
  $role=(string)$row['role'];$permissions=$row['permissions_json']===null?self::defaults($role):json_decode($row['permissions_json'],true);
  // Listas antigas preservam os acessos anteriores. Novas gravações usam versão explícita,
  // permitindo desmarcar ambos os painéis sem reabilitá-los no próximo login.
  if(is_array($permissions)&&isset($permissions['version']))$permissions=$permissions['permissions']??[];
  elseif($row['permissions_json']!==null&&is_array($permissions)){
   if(in_array('read',$permissions,true))$permissions=array_merge($permissions,['dashboard','monitor']);
   foreach(['write'=>'monitor_write','import'=>'monitor_import','export'=>'monitor_export'] as $old=>$new)if(in_array($old,$permissions,true))$permissions[]=$new;
  }
  if(!is_array($permissions))$permissions=[];
  return ['id'=>(int)$row['id'],'name'=>$row['name'],'email'=>$row['email'],'role'=>$role,'active'=>(bool)$row['active'],'permissions'=>$role==='admin'?self::defaults('admin'):array_values(array_intersect(array_keys(self::PERMISSIONS),$permissions)),'session_version'=>(int)$row['session_version']];
 }
 public static function find(PDO $db,int $id):?array {
  $q=$db->prepare('SELECT u.id,u.name,u.email,a.* FROM fb_users u JOIN fb_user_access a ON a.user_id=u.id WHERE u.id=?');$q->execute([$id]);$row=$q->fetch();return $row?self::present($row):null;
 }
 public static function session(PDO $db):?array {
  $old=$_SESSION['fb_user']??null;if(!$old)return null;
  $user=self::find($db,(int)$old['id']);
  if(!$user||!$user['active']||$user['session_version']!==(int)($old['session_version']??1)){unset($_SESSION['fb_user']);return null;}
  $_SESSION['fb_user']=$user;return $user;
 }
 public static function can(array $user,string $permission):bool {return in_array($permission,$user['permissions']??[],true);}
 public static function requirePermission(array $user,string $permission):void {if(!self::can($user,$permission))throw new DomainException('Seu usuário não tem permissão para esta operação.');}
 public static function authorize(array $user,string $action,string $method):void {
  if(in_array($action,['logout','password'],true))return;
  if($action==='settings'&&$method==='GET')return;
  $groups=[
   'dashboard'=>['stats'],
   'read'=>['list','options','get'],
   'write'=>['save'],
   'import'=>['preview','import','imports'],
   'export'=>['export'],
   'monitor'=>['for024_monitor','for024'],
   'monitor_write'=>['for024_update'],
   'monitor_import'=>['pdf_preview','pdf_import','for024_history','for024_template'],
   'monitor_export'=>['for024_export'],
   'delete_import'=>['pdf_import_delete'],
   'assistant'=>['chat','conversations','conversation','conversation_delete','chat_history'],
   'settings'=>['settings'],
   'users'=>['users','user_save']
  ];
  $parents=['write'=>'read','import'=>'read','export'=>'read','assistant'=>'read','monitor_write'=>'monitor','monitor_import'=>'monitor','monitor_export'=>'monitor','delete_import'=>'monitor'];
  if($action==='appearance'){if($method==='POST')self::requirePermission($user,'settings');return;}
  foreach($groups as $permission=>$actions)if(in_array($action,$actions,true)){self::requirePermission($user,$permission);if(isset($parents[$permission]))self::requirePermission($user,$parents[$permission]);return;}
  throw new DomainException('Operação não permitida.');
 }
 public static function listing(PDO $db):array {
  $rows=$db->query('SELECT u.id,u.name,u.email,a.* FROM fb_users u JOIN fb_user_access a ON a.user_id=u.id ORDER BY u.name,u.id')->fetchAll();
  return ['rows'=>array_map([self::class,'present'],$rows),'roles'=>self::ROLES,'permissions'=>self::PERMISSIONS,'groups'=>self::GROUPS,'defaults'=>array_map([self::class,'defaults'],array_combine(array_keys(self::ROLES),array_keys(self::ROLES)))];
 }
 public static function save(PDO $db,array $data,int $actor):array {
  $id=(int)($data['id']??0);$name=trim((string)($data['name']??''));$email=mb_strtolower(trim((string)($data['email']??'')));
  $role=(string)($data['role']??'operator');$active=$data['active']??true;$password=(string)($data['password']??'');
  if($id<0||$name===''||mb_strlen($name)>120)throw new InvalidArgumentException('Informe um nome de até 120 caracteres.');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new InvalidArgumentException('Informe um e-mail válido.');
  if(!isset(self::ROLES[$role])||!is_bool($active))throw new InvalidArgumentException('Perfil ou situação inválidos.');
  if(($id===0||$password!=='')&&(strlen($password)<10||strlen($password)>200))throw new InvalidArgumentException('Use uma senha de 10 a 200 caracteres.');
  $permissions=$data['permissions']??self::defaults($role);
  if(!is_array($permissions)||array_filter($permissions,static fn($p)=>!is_string($p)||!isset(self::PERMISSIONS[$p])))throw new InvalidArgumentException('Permissões inválidas.');
  $permissions=$role==='admin'?self::defaults('admin'):array_values(array_unique($permissions));
  foreach(['write'=>'read','import'=>'read','export'=>'read','assistant'=>'read','monitor_write'=>'monitor','monitor_import'=>'monitor','monitor_export'=>'monitor','delete_import'=>'monitor'] as $permission=>$parent){
   if(in_array($permission,$permissions,true)&&!in_array($parent,$permissions,true))throw new InvalidArgumentException('Para liberar “'.self::PERMISSIONS[$permission].'”, marque também “'.self::PERMISSIONS[$parent].'”.');
  }
  // Administrar contas permite atribuir qualquer perfil: reservado aos administradores.
  if($role!=='admin'&&in_array('users',$permissions,true))throw new InvalidArgumentException('Somente o perfil Administrador pode administrar usuários.');
  $db->beginTransaction();
  try{
   // Serializa alterações de acesso para proteger o último administrador ativo.
   $db->query('SELECT user_id FROM fb_user_access ORDER BY user_id FOR UPDATE')->fetchAll();
   $current=self::find($db,$actor);if(!$current||!$current['active'])throw new DomainException('Sua conta não está ativa.');self::requirePermission($current,'users');
   $before=$id?self::find($db,$id):null;if($id&&!$before)throw new OutOfBoundsException('Usuário não encontrado.');
   if($id===$actor&&!$active)throw new InvalidArgumentException('Você não pode desativar sua própria conta.');
   if($before&&$before['role']==='admin'&&$before['active']&&($role!=='admin'||!$active)){
    $admins=(int)$db->query("SELECT COUNT(*) FROM fb_user_access WHERE role='admin' AND active=1")->fetchColumn();
    if($admins<=1)throw new InvalidArgumentException('Mantenha pelo menos um administrador ativo.');
   }
   $q=$db->prepare('SELECT id FROM fb_users WHERE email=? AND id<>?');$q->execute([$email,$id]);if($q->fetchColumn())throw new InvalidArgumentException('Este e-mail já está cadastrado.');
   if($id){$db->prepare('UPDATE fb_users SET name=?,email=? WHERE id=?')->execute([$name,$email,$id]);}
   else{$db->prepare('INSERT INTO fb_users(name,email,password_hash) VALUES(?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$db->lastInsertId();}
   $invalidate=$before&&($password!==''||$role!==$before['role']||$active!==$before['active']||$permissions!==$before['permissions']);
   $encoded=json_encode(['version'=>2,'permissions'=>$permissions],JSON_THROW_ON_ERROR);
   if($before){$db->prepare('UPDATE fb_user_access SET role=?,active=?,permissions_json=?,session_version=session_version+? WHERE user_id=?')->execute([$role,(int)$active,$encoded,(int)$invalidate,$id]);}
   else{$db->prepare('INSERT INTO fb_user_access(user_id,role,active,permissions_json) VALUES(?,?,?,?)')->execute([$id,$role,(int)$active,$encoded]);}
   if($before&&$password!=='')$db->prepare('UPDATE fb_users SET password_hash=?,failed_attempts=0,locked_until=NULL WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
   $after=self::find($db,$id);$details=['before'=>$before,'after'=>$after,'password_changed'=>$password!==''];
   $db->prepare('INSERT INTO fb_user_audit(actor_id,target_id,action,details_json) VALUES(?,?,?,?)')->execute([$actor,$id,$before?'update':'create',json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
   $db->commit();if($actor===$id)$_SESSION['fb_user']=$after;return $after;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new InvalidArgumentException('Este e-mail já está cadastrado.');throw $e;}
 }
 public static function password(PDO $db,int $id,array $data):void {
  $password=(string)($data['new_password']??'');if(strlen($password)<10||strlen($password)>200)throw new InvalidArgumentException('Use uma senha de 10 a 200 caracteres.');
  $db->beginTransaction();try{
   $q=$db->prepare('SELECT password_hash FROM fb_users WHERE id=? FOR UPDATE');$q->execute([$id]);if(!password_verify((string)($data['current_password']??''),(string)$q->fetchColumn()))throw new InvalidArgumentException('A senha atual está incorreta.');
   $db->prepare('UPDATE fb_users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
   $db->prepare('UPDATE fb_user_access SET session_version=session_version+1 WHERE user_id=?')->execute([$id]);
   $db->prepare('INSERT INTO fb_user_audit(actor_id,target_id,action,details_json) VALUES(?,?,?,?)')->execute([$id,$id,'password',json_encode(['password_changed'=>true],JSON_THROW_ON_ERROR)]);
   $db->commit();$_SESSION['fb_user']=self::find($db,$id);session_regenerate_id(true);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}
