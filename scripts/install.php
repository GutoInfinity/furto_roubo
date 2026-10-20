<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$name=fb_env('DB_NAME');if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Preencha o .env com o nome exclusivo do novo banco.');
$server=new PDO('mysql:host='.fb_env('DB_HOST','127.0.0.1').';port='.fb_env('DB_PORT','3306').';charset=utf8mb4',fb_env('DB_USER'),fb_env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db=fb_db();$db->exec(file_get_contents(FB_ROOT.'/database/schema.sql'));$db->exec(file_get_contents(FB_ROOT.'/database/admin.sql'));
if(strlen(fb_env('APP_KEY'))<32)throw new RuntimeException('Configure APP_KEY com pelo menos 32 caracteres aleatórios antes de usar a IA.');
echo "Banco independente e estrutura preparados.\n";
$db->exec(file_get_contents(FB_ROOT.'/database/atualizacao-conversas.sql'));
if(isset($argv[1])){$path=$argv[1];$parsed=Spreadsheets::read($path,basename($path));$plan=Spreadsheets::plan($db,$parsed,hash_file('sha256',$path));$user=(int)$db->query('SELECT id FROM fb_users ORDER BY id LIMIT 1')->fetchColumn();$result=Spreadsheets::commit($db,$plan,$user,basename($path));echo json_encode($result,JSON_UNESCAPED_UNICODE),"\n";}
