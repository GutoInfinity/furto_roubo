<?php
declare(strict_types=1);
/**
 * Planilha FOR 024 – Controle de Roubos e Furtos.
 * Usa o arquivo enviado pelo cliente como modelo: todas as abas, logos, cores, larguras e mesclagens
 * são mantidas. Só a aba do ano recebe as novas linhas, dentro do bloco do mês, com os mesmos
 * estilos das linhas já existentes. As outras partes do arquivo são copiadas sem alteração.
 */
final class ControlWorkbook {
 private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
 private const RELS='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
 public const MONTHS=[1=>'JANEIRO','FEVEREIRO','MARÇO','ABRIL','MAIO','JUNHO','JULHO','AGOSTO','SETEMBRO','OUTUBRO','NOVEMBRO','DEZEMBRO'];
 /** Mesmo veículo dentro deste intervalo é o mesmo caso (atendimento repetido), como na planilha manual. */
 public const DUPLICATE_DAYS=7;
 /** Campos do FOR 024 (colunas da versão atual, de CIDADE a ID RASTREADOR). */
 public const FIELDS=['cidade','estado','data','cliente','beneficiario','placa','veiculo','tipo','rastreador','localizado','evento','recuperado'];

 public static function templatePath():string {return FB_ROOT.'/storage/templates/FOR_024.xlsx';}
 public static function hasTemplate():bool {return is_file(self::templatePath());}
 public static function saveTemplate(string $source):array {
  $info=self::inspect($source);$dir=dirname(self::templatePath());if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('Não foi possível criar a pasta do modelo.');
  if(!copy($source,self::templatePath()))throw new RuntimeException('Não foi possível guardar o modelo FOR 024.');return $info;
 }

 /** Confere se o arquivo é um FOR 024 válido e lista as abas por ano. */
 public static function inspect(string $path):array {
  $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('Envie a planilha FOR 024 em formato .xlsx.');
  try{$sheets=self::sheets($zip);$years=[];foreach($sheets as $name=>$part)if(preg_match('/^(20\d{2})\b/',(string)$name,$m))$years[(int)$m[1]]=(string)$name;
   if(!$years)throw new InvalidArgumentException('A planilha não tem abas por ano (ex.: 2026).');
   $last=max(array_keys($years));$xml=$zip->getFromName($sheets[$years[$last]]);if(!$xml||!str_contains(self::plain($xml),'CONTROLE DE ROUBOS E FURTOS')&&!str_contains(self::shared($zip,$xml),'CONTROLE DE ROUBOS E FURTOS'))throw new InvalidArgumentException('Este arquivo não parece ser o FOR 024 – Controle de Roubos e Furtos.');
   ksort($years);return ['years'=>$years,'sheets'=>array_keys($sheets)];}
  finally{$zip->close();}
 }

 /**
  * Gera o FOR 024 com as ocorrências. $rows: [date(Y-m-d), cidade, estado, cliente, beneficiario, placa, veiculo, tipo, rastreador, localizado, evento, recuperado, chave].
  * @return array{added:list<array>,skipped:list<array>,warnings:list<string>}
  */
 /**
  * Exporta somente as linhas informadas, sem o histórico: mantém o visual do FOR 024 (título, logo,
  * cabeçalho, estilos), remove as abas de outros anos e limpa as linhas antigas da aba do ano.
  */
 public static function exportOnly(array $rows,string $target,?DateTimeImmutable $today=null):array {return self::export($rows,$target,$today,true);}

