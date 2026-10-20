<?php
declare(strict_types=1);
final class Appearance {
 public static function keys():array{return ['scheme'=>'scheme','theme-primary'=>'primary','theme-font'=>'font','theme-base'=>'base','theme-radius'=>'radius','navbar-position'=>'position','layout'=>'width','navbar'=>'navbar','navbar-style'=>'navstyle','offcanvas-style'=>'panel','sidebar'=>'sidebar'];}
 public static function value(string $key,string $value):string {
  if($key==='theme-radius')return (string)((float)$value*6);
  if($key==='theme-font')return ['sans-serif'=>'sans','monospace'=>'mono'][$value]??$value;
  return $value;
 }
 public static function controls():array {
  require __DIR__.'/appearance-options.php';$out=[];
  foreach($tablerSettings as [$key,$label,$type,$values]){$options=[];foreach($values as $value=>$text)$options[self::value($key,(string)$value)]=$text;$out[self::keys()[$key]]=[$label,$options];}
  // Accept preferences saved by the previous version when updating an existing installation.
  $out['scheme'][1]['default']='Padrão';foreach(['4','8','16'] as $v)$out['radius'][1][$v]=$v;
  $out['size']=['Tamanho',['small'=>'Compacto','normal'=>'Padrão','large'=>'Maior']];$out['sidewidth']=['Largura',['220'=>'Estreita','260'=>'Padrão','300'=>'Larga']];return $out;
 }
 public static function defaults():array{return ['scheme'=>'company','primary'=>'company','font'=>'sans','base'=>'company','radius'=>'6','size'=>'normal','position'=>'horizontal','width'=>'fluid','navbar'=>'default','navstyle'=>'default','panel'=>'default','sidebar'=>'default','sidewidth'=>'260','custom_primary'=>'','custom_sidebar'=>''];}
 public static function validate(array $data):array {
  $clean=self::defaults();foreach($data as $key=>$value){if(!array_key_exists($key,$clean)||!is_string($value))throw new InvalidArgumentException('Opção de aparência inválida.');
   if(strpos($key,'custom_')===0){if($value!==''&&!preg_match('/^#[0-9a-f]{6}$/iD',$value))throw new InvalidArgumentException('Cor inválida.');}
   elseif(!array_key_exists($value,self::controls()[$key][1]))throw new InvalidArgumentException('Opção de aparência inválida.');$clean[$key]=$value;
  }return $clean;
 }
 public static function get(PDO $db):array{$q=$db->prepare('SELECT setting_value FROM fb_settings WHERE setting_key=?');$q->execute(['appearance']);$saved=json_decode((string)$q->fetchColumn(),true);try{$out=self::validate(is_array($saved)?$saved:[]);if($out['scheme']==='default')$out['scheme']='company';$out['radius']=['4'=>'3','8'=>'6','16'=>'12'][$out['radius']]??$out['radius'];return $out;}catch(InvalidArgumentException $e){return self::defaults();}}
}
