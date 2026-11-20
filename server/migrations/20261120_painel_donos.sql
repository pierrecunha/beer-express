-- Painel pelo celular: donos de quiosque (cada um vê e edita só os próprios pontos) e bebidas de cada dono.
ALTER TABLE be_users MODIFY role ENUM('admin','dono','operador') NOT NULL DEFAULT 'admin';
CREATE TABLE IF NOT EXISTS be_user_locations (
  user_id INT NOT NULL,
  location_id INT NOT NULL,
  PRIMARY KEY (user_id, location_id),
  KEY (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE be_products ADD COLUMN IF NOT EXISTS dono_id INT NULL;
