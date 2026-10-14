-- Dados iniciais da UP (cadastros-base). Rodar depois do schema.sql.
SET NAMES utf8mb4;

INSERT INTO representadas (id, nome, cnpj, comissao_pct, comissao_base, cor) VALUES
 (1,'Buffalo','07.040.941/0001-06',5.000,'recebido','#E4572E'),
 (2,'Menegotti','84.431.154/0006-32',5.000,'recebido','#2E86AB'),
 (3,'Mecan','19.537.752/0001-14',5.000,'recebido','#F2A541'),
 (4,'Kärcher','47.110.960/0001-78',5.000,'recebido','#FFD23F'),
 (5,'Bognar','05.061.744/0001-30',7.000,'recebido','#6A994E');

-- Os 27 cadastros de "representada" do Mercos viram canais das 5 fábricas.
-- mercos_nome guarda o nome exato do Mercos para a importação.
INSERT INTO canais (representada_id, nome, contribuinte, casa_construtor, desconto_base_pct, mercos_nome) VALUES
 (1,'Rental','sim',0,0,'BUFFALO RENTAL - CONTRIBUINTE'),
 (1,'Rental','nao',0,0,'BUFFALO RENTAL - NÃO CONTRIBUINTE'),
 (1,'Rental Peças','sim',0,0,'BUFFALO RENTAL - CONTRIBUINTE - PEÇAS'),
 (1,'Rental Peças','nao',0,0,'BUFFALO RENTAL - NÃO CONTRIBUINTE - PEÇAS'),
 (1,'Revenda','sim',0,0,'BUFFALO REVENDA - CONTRIBUINTE'),
 (1,'Casa do Construtor','ambos',1,0,'BUFFALO - CASA DO CONSTRUTOR'),
 (1,'B2B Peças CC 41%','ambos',1,41,'BUFFALLO CC (B2B) - PEÇAS - DESCONTO 41%'),
 (1,'B2B Peças Rental 26%','ambos',0,26,'BUFFALLO RENTAL (B2B) - PEÇAS - DESCONTO 26%'),
 (1,'B2B Peças Rental 49%','ambos',0,49,'BUFFALLO RENTAL (B2B) - PEÇAS - DESCONTO 49%'),
 (1,'B2B Peças Revenda 26%','ambos',0,26,'BUFFALLO REVENDA (B2B) - PEÇAS - DESCONTO 26%'),
 (1,'B2B Peças Revenda 49%','ambos',0,49,'BUFFALLO REVENDA (B2B) - PEÇAS - DESCONTO 49%'),
 (2,'Padrão','sim',0,0,'MENEGOTTI - CONTRIBUINTE'),
 (2,'Padrão','nao',0,0,'MENEGOTTI – NÃO CONTRIBUINTE'),
 (2,'Betoneiras','ambos',0,0,'MENEGOTTI - BETONEIRAS'),
 (2,'B2B Reposição 28%','sim',0,28,'MENEGOTTI (B2B) – CONTRIBUINTE – DESCONTO 28%'),
 (2,'B2B Reposição 28%','nao',0,28,'MENEGOTTI (B2B) - NÃO CONTRIBUINTE – DESCONTO 28%'),
 (2,'B2B Assistente 36%','sim',0,36,'MENEGOTTI (B2B) – CONTRIBUINTE – DESCONTO 36%'),
 (2,'B2B Assistente 36%','nao',0,36,'MENEGOTTI (B2B) - NÃO CONTRIBUINTE – DESCONTO 36%'),
 (2,'B2B 50%','sim',0,50,'MENEGOTTI (B2B) – CONTRIBUINTE – DESCONTO 50%'),
 (3,'Locador','sim',0,0,'MECAN - LOCADOR CONTR.'),
 (3,'Locador','nao',0,0,'MECAN - LOCADOR NÃO CONTR.'),
 (3,'Consumidor final','sim',0,0,'MECAN CONSUMIDOR FINAL - CONTRIBUINTE'),
 (3,'Consumidor final','nao',0,0,'MECAN CONSUMIDOR FINAL - NÃO CONTRIBUINTE'),
 (4,'Padrão','sim',0,0,'KARCHER - CONTRIBUINTE'),
 (4,'Padrão','nao',0,0,'KARCHER - NÃO CONTRIBUINTE'),
 (4,'Casa do Construtor','ambos',1,0,'KARCHER - CASA DO CONSTRUTOR'),
 (5,'Padrão','ambos',0,0,'BOGNAR');

-- uma tabela de preço por canal para começar (a importação de produtos preenche os preços)
INSERT INTO tabelas_preco (representada_id, canal_id, nome, tipo)
SELECT c.representada_id, c.id,
       CONCAT(r.nome,' - ',c.nome, CASE c.contribuinte WHEN 'sim' THEN ' (contribuinte)' WHEN 'nao' THEN ' (não contribuinte)' ELSE '' END),
       IF(c.casa_construtor=1,'casa_construtor','normal')
