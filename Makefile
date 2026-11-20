# `make gate` é o mesmo portão do GitHub Actions: rode antes de todo commit/deploy.
.PHONY: gate segredos lint testes deploy deploy-simular local

gate: segredos lint testes

segredos:
	python3 scripts/verificar-segredos.py

lint:
	scripts/lint.sh

testes:
	tests/run.sh

local:          # http://localhost:8000/painel.html (precisa de MySQL e das variáveis BE_DB_*)
	php -S localhost:8000 -t server

deploy-simular:
	scripts/deploy-hostinger.sh --simular

deploy:
	scripts/deploy-hostinger.sh
