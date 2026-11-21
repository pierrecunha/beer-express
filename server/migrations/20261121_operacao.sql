-- Operação do dia a dia: custo do barril (margem), validade do barril aberto e limpeza das linhas.
ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS custo_barril DECIMAL(10,2) NULL;
ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS barril_aberto_em DATETIME NULL;
ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS validade_dias SMALLINT NOT NULL DEFAULT 30;
ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS limpeza_em DATETIME NULL;
ALTER TABLE be_taps ADD COLUMN IF NOT EXISTS limpeza_dias SMALLINT NOT NULL DEFAULT 14;
UPDATE be_taps SET barril_aberto_em = NOW() WHERE barril_aberto_em IS NULL;
UPDATE be_taps SET limpeza_em = NOW() WHERE limpeza_em IS NULL;
