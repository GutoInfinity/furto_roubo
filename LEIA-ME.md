# Infinity System — Furto e Roubo

Sistema independente em PHP 8.0+ e MySQL, com o padrão visual do sistema anterior. Não utiliza o banco nem os usuários do sistema anterior.

## Acesso local

http://localhost/bate_pronto/sistema-FB/

E-mail inicial: `admin@centrocorporativo.com.br`

Senha inicial: definida em `database/admin.sql` (peça ao responsável e troque em Configurações no primeiro acesso).

Banco local exclusivo: `axioma_furto_roubo`. A senha pode ser alterada em Configurações.

## Fluxo proposto

1. Cadastrar a ocorrência com placa, veículo e tipo de evento.
2. Completar data, boletim, município, vítima e associação conforme disponíveis.
3. Acompanhar nas etapas Registrado, Em análise e Em buscas.
4. Registrar recuperação, encerramento ou desistência, mantendo relatório, investigação e solução.

Etapa operacional e situação do veículo são campos separados. Cada edição fica no histórico e tem controle de revisão para impedir que duas edições simultâneas se sobrescrevam. A pesquisa aceita placa, boletim, vítima, município e protocolo, com filtros combináveis. O painel considera os mesmos registros e filtros da base.

## Planilha recebida

Foram importadas as 474 linhas preenchidas de `11 AANESC.xlsx`. Planilhas vazias e linhas em branco foram ignoradas. Foram preservadas as 14 colunas de negócio e os valores originais, disponíveis nos detalhes da ocorrência.

A planilha identifica 82 furtos e 2 roubos; 390 registros não informam o tipo. Existem 423 cadastros com informações a conferir. Cinco registros ficaram sem data confirmada: três sem data e dois com ano inválido ou intervalo de dias. Essas datas permanecem no conteúdo original e não entram nos filtros por período. Não foram inventadas informações ausentes.

## Personalização do painel

O lápis no canto inferior direito abre o mesmo painel de personalização do sistema anterior, com as mesmas 11 seções, opções, miniaturas e paleta de cores. Inclui Da empresa, modo automático/claro/escuro, menu colorido, paleta invertida, fonte, base do tema, cantos, largura da página, menu no topo ou na lateral, menu fixo/flutuante e painel de configurações flutuante. A sidebar pode ficar expandida, só com ícones ou expandir ao passar o mouse ou navegar pelo teclado. As cores recebem contraste de texto automático.

As alterações são aplicadas imediatamente e salvas no banco exclusivo deste sistema. A opção Restaurar padrão permite desfazer as escolhas. No celular, a navegação se adapta ao topo. A atualização utiliza a tabela fb_settings já existente e não exige alteração de estrutura do banco.

## Importar e exportar planilhas

Em Planilhas, envie XLSX ou CSV, confira a prévia e confirme a importação. O limite é 10 MB e 20.000 linhas por arquivo. Reenviar o mesmo arquivo não duplica as linhas já importadas. Possíveis duplicidades entre arquivos diferentes são sinalizadas para decisão explícita.

Para editar em lote, exporte pelo sistema, preserve a coluna ID DO SISTEMA, altere os dados e selecione a opção de atualização por ID na nova importação. Registros novos devem ficar sem ID. Linhas com erro aparecem na conferência. Toda alteração importada também gera histórico. A exportação respeita os filtros atuais e suporta Excel e CSV.

## PDF do WebAssist → FOR 024 (automático)

Em Planilhas, no painel “Relatório PDF do WebAssist”:

1. Envie o PDF “Relatório de Furto / Roubo” gerado pelo WebAssist. O sistema lê a tabela pela grade do PDF: cliente, data do atendimento, modelo, montadora, beneficiário, placa, protocolo e situação. Nada é digitado.
2. Confira e importe. Cada protocolo entra uma única vez; reenviar o mesmo PDF não duplica. Placa repetida em até 7 dias é marcada como “mesmo veículo”: entra no sistema, mas não repete no Excel.
3. Envie uma vez a planilha FOR 024 atual (“Enviar modelo FOR 024”). Ela é guardada como modelo.
4. Clique em “Exportar FOR 024”. O arquivo é o mesmo modelo, com todas as abas, logos e formatação; as novas linhas entram na aba do ano, no bloco do mês (o bloco é criado se ainda não existir), com os mesmos estilos das linhas existentes. A data de “ÚLTIMA ATUALIZAÇÃO” é atualizada. Linhas já lançadas na planilha não são repetidas.

Preenchimento das colunas: DATA, CLIENTE, NOME DO BENEFICIÁRIO, PLACA e MARCA/MODELO vêm do PDF. TIPO DE VEÍCULO é previsto pelo histórico da própria planilha (ex.: CBR 250R → MOTO). VEÍCULO LOCALIZADO vem da situação (Em busca → NÃO; Recuperado → SIM e RECUPERADO). CIDADE, ESTADO, RASTREADOR e FURTO/ROUBO não existem no PDF e ficam em branco; se forem preenchidos depois na ocorrência (município como “Cidade/UF” e tipo do evento), saem na próxima exportação.

Se a planilha for editada à mão depois, envie a versão nova em “Atualizar modelo” antes de exportar de novo.

### Painel de Monitoramento (fluxo completo)

