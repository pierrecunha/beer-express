# 🍺 Beer Express — chopp self-service com liberação por telemetria

Sistema no modelo **BeerPass**: uma **maquininha por torneira** (Moderninha Smart 2) mostra o chopp (foto, tipo, ABV, IBU, valor por 100 ml); o cliente aceita os termos 18+, escolhe o tamanho, a quantidade e um cupom, paga e **puxa a alavanca** — o fluxômetro corta no volume pago. Tudo editável no painel. Vendas: happy hour automático, selo "Mais pedido" e "Economize X%", contador de copos do dia, Pix recomendado, alternativa quando o cartão é recusado, "Pedir mais um", avaliação pós-compra e funil de conversão no painel. O modelo de **crédito pré-pago + cartão RFID + cobrança por ml** está pronto e **desligado (backlog)**. Inclui **BeerList** para TV (`tv.html`) e ranking de consumo. Lista de compras e instalação: **[docs/HARDWARE.md](docs/HARDWARE.md)**.

Componentes: **app da maquininha** (verificação 18+, recarga, cartão, cardápio, pagamento), **servidor** (PHP/MySQL na Hostinger, igual ao WashEasy), **controlador ESP32** que libera o volume exato na torneira, **painel web** e **alertas no Telegram**.

```
 Cliente ──► MAQUININHA (Android)                SERVIDOR (Hostinger)                 FREEZER
            pos.html dentro do app  ──HTTPS──►   api.php + MySQL  ◄──HTTPS (poll)──  ESP32
            • 18+ / CPF / nascimento / nome      • valida CPF + idade (Receita)       • válvula solenoide
            • escolhe chopp, 300/500 ml, qtd     • cria pedido, confirma pagamento    • fluxômetro (ml exatos)
            • paga: crédito/débito (SDK)         • fila de copos por torneira         • botão iluminado
              ou Pix (API Mercado Pago)          • Telegram, cron, painel             • temperatura / porta
```

## Estrutura

| Pasta | Conteúdo |
|---|---|
| `server/` | `api.php` (API única), `pos.html` (tela da maquininha), `painel.html` (gestão), `tv.html` (BeerList), `cron_tick.php`, `install.sql`, `config.php` |
| `android/` | App nativo (casca) que roda o `pos.html` em modo quiosque e chama o pagamento da adquirente |
| `firmware/` | `beer_express_torneira` — torneira inteligente (tela + RFID, Beer Pass + copo) • `beer_express_esp32` — controlador central sem tela (copo, até 4 torneiras) |

A tela da maquininha fica **no servidor**. Mudou preço, texto, layout? Atualiza o `pos.html` e todas as maquininhas recebem na hora, **sem reinstalar nem re-homologar o APK**.

---

## 1. Qual maquininha usar

Precisa ser uma **maquininha smart (Android) que aceite app próprio**. As comuns (Point Mini/Pro, Moderninha Pro, Ton T1/T2) **não** rodam aplicativo de terceiros.

| | **Mercado Pago Point Smart 2** ⭐ recomendada | **PagBank Moderninha Smart 2** (alternativa) |
|---|---|---|
| Como o app entra | "SmartApp" do tipo *Main App*: vira a tela principal da maquininha, distribuição privada só para a sua empresa | SDK PlugPag (aberto no GitHub do PagSeguro) |
| Homologação | Sim — você envia o APK assinado + nº de série das maquininhas ao Mercado Pago | Necessário falar com o time de parceiros/desenvolvedores PagBank para liberar app em produção |
| Pix | Mesmo Mercado Pago: o servidor gera o QR e recebe a confirmação por webhook | Pix via API Mercado Pago também funciona (conta MP separada) |
| Segurança extra | O servidor **confere cada cartão na API do MP** pelo `external_reference` antes de liberar | Confia no retorno da maquininha (token exclusivo por aparelho) |

**Por que a Point Smart 2:** um único fornecedor para cartão + Pix + estorno automático pelo painel, e a documentação descreve exatamente o seu caso (app privado da própria empresa como tela principal).

**Antes de comprar várias:** peça ao comercial do Mercado Pago a liberação de SmartApp para a sua conta (é pré-requisito). Enquanto isso, teste tudo no **modo simulado** (abaixo) num celular Android qualquer.

---

## 2. Hardware da chopeira (por freezer)

| Item | Observação |
|---|---|
| Freezer horizontal + **termostato digital externo** (ex.: Full Gauge TIC-17 ou similar) | Chopp a 0–4 °C. O freezer sozinho congela o barril |
| Barril 30/50 L + válvula extratora + cilindro de CO₂ com regulador | Padrão do fornecedor do chopp |
| Torneira de chopp **com compensador** | Controla a espuma |
| **Válvula solenoide 12 V NF (normalmente fechada), atóxica/inox, própria para bebida** | Se faltar energia, fecha sozinha |
| **Fluxômetro de grau alimentício** (ex.: Digmesa FHKSC ou sensor de chopeira self-service) | Fica entre o barril e a torneira, dentro do freezer. O valor de pulsos/litro é calibrado no painel |
| ESP32 DevKit + módulo relé (ou MOSFET) + fonte 12 V/2 A com step-down 5 V | 1 ESP32 controla até 4 torneiras |
| Sensor DS18B20 à prova d'água + resistor 4k7 | Temperatura no painel e alerta no Telegram |
| Botão iluminado por torneira (opcional) + reed switch na tampa (opcional) | "Aperte para servir" / alerta de porta aberta |

