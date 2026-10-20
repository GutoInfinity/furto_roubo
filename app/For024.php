<?php
declare(strict_types=1);
require_once __DIR__.'/WebAssistPdf.php';
require_once __DIR__.'/ControlWorkbook.php';
/**
 * Controle de Roubos e Furtos (FOR 024) dentro do sistema.
 * Origens: PDF "Relatório de Furto / Roubo" do WebAssist e o histórico da própria planilha FOR 024.
 * Cada ocorrência guarda as colunas da planilha em original_json.planilha. O que o PDF não traz
 * (cidade, UF, rastreador, furto/roubo) fica vazio até ser preenchido no sistema.
 */
final class For024 {
 public const ORIGIN='webassist_pdf';
 public const HISTORY='for024_planilha';
 /** Colunas editáveis no sistema e os valores aceitos (lista vazia = texto livre). */
 public const EDITABLE=['cidade'=>[],'estado'=>['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'],'tipo'=>['VEICULO LEVE','MOTO','UTILITARIO','PESADO'],'rastreador'=>['SIM','NÃO'],'localizado'=>['SIM','NÃO'],'evento'=>['FURTO','ROUBO'],'recuperado'=>['RECUPERADO']];

 // ---------- PDF ----------
 /** Prévia do PDF: o que é novo, o que já foi importado e o que não irá para o Excel por ser o mesmo veículo. */
 public static function preview(PDO $db,string $path,string $filename):array {
  $report=WebAssistPdf::read($path);$learned=ControlWorkbook::vehicleTypes();$rows=[];$seen=[];
  $known=$db->prepare('SELECT id FROM fb_occurrences WHERE source_key=?');
  $recent=$db->prepare("SELECT id FROM fb_occurrences WHERE REPLACE(REPLACE(placa,'-',''),' ','')=? AND data_evento BETWEEN ? AND ? LIMIT 1");
  foreach($report['rows'] as $i=>$r){
   $date=Records::date($r['data']);$known->execute([self::pdfKey($r)]);$existing=$known->fetchColumn();
   $type=ControlWorkbook::guessType($r['montadora'],$r['modelo'],$learned);$status=$existing?'ja_importada':'nova';$note='';
   if(!$existing&&$date){
    foreach($seen as [$plate,$d,$line])if($plate===$r['placa']&&abs((strtotime($date)-strtotime($d))/86400)<=ControlWorkbook::DUPLICATE_DAYS){$note='Mesmo veículo da linha '.$line.' do PDF.';break;}
    if($note===''){$from=date('Y-m-d',strtotime($date.' -'.ControlWorkbook::DUPLICATE_DAYS.' days'));$to=date('Y-m-d',strtotime($date.' +'.ControlWorkbook::DUPLICATE_DAYS.' days'));$recent->execute([$r['placa'],$from,$to]);$other=$recent->fetchColumn();if($other)$note='Mesmo veículo já cadastrado (FB-'.str_pad((string)$other,6,'0',STR_PAD_LEFT).').';}
   }
   if($date)$seen[]=[$r['placa'],$date,$i+1];
   $rows[]=['line'=>$i+1,'status'=>$status,'nota'=>$note,'data_iso'=>$date,'tipo_veiculo'=>$type]+$r;
  }
  return ['filename'=>$filename,'generated'=>$report['generated'],'warnings'=>$report['warnings'],'rows'=>$rows,'template'=>ControlWorkbook::hasTemplate()];
 }