 public static function export(array $rows,string $target,?DateTimeImmutable $today=null,bool $clean=false):array {
  if(!self::hasTemplate())throw new InvalidArgumentException('Envie primeiro a planilha FOR 024 que será usada como modelo.');
  $today=$today??new DateTimeImmutable('today',new DateTimeZone('America/Sao_Paulo'));
  if(!copy(self::templatePath(),$target))throw new RuntimeException('Não foi possível preparar a planilha.');
  $zip=new ZipArchive();if($zip->open($target)!==true)throw new RuntimeException('Modelo FOR 024 inválido.');
  $result=['added'=>[],'skipped'=>[],'warnings'=>[]];
  try{
   $sheets=self::sheets($zip);$strings=self::sharedList($zip);$byYear=[];
   foreach($rows as $row){$y=(int)($row['year']??0)?:(!empty($row['date'])?(int)substr($row['date'],0,4):0);if($y<2000){$result['skipped'][]=$row+['motivo'=>'Sem data nem ano'];continue;}$byYear[$y][]=$row;}
   ksort($byYear);
   if($clean){
    $keep=[];foreach($sheets as $sheetName=>$part){$sheetName=(string)$sheetName;if(!preg_match('/^(20\d{2})\b/',$sheetName,$m)||isset($byYear[(int)$m[1]]))$keep[]=$sheetName;}
    if(!array_filter($keep,fn($n)=>preg_match('/^20\d{2}\b/',$n)))throw new InvalidArgumentException('A planilha modelo não tem a aba do ano das ocorrências selecionadas.');
    [$wbXml,$relsXml]=self::prune($zip,$keep);$sheets=self::sheets($zip,$wbXml,$relsXml);
   }
   foreach($byYear as $year=>$items){
    $name=null;foreach($sheets as $sheetName=>$part)if(preg_match('/^'.$year.'\b/',(string)$sheetName)){$name=(string)$sheetName;break;}
    if($name===null){$result['warnings'][]='A planilha modelo não tem a aba '.$year.'. '.count($items).' ocorrência(s) desse ano não foram exportadas.';foreach($items as $i)$result['skipped'][]=$i+['motivo'=>'Sem aba '.$year];continue;}
    $part=$sheets[$name];$doc=new DOMDocument();$doc->preserveWhiteSpace=true;if(!$doc->loadXML((string)$zip->getFromName($part)))throw new RuntimeException('Aba '.$name.' ilegível.');
    $sheet=new ControlSheet($doc,$strings);if($clean)$sheet->clearData();
    // Data com ano digitado errado (ex.: 2052 na aba 2026): vale o bloco do mês de origem.
    $month=fn($i)=>!empty($i['date'])&&((int)substr($i['date'],0,4)===$year||empty($i['month']))?(int)substr($i['date'],5,2):max(1,min(12,(int)($i['month']??1)));
    usort($items,fn($a,$b)=>[$month($a),$a['date']??'',(int)($a['order']??0),$a['placa']]<=>[$month($b),$b['date']??'',(int)($b['order']??0),$b['placa']]);
    foreach($items as $item){
     $reason=$clean?null:$sheet->duplicate($item,self::DUPLICATE_DAYS);
     if($reason!==null){$result['skipped'][]=$item+['motivo'=>$reason];continue;}
     $sheet->add($item,$month($item));$result['added'][]=$item+['aba'=>$name];
    }
    if($sheet->changed||$clean){$sheet->stamp(self::serial($today->format('Y-m-d')));$zip->addFromString($part,$doc->saveXML());}
   }
  }finally{$zip->close();}
  return $result;
 }

 /**
  * Todas as linhas das abas por ano da planilha (para importar o histórico no sistema).
  * @return list<array> cada linha com os campos, aba, ano, mês do bloco e ordem na aba.
  */
 public static function history(string $path):array {
  $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('Envie a planilha FOR 024 em formato .xlsx.');
  try{$sheets=self::sheets($zip);$strings=self::sharedList($zip);$out=[];
   foreach($sheets as $name=>$part){$name=(string)$name;if(!preg_match('/^(20\d{2})\b/',$name,$m))continue;$year=(int)$m[1];$doc=new DOMDocument();if(!@$doc->loadXML((string)$zip->getFromName($part)))continue;
    try{$sheet=new ControlSheet($doc,$strings);}catch(InvalidArgumentException $e){continue;}
    foreach($sheet->records() as $r){$r['aba']=$name;$r['year']=$year;if($r['date']!==null&&(int)substr($r['date'],0,4)!==$year&&$r['month']!==null){/* Data digitada com ano diferente da aba: mantém a data e o bloco. */}$r['month']=$r['month']??($r['date']?(int)substr($r['date'],5,2):null);$r['order']=$r['row'];$out[]=$r;}}
   return $out;}
  finally{$zip->close();}
 }

 public static function serial(string $iso):int {return (int)((new DateTimeImmutable($iso.' 00:00:00',new DateTimeZone('UTC')))->getTimestamp()/86400)+25569;}
 public static function iso(float $serial):string {return gmdate('Y-m-d',(int)round(($serial-25569)*86400));}

