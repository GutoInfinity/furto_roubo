PDF do WebAssist → sistema → FOR 024 (20/10/2026)

O que faz:
- Planilhas › "Relatório PDF do WebAssist": envie o PDF "Relatório de Furto / Roubo". O sistema lê a tabela automaticamente (cliente, data, modelo, montadora, beneficiário, placa, protocolo e situação), mostra a conferência e importa com um clique. Reenviar o mesmo PDF não duplica (controle pelo protocolo).
- Planilhas › "FOR 024": envie uma vez a planilha FOR 024 atual como modelo e clique em "Exportar FOR 024". O arquivo baixado é o mesmo modelo com as novas linhas na aba do ano e no bloco do mês, com os mesmos estilos. O restante do arquivo fica idêntico.
- Mesmo veículo em até 7 dias (ex.: 2 protocolos da mesma placa) não repete no Excel, como na planilha manual.
- Tipo de veículo previsto pelo histórico da planilha (MOTO / VEICULO LEVE / UTILITARIO / PESADO).
- Cidade, estado, rastreador e furto/roubo não existem no PDF: ficam em branco.

Verificado com os arquivos de exemplo:
- 14 ocorrências lidas.
- 6 linhas novas no bloco OUTUBRO (152 a 157).
- 8 fora: 5 já lançadas à mão e 3 do mesmo veículo.
- Só a aba 2026 é alterada.
- Também testados: criação do bloco NOVEMBRO e inserção em mês já fechado.

Arquivos novos: app/PdfText.php, app/WebAssistPdf.php, app/ControlWorkbook.php, app/For024.php, assets/js/for024.js, tests/for024.php.
Alterados: api.php, app/bootstrap.php, assets/js/app.js, assets/css/app.css, index.php, .htaccess (bloqueia a pasta documento), LEIA-ME.md.

Publicação:
1. Extraia na pasta do sistema-FB no cPanel.
2. A pasta storage precisa permitir escrita pelo PHP (cria storage/templates).
3. Pressione Ctrl+F5.
Sem SQL: usa as tabelas existentes. Não envie a pasta "documento" ao servidor.
