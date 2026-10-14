# Arquitetura: Sistema UP de Gestão Comercial

Sistema próprio da UP, desenhado a partir dos processos da empresa, para substituir o Mercos. Roda em paralelo ao Mercos até estar completo; o Mercos não é alterado.

## Stack

| camada | escolha | por quê |
| --- | --- | --- |
| hospedagem | HostGator / cPanel atual, subdomínio `sistema.upgestaocomercial.com.br` com HTTPS (AutoSSL) | já pago; sem custo novo |
| servidor | PHP 8.1+ com roteador próprio pequeno + PDO | roda em hospedagem compartilhada sem configurar servidor; sem framework pesado |
| banco | MySQL da HostGator (compatível com 5.7+ e MariaDB) | já incluso no plano |
| bibliotecas PHP | PhpSpreadsheet (ler/gerar Excel), Dompdf (PDF de pedido e fechamento), PHPMailer (e-mail via SMTP da HostGator) | padrão de mercado, sem serviço externo |
| tela (web e celular) | Vue 3 + Vite, gerado como arquivos estáticos | um código só para computador e celular |
| app no celular | PWA instalável (Android e iPhone) com service worker | sem loja de apps; instala pelo navegador |
| offline | IndexedDB (Dexie) no aparelho + fila de envio | pedido, cliente e visita sem internet |
| login | sessão própria (token em cookie http-only), senhas com `password_hash` | sem serviço externo; aparelho da TV tem token fixo sem relogin |
| rotinas automáticas | Cron do cPanel | recorrências, alertas de vencimento, backup |
| arquivos | pasta `storage/` fora do `public_html` | anexos, extratos, comprovantes não ficam públicos |
| código | repositório GitHub privado novo `upgestaocomercial/sistema-up`, publicado pelo "Git Version Control" do cPanel | histórico e volta de versão; diferente do painel atual, que só existe no cPanel |

A confirmar no cPanel antes de publicar: versão do PHP (precisa 8.1+) e do MySQL, extensões `pdo_mysql`, `mbstring`, `zip`, `gd`, `intl`.

## Banco de dados

`database/schema.sql` (42 tabelas) e `database/seed.sql` (cadastros-base da UP). Testado num MariaDB 10.11 local.

Principais decisões:

- **Representada (5) → Canal (27) → Tabela de preço.** Os 27 cadastros de "representada" do Mercos viram canais das 5 fábricas. `canais.mercos_nome` guarda o nome exato do Mercos para a importação.
- **Nada com histórico é apagado.** Tabelas, canais, produtos e clientes são desativados (`ativo = 0`). Venda e meta continuam ligadas ao canal antigo.
- **Comissão sobre o recebido, por parcela.** Cada NF gera uma linha em `comissoes` por parcela (pela condição de pagamento): vencimento do cliente + dias de repasse da fábrica = previsão de entrada no caixa.
- **% de comissão:** representada (5%, Bognar 7%) → pode ser sobrescrito por canal → por produto.
- **% do vendedor preenchido à mão** em `regras_comissao_vendedor`, por vendedor, opcionalmente por representada, com vigência (mudança de % não altera meses fechados).
- **Financeiro isolado:** só usuários com `acesso_financeiro = 1` (hoje: Leonardo) veem contas, caixa, comissões a receber e fechamento de vendedores. A checagem é feita no servidor em toda rota `/api/financeiro/*`, não só escondendo o menu.
- **Tipos de pedido:** só `venda` conta para meta, comissão e positivação.
- **Dias úteis** descontam sábado, domingo e a tabela `feriados`.
- **Offline:** pedidos, clientes e visitas criados no celular têm `uuid_local`; o servidor registra cada envio em `sync_recebidos`, então reenviar não duplica.
- **Dinheiro** em `DECIMAL(14,2)`; datas/horas em UTC, exibidas em America/Sao_Paulo.
- **Acesso por perfil** checado na camada de dados: vendedor só lê/edita os próprios clientes, pedidos e visitas; administrativo e gestão veem tudo; TV só lê o painel.

## API (servidor PHP, JSON)

