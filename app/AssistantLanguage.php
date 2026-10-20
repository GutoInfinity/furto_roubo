<?php
declare(strict_types=1);
final class AssistantLanguage {
 public static function normalize(string $text):string {
  $q=Records::norm($text);
  $aliases=['qnts'=>'quantos','qnt'=>'quantos','qtos'=>'quantos','qntos'=>'quantos','qntas'=>'quantas','qtas'=>'quantas','vc'=>'voce','vcs'=>'voces','hj'=>'hoje','ontem'=>'ontem','bomdia'=>'bom dia','boatarde'=>'boa tarde','boanoite'=>'boa noite','obg'=>'obrigado','obgd'=>'obrigado','brigado'=>'obrigado','frutos'=>'furtos','furtoos'=>'furtos','rouboss'=>'roubos','mespassado'=>'mes passado','mespasado'=>'mes passado','nessemes'=>'nesse mes','nestemes'=>'neste mes','dessse'=>'desse','pasado'=>'passado','passdo'=>'passado','julhoo'=>'julho','agost'=>'agosto','meses'=>'meses'];
  $words=['furtos','roubos','ocorrencias','recuperados','recuperado','encerrados','finalizados','incompletos','pendencias','quantos','quantas','passado','anterior','janeiro','fevereiro','marco','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  return preg_replace_callback('/\b[a-z]+\b/',static function($m)use($aliases,$words){$word=$m[0];if(isset($aliases[$word]))return $aliases[$word];if(strlen($word)<5||in_array($word,$words,true))return $word;$best=[];$distance=99;foreach($words as $candidate){$d=levenshtein($word,$candidate);if($d<$distance){$distance=$d;$best=[$candidate];}elseif($d===$distance)$best[]=$candidate;}return count($best)===1&&$distance<=1?$best[0]:$word;},$q)??$q;
 }
 public static function social(string $q):?string {
  $q=trim(preg_replace('/[^a-z0-9 ]/',' ',$q)??$q);$q=preg_replace('/\s+/',' ',$q)??$q;
  if(in_array($q,['ajuda','como funciona','o que voce faz','como voce funciona'],true))return 'Posso consultar placas, municípios, furtos, roubos, recuperações e cadastros incompletos. Aceito períodos como “neste mês”, “mês passado”, “mês 7 até mês 8” e “do dia 21 do 8 até 21 do 9”. Sem ano informado, uso o ano atual. Abra o botão “?” para ver exemplos. Para cadastrar ou editar, use a tela de ocorrências.';
  if(preg_match('/^(obrigad[oa]|muito obrigad[oa]|valeu|agradeco|ok|certo|entendi)( mesmo| pela ajuda)?$/',$q))return 'Por nada! Quando precisar, posso consultar outra placa, município ou período. As perguntas de exemplo ficam no botão “?”.';
  if(preg_match('/^(oi+|oie+|ola|olaa|opa|e ai|bom dia|boa tarde|boa noite)( tudo bem| tudo bom| como vai| bom dia| boa tarde| boa noite)?$/',$q)){
   $greeting=strpos($q,'bom dia')!==false?'Bom dia!':(strpos($q,'boa tarde')!==false?'Boa tarde!':(strpos($q,'boa noite')!==false?'Boa noite!':'Olá!'));
   return $greeting.' Sou o assistente do Infinity System. Como posso ajudar? Posso localizar uma placa, consultar furtos e roubos ou analisar um período. Por exemplo: “Quantos furtos neste mês?”. Use o botão “?” para ver mais exemplos.';
  }return null;
 }
 public static function period(string $q,?DateTimeImmutable $today=null):?array {
  $today=$today??new DateTimeImmutable('today');$year=(int)$today->format('Y');
  if(preg_match('/\b(19\d{2}|20\d{2}|2100)\b/',$q,$m))$year=(int)$m[1];
  $months=['janeiro'=>1,'fevereiro'=>2,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];
  $text=preg_replace_callback('/\b('.implode('|',array_keys($months)).')\b/',static fn($m)=>'mes '.$months[$m[1]],$q)??$q;
  $text=preg_replace_callback('/\b(dia\s+)?(\d{1,2})\s+(?:de|do)\s+(?:mes\s+)?(\d{1,2})(?:\s+de\s+(\d{4}))?\b/',static fn($m)=>$m[2].'/'.$m[3].(!empty($m[4])?'/'.$m[4]:''),$text)??$text;
  if(preg_match_all('~(?<![\d/])(\d{1,2})\s*[/\-]\s*(\d{1,2})(?:\s*[/\-]\s*((?:19|20)\d{2}|2100))?(?![\d/])~',$text,$matches,PREG_SET_ORDER)){
   if(count($matches)>2)throw new InvalidArgumentException('Informe no máximo duas datas para definir um período.');
   $dates=[];foreach($matches as $i=>$m){$y=isset($m[3])&&$m[3]!==''?(int)$m[3]:$year;if($i===1&&empty($m[3])&&(int)$m[2]<(int)$matches[0][2])$y=(int)substr($dates[0],0,4)+1;$dates[]=self::day((int)$m[1],(int)$m[2],$y);}
   return self::range($dates[0],$dates[1]??$dates[0]);
  }
  if(preg_match('/\b(este|esse|neste|nesse|deste|desse)\s+mes\b|\bmes atual\b/',$q))return self::month((int)$today->format('n'),(int)$today->format('Y'));
  if(preg_match('/\bmes (passado|anterior)\b|\bultimo mes\b/',$q)){$last=$today->modify('first day of last month');return self::month((int)$last->format('n'),(int)$last->format('Y'));}
  if(preg_match('/\bhoje\b/',$q))return self::range($today->format('Y-m-d'),$today->format('Y-m-d'));
  if(preg_match('/\bontem\b/',$q))return self::range($today->modify('-1 day')->format('Y-m-d'),$today->modify('-1 day')->format('Y-m-d'));
  if(preg_match('/ultimos?\s+(\d{1,3})\s+dias/',$q,$m)){$days=(int)$m[1];if($days<1)throw new InvalidArgumentException('O número de dias deve ser maior que zero.');return self::range($today->modify('-'.($days-1).' days')->format('Y-m-d'),$today->format('Y-m-d'));}
  if(preg_match('/\bmes\s+(\d{1,2})(?:\s*(?:a|ate|e|ao|-)\s*(?:o\s+)?(?:mes\s+)?(\d{1,2}))?\b/',$text,$m)){
   $start=self::month((int)$m[1],$year);if(empty($m[2]))return $start;$endMonth=(int)$m[2];$endYear=$year+($endMonth<(int)$m[1]?1:0);$end=self::month($endMonth,$endYear);return self::range($start['inicio'],$end['fim']);
  }
  if(preg_match('/\b(19\d{2}|20\d{2}|2100)\b/',$q))return self::range($year.'-01-01',$year.'-12-31');
  if(preg_match('/\b(este|esse|neste|nesse) ano\b|\bano atual\b/',$q))return self::range($today->format('Y').'-01-01',$today->format('Y').'-12-31');
  if(preg_match('/\bano (passado|anterior)\b/',$q))return self::range(((int)$today->format('Y')-1).'-01-01',((int)$today->format('Y')-1).'-12-31');
  if(preg_match('/\bmes\s+\d+|\bdia\s+\d+|\b\d{1,2}\/\d/',$q))throw new InvalidArgumentException('Não consegui confirmar esse período. Use, por exemplo, “mês 7 até mês 8 de 2026” ou “21/08/2026 até 21/09/2026”.');
  return null;
 }
 private static function day(int $day,int $month,int $year):string{if($year<1900||$year>2100||!checkdate($month,$day,$year))throw new InvalidArgumentException('A data informada não existe. Confira o dia, mês e ano da pergunta.');return sprintf('%04d-%02d-%02d',$year,$month,$day);}
 private static function month(int $month,int $year):array{$start=self::day(1,$month,$year);return self::range($start,(new DateTimeImmutable($start))->format('Y-m-t'));}
 private static function range(string $start,string $end):array{if($start>$end)throw new InvalidArgumentException('A data inicial precisa vir antes da data final. Confira o período.');return ['inicio'=>$start,'fim'=>$end];}
}