**Ligações padrão** (mude no topo do `.ino`):

| Torneira | Válvula (saída) | Fluxômetro (entrada) | Botão | LED do botão |
|---|---|---|---|---|
| 1 | GPIO 26 | GPIO 34 | GPIO 18 | GPIO 23 |
| 2 | GPIO 27 | GPIO 35 | GPIO 19 | GPIO 25 |
| 3 | GPIO 14 | GPIO 32 | GPIO 21 | GPIO 13 |
| 4 | GPIO 12 | GPIO 33 | GPIO 22 | GPIO 15 |

DS18B20 → GPIO 4 · Porta → GPIO 5 · GPIO 34/35 precisam de pull-up externo de 10k para 3V3.

**Já tem uma controladora de chopeira que libera por pulso?** Cadastre a torneira no **modo "pulso"**: o ESP32 envia N pulsos (pelo pino da válvula, via optoacoplador) e você define quantos ml cada pulso libera.

---

## 3. Servidor (Hostinger) e deploy automático

Tudo sobe sozinho pelo GitHub (SSH + rsync, mesmo padrão do WashEasy e do Portaria Express): **push na `main` → portão (segredos, sintaxe, testes de ponta a ponta) → deploy → health**. O deploy cria as tabelas do banco e aplica `server/migrations/` sozinho — não precisa de phpMyAdmin.

Configuração única (banco, chave SSH, segredos, cron, administrador): **[docs/CONFIGURAR.md](docs/CONFIGURAR.md)**.

| Workflow (aba Actions) | O que faz |
|---|---|
| `gate` | Em todo push/PR: procura segredo no código, checa sintaxe PHP/JS e roda o fluxo completo (painel → maquininha → pagamento → ESP32) num MySQL de teste |
| `deploy` | Push em `server/` (ou manual, com opção "só simular"): backup do que está no ar → envio → `.env` → banco → health |
| `conferir` | Diagnóstico de 1 clique: SSH, pasta, rsync, PHP, banco, e se os arquivos sensíveis estão bloqueados na web |
| `monitor` | A cada 15 min: site, banco e cron. Caiu → e-mail do GitHub (+ WhatsApp com `ALERT_*`) |

No seu PC: `make gate` (mesmo portão), `make deploy-simular`, `make local`.

## 4. Telegram

1. No Telegram, fale com **@BotFather** → `/newbot` → copie o token.
2. Mande `/start` para o seu bot. Pegue o seu chat id com **@userinfobot** (para grupo: adicione o bot ao grupo; o id começa com `-100`).
3. Painel → **Configurações → Telegram** → cole token e chat id(s) → **Salvar e enviar teste**. Isso também ativa os comandos `/status`, `/hoje` e `/barris`.

Você recebe: cada venda (itens, forma de pagamento, cliente mascarado, total do dia), tentativa de compra por menor, maquininha bloqueada por tentativas inválidas, erro ao servir/pedido parcial, copo não retirado, pedido pago parado, chopeira/maquininha offline e online, temperatura fora da faixa, barril acabando, fluxo sem pedido (vazamento ou violação), troca de barril, estorno, liberação manual e o resumo diário às 23:55.

## 5. Cadastro no painel (ordem)

**Chopeiras e barris →** Pontos → Controladores ESP32 (gera a *chave do dispositivo*) → Bebidas → Torneiras (controlador, canal, bebida, tamanho do barril) → tamanhos e preços (ex.: 300 ml R$ 9,90 / 500 ml R$ 14,90) → Maquininhas (gera o *código de pareamento*).

**Como o cliente inicia o serviço** (por controlador):
- **Botão físico** — o LED da torneira pisca e o cliente aperta quando o copo estiver posicionado (recomendado).
- **Botão SERVIR na tela** — sem botão físico; o cliente toca na maquininha.
- **Automático** — começa logo após o pagamento (não recomendado: o copo pode não estar lá).

## 6. ESP32

1. Arduino IDE → instale a placa **esp32 (Espressif)** e as bibliotecas **WiFiManager (tzapu)**, **ArduinoJson 7**, **OneWire**, **DallasTemperature**.
2. Abra `firmware/beer_express_esp32/beer_express_esp32.ino`, confira os pinos e se o seu relé liga em HIGH ou LOW (`VALVE_ON`), grave.
3. O ESP cria a rede **BeerExpress-XXXX** (senha `beerexpress`). Conecte pelo celular, escolha o Wi-Fi do ponto e informe a **URL da API** (`https://SEU_DOMINIO/beerexpress/api.php`) e a **chave do dispositivo**.
4. Para reconfigurar: segure o botão **BOOT** por 5 s.
5. **Calibre cada torneira:** Painel → Torneira → *Liberar teste* 500 ml num copo medidor → *Calibrar* informando o volume real. Repita até ficar dentro de ±10 ml.

