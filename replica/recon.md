# Mapa do sistema: Sistema UP de Gestão Comercial (web + app celular)

Escopo: substituir o Mercos na UP Gestão Comercial com 4 módulos: financeiro da representação, pedidos e clientes, metas e painel, visitas e check-in.
Para: uso interno da UP (vendedores, administrativo, gestão). Não é produto para vender.
Data: 2026-10-14
Hospedagem: HostGator / cPanel atual (PHP + MySQL)
Transição: sistema novo construído e testado em paralelo; o Mercos não é alterado até o sistema novo estar completo.

## Regra de origem (importante)

Os termos de uso do Mercos (cláusulas 9.1 b, d, f, g e 5.2) proíbem engenharia reversa e reprodução, total ou parcial, do aplicativo e do dicionário de dados. Por isso:

- Este mapa **não** foi feito a partir das telas logadas do Mercos. Ele parte dos processos da UP e de funções genéricas de representação comercial, que não pertencem a ninguém.
- Telas, nomes de campos, estrutura de banco e textos são desenhados do zero.
- Fontes públicas do Mercos foram usadas só para saber **quais funções o mercado oferece**, não como ele as implementa.
- Os dados da UP (clientes, pedidos, produtos) são exportados pelos relatórios em Excel, como uso normal da conta, e importados no sistema novo.

## Fontes

| # | fonte | URL | uso |
| --- | --- | --- | --- |
| 1 | Site Mercos | https://www.mercos.com/ | lista de funções do mercado |
| 2 | Planos para representantes | https://mercos.com/planos-e-precos/representantes/ | o que a UP paga hoje e o que falta (sem módulo financeiro) |
| 3 | Termos e condições | https://mercos.com/termos-e-condicoes/ | limites legais (ver regra acima) |
| 4 | App Store | https://apps.apple.com/br/app/mercos-app-de-vendas/id485511628 | pedido offline é função esperada pelos usuários |
| 5 | B2B Stack | https://www.b2bstack.com.br/product/mercos/funcionalidades | panorama de funções da categoria |
| 6 | Processos da UP (conversas com o Cristiano) | — | representadas, equipe, territórios, metas, painel da TV, fechamento mensal |

## Loop principal

O vendedor tira o pedido do cliente (no celular, até sem internet), o pedido vai para a representada, a representada fatura, e a UP recebe e confere a comissão, que entra no fluxo de caixa e na meta do vendedor.

Hoje a UP só tem a primeira metade disso no Mercos. A segunda metade (faturamento, comissão, caixa) é o ganho principal do sistema novo.

## Contexto da UP

- Representadas: Buffalo, Mecan, Kärcher, Menegotti, Bognar.
- Cada representada tem tabelas de preço próprias e tabela de Casa do Construtor (CC) com meta própria.
- Equipe: vendedores (Sara, Lilian, Andréia, Rodrigo, Raphael), administrativo (Aryane, Isabelle), gestão (Beatriz, Bruna, Cristiano).
- Território por município: Menegotti em área menor (capital, oeste, ABC); Kärcher, Mecan e Buffalo em área ampla do estado.
- Painel na TV de 58" do comercial e fechamento mensal impresso para reunião.
- Problema real que o sistema novo resolve: perda de metas e realizado da Menegotti (jan–set/2026) ao unificar tabelas no Mercos. No sistema novo, tabela de preço e meta são separadas e históricas: trocar tabela nunca apaga venda nem meta.

## O que a UP usa hoje no Mercos (consulta feita na conta da UP em 14/10/2026)

Levantamento feito como usuário, só pelo que aparece nas telas. Nenhum código do Mercos foi lido.

**Representadas viraram "pastas".** São 27 cadastros, mas só 5 fábricas. Cada cadastro mistura fábrica + canal + situação fiscal + desconto:
- Buffalo (11): Rental, Revenda e CC; contribuinte e não contribuinte; Peças; B2B com 26%, 41% e 49%.
- Menegotti (8): Contribuinte, Não Contribuinte, Betoneiras; B2B com 28%, 36% e 50%.
- Mecan (4): Locador e Consumidor Final; contribuinte e não contribuinte.
- Kärcher (3): Contribuinte, Não Contribuinte e CC.
- Bognar (1).

