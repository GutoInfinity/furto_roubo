<?php
declare(strict_types=1);
/**
 * Leitor de PDF sem bibliotecas externas: extrai trechos de texto (com posição) e os retângulos
 * desenhados (bordas de tabela). Suporta objetos comprimidos (FlateDecode), fluxos de objetos,
 * fontes Type0/Identity-H, Type3 e simples com mapas ToUnicode, e Form XObjects.
 * Coordenadas de saída: espaço da página em pontos, com y crescendo para baixo (topo = 0).
 */
final class PdfText {
 private string $data;
 private array $objects=[];
 private array $fontCache=[];

 public function __construct(string $data){
  if(strncmp($data,'%PDF-',5)!==0)throw new InvalidArgumentException('O arquivo enviado não é um PDF.');
  $this->data=$data;$this->loadObjects();
 }

 public static function fromFile(string $path):self {
  $data=file_get_contents($path);if($data===false)throw new RuntimeException('Não foi possível ler o PDF.');return new self($data);
 }

 /** @return array<int,array{texts:list<array{x:float,y:float,size:float,text:string}>,rects:list<array{x:float,y:float,w:float,h:float}>,width:float,height:float}> */
 public function pages():array {
  $catalog=null;foreach($this->objects as $num=>$raw)if(preg_match('~/Type\s*/Catalog\b~',$raw)){$catalog=$num;break;}
  if($catalog===null)throw new RuntimeException('Estrutura do PDF não reconhecida (sem catálogo).');
  $root=$this->parseObject($catalog);$pages=[];$this->collectPages($this->resolve($root['Pages']??null),$pages,[],0);
  $out=[];foreach($pages as [$page,$inherited]){
   $resources=$this->resolve($page['Resources']??$inherited['Resources']??[]);$box=$this->resolve($page['MediaBox']??$inherited['MediaBox']??[0,0,595,842]);
   $height=(float)$this->resolve($box[3]??842)-(float)$this->resolve($box[1]??0);$width=(float)$this->resolve($box[2]??595)-(float)$this->resolve($box[0]??0);
   $content='';foreach($this->contents($page['Contents']??null) as $stream)$content.=$stream."\n";
   $state=['texts'=>[],'rects'=>[]];$this->run($content,is_array($resources)?$resources:[],[1,0,0,1,0,0],$state,0);
   foreach($state['texts'] as &$t)$t['y']=$height-$t['y'];unset($t);foreach($state['rects'] as &$r){$r['y']=$height-$r['y']-$r['h'];}unset($r);
   $out[]=['texts'=>$state['texts'],'rects'=>$state['rects'],'width'=>$width,'height'=>$height];
  }
  return $out;
 }