## 7. App da maquininha

O mesmo projeto gera 3 APKs (*flavors*):

| Flavor | Uso |
|---|---|
| `simulado` | Teste em qualquer celular/tablet Android, com aprovação/recusa simuladas |
| `mercadopago` | Point Smart / Point Smart 2 |
| `pagbank` | Moderninha Smart / Smart 2 |

1. Instale o **Android Studio**, abra a pasta `android/`.
2. Em `app/build.gradle` ajuste `POS_URL` (ex.: `https://SEU_DOMINIO/beerexpress/pos.html`) e `ADMIN_PIN`.
3. **Teste primeiro:** Build Variants → `simuladoDebug` → rode no seu celular. No painel, cadastre uma maquininha com adquirente "Simulado" e digite o código de pareamento no app. Faça o fluxo inteiro com o ESP32 ligado.
4. **Mercado Pago:** conta MP do seu CNPJ → contato comercial para SmartApps → baixe o *kit de integração* → coloque o `.aar` do SDK em `android/app/libs/` → informe o seu `CLIENT_ID` em `app/build.gradle` (flavor `mercadopago`) → teste na Point Smart de desenvolvimento → gere o APK **release assinado** (`assembleMercadopagoRelease`) → envie ao Mercado Pago com o nº de série de cada maquininha → após aprovação, o app é instalado como tela principal.
   Os imports do SDK estão em `app/src/mercadopago/.../ProviderFactory.kt`; se o kit vier com outro nome de pacote, ajuste só ali.
5. **PagBank:** flavor `pagbank` → informe o código de ativação da maquininha em `PAGBANK_ACTIVATION` → siga o processo de liberação de app com o PagBank.
6. **Menu técnico na maquininha:** toque 5 vezes rápido no canto superior esquerdo → PIN → recarregar, trocar URL, desparear ou sair.

Também dá para testar só a tela: abra `pos.html` no navegador do PC (com a maquininha "Simulado" pareada) — o pagamento é simulado por botões.

**Instalação remota:** os APKs são gerados pelo GitHub (workflow `apk`) e ficam em `/apk/`; a página `instalar.html` (com QR e botão de WhatsApp) guia quem estiver no ponto. Passo a passo: `docs/INSTALAR-MAQUININHA.md`.

## 8. Verificação de idade — como funciona

1. Cliente confirma ter 18+ (aceite de LGPD na tela), digita **CPF** (dígitos conferidos na hora) e **data de nascimento** (menor de 18 é barrado e você é avisado). Não pede nome.
2. O servidor **consulta a Receita** (CPF + nascimento) e só libera se bater. Resultado vale por 365 dias (configurável); depois reconsulta.
3. **Sem consulta configurada, a venda fica bloqueada** nas maquininhas reais (provedor `local` + `cpf_exigir_receita`=1) e o painel mostra alerta. Maquininhas em modo *simulado* ficam livres para teste.
4. 5 tentativas inválidas em 10 min → maquininha bloqueada por 10 min + alerta.
5. Limite de consumo por CPF por dia (padrão 3 L) e de copos por pedido (padrão 4).

**Provedor de consulta** (Painel → Configurações):
- `local` — só dígitos + idade declarada. Grátis, mas não comprova que a data de nascimento é verdadeira. Use apenas para testes.
- `nfeio` — **recomendado para começar**: a NFE.io revende a Consulta CPF do Serpro, envia CPF + nascimento e devolve o nome. É preciso habilitar a modalidade "Irrestrita" com o comercial deles.
- `serpro` — contrato direto com o Serpro (precisa de e-CNPJ). Confirme no manual do Serpro a URL da versão 3 e ajuste em *URL Serpro*.

**Limitação honesta:** nenhum sistema só com CPF impede que um menor use os dados de um adulto. As camadas que reduzem esse risco já estão no sistema (conferência na Receita, bloqueio por tentativas, limite diário, alertas). Para ir além: câmera apontada para a chopeira (como no Barista Express), avisos 18+ visíveis no ponto e, numa fase 2, biometria facial (Serpro Datavalid).

## 9. Antes de abrir o ponto

- [ ] Alvará / Vigilância Sanitária do local e autorização do estabelecimento anfitrião
- [ ] Atividade de comércio de bebidas no seu CNPJ (confira se a ocupação do MEI permite) e emissão de nota
- [ ] Aviso "Proibida a venda para menores de 18 anos" visível no quiosque (Lei 13.106/2015)
- [ ] Texto de privacidade (LGPD) disponível: o sistema guarda CPF, nome e nascimento para cumprir a lei
- [ ] Calibração de todas as torneiras e teste de "sem fluxo" (feche o CO₂ e veja o alerta chegar)
- [ ] Teste de queda de internet e de energia no meio de um copo (o sistema reporta o parcial)
- [ ] Telegram recebendo vendas e alertas

Recomendo validar com um advogado o modelo de venda autônoma de bebida alcoólica no DF antes de escalar.