Em Painel › Monitoramento (`#painel/monitoramento`), em três passos:

1. **Enviar o PDF do WebAssist**: ler, conferir e importar. Ao terminar, as ocorrências do PDF ficam marcadas e aparecem duas opções de Excel:
   - **Modelo completo com histórico**: a planilha modelo inteira, com o histórico dela, mais as linhas novas do PDF;
   - **Somente as deste PDF**: só as ocorrências enviadas, com o mesmo visual e cabeçalho.
2. **Preencher no sistema**: na tabela, Cidade, UF, Tipo, Rastreador, Localizado e Furto/Roubo podem ser editados (cada alteração é salva na hora e fica no histórico da ocorrência). O que o PDF não traz fica em branco até ser preenchido. O filtro “Só sem cidade” mostra o que falta.
   - **Importar histórico do Excel**: traz para o sistema todas as linhas já lançadas no FOR 024 (abas 2021 a 2026, nos layouts de cada ano). Reenviar não duplica. Se ainda não houver modelo, a planilha também vira o modelo.
3. **Exportar**:
   - Modelo completo com histórico;
   - **Excel novo com os dados do sistema**: gera o FOR 024 só com o que está no sistema (histórico importado + PDFs, com as edições), uma aba por ano;
   - Somente as selecionadas.

Também no painel: indicadores e gráficos por ano (mês, tipo de veículo, clientes e cidades) e a lista de importações. Excluir uma importação (com confirmação) remove as ocorrências dela e libera o reenvio.

## Assistente

O assistente local encontra ocorrências e calcula totais por placa, tipo, período, município e etapa. Exemplos: “Quantos furtos em Joinville em 2026?”, “Quais ocorrências estão em aberto?” ou uma placa. Use Nova conversa para limpar os filtros de contexto. Também responde sobre o controle FOR 024: “Quantas motos sem rastreador em 2026?”, “Quais cidades têm mais ocorrências?”, “Ocorrências do cliente SC CLUBE”, “Quantas estão sem cidade?”. A consulta permite conferir os registros utilizados e exportar o resultado.

O botão “?” / Ajuda abre as perguntas prontas. A lateral mostra conversas pesquisáveis com opção de retomar e excluir. Nova conversa inicia um assunto sem os filtros anteriores. Saudações, abreviações e erros comuns são reconhecidos. O assistente aceita meses por nome ou número, mês atual/passado e intervalos como “mês 7 até mês 8” ou “dia 21 do 8 até 21 do 9”; sem ano informado, usa o ano atual e mostra o período exato considerado. Para atualizar uma instalação existente, siga `ATUALIZAR-IA.md` e importe `database/atualizacao-conversas.sql` no banco exclusivo deste sistema.

A consulta local funciona sem integração externa. Em Configurações, informe modelo e chave para acrescentar explicações de um modelo externo. Essa integração não foi ativada porque não havia chave configurada disponível. O assistente não altera ocorrências. A chave é armazenada cifrada com APP_KEY; preserve essa chave ao atualizar a instalação.

## Instalação nova no cPanel

1. Crie um banco MySQL NOVO e um usuário com permissão nesse banco. Não selecione o banco do sistema anterior.
2. Extraia o ZIP dentro da pasta destinada ao novo sistema, por exemplo `public_html/sistema-FB`. O ZIP já contém index.php na raiz.
3. No phpMyAdmin, selecione o novo banco e importe, nesta ordem: `database/schema.sql`, `database/admin.sql` e `database/dados-iniciais.sql`. O último arquivo contém os 474 registros e seu histórico inicial e deve ser usado somente na instalação nova, em tabelas vazias.
4. Copie `.env.example` para `.env` e configure DB_HOST, DB_PORT, DB_NAME, DB_USER e DB_PASS com os dados do cPanel, incluindo o prefixo do banco/usuário.
5. Gere uma APP_KEY aleatória com `php -r "echo bin2hex(random_bytes(32));"` e coloque o resultado no .env. Não reutilize o texto de exemplo nem deixe esse campo vazio.
6. Ative PHP 8.0 ou superior com pdo_mysql, mbstring, zip, SimpleXML, DOM, openssl e curl. Configure upload_max_filesize em pelo menos 10M e post_max_size acima de 10M. A pasta storage/imports precisa permitir escrita pelo PHP.
7. Acesse a pasta pelo navegador e entre com o acesso inicial. O .htaccess protege configurações, SQL e arquivos internos; mantenha-o na hospedagem Apache do cPanel.

O pacote não inclui o .env local, chaves de integração, arquivos temporários ou testes. Inclui as fontes locais, portanto não depende da instalação anterior.

Alternativa com terminal e permissão para criar banco: configure o .env e execute `php scripts/install.php` para estrutura e administrador; depois importe os dados iniciais no novo banco, ou use o caminho da planilha como argumento do instalador. Não importe a planilha original novamente se já restaurou dados-iniciais.sql.

## Validação realizada

28 verificações de serviços: leitura real, reimportação sem duplicação, exportação/reimportação XLSX e CSV, datas inválidas, histórico, conflito de revisão, busca parametrizada e filtros do assistente. Testes de navegador: autenticação, proteção de arquivos internos, filtro de roubos, prévia real de importação, navegação do assistente/configurações e apresentação no celular.
