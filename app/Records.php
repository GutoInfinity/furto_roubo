<?php
declare(strict_types=1);
final class Records {
 public const FIELDS=['placa','veiculo','ano_modelo','cor','data_evento','boletim','municipio','vitima','associacao','evento','situacao','relatorio','investigacao','solucao'];
 public const LABELS=['PLACA','VEÍCULO/MARCA/MODELO','ANO/MODELO','COR','DATA','BOLETIM DE OCORRÊNCIA','LOCAL/MUNICÍPIO','ASSOCIADO/VITIMA','ASSOCIAÇÃO','EVENTO','SITUAÇÃO','RELATÓRIO','INVESTIGAÇÃO','SOLUÇÃO DO EVENTO'];
 public const STAGES=['Registrado','Em análise','Em buscas','Recuperado','Encerrado','Desistência'];
 public static function norm(string $v):string {return trim(strtolower(strtr($v,['Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','É'=>'e','Ê'=>'e','Í'=>'i','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ú'=>'u','Ç'=>'c','á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'])));}
 public static function date(string $v):?string {
  $v=trim($v," \t\n\r.");if($v==='')return null;
  if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))$iso=$v;
  elseif(preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$~D',$v,$m))$iso=sprintf('%04d-%02d-%02d',$m[3],$m[2],$m[1]);else return null;
  [$y,$m,$d]=array_map('intval',explode('-',$iso));return $y>=1900&&$y<=2100&&checkdate($m,$d,$y)?$iso:null;
 }
 public static function normalize(array $input,bool $import=false):array {
  $out=[];foreach(array_merge(self::FIELDS,['data_original','etapa','observacoes']) as $key){$v=$input[$key]??'';if(!is_scalar($v)&&$v!==null)throw new InvalidArgumentException('Valor inválido em '.$key.'.');$v=trim(str_replace("\0",'',(string)$v));$limit=in_array($key,['relatorio','investigacao','observacoes'],true)?20000:($key==='placa'?40:255);if(strlen($v)>$limit*4||mb_strlen($v,'UTF-8')>$limit)throw new InvalidArgumentException('Texto muito longo em '.$key.'.');$out[$key]=$v;}
  $out['placa']=mb_strtoupper($out['placa'],'UTF-8');if($out['placa']===''||$out['veiculo']==='')throw new InvalidArgumentException('Informe a placa e o veículo.');
  $raw=$out['data_evento']?:$out['data_original'];$out['data_evento']=self::date($raw);$out['data_original']=$out['data_original']?:$raw;
  if(!$import&&!empty($input['data_evento'])&&$out['data_evento']===null)throw new InvalidArgumentException('Informe uma data válida. Intervalos e datas antigas incorretas devem ser conferidos.');
  foreach(['ano_modelo'=>60,'cor'=>80,'data_original'=>80,'situacao'=>80,'etapa'=>40] as $key=>$limit)if(mb_strlen($out[$key],'UTF-8')>$limit)throw new InvalidArgumentException('Texto muito longo em '.$key.'.');
  if(!$import&&!in_array(self::norm($out['evento']),['furto','roubo','nao informado'],true))throw new InvalidArgumentException('Selecione o tipo do evento.');
  $type=self::norm($out['evento']);$out['evento']=$type==='furto'?'Furto':($type==='roubo'?'Roubo':'Não informado');
  $status=self::norm($out['situacao']);$map=['buscas'=>'Em buscas','em buscas'=>'Em buscas','em aberto'=>'Em aberto','em abero'=>'Em aberto','em analise'=>'Em análise','recuperado'=>'Recuperado','recuperada'=>'Recuperado','recuperadd'=>'Recuperado','recuperado (desmanchado)'=>'Recuperado','desistiu'=>'Desistência','desistencia'=>'Desistência'];$out['situacao']=$map[$status]??($out['situacao']?:'Não informada');
  if(!in_array($out['etapa'],self::STAGES,true)){$solution=self::norm($out['solucao']);$out['etapa']=in_array($solution,['finalizado','finalizada'],true)?'Encerrado':($out['situacao']==='Recuperado'?'Recuperado':($out['situacao']==='Desistência'?'Desistência':($out['situacao']==='Em buscas'?'Em buscas':($out['situacao']==='Em análise'?'Em análise':'Registrado'))));}
  $issues=[];if($raw!==''&&$out['data_evento']===null)$issues[]='Data original precisa de conferência: '.$raw;
  foreach(['data_evento'=>'data','boletim'=>'boletim','municipio'=>'município','vitima'=>'associado/vítima','associacao'=>'associação'] as $key=>$label)if(empty($out[$key]))$issues[]='Sem '.$label;
  if($out['evento']==='Não informado')$issues[]='Tipo de evento não informado';
  $out['avisos_json']=json_encode($issues,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=[];foreach(self::FIELDS as $k)$hash[$k]=$out[$k];$out['content_hash']=hash('sha256',json_encode($hash,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return $out;
 }
 public static function present(array $row):array {$row['protocolo']='FB-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT);$row['avisos']=json_decode($row['avisos_json']??'[]',true)?:[];unset($row['avisos_json'],$row['content_hash'],$row['source_key']);return $row;}
 public static function where(array $filters):array {
  foreach($filters as $v)if(!is_scalar($v)&&$v!==null)throw new InvalidArgumentException('Filtro inválido.');
  $where=[];$params=[];foreach(['evento','situacao','etapa','associacao','municipio'] as $key)if(isset($filters[$key])&&is_scalar($filters[$key])&&trim((string)$filters[$key])!==''){$where[]="$key=:$key";$params[$key]=trim((string)$filters[$key]);}
  foreach(['inicio'=>'>=','fim'=>'<='] as $key=>$op)if(!empty($filters[$key])){$date=self::date((string)$filters[$key]);if(!$date)throw new InvalidArgumentException('Filtro de data inválido.');$where[]="data_evento $op :$key";$params[$key]=$date;}
  if(!empty($params['inicio'])&&!empty($params['fim'])&&$params['inicio']>$params['fim'])throw new InvalidArgumentException('A data inicial deve vir antes da final.');
  if(($filters['incompletos']??'')==='1')$where[]="avisos_json<>'[]'";
  if(($filters['andamento']??'')==='1')$where[]="etapa NOT IN ('Encerrado','Desistência')";
  $search=trim((string)($filters['q']??''));if(mb_strlen($search)>120)throw new InvalidArgumentException('Use uma busca com até 120 caracteres.');
  if($search!==''){$parts=[];$value='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';foreach(['placa','veiculo','boletim','municipio','vitima','associacao','relatorio','investigacao','solucao','observacoes'] as $i=>$column){$parts[]="$column LIKE :q$i ESCAPE '!'";$params['q'.$i]=$value;}if(preg_match('/^(?:FB[- ]?)?(\d+)$/i',$search,$m)){$parts[]='id=:record_id';$params['record_id']=(int)$m[1];}$parts[]="REPLACE(REPLACE(placa,'-',''),' ','') LIKE :normalized_plate ESCAPE '!'";$params['normalized_plate']='%'.strtr(str_replace(['-',' '],'',$search),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$where[]='('.implode(' OR ',$parts).')';}
  return [$where?' WHERE '.implode(' AND ',$where):'',$params];
 }
 public static function list(PDO $db,array $filters,int $limit=25,int $offset=0):array {
  [$where,$params]=self::where($filters);$q=$db->prepare('SELECT COUNT(*) FROM fb_occurrences'.$where);$q->execute($params);$total=(int)$q->fetchColumn();$limit=max(1,min(50000,$limit));$offset=max(0,$offset);
  $q=$db->prepare('SELECT * FROM fb_occurrences'.$where." ORDER BY data_evento IS NULL,data_evento DESC,id DESC LIMIT $limit OFFSET $offset");$q->execute($params);return ['total'=>$total,'rows'=>array_map([self::class,'present'],$q->fetchAll())];
 }
 public static function stats(PDO $db,array $filters=[]):array {
  [$where,$params]=self::where($filters);$q=$db->prepare("SELECT COUNT(*) total,SUM(evento='Furto') furtos,SUM(evento='Roubo') roubos,SUM(situacao='Recuperado') recuperados,SUM(etapa='Encerrado') encerrados,SUM(etapa NOT IN ('Encerrado','Desistência')) andamento,SUM(avisos_json<>'[]') incompletos,SUM(data_evento IS NULL) sem_data FROM fb_occurrences".$where);$q->execute($params);$out=array_map('intval',$q->fetch());
  foreach(['mensal'=>"COALESCE(SUBSTR(data_evento,1,7),'Sem data')",'etapas'=>'etapa','tipos'=>'evento','municipios'=>"COALESCE(NULLIF(municipio,''),'Não informado')",'associacoes'=>"COALESCE(NULLIF(associacao,''),'Não informada')"] as $key=>$field){$q=$db->prepare("SELECT $field label,COUNT(*) total FROM fb_occurrences$where GROUP BY $field ORDER BY ".($key==='mensal'?'label':'total DESC')." LIMIT ".($key==='mensal'?'200':'12'));$q->execute($params);$out[$key]=$q->fetchAll();}return $out;
 }
 public static function get(PDO $db,int $id):array {$q=$db->prepare('SELECT * FROM fb_occurrences WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Ocorrência não encontrada.');return self::present($row);}
 public static function history(PDO $db,int $id):array {$q=$db->prepare('SELECT h.*,u.name user_name FROM fb_history h JOIN fb_users u ON u.id=h.user_id WHERE occurrence_id=? ORDER BY h.id DESC LIMIT 100');$q->execute([$id]);$rows=$q->fetchAll();foreach($rows as &$row){$row['changes']=json_decode($row['changes_json'],true);unset($row['changes_json']);}return $rows;}
 public static function save(PDO $db,array $input,int $user,?int $id=null,?int $revision=null,string $action='Edição',bool $import=false,?string $source=null,?array $original=null):array {
  $safe=self::normalize($input,$import);$owner=!$db->inTransaction();if($owner)$db->beginTransaction();
  try{$before=$id?self::get($db,$id):null;$safe['atualizado_por']=$user;$changes=[];
   if($before){foreach(array_merge(self::FIELDS,['etapa','observacoes','data_original']) as $key)if((string)$safe[$key]!== (string)($before[$key]??''))$changes[$key]=['antes'=>$before[$key]??'','depois'=>$safe[$key]];
    if($revision===null||$revision!==(int)$before['revision'])throw new UnexpectedValueException('Este registro foi alterado por outra pessoa. Reabra antes de salvar.');
    $sets=[];foreach(array_keys($safe) as $key)$sets[]="$key=:$key";$safe['id']=$id;$safe['expected']=$revision;$q=$db->prepare('UPDATE fb_occurrences SET '.implode(',',$sets).',revision=revision+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND revision=:expected');$q->execute($safe);if($q->rowCount()!==1)throw new UnexpectedValueException('O registro foi alterado em outra sessão. Reabra antes de salvar.');
   }else{$safe['criado_por']=$user;$safe['source_key']=$source;$safe['original_json']=$original===null?null:json_encode($original,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$keys=array_keys($safe);$q=$db->prepare('INSERT INTO fb_occurrences('.implode(',',$keys).') VALUES(:'.implode(',:',$keys).')');$q->execute($safe);$id=(int)$db->lastInsertId();$changes=['resumo'=>$action==='Importação'?'Registro recebido da planilha':'Ocorrência cadastrada','etapa'=>$safe['etapa']];}
   $q=$db->prepare('INSERT INTO fb_history(occurrence_id,user_id,action,changes_json) VALUES(?,?,?,?)');$q->execute([$id,$user,$before?$action:($action==='Importação'?'Importação':'Cadastro'),json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);if($owner)$db->commit();return self::get($db,$id);
  }catch(Throwable $e){if($owner&&$db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function options(PDO $db):array {$out=[];foreach(['associacao','municipio','situacao'] as $key)$out[$key]=$db->query("SELECT DISTINCT $key FROM fb_occurrences WHERE $key<>'' ORDER BY $key LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);$out['etapa']=self::STAGES;return $out;}
}
