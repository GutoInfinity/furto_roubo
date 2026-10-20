<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$db=fb_db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='axioma_furto_roubo')throw new RuntimeException('Geracao restrita ao banco novo local.');
if((int)$db->query('SELECT COUNT(*) FROM fb_occurrences')->fetchColumn()!==474)throw new RuntimeException('Confira os registros antes da entrega.');
$sql="-- Instalacao nova: importar schema.sql e admin.sql antes deste arquivo.\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n";
foreach(['fb_occurrences','fb_history','fb_imports'] as $table){
 foreach($db->query('SELECT * FROM `'.$table.'` ORDER BY id') as $row){
  $columns=array_map(static fn($c)=>'`'.$c.'`',array_keys($row));
  $values=array_map(static fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row));
  $sql.='INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n";
 }
}
$sql.="COMMIT;\n";file_put_contents(FB_ROOT.'/database/dados-iniciais.sql',$sql);
$out=FB_ROOT.'/entrega';if(!is_dir($out))mkdir($out,0755,true);file_put_contents($out.'/.htaccess',"Require all denied\n");
$path=$out.'/sistema-FB-cpanel-'.date('Ymd').'.zip';$zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP indisponivel.');
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FB_ROOT,FilesystemIterator::SKIP_DOTS));$count=0;
foreach($iterator as $file){if(!$file->isFile())continue;$relative=str_replace('\\','/',substr($file->getPathname(),strlen(FB_ROOT)+1));
 if($relative==='.env'||preg_match('~^(?:tests|entrega)/~',$relative)||$relative==='scripts/build-release.php')continue;
 if(strpos($relative,'storage/')===0&&basename($relative)!=='.htaccess')continue;
 $zip->addFile($file->getPathname(),$relative);$count++;
}
$zip->close();$check=new ZipArchive();if($check->open($path,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('ZIP invalido.');if($check->locateName('.env')!==false)throw new RuntimeException('Configuracao privada indevida.');$check->close();
echo $path,"\n",$count," arquivos; ",filesize($path)," bytes; SHA256 ",hash_file('sha256',$path),"\n";
