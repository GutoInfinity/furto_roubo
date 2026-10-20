-- Migração aditiva: não altera usuários, senhas ou ocorrências existentes.
CREATE TABLE IF NOT EXISTS fb_user_access (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 role VARCHAR(20) NOT NULL DEFAULT 'operator',
 active TINYINT NOT NULL DEFAULT 1,
 permissions_json TEXT NULL,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (user_id) REFERENCES fb_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fb_user_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(40) NOT NULL,
 details_json TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_fb_user_audit(target_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Somente contas ainda sem perfil são inicializadas. Reexecutar preserva permissões.
-- A primeira conta existente administra o sistema; as demais começam como operadores.
INSERT INTO fb_user_access(user_id,role)
 SELECT u.id,CASE WHEN u.id=(SELECT MIN(first_user.id) FROM fb_users first_user) THEN 'admin' ELSE 'operator' END
 FROM fb_users u LEFT JOIN fb_user_access a ON a.user_id=u.id WHERE a.user_id IS NULL;
