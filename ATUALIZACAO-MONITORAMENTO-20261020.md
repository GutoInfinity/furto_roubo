Excluir importação, Monitoramento e exportação dos selecionados (20/10/2026)

- Planilhas › Importações de PDF: botão "Excluir" (pede confirmação). Remove as ocorrências daquele PDF, inclusive as importadas antes desta versão, e permite reenviar o mesmo arquivo.
- Menu: "Painel" abre as opções Painel e Monitoramento (menu no topo: lista abrindo para baixo; menu lateral: logo abaixo do item).
- Monitoramento:
  - indicadores do WebAssist e gráficos por mês, situação, cliente e tipo de veículo;
  - tabela com busca, filtro "Só em busca" e caixas de seleção.
- "Exportar selecionados": Excel no formato FOR 024 somente com as ocorrências marcadas, sem histórico e sem as abas dos outros anos. Cidade, UF, rastreador e furto/roubo em branco (só o que está no PDF).
- Correção: a exportação dos selecionados falhava ("regular expression is too large").

Arquivos: api.php, app/For024.php, app/ControlWorkbook.php, assets/js/app.js, assets/js/for024.js, assets/css/app.css, index.php, tests/for024.php, LEIA-ME.md.
Inclui também a atualização anterior (leitura do PDF e FOR 024): app/PdfText.php, app/WebAssistPdf.php, app/bootstrap.php e .htaccess.
Publicação: extraia na pasta do sistema-FB e pressione Ctrl+F5. Sem SQL.