 // ---------- objetos ----------
 private function loadObjects():void {
  if(preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj/s',$this->data,$m,PREG_SET_ORDER))foreach($m as $o)$this->objects[(int)$o[1]]=$o[3];
  // Objetos guardados dentro de fluxos de objetos (/Type /ObjStm).
  foreach($this->objects as $raw){if(!preg_match('~/Type\s*/ObjStm\b~',$raw))continue;$dict=$this->parseValue($raw);$body=$this->streamData($raw,is_array($dict)?$dict:[]);if($body===null)continue;
   $n=(int)($dict['N']??0);$first=(int)($dict['First']??0);$head=preg_split('/\s+/',trim(substr($body,0,$first)));for($i=0;$i+1<count($head)&&$i/2<$n;$i+=2){$num=(int)$head[$i];$off=(int)$head[$i+1];$next=isset($head[$i+3])?(int)$head[$i+3]:strlen($body)-$first;if(!isset($this->objects[$num]))$this->objects[$num]=substr($body,$first+$off,$next-$off);}}
 }
 private function parseObject(int $num):mixed {return isset($this->objects[$num])?$this->parseValue($this->objects[$num]):null;}
 private function resolve(mixed $v):mixed {for($i=0;$i<20&&is_array($v)&&isset($v['__ref']);$i++)$v=$this->parseObject($v['__ref']);return $v;}
 private function streamData(string $raw,array $dict):?string {
  if(!preg_match('/stream\r?\n/',$raw,$m,PREG_OFFSET_CAPTURE))return null;$start=$m[0][1]+strlen($m[0][0]);$len=$this->resolve($dict['Length']??null);
  $body=is_numeric($len)&&$start+(int)$len<=strlen($raw)?substr($raw,$start,(int)$len):substr($raw,$start,max(0,(int)strrpos($raw,'endstream')-$start));
  $filters=$this->resolve($dict['Filter']??null);$filters=$filters===null?[]:(is_array($filters)?array_map(fn($f)=>$this->resolve($f),$filters):[$filters]);
  foreach($filters as $f){if($f==='/FlateDecode'||$f==='/Fl'){$out=@gzuncompress($body);if($out===false)$out=@gzinflate(substr($body,2));if($out===false)$out=@gzinflate($body);if($out===false)return null;$body=$out;}
   elseif($f==='/ASCIIHexDecode'||$f==='/AHx'){$body=(string)hex2bin(preg_replace('/[^0-9a-fA-F]/','',rtrim($body,'>'))?:'');}else return null;}
  return $body;
 }
 private function stream(mixed $ref):?string {$num=is_array($ref)&&isset($ref['__ref'])?$ref['__ref']:null;if($num===null||!isset($this->objects[$num]))return null;$raw=$this->objects[$num];$dict=$this->parseValue($raw);return $this->streamData($raw,is_array($dict)?$dict:[]);}
 private function collectPages(mixed $node,array &$pages,array $inherited,int $depth):void {
  if(!is_array($node)||$depth>40)return;foreach(['Resources','MediaBox'] as $k)if(isset($node[$k]))$inherited[$k]=$node[$k];
  if(($node['Type']??'')==='/Pages'){foreach($this->resolve($node['Kids']??[]) as $kid)$this->collectPages($this->resolve($kid),$pages,$inherited,$depth+1);return;}
  if(($node['Type']??'')==='/Page')$pages[]=[$node,$inherited];
 }
 /** /Contents pode ser uma referência a um fluxo ou uma lista de referências. */
 private function contents(mixed $c):array {
  if(is_array($c)&&isset($c['__ref'])){$direct=$this->stream($c);if($direct!==null)return [$direct];$c=$this->resolve($c);}
  $out=[];if(is_array($c))foreach($c as $part){$s=$this->stream($part);if($s!==null)$out[]=$s;}return $out;
 }

 // ---------- valores PDF ----------
 private function parseValue(string $s):mixed {$pos=0;return $this->value($s,$pos);}
 private function skip(string $s,int &$p):void {$n=strlen($s);while($p<$n){$c=$s[$p];if($c==='%'){while($p<$n&&$s[$p]!=="\n"&&$s[$p]!=="\r")$p++;continue;}if(strpos(" \t\r\n\f\0",$c)!==false){$p++;continue;}break;}}
 private function value(string $s,int &$p):mixed {
  $this->skip($s,$p);$n=strlen($s);if($p>=$n)return null;$c=$s[$p];
  if($c==='<'&&($s[$p+1]??'')==='<'){$p+=2;$out=[];while(true){$this->skip($s,$p);if($p>=$n)break;if($s[$p]==='>'&&($s[$p+1]??'')==='>'){$p+=2;break;}$key=$this->value($s,$p);if(!is_string($key)||$key===''||$key[0]!=='/'){$p++;continue;}$out[substr($key,1)]=$this->value($s,$p);}return $out;}
  if($c==='['){$p++;$out=[];while(true){$this->skip($s,$p);if($p>=$n)break;if($s[$p]===']'){$p++;break;}$out[]=$this->value($s,$p);}return $out;}
  if($c==='('){return ['__str'=>$this->literal($s,$p)];}
  if($c==='<'){$end=strpos($s,'>',$p);$hex=preg_replace('/\s+/','',substr($s,$p+1,$end-$p-1));$p=$end+1;if(strlen($hex)%2)$hex.='0';return ['__str'=>(string)hex2bin($hex)];}
  if($c==='/'){$q=$p+1;while($q<$n&&strpos(" \t\r\n\f\0/[]<>(){}%",$s[$q])===false)$q++;$name='/'.substr($s,$p+1,$q-$p-1);$p=$q;return $name;}
  if(preg_match('/\G(-?\d+)\s+(\d+)\s+R\b/',$s,$m,0,$p)){$p+=strlen($m[0]);return ['__ref'=>(int)$m[1]];}
  if(preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/',$s,$m,0,$p)){$p+=strlen($m[0]);return (float)$m[0];}
  if(preg_match('/\G(true|false|null)\b/',$s,$m,0,$p)){$p+=strlen($m[0]);return $m[1]==='null'?null:$m[1]==='true';}
  $q=$p;while($q<$n&&strpos(" \t\r\n\f\0/[]<>(){}%",$s[$q])===false)$q++;$word=substr($s,$p,max(1,$q-$p));$p=max($q,$p+1);return ['__op'=>$word];
 }
 private function literal(string $s,int &$p):string {
  $p++;$depth=1;$out='';$n=strlen($s);
  while($p<$n){$c=$s[$p++];if($c==='\\'){$e=$s[$p++]??'';$map=['n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'f'=>"\f",'('=>'(',')'=>')','\\'=>'\\'];if(isset($map[$e]))$out.=$map[$e];elseif(ctype_digit($e)){$oct=$e;while(strlen($oct)<3&&ctype_digit($s[$p]??'x'))$oct.=$s[$p++];$out.=chr(octdec($oct)&255);}elseif($e==="\r"){if(($s[$p]??'')==="\n")$p++;}elseif($e!=="\n")$out.=$e;continue;}
   if($c==='('){$depth++;$out.=$c;continue;}if($c===')'){if(--$depth===0)break;$out.=$c;continue;}$out.=$c;}
  return $out;
 }

 // ---------- fontes ----------
 private function font(mixed $ref):array {
  $key=is_array($ref)&&isset($ref['__ref'])?'r'.$ref['__ref']:md5(serialize($ref));if(isset($this->fontCache[$key]))return $this->fontCache[$key];
  $font=$this->resolve($ref);$font=is_array($font)?$font:[];$bytes=($font['Subtype']??'')==='/Type0'?2:1;$map=[];
  if(isset($font['ToUnicode'])){$cmap=$this->stream($font['ToUnicode']);if($cmap!==null)$map=self::cmap($cmap,$bytes);}
  return $this->fontCache[$key]=['bytes'=>$bytes,'map'=>$map];
 }
 private static function utf16(string $hex):string {$bin=(string)hex2bin(strlen($hex)%2?$hex.'0':$hex);$s=@mb_convert_encoding($bin,'UTF-8','UTF-16BE');return is_string($s)?$s:'';}
 private static function cmap(string $cmap,int &$bytes):array {
  $map=[];if(preg_match('/begincodespacerange\s*<([0-9a-fA-F]+)>/',$cmap,$m))$bytes=max(1,intdiv(strlen($m[1]),2));
  if(preg_match_all('/beginbfchar(.*?)endbfchar/s',$cmap,$blocks))foreach($blocks[1] as $b)if(preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]*)>/',$b,$pairs,PREG_SET_ORDER))foreach($pairs as $pp)$map[hexdec($pp[1])]=self::utf16($pp[2]);
  if(preg_match_all('/beginbfrange(.*?)endbfrange/s',$cmap,$blocks))foreach($blocks[1] as $b){
   if(preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*(<[0-9a-fA-F]+>|\[[^\]]*\])/',$b,$ranges,PREG_SET_ORDER))foreach($ranges as $r){$lo=hexdec($r[1]);$hi=hexdec($r[2]);if($hi-$lo>65535)continue;
    if($r[3][0]==='['){preg_match_all('/<([0-9a-fA-F]+)>/',$r[3],$list);foreach($list[1] as $i=>$h)$map[$lo+$i]=self::utf16($h);}
    else{$base=substr($r[3],1,-1);$prefix=substr($base,0,-4);$last=hexdec(substr($base,-4));for($c=$lo;$c<=$hi;$c++)$map[$c]=self::utf16($prefix.str_pad(dechex($last+$c-$lo),4,'0',STR_PAD_LEFT));}}}
  return $map;
 }
 private function decode(string $bin,array $font):string {
  $out='';$step=$font['bytes'];for($i=0;$i+$step<=strlen($bin);$i+=$step){$code=$step===2?(ord($bin[$i])<<8)|ord($bin[$i+1]):ord($bin[$i]);
   if(isset($font['map'][$code]))$out.=$font['map'][$code];elseif($step===1&&$code>=32&&$code<127)$out.=chr($code);elseif($step===1&&$code>=160)$out.=mb_convert_encoding(chr($code),'UTF-8','ISO-8859-1');}
  return $out;
 }

 // ---------- interpretação do conteúdo ----------
 private static function mul(array $a,array $b):array {return [$a[0]*$b[0]+$a[1]*$b[2],$a[0]*$b[1]+$a[1]*$b[3],$a[2]*$b[0]+$a[3]*$b[2],$a[2]*$b[1]+$a[3]*$b[3],$a[4]*$b[0]+$a[5]*$b[2]+$b[4],$a[4]*$b[1]+$a[5]*$b[3]+$b[5]];}
 private function run(string $content,array $resources,array $ctm,array &$state,int $depth):void {
  if($depth>8)return;$fonts=$this->resolve($resources['Font']??[]);$fonts=is_array($fonts)?$fonts:[];$xobjects=$this->resolve($resources['XObject']??[]);$xobjects=is_array($xobjects)?$xobjects:[];
  $stack=[];$tm=[1,0,0,1,0,0];$tlm=$tm;$font=null;$size=0.0;$leading=0.0;$args=[];$p=0;$n=strlen($content);
  $emit=function(string $text)use(&$tm,&$ctm,&$size,&$state){if($text==='')return;$m=self::mul($tm,$ctm);$state['texts'][]=['x'=>round($m[4],2),'y'=>round($m[5],2),'size'=>round($size*sqrt(abs($m[0]*$m[3]-$m[1]*$m[2])),2),'text'=>$text];};
  while($p<$n){
   $this->skip($content,$p);if($p>=$n)break;
   if(substr($content,$p,2)==='BI'&&preg_match('/\GBI\b/',$content,$mm,0,$p)){$end=strpos($content,'EI',$p);$p=$end===false?$n:$end+2;continue;}
   $v=$this->value($content,$p);
   if(!(is_array($v)&&isset($v['__op']))){$args[]=$v;continue;}
   $op=$v['__op'];
   switch($op){
    case 'q':$stack[]=$ctm;break;
    case 'Q':if($stack)$ctm=array_pop($stack);break;
    case 'cm':if(count($args)>=6)$ctm=self::mul(array_map('floatval',array_slice($args,-6)),$ctm);break;
    case 'BT':$tm=$tlm=[1,0,0,1,0,0];break;
    case 'Tf':$font=$fonts[ltrim((string)($args[count($args)-2]??''),'/')]??null;$size=(float)($args[count($args)-1]??0);break;
    case 'TL':$leading=(float)end($args);break;
    case 'Tm':if(count($args)>=6){$tm=$tlm=array_map('floatval',array_slice($args,-6));}break;
    case 'Td':case 'TD':if(count($args)>=2){[$tx,$ty]=array_map('floatval',array_slice($args,-2));if($op==='TD')$leading=-$ty;$tlm=self::mul([1,0,0,1,$tx,$ty],$tlm);$tm=$tlm;}break;
    case 'T*':$tlm=self::mul([1,0,0,1,0,-$leading],$tlm);$tm=$tlm;break;
    case 'Tj':case "'":case '"':
     if($op!=='Tj'){$tlm=self::mul([1,0,0,1,0,-$leading],$tlm);$tm=$tlm;}
     $str=end($args);if(is_array($str)&&isset($str['__str'])&&$font!==null)$emit($this->decode($str['__str'],$this->font($font)));break;
    case 'TJ':$arr=end($args);if(is_array($arr)&&$font!==null){$f=$this->font($font);$text='';foreach($arr as $part){if(is_array($part)&&isset($part['__str']))$text.=$this->decode($part['__str'],$f);elseif(is_float($part)&&$part<-200)$text.=' ';}$emit($text);}break;
    case 're':if(count($args)>=4){[$x,$y,$w,$h]=array_map('floatval',array_slice($args,-4));$a=self::mul([1,0,0,1,$x,$y],$ctm);$b=self::mul([1,0,0,1,$x+$w,$y+$h],$ctm);$state['rects'][]=['x'=>round(min($a[4],$b[4]),2),'y'=>round(min($a[5],$b[5]),2),'w'=>round(abs($b[4]-$a[4]),2),'h'=>round(abs($b[5]-$a[5]),2)];}break;
    case 'Do':$name=ltrim((string)end($args),'/');$ref=$xobjects[$name]??null;$obj=$ref!==null?$this->resolve($ref):null;
     if(is_array($obj)&&($obj['Subtype']??'')==='/Form'&&is_array($ref)&&isset($ref['__ref'])){$body=$this->stream($ref);if($body!==null){$matrix=isset($obj['Matrix'])?array_map(fn($x)=>(float)$this->resolve($x),$this->resolve($obj['Matrix'])):[1,0,0,1,0,0];$res=$this->resolve($obj['Resources']??$resources);$this->run($body,is_array($res)?$res:$resources,self::mul($matrix,$ctm),$state,$depth+1);}}
     break;
   }
   $args=[];
  }
 }
}
