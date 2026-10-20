# Usuários e permissões

A atualização usa somente o banco independente do Furto & Roubo configurado em `sistema-FB/.env` ou nas variáveis do servidor. Não utiliza o banco do sistema anterior.

## O que muda

- Novo menu **Usuários**, disponível aos administradores: cadastrar, editar, definir senha e desativar contas.
- Perfis Administrador, Operador e Consulta, com permissões individuais. Administrar usuários é exclusivo do perfil Administrador.
- Operador: consulta, cadastro/edição, importação, exportação e IA. Consulta: somente leitura por padrão.
- Configuração de IA/aparência e exclusão de importações dependem de permissões específicas.
- Senhas novas: 10 a 200 caracteres. As senhas atuais continuam funcionando.
- As permissões são verificadas na API em cada requisição. Desativar, alterar permissões ou redefinir senha invalida as sessões anteriores; operações já em execução podem terminar.
- Não há exclusão física de usuários: desativar preserva os vínculos e o histórico.
- O último administrador ativo não pode perder o perfil nem ser desativado. Ninguém pode desativar a própria conta.

## Banco existente em produção

1. Faça backup completo do banco e guarde a versão anterior dos arquivos. Confirme o `DB_NAME` do Furto & Roubo antes de executar.
2. Em uma janela de manutenção, aplique **somente** `database/atualizacao-usuarios.sql` no banco existente, pelo phpMyAdmin, ou execute na pasta do sistema:

   ```sh
   php scripts/migrate-users.php
   ```

3. A migração cria `fb_user_access` e `fb_user_audit`. Preserva IDs, nomes, e-mails, senhas, registros e histórico. Não executa `DROP`, `TRUNCATE` nem alterações em `fb_occurrences`.
4. A conta de menor ID existente recebe o perfil Administrador. Outras contas existentes recebem Operador. Confirme que essa conta pertence ao responsável pela administração antes de liberar o sistema. Caso seja outra pessoa, ajuste o perfil no banco durante a manutenção ou use a primeira conta para cadastrar outro administrador.
5. Publique os arquivos atualizados e entre com a conta administradora já existente. Confira Usuários, depois teste um Operador e uma conta Consulta antes de liberar à equipe.
6. Reexecutar a migração não redefine acessos, reativa contas nem troca senhas. Usuários criados posteriormente recebem o perfil escolhido na tela.

**Não importe `admin.sql`, `dados.sql`, um dump local ou execute uma reinstalação sobre o banco de produção para fazer esta atualização.** O procedimento de atualização é a migração acima.

### Coolify / Docker

O instalador chamado pelo entrypoint aplica a migração de usuários após preparar a estrutura. A conta padrão é criada somente se ainda não existir nenhum usuário no banco. O redeploy mantém o banco existente; confirme backup, variáveis de conexão e volume persistente de `storage` antes. A migração pode também ser aplicada previamente no banco, com a aplicação antiga em manutenção. Não copie `.env` local para produção.

### cPanel

Faça backup pelo painel e importe apenas `database/atualizacao-usuarios.sql` pelo phpMyAdmin no banco do Furto & Roubo. Em seguida envie os arquivos da atualização, mantendo `.env` e `storage` existentes. O script CLI é opcional quando há terminal PHP.

### Retorno à versão anterior

As novas tabelas podem permanecer no banco: a versão anterior não as utiliza. Porém a versão anterior não aplica estas permissões. Mantenha o acesso em manutenção se precisar voltar, até corrigir a atualização. Não apague as tabelas de acesso ou auditoria.

## Verificação local

`php tests/users.php` cria um banco temporário exclusivo, testa a migração e as regras de autorização e remove esse banco ao final. Requer permissão de criar bancos no ambiente de desenvolvimento. Nunca execute testes em produção.