No sistema novo, isso vira **Representada (5) → Canal/Tabela** (tipo de cliente, contribuinte sim/não, desconto da tabela). Relatórios por fábrica saem somados direto, e trocar tabela não apaga histórico.

**Comissão das representadas:** 5% em quase tudo e 7% na Bognar. O Mercos permite comissão por produto, sobre IPI e ICMS-ST, parcelada ou em parcela única, e marca comissão "baixada/não baixada".

**Faturamento:** o Mercos registra data, número da NF, pedido, representada, cliente, vendedor, valor do pedido e valor faturado.

**Pedidos:**
- Situação: em orçamento, concluído, faturado, e-commerce em andamento.
- Tipos: Venda, Bonificação e Troca. Bonificação e Troca não contam para meta, comissão nem positivação.
- Status de acompanhamento próprios da UP: 1) Confirmado fábrica → 2) Análise financeira (liberado/bloqueado) → 3) Separação → 4) Despachado → 5) Recebido cliente, mais "Comissão estornada". Ou seja, a UP já acompanha o pós-venda e o estorno de comissão "na mão".
- Campos extras: O.C, Pedido Kärcher (nº na fábrica), Comprador, Telefone e E-mail (obrigatórios), Aceita parcial (obrigatório), Remessa.
- Frete: CIF, FOB, À retirar. Condição de pagamento em dias (ex.: 28/56/84/112/140/168D).

**Clientes:**
- Campos extras: Aniversário, Ficha cadastral (anexo), Contrato social (anexo), Sugestão de limite de crédito.
- Segmentos (15): Assistente Técnico, Cliente TUL, Construtora, Consumidor final, E-commerce, Indústria, Instalador, Licitação, Locador, Material Construção, Revenda, Revenda Agrícola, Revenda Loja de Tintas, Revenda Material de Construção, Revenda Máquinas e Equipamentos.
- Tags: usadas para carteira (ADM Leonardo, Carteira UP para repassar, CC Cristiano, Vendedor 1/2).
- "Redes" (44): misturam redes de verdade (Casa do Construtor, Disensa, Nikkeybraz, Copafer...) com regiões (Zona Leste, Vale do Paraíba, Litoral Sul...). No sistema novo: **Rede** e **Região** separadas, com a região calculada pelo município.
- Qualidade de dados: contribuinte marcado no nome ("(contr)", "(CONTRIBUINTE)"), e-mails e telefones de preenchimento ("NÃO TEM", "naotem@com.br"). Isso será limpo na importação.

**Produtos:**
- Por representada: código, nome, IPI, unidade, comissão por produto, preço de tabela + colunas de tabelas extras (ex.: promocional), categorias, promoções, destaques e políticas comerciais.
- Informação escrita no nome do produto: substituição ("SUBSTITUIU O COD. 3160", "USAR COD. 60733") e falta ("FALT. 2ª QUINZENA OUTUBRO"). No sistema novo viram campos: **substituído por** e **disponibilidade/previsão**, que o vendedor vê no pedido.

**Funil de vendas:** fases Follow-up de inatividade → Levantado → Negociação → Fechado ganho/perdido. Pouco usado (5 cartões, parados há 4 a 6 meses).

**Agenda:** tarefas, atividades e roteiros. Vazia com os filtros atuais.

**Relatórios que existem hoje:** resumo de vendas, vendas detalhadas, ranking/meta, gerencial para representada, saldo flex, funil, pedidos faturados, faturamento, clientes, situação de carteira (geral e por vendedor), positivação, curva ABC, visitas com check-in, tarefas, comissões, comissões por pedido, produtos mais vendidos, positivação de produtos por cliente, produtos por pedido, estoque.