| método e rota | faz | quem | fluxo |
| --- | --- | --- | --- |
| POST /api/auth/login | entra, devolve sessão | todos | — |
| POST /api/auth/logout | sai | todos | — |
| GET /api/auth/eu | usuário atual e permissões | todos | — |
| POST /api/auth/tv | troca código do aparelho por sessão longa | TV | F10 |
| GET /api/sync/baixar?desde= | clientes, produtos, preços, condições alterados desde a última sincronização | vendedor+ | F01 |
| POST /api/sync/enviar | recebe fila do celular (pedidos, clientes, visitas) com uuid_local | vendedor+ | F01, F09 |
| GET/POST/PUT /api/clientes | lista, cria, edita | vendedor (os seus), adm, gestão | — |
| GET /api/clientes/{id} | ficha com histórico, mix e visitas | idem | — |
| POST /api/clientes/{id}/anexos | ficha cadastral, contrato social | idem | — |
| GET /api/cnpj/{cnpj} | busca dados públicos do CNPJ (BrasilAPI) | vendedor+ | — |
| GET /api/produtos?representada&tabela&q | catálogo com preço | vendedor+ | — |
| GET/POST/PUT /api/pedidos | lista, cria, edita | vendedor (os seus), adm, gestão | F01 |
| POST /api/pedidos/{id}/enviar | gera PDF e envia à fábrica/cliente | vendedor+ | F02 |
| PUT /api/pedidos/{id}/etapa | muda etapa (confirmado fábrica → recebido cliente) | adm, gestão | F02 |
| GET /api/pedidos/{id}/pdf | PDF do pedido | vendedor+ | F02 |
| POST /api/faturamento/importar | planilha de NFs da fábrica (prévia + confirmar) | financeiro, adm | F03 |
| POST /api/faturamento | lançamento manual de NF | financeiro, adm | F03 |
| GET /api/financeiro/comissoes | a receber por representada e mês | financeiro | F04 |
| POST /api/financeiro/extratos | importa extrato de comissão e concilia | financeiro | F04 |
| PUT /api/financeiro/comissoes/{id} | baixa, divergência, estorno | financeiro | F04 |
| GET/POST/PUT /api/financeiro/lancamentos | contas a pagar e a receber | financeiro | F06 |
| POST /api/financeiro/lancamentos/{id}/baixa | registra pagamento | financeiro | F06 |
| GET/POST /api/financeiro/recorrencias | despesas fixas | financeiro | F06 |
| GET /api/financeiro/fluxo-caixa?de&ate | realizado + projetado | financeiro | F07 |
| GET/POST /api/financeiro/vendedores/regras | % de cada vendedor | financeiro | F05 |
| POST /api/financeiro/vendedores/fechar?competencia | calcula e fecha comissão do mês, gera contas a pagar | financeiro | F05 |
| GET/PUT /api/metas?competencia | metas por vendedor x representada x canal | gestão | F08 |
| GET /api/metas/acompanhamento | realizado x meta, % do dia e do mês | todos (vendedor vê a sua) | F08 |
| GET/POST/PUT /api/visitas | agenda, check-in, check-out | vendedor+ | F09 |
| GET /api/painel | dados do painel da TV | TV, gestão | F10 |
| GET /api/relatorios/{nome} | vendas, ABC, positivação, carteira, comparativo; `?formato=xlsx` | adm, gestão (vendedor: só os seus) | — |
| GET /api/fechamento/{competencia}.pdf | fechamento mensal | gestão | F11 |
| POST /api/importacao/{tipo} | importa Excel do Mercos (prévia → confirmar → desfazer) | gestão | F12 |
| CRUD /api/config/* | usuários, representadas, canais, tabelas, condições, feriados, categorias | gestão | — |

Integrações externas: só BrasilAPI (CNPJ e CEP, pública e gratuita). Nenhuma chamada ao Mercos.

Rotinas (Cron do cPanel):
- **diária 06:00** — gera lançamentos das recorrências do mês; marca contas e comissões vencidas; e-mail de resumo para o financeiro.
- **diária 02:00** — backup do banco (`mysqldump` compactado) em `storage/backups`, mantendo 30 dias.
- **a cada 5 min (horário comercial)** — recalcula o cache do painel da TV.

## As partes difíceis

- **Offline:** o celular baixa só o que mudou (`updated_at`), guarda no aparelho e envia a fila quando a internet volta. Conflito simples: o servidor é a fonte da verdade para cadastros; o pedido feito offline usa o preço que o aparelho tinha e avisa se o preço mudou.
- **iPhone:** PWA funciona no Safari e pode ser instalado na tela inicial; o iOS pode limpar dados de apps pouco usados. Por isso nada fica só no aparelho: tudo que é criado vai para a fila e é enviado assim que possível, e a tela mostra o que está pendente.
- **Repetição:** reenvio pelo celular, duplo clique e importação repetida não duplicam (`uuid_local`, chaves únicas em NF por representada, recorrência por vencimento).
- **Hospedagem compartilhada:** sem processos rodando direto; tudo pesado (importação grande, PDF) roda na requisição com limite de tempo ou em lotes. Planilhas grandes são importadas em partes.
- **Fuso:** gravado em UTC; dia útil e "hoje" calculados em America/Sao_Paulo.
- **Segurança:** HTTPS obrigatório, senhas com hash, limite de tentativas de login, CSRF nas rotas que alteram dados, arquivos fora do `public_html`, financeiro checado no servidor.
- **Migração:** histórico vem dos relatórios em Excel do Mercos. O histórico da Menegotti de jan–set/2026 já foi perdido no Mercos e não volta pela exportação; a planilha de acompanhamento própria da UP pode servir de fonte.

## Ordem de construção

1. **Base (fase 1):** estrutura do projeto, banco, login com perfis e permissão financeira, layout web e celular, cadastros de representadas/canais/usuários. Telas S01, S28.
2. **Fatia completa (fase 2):** cliente → catálogo → pedido no celular (online) → PDF/envio → NF lançada → comissão prevista por parcela. Telas S04–S12, S13, S14. Prova o sistema de ponta a ponta.
3. **Migração (fase 3):** importadores de clientes, produtos/preços, pedidos, faturamento e metas a partir dos Excel do Mercos, com conferência de totais por mês e representada. Tela S27.
4. **Financeiro (fase 4):** extrato e conciliação, contas a pagar/receber, recorrências, comissão dos vendedores, fluxo de caixa. Telas S15–S19.
5. **Metas e painel (fase 5):** metas, acompanhamento, painel da TV, fechamento mensal, relatórios. Telas S20, S21, S24–S26.
6. **Campo e offline (fase 6):** app instalável, pedido e cliente offline, agenda, check-in com GPS, rota do dia. Telas S02, S22, S23, S29.
7. **Testes e virada (fase 7):** testes de todos os fluxos, uso em paralelo com o Mercos por um período, conferência de números, e só então a virada.
