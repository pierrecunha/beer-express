-- =====================================================================
--  BEER EXPRESS — Chopp self-service com liberação por telemetria
--  Banco: MySQL 5.7+/MariaDB 10.3+ (Hostinger)  •  charset utf8mb4
--  Execute UMA vez no phpMyAdmin. O usuário admin inicial é criado
--  pelo próprio api.php no primeiro acesso a ?r=setup (ver README).
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS be_settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS be_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','operador') NOT NULL DEFAULT 'admin',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pontos de venda (cada ponto = 1 freezer/quiosque)
CREATE TABLE IF NOT EXISTS be_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  endereco VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Controladores ESP32 (cada um comanda até 4 torneiras)
CREATE TABLE IF NOT EXISTS be_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_id INT NOT NULL,
  nome VARCHAR(80) NOT NULL,
  tipo ENUM('central','torneira') NOT NULL DEFAULT 'central',  -- central = ESP32 sem tela; torneira = tela + RFID
  device_key VARCHAR(64) NOT NULL UNIQUE,
  start_mode ENUM('button','pos','auto') NOT NULL DEFAULT 'button',
  arm_timeout_s INT NOT NULL DEFAULT 120,
  temp_min DECIMAL(5,2) NOT NULL DEFAULT -1.00,
  temp_max DECIMAL(5,2) NOT NULL DEFAULT 6.00,
  last_seen DATETIME NULL,
  online TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  fw_version VARCHAR(20) NULL,
  rssi INT NULL,
  temperatura DECIMAL(5,2) NULL,
  temp_alert_since DATETIME NULL,
  porta_aberta TINYINT(1) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maquininhas (POS Android) — cada uma com token próprio
CREATE TABLE IF NOT EXISTS be_terminals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_id INT NOT NULL,
  nome VARCHAR(80) NOT NULL,
  codigo VARCHAR(12) NOT NULL UNIQUE,      -- código de pareamento digitado no app
  token VARCHAR(64) NULL UNIQUE,           -- gerado no pareamento
  provider ENUM('mercadopago','pagbank','simulado') NOT NULL DEFAULT 'simulado',
  serial VARCHAR(64) NULL,
  app_version VARCHAR(20) NULL,
  last_seen DATETIME NULL,
  online TINYINT(1) NOT NULL DEFAULT 0,
  aceita_pix TINYINT(1) NOT NULL DEFAULT 1,
  aceita_credito TINYINT(1) NOT NULL DEFAULT 1,
  aceita_debito TINYINT(1) NOT NULL DEFAULT 1,
  bloqueado_ate DATETIME NULL,             -- trava após muitas tentativas inválidas
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bebidas (catálogo)
CREATE TABLE IF NOT EXISTS be_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  estilo VARCHAR(80) NULL,
  descricao VARCHAR(255) NULL,
  abv DECIMAL(4,1) NULL,
  imagem_url VARCHAR(255) NULL,
  cor VARCHAR(9) NULL DEFAULT '#F5B301',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Torneiras (canal físico do ESP32) — cada uma com um barril
