# Publicar no GitHub e no Coolify

## Atualização: boas-vindas e permissões (20/10/2026)

As boas-vindas estão no Painel e no Monitoramento. O Monitoramento usa os totais do ano selecionado no FOR 024.

Ao publicar esta versão pelo GitHub e fazer redeploy no Coolify, mantenha as variáveis do banco e `APP_KEY` existentes, além do volume `/var/www/html/storage`. Faça backup do banco antes. O entrypoint executa `scripts/install.php`, que aplica `database/atualizacao-usuarios.sql` sem substituir as senhas ou ocorrências existentes. A conta de menor ID vira Administrador na primeira migração; confira o acesso depois do deploy. Reexecutar preserva os perfis já definidos. A conta padrão só é criada se o banco não tiver usuários.

Inclua no commit os novos arquivos `app/Users.php`, `assets/js/users.js`, `assets/welcome.svg`, `database/atualizacao-usuarios.sql` e `scripts/migrate-users.php`, além dos arquivos modificados. Nos logs do deploy, confirme que o instalador terminou sem erros de banco. Instruções completas em `ATUALIZACAO-USUARIOS.md`.

## 1. GitHub

Crie um repositório **privado** no GitHub (ex.: `sistema-fb`) e, nesta pasta:

```
git remote add origin https://github.com/SEU-USUARIO/sistema-fb.git
git push -u origin main
```

Ficam fora do GitHub (veja `.gitignore`): `.env`, `documento/`, `entrega/`, `storage/` (planilhas importadas e modelo FOR 024), `database/dados-iniciais.sql` e os prints dos testes. São dados reais de clientes.

## 2. Coolify

Escolha uma das opções.

### Opção A — Docker Compose (sistema + banco juntos, mais simples)

1. New Resource → Public/Private Repository (GitHub) → escolha o repositório, branch `main`.
2. Build Pack: **Docker Compose** (arquivo `docker-compose.yml`).
3. Em Environment Variables:
   - `DB_PASS` = senha forte do banco
   - `DB_ROOT_PASS` = outra senha forte
   - `APP_KEY` = 32+ caracteres aleatórios (protege a chave da IA; **não troque depois**, senão a chave salva deixa de abrir)
   - `OPENAI_KEY` e `OPENAI_MODEL` (opcionais: também podem ser configurados na tela de Configurações)
4. Em Domains, informe o domínio no serviço `app` (porta 80).
5. Deploy.

### Opção B — Dockerfile + banco MySQL/MariaDB já existente no Coolify

1. New Resource → repositório → Build Pack: **Dockerfile**. Porta: `80`.
2. Environment Variables: `DB_HOST` (nome interno do banco no Coolify), `DB_PORT=3306`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_KEY`, e opcionalmente `OPENAI_KEY`, `OPENAI_MODEL`.
3. Storage: volume persistente em `/var/www/html/storage`.
4. Deploy.

Na inicialização, o container cria as tabelas e o administrador inicial se ainda não existirem (`scripts/install.php`). Rodar de novo não apaga nada.

## 3. Levar os dados atuais

Os registros já cadastrados não vão pelo GitHub. Exporte o banco local e importe no banco do Coolify:

```
C:\xampp\mysql\bin\mysqldump -uroot --single-transaction axioma_furto_roubo > backup.sql
```

Depois, no terminal do serviço `db` (Coolify → Terminal), ou com um cliente MySQL conectado ao banco:

```
mysql -usistema_fb -p axioma_furto_roubo < backup.sql
```

O modelo FOR 024 (`storage/templates/FOR_024.xlsx`) pode ser reenviado pela tela do monitoramento.

## 4. Depois do primeiro acesso

- Entre com o administrador inicial e **troque a senha** em Configurações.
- Atualizações: `git push` para o GitHub e Deploy no Coolify (ou ative o deploy automático).
