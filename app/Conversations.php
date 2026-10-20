<?php
declare(strict_types=1);
final class Conversations {
 public static function find(PDO $db,int $user,int $id,bool $lock=false):array{$q=$db->prepare('SELECT * FROM fb_conversations WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id,$user]);$r=$q->fetch();if(!$r)throw new OutOfBoundsException('Esta conversa não está disponível.');return $r;}
 public static function list(PDO $db,int $user):array{$q=$db->prepare('SELECT id,title,created_at,updated_at FROM fb_conversations WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 200');$q->execute([$user]);return $q->fetchAll();}
 public static function history(PDO $db,int $user,int $id):array{
  $thread=self::find($db,$user,$id);$q=$db->prepare('SELECT c.id,c.question,c.answer,c.created_at FROM fb_chat c JOIN fb_conversation_messages l ON l.message_id=c.id WHERE l.conversation_id=? AND c.user_id=? ORDER BY c.id DESC LIMIT 200');$q->execute([$id,$user]);$rows=array_reverse($q->fetchAll());foreach($rows as &$r)$r['answer']=json_decode($r['answer'],true);return ['conversation'=>$thread,'rows'=>$rows];
 }
 public static function append(PDO $db,int $user,string $question,array $response,array $filters,?int $id):int {
  $own=!$db->inTransaction();if($own)$db->beginTransaction();try{
   $thread=$id?self::find($db,$user,$id,true):null;
   if(!$id){$q=$db->prepare('INSERT INTO fb_conversations(user_id,title) VALUES(?,?)');$q->execute([$user,mb_substr($question,0,100)]);$id=(int)$db->lastInsertId();}
   elseif(empty($response['social'])&&AssistantLanguage::social(AssistantLanguage::normalize($thread['title']))!==null)$db->prepare('UPDATE fb_conversations SET title=? WHERE id=?')->execute([mb_substr($question,0,100),$id]);
   $q=$db->prepare('INSERT INTO fb_chat(user_id,question,answer,filters_json) VALUES(?,?,?,?)');$q->execute([$user,$question,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$message=(int)$db->lastInsertId();
   $db->prepare('INSERT INTO fb_conversation_messages(conversation_id,message_id) VALUES(?,?)')->execute([$id,$message]);$db->prepare('UPDATE fb_conversations SET updated_at=NOW() WHERE id=?')->execute([$id]);if($own)$db->commit();return $id;
  }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function delete(PDO $db,int $user,int $id):void{$db->beginTransaction();try{self::find($db,$user,$id,true);$q=$db->prepare('DELETE c FROM fb_chat c JOIN fb_conversation_messages l ON l.message_id=c.id WHERE l.conversation_id=? AND c.user_id=?');$q->execute([$id,$user]);$db->prepare('DELETE FROM fb_conversations WHERE id=? AND user_id=?')->execute([$id,$user]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
}