**Usuários:** 15 no Mercos (incluindo Junior Brezan, Leonardo Ferreira, Sergio Bueno e Daniel, além da equipe conhecida). Os nomes com "|" na frente são da equipe interna.

**Números de outubro/2026 (até 14/10):** R$ 2,28 mi vendidos, meta de R$ 7,40 mi (30,7%).

## Perfis de acesso

| perfil | vê | faz |
| --- | --- | --- |
| Vendedor | os próprios clientes e pedidos, a própria meta | pedido, cliente, visita, check-in |
| Administrativo | tudo de pedidos e clientes, financeiro operacional | lança faturamento, confere comissão, contas a pagar e receber |
| Gestão | tudo | metas, relatórios, fluxo de caixa, usuários, configurações |
| TV (painel) | só o painel, sem login manual | nada |

## Telas

| ID | tela | como chega | objetivo | componentes principais | estados |
| --- | --- | --- | --- | --- | --- |
| S01 | Login | / | entrar | formulário, "lembrar aparelho" | erro, bloqueado |
| S02 | Início do vendedor | após login (celular) | meta do dia, visitas de hoje, atalhos | cartões de meta, lista de visitas, botão "novo pedido" | sem visitas, offline |
| S03 | Início da gestão | após login (computador) | visão geral do mês | indicadores, gráficos por representada e vendedor | mês sem dados |
| S04 | Clientes | menu | buscar e filtrar carteira | busca, filtros (vendedor, cidade, representada, situação), lista | vazio, offline (cache) |
| S05 | Ficha do cliente | S04 | tudo sobre o cliente | dados, contatos, histórico de pedidos, visitas, mix por representada, observações | novo, inativo, bloqueado |
| S06 | Cadastro/edição de cliente | S04, S05 | criar/editar | formulário, busca de CNPJ, cidade (valida território) | erro de validação, fora do território |
| S07 | Catálogo | menu | consultar produtos por representada | filtro de representada e tabela, busca por código/descrição, foto, preço | sem foto, item inativo |
| S08 | Novo pedido: cliente e representada | S02, S05 | iniciar pedido | seleção de cliente, representada, tabela (normal ou CC), condição de pagamento | cliente bloqueado |
| S09 | Novo pedido: itens | S08 | montar o carrinho | busca, quantidade, desconto, subtotal, total | item sem estoque/inativo, desconto acima do limite |
| S10 | Novo pedido: revisão e envio | S09 | conferir e enviar | resumo, observações, enviar/salvar rascunho, PDF/WhatsApp | offline (fica na fila), erro de envio |
| S11 | Pedidos | menu | acompanhar pedidos | lista com status, filtros | vazio |
| S12 | Detalhe do pedido | S11 | ver e mudar status | itens, status (rascunho, enviado, aceito, faturado parcial, faturado, cancelado), notas fiscais vinculadas | — |
| S13 | Importar faturamento | menu financeiro | registrar o que a representada faturou | upload de planilha/relatório da representada ou lançamento manual, vínculo com pedidos | divergências |
| S14 | Comissões a receber | menu financeiro | o que cada representada deve à UP | por representada e mês: previsto, faturado, recebido, diferença | atrasado, divergente |
| S15 | Conferência de comissão | S14 | bater extrato da representada com o previsto | comparação linha a linha, marcar divergência, aceitar | tudo bate, divergências |
| S16 | Contas a receber | menu financeiro | todas as entradas | lista, baixa, anexos | vencido |
| S17 | Contas a pagar | menu financeiro | despesas da UP | lista, categorias, recorrência, baixa, anexos | vencido |
| S18 | Comissão dos vendedores | menu financeiro | quanto a UP repassa a cada vendedor | regra por vendedor e representada, cálculo do mês, fechamento | mês aberto, fechado |
| S19 | Fluxo de caixa | menu financeiro | saldo realizado e projetado | gráfico por semana/mês, entradas x saídas, saldo, filtro por representada/categoria | projeção negativa |
| S20 | Metas | menu gestão | definir metas | por vendedor x representada x tabela (incluindo CC) x mês, dias úteis com feriados | sem meta |
| S21 | Acompanhamento de metas | menu | realizado x meta | % do mês e % do dia, ranking | — |
| S22 | Agenda de visitas | menu | planejar visitas | calendário/lista, rota do dia no mapa | sem visitas |
| S23 | Check-in / check-out | S02, S22 | registrar visita | GPS, horário, resultado da visita, foto opcional | sem GPS, offline |
| S24 | Relatórios | menu | relatórios e exportação | vendas por período/representada/vendedor/cliente, curva ABC, positivação, carteira parada, comparativo ano a ano | — |
| S25 | Painel da TV | URL própria | painel rotativo para a TV | telas que alternam, atualização automática | sem conexão (mostra último dado) |
| S26 | Fechamento mensal | menu gestão | relatório para imprimir | resumo comercial e financeiro do mês, PDF | — |
| S27 | Importação de dados | menu admin | trazer dados do Mercos e das representadas | upload Excel/CSV, mapeamento de colunas, prévia, relatório de erros | erros de importação |
| S28 | Configurações | menu admin | usuários, representadas, tabelas, condições de pagamento, feriados, categorias financeiras | formulários | — |
| S29 | Sincronização (celular) | ícone no topo | ver o que está pendente de envio | fila de pedidos/visitas, último sync, forçar sync | conflito |

