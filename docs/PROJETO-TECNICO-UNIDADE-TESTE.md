# Projeto técnico — Estação de Chopp Self-Service (2 torneiras)

Unidade do Beer Express onde será testada toda a automação junto com o sistema.
Fonte: "Projeto Técnico - Chopeira Self-Service" (PDF de 4 páginas, guardado em `docs/projeto-tecnico-unidade-teste.pdf`).

Especificação técnica e lista de materiais (BOM). Arquitetura de hardware, linha hidráulica alimentícia e integração via Bluetooth Low Energy (BLE) com o terminal **Moderninha Smart 2**.

O software de pagamento e a interface rodam embarcados no terminal Moderninha Smart 2 (tela touch, leitor NFC/cartão/Pix, 4G/Wi-Fi e bateria interna). O hardware adicional é só o gabinete refrigerado, a linha hidráulica/gás e uma placa controladora escrava baseada em ESP32.

## 1. Lista de compras (BOM) por estação

### Bloco A — Refrigeração e controle térmico

| Item | Especificação recomendada | Qtd | Faixa de preço |
|---|---|---|---|
| Freezer / conservador porta cega | Imbera EVZ21 MAX (594 L) ou Gelopar GPC-57 (577 L), tripla ação, 220 V, porta sólida sem visor | 1 | R$ 4.900 – 5.600 |
| Controlador digital externo | Full Gauge MT-512E 2HP, trava a temperatura interna entre -1,5 °C e 0 °C sem congelar a linha | 1 | R$ 140 – 180 |

### Bloco B — Linha de chopp e gás (hidráulica alimentícia)

| Item | Especificação recomendada | Qtd | Faixa de preço |
|---|---|---|---|
| Torneira italiana c/ compensador | Cromada ou inox, com shank (prolongador) de 10 a 15 cm para atravessar a porta | 2 | R$ 380 – 480 |
| Válvula extratora de barril | Tipo S (padrão nacional) com alívio de pressão integrado | 2 | R$ 280 – 360 |
| Cilindro de CO₂ | 4 kg ou 6 kg em alumínio (fica dentro do freezer com os barris) | 1 | R$ 550 – 750 |
| Regulador de pressão duplo | 1 entrada de alta (manômetro do cilindro) + 2 saídas independentes com manômetro | 1 | R$ 320 – 420 |
| Mangueira atóxica rígida | Tubo 3/8" (9,5 mm) ou 5/16", polietileno/DMfit alimentício, rolo de 5 m | 5 m | R$ 40 – 60 |
| Conexões engate rápido | Kit John Guest / DMfit (espigões rosca 1/2" e 3/8", sem abraçadeiras) | 12 | R$ 120 – 180 |
| Bandeja pingadeira de inox c/ dreno | De sobrepor na porta (40 a 50 cm), com niple/saída de dreno inferior | 1 | R$ 220 – 310 |
| Kit dreno e bombona coletora | Mangueira cristal/silicone 1/2" ou 3/8" (2 m) + passa-muro + bombona de 5 ou 10 L dentro do freezer | 1 | R$ 45 – 75 |
| Dispensador de copos | Tubo vertical inox ou acrílico, com suporte lateral ou frontal na porta | 1 | R$ 90 – 140 |

### Bloco C — Eletrônica de automação e telemetria (módulo controlador)

