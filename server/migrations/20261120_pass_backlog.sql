-- Beer Pass (recarga + cartão + cobrança por ml) fica pronto, porém DESLIGADO (backlog).
-- Para ligar: painel → Configurações → Beer Pass → Ativo = Sim.
UPDATE be_settings SET v = '0' WHERE k = 'pass_ativo';
