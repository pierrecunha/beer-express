-- Consulta obrigatória do CPF na Receita + maquininha Stone (Sunmi P2) com instalação remota.
ALTER TABLE be_terminals MODIFY provider ENUM('mercadopago','pagbank','stone','simulado') NOT NULL DEFAULT 'simulado';
ALTER TABLE be_terminals ADD COLUMN IF NOT EXISTS stone_code VARCHAR(20) NULL;
ALTER TABLE be_terminals ADD COLUMN IF NOT EXISTS comando VARCHAR(20) NULL;
INSERT IGNORE INTO be_settings (k, v) VALUES ('cpf_exigir_receita', '1');