 /** Aprende o tipo de veículo pelo histórico da planilha (aba do ano mais recente pesa mais). */
 public static function vehicleTypes():array {
  if(!self::hasTemplate())return [];$zip=new ZipArchive();if($zip->open(self::templatePath())!==true)return [];
  try{$sheets=self::sheets($zip);$strings=self::sharedList($zip);$votes=[];$years=[];foreach($sheets as $n=>$p)if(preg_match('/^(20\d{2})\b/',(string)$n,$m))$years[(string)$n]=(int)$m[1];$max=$years?max($years):0;
   foreach($years as $name=>$year){$doc=new DOMDocument();if(!@$doc->loadXML((string)$zip->getFromName($sheets[$name]??$sheets[(int)$name]??'')))continue;$sheet=new ControlSheet($doc,$strings);$weight=$year===$max?5:($year>=$max-1?2:1);
    foreach($sheet->records() as $r){$type=self::type($r['tipo']);if($type==='')continue;foreach(self::modelKeys($r['veiculo']) as $k)$votes[$k][$type]=($votes[$k][$type]??0)+$weight;}}
   $out=[];foreach($votes as $k=>$v){arsort($v);$out[$k]=array_key_first($v);}return $out;}
  finally{$zip->close();}
 }
 /** Grafia padronizada usada na planilha atual. */
 public static function type(string $v):string {$n=WebAssistPdf::norm($v);return match(true){$n==='moto'||$n==='motocicleta'=>'MOTO',str_contains($n,'pesado')=>'PESADO',str_contains($n,'utilitario')=>'UTILITARIO',str_contains($n,'leve')=>'VEICULO LEVE',default=>''};}
 /** Chaves do modelo, da mais específica para a mais geral (ignora a montadora antes da barra). */
 public static function modelKeys(string $vehicle):array {
  $n=WebAssistPdf::norm($vehicle);if(str_contains($n,'/')&&preg_match('~^[a-z\- ]+/\s*(.+)$~u',$n,$m))$n=$m[1];$words=array_values(array_filter(preg_split('/[\s\/]+/',$n)));if(!$words)return [];
  $keys=[];if(count($words)>=2)$keys[]=$words[0].' '.$words[1];$keys[]=$words[0];return $keys;
 }
 public static function guessType(string $brand,string $model,array $learned):string {
  foreach(self::modelKeys($model) as $k)if(isset($learned[$k]))return $learned[$k];
  $m=WebAssistPdf::norm($model);$b=WebAssistPdf::norm($brand);
  if(preg_match('/^(cg|cb|cbr|cbx|biz|pop|xre|nxr|bros|titan|fan|start|factor|ybr|fazer|fz\d+|nmax|xmax|xtz|lander|crosser|neo|r1|r3|r15|mt\s?-?\d+|xj6|burgman|yes|intruder|dk\s?\d+|twister|hornet|pcx|elite|lead|sh\s?\d+|adv|crf|xr|cg\d+|dominar|duke|scrambler)\b/',$m)||in_array($b,['yamaha','dafra','shineray','harley-davidson','harley davidson','triumph','ducati','ktm','royal enfield','haojue','kasinski','traxx','mottu'],true))return 'MOTO';
  if(preg_match('/\b(fh|fm|axor|atego|actros|constellation|cargo|scania|r ?\d{3}|semi|reboque|carreta)\b/',$m)||in_array($b,['scania','volvo','iveco','daf','man'],true))return 'PESADO';
  return 'VEICULO LEVE';
 }