## Fluxos

```
F01 Vendedor tira pedido em campo, sem internet
    S02 -> S08 -> S09 -> S10 (fica na fila) -> S29 (envia quando volta a internet)
    meta de cliques no caminho feliz: até 8 para um pedido de 3 itens
    edge: cliente novo criado offline, preço mudou enquanto offline, dois aparelhos editando o mesmo cliente,
          desconto acima do permitido, sessão expirada durante o offline

F02 Pedido segue para a representada
    S12 -> envio por e-mail/PDF no formato da representada -> status "enviado"
    edge: representada exige formato próprio, pedido parcial

F03 Lançar faturamento da representada
    S13 (importa relatório da representada) -> vincula a pedidos -> S12 atualiza status
    edge: nota sem pedido, pedido faturado em várias notas, devolução/cancelamento

F04 Conferir e receber comissão
    S14 -> S15 (compara previsto x extrato) -> aceita -> S16 (baixa do recebimento) -> S19
    edge: comissão menor que a esperada, pagamento atrasado, estorno por devolução

F05 Fechar comissão dos vendedores
    S18 (calcula pelo faturado/recebido do mês) -> fecha -> S17 (gera contas a pagar) -> S19

F06 Lançar e pagar despesas
    S17 -> nova conta (ou recorrente) -> baixa -> S19

F07 Ver fluxo de caixa e projeção
    S19 com comissões previstas (S14) + contas a pagar (S17)

F08 Definir metas do mês
    S20 -> por vendedor x representada x tabela -> S21 / S25
    edge: troca de tabela da representada no meio do ano (não pode apagar histórico)

F09 Planejar e registrar visita
    S22 -> S23 check-in -> resultado -> (opcional) S08 pedido -> S23 check-out

F10 Painel na TV
    S25 abre sozinho no PC da TV, sem login manual, atualiza a cada N minutos

F11 Fechamento do mês
    S26 -> PDF para a reunião

F12 Migrar dados do Mercos
    exportações em Excel do Mercos -> S27 -> prévia -> importação -> conferência de totais por mês/representada
```

## Componentes

