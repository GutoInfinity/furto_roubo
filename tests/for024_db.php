<?php
declare(strict_types=1);
// Fluxo completo no banco de desenvolvimento: histórico do Excel → PDF → edição de cidade/UF → Excel → assistente.
// Tudo o que o teste grava é excluído no final (as importações são removidas pelo próprio sistema).
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/app/bootstrap.php';
$db=fb_db();if($db->query('SELECT DATABASE()')->fetchColumn()!=='axioma_furto_roubo')throw new RuntimeException('Os testes locais exigem o banco independente de desenvolvimento.');
$pdf=FB_ROOT.'/documento/roubo e furto.pdf';$xlsx=FB_ROOT.'/documento/FOR_024_CONTROLE_DE_ROUBOS_E_FURTOS_VER02.xlsx';
if(!is_file($pdf)||!is_file($xlsx)){echo "Arquivos de exemplo ausentes em documento/: teste ignorado.\n";exit(0);}
if(For024::rows($db)['rows']){echo "Já há dados do FOR 024 no banco: teste de importação ignorado para não mexer neles.
";exit(0);}
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException('Falhou: '.$label);$checks++;};
$user=(int)$db->query('SELECT id FROM fb_users ORDER BY id LIMIT 1')->fetchColumn();$before=(int)$db->query('SELECT COUNT(*) FROM fb_occurrences')->fetchColumn();
$installed=ControlWorkbook::templatePath();$backup=is_file($installed)?$installed.'.bak-test':null;if($backup)copy($installed,$backup);$imports=[];
try{
 ControlWorkbook::saveTemplate($xlsx);
 $h=For024::importHistory($db,$xlsx,'teste-automatico.xlsx',$user);$imports[]=$h['import'];
 $check($h['inserted']+$h['invalid']===756&&$h['inserted']>700,'histórico importado ('.$h['inserted'].')');
 $again=For024::importHistory($db,$xlsx,'teste-automatico.xlsx',$user);$check($again['import']===0&&$again['inserted']===0&&$again['skipped']===$h['inserted'],'reimportar o histórico não duplica');
 $plan=For024::preview($db,$pdf,'teste-automatico.pdf');$plan['hash']=hash_file('sha256',$pdf);$c=For024::commit($db,$plan,$user,$plan['hash']);$imports[]=$c['import'];
 $check($c['inserted']===14,'PDF importado');
 $id=$c['ids'][0];$r=For024::update($db,$id,'cidade','  joinville ',$user);$check($r['planilha']['cidade']==='JOINVILLE','cidade gravada em maiúsculas');
 For024::update($db,$id,'estado','SC',$user);For024::update($db,$id,'evento','ROUBO',$user);
 $row=$db->query('SELECT municipio,evento FROM fb_occurrences WHERE id='.$id)->fetch();$check($row['municipio']==='JOINVILLE/SC'&&$row['evento']==='Roubo','cadastro sincronizado');
 try{For024::update($db,$id,'estado','XX',$user);$check(false,'UF inválida aceita');}catch(InvalidArgumentException $e){$checks++;}
 $rows=For024::rows($db,[$id])['rows'];$check($rows[0]['cidade']==='JOINVILLE'&&$rows[0]['estado']==='SC','exportação usa a cidade preenchida');
 $out=tempnam(sys_get_temp_dir(),'for024_');$res=ControlWorkbook::exportOnly($rows,$out);$zip=new ZipArchive();$zip->open($out);$sheet=(string)$zip->getFromName('xl/worksheets/sheet6.xml');$zip->close();@unlink($out);
 $check(count($res['added'])===1&&str_contains($sheet,'>JOINVILLE<')&&str_contains($sheet,'>ROUBO<'),'Excel das selecionadas com cidade e furto/roubo');
 $all=For024::rows($db)['rows'];$out=tempnam(sys_get_temp_dir(),'for024_');$res=ControlWorkbook::exportOnly($all,$out);$zip->open($out);$wb=(string)$zip->getFromName('xl/workbook.xml');$zip->close();@unlink($out);
 $check(count($res['added'])===count($all)&&str_contains($wb,'name="2021"')&&str_contains($wb,'name="2026"'),'Excel novo com todos os dados do sistema');
 $pdfOnly=For024::rows($db,null,[For024::ORIGIN])['rows'];$out=tempnam(sys_get_temp_dir(),'for024_');$res=ControlWorkbook::export($pdfOnly,$out,new DateTimeImmutable('2026-10-20'));@unlink($out);$check(count($res['added'])===6,'modelo completo: só as novas do PDF');
 $m=For024::monitor($db,2026,new DateTimeImmutable('2026-10-20'));$check($m['ano']===2026&&$m['totais']['pdf']===14&&$m['totais']['planilha']>=130&&in_array(2021,$m['anos'],true),'monitor por ano');
 $text=Assistant::for024($db,[],'quantas motos sem rastreador por cidade');$check(str_contains($text,'FOR 024')&&str_contains($text,'MOTO')&&str_contains($text,'JOINVILLE/SC'),'assistente com dados do FOR 024');
 $f=Assistant::filters($db,'ocorrências do cliente SC CLUBE',[]);$check(($f['associacao']??'')==='SC CLUBE','cliente reconhecido na pergunta');
}finally{
 foreach(array_reverse($imports) as $i){try{For024::deleteImport($db,$i);}catch(Throwable $e){}}
 if($backup){copy($backup,$installed);unlink($backup);}else @unlink($installed);
}
$check((int)$db->query('SELECT COUNT(*) FROM fb_occurrences')->fetchColumn()===$before,'dados de teste removidos');
echo $checks." verificações do FOR 024 no banco aprovadas.\n";
