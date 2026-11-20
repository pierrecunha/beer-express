-- UX de vendas: funil de conversão, avaliação pós-compra e happy hour automático.

CREATE TABLE IF NOT EXISTS be_funnel (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  terminal_id INT NOT NULL,
  sessao CHAR(16) NOT NULL,
  etapa VARCHAR(20) NOT NULL,            -- inicio, termos, identificado, tamanho, quantidade, pagamento, pago, servido
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sessao_etapa (sessao, etapa),
  KEY (created_at), KEY (terminal_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS be_ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  terminal_id INT NOT NULL,
  nota TINYINT NOT NULL,                 -- 1 a 5
  motivo VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO be_settings (k, v) VALUES
 ('happy_hour_ativo','0'),
 ('happy_hour_nome','Happy Hour'),
 ('happy_hour_inicio','17:00'),
 ('happy_hour_fim','19:00'),
 ('happy_hour_dias','1,2,3,4,5'),       -- 1 = segunda … 7 = domingo
 ('happy_hour_pct','20'),
 ('prova_social_min','5'),              -- só mostra "X copos hoje" a partir deste número
 ('avaliacao_ativa','1'),
 ('inatividade_s','45');