CREATE TABLE IF NOT EXISTS be_taps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  canal TINYINT NOT NULL,                  -- 1..4
  nome VARCHAR(40) NOT NULL,               -- "Torneira 1"
  product_id INT NULL,
  modo ENUM('flow','pulse') NOT NULL DEFAULT 'flow',
  pulsos_por_litro DECIMAL(8,2) NOT NULL DEFAULT 1925.00,  -- modo flow (calibrável)
  ml_por_pulso DECIMAL(8,2) NOT NULL DEFAULT 100.00,       -- modo pulse (controladora externa)
  preco_litro DECIMAL(10,2) NULL,                          -- Modo Pass (cobrança por ml); NULL = só copo
  max_ml_servida INT NOT NULL DEFAULT 1000,
  barril_ml INT NOT NULL DEFAULT 50000,
  restante_ml INT NOT NULL DEFAULT 50000,
  alerta_pct TINYINT NOT NULL DEFAULT 15,
  alerta_enviado TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_dev_canal (device_id, canal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tamanhos/preços por torneira (300 ml, 500 ml …)
CREATE TABLE IF NOT EXISTS be_offers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tap_id INT NOT NULL,
  volume_ml INT NOT NULL,
  preco DECIMAL(10,2) NOT NULL,
  ordem TINYINT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  KEY (tap_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clientes verificados (CPF + nascimento validados)
CREATE TABLE IF NOT EXISTS be_customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cpf CHAR(11) NOT NULL UNIQUE,
  nome VARCHAR(160) NOT NULL,
  nascimento DATE NOT NULL,
  verificado_por VARCHAR(20) NOT NULL,     -- nfeio | serpro | local
  verificado_em DATETIME NOT NULL,
  bloqueado TINYINT(1) NOT NULL DEFAULT 0,
  motivo_bloqueio VARCHAR(255) NULL,
  compras INT NOT NULL DEFAULT 0,
  total_ml INT NOT NULL DEFAULT 0,
  total_gasto DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
  ultima_compra DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Toda tentativa de verificação (auditoria + anti-força-bruta)
CREATE TABLE IF NOT EXISTS be_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  terminal_id INT NULL,
  cpf CHAR(11) NULL,
  ok TINYINT(1) NOT NULL,
  motivo VARCHAR(60) NOT NULL,
  token VARCHAR(64) NULL,
  customer_id INT NULL,
  expira_em DATETIME NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (terminal_id, created_at),
  KEY (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS be_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(32) NOT NULL UNIQUE,          -- external_reference enviada à adquirente
  tipo ENUM('copo','recarga') NOT NULL DEFAULT 'copo',
  terminal_id INT NOT NULL,
  location_id INT NOT NULL,
  customer_id INT NOT NULL,
  status ENUM('aguardando_pagamento','pago','servindo','concluido','parcial','falhou','cancelado','estornado') NOT NULL DEFAULT 'aguardando_pagamento',
  total DECIMAL(10,2) NOT NULL,
  bonus DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_ml INT NOT NULL,
  metodo ENUM('credito','debito','pix') NULL,
  provider VARCHAR(20) NULL,
  pagamento_id VARCHAR(64) NULL,
  nsu VARCHAR(40) NULL,
  bandeira VARCHAR(30) NULL,
  final_cartao VARCHAR(4) NULL,
  pix_qr_base64 MEDIUMTEXT NULL,
  pix_copia_cola TEXT NULL,
  verificado_servidor TINYINT(1) NOT NULL DEFAULT 0,
  obs VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pago_em DATETIME NULL,
  concluido_em DATETIME NULL,
  KEY (status), KEY (created_at), KEY (customer_id), KEY (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS be_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  tap_id INT NOT NULL,
  offer_id INT NOT NULL,
  produto VARCHAR(80) NOT NULL,
  volume_ml INT NOT NULL,
  qtd TINYINT NOT NULL,
  preco_unit DECIMAL(10,2) NOT NULL,
  KEY (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada copo a ser servido = 1 dispense (fila por ESP32)
CREATE TABLE IF NOT EXISTS be_dispenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  item_id INT NOT NULL,
  tap_id INT NOT NULL,
  device_id INT NOT NULL,
  seq TINYINT NOT NULL,                    -- copo n de N no pedido
  volume_ml INT NOT NULL,
  status ENUM('pendente','armado','liberado','servindo','concluido','timeout','erro','cancelado') NOT NULL DEFAULT 'pendente',
  servido_ml INT NOT NULL DEFAULT 0,
  pulsos INT NOT NULL DEFAULT 0,
  erro VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  armado_em DATETIME NULL,
  inicio_em DATETIME NULL,
  fim_em DATETIME NULL,
  KEY (device_id, status), KEY (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS be_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  nivel ENUM('info','aviso','critico') NOT NULL DEFAULT 'info',
  tipo VARCHAR(40) NOT NULL,
  location_id INT NULL,
  device_id INT NULL,
  terminal_id INT NULL,
  order_id INT NULL,
  msg VARCHAR(500) NOT NULL,
  dados TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (created_at), KEY (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS be_telemetry (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  temperatura DECIMAL(5,2) NULL,
  rssi INT NULL,
  heap INT NULL,
  uptime_s INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cartões / pulseiras RFID (UID do chip) ligados a um CPF
CREATE TABLE IF NOT EXISTS be_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(32) NOT NULL UNIQUE,
  customer_id INT NULL,
  apelido VARCHAR(40) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  codigo_vinculo CHAR(4) NULL,
  codigo_expira DATETIME NULL,
  ultimo_uso DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (customer_id), KEY (codigo_vinculo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extrato da carteira (todo crédito/débito)
CREATE TABLE IF NOT EXISTS be_ledger (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  tipo ENUM('recarga','bonus','consumo','estorno','ajuste') NOT NULL,
  valor DECIMAL(12,2) NOT NULL,              -- positivo = crédito, negativo = débito
  saldo_apos DECIMAL(12,2) NOT NULL,
  order_id INT NULL,
  pour_id INT NULL,
  obs VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Servidas por ml (cliente aproxima o cartão e se serve)
CREATE TABLE IF NOT EXISTS be_pours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  card_id INT NULL,
  tap_id INT NOT NULL,
  device_id INT NOT NULL,
  location_id INT NOT NULL,
  produto VARCHAR(80) NULL,
  preco_litro DECIMAL(10,2) NOT NULL,
  limite_ml INT NOT NULL,
  servido_ml INT NOT NULL DEFAULT 0,
  pulsos INT NOT NULL DEFAULT 0,
  valor DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('aberta','fechada','erro') NOT NULL DEFAULT 'aberta',
  motivo_fim VARCHAR(60) NULL,
  inicio_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL,
  fim_em DATETIME NULL,
  KEY (customer_id), KEY (status), KEY (tap_id, inicio_em), KEY (location_id, inicio_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações padrão (todas editáveis no painel → Configurações)
INSERT IGNORE INTO be_settings (k, v) VALUES
 ('marca_nome','Beer Express'),
 ('marca_slogan','Chopp gelado, do seu jeito'),
 ('marca_cor','#F5B301'),
 ('idade_minima','18'),
 ('cpf_provider','local'),            -- local | nfeio | serpro
 ('nfeio_api_key',''),
 ('serpro_consumer_key',''),
 ('serpro_consumer_secret',''),
 ('serpro_url','https://gateway.apiserpro.serpro.gov.br/consulta-cpf-df/v3/cpf/{cpf}?dataNascimento={nasc}'),
 ('reverificar_dias','365'),
 ('nome_regra','primeiro_e_sobrenome'), -- primeiro | primeiro_e_sobrenome
 ('limite_ml_dia_cpf','3000'),
 ('limite_copos_pedido','4'),
 ('max_falhas_verificacao','5'),
 ('bloqueio_minutos','10'),
 ('mp_access_token',''),
 ('mp_verificar_cartao','1'),
 ('pix_expira_min','5'),
 ('telegram_token',''),
 ('telegram_chat_ids',''),
 ('tg_notificar_vendas','1'),
 ('tg_notificar_tentativa_menor','1'),
 ('tg_resumo_diario','1'),
 ('offline_minutos','3'),
 ('msg_final','Beba com moderação. Se beber, não dirija.'),
 ('ultimo_resumo','');

INSERT IGNORE INTO be_settings (k, v) VALUES
 ('pass_ativo','0'),                     -- backlog: recarga/cartão/cobrança por ml prontos, desligados
 ('recarga_valores','20,50,100,150'),
 ('recarga_bonus','100:5,150:10'),      -- recarga >= R$100 ganha R$5; >= R$150 ganha R$10
 ('pass_ml_minimo','100'),              -- saldo precisa cobrir ao menos isso para abrir a torneira
 ('pass_fim_inatividade_s','8'),        -- fecha a torneira após X s sem fluxo
 ('tv_ranking','0'),                    -- mostra ranking (Nome S.) na TV
 ('tv_mensagem','Aproxime seu cartão Beer Express na torneira e sirva-se. Pague só o que beber!');
