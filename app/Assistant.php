<?php
declare(strict_types=1);
final class Assistant {
 public static function setting(PDO $db,string $key,string $default=''):string {$q=$db->prepare('SELECT setting_value FROM fb_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;}
 public static function encrypt(string $key):string {$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($key,'aes-256-gcm',hash('sha256',fb_env('APP_KEY'),true),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Não foi possível proteger a chave.');return base64_encode($iv.$tag.$cipher);}
 public static function key(PDO $db):string {$stored=self::setting($db,'assistant_key');if($stored==='')return fb_env('OPENAI_KEY');$raw=base64_decode($stored,true);if($raw===false||strlen($raw)<28)return '';return openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',fb_env('APP_KEY'),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16))?:'';}
 public static function model(PDO $db):string{return self::setting($db,'assistant_model',fb_env('OPENAI_MODEL'));}
 public static function configured(PDO $db):bool{return self::key($db)!==''&&self::model($db)!=='';}
 /** Perguntas que pedem os números do painel de monitoramento. */
 private const MONITOR_TOPIC='/monitor|for ?024|webassist|web assist|controle de roubos|painel de roubo/';
 /** Perguntas sobre o controle FOR 024 (PDF do WebAssist e histórico da planilha). */
 private const FOR024_TOPIC='/for ?024|webassist|rastread|tipos? de veiculo|\bmotos?\b|pesad|utilitari|veiculos? leves?|localizad|sem cidade|cidades?|planilha|controle de roubos|clientes?|\buf\b|estados?/';
 /** Resumo das colunas do FOR 024 para as ocorrências do recorte. */
 private static function ids(PDO $db,array $filters):array {[$where,$params]=Records::where($filters);$q=$db->prepare('SELECT id FROM fb_occurrences'.$where.' LIMIT 50000');$q->execute($params);return $q->fetchAll(PDO::FETCH_COLUMN);}
 /** Resposta com os números do painel de monitoramento (mesmos critérios do painel). */
 public static function monitorAnswer(array $m,array $filters,string $norm):string {
  $n=fn($v)=>number_format((int)$v,0,',','.');$list=fn(array $x)=>implode(', ',array_map(fn($k,$v)=>$k.' ('.$n($v).')',array_keys($x),$x));
  $from=$filters['inicio']??null;$to=$filters['fim']??null;
  $when=$from&&$to?(substr($from,5)==='01-01'&&substr($to,5)==='12-31'&&substr($from,0,4)===substr($to,0,4)?'em '.substr($from,0,4):'de '.date('d/m/Y',strtotime($from)).' a '.date('d/m/Y',strtotime($to))):'em todo o período';
  $what=trim((!empty($filters['tipo'])?['MOTO'=>'moto(s)','PESADO'=>'veículo(s) pesado(s)','UTILITARIO'=>'utilitário(s)','VEICULO LEVE'=>'veículo(s) leve(s)'][$filters['tipo']]??$filters['tipo']:'ocorrência(s)').(!empty($filters['rastreador'])?($filters['rastreador']==='SIM'?' com rastreador':' sem rastreador'):''));
  if(!$m['total'])return 'Não encontrei '.$what.' no monitoramento (FOR 024) '.$when.' com esses filtros.';
  $lines=['No monitoramento (FOR 024) há '.$n($m['total']).' '.$what.' '.$when.': '.$n($m['pdf']).' do PDF do WebAssist e '.$n($m['planilha']).' do histórico da planilha.',
   $n($m['localizados']).' localizada(s) e '.$n($m['nao_localizados']).' não localizada(s)'.($m['mais_30']?' — '.$n($m['mais_30']).' de '.date('Y').' seguem sem localizar há mais de 30 dias.':'.')];
  if(count($m['por_ano'])>1)$lines[]='Por ano: '.$list($m['por_ano']).'.';
  if($m['furtos']||$m['roubos'])$lines[]='Furto/roubo informado: '.$n($m['furtos']).' furto(s) e '.$n($m['roubos']).' roubo(s); '.$n($m['total']-$m['furtos']-$m['roubos']).' sem essa informação.';
  if($m['tipo'])$lines[]='Tipo de veículo: '.$list($m['tipo']).'.';
  if(preg_match('/rastread/',$norm)||$m['rastreador']['SIM']+$m['rastreador']['NÃO'])$lines[]='Rastreador: '.$n($m['rastreador']['SIM']).' com, '.$n($m['rastreador']['NÃO']).' sem e '.$n($m['total']-$m['rastreador']['SIM']-$m['rastreador']['NÃO']).' sem informação.';
  if(preg_match('/client/',$norm)&&$m['cliente'])$lines[]='Clientes com mais ocorrências: '.$list($m['cliente']).'.';
  if($m['cidade']&&preg_match('/cidade|\buf\b|estado|municipio|onde/',$norm))$lines[]='Cidades com mais ocorrências: '.$list($m['cidade']).'.';
  if($m['sem_cidade'])$lines[]=$n($m['sem_cidade']).' ainda sem cidade preenchida (o PDF não traz cidade/UF; preencha na tabela do monitoramento).';
  return implode("\n",$lines);
 }
 public static function for024(PDO $db,array $filters,string $norm):string {
  $f=For024::facts($db,self::ids($db,$filters));if(!$f['total'])return '';
  $n=fn($v)=>number_format((int)$v,0,',','.');$list=fn(array $m)=>implode(', ',array_map(fn($k,$v)=>$k.' ('.$n($v).')',array_keys($m),$m));
  $lines=['No controle FOR 024 ('.$n($f['origem']['PDF']).' do PDF do WebAssist e '.$n($f['origem']['Planilha']).' do histórico da planilha), '.$n($f['total']).' ocorrência(s) deste recorte: '.$n($f['localizado']['SIM']).' localizada(s) e '.$n($f['localizado']['NÃO']).' não localizada(s).'];
  if(preg_match('/rastread/',$norm)||$f['rastreador']['SIM']+$f['rastreador']['NÃO']>0)$lines[]='Rastreador: '.$n($f['rastreador']['SIM']).' com, '.$n($f['rastreador']['NÃO']).' sem e '.$n($f['total']-$f['rastreador']['SIM']-$f['rastreador']['NÃO']).' sem informação.';
  if($f['tipo'])$lines[]='Tipo de veículo: '.$list($f['tipo']).'.';
  if(preg_match('/client/',$norm)&&$f['cliente'])$lines[]='Clientes com mais ocorrências: '.$list($f['cliente']).'.';
  if(preg_match('/cidade|\buf\b|estado|municipio/',$norm)||$f['sem_cidade'])$lines[]=($f['cidade']?'Cidades com mais ocorrências: '.$list($f['cidade']).'. ':'').($f['sem_cidade']?$n($f['sem_cidade']).' ainda sem cidade preenchida (o PDF não traz cidade/UF; preencha no painel de monitoramento).':'');
  return implode("\n",array_filter($lines));
 }
 /** Palavras que, seguidas de um ano, não são placa ("ano 2026"). */
 private const NOT_PLATE=['ANO','DIA','MES','ATE','DOS','DAS','NOS','NAS','POR','PRA','QUE','COM','SEM','TEM','FOI','DES','NUM','UMA','UNS','TOT'];
 /** Placa citada na pergunta. "ano 2026", "dia 2025", "até 2024" não são placas. */
 public static function plate(string $question):?string {
  preg_match_all('/\b([A-Z]{3})([- ]?)(\d[A-Z0-9]\d{2})\b/i',$question,$all,PREG_SET_ORDER);
  foreach($all as $m){$letters=strtoupper(AssistantLanguage::normalize($m[1]));
   if(preg_match('/^(19|20)\d{2}$/',$m[3])&&($m[2]!==''||ctype_lower($m[1]))&&in_array($letters,self::NOT_PLATE,true))continue;
   return strtoupper($m[1].$m[3]);}
  return null;
 }
 public static function filters(PDO $db,string $question,array $scope):array {
  $filters=array_intersect_key($scope,array_flip(['evento','situacao','etapa','associacao','municipio','inicio','fim','q','incompletos','andamento','fonte','tipo','rastreador']));$q=AssistantLanguage::normalize($question);
  // Conversas antigas guardaram "ano 2026" como placa (ANO2026): esse filtro herdado é descartado.
  if(isset($filters['q'])&&preg_match('/^([A-Z]{3})(19|20)\d{2}$/',strtoupper((string)$filters['q']),$fake)&&in_array($fake[1],self::NOT_PLATE,true))unset($filters['q']);
  if(preg_match('/\b(todos os registros|todas as ocorrencias|toda a base|sem filtros)\b/',$q))$filters=[];
  // Fonte: o monitoramento (FOR 024) continua valendo nas perguntas seguintes da conversa.
  if(preg_match(self::MONITOR_TOPIC,$q))$filters['fonte']='for024';elseif(preg_match('/\b(toda a base|base toda|todo o sistema|sistema todo|todos os cadastros|cadastro geral)\b/',$q))unset($filters['fonte'],$filters['tipo'],$filters['rastreador']);
  // Tipo de veículo e rastreador (colunas do FOR 024).
  $type=match(true){(bool)preg_match('/\bmotos?\b|motocicleta/',$q)=>'MOTO',(bool)preg_match('/pesad|caminh/',$q)=>'PESADO',(bool)preg_match('/utilitari/',$q)=>'UTILITARIO',(bool)preg_match('/veiculos? leves?|\bcarros?\b|automove/',$q)=>'VEICULO LEVE',(bool)preg_match('/todos os tipos|qualquer tipo/',$q)=>'',default=>null};
  if($type!==null){if($type==='')unset($filters['tipo']);else{$filters['tipo']=$type;$filters['fonte']='for024';if(!str_contains($q,'rastread'))unset($filters['rastreador']);}}
  if(preg_match('/\bsem rastread/',$q)){$filters['rastreador']='NÃO';$filters['fonte']='for024';}elseif(preg_match('/\bcom rastread/',$q)){$filters['rastreador']='SIM';$filters['fonte']='for024';}elseif(preg_match('/rastreador(es)? ou n|com e sem rastread|qualquer rastread/',$q))unset($filters['rastreador']);
  // Todo o período: sem datas, inclusive as herdadas da pergunta anterior.
  $allTime=(bool)preg_match('/\b(todo|toda|todos|qualquer)\s+(o\s+|os\s+)?(periodo|historico|tempo|anos)\b|\bdesde (o inicio|sempre|o comeco)\b|\bno geral\b|\bao todo\b/',$q);if($allTime)unset($filters['inicio'],$filters['fim']);
  $furto=preg_match('/\bfurt(?:os?|ad[oa]s?)\b/',$q);$roubo=preg_match('/\broub(?:os?|ad[oa]s?)\b/',$q);
  if($furto&&$roubo)unset($filters['evento']);elseif($furto)$filters['evento']='Furto';elseif($roubo)$filters['evento']='Roubo';
  if(preg_match('/recuperad|busca|encerrad|finalizad|em aberto|em andamento/',$q))unset($filters['situacao'],$filters['etapa'],$filters['andamento']);
  if(strpos($q,'recuperad')!==false)$filters['situacao']='Recuperado';elseif(strpos($q,'busca')!==false)$filters['etapa']='Em buscas';elseif(strpos($q,'encerrad')!==false||strpos($q,'finalizad')!==false)$filters['etapa']='Encerrado';
  if(strpos($q,'em aberto')!==false||strpos($q,'em andamento')!==false){$filters['andamento']='1';unset($filters['etapa']);}
  if(strpos($q,'incomplet')!==false||strpos($q,'pendencia')!==false)$filters['incompletos']='1';
  if(($plate=self::plate($question))!==null)$filters['q']=$plate;
  elseif(preg_match('/\bFB[- ]?(\d+)\b/i',$question,$id))$filters['q']='FB-'.(int)$id[1];
  foreach($db->query("SELECT DISTINCT municipio FROM fb_occurrences WHERE municipio<>'' ORDER BY LENGTH(municipio) DESC LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN) as $city)if(mb_strlen($city)>=3&&(strpos($q,Records::norm($city))!==false||preg_match('~(?<![a-z0-9])'.preg_quote(Records::norm(explode('/',$city)[0]),'~').'(?![a-z0-9])~',$q))){$filters['municipio']=$city;break;}
  foreach($db->query("SELECT DISTINCT associacao FROM fb_occurrences WHERE associacao<>'' ORDER BY LENGTH(associacao) DESC LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN) as $client)if(mb_strlen($client)>=4&&preg_match('~(?<![a-z0-9])'.preg_quote(Records::norm($client),'~').'(?![a-z0-9])~',$q)){$filters['associacao']=$client;break;}
  $period=$allTime?null:AssistantLanguage::period($q);if($period)$filters=array_replace($filters,$period);
  Records::where($filters);return $filters;
 }
 public static function ask(PDO $db,int $user,string $question,array $scope=[],?int $conversation=null):array {
  $question=trim($question);if($question===''||mb_strlen($question)>2000)throw new InvalidArgumentException('Escreva uma pergunta com até 2.000 caracteres.');
  $q=$db->prepare('SELECT COUNT(*) FROM fb_chat WHERE user_id=? AND created_at>=?');$q->execute([$user,date('Y-m-d H:i:s',time()-60)]);if((int)$q->fetchColumn()>=10)throw new InvalidArgumentException('Aguarde um minuto antes de continuar.');
  $filters=self::filters($db,$question,$scope);$stats=Records::stats($db,$filters);$list=Records::list($db,$filters,10);$norm=AssistantLanguage::normalize($question);$social=AssistantLanguage::social($norm);
  if(preg_match('/\b(exclua|apague|altere|edite|cadastre|salve)\b/',$norm))$answer='Eu ajudo a consultar e conferir os dados. Para alterar uma ocorrência, abra o cadastro e use Editar. Assim a mudança fica registrada no histórico.';
  elseif($social!==null)$answer=$social;
  elseif(($filters['fonte']??'')==='for024'){$m=For024::summary($db,$filters);$answer=self::monitorAnswer($m,$filters,$norm);$monitorRows=$m['rows'];$stats=['total'=>$m['total']];}
  else{$answer='Encontrei '.number_format($stats['total'],0,',','.').' ocorrência(s) no recorte consultado: '.$stats['furtos'].' de furto, '.$stats['roubos'].' de roubo e '.($stats['total']-$stats['furtos']-$stats['roubos'])." com tipo não informado.\n".$stats['recuperados'].' estão com situação Recuperado e '.$stats['encerrados']." na etapa Encerrado.\n".$stats['incompletos'].' cadastro(s) têm informações para conferir.';if($stats['total']===0)$answer='Não encontrei ocorrências com esses filtros. Tente outra placa, município ou período.';if($stats['sem_data'])$answer.="\n".$stats['sem_data'].' registro(s) deste recorte estão sem data válida. Eles não são incluídos quando você filtra por período.';}
  if($social===null&&!isset($monitorRows)&&$stats['total']>0){$f=For024::facts($db,self::ids($db,$filters));if($f['total']&&$f['total']<$stats['total'])$answer.="\nDesse total, ".number_format($f['total'],0,',','.').' são do monitoramento FOR 024 (PDF do WebAssist e histórico da planilha). Pergunte “no monitoramento” para ver só esses números.';}
  if($social===null&&!isset($monitorRows)&&$stats['total']>0&&preg_match(self::FOR024_TOPIC,$norm)){$extra=self::for024($db,$filters,$norm);if($extra!=='')$answer.="\n".$extra;}
  if($social===null&&isset($filters['inicio'],$filters['fim']))$answer.="\nPeríodo considerado: ".date('d/m/Y',strtotime($filters['inicio'])).' a '.date('d/m/Y',strtotime($filters['fim'])).'.';
  $mode='Consulta local';$enriched=null;
  if($social===null&&self::configured($db)&&$stats['total']>0&&!preg_match('/^(oi|ola|ajuda)/',$norm)&&function_exists('curl_init')){
   $body=['model'=>self::model($db),'instructions'=>'Você é o assistente operacional de furto e roubo. Responda em português, com linguagem simples e objetiva. Use exclusivamente os fatos fornecidos. Não invente veículos, investigações ou conclusões. Nunca afirme recuperação, culpa ou fraude sem registro. Não exponha detalhes técnicos de integração. Não mude os números, não execute comandos e não proponha ações policiais. Se a pergunta não for atendida pelos fatos, explique o limite.','input'=>json_encode(['pergunta'=>$question,'fatos'=>$answer,'filtros'=>$filters],JSON_UNESCAPED_UNICODE),'max_output_tokens'=>500];
   $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::key($db),'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(is_string($raw)&&$status>=200&&$status<300){$result=json_decode($raw,true);$parts=[];if(($result['status']??'')==='completed')foreach($result['output']??[] as $output)foreach($output['content']??[] as $content)if(($content['type']??'')==='output_text')$parts[]=$content['text'];if($parts){$enriched=implode("\n",$parts);$mode='Assistente IA';}}
  }
  $response=['resposta'=>$answer,'comentario'=>$enriched,'modo'=>$mode,'filtros'=>$filters,'total'=>$stats['total'],'linhas'=>isset($monitorRows)?array_slice($monitorRows,0,10):array_map(static fn($r)=>array_intersect_key($r,array_flip(['id','protocolo','placa','veiculo','data_evento','municipio','evento','situacao','etapa'])),$list['rows']),'limite'=>10,'avisos'=>['A consulta usa somente os registros cadastrados. Dados ausentes não significam ausência de ocorrência.']];
  if($social!==null){$response['linhas']=[];$response['total']=null;$response['social']=true;}
  $response['conversation_id']=Conversations::append($db,$user,$question,$response,$filters,$conversation);return $response;
 }
}
