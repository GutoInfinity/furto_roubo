<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
define('FB_ROOT', dirname(__DIR__));
function fb_env(string $key,string $default=''):string {
 static $values=null;
 if($values===null){$values=[];foreach(@file(FB_ROOT.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||strpos($line,'=')===false)continue;[$name,$value]=explode('=',$line,2);$values[trim($name)]=trim($value," \t\n\r\0\x0B\"'");}}
 // Variáveis do servidor (Coolify/Docker) têm prioridade sobre o arquivo .env.
 $server=getenv($key);if($server!==false&&$server!=='')return $server;
 return (string)($values[$key]??$default);
}
function fb_db():PDO {
 static $db=null;if($db)return $db;
 $name=fb_env('DB_NAME');if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Configure o banco próprio do sistema.');
 $db=new PDO('mysql:host='.fb_env('DB_HOST','127.0.0.1').';port='.fb_env('DB_PORT','3306').';dbname='.$name.';charset=utf8mb4',fb_env('DB_USER'),fb_env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='-03:00'");return $db;
}
if(PHP_SAPI!=='cli'){
 session_name('axioma_fb');session_set_cookie_params(['lifetime'=>0,'path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/').'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);session_start();
 header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: same-origin');
 header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
 if(isset($_SESSION['fb_last'])&&time()-$_SESSION['fb_last']>3600){$_SESSION=[];session_regenerate_id(true);}$_SESSION['fb_last']=time();
}
function fb_csrf():string {if(empty($_SESSION['fb_csrf']))$_SESSION['fb_csrf']=bin2hex(random_bytes(32));return $_SESSION['fb_csrf'];}
function fb_verify_csrf():void {if(!hash_equals(fb_csrf(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'')))throw new DomainException('Sua sessão expirou. Atualize a página.');}
function fb_user():array {if(empty($_SESSION['fb_user']))throw new DomainException('Entre na sua conta para continuar.');return $_SESSION['fb_user'];}
function fb_json(array $data,int $code=200):void {http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function fb_input():array {$raw=file_get_contents('php://input');if(strlen($raw)>200000)throw new InvalidArgumentException('Solicitação muito grande.');$data=json_decode($raw,true);if(!is_array($data))throw new InvalidArgumentException('Solicitação inválida.');return $data;}
function fb_escape($text):string{return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function fb_log(Throwable $e):void {error_log('[Infinity System FB] '.get_class($e).': '.$e->getMessage());}
require_once __DIR__.'/Records.php';
require_once __DIR__.'/Spreadsheets.php';
require_once __DIR__.'/Assistant.php';
require_once __DIR__.'/AssistantLanguage.php';
require_once __DIR__.'/Conversations.php';
require_once __DIR__.'/Geo.php';
require_once __DIR__.'/For024.php';