 /** Grava as ocorrências novas da prévia do PDF. */
 public static function commit(PDO $db,array $plan,int $user,string $fileHash):array {
  $inserted=0;$skipped=0;$ids=[];$db->beginTransaction();
  try{
   // A importação é registrada primeiro: cada ocorrência guarda o número dela (permite excluir e reenviar).
   $import=self::newImport($db,$fileHash,$plan['filename'],count($plan['rows']),$user);
   foreach($plan['rows'] as $r){
    if($r['status']!=='nova'||!$r['data_iso']){$skipped++;continue;}
    $found=str_contains(WebAssistPdf::norm($r['situacao']),'recuper');
    $sheet=['cidade'=>'','estado'=>'','cliente'=>$r['cliente'],'beneficiario'=>$r['beneficiario'],'placa'=>$r['placa'],'veiculo'=>trim($r['montadora'].' / '.$r['modelo'],' /'),'tipo'=>$r['tipo_veiculo'],'rastreador'=>'','localizado'=>$found?'SIM':'NÃO','evento'=>'','recuperado'=>$found?'RECUPERADO':'','date_text'=>''];
    $original=['origem'=>self::ORIGIN,'importacao'=>$import,'arquivo'=>$plan['filename'],'gerado_em'=>$plan['generated'],'tipo_veiculo'=>$r['tipo_veiculo'],'planilha'=>$sheet]+array_intersect_key($r,array_flip(['cliente','data','modelo','montadora','beneficiario','placa','protocolo','situacao','valor']));
    $saved=Records::save($db,self::recordInput($sheet,$r['data_iso'],$r['data'],self::ORIGIN,'Protocolo WebAssist: '.$r['protocolo']),$user,null,null,'Importação',true,self::pdfKey($r),$original);$ids[]=$saved['id'];$inserted++;
   }
   $db->prepare('UPDATE fb_imports SET inserted=?,skipped=? WHERE id=?')->execute([$inserted,$skipped,$import]);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return ['inserted'=>$inserted,'skipped'=>$skipped,'ids'=>$ids,'import'=>$import];
 }

 // ---------- histórico da planilha ----------
 /** Importa as linhas já lançadas no FOR 024 (todas as abas por ano). Mesma placa e data do PDF completa a ocorrência existente. */
 public static function importHistory(PDO $db,string $path,string $filename,int $user):array {
  $rows=ControlWorkbook::history($path);if(!$rows)throw new InvalidArgumentException('Não encontrei linhas lançadas nas abas por ano do FOR 024.');
  $result=['inserted'=>0,'merged'=>0,'skipped'=>0,'invalid'=>0,'por_aba'=>[]];$byKey=$db->prepare('SELECT id FROM fb_occurrences WHERE source_key=?');
  $same=$db->prepare("SELECT id,original_json FROM fb_occurrences WHERE REPLACE(REPLACE(placa,'-',''),' ','')=? AND data_evento=? AND original_json LIKE ? LIMIT 1");
  $db->beginTransaction();
  try{
   $import=self::newImport($db,hash_file('sha256',$path),$filename,count($rows),$user);
   foreach($rows as $r){
    $plate=preg_replace('/[^A-Z0-9]/','',strtoupper($r['placa']));if($plate===''){$result['invalid']++;continue;}
    $key=hash('sha256','for024|'.$r['aba'].'|'.$plate.'|'.($r['date']??$r['date_text']).'|'.WebAssistPdf::norm($r['cliente']).'|'.$r['order']);
    $byKey->execute([$key]);if($byKey->fetchColumn()){$result['skipped']++;continue;}
    $sheet=self::fromSheet($r);
    if($r['date']){$same->execute([$plate,$r['date'],'%"origem":"'.self::ORIGIN.'"%']);$hit=$same->fetch();
     if($hit){$o=json_decode((string)$hit['original_json'],true)?:[];$current=$o['planilha']??[];foreach($sheet as $k=>$v)if($v!=='')$current[$k]=$v;$o['planilha']=$current;$o['planilha_aba']=$r['aba'];self::store($db,(int)$hit['id'],$o,$user,'Completada pela planilha FOR 024');$result['merged']++;continue;}}
    $original=['origem'=>self::HISTORY,'importacao'=>$import,'arquivo'=>$filename,'aba'=>$r['aba'],'linha'=>$r['row'],'ano'=>$r['year'],'mes'=>$r['month'],'ordem'=>$r['order'],'tipo_veiculo'=>$sheet['tipo'],'planilha'=>$sheet];
    $obs=trim(implode(' · ',array_filter([$r['hora']?'Hora: '.$r['hora']:'',$r['endereco']?'Endereço: '.$r['endereco']:'',$r['observacao']])));
    Records::save($db,self::recordInput($sheet,$r['date'],$r['date']?'':$r['date_text'],self::HISTORY,$obs),$user,null,null,'Importação',true,$key,$original);
    $result['inserted']++;$result['por_aba'][$r['aba']]=($result['por_aba'][$r['aba']]??0)+1;
   }
   // Nada novo: a importação não fica registrada (não há o que excluir depois).
   if(!$result['inserted']&&!$result['merged']){$db->prepare('DELETE FROM fb_imports WHERE id=?')->execute([$import]);$import=0;}
   else $db->prepare('UPDATE fb_imports SET inserted=?,updated=?,skipped=? WHERE id=?')->execute([$result['inserted'],$result['merged'],$result['skipped']+$result['invalid'],$import]);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return $result+['import'=>$import];
 }
 private static function fromSheet(array $r):array {
  $up=fn($v)=>mb_strtoupper(trim((string)$v),'UTF-8');$yes=fn($v)=>match(WebAssistPdf::norm((string)$v)){'sim','s'=>'SIM','nao','n'=>'NÃO',default=>$up($v)};
  return ['cidade'=>$up($r['cidade']),'estado'=>$up($r['estado']),'cliente'=>trim($r['cliente']),'beneficiario'=>trim($r['beneficiario']),'placa'=>$up($r['placa']),'veiculo'=>trim($r['veiculo']),'tipo'=>ControlWorkbook::type($r['tipo'])?:$up($r['tipo']),'rastreador'=>$yes($r['rastreador']),'localizado'=>$yes($r['localizado']),'evento'=>$up($r['evento']),'recuperado'=>$up($r['recuperado']),'date_text'=>(string)($r['date_text']??''),'hora'=>(string)($r['hora']??''),'endereco'=>trim((string)($r['endereco']??'')),'observacao'=>trim((string)($r['observacao']??''))];
 }

 // ---------- edição no sistema ----------
 /** Altera uma coluna da planilha de uma ocorrência (cidade, UF, tipo, rastreador, localizado, furto/roubo, recuperado). */
 public static function update(PDO $db,int $id,string $field,string $value,int $user):array {
  if(!array_key_exists($field,self::EDITABLE))throw new InvalidArgumentException('Campo não editável.');
  $value=mb_strtoupper(trim($value),'UTF-8');if(mb_strlen($value)>120)throw new InvalidArgumentException('Texto muito longo.');
  $allowed=self::EDITABLE[$field];if($value!==''&&$allowed&&!in_array($value,$allowed,true))throw new InvalidArgumentException('Valor inválido para '.$field.'.');
  $q=$db->prepare('SELECT original_json FROM fb_occurrences WHERE id=?');$q->execute([$id]);$raw=$q->fetchColumn();if($raw===false)throw new OutOfBoundsException('Ocorrência não encontrada.');
  $o=json_decode((string)$raw,true)?:[];if(!in_array($o['origem']??'',[self::ORIGIN,self::HISTORY],true))throw new InvalidArgumentException('Esta ocorrência não pertence ao FOR 024.');
  $sheet=self::sheetOf($o,null);$before=$sheet[$field]??'';if($before===$value)return ['id'=>$id,'planilha'=>$sheet];
  $sheet[$field]=$value;if($field==='recuperado'&&$value==='RECUPERADO')$sheet['localizado']='SIM';if($field==='tipo')$o['tipo_veiculo']=$value;$o['planilha']=$sheet;
  self::store($db,$id,$o,$user,'FOR 024 · '.$field.': '.($before===''?'(vazio)':$before).' → '.($value===''?'(vazio)':$value));
  return ['id'=>$id,'planilha'=>$sheet];
 }
 /** Grava a planilha no JSON e sincroniza município, tipo do evento e situação do cadastro. */
 private static function store(PDO $db,int $id,array $o,int $user,string $summary):void {
  $sheet=$o['planilha'];$city=trim($sheet['cidade'].($sheet['estado']!==''?'/'.$sheet['estado']:''),'/');$recovered=$sheet['recuperado']!==''||$sheet['localizado']==='SIM';
  $q=$db->prepare('SELECT situacao,etapa FROM fb_occurrences WHERE id=?');$q->execute([$id]);$cur=$q->fetch();
  $situacao=$recovered?'Recuperado':(($o['origem']??'')===self::ORIGIN?'Em buscas':($sheet['localizado']==='NÃO'?'Não localizado':'Não informada'));
  $etapa=$recovered?'Recuperado':($cur['etapa']==='Recuperado'?(($o['origem']??'')===self::ORIGIN?'Em buscas':'Registrado'):$cur['etapa']);
  $evento=['FURTO'=>'Furto','ROUBO'=>'Roubo'][$sheet['evento']]??'Não informado';
  $db->prepare('UPDATE fb_occurrences SET original_json=?,municipio=?,evento=?,situacao=?,etapa=?,atualizado_por=?,revision=revision+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=?')->execute([json_encode($o,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$city,$evento,$situacao,$etapa,$user,$id]);
  $db->prepare('INSERT INTO fb_history(occurrence_id,user_id,action,changes_json) VALUES(?,?,?,?)')->execute([$id,$user,'Edição',json_encode(['resumo'=>$summary],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
 }
 private static function recordInput(array $sheet,?string $date,string $dateText,string $origin,string $obs):array {
  $recovered=$sheet['recuperado']!==''||$sheet['localizado']==='SIM';$city=trim($sheet['cidade'].($sheet['estado']!==''?'/'.$sheet['estado']:''),'/');
  return ['placa'=>$sheet['placa'],'veiculo'=>$sheet['veiculo']!==''?$sheet['veiculo']:'Não informado','data_evento'=>$date??'','data_original'=>$dateText,'vitima'=>$sheet['beneficiario'],'associacao'=>$sheet['cliente'],'municipio'=>$city,
   'situacao'=>$recovered?'Recuperado':($origin===self::ORIGIN?'Em buscas':($sheet['localizado']==='NÃO'?'Não localizado':'Não informada')),'evento'=>['FURTO'=>'Furto','ROUBO'=>'Roubo'][$sheet['evento']]??'Não informado','observacoes'=>$obs];
 }
 /** Planilha da ocorrência; registros antigos do PDF (sem "planilha") são montados a partir do PDF. */
 private static function sheetOf(array $o,?array $row):array {
  $base=['cidade'=>'','estado'=>'','cliente'=>'','beneficiario'=>'','placa'=>'','veiculo'=>'','tipo'=>'','rastreador'=>'','localizado'=>'','evento'=>'','recuperado'=>'','date_text'=>''];
  if(isset($o['planilha'])&&is_array($o['planilha']))return array_replace($base,$o['planilha']);
  $found=str_contains(WebAssistPdf::norm((string)($o['situacao']??'')),'recuper');
  return array_replace($base,['cliente'=>(string)($o['cliente']??''),'beneficiario'=>(string)($o['beneficiario']??''),'placa'=>(string)($o['placa']??''),'veiculo'=>trim(($o['montadora']??'').' / '.($o['modelo']??''),' /'),'tipo'=>(string)($o['tipo_veiculo']??''),'localizado'=>$found?'SIM':'NÃO','recuperado'=>$found?'RECUPERADO':'']);
 }

 // ---------- leitura ----------
 /** Ocorrências do FOR 024 (PDF e/ou histórico) com o JSON decodificado. */
 private static function origin(PDO $db,array $origins=[self::ORIGIN,self::HISTORY]):array {
  $parts=[];$params=[];foreach($origins as $i=>$o){$parts[]="original_json LIKE :o$i";$params["o$i"]='%"origem":"'.$o.'"%';}
  $q=$db->prepare('SELECT * FROM fb_occurrences WHERE '.implode(' OR ',$parts).' ORDER BY data_evento,id');$q->execute($params);$out=[];
  foreach($q->fetchAll() as $row){$o=json_decode((string)$row['original_json'],true)?:[];if(!in_array($o['origem']??'',$origins,true))continue;$row['original']=$o;$row['planilha']=self::sheetOf($o,$row);$out[]=$row;}
  return $out;
 }
 /** Linhas no formato das colunas do FOR 024 (valores do sistema, inclusive os preenchidos depois). */
 public static function rows(PDO $db,?array $only=null,array $origins=[self::ORIGIN,self::HISTORY]):array {
  $only=$only===null?null:array_flip(array_map('intval',$only));$out=[];$warnings=[];
  foreach(self::origin($db,$origins) as $row){
   if($only!==null&&!isset($only[(int)$row['id']]))continue;$s=$row['planilha'];$o=$row['original'];
   $year=self::year($row);
   if(!$row['data_evento']&&!$year){$warnings[]='FB-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT).' sem data: não exportada.';continue;}
   $out[]=['id'=>(int)$row['id'],'date'=>$row['data_evento'],'date_text'=>$row['data_evento']?'':($s['date_text']?:$row['data_original']),'year'=>$year,'month'=>(int)($o['mes']??0),'order'=>(int)($o['ordem']??0),
    'cidade'=>$s['cidade'],'estado'=>$s['estado'],'cliente'=>$s['cliente']?:$row['associacao'],'beneficiario'=>$s['beneficiario']?:$row['vitima'],'placa'=>$s['placa']?:$row['placa'],'veiculo'=>$s['veiculo']?:$row['veiculo'],'tipo'=>$s['tipo'],
    'rastreador'=>$s['rastreador'],'localizado'=>$s['localizado'],'evento'=>$s['evento'],'recuperado'=>$s['recuperado'],'hora'=>(string)($s['hora']??''),'endereco'=>(string)($s['endereco']??''),'observacao'=>(string)($s['observacao']??''),'protocolo'=>(string)($o['protocolo']??'')];
  }
  return ['rows'=>$out,'warnings'=>$warnings];
 }

 /** Importações (PDF e histórico) com quantas ocorrências ainda existem de cada uma. */
 public static function imports(PDO $db):array {
  $origin=self::origin($db);$linked=[];foreach($origin as $row)if(isset($row['original']['importacao']))$linked[(int)$row['original']['importacao']]=true;
  $rows=$db->query("SELECT i.id,i.filename,i.total,i.inserted,i.updated,i.skipped,i.created_at,u.name user_name FROM fb_imports i JOIN fb_users u ON u.id=i.user_id ORDER BY i.id DESC LIMIT 100")->fetchAll();$out=[];
  foreach($rows as $r){$r['id']=(int)$r['id'];$pdf=str_ends_with(strtolower($r['filename']),'.pdf');if(!$pdf&&!isset($linked[$r['id']]))continue;$r['tipo']=$pdf?'pdf':'planilha';$r['ativas']=count(self::belonging($origin,$r));$out[]=$r;}
  return $out;
 }
 /** Ocorrências de uma importação; as antigas sem número são reconhecidas pelo arquivo e horário (até 2 minutos). */
 private static function belonging(array $origin,array $import):array {
  $ids=[];$at=strtotime((string)$import['created_at']);
  foreach($origin as $row){$o=$row['original'];
   if(isset($o['importacao'])){if((int)$o['importacao']===(int)$import['id'])$ids[]=(int)$row['id'];continue;}
   if(($o['arquivo']??'')===$import['filename']&&abs(strtotime((string)$row['criado_em'])-$at)<=120)$ids[]=(int)$row['id'];}
  return $ids;
 }
 /** Exclui as ocorrências de uma importação (PDF ou histórico) e o registro dela, para permitir reenviar o arquivo. */
 public static function deleteImport(PDO $db,int $import):array {
  $q=$db->prepare('SELECT id,filename,created_at FROM fb_imports WHERE id=?');$q->execute([$import]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Importação não encontrada.');
  $origin=self::origin($db);$ids=self::belonging($origin,$row);if(!$ids&&!str_ends_with(strtolower($row['filename']),'.pdf'))throw new InvalidArgumentException('Esta importação não é do FOR 024.');
  $db->beginTransaction();
  try{if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$db->prepare("DELETE FROM fb_history WHERE occurrence_id IN ($in)")->execute($ids);$db->prepare("DELETE FROM fb_occurrences WHERE id IN ($in)")->execute($ids);}
   $db->prepare('DELETE FROM fb_imports WHERE id=?')->execute([$import]);$db->commit();}
  catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return ['deleted'=>count($ids)];
 }

 /** Painel de monitoramento por ano (PDF + histórico), com os campos da planilha para edição. */
 public static function monitor(PDO $db,?int $year=null,?DateTimeImmutable $today=null):array {
  $today=$today??new DateTimeImmutable('today');$all=self::origin($db);$years=[];
  foreach($all as $row){$y=self::year($row);if($y)$years[$y]=true;}krsort($years);$years=array_keys($years);
  $year=$year&&in_array($year,$years,true)?$year:($years[0]??(int)$today->format('Y'));
  $count=['total'=>0,'pdf'=>0,'planilha'=>0,'localizados'=>0,'nao_localizados'=>0,'mais_30'=>0,'com_rastreador'=>0,'furtos'=>0,'roubos'=>0,'sem_cidade'=>0];$by=['mes'=>[],'cliente'=>[],'tipo'=>[],'cidade'=>[]];$rows=[];
  foreach($all as $row){
   $o=$row['original'];$s=$row['planilha'];$y=self::year($row);if($y!==$year)continue;
   $found=$s['localizado']==='SIM'||$s['recuperado']!=='';$days=$row['data_evento']?(int)(new DateTimeImmutable($row['data_evento']))->diff($today)->format('%r%a'):null;$pdf=($o['origem']??'')===self::ORIGIN;
   $count['total']++;$count[$pdf?'pdf':'planilha']++;$count[$found?'localizados':'nao_localizados']++;if(!$found&&$days!==null&&$days>30&&$y===(int)$today->format('Y'))$count['mais_30']++;
   if($s['rastreador']==='SIM')$count['com_rastreador']++;if($s['evento']==='FURTO')$count['furtos']++;if($s['evento']==='ROUBO')$count['roubos']++;if($s['cidade']==='')$count['sem_cidade']++;
   $month=$row['data_evento']&&(int)substr($row['data_evento'],0,4)===$y?(int)substr($row['data_evento'],5,2):(int)($o['mes']??0);if($month)$by['mes'][$month]=($by['mes'][$month]??0)+1;
   foreach(['cliente'=>$s['cliente']?:$row['associacao']?:'Não informado','tipo'=>$s['tipo']?:'Não informado','cidade'=>$s['cidade']!==''?$s['cidade'].($s['estado']!==''?'/'.$s['estado']:''):'Sem cidade'] as $k=>$v)$by[$k][$v]=($by[$k][$v]??0)+1;
   $rows[]=['id'=>(int)$row['id'],'registro'=>'FB-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT),'origem'=>$pdf?'PDF':'Planilha','protocolo'=>(string)($o['protocolo']??''),'data'=>$row['data_evento'],'data_texto'=>$row['data_evento']?'':($s['date_text']?:$row['data_original']),'dias'=>$days,
    'cliente'=>$s['cliente']?:$row['associacao'],'beneficiario'=>$s['beneficiario']?:$row['vitima'],'placa'=>$row['placa'],'veiculo'=>$s['veiculo']?:$row['veiculo'],'arquivo'=>(string)($o['arquivo']??''),'importacao'=>(int)($o['importacao']??0),
    'cidade'=>$s['cidade'],'estado'=>$s['estado'],'tipo'=>$s['tipo'],'rastreador'=>$s['rastreador'],'localizado'=>$s['localizado'],'evento'=>$s['evento'],'recuperado'=>$s['recuperado'],'em_busca'=>!$found];
  }
  ksort($by['mes']);foreach(['cliente','tipo','cidade'] as $k)arsort($by[$k]);
  usort($rows,fn($a,$b)=>[$b['data']??'',$b['id']]<=>[$a['data']??'',$a['id']]);
  $fmt=fn(array $m)=>array_map(fn($label,$total)=>['label'=>(string)$label,'total'=>$total],array_keys($m),$m);
  $months=[];foreach($by['mes'] as $m=>$t)$months[]=['label'=>ControlWorkbook::MONTHS[$m]??(string)$m,'total'=>$t];
  return ['ano'=>$year,'anos'=>$years,'totais'=>$count,'por_mes'=>$months,'por_cliente'=>array_slice($fmt($by['cliente']),0,10),'por_tipo'=>$fmt($by['tipo']),'por_cidade'=>array_slice($fmt($by['cidade']),0,10),'rows'=>$rows,'imports'=>self::imports($db),'editaveis'=>self::EDITABLE,'template'=>ControlWorkbook::hasTemplate()];
 }

 /**
  * Resumo do monitoramento para o assistente, com os mesmos critérios do painel: o ano é o da aba de
  * origem (histórico) ou o da data do atendimento. Filtros aceitos: inicio/fim, q (placa), municipio,
  * associacao (cliente), evento, situacao=Recuperado, etapa=Em buscas.
  */
 public static function summary(PDO $db,array $filters,?DateTimeImmutable $today=null):array {
  $today=$today??new DateTimeImmutable('today');$current=(int)$today->format('Y');
  $from=$filters['inicio']??null;$to=$filters['fim']??null;$wholeYear=$from&&$to&&substr($from,0,4)===substr($to,0,4)&&substr($from,5)==='01-01'&&substr($to,5)==='12-31'?(int)substr($from,0,4):null;
  $plate=isset($filters['q'])?preg_replace('/[^A-Z0-9]/','',strtoupper((string)$filters['q'])):'';$n=fn($v)=>WebAssistPdf::norm((string)$v);
  $out=['total'=>0,'pdf'=>0,'planilha'=>0,'localizados'=>0,'nao_localizados'=>0,'mais_30'=>0,'sem_cidade'=>0,'furtos'=>0,'roubos'=>0,'rastreador'=>['SIM'=>0,'NÃO'=>0],'por_ano'=>[],'tipo'=>[],'cidade'=>[],'cliente'=>[],'rows'=>[]];
  foreach(self::origin($db) as $row){
   $s=$row['planilha'];$y=self::year($row);$date=$row['data_evento'];
   if($wholeYear!==null){if($y!==$wholeYear)continue;}elseif($from||$to){if(!$date||($from&&$date<$from)||($to&&$date>$to))continue;}
   if($plate!==''&&!str_contains(preg_replace('/[^A-Z0-9]/','',strtoupper($row['placa'])),$plate))continue;
   $city=$s['cidade']!==''?$s['cidade'].($s['estado']!==''?'/'.$s['estado']:''):'';$client=$s['cliente']?:$row['associacao'];
   if(!empty($filters['municipio'])&&!str_contains($n($city),$n(explode('/',$filters['municipio'])[0])))continue;
   if(!empty($filters['associacao'])&&$n($client)!==$n($filters['associacao']))continue;
   if(!empty($filters['evento'])&&$s['evento']!==mb_strtoupper((string)$filters['evento'],'UTF-8'))continue;
   if(!empty($filters['tipo'])&&$s['tipo']!==$filters['tipo'])continue;if(!empty($filters['rastreador'])&&$s['rastreador']!==$filters['rastreador'])continue;
   $found=$s['localizado']==='SIM'||$s['recuperado']!=='';
   if(($filters['situacao']??'')==='Recuperado'&&!$found)continue;if(($filters['etapa']??'')==='Em buscas'&&$found)continue;
   $out['total']++;$out[($row['original']['origem']??'')===self::ORIGIN?'pdf':'planilha']++;$out[$found?'localizados':'nao_localizados']++;
   if(!$found&&$date&&$y===$current&&(int)(new DateTimeImmutable($date))->diff($today)->format('%r%a')>30)$out['mais_30']++;
   if($s['evento']==='FURTO')$out['furtos']++;if($s['evento']==='ROUBO')$out['roubos']++;if(isset($out['rastreador'][$s['rastreador']]))$out['rastreador'][$s['rastreador']]++;
   if($y)$out['por_ano'][$y]=($out['por_ano'][$y]??0)+1;if($s['tipo']!=='')$out['tipo'][$s['tipo']]=($out['tipo'][$s['tipo']]??0)+1;
   if($city==='')$out['sem_cidade']++;else $out['cidade'][$city]=($out['cidade'][$city]??0)+1;if($client!=='')$out['cliente'][$client]=($out['cliente'][$client]??0)+1;
   $out['rows'][]=['id'=>(int)$row['id'],'protocolo'=>(string)($row['original']['protocolo']??''),'placa'=>$row['placa'],'veiculo'=>$s['veiculo']?:$row['veiculo'],'data_evento'=>$date,'municipio'=>$city,'evento'=>$s['evento']!==''?ucfirst(strtolower($s['evento'])):'Não informado','situacao'=>$found?'Localizado':'Não localizado','etapa'=>($row['original']['origem']??'')===self::ORIGIN?'PDF WebAssist':'Histórico '.($row['original']['aba']??'')];
  }
  ksort($out['por_ano']);arsort($out['tipo']);foreach(['cidade','cliente'] as $k){arsort($out[$k]);$out[$k]=array_slice($out[$k],0,5,true);}
  usort($out['rows'],fn($a,$b)=>[$b['data_evento']??'',$b['id']]<=>[$a['data_evento']??'',$a['id']]);return $out;
 }

 /** Fatos do FOR 024 para o assistente (somente das ocorrências do recorte). */
 public static function facts(PDO $db,array $ids):array {
  $set=array_flip(array_map('intval',$ids));$out=['total'=>0,'tipo'=>[],'rastreador'=>['SIM'=>0,'NÃO'=>0],'localizado'=>['SIM'=>0,'NÃO'=>0],'cliente'=>[],'cidade'=>[],'sem_cidade'=>0,'origem'=>['PDF'=>0,'Planilha'=>0]];
  foreach(self::origin($db) as $row){if(!isset($set[(int)$row['id']]))continue;$s=$row['planilha'];$out['total']++;
   if($s['tipo']!=='')$out['tipo'][$s['tipo']]=($out['tipo'][$s['tipo']]??0)+1;if(isset($out['rastreador'][$s['rastreador']]))$out['rastreador'][$s['rastreador']]++;
   $found=$s['localizado']==='SIM'||$s['recuperado']!=='';$out['localizado'][$found?'SIM':'NÃO']++;$c=$s['cliente']?:$row['associacao'];if($c!=='')$out['cliente'][$c]=($out['cliente'][$c]??0)+1;
   if($s['cidade']!==''){$c=$s['cidade'].($s['estado']!==''?'/'.$s['estado']:'');$out['cidade'][$c]=($out['cidade'][$c]??0)+1;}else $out['sem_cidade']++;$out['origem'][($row['original']['origem']??'')===self::ORIGIN?'PDF':'Planilha']++;}
  arsort($out['tipo']);foreach(['cliente','cidade'] as $k){arsort($out[$k]);$out[$k]=array_slice($out[$k],0,5,true);}return $out;
 }

 /** Ano da ocorrência: o da aba de origem (histórico) ou o da data do atendimento. */
 private static function year(array $row):int {return (int)($row['original']['ano']??0)?:($row['data_evento']?(int)substr($row['data_evento'],0,4):0);}
 private static function newImport(PDO $db,string $hash,string $filename,int $total,int $user):int {
  $db->prepare('INSERT INTO fb_imports(file_hash,filename,total,inserted,updated,skipped,user_id) VALUES(?,?,?,0,0,0,?)')->execute([$hash,$filename,$total,$user]);return (int)$db->lastInsertId();
 }
 private static function pdfKey(array $r):string {
  return hash('sha256',$r['protocolo']!==''?'webassist|'.strtoupper($r['protocolo']):'webassist|'.$r['placa'].'|'.$r['data'].'|'.WebAssistPdf::norm($r['cliente']));
 }
}