 /**
  * Remove do pacote as abas fora de $keep, com tudo o que só elas usam (desenhos, configurações),
  * e ajusta workbook.xml, relações, [Content_Types].xml e nomes definidos. calcChain é descartado
  * (o Excel recria ao abrir).
  */
 private static function prune(ZipArchive $zip,array $keep):array {
  $wb=new DOMDocument();$wb->loadXML((string)$zip->getFromName('xl/workbook.xml'));$rels=new DOMDocument();$rels->loadXML((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
  $xp=new DOMXPath($wb);$xp->registerNamespace('m',self::NS);$relById=[];foreach($rels->getElementsByTagName('Relationship') as $r)$relById[$r->getAttribute('Id')]=$r;
  $removeParts=[];$oldIndex=0;$newIndex=0;$map=[];$removedNames=[];
  foreach(iterator_to_array($xp->query('//m:sheets/m:sheet')) as $sheet){
   $name=$sheet->getAttribute('name');$rid=$sheet->getAttributeNS(self::RELS,'id');
   if(in_array($name,$keep,true)){$map[$oldIndex]=$newIndex++;}
   else{$rel=$relById[$rid]??null;if($rel){$t=$rel->getAttribute('Target');$part=str_starts_with($t,'/')?ltrim($t,'/'):'xl/'.$t;$removeParts[]=$part;$removeParts=array_merge($removeParts,self::dependents($zip,$part));$rel->parentNode->removeChild($rel);}
    $removedNames[]=$name;$sheet->parentNode->removeChild($sheet);}
   $oldIndex++;
  }
  // Nomes definidos: os de abas removidas saem; os locais são renumerados.
  foreach(iterator_to_array($xp->query('//m:definedNames/m:definedName')) as $dn){
   if($dn->hasAttribute('localSheetId')){$i=(int)$dn->getAttribute('localSheetId');if(!isset($map[$i])){$dn->parentNode->removeChild($dn);continue;}$dn->setAttribute('localSheetId',(string)$map[$i]);}
   foreach($removedNames as $n)if(str_contains($dn->textContent,"'".$n."'!")||str_contains($dn->textContent,$n.'!')){$dn->parentNode->removeChild($dn);break;}
  }
  $dns=$xp->query('//m:definedNames')->item(0);if($dns instanceof DOMElement&&!$dns->childNodes->length)$dns->parentNode->removeChild($dns);
  foreach($xp->query('//m:bookViews/m:workbookView') as $v){if($v instanceof DOMElement){$v->setAttribute('activeTab','0');$v->removeAttribute('firstSheet');}}
  // calcChain referencia abas pelo número: descartado.
  foreach(iterator_to_array($rels->getElementsByTagName('Relationship')) as $r)if(str_ends_with($r->getAttribute('Target'),'calcChain.xml')){$removeParts[]='xl/calcChain.xml';$r->parentNode->removeChild($r);}
  $wbXml=$wb->saveXML();$relsXml=$rels->saveXML();$zip->addFromString('xl/workbook.xml',$wbXml);$zip->addFromString('xl/_rels/workbook.xml.rels',$relsXml);
  // Partes ainda usadas pelas abas mantidas não são apagadas.
  $used=[];foreach(self::sheets($zip,$wbXml,$relsXml) as $part)$used=array_merge($used,[$part],self::dependents($zip,$part));
  $removeParts=array_values(array_diff(array_unique($removeParts),$used));
  $types=new DOMDocument();$types->loadXML((string)$zip->getFromName('[Content_Types].xml'));
  foreach(iterator_to_array($types->getElementsByTagName('Override')) as $o)if(in_array(ltrim($o->getAttribute('PartName'),'/'),$removeParts,true))$o->parentNode->removeChild($o);
  $zip->addFromString('[Content_Types].xml',$types->saveXML());
  foreach($removeParts as $part){$zip->deleteName($part);$rel=dirname($part).'/_rels/'.basename($part).'.rels';if($zip->locateName($rel)!==false)$zip->deleteName($rel);}
  $app=$zip->getFromName('docProps/app.xml');if($app!==false){$doc=new DOMDocument();if(@$doc->loadXML($app)){foreach(['HeadingPairs','TitlesOfParts'] as $tag)foreach(iterator_to_array($doc->getElementsByTagName($tag)) as $n)$n->parentNode->removeChild($n);$zip->addFromString('docProps/app.xml',$doc->saveXML());}}
  return [$wbXml,$relsXml];
 }
 /** Partes ligadas a uma parte (desenhos, imagens, configurações), em profundidade. */
 private static function dependents(ZipArchive $zip,string $part,int $depth=0):array {
  if($depth>4)return [];$relPath=dirname($part).'/_rels/'.basename($part).'.rels';$xml=$zip->getFromName($relPath);if($xml===false)return [];
  $doc=new DOMDocument();if(!@$doc->loadXML($xml))return [];$out=[];
  foreach($doc->getElementsByTagName('Relationship') as $r){if($r->getAttribute('TargetMode')==='External')continue;$t=$r->getAttribute('Target');$path=str_starts_with($t,'/')?ltrim($t,'/'):self::join(dirname($part),$t);$out[]=$path;$out=array_merge($out,self::dependents($zip,$path,$depth+1));}
  return $out;
 }
 private static function join(string $dir,string $rel):string {$parts=explode('/',$dir.'/'.$rel);$out=[];foreach($parts as $p){if($p==='..')array_pop($out);elseif($p!==''&&$p!=='.')$out[]=$p;}return implode('/',$out);}

 // ---------- utilidades do pacote xlsx ----------
 /** Abas e caminhos. Aceita o XML já alterado (o ZipArchive não relê partes regravadas antes de fechar). */
 private static function sheets(ZipArchive $zip,?string $workbookXml=null,?string $relsXml=null):array {
  $wb=new DOMDocument();$wb->loadXML($workbookXml??(string)$zip->getFromName('xl/workbook.xml'));$rels=new DOMDocument();$rels->loadXML($relsXml??(string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
  $target=[];foreach($rels->getElementsByTagName('Relationship') as $r)$target[$r->getAttribute('Id')]=$r->getAttribute('Target');
  $out=[];foreach($wb->getElementsByTagNameNS(self::NS,'sheet') as $s){$t=$target[$s->getAttributeNS(self::RELS,'id')]??'';$t=str_starts_with($t,'/')?ltrim($t,'/'):'xl/'.$t;$out[$s->getAttribute('name')]=$t;}
  return $out;
 }
 private static function sharedList(ZipArchive $zip):array {
  $xml=$zip->getFromName('xl/sharedStrings.xml');if(!$xml)return [];$doc=new DOMDocument();$doc->loadXML($xml);$out=[];
  foreach($doc->getElementsByTagNameNS(self::NS,'si') as $si){$text='';foreach($si->getElementsByTagNameNS(self::NS,'t') as $t)$text.=$t->textContent;$out[]=$text;}return $out;
 }
 private static function plain(string $xml):string {return html_entity_decode(strip_tags($xml));}
 private static function shared(ZipArchive $zip,string $sheetXml):string {return implode("\n",self::sharedList($zip));}
}

/**
 * Edição de uma aba do FOR 024. As colunas são localizadas pelo cabeçalho (linha com "PLACA"),
 * porque o layout muda entre os anos (2021–2022, 2023 e 2024 em diante). Blocos por mês.
 */
final class ControlSheet {
 private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
 public bool $changed=false;
 /** Cabeçalho de mês usado como modelo (estilo, altura) — guardado antes de limpar a aba. */
 private ?array $monthModel=null;
 private DOMXPath $xp;private DOMElement $data;private array $rows=[];private int $first=1;private int $last=12;private array $cols=[];private array $headers=[];private array $styles=[];private array $existing=[];

 /** Cabeçalho normalizado → campo. "NOME DO CLIENTE" repetido (2021/2022) = cliente e beneficiário. */
 public static function headerField(string $h,array $used):?string {
  $h=trim(preg_replace('/[^a-z0-9\/ ]/','',str_replace('?','',WebAssistPdf::norm($h))));
  return match(true){
   $h==='cidade'=>'cidade',$h==='estado'||$h==='uf'=>'estado',$h==='data'||str_starts_with($h,'data ')=>'data',$h==='hora'=>'hora',
   $h==='cliente'=>'cliente',$h==='nome do cliente'=>isset($used['cliente'])?'beneficiario':'cliente',
   str_contains($h,'beneficiario')=>'beneficiario',$h==='placa'=>'placa',str_starts_with($h,'marca/modelo')||str_starts_with($h,'marca modelo')=>'veiculo',
   str_starts_with($h,'tipo')=>'tipo',$h==='rastreador'=>'rastreador',str_starts_with($h,'veiculo localizado')||str_starts_with($h,'veiculo  localizado')=>'localizado',
   str_starts_with($h,'furto/roubo')||str_starts_with($h,'furto roubo')=>'evento',$h==='id rastreador'=>'recuperado',
   str_starts_with($h,'endereco')=>'endereco',str_starts_with($h,'observ')=>'observacao',default=>null};
 }

 public function __construct(private DOMDocument $doc,private array $strings){
  $this->xp=new DOMXPath($doc);$this->xp->registerNamespace('m',self::NS);$data=$this->xp->query('//m:sheetData')->item(0);if(!$data instanceof DOMElement)throw new RuntimeException('Aba sem dados.');$this->data=$data;
  foreach($this->xp->query('m:row',$data) as $row)$this->rows[(int)$row->getAttribute('r')]=$row;
  foreach($this->rows as $r=>$row){if($r>10)break;$cells=[];foreach($this->xp->query('m:c',$row) as $c)$cells[self::colIndex($c->getAttribute('r'))]=$this->value($c);
   if(!array_filter($cells,fn($v)=>WebAssistPdf::norm($v)==='placa'))continue;
   ksort($cells);$used=[];foreach($cells as $col=>$text){$field=self::headerField($text,$used);if($field!==null&&!isset($used[$field])){$used[$field]=true;$this->cols[$field]=$col;}}
   $this->headers['row']=$r;break;}
  if(!$this->cols)throw new InvalidArgumentException('Cabeçalho do FOR 024 não encontrado nesta aba.');
  $this->first=min($this->cols);$this->last=max($this->cols);$this->scan();
 }
 public function columns():array {return $this->cols;}
 private function scan():void {
  $this->headers['months']=[];$this->existing=[];$counts=[];$monthNames=array_map(fn($m)=>WebAssistPdf::norm($m),ControlWorkbook::MONTHS);$current=null;$header=$this->headers['row']??3;
  foreach($this->rows as $r=>$row){if($r<=$header)continue;$cells=$this->cells($row);$filled=array_filter($cells,fn($v)=>trim($v)!=='');if(!$filled)continue;
   if(count($filled)===1){$month=array_search(WebAssistPdf::norm(reset($filled)),$monthNames,true);
    if($month!==false){$current=(int)$month;$this->headers['months'][$current]=$r;if($this->monthModel===null){$style=null;foreach($this->xp->query('m:c',$row) as $c)if($this->value($c)!==''){$style=$c->getAttribute('s');break;}$this->monthModel=['s'=>$style,'ht'=>$row->getAttribute('ht'),'customHeight'=>$row->getAttribute('customHeight')];}continue;}}
   $rec=$this->record($row);if($rec['placa']===''&&$rec['veiculo']===''&&$rec['cliente']==='')continue;$rec['row']=$r;$rec['month']=$current;$this->existing[]=$rec;
   $byCol=array_flip($this->cols);
   foreach($this->xp->query('m:c',$row) as $c){$field=$byCol[self::colIndex($c->getAttribute('r'))]??null;if($field===null)continue;$v=WebAssistPdf::norm($this->value($c));$st=$c->getAttribute('s');$counts[$field.'|'.$v][$st]=($counts[$field.'|'.$v][$st]??0)+1;$counts[$field.'|*'][$st]=($counts[$field.'|*'][$st]??0)+1;if($field==='data'&&$c->getAttribute('t')===''&&is_numeric($this->value($c)))$counts['data|date'][$st]=($counts['data|date'][$st]??0)+1;}
   $this->styles['row']??=['ht'=>$row->getAttribute('ht'),'customHeight'=>$row->getAttribute('customHeight')];}
  // Estilo mais usado em cada coluna (e para cada valor, ex.: SIM/NÃO), igual às linhas lançadas à mão.
  foreach($counts as $key=>$byStyle){arsort($byStyle);$this->styles[$key]=(string)array_key_first($byStyle);}
 }
 /** Valores das colunas do cabeçalho (por número da coluna). */
 private function cells(DOMElement $row):array {$out=[];foreach($this->xp->query('m:c',$row) as $c){$i=self::colIndex($c->getAttribute('r'));if($i>=$this->first&&$i<=$this->last)$out[$i]=$this->value($c);}return $out;}
 private function record(DOMElement $row):array {
  $byCol=array_flip($this->cols);$rec=array_fill_keys(array_merge(ControlWorkbook::FIELDS,['hora','endereco','observacao']),'');$date=null;$text='';
  foreach($this->xp->query('m:c',$row) as $c){$field=$byCol[self::colIndex($c->getAttribute('r'))]??null;if($field===null)continue;$value=$this->value($c);$rec[$field]=trim(preg_replace('/\s+/u',' ',$value));
   if($field==='data'){if($c->getAttribute('t')===''&&is_numeric($value)&&(float)$value>20000)$date=ControlWorkbook::iso((float)$value);else $text=trim($value);}
   if($field==='hora'&&is_numeric($value)&&(float)$value<1){$m=(int)round((float)$value*1440);$rec['hora']=sprintf('%02d:%02d',intdiv($m,60),$m%60);}}
  if($date===null&&preg_match('~(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})~',$text,$m)&&checkdate((int)$m[2],(int)$m[1],(int)$m[3]))$date=sprintf('%04d-%02d-%02d',$m[3],$m[2],$m[1]);
  $rec['data']=$date===null?$text:'';$rec['date']=$date;$rec['date_text']=$date===null?$text:'';$rec['placa_key']=preg_replace('/[^A-Z0-9]/','',strtoupper($rec['placa']));return $rec;
 }
 /** Remove as linhas abaixo do cabeçalho (histórico) e suas mesclagens; mantém os estilos aprendidos. */
 public function clearData():void {
  $header=$this->headers['row']??3;
  foreach($this->rows as $r=>$row)if($r>$header){$row->parentNode->removeChild($row);unset($this->rows[$r]);}
  foreach(iterator_to_array($this->xp->query('//m:mergeCell')) as $m){if(preg_match('/[A-Z]+(\d+)/',$m->getAttribute('ref'),$x)&&(int)$x[1]>$header)$m->parentNode->removeChild($m);}
  $mc=$this->xp->query('//m:mergeCells')->item(0);if($mc instanceof DOMElement)$mc->setAttribute('count',(string)$this->xp->query('m:mergeCell',$mc)->length);
  $dim=$this->xp->query('//m:dimension')->item(0);if($dim instanceof DOMElement)$dim->setAttribute('ref',preg_replace('/\d+$/',(string)$header,$dim->getAttribute('ref')));
  $this->existing=[];$this->headers['months']=[];$this->changed=true;
 }
 /** Registros já lançados (para aprender tipos, importar o histórico e evitar duplicidade). */
 public function records():array {return $this->existing;}

 /** Motivo para não lançar (já está na planilha ou é o mesmo veículo em poucos dias), ou null. */
 public function duplicate(array $item,int $days):?string {
  $key=preg_replace('/[^A-Z0-9]/','',strtoupper($item['placa']));if($key==='')return null;
  foreach($this->existing as $rec){if($rec['placa_key']!==$key)continue;
   if(empty($item['date'])||$rec['date']===null){if(($item['date']??null)===null&&($item['date_text']??'')===$rec['date_text'])return 'Placa já lançada na linha '.$rec['row'].' da planilha';continue;}
   $diff=abs((int)(new DateTimeImmutable($item['date']))->diff(new DateTimeImmutable($rec['date']))->format('%a'));if($diff<=$days)return $diff===0?'Placa já lançada na linha '.$rec['row'].' (mesma data)':'Mesmo veículo lançado na linha '.$rec['row'].' há '.$diff.' dia(s)';}
  return null;
 }

 public function add(array $item,int $month):void {
  $header=$this->headers['months'][$month]??null;
  if($header===null){$header=$this->createMonth($month);}
  // Última linha do bloco do mês e início do próximo bloco.
  $next=null;foreach($this->headers['months'] as $m=>$r)if($r>$header&&($next===null||$r<$next))$next=$r;
  $last=$header;foreach($this->existing as $rec)if($rec['row']>$header&&($next===null||$rec['row']<$next))$last=max($last,$rec['row']);
  $target=$last+1;if($next!==null&&$target>=$next){$this->shift($target,1);}
  $this->writeRow($target,$item);
  $rec=$item+['row'=>$target,'month'=>$month,'placa_key'=>preg_replace('/[^A-Z0-9]/','',strtoupper($item['placa'])),'date_text'=>$item['date_text']??''];$rec['date']=$item['date']??null;$this->existing[]=$rec;$this->changed=true;
 }
 private function createMonth(int $month):int {
  // Novo bloco depois do último bloco existente (meses em ordem).
  $after=$this->headers['row']??3;foreach($this->headers['months'] as $m=>$r)if($m<$month)$after=max($after,$r);
  foreach($this->existing as $rec)if($rec['row']>$after&&($rec['month']??0)<$month)$after=max($after,$rec['row']);
  $next=null;foreach($this->headers['months'] as $m=>$r)if($m>$month&&($next===null||$r<$next))$next=$r;
  $target=$after+1;if($next!==null&&$target>=$next)$this->shift($target,1);
  // Copia o cabeçalho de mês existente (estilo, altura e mesclagem).
  $model=$this->headers['months']?$this->rows[end($this->headers['months'])]??null:null;$row=$this->row($target);
  if($model){foreach(['ht','customHeight'] as $a)if($model->hasAttribute($a))$row->setAttribute($a,$model->getAttribute($a));}elseif($this->monthModel){foreach(['ht','customHeight'] as $a)if(($this->monthModel[$a]??'')!=='')$row->setAttribute($a,$this->monthModel[$a]);}
  $style=$this->monthModel['s']??null;if($model)foreach($this->xp->query('m:c',$model) as $c)if($this->value($c)!==''){$style=$c->getAttribute('s');break;}
  $this->setText($row,$this->first,ControlWorkbook::MONTHS[$month],$style);
  $this->merge(self::colName($this->first).$target.':'.self::colName($this->last).$target);
  $this->headers['months'][$month]=$target;$this->changed=true;return $target;
 }
 private function writeRow(int $r,array $item):void {
  $row=$this->row($r);if(isset($this->styles['row'])){foreach($this->styles['row'] as $a=>$v)if($v!==''&&!$row->hasAttribute($a))$row->setAttribute($a,$v);}
  foreach($this->cols as $field=>$col){
   if($field==='data'){if(!empty($item['date']))$this->setNumber($row,$col,ControlWorkbook::serial($item['date']),$this->styles['data|date']??$this->styles['data|*']??null);else{$t=(string)($item['date_text']??'');$t===''?$this->clear($row,$col,$this->styles['data|*']??null):$this->setText($row,$col,$t,$this->styles['data|*']??null);}continue;}
   $value=(string)($item[$field]??'');$style=$this->styles[$field.'|'.WebAssistPdf::norm($value)]??$this->styles[$field.'|*']??null;
   if($value===''){$this->clear($row,$col,$style);continue;}
   $this->setText($row,$col,$value,$style);}
 }
 /** Atualiza a data de "ÚLTIMA ATUALIZAÇÃO". */
 public function stamp(int $serial):void {foreach($this->rows as $r=>$row){if($r>5)break;$cells=iterator_to_array($this->xp->query('m:c',$row));foreach($cells as $i=>$c)if(WebAssistPdf::norm($this->value($c))==='ultima atualizacao'){$next=self::colIndex($c->getAttribute('r'))+1;$style=null;foreach($cells as $d)if(self::colIndex($d->getAttribute('r'))===$next)$style=$d->getAttribute('s');$this->setNumber($row,$next,$serial,$style);return;}}}

 // ---------- células ----------
 private function row(int $r):DOMElement {
  if(isset($this->rows[$r]))return $this->rows[$r];$row=$this->doc->createElementNS(self::NS,'row');$row->setAttribute('r',(string)$r);
  $after=null;foreach($this->rows as $n=>$el)if($n>$r){$after=$el;break;}if($after)$this->data->insertBefore($row,$after);else $this->data->appendChild($row);$this->rows[$r]=$row;ksort($this->rows);return $row;
 }
 private function cell(DOMElement $row,int $col):DOMElement {
  $ref=self::colName($col).$row->getAttribute('r');$before=null;
  foreach($this->xp->query('m:c',$row) as $c){$i=self::colIndex($c->getAttribute('r'));if($i===$col)return $c;if($i>$col){$before=$c;break;}}
  $c=$this->doc->createElementNS(self::NS,'c');$c->setAttribute('r',$ref);if($before)$row->insertBefore($c,$before);else $row->appendChild($c);
  $spans=$row->getAttribute('spans');if($spans!==''&&preg_match('/^(\d+):(\d+)$/',$spans,$m)&&($col<(int)$m[1]||$col>(int)$m[2]))$row->setAttribute('spans',min($col,(int)$m[1]).':'.max($col,(int)$m[2]));
  return $c;
 }
 private function reset(DOMElement $c,?string $style):void {while($c->firstChild)$c->removeChild($c->firstChild);$c->removeAttribute('t');if($style!==null&&$style!=='')$c->setAttribute('s',$style);}
 private function setText(DOMElement $row,int $col,string $text,?string $style):void {$c=$this->cell($row,$col);$this->reset($c,$style);$c->setAttribute('t','inlineStr');$is=$this->doc->createElementNS(self::NS,'is');$t=$this->doc->createElementNS(self::NS,'t');$t->appendChild($this->doc->createTextNode($text));if($text!==trim($text))$t->setAttribute('xml:space','preserve');$is->appendChild($t);$c->appendChild($is);}
 private function setNumber(DOMElement $row,int $col,int|float $n,?string $style):void {$c=$this->cell($row,$col);$this->reset($c,$style);$v=$this->doc->createElementNS(self::NS,'v');$v->appendChild($this->doc->createTextNode((string)$n));$c->appendChild($v);}
 private function clear(DOMElement $row,int $col,?string $style):void {$c=$this->cell($row,$col);$this->reset($c,$style);}
 private function value(DOMElement $c):string {
  $t=$c->getAttribute('t');if($t==='inlineStr'){$s='';foreach($c->getElementsByTagNameNS(self::NS,'t') as $x)$s.=$x->textContent;return $s;}
  $v=$this->xp->query('m:v',$c)->item(0)?->textContent;if($v===null)return '';if($t==='s')return $this->strings[(int)$v]??'';return $v;
 }
 private function merge(string $ref):void {
  $mc=$this->xp->query('//m:mergeCells')->item(0);if(!$mc instanceof DOMElement){$mc=$this->doc->createElementNS(self::NS,'mergeCells');$after=$this->xp->query('//m:sheetData')->item(0)->nextSibling;$after?$this->doc->documentElement->insertBefore($mc,$after):$this->doc->documentElement->appendChild($mc);}
  $m=$this->doc->createElementNS(self::NS,'mergeCell');$m->setAttribute('ref',$ref);$mc->appendChild($m);$mc->setAttribute('count',(string)$this->xp->query('m:mergeCell',$mc)->length);
 }
 /** Desloca para baixo as linhas a partir de $from (mesclagens e referências da aba acompanham). */
 private function shift(int $from,int $by):void {
  $rows=$this->rows;krsort($rows);$new=[];
  foreach($rows as $r=>$row){if($r<$from){$new[$r]=$row;continue;}$nr=$r+$by;$row->setAttribute('r',(string)$nr);foreach($this->xp->query('m:c',$row) as $c){$c->setAttribute('r',preg_replace('/\d+$/',(string)$nr,$c->getAttribute('r')));}$new[$nr]=$row;}
  ksort($new);$this->rows=$new;
  foreach($this->xp->query('//m:mergeCell') as $m)$m->setAttribute('ref',preg_replace_callback('/([A-Z]+)(\d+)/',fn($x)=>$x[1].((int)$x[2]>=$from?(int)$x[2]+$by:(int)$x[2]),$m->getAttribute('ref')));
  $dim=$this->xp->query('//m:dimension')->item(0);if($dim instanceof DOMElement)$dim->setAttribute('ref',preg_replace_callback('/([A-Z]+)(\d+)$/',fn($x)=>$x[1].((int)$x[2]>=$from?(int)$x[2]+$by:(int)$x[2]),$dim->getAttribute('ref')));
  foreach($this->headers['months'] as $m=>$r)if($r>=$from)$this->headers['months'][$m]=$r+$by;
  foreach($this->existing as &$rec)if($rec['row']>=$from)$rec['row']+=$by;unset($rec);
 }
 private static function colIndex(string $ref):int {preg_match('/^([A-Z]+)/',$ref,$m);$n=0;foreach(str_split($m[1]) as $ch)$n=$n*26+ord($ch)-64;return $n;}
 private static function colName(int $n):string {$s='';while($n>0){$m=($n-1)%26;$s=chr(65+$m).$s;$n=intdiv($n-1,26);}return $s;}
}