| Item | Especificação recomendada | Qtd | Faixa de preço |
|---|---|---|---|
| Microcontrolador | ESP32-WROOM-32D ou ESP32-S3, com Wi-Fi e BLE | 1 | R$ 35 – 55 |
| Válvula solenoide alimentícia | 12 V DC, normalmente fechada (NF), ação direta, inox 304 ou POM/PP, rosca 3/8" ou 1/2" | 2 | R$ 140 – 240 |
| Sensor de fluxo (caudalímetro) | Turbina efeito Hall alimentícia de alta resolução (ex.: Digmesa FHKSC, Gems FT-110 ou YF-S401 calibrado, rosca 3/8") | 2 | R$ 160 – 380 |
| Driver MOSFET / módulo relé | Placa de 2 canais MOSFET (IRLZ44N) ou relé 5 V/12 V optoacoplado, com diodo flyback contra ruído EMI | 1 | R$ 25 – 45 |
| Sensor de temperatura | Sonda DS18B20 à prova d'água (cabo 2 m): telemetria térmica interna e proteção anticongelamento | 1 | R$ 15 – 25 |
| Fonte chaveada 12 V | 12 V / 3 A ou 5 A estabilizada (Mean Well ou colmeia industrial) | 1 | R$ 45 – 70 |
| Conversor step-down (buck) | LM2596 ou MP1584 (12 V → 5 V regulados para o ESP32) | 1 | R$ 12 – 20 |
| Caixa hermética IP65 | ~15 × 10 × 7 cm + prensa-cabos PG7/PG9, contra condensação | 1 | R$ 35 – 55 |

### Bloco D — Fixação do terminal e acabamento

| Item | Especificação recomendada | Qtd | Faixa de preço |
|---|---|---|---|
| Suporte antifurto + cabo | Suporte fixo para Moderninha Smart 2 (parafusado na porta) + cabo USB-C de 2 m ligado à fonte 5 V | 1 | R$ 90 – 160 |
| Envelopamento vinílico | Vinil automotivo fosco com laminação protetora, aplicação completa no gabinete | 1 | R$ 400 – 650 |

## 2. Resumo financeiro estimado (1 unidade completa)

| Categoria | Custo médio |
|---|---|
| Bloco A — Freezer porta cega tripla ação + controlador térmico | R$ 5.200,00 |
| Bloco B — Kit hidráulico, gás e drenagem completo (2 vias + CO₂ + bombona) | R$ 2.250,00 |
| Bloco C — Kit eletrônico de automação e telemetria (ESP32 + solenoides + fluxo) | R$ 580,00 |
| Bloco D — Suporte Moderninha Smart 2 + envelopamento | R$ 600,00 |
| **Investimento total estimado por estação pronta** | **R$ 8.630,00** |

## 3. Arquitetura de software e comunicação (Moderninha Smart 2 ↔ ESP32)

Com o app Android embarcado na Moderninha Smart 2, o projeto recomenda arquitetura mestre-escravo via BLE local para acionamento instantâneo, usando o 4G/Wi-Fi da maquininha para sincronizar com a nuvem:

1. **Liberação de baixa latência (BLE GATT Server).** O ESP32 anuncia um serviço BLE dedicado com pareamento autenticado (ex.: `CHOPP_UNIT_01`). Quando o SDK do PagBank confirma `TRANSACTION_APPROVED`, o app envia `{"cmd": "OPEN", "tap": 1, "max_ml": 500, "timeout_s": 45}`. A abertura ocorre em menos de 100 ms, sem depender de rede externa.
2. **Telemetria de envase em tempo real.** O ESP32 lê os pulsos do sensor de fluxo por interrupção de hardware (`attachInterrupt`) e envia notificações BLE a cada 25 mL servidos (Characteristic Notify). O app mostra uma barra de progresso na tela da Moderninha.
3. **Fechamento autônomo (fail-safe).** Ao atingir `max_ml`, expirar `timeout_s` ou receber toque de parada do cliente, o ESP32 desenergiza a solenoide na hora e devolve o balanço final (`{"status": "DONE", "served_ml": 485}`), para o app registrar baixa de estoque e faturamento na nuvem.

## 4. Montagem física e hidráulica

```
[INTERIOR DO FREEZER (-1,5 °C a 0 °C)]

[Cilindro CO2] ---> [Regulador Duplo]
                          │
             ┌────────────┴────────────┐
             ▼                         ▼
       [Barril 1]                [Barril 2]
      (Extratora S)             (Extratora S)
             │                         │
             ▼                         ▼
     [Sensor Fluxo 1]          [Sensor Fluxo 2]   <-- INSTALAR SEMPRE ANTES DA SOLENOIDE
             │                         │
             ▼                         ▼
     [Solenoide 12V 1]         [Solenoide 12V 2]
             │                         │
═════════════╪═════════════════════════╪═════════ (PAREDE DA PORTA DO FREEZER)
             ▼                         ▼
     [Torneira It. 1]          [Torneira It. 2]
             │                         │
      [Bandeja Pingadeira Inox c/ Dreno na Porta]
                       │
═══════════════════════╪═══════════════════════ (FURO PASSA-MURO NA PORTA)
                       ▼
       [Mangueira de Dreno 1/2"] ---> [Bombona Coletora Interna (5L/10L)]
```

### Diretrizes críticas de instalação em campo

1. **Sequência hidráulica obrigatória:** barril → sensor de fluxo → válvula solenoide → torneira. O sensor no trecho pressurizado, antes da solenoide, impede a descompressão do CO₂ dentro da turbina e evita ler espuma como líquido.
2. **Drenagem da pingadeira para a bombona:** bandeja a cerca de 25 a 30 cm abaixo do bico das torneiras; furo passa-muro inclinado na porta para a mangueira descer por gravidade até a bombona interna. Deixe folga na mangueira para a porta abrir inteira sem dobrar ou esticar, e sele o furo com silicone acético para não perder frio.
3. **Ergonomia de furação:** torneiras entre 1,35 m e 1,45 m do piso, com o suporte da Moderninha logo acima ou ao lado.
4. **Cabeamento e isolamento da fonte:** nunca perfure as paredes laterais do gabinete (passam as serpentinas do condensador). Passe os fios da porta pela dobradiça superior com conduíte espiralado e fixe a fonte 12 V no compartimento traseiro externo, junto ao compressor, levando só a linha 12 V DC para dentro da caixa hermética IP65.

---

## Anotações do projeto (não fazem parte do PDF)

- Esta unidade usa **Moderninha Smart 2 (PagBank) + ESP32 por BLE**. O `docs/HARDWARE.md` atual descreve outra arquitetura: ESP32 com tela e leitor de cartão por torneira, ligado ao servidor por Wi-Fi. Na hora de testar, a ponte entre o app da maquininha e o ESP32 precisa ser definida (BLE direto, como no projeto, ou via servidor/Wi-Fi, como no sistema hoje).
- O app Android do repositório já tem a variante `pagbank`.
