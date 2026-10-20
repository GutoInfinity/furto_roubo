<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/app/bootstrap.php';
$today=new DateTimeImmutable('2026-10-16');$checks=0;
function verify($condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
$cases=[
 ['nesse mês','2026-10-01','2026-10-31'],['neste mes','2026-10-01','2026-10-31'],['mês passado','2026-09-01','2026-09-30'],
 ['mes 7','2026-07-01','2026-07-31'],['mes 7 ate mes 8','2026-07-01','2026-08-31'],['mes 7 ate 8 de 2025','2025-07-01','2025-08-31'],
 ['de julho a agosto de 2026','2026-07-01','2026-08-31'],['dia 21 do 8 ate 21 do 9','2026-08-21','2026-09-21'],
 ['do dia 21 de agosto ate o dia 21 de setembro','2026-08-21','2026-09-21'],['21/8 ate 21/9','2026-08-21','2026-09-21'],
 ['21/08/2025 a 21/09/2025','2025-08-21','2025-09-21'],['mes 12 ate mes 1','2026-12-01','2027-01-31'],
 ['21/12 ate 21/1','2026-12-21','2027-01-21'],['nos ultimos 30 dias','2026-09-17','2026-10-16'],['ontem','2026-10-15','2026-10-15'],
 ['hoje','2026-10-16','2026-10-16'],['ano passado','2025-01-01','2025-12-31'],['mes 2 de 2028','2028-02-01','2028-02-29'],
 ['qnts frutos nessemEs','2026-10-01','2026-10-31'],['mespasado','2026-09-01','2026-09-30']
];
foreach($cases as [$question,$start,$end]){$r=AssistantLanguage::period(AssistantLanguage::normalize($question),$today);verify($r===['inicio'=>$start,'fim'=>$end],'Periodo: '.$question.' '.json_encode($r));}
foreach(['31/02/2026','mes 13','mes 0','22/8 ate 21/8','ultimos 0 dias'] as $bad){try{AssistantLanguage::period(AssistantLanguage::normalize($bad),$today);throw new RuntimeException('Periodo invalido aceito: '.$bad);}catch(InvalidArgumentException $e){$checks++;}}
foreach(['oi','oi tudo bem?','olá','bomdia','boa tarde','obg','valeu','ajuda'] as $q)verify(AssistantLanguage::social(AssistantLanguage::normalize($q))!==null,'Social: '.$q);
verify(AssistantLanguage::social(AssistantLanguage::normalize('oi quantos furtos nesse mes?'))===null,'Saudacao com consulta');
verify(AssistantLanguage::normalize('qnts frutos e rouboss mespasado')==='quantos furtos e roubos mes passado','Erros comuns');
$db=fb_db();verify($db->query('SELECT DATABASE()')->fetchColumn()==='axioma_furto_roubo','Banco independente');$baseline=Records::stats($db)['total'];
$db->beginTransaction();try{
 $a=Assistant::ask($db,1,'Oi tudo bem?');verify(!empty($a['social'])&&$a['total']===null&&$a['linhas']===[],'Saudacao sem dados indevidos');
 $id=$a['conversation_id'];$b=Assistant::ask($db,1,'Qnts frutos mes 7 ate mes 8 de 2026?',[],$id);verify($b['filtros']['evento']==='Furto'&&$b['filtros']['inicio']==='2026-07-01'&&$b['filtros']['fim']==='2026-08-31','Consulta com erros e meses');verify(strpos($b['resposta'],'01/07/2026 a 31/08/2026')!==false,'Periodo explicado');
 verify(count(Conversations::history($db,1,$id)['rows'])===2,'Conversa separada');
 try{Conversations::history($db,999999,$id);throw new RuntimeException('Vazou conversa');}catch(OutOfBoundsException $e){$checks++;}
 $new=Assistant::ask($db,1,'Quantos roubos no mes passado?');verify($new['conversation_id']!==$id,'Nova conversa independente');
 verify(Records::stats($db)['total']===$baseline,'Ocorrencias preservadas');
verify(Assistant::plate('ano 2026')===null&&Assistant::plate('do ano 2025')===null&&Assistant::plate('placa abc-1d23')==='ABC1D23'&&Assistant::plate('OZB 7410')==='OZB7410','Ano nao confundido com placa');
$inherited=Assistant::filters($db,'quantas ocorrencias neste mes',['fonte'=>'for024','q'=>'ANO2026']);verify(!isset($inherited['q'])&&Assistant::filters($db,'e essa?',['q'=>'ABC1D23'])['q']==='ABC1D23','Placa falsa ANO2026 herdada da conversa descartada');
}finally{$db->rollBack();}
echo $checks," verificacoes de periodos, linguagem, conversas, contexto e isolamento aprovadas.\n";
