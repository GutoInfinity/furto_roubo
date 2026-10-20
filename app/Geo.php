<?php
declare(strict_types=1);
/**
 * Coordenadas dos municípios brasileiros (app/data/municipios.json, IBGE via kelvins/municipios-brasileiros, MIT).
 * Aceita "JOINVILLE" + "SC", "JOINVILLE/SC", sem acento e pequenos erros de digitação dentro da UF.
 */
final class Geo {
 private const UF=['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
 private static ?array $data=null;
 private static array $cache=[];
 /** Nome sem acento, em maiúsculas, só letras/números separados por um espaço. */
 public static function key(string $name):string {
  $v=strtr(mb_strtoupper(trim($name),'UTF-8'),['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
  return trim(preg_replace('/[^A-Z0-9]+/',' ',$v));
 }
 private static function data():array {return self::$data??=json_decode((string)file_get_contents(__DIR__.'/data/municipios.json'),true)?:[];}
 /** Separa "CIDADE/UF" (ou "CIDADE - UF") quando a UF não veio na coluna própria. */
 public static function split(string $city,string $uf):array {
  $uf=strtoupper(trim($uf));
  if(preg_match('~^(.+?)\s*[/-]\s*([A-Za-z]{2})$~u',trim($city),$m)&&in_array(strtoupper($m[2]),self::UF,true)){$city=$m[1];if($uf==='')$uf=strtoupper($m[2]);}
  return [trim($city),in_array($uf,self::UF,true)?$uf:''];
 }
 /** Nome sem "DO/DA/DE/DOS/DAS/E" ("FOZ IGUACU" = "FOZ DO IGUACU"). */
 private static function compact(string $key):string {return trim(preg_replace('/\s+/',' ',preg_replace('/\b(?:DOS|DAS|DO|DA|DE|D|E)\b/',' ',$key)));}
 /** ['nome'=>…, 'uf'=>…, 'lat'=>…, 'lng'=>…] ou null quando não dá para localizar com segurança. */
 public static function locate(string $city,string $uf=''):?array {
  [$city,$uf]=self::split($city,$uf);$key=self::key($city);if(strlen($key)<3)return null;
  $cacheKey=$key.'|'.$uf;if(array_key_exists($cacheKey,self::$cache))return self::$cache[$cacheKey];
  $data=self::data();$short=self::compact($key);
  $exact=function(array $list)use($key,$short):?string{if(isset($list[$key]))return $key;foreach($list as $name=>$c)if(self::compact($name)===$short)return $name;return null;};
  // Erro de digitação: até 1 letra; 2 letras só em nomes longos e quando há um único candidato.
  $typo=function(array $list)use($short):?string{$hits=[1=>[],2=>[]];foreach($list as $name=>$c){$n=self::compact($name);if(abs(strlen($n)-strlen($short))>2)continue;$d=levenshtein($n,$short);if($d<=2)$hits[max(1,$d)][]=$name;}
   if(count($hits[1])===1)return $hits[1][0];if(!$hits[1]&&strlen($short)>=12&&count($hits[2])===1)return $hits[2][0];return null;};
  $hit=null;
  if($uf!==''&&($name=$exact($data[$uf]??[])))$hit=[$name,$uf];
  elseif($uf!==''&&($name=$typo($data[$uf]??[])))$hit=[$name,$uf];
  else{
   // UF ausente ou errada: vale o nome que existe em um único estado.
   $found=[];foreach($data as $u=>$list)if($name=$exact($list))$found[]=[$name,$u];if(count($found)===1)$hit=$found[0];
  }
  // DF: regiões administrativas (Samambaia, Taguatinga…) ficam no ponto de Brasília.
  if(!$hit&&$uf==='DF'&&isset($data['DF']['BRASILIA']))$hit=['BRASILIA','DF'];
  if($hit)$c=$data[$hit[1]][$hit[0]];
  return self::$cache[$cacheKey]=$hit?['nome'=>$hit[0],'uf'=>$hit[1],'lat'=>$c[0],'lng'=>$c[1]]:null;
 }
}
