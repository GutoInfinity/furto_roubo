<?php
declare(strict_types=1);
// Teste do fluxo PDF WebAssist → FOR 024 com os arquivos de exemplo da pasta documento (não altera o banco).
define('FB_ROOT',dirname(__DIR__));
require FB_ROOT.'/app/Records.php';require FB_ROOT.'/app/For024.php';
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException('Falhou: '.$label);$checks++;};
$pdf=FB_ROOT.'/documento/roubo e furto.pdf';$xlsx=FB_ROOT.'/documento/FOR_024_CONTROLE_DE_ROUBOS_E_FURTOS_VER02.xlsx';
if(!is_file($pdf)||!is_file($xlsx)){echo "Arquivos de exemplo ausentes em documento/: teste ignorado.\n";exit(0);}
$report=WebAssistPdf::read($pdf);$rows=$report['rows'];
$check(count($rows)===14,'14 ocorrências no PDF');$check($report['generated']==='20/10/2026 08:21','data de geração');
$check($rows[0]===['cliente'=>'SANTA CLARA','data'=>'03/10/2026','modelo'=>'HB20 COMF./C.PLUS/C.STYLE 1.0 FLEX 12V','montadora'=>'HYUNDAI','beneficiario'=>'LUCAS MATHEUS LOPES ALMEIDA','placa'=>'OOP5C07','protocolo'=>'FR2026059947','situacao'=>'Em busca','valor'=>'0,00'],'primeira linha completa');
$check($rows[4]['beneficiario']==='CAROLINA DE SOUZA ÁVILA'&&$rows[8]['montadora']==='CITROËN','acentos preservados');
$check($rows[11]['placa']==='IVE0D65'&&$rows[11]['modelo']==='CBR 250R','moto lida');
$check(implode(',',array_column($rows,'protocolo'))===implode(',',array_map(fn($r)=>$r['protocolo'],$rows))&&count(array_unique(array_column($rows,'protocolo')))===14,'protocolos únicos');
// Exportação usando o FOR 024 de exemplo como modelo (cópia temporária; o modelo instalado é restaurado).
$installed=ControlWorkbook::templatePath();$backup=is_file($installed)?$installed.'.bak-test':null;if($backup)copy($installed,$backup);
try{
 ControlWorkbook::saveTemplate($xlsx);$learned=ControlWorkbook::vehicleTypes();$check(ControlWorkbook::guessType('HONDA','CBR 250R',$learned)==='MOTO','CBR 250R é moto');$check(ControlWorkbook::guessType('FIAT','PALIO 1.0',$learned)==='VEICULO LEVE','Palio é veículo leve');
 $items=[];foreach($rows as $r){[$d,$m,$y]=explode('/',$r['data']);$items[]=['date'=>"$y-$m-$d",'cidade'=>'','estado'=>'','cliente'=>$r['cliente'],'beneficiario'=>$r['beneficiario'],'placa'=>$r['placa'],'veiculo'=>$r['montadora'].' / '.$r['modelo'],'tipo'=>ControlWorkbook::guessType($r['montadora'],$r['modelo'],$learned),'rastreador'=>'','localizado'=>'NÃO','evento'=>'','recuperado'=>'','protocolo'=>$r['protocolo']];}
 $out=tempnam(sys_get_temp_dir(),'for024_');$result=ControlWorkbook::export($items,$out,new DateTimeImmutable('2026-10-20'));
 $check(count($result['added'])===6&&count($result['skipped'])===8,'6 novas e 8 fora (já lançadas ou mesmo veículo)');
 $zip=new ZipArchive();$zip->open($out);$orig=new ZipArchive();$orig->open($xlsx);$changed=[];for($i=0;$i<$orig->numFiles;$i++){$n=$orig->getNameIndex($i);if($orig->getFromName($n)!==$zip->getFromName($n))$changed[]=$n;}
 $check($changed===['xl/worksheets/sheet6.xml'],'só a aba 2026 muda');$sheet=(string)$zip->getFromName('xl/worksheets/sheet6.xml');$check(str_contains($sheet,'<c r="I156" s="16" t="inlineStr"><is><t>MOTO</t></is></c>'),'moto na linha 156');
 $check(str_contains($sheet,'<c r="D152" s="18"><v>46302</v></c>'),'data como data do Excel');$zip->close();$orig->close();@unlink($out);
 // Mês novo e mês já fechado.
 $base=['cidade'=>'','estado'=>'','cliente'=>'TESTE','beneficiario'=>'X','veiculo'=>'FIAT / UNO','tipo'=>'VEICULO LEVE','rastreador'=>'','localizado'=>'NÃO','evento'=>'','recuperado'=>''];
 $out=tempnam(sys_get_temp_dir(),'for024_');ControlWorkbook::export([array_merge($base,['date'=>'2026-11-03','placa'=>'AAA1A11']),array_merge($base,['date'=>'2026-09-28','placa'=>'BBB2B22'])],$out);$zip->open($out);$sheet=(string)$zip->getFromName('xl/worksheets/sheet6.xml');$zip->close();@unlink($out);
 $check(str_contains($sheet,'>NOVEMBRO<')&&str_contains($sheet,'<mergeCell ref="B147:M147"/>'),'bloco NOVEMBRO criado e OUTUBRO deslocado');
 // Só as selecionadas: sem histórico e sem as abas dos outros anos.
 $out=tempnam(sys_get_temp_dir(),'for024_');$r=ControlWorkbook::exportOnly(array_slice($items,0,2),$out,new DateTimeImmutable('2026-10-20'));$zip->open($out);
 $wb=(string)$zip->getFromName('xl/workbook.xml');preg_match_all('/<sheet [^>]*name="([^"]+)"/',$wb,$m);$sheet=(string)$zip->getFromName('xl/worksheets/sheet6.xml');$hasOld=$zip->locateName('xl/worksheets/sheet1.xml')!==false;$zip->close();@unlink($out);
 $check(count($r['added'])===2&&$m[1]===['2026','Dados regras']&&!$hasOld,'só selecionadas: aba 2026 e auxiliar');
 $check(substr_count($sheet,'<row ')===6&&str_contains($sheet,'>OUTUBRO<')&&!str_contains($sheet,'SETE LAGOAS'),'sem histórico: cabeçalho, mês e 2 linhas');
 $check(str_contains($wb,'localSheetId="0"'),'nome definido renumerado');
}finally{if($backup){copy($backup,$installed);unlink($backup);}else @unlink($installed);}
// Histórico: todas as abas por ano, com as colunas de cada layout (2021, 2023 e 2024 em diante).
$h=ControlWorkbook::history($xlsx);$by=[];foreach($h as $r)$by[$r['aba']]=($by[$r['aba']]??0)+1;
$check(($by['2021']??0)===11&&($by['2026']??0)===138&&count($h)===756,'histórico de todas as abas');
$first=null;foreach($h as $r)if($r['aba']==='2021'){$first=$r;break;}$check($first['placa']==='OZB-7410'&&$first['beneficiario']==='HENRQUE NIEHUES'&&$first['hora']==='09:20'&&$first['date']==='2021-02-11','layout 2021 (cliente repetido, hora)');
$r26=null;foreach($h as $r)if($r['aba']==='2026'){$r26=$r;break;}$check($r26['cidade']==='CAMBORIÚ'&&$r26['estado']==='SC'&&$r26['recuperado']==='RECUPERADO'&&$r26['evento']==='FURTO','layout 2026');
$bad=array_values(array_filter($h,fn($r)=>$r['date']===null));$check(count($bad)===4&&$bad[0]['month']!==null,'datas digitadas erradas mantêm o mês do bloco');
// Excel novo só com dados (todas as abas com linhas), com cidade preenchida no sistema.
$installed2=ControlWorkbook::templatePath();$backup=is_file($installed2)?$installed2.'.bak-test':null;if($backup)copy($installed2,$backup);
try{ControlWorkbook::saveTemplate($xlsx);$items=array_map(fn($r)=>$r+['cidade'=>''],array_slice($h,0,30));$items[0]['cidade']='LAGES';$out=tempnam(sys_get_temp_dir(),'for024_');$res=ControlWorkbook::exportOnly($items,$out);$zip=new ZipArchive();$zip->open($out);$s1=(string)$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();@unlink($out);
 $check(count($res['added'])===30&&str_contains($s1,'OZB-7410'),'Excel novo com o histórico');}
finally{if($backup){copy($backup,$installed2);unlink($backup);}else @unlink($installed2);}
echo $checks." verificações do PDF WebAssist e do FOR 024 aprovadas.\n";