| componente | variantes | estados | usado em |
| --- | --- | --- | --- |
| Botão | principal, secundário, perigo, ícone | normal, desabilitado, carregando | todas |
| Campo de texto / número / moeda | — | normal, erro, desabilitado | formulários |
| Busca com sugestões | cliente, produto, cidade | vazio, carregando, sem resultado | S04, S07, S08, S09 |
| Tabela de dados | com filtros, ordenação, exportar | vazio, carregando | S11, S14, S16, S17, S24 |
| Cartão de indicador | valor, %, comparação | positivo, negativo | S02, S03, S21, S25 |
| Barra de progresso de meta | dia, mês | abaixo, no ritmo, acima | S02, S21, S25 |
| Gráfico | barras, linhas, pizza | vazio | S03, S19, S24, S25 |
| Selo de status | pedido, conta, comissão | — | listas |
| Modal / folha inferior (celular) | confirmação, formulário | — | várias |
| Aviso de offline / sincronização | — | online, offline, sincronizando, conflito | todas no celular |
| Upload de arquivo | Excel/CSV, PDF, foto | enviando, erro | S13, S17, S23, S27 |
| Calendário | mês, semana, dia | — | S20, S22 |
| Mapa | rota, pontos | sem GPS | S22, S23 |

## Modelo de dados (desenhado para a UP)

```
Representada   id, nome, cnpj, ativa, comissao_padrao_pct, dia_pagamento_comissao, contato_financeiro, area_atendimento
               confiança: alta (processo da UP)

Canal          id, representada_id, nome (ex.: Rental, Revenda, Casa do Construtor, Locador, Consumidor final, B2B Peças),
               contribuinte (sim | nao | ambos), desconto_base_pct, comissao_pct (sobrescreve a da representada)
               confiança: alta (as 27 "representadas" do Mercos viram 5 representadas x canais)

TabelaPreco    id, representada_id, canal_id, nome, tipo (normal | casa_construtor | promocional | outra), vigencia_inicio, vigencia_fim, ativa
               regra: desativar, nunca apagar (evita o problema da Menegotti)

Produto        id, representada_id, categoria_id, codigo, descricao, unidade, multiplo_venda, ipi_pct, comissao_pct (opcional),
               foto, ativo, substituido_por_produto_id, disponibilidade (normal | em_falta | sob_encomenda), previsao_disponivel
PrecoProduto   tabela_id, produto_id, preco, desconto_max_pct

Cliente        id, cnpj_cpf, razao_social, fantasia, ie, contribuinte, endereco, municipio, uf, lat, lng, vendedor_id,
               segmento_id, rede_id, regiao (pelo município), tags, aniversario, limite_credito_sugerido,
               situacao (ativo | inativo | bloqueado), motivo_bloqueio, observacoes
ClienteAnexo   cliente_id, tipo (ficha_cadastral | contrato_social | outro), arquivo
Contato        id, cliente_id, nome, telefone, whatsapp, email, cargo
ClienteRepresentada  cliente_id, representada_id, codigo_no_fornecedor, condicao_pagamento_id

Municipio      ibge, nome, uf, regiao_up; AreaAtendimento representada_id x municipio (valida território)

Usuario        id, nome, email, perfil (vendedor | administrativo | gestao | tv), ativo
CondicaoPagamento  id, representada_id, descricao, parcelas, prazo_dias, valor_minimo

Pedido         id, uuid_local (para offline), cliente_id, representada_id, canal_id, tabela_id, vendedor_id, data, condicao_pagamento_id,
               tipo (venda | bonificacao | troca; só venda conta para meta/comissão/positivação),
               situacao (orcamento | enviado | faturado_parcial | faturado | cancelado),
               etapa (confirmado_fabrica | analise_financeira_liberado | analise_financeira_bloqueado | separacao | despachado | recebido_cliente),
               frete (CIF | FOB | retirar), ordem_compra, numero_pedido_fabrica, comprador_nome, comprador_telefone, comprador_email,
               aceita_parcial, total_bruto, total_desconto, total_ipi, total_liquido, observacoes, origem (app | web | importado | mercos)
               (estorno de comissão deixa de ser status do pedido e vira lançamento no financeiro)
PedidoItem     pedido_id, produto_id, quantidade, preco_tabela, desconto_pct, preco_final, total

NotaFiscal     id, representada_id, pedido_id (opcional), numero, data_emissao, valor, cliente_id, status (normal | cancelada | devolvida)
ComissaoPrevista  nota_id, representada_id, pct, valor, competencia, vencimento
ExtratoComissao   id, representada_id, competencia, arquivo, valor_total
ExtratoItem       extrato_id, nota_numero, valor, conciliado_com (comissao_prevista_id), divergencia

RegraComissaoVendedor  vendedor_id, representada_id, tabela_tipo, pct, base (faturado | recebido)
FechamentoComissao     vendedor_id, competencia, valor, status (aberto | fechado), conta_pagar_id

ContaFinanceira  id, tipo (receber | pagar), categoria_id, descricao, valor, vencimento, pagamento_data, valor_pago,
                 representada_id (opcional), origem (comissao | despesa | repasse | outro), recorrencia_id, anexo
CategoriaFinanceira  id, nome, tipo, grupo (para o fluxo de caixa)
ContaBancaria        id, nome, saldo_inicial

Meta           id, competencia (mês), vendedor_id, representada_id, tabela_tipo, valor
Feriado        data, descricao, abrangencia (nacional | estadual | municipal)  -> dias úteis corretos

Visita         id, cliente_id, vendedor_id, data_planejada, checkin_em, checkin_lat, checkin_lng, checkout_em,
               resultado, observacao, pedido_id (opcional), uuid_local

Auditoria      id, usuario_id, entidade, entidade_id, acao, antes, depois, em  (quem mudou o quê)
```

