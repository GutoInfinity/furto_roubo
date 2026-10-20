<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$db=fb_db();
$db->exec(file_get_contents(FB_ROOT.'/database/atualizacao-usuarios.sql'));
echo "Migração de usuários concluída. Usuários, senhas e ocorrências preservados.\n";
foreach($db->query('SELECT u.id,u.name,a.role,a.active FROM fb_users u JOIN fb_user_access a ON a.user_id=u.id ORDER BY u.id') as $row){
 echo $row['id'].' | '.$row['name'].' | '.$row['role'].' | '.($row['active']?'ativo':'inativo')."\n";
}
