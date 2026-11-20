-- Fluxo igual ao BeerPass: maquininha por torneira, IBU/cervejaria, cupons de desconto, verificação de idade configurável.

ALTER TABLE be_products ADD COLUMN IF NOT EXISTS cervejaria VARCHAR(80) NULL AFTER nome;
ALTER TABLE be_products ADD COLUMN IF NOT EXISTS ibu SMALLINT NULL AFTER abv;

-- Maquininha presa a uma torneira (NULL = mostra todas as torneiras do ponto)
ALTER TABLE be_terminals ADD COLUMN IF NOT EXISTS tap_id INT NULL AFTER location_id;

ALTER TABLE be_orders ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) NULL AFTER total;
ALTER TABLE be_orders ADD COLUMN IF NOT EXISTS desconto DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER subtotal;
ALTER TABLE be_orders ADD COLUMN IF NOT EXISTS cupom VARCHAR(30) NULL AFTER desconto;

CREATE TABLE IF NOT EXISTS be_coupons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) NOT NULL UNIQUE,
  descricao VARCHAR(120) NULL,
  tipo ENUM('percentual','valor') NOT NULL DEFAULT 'percentual',
  valor DECIMAL(10,2) NOT NULL,
  minimo DECIMAL(10,2) NOT NULL DEFAULT 0,      -- subtotal mínimo
  validade DATE NULL,
  usos_max INT NULL,                             -- NULL = ilimitado
  usos INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO be_settings (k, v) VALUES
 ('verificacao_idade','cpf'),            -- cpf = CPF + nascimento (+ nome no 1º acesso) • declaracao = só "Sim, concordo" (igual BeerPass)
 ('termos_texto','Para prosseguir com a compra de chope você deve concordar com os termos de uso e que tem mais de 18 anos.'),
 ('ajuda_texto','Precisa de ajuda? Chame no WhatsApp do responsável pelo ponto.'),
 ('cupons_ativo','1'),
 ('pix_modo','api_mp');                  -- api_mp = QR gerado pelo servidor (Mercado Pago) • maquininha = Pix da própria maquininha (PagBank)
