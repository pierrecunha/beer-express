-- Portal público (index.html): pontos no mapa, contatos do site e "quero saber mais".
ALTER TABLE be_locations ADD COLUMN IF NOT EXISTS lat DECIMAL(10,7) NULL;
ALTER TABLE be_locations ADD COLUMN IF NOT EXISTS lng DECIMAL(10,7) NULL;
ALTER TABLE be_locations ADD COLUMN IF NOT EXISTS horario VARCHAR(120) NULL;
ALTER TABLE be_locations ADD COLUMN IF NOT EXISTS publico TINYINT(1) NOT NULL DEFAULT 1;
CREATE TABLE IF NOT EXISTS be_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('espaco','avise','contato') NOT NULL DEFAULT 'contato',
  nome VARCHAR(120) NOT NULL,
  contato VARCHAR(160) NOT NULL,
  cidade VARCHAR(120) NULL,
  mensagem VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  atendido TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
UPDATE be_locations SET publico = 0 WHERE nome = 'Demonstração (simulado)';
