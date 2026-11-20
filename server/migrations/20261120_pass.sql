-- Modo Pass (igual BeerPass): crédito pré-pago por CPF, cartão/pulseira RFID e cobrança por ml.
-- Idempotente (MariaDB): pode rodar mais de uma vez.

ALTER TABLE be_customers ADD COLUMN IF NOT EXISTS saldo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_gasto;

ALTER TABLE be_orders ADD COLUMN IF NOT EXISTS tipo ENUM('copo','recarga') NOT NULL DEFAULT 'copo' AFTER ref;
ALTER TABLE be_orders ADD COLUMN IF NOT EXISTS bonus DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total;

ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS preco_litro DECIMAL(10,2) NULL AFTER ml_por_pulso;
ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS max_ml_servida INT NOT NULL DEFAULT 1000 AFTER preco_litro;

ALTER TABLE be_devices ADD COLUMN IF NOT EXISTS tipo ENUM('central','torneira') NOT NULL DEFAULT 'central' AFTER nome;

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

INSERT IGNORE INTO be_settings (k, v) VALUES
 ('pass_ativo','1'),
 ('recarga_valores','20,50,100,150'),
 ('recarga_bonus','100:5,150:10'),      -- recarga >= R$100 ganha R$5; >= R$150 ganha R$10
 ('pass_ml_minimo','100'),              -- saldo precisa cobrir ao menos isso para abrir a torneira
 ('pass_fim_inatividade_s','8'),        -- fecha a torneira após X s sem fluxo
 ('tv_ranking','0'),                    -- mostra ranking (Nome S.) na TV
 ('tv_mensagem','Aproxime seu cartão Beer Express na torneira e sirva-se. Pague só o que beber!');