FROM canais c JOIN representadas r ON r.id = c.representada_id;

INSERT INTO segmentos (nome) VALUES
 ('Assistente Técnico'),('Cliente TUL'),('Construtora'),('Consumidor final'),('E-commerce'),('Indústria'),
 ('Instalador'),('Licitação'),('Locador'),('Material Construção'),('Revenda'),('Revenda Agrícola'),
 ('Revenda Loja de Tintas'),('Revenda Material de Construção'),('Revenda Máquinas e Equipamentos');

-- Só redes de lojas; as regiões que estavam misturadas nas "redes" do Mercos vão para municipios.regiao_up
INSERT INTO redes (nome) VALUES
 ('Alugmaq'),('BIOFER'),('Betamax'),('CITILOC'),('COPAFER'),('Casa do Construtor'),('Conceito'),('Coseletro'),
 ('DBI'),('DEGRAUS ANDAIMES'),('Dutra Máquinas'),('FTS MULTIMARCAS'),('IM'),('Kanaflex'),('LOCA TUDO'),
 ('LOCEXPRESS'),('MESTRE DA OBRA'),('Mérito Comércio'),('PAGOTTI'),('RM'),('Rede Disensa'),('Rede Nikkeybraz'),
 ('SP LOG'),('SUGUIURA'),('SÃO BENTO'),('TALISMÃ'),('TUL'),('Torres Cabral'),('VILLAGE');

INSERT INTO tags (nome) VALUES ('ADM LEONARDO'),('CARTEIRA UP (cliente p/ repassar)'),('CC CRISTIANO'),('VENDEDOR 1'),('VENDEDOR 2');

INSERT INTO resultados_visita (nome) VALUES ('Pedido fechado'),('Orçamento enviado'),('Sem interesse no momento'),('Retornar depois'),('Cliente ausente'),('Apresentação de produto');

INSERT INTO categorias_financeiras (nome, tipo, grupo) VALUES
 ('Comissão de representada','receita','Comissões'),
 ('Outras receitas','receita','Outras'),
 ('Comissão de vendedores','despesa','Comissões'),
 ('Salários e encargos','despesa','Pessoal'),
 ('Pró-labore','despesa','Pessoal'),
 ('Aluguel','despesa','Estrutura'),
 ('Água, luz, internet e telefone','despesa','Estrutura'),
 ('Combustível e pedágio','despesa','Veículos'),
 ('Manutenção de veículos','despesa','Veículos'),
 ('Impostos','despesa','Impostos'),
 ('Contabilidade','despesa','Serviços'),
 ('Sistemas e assinaturas','despesa','Serviços'),
 ('Marketing e eventos','despesa','Marketing'),
 ('Tarifas bancárias','despesa','Financeiro'),
 ('Outras despesas','despesa','Outras');

-- Feriados nacionais 2026–2027, estadual SP (9/jul) e capital SP (25/jan). Ajustáveis na tela de configurações.
INSERT INTO feriados (data, descricao, abrangencia) VALUES
 ('2026-01-01','Confraternização Universal','nacional'),('2026-01-25','Aniversário de São Paulo','municipal'),
 ('2026-02-16','Carnaval','nacional'),('2026-02-17','Carnaval','nacional'),('2026-04-03','Sexta-feira Santa','nacional'),
 ('2026-04-21','Tiradentes','nacional'),('2026-05-01','Dia do Trabalho','nacional'),('2026-06-04','Corpus Christi','nacional'),
 ('2026-07-09','Revolução Constitucionalista','estadual'),('2026-09-07','Independência','nacional'),
 ('2026-10-12','Nossa Senhora Aparecida','nacional'),('2026-11-02','Finados','nacional'),
 ('2026-11-15','Proclamação da República','nacional'),('2026-11-20','Consciência Negra','nacional'),
 ('2026-12-25','Natal','nacional'),
 ('2027-01-01','Confraternização Universal','nacional'),('2027-01-25','Aniversário de São Paulo','municipal'),
 ('2027-02-08','Carnaval','nacional'),('2027-02-09','Carnaval','nacional'),('2027-03-26','Sexta-feira Santa','nacional'),
 ('2027-04-21','Tiradentes','nacional'),('2027-05-01','Dia do Trabalho','nacional'),('2027-05-27','Corpus Christi','nacional'),
 ('2027-07-09','Revolução Constitucionalista','estadual'),('2027-09-07','Independência','nacional'),
 ('2027-10-12','Nossa Senhora Aparecida','nacional'),('2027-11-02','Finados','nacional'),
 ('2027-11-15','Proclamação da República','nacional'),('2027-11-20','Consciência Negra','nacional'),
 ('2027-12-25','Natal','nacional');

INSERT INTO configuracoes (chave, valor) VALUES
 ('empresa_nome','UP Gestão Comercial'),
 ('fuso','America/Sao_Paulo'),
 ('painel_tv_intervalo_seg','20'),
 ('painel_tv_atualizar_min','5');
