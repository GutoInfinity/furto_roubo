# Atualização do assistente — Infinity System

## Instalação local

O novo assistente e a atualização do banco já foram aplicados em `sistema-FB`. As ocorrências e as mensagens anteriores foram preservadas. As mensagens antigas ficam agrupadas na conversa “Conversas anteriores”.

## Atualizar uma instalação existente no cPanel

1. Extraia `atualizacao-personalizacao-20261016.zip` dentro da pasta deste sistema, substituindo os arquivos correspondentes.
2. No phpMyAdmin, selecione exclusivamente o banco do Infinity System e importe `database/atualizacao-conversas.sql`. Essa atualização cria as tabelas de conversas e organiza o histórico existente; não substitui ocorrências, usuários ou configurações. Pode ser reaplicada sem duplicar as conversas antigas.
3. Recarregue a página do assistente. O acesso, a chave de integração e o arquivo .env permanecem os mesmos.

Se a hospedagem tiver terminal, o passo 2 pode ser realizado com `php scripts/update.php` dentro da pasta do sistema, utilizando o .env já configurado. Não importe dados-iniciais.sql ou admin.sql para atualizar uma instalação existente.

## O que mudou

- As perguntas de exemplo ficam no botão “?” / Ajuda, ao lado de Nova conversa.
- A lateral mostra conversas, permite pesquisar pelo título, retomar o assunto e excluir uma conversa com confirmação. Excluir uma conversa não exclui ocorrências. Cada usuário acessa apenas o próprio histórico.
- Nova conversa limpa o contexto; perguntas seguintes na mesma conversa mantêm os filtros até você mudar o assunto. Os detalhes da resposta permitem conferir os filtros e exportar a consulta.
- Saudações como “oi”, “olá”, “bom dia”, “oi, tudo bem?” e agradecimentos recebem respostas próprias.
- Abreviações e erros comuns são normalizados, por exemplo “qnts frutos mespasado” é interpretado como “quantos furtos mês passado”. Datas impossíveis são rejeitadas com orientação.
- Períodos: neste/nesse mês, mês passado/anterior, hoje, ontem, últimos N dias, ano atual/passado, mês 7, mês 7 até mês 8, julho a agosto, 21/8 até 21/9 e “dia 21 do 8 até 21 do 9”. Também aceita o ano explícito e intervalos que passam de dezembro para janeiro.

Sem ano informado, meses e datas usam o ano atual do servidor. As datas inicial e final são inclusivas. “Mês 7 até mês 8” inclui os dois meses inteiros. A resposta informa o período exato usado. Os números continuam vindo da base cadastrada. A conexão opcional com modelo externo continua disponível em Configurações; a consulta local ampliada funciona sem ela.
