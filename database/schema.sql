-- Sistema UP de Gestão Comercial — schema MySQL (compatível com MySQL 5.7+ e MariaDB 10.3+)
-- Convenções:
--   * InnoDB, utf8mb4
--   * id BIGINT auto incremento; registros criados no celular offline têm uuid_local (idempotência no sync)
--   * datas/horas em UTC (DATETIME); a aplicação converte para America/Sao_Paulo
--   * dinheiro em DECIMAL(14,2); percentuais em DECIMAL(6,3)
--   * nada é apagado de verdade em cadastros com histórico: usa-se `ativo` = 0

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- ACESSO
-- =========================================================
CREATE TABLE usuarios (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome             VARCHAR(120) NOT NULL,
  email            VARCHAR(190) NOT NULL,
  telefone         VARCHAR(30) NULL,
  senha_hash       VARCHAR(255) NOT NULL,
  perfil           ENUM('vendedor','administrativo','gestao','tv') NOT NULL DEFAULT 'vendedor',
  acesso_financeiro TINYINT(1) NOT NULL DEFAULT 0,   -- módulo financeiro: só quem tiver 1 (hoje: Leonardo)
  ativo            TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login_em  DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessoes (
  id            CHAR(64) NOT NULL,                 -- hash do token
  usuario_id    BIGINT UNSIGNED NOT NULL,
  dispositivo   VARCHAR(190) NULL,
  tipo          ENUM('navegador','aparelho_tv','app') NOT NULL DEFAULT 'navegador',
  expira_em     DATETIME NOT NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visto_em      DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_sessoes_usuario (usuario_id),
  CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_tentativas (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  sucesso    TINYINT(1) NOT NULL DEFAULT 0,
  criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_lt_email (email, criado_em),
  KEY ix_lt_ip (ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auditoria (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id   BIGINT UNSIGNED NULL,
  entidade     VARCHAR(60) NOT NULL,
  entidade_id  BIGINT UNSIGNED NOT NULL,
  acao         ENUM('criar','alterar','desativar','excluir','importar','login') NOT NULL,
  antes        TEXT NULL,   -- JSON
  depois       TEXT NULL,   -- JSON
  criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_aud_entidade (entidade, entidade_id),
  KEY ix_aud_usuario (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- REPRESENTADAS, CANAIS, TABELAS, PRODUTOS
-- =========================================================
CREATE TABLE representadas (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome                  VARCHAR(120) NOT NULL,          -- Buffalo, Mecan, Kärcher, Menegotti, Bognar
  razao_social          VARCHAR(190) NULL,
  cnpj                  VARCHAR(18) NULL,
  comissao_pct          DECIMAL(6,3) NOT NULL DEFAULT 5.000,
  comissao_base         ENUM('recebido','faturado') NOT NULL DEFAULT 'recebido',
  comissao_sobre_ipi    TINYINT(1) NOT NULL DEFAULT 0,
  comissao_sobre_st     TINYINT(1) NOT NULL DEFAULT 0,
  dias_repasse_comissao SMALLINT NOT NULL DEFAULT 30,   -- após o cliente pagar, em quantos dias a fábrica repassa (projeção do caixa)
  email_pedidos         VARCHAR(190) NULL,
  contato_financeiro    VARCHAR(190) NULL,
  cor                   CHAR(7) NULL,                   -- cor no painel
  ativo                 TINYINT(1) NOT NULL DEFAULT 1,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_representadas_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE representada_contatos (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id BIGINT UNSIGNED NOT NULL,
  nome            VARCHAR(120) NOT NULL,
  cargo           VARCHAR(80) NULL,
  telefone        VARCHAR(30) NULL,
  email           VARCHAR(190) NULL,
  PRIMARY KEY (id),
  KEY ix_repcont_rep (representada_id),
  CONSTRAINT fk_repcont_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canal = o que no Mercos virou "representada" separada (Rental, Revenda, CC, Locador, B2B Peças...)
CREATE TABLE canais (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id   BIGINT UNSIGNED NOT NULL,
  nome              VARCHAR(120) NOT NULL,
  contribuinte      ENUM('sim','nao','ambos') NOT NULL DEFAULT 'ambos',
  casa_construtor   TINYINT(1) NOT NULL DEFAULT 0,
  desconto_base_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
  comissao_pct      DECIMAL(6,3) NULL,                  -- NULL = usa a da representada
  ativo             TINYINT(1) NOT NULL DEFAULT 1,
  mercos_nome       VARCHAR(190) NULL,                  -- nome da "representada" no Mercos (para a importação)
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_canais_rep (representada_id, ativo),
  UNIQUE KEY uq_canais_mercos (mercos_nome),
  CONSTRAINT fk_canais_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tabelas_preco (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id BIGINT UNSIGNED NOT NULL,
  canal_id        BIGINT UNSIGNED NULL,
  nome            VARCHAR(120) NOT NULL,
  tipo            ENUM('normal','casa_construtor','promocional','outra') NOT NULL DEFAULT 'normal',
  vigencia_inicio DATE NULL,
  vigencia_fim    DATE NULL,
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_tab_rep (representada_id, ativo),
  KEY ix_tab_canal (canal_id),
  CONSTRAINT fk_tab_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tab_canal FOREIGN KEY (canal_id) REFERENCES canais(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_produto (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id BIGINT UNSIGNED NOT NULL,
  nome            VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat (representada_id, nome),
  CONSTRAINT fk_cat_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produtos (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id       BIGINT UNSIGNED NOT NULL,
  categoria_id          BIGINT UNSIGNED NULL,
  codigo                VARCHAR(60) NOT NULL,
  descricao             VARCHAR(255) NOT NULL,
  unidade               VARCHAR(10) NOT NULL DEFAULT 'UN',
  multiplo_venda        DECIMAL(12,3) NOT NULL DEFAULT 1,
  ipi_pct               DECIMAL(6,3) NOT NULL DEFAULT 0,
  comissao_pct          DECIMAL(6,3) NULL,               -- NULL = usa canal/representada
  foto                  VARCHAR(255) NULL,
  disponibilidade       ENUM('normal','em_falta','sob_encomenda') NOT NULL DEFAULT 'normal',
  previsao_disponivel   VARCHAR(80) NULL,                -- ex.: "2ª quinzena de outubro"
  substituido_por_id    BIGINT UNSIGNED NULL,
  ativo                 TINYINT(1) NOT NULL DEFAULT 1,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prod_codigo (representada_id, codigo),
  KEY ix_prod_cat (categoria_id),
  KEY ix_prod_desc (descricao),
  KEY ix_prod_upd (updated_at),
  CONSTRAINT fk_prod_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_prod_cat FOREIGN KEY (categoria_id) REFERENCES categorias_produto(id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_subst FOREIGN KEY (substituido_por_id) REFERENCES produtos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE precos (
  tabela_id        BIGINT UNSIGNED NOT NULL,
  produto_id       BIGINT UNSIGNED NOT NULL,
  preco            DECIMAL(14,2) NOT NULL,
  desconto_max_pct DECIMAL(6,3) NULL,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tabela_id, produto_id),
  KEY ix_precos_prod (produto_id),
  KEY ix_precos_upd (updated_at),
  CONSTRAINT fk_precos_tab FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE CASCADE,
  CONSTRAINT fk_precos_prod FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE condicoes_pagamento (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id BIGINT UNSIGNED NULL,               -- NULL = vale para todas
  descricao       VARCHAR(120) NOT NULL,              -- ex.: 28/56/84/112D
  dias            VARCHAR(120) NOT NULL,              -- "28,56,84,112" (vazio = à vista / a combinar)
  valor_minimo    DECIMAL(14,2) NULL,
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_cond_rep (representada_id),
  CONSTRAINT fk_cond_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CLIENTES E TERRITÓRIO
-- =========================================================
CREATE TABLE municipios (
  ibge       INT UNSIGNED NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  uf         CHAR(2) NOT NULL,
  regiao_up  VARCHAR(60) NULL,       -- Grande SP, Vale do Paraíba, Litoral Sul, ...
  PRIMARY KEY (ibge),
  KEY ix_mun_nome (uf, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE area_atendimento (
  representada_id BIGINT UNSIGNED NOT NULL,
  municipio_ibge  INT UNSIGNED NOT NULL,
  PRIMARY KEY (representada_id, municipio_ibge),
  CONSTRAINT fk_area_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE CASCADE,
  CONSTRAINT fk_area_mun FOREIGN KEY (municipio_ibge) REFERENCES municipios(ibge) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE segmentos (
  id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome  VARCHAR(120) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id), UNIQUE KEY uq_seg (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redes (
  id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome  VARCHAR(120) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id), UNIQUE KEY uq_rede (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tags (
  id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome  VARCHAR(120) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_tag (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE motivos_bloqueio (
  id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome  VARCHAR(120) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_motbloq (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clientes (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid_local             CHAR(36) NULL,
  tipo_pessoa            ENUM('PJ','PF') NOT NULL DEFAULT 'PJ',
  cnpj_cpf               VARCHAR(18) NULL,
  razao_social           VARCHAR(190) NOT NULL,
  fantasia               VARCHAR(190) NULL,
  inscricao_estadual     VARCHAR(30) NULL,
  contribuinte           TINYINT(1) NULL,           -- NULL = não informado
  cep                    VARCHAR(9) NULL,
  endereco               VARCHAR(190) NULL,
  numero                 VARCHAR(20) NULL,
  complemento            VARCHAR(80) NULL,
  bairro                 VARCHAR(80) NULL,
  municipio_ibge         INT UNSIGNED NULL,
  municipio_nome         VARCHAR(120) NULL,         -- texto original quando o IBGE não for encontrado
  uf                     CHAR(2) NULL,
  lat                    DECIMAL(10,7) NULL,
  lng                    DECIMAL(10,7) NULL,
  vendedor_id            BIGINT UNSIGNED NULL,
  segmento_id            BIGINT UNSIGNED NULL,
  rede_id                BIGINT UNSIGNED NULL,
  aniversario            DATE NULL,
  limite_credito_sugerido DECIMAL(14,2) NULL,
  situacao               ENUM('ativo','inativo','bloqueado') NOT NULL DEFAULT 'ativo',
  motivo_bloqueio_id     BIGINT UNSIGNED NULL,
  observacoes            TEXT NULL,
  mercos_id              VARCHAR(40) NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cli_uuid (uuid_local),
  KEY ix_cli_doc (cnpj_cpf),
  KEY ix_cli_vend (vendedor_id, situacao),
  KEY ix_cli_mun (municipio_ibge),
  KEY ix_cli_razao (razao_social),
  KEY ix_cli_upd (updated_at),
  CONSTRAINT fk_cli_mun FOREIGN KEY (municipio_ibge) REFERENCES municipios(ibge) ON DELETE SET NULL,
  CONSTRAINT fk_cli_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_cli_seg FOREIGN KEY (segmento_id) REFERENCES segmentos(id) ON DELETE SET NULL,
  CONSTRAINT fk_cli_rede FOREIGN KEY (rede_id) REFERENCES redes(id) ON DELETE SET NULL,
  CONSTRAINT fk_cli_motbloq FOREIGN KEY (motivo_bloqueio_id) REFERENCES motivos_bloqueio(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente_tags (
  cliente_id BIGINT UNSIGNED NOT NULL,
  tag_id     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (cliente_id, tag_id),
  CONSTRAINT fk_ct_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ct_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente_contatos (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id BIGINT UNSIGNED NOT NULL,
  nome       VARCHAR(120) NULL,
  cargo      VARCHAR(80) NULL,
  telefone   VARCHAR(30) NULL,
  whatsapp   TINYINT(1) NOT NULL DEFAULT 0,
  email      VARCHAR(190) NULL,
  principal  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_cc_cli (cliente_id),
  CONSTRAINT fk_cc_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente_anexos (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id BIGINT UNSIGNED NOT NULL,
  tipo       ENUM('ficha_cadastral','contrato_social','outro') NOT NULL DEFAULT 'outro',
  arquivo    VARCHAR(255) NOT NULL,
  nome_original VARCHAR(190) NULL,
  enviado_por BIGINT UNSIGNED NULL,
  criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ca_cli (cliente_id),
  CONSTRAINT fk_ca_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- código do cliente no sistema de cada fábrica
CREATE TABLE cliente_representada (
  cliente_id        BIGINT UNSIGNED NOT NULL,
  representada_id   BIGINT UNSIGNED NOT NULL,
  codigo_fabrica    VARCHAR(60) NULL,
  condicao_padrao_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (cliente_id, representada_id),
  CONSTRAINT fk_cr_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_cond FOREIGN KEY (condicao_padrao_id) REFERENCES condicoes_pagamento(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- PEDIDOS
-- =========================================================
CREATE TABLE pedidos (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid_local           CHAR(36) NULL,
  numero               INT UNSIGNED NULL,           -- numeração própria da UP
  cliente_id           BIGINT UNSIGNED NOT NULL,
  representada_id      BIGINT UNSIGNED NOT NULL,
  canal_id             BIGINT UNSIGNED NULL,
  tabela_id            BIGINT UNSIGNED NULL,
  vendedor_id          BIGINT UNSIGNED NOT NULL,
  condicao_pagamento_id BIGINT UNSIGNED NULL,
  condicao_texto       VARCHAR(120) NULL,          -- texto livre/"à combinar"/importado
  data_emissao         DATE NOT NULL,
  tipo                 ENUM('venda','bonificacao','troca') NOT NULL DEFAULT 'venda',
  situacao             ENUM('orcamento','enviado','faturado_parcial','faturado','cancelado') NOT NULL DEFAULT 'orcamento',
  etapa                ENUM('confirmado_fabrica','analise_fin_liberado','analise_fin_bloqueado','separacao','despachado','recebido_cliente') NULL,
  frete                ENUM('CIF','FOB','retirar') NULL,
  ordem_compra         VARCHAR(60) NULL,
  numero_pedido_fabrica VARCHAR(60) NULL,
  comprador_nome       VARCHAR(120) NULL,
  comprador_telefone   VARCHAR(30) NULL,
  comprador_email      VARCHAR(190) NULL,
  aceita_parcial       TINYINT(1) NULL,
  total_bruto          DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_desconto       DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_ipi            DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_liquido        DECIMAL(14,2) NOT NULL DEFAULT 0,  -- sem IPI
  total_com_impostos   DECIMAL(14,2) NOT NULL DEFAULT 0,
  observacoes          TEXT NULL,
  origem               ENUM('app','web','importado','mercos') NOT NULL DEFAULT 'web',
  mercos_numero        VARCHAR(20) NULL,
  enviado_em           DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ped_uuid (uuid_local),
  UNIQUE KEY uq_ped_numero (numero),
  UNIQUE KEY uq_ped_mercos (mercos_numero),
  KEY ix_ped_cli (cliente_id, data_emissao),
  KEY ix_ped_vend (vendedor_id, data_emissao),
  KEY ix_ped_rep (representada_id, data_emissao),
  KEY ix_ped_sit (situacao, data_emissao),
  CONSTRAINT fk_ped_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ped_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ped_canal FOREIGN KEY (canal_id) REFERENCES canais(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_tab FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ped_cond FOREIGN KEY (condicao_pagamento_id) REFERENCES condicoes_pagamento(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedido_itens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id     BIGINT UNSIGNED NOT NULL,
  produto_id    BIGINT UNSIGNED NULL,               -- NULL se o produto importado não existir mais
  codigo        VARCHAR(60) NOT NULL,               -- cópia no momento do pedido
  descricao     VARCHAR(255) NOT NULL,
  quantidade    DECIMAL(12,3) NOT NULL,
  preco_tabela  DECIMAL(14,2) NOT NULL,
  desconto_pct  DECIMAL(6,3) NOT NULL DEFAULT 0,
  preco_final   DECIMAL(14,2) NOT NULL,
  ipi_pct       DECIMAL(6,3) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL,             -- sem IPI
  PRIMARY KEY (id),
  KEY ix_pi_ped (pedido_id),
  KEY ix_pi_prod (produto_id),
  CONSTRAINT fk_pi_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_prod FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedido_historico (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id   BIGINT UNSIGNED NOT NULL,
  usuario_id  BIGINT UNSIGNED NULL,
  campo       VARCHAR(30) NOT NULL,      -- situacao | etapa
  de          VARCHAR(40) NULL,
  para        VARCHAR(40) NULL,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ph_ped (pedido_id),
  CONSTRAINT fk_ph_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- FATURAMENTO E COMISSÕES A RECEBER (das representadas)
-- =========================================================
CREATE TABLE notas_fiscais (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id BIGINT UNSIGNED NOT NULL,
  pedido_id       BIGINT UNSIGNED NULL,
  cliente_id      BIGINT UNSIGNED NULL,
  vendedor_id     BIGINT UNSIGNED NULL,
  numero          VARCHAR(30) NOT NULL,
  data_emissao    DATE NOT NULL,
  valor_produtos  DECIMAL(14,2) NOT NULL,         -- base sem IPI
  valor_total     DECIMAL(14,2) NOT NULL,
  condicao_dias   VARCHAR(120) NULL,              -- "28,56,84"
  situacao        ENUM('normal','cancelada','devolvida_parcial','devolvida') NOT NULL DEFAULT 'normal',
  origem          ENUM('manual','planilha','mercos') NOT NULL DEFAULT 'manual',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nf (representada_id, numero),
  KEY ix_nf_ped (pedido_id),
  KEY ix_nf_data (data_emissao),
  KEY ix_nf_vend (vendedor_id, data_emissao),
  CONSTRAINT fk_nf_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_nf_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL,
  CONSTRAINT fk_nf_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_nf_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma linha por parcela da NF: a comissão é paga sobre o RECEBIDO
CREATE TABLE comissoes (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nota_id               BIGINT UNSIGNED NOT NULL,
  representada_id       BIGINT UNSIGNED NOT NULL,
  vendedor_id           BIGINT UNSIGNED NULL,
  parcela               SMALLINT NOT NULL DEFAULT 1,
  vencimento_cliente    DATE NOT NULL,             -- quando o cliente paga a fábrica
  previsao_recebimento  DATE NOT NULL,             -- vencimento_cliente + dias_repasse_comissao
  base_valor            DECIMAL(14,2) NOT NULL,
  pct                   DECIMAL(6,3) NOT NULL,
  valor_previsto        DECIMAL(14,2) NOT NULL,
  valor_recebido        DECIMAL(14,2) NULL,
  recebido_em           DATE NULL,
  situacao              ENUM('prevista','recebida','divergente','estornada','cancelada') NOT NULL DEFAULT 'prevista',
  extrato_item_id       BIGINT UNSIGNED NULL,
  conta_id              BIGINT UNSIGNED NULL,      -- lançamento no contas a receber
  observacao            VARCHAR(255) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_com_parcela (nota_id, parcela),
  KEY ix_com_prev (previsao_recebimento, situacao),
  KEY ix_com_rep (representada_id, situacao),
  KEY ix_com_vend (vendedor_id),
  CONSTRAINT fk_com_nf FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id) ON DELETE CASCADE,
  CONSTRAINT fk_com_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_com_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE extratos_comissao (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  representada_id BIGINT UNSIGNED NOT NULL,
  competencia     CHAR(7) NOT NULL,              -- AAAA-MM
  arquivo         VARCHAR(255) NULL,
  valor_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
  situacao        ENUM('aberto','conferido') NOT NULL DEFAULT 'aberto',
  importado_por   BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ext_rep (representada_id, competencia),
  CONSTRAINT fk_ext_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE extrato_itens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  extrato_id   BIGINT UNSIGNED NOT NULL,
  nota_numero  VARCHAR(30) NULL,
  parcela      SMALLINT NULL,
  cliente_texto VARCHAR(190) NULL,
  valor        DECIMAL(14,2) NOT NULL,
  comissao_id  BIGINT UNSIGNED NULL,
  divergencia  DECIMAL(14,2) NULL,
  status       ENUM('pendente','conciliado','divergente','sem_par') NOT NULL DEFAULT 'pendente',
  PRIMARY KEY (id),
  KEY ix_ei_ext (extrato_id),
  KEY ix_ei_nf (nota_numero),
  CONSTRAINT fk_ei_ext FOREIGN KEY (extrato_id) REFERENCES extratos_comissao(id) ON DELETE CASCADE,
  CONSTRAINT fk_ei_com FOREIGN KEY (comissao_id) REFERENCES comissoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- FINANCEIRO (acesso só para usuarios.acesso_financeiro = 1)
-- =========================================================
CREATE TABLE contas_bancarias (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome              VARCHAR(120) NOT NULL,
  banco             VARCHAR(80) NULL,
  saldo_inicial     DECIMAL(14,2) NOT NULL DEFAULT 0,
  saldo_inicial_em  DATE NOT NULL,
  ativo             TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_financeiras (
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome    VARCHAR(120) NOT NULL,
  tipo    ENUM('receita','despesa') NOT NULL,
  grupo   VARCHAR(80) NULL,         -- ex.: Pessoal, Veículos, Impostos, Comissões
  ativo   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catfin (tipo, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recorrencias (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo          ENUM('receber','pagar') NOT NULL,
  descricao     VARCHAR(190) NOT NULL,
  categoria_id  BIGINT UNSIGNED NULL,
  conta_bancaria_id BIGINT UNSIGNED NULL,
  valor         DECIMAL(14,2) NOT NULL,
  dia_vencimento TINYINT UNSIGNED NOT NULL,
  inicio        DATE NOT NULL,
  fim           DATE NULL,
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT fk_rec_cat FOREIGN KEY (categoria_id) REFERENCES categorias_financeiras(id) ON DELETE SET NULL,
  CONSTRAINT fk_rec_cb FOREIGN KEY (conta_bancaria_id) REFERENCES contas_bancarias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lancamentos (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo              ENUM('receber','pagar') NOT NULL,
  descricao         VARCHAR(190) NOT NULL,
  categoria_id      BIGINT UNSIGNED NULL,
  conta_bancaria_id BIGINT UNSIGNED NULL,
  representada_id   BIGINT UNSIGNED NULL,
  favorecido        VARCHAR(190) NULL,
  valor             DECIMAL(14,2) NOT NULL,
  vencimento        DATE NOT NULL,
  pago_em           DATE NULL,
  valor_pago        DECIMAL(14,2) NULL,
  situacao          ENUM('aberto','pago','cancelado') NOT NULL DEFAULT 'aberto',
  origem            ENUM('manual','comissao','repasse_vendedor','recorrencia','estorno') NOT NULL DEFAULT 'manual',
  origem_id         BIGINT UNSIGNED NULL,
  recorrencia_id    BIGINT UNSIGNED NULL,
  anexo             VARCHAR(255) NULL,
  observacao        VARCHAR(255) NULL,
  criado_por        BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lanc_rec (recorrencia_id, vencimento),   -- a rotina diária não duplica recorrências
  KEY ix_lanc_venc (tipo, situacao, vencimento),
  KEY ix_lanc_pago (pago_em),
  KEY ix_lanc_origem (origem, origem_id),
  CONSTRAINT fk_lanc_cat FOREIGN KEY (categoria_id) REFERENCES categorias_financeiras(id) ON DELETE SET NULL,
  CONSTRAINT fk_lanc_cb FOREIGN KEY (conta_bancaria_id) REFERENCES contas_bancarias(id) ON DELETE SET NULL,
  CONSTRAINT fk_lanc_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE SET NULL,
  CONSTRAINT fk_lanc_rec FOREIGN KEY (recorrencia_id) REFERENCES recorrencias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- % do vendedor preenchido à mão, por vendedor (e opcionalmente por representada)
CREATE TABLE regras_comissao_vendedor (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendedor_id      BIGINT UNSIGNED NOT NULL,
  representada_id  BIGINT UNSIGNED NULL,          -- NULL = vale para todas
  pct              DECIMAL(6,3) NOT NULL,
  base             ENUM('comissao_recebida_up','valor_recebido_venda') NOT NULL DEFAULT 'comissao_recebida_up',
  vigencia_inicio  DATE NOT NULL,
  vigencia_fim     DATE NULL,
  PRIMARY KEY (id),
  KEY ix_rcv (vendedor_id, representada_id, vigencia_inicio),
  CONSTRAINT fk_rcv_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_rcv_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fechamentos_vendedor (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendedor_id  BIGINT UNSIGNED NOT NULL,
  competencia  CHAR(7) NOT NULL,
  base_total   DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor        DECIMAL(14,2) NOT NULL DEFAULT 0,
  ajuste       DECIMAL(14,2) NOT NULL DEFAULT 0,
  situacao     ENUM('aberto','fechado') NOT NULL DEFAULT 'aberto',
  lancamento_id BIGINT UNSIGNED NULL,
  fechado_por  BIGINT UNSIGNED NULL,
  fechado_em   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fech (vendedor_id, competencia),
  CONSTRAINT fk_fech_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fech_lanc FOREIGN KEY (lancamento_id) REFERENCES lancamentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- METAS E CALENDÁRIO
-- =========================================================
CREATE TABLE metas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia     CHAR(7) NOT NULL,
  vendedor_id     BIGINT UNSIGNED NOT NULL,
  representada_id BIGINT UNSIGNED NOT NULL,
  canal_id        BIGINT UNSIGNED NULL,           -- NULL = meta da representada inteira; preenchido = ex.: Casa do Construtor
  valor           DECIMAL(14,2) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meta (competencia, vendedor_id, representada_id, canal_id),
  KEY ix_meta_comp (competencia),
  CONSTRAINT fk_meta_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_meta_rep FOREIGN KEY (representada_id) REFERENCES representadas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_meta_canal FOREIGN KEY (canal_id) REFERENCES canais(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feriados (
  data        DATE NOT NULL,
  descricao   VARCHAR(120) NOT NULL,
  abrangencia ENUM('nacional','estadual','municipal') NOT NULL DEFAULT 'nacional',
  PRIMARY KEY (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- VISITAS
-- =========================================================
CREATE TABLE resultados_visita (
  id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome  VARCHAR(120) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id), UNIQUE KEY uq_resvis (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visitas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid_local      CHAR(36) NULL,
  cliente_id      BIGINT UNSIGNED NOT NULL,
  vendedor_id     BIGINT UNSIGNED NOT NULL,
  data_planejada  DATE NULL,
  ordem_rota      SMALLINT NULL,
  checkin_em      DATETIME NULL,
  checkin_lat     DECIMAL(10,7) NULL,
  checkin_lng     DECIMAL(10,7) NULL,
  checkin_precisao_m INT NULL,
  checkout_em     DATETIME NULL,
  resultado_id    BIGINT UNSIGNED NULL,
  observacao      TEXT NULL,
  pedido_id       BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vis_uuid (uuid_local),
  KEY ix_vis_vend (vendedor_id, data_planejada),
  KEY ix_vis_cli (cliente_id, checkin_em),
  CONSTRAINT fk_vis_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_vis_vend FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_vis_res FOREIGN KEY (resultado_id) REFERENCES resultados_visita(id) ON DELETE SET NULL,
  CONSTRAINT fk_vis_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- IMPORTAÇÕES E SINCRONIZAÇÃO
-- =========================================================
CREATE TABLE importacoes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo         ENUM('clientes','produtos','precos','pedidos','faturamento','metas','visitas','extrato') NOT NULL,
  arquivo      VARCHAR(255) NOT NULL,
  linhas_total INT NOT NULL DEFAULT 0,
  linhas_ok    INT NOT NULL DEFAULT 0,
  linhas_erro  INT NOT NULL DEFAULT 0,
  relatorio    MEDIUMTEXT NULL,       -- JSON com erros por linha
  situacao     ENUM('previa','importada','desfeita') NOT NULL DEFAULT 'previa',
  usuario_id   BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- registros de envio do celular já processados (o mesmo envio repetido não duplica)
CREATE TABLE sync_recebidos (
  uuid_local   CHAR(36) NOT NULL,
  usuario_id   BIGINT UNSIGNED NOT NULL,
  entidade     VARCHAR(30) NOT NULL,
  entidade_id  BIGINT UNSIGNED NOT NULL,
  recebido_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uuid_local)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuracoes (
  chave  VARCHAR(80) NOT NULL,
  valor  TEXT NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
