CREATE TABLE IF NOT EXISTS fb_conversations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(120) NOT NULL,
 legacy_owner BIGINT UNSIGNED NULL UNIQUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_fb_conversation_user(user_id,updated_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fb_conversation_messages (
 conversation_id BIGINT UNSIGNED NOT NULL,message_id BIGINT UNSIGNED NOT NULL UNIQUE,
 PRIMARY KEY(conversation_id,message_id),
 FOREIGN KEY(conversation_id) REFERENCES fb_conversations(id) ON DELETE CASCADE,
 FOREIGN KEY(message_id) REFERENCES fb_chat(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO fb_conversations(user_id,title,legacy_owner,created_at,updated_at)
 SELECT c.user_id,'Conversas anteriores',c.user_id,MIN(c.created_at),MAX(c.created_at)
 FROM fb_chat c LEFT JOIN fb_conversation_messages l ON l.message_id=c.id
 WHERE l.message_id IS NULL GROUP BY c.user_id;
INSERT IGNORE INTO fb_conversation_messages(conversation_id,message_id)
 SELECT t.id,c.id FROM fb_chat c JOIN fb_conversations t ON t.legacy_owner=c.user_id
 LEFT JOIN fb_conversation_messages l ON l.message_id=c.id WHERE l.message_id IS NULL;
