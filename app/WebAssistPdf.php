<?php
declare(strict_types=1);
require_once __DIR__.'/PdfText.php';
/**
 * "RELATÓRIO DE FURTO / ROUBO" do Infinity/WebAssist em PDF → ocorrências.
 * As células são lidas pela grade desenhada no PDF (linhas verticais e horizontais da tabela),
 * sem depender da largura do texto. Nada é inventado: campos que o PDF não traz ficam vazios.
 */
final class WebAssistPdf {
 /** Cabeçalhos aceitos (normalizados) → campo. */
 private const HEADERS=['cliente'=>'cliente','atendimento de dados'=>'data','atendimento'=>'data','data'=>'data','modelo'=>'modelo','montadora'=>'montadora','marca'=>'montadora','beneficiario'=>'beneficiario','placa'=>'placa','protocolo'=>'protocolo','situacao'=>'situacao','valor veiculo'=>'valor','valor'=>'valor'];

 public static function norm(string $v):string {
  $v=mb_strtolower(trim(preg_replace('/\s+/u',' ',$v)),'UTF-8');
  return strtr($v,['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','ë'=>'e']);
 }

 /** @return array{rows:list<array<string,string>>,generated:?string,warnings:list<string>} */
 public static function read(string $path):array {
  $pdf=PdfText::fromFile($path);$rows=[];$warnings=[];$generated=null;
  foreach($pdf->pages() as $index=>$page){
   $all=implode('',array_column($page['texts'],'text'));
   if($generated===null&&preg_match('~Gerado\s*em\s*(\d{2}/\d{2}/\d{4})\s*as\s*(\d{2}:\d{2})~u',preg_replace('/\s+/u',' ',$all)??'',$m))$generated=$m[1].' '.$m[2];
   $grid=self::grid($page);if(!$grid)continue;
   [$xs,$ys]=$grid;$cells=self::cells($page['texts'],$xs,$ys);$columns=null;
   foreach($cells as $r=>$line){
    $first=self::norm($line[0]??'');
    if($columns===null){$map=[];foreach($line as $c=>$text){$key=self::norm($text);if(isset(self::HEADERS[$key]))$map[$c]=self::HEADERS[$key];}
     if(in_array('placa',$map,true)&&in_array('protocolo',$map,true)){$columns=$map;}continue;}
    if(str_starts_with($first,'valor total')||implode('',$line)==='')continue;
    $row=['cliente'=>'','data'=>'','modelo'=>'','montadora'=>'','beneficiario'=>'','placa'=>'','protocolo'=>'','situacao'=>'','valor'=>''];
    foreach($columns as $c=>$field)$row[$field]=$line[$c]??'';
    $row['placa']=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$row['placa']));
    if($row['placa']===''&&$row['protocolo']===''){continue;}
    if(!preg_match('~^\d{2}/\d{2}/\d{4}$~',$row['data']))$warnings[]='Página '.($index+1).': data não reconhecida para a placa '.$row['placa'].' ("'.$row['data'].'").';
    $rows[]=$row;
   }
   if($columns===null)$warnings[]='Página '.($index+1).': cabeçalho da tabela não encontrado.';
  }
  if(!$rows)throw new InvalidArgumentException('Não encontrei a tabela do "Relatório de Furto / Roubo" neste PDF. Envie o relatório gerado pelo WebAssist.');
  return ['rows'=>$rows,'generated'=>$generated,'warnings'=>$warnings];
 }

 /** Bordas verticais e horizontais da maior tabela da página. */
 private static function grid(array $page):?array {
  $vertical=[];$horizontal=[];
  foreach($page['rects'] as $r){if($r['w']<2&&$r['h']>5)$vertical[]=$r['x']+$r['w']/2;elseif($r['h']<2&&$r['w']>5)$horizontal[]=$r['y']+$r['h']/2;}
  $xs=self::unique($vertical,1.5);$ys=self::unique($horizontal,1.5);
  return count($xs)>=4&&count($ys)>=2?[$xs,$ys]:null;
 }
 private static function unique(array $values,float $tolerance):array {sort($values);$out=[];foreach($values as $v)if(!$out||$v-end($out)>$tolerance)$out[]=$v;return $out;}

 /** Texto de cada célula: trechos ordenados por linha (y) e posição (x); linhas da célula unidas com espaço. */
 private static function cells(array $texts,array $xs,array $ys):array {
  $buckets=[];
  foreach($texts as $t){
   $col=null;for($i=0;$i+1<count($xs);$i++)if($t['x']>=$xs[$i]-0.5&&$t['x']<$xs[$i+1]-0.5){$col=$i;break;}
   $row=null;for($j=0;$j+1<count($ys);$j++)if($t['y']>$ys[$j]&&$t['y']<=$ys[$j+1]+0.5){$row=$j;break;}
   if($col===null||$row===null)continue;$buckets[$row][$col][]=$t;
  }
  ksort($buckets);$out=[];
  foreach($buckets as $r=>$cols){$line=array_fill(0,count($xs)-1,'');
   foreach($cols as $c=>$items){usort($items,fn($a,$b)=>[round($a['y']),$a['x']]<=>[round($b['y']),$b['x']]);$lines=[];
    foreach($items as $it){$key=null;foreach(array_keys($lines) as $y)if(abs($y-$it['y'])<=max(1.5,$it['size']*0.4)){$key=$y;break;}$key??=$it['y'];$lines[$key][]=$it;}
    ksort($lines);$parts=[];foreach($lines as $ln){usort($ln,fn($a,$b)=>$a['x']<=>$b['x']);$parts[]=trim(implode('',array_column($ln,'text')));}
    $line[$c]=trim(preg_replace('/\s+/u',' ',implode(' ',$parts)));}
   $out[$r]=$line;}
  return $out;
 }
}