Relações: Representada 1-n TabelaPreco 1-n PrecoProduto; Cliente 1-n Pedido 1-n PedidoItem; Pedido 1-n NotaFiscal 1-1 ComissaoPrevista; Vendedor 1-n Meta; Cliente 1-n Visita.

Pontos a confirmar com a UP estão marcados em "Perguntas" abaixo.

## Matriz de funcionalidades

Ver `features.csv`.

## Fora do escopo

- Integração automática com os sistemas das representadas: cada uma tem o seu. Começamos com importação de planilha/relatório e envio de pedido por e-mail/PDF.
- E-commerce B2B para clientes finais: fica para uma segunda fase.
- Meios de pagamento próprios (Pix, boleto, cartão): a UP recebe comissão das representadas, não vende direto.
- Emissão de nota fiscal: fica com a contabilidade ou com o sistema de NFS-e da prefeitura. O sistema registra as notas.
- Recursos de IA do Mercos (pedido por áudio, foto): fora da primeira versão.
- Qualquer coisa copiada do Mercos: telas, textos, estrutura de banco.

## Perguntas para a UP (definem o módulo financeiro)

1. Como cada representada paga a comissão: sobre o faturado ou sobre o recebido do cliente? Em que dia? Manda extrato/relatório (em qual formato)?
2. No Mercos está 5% para todas e 7% na Bognar. É isso mesmo, ou muda por canal (CC, peças, B2B) ou produto?
3. Como os vendedores recebem: % sobre o quê, por representada? Salário fixo + comissão?
4. Quem lança as despesas hoje e onde (planilha, sistema da contabilidade, banco)?
5. Quantas contas bancárias a empresa usa?
6. Como o pedido chega à representada hoje: o Mercos envia, ou vocês digitam no portal da representada?
7. Quais relatórios do Mercos vocês mais usam e quais faltam?

## Tamanho

Telas 29, fluxos 12, entidades 25.

Partes difíceis:
1. **Pedido offline no celular** com fila de envio e resolução de conflitos, rodando como app instalável (PWA) sobre PHP/MySQL na HostGator.
2. **Conferência de comissões**: cada representada manda o extrato num formato diferente.
3. **Migração dos dados do Mercos** a partir das exportações em Excel, com conferência de totais (e sem o histórico da Menegotti de jan–set/2026, que já foi perdido).

Tamanho: **L** (um trimestre de trabalho em etapas). Dá para usar partes antes: o módulo financeiro pode entrar em uso enquanto o Mercos continua tirando os pedidos.

Próximo passo: arquitetura (stack na HostGator, banco MySQL, API, estratégia offline e ordem de construção).
