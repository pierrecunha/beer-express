# Hardware e instalação — Beer Express (modelo Beer Pass)

Dois jeitos de vender, que funcionam juntos no mesmo ponto:

| | **Beer Pass** (igual BeerPass/myTapp) | **Copo fechado** |
|---|---|---|
| Como paga | Recarrega crédito na maquininha (cartão ou Pix) | Paga o copo (300/500 ml) na maquininha |
| Como serve | Aproxima o cartão/pulseira na torneira e se serve à vontade | Toca SERVIR e a torneira libera o volume exato |
| Cobrança | Por ml servido (R$/litro), debitado do saldo | Valor fixo do copo |
| Verificação 18+ | Uma vez, no cadastro (CPF + nascimento + nome) | A cada compra (CPF + nascimento) |

## 1. Maquininha (1 por ponto) — totem de recarga + venda de copo

**Compre: Mercado Pago Point Smart 2.** Android, tela touch, 4G/Wi-Fi, Pix por QR. É a única do Mercado Pago que roda app próprio (SmartApp "Main App", distribuição privada para a sua empresa, após homologação).
Por que Mercado Pago: um só fornecedor para cartão, Pix pela API (QR na tela) e estorno automático pelo painel; o servidor confere cada pagamento na API.

- Alternativa: **PagBank Moderninha Smart 2** (garantia maior; app via PlugPag — o projeto já tem o flavor `pagbank`).
- **Não servem:** Point Pro/Mini/Air, Moderninha Pro, Ton T1/T2 (não rodam app de terceiros).
- Antes de comprar várias: peça ao comercial do Mercado Pago a liberação de SmartApp na sua conta. Teste tudo antes com o app `simulado` num celular.
- Fixe a maquininha num suporte com carregador (ela é portátil, com bateria).

## 2. Torneira inteligente (1 kit por torneira) — o "BeerPass" de baixo custo

| Peça | Para quê | Observação |
|---|---|---|
| **ESP32-2432S028R ("Cheap Yellow Display")** | Cérebro + tela 2,8" da torneira | Mostra chopp, R$/L, ml e R$ ao vivo. Já tem Wi-Fi e LED RGB |
| **Leitor RFID RC522 (13,56 MHz)** | Lê cartão/pulseira | Ligado no conector do cartão SD da placa |
| **Cartões ou pulseiras MIFARE 13,56 MHz** | Identificam o cliente | Compre em lote; o cliente vincula o seu na maquininha |
| **Válvula solenoide 12 V, NF (normalmente fechada), atóxica/inox, para bebida** | Abre/fecha o chopp | NF = sem energia, fecha sozinha |
| **Fluxômetro de grau alimentício** (ex.: Digmesa ou sensor de chopeira self-service) | Mede os ml | Calibre no painel (± 10 ml) |
| Módulo relé 1 canal ou MOSFET + diodo | Aciona a solenoide | |
| Fonte 12 V 2 A + step-down 5 V | Alimenta válvula e placa | |
| DS18B20 à prova d'água + resistor 4k7 | Temperatura no painel/Telegram | Um por freezer basta |
| Caixa/suporte para a tela acima da torneira | | Proteja de respingo |

Sem leitor de cartão? Dá para usar só o **controlador central** (`firmware/beer_express_esp32`, até 4 torneiras, sem tela) no modo copo fechado.

### Onde vai cada coisa
```
Barril ── válvula extratora ── [dentro do freezer: FLUXÔMETRO → SOLENOIDE] ── torneira com compensador
                                         │ pulsos            │ 12 V via relé
                                         └──── ESP32 CYD (tela + RC522) acima da torneira ──── Wi-Fi ──── servidor
```
O fluxômetro e a solenoide ficam na linha do chopp, **antes da torneira e no frio** (menos espuma, medição melhor).

### Ligações da CYD
| CYD | Ligação |
|---|---|
| GPIO 5 / 18 / 23 / 19 | RC522 SDA / SCK / MOSI / MISO (RST do RC522 no 3V3) |
| GPIO 35 | Sinal do fluxômetro (resistor 10k para 3V3) |
| GPIO 22 | Entrada do relé/MOSFET da solenoide |
| GPIO 27 | DS18B20 (4k7 para 3V3) |
| 3V3 / GND | RC522, sensores |

## 3. Freezer
Freezer horizontal + **termostato digital externo** (0–4 °C, o freezer sozinho congela o chopp), barril 30/50 L, CO₂ com regulador, torneira com compensador.

## 4. Passo a passo da instalação
1. **Painel → Chopeiras e barris → Controladores** → Adicionar, tipo **Torneira inteligente** → copie a chave.
2. **Torneiras** → canal 1, bebida, **preço por litro** (Beer Pass) e/ou **tamanhos de copo**.
3. Grave `firmware/beer_express_torneira` na CYD (bibliotecas listadas no topo do arquivo). Na 1ª vez ela cria a rede `BeerTap-XXXX` → Wi-Fi + URL da API + chave.
4. **Calibre:** Torneira → *Liberar teste* 500 ml num copo medidor → *Calibrar*.
5. **TV (BeerList):** qualquer Smart TV/TV box com navegador → `https://lavafacilexpress.com/beerexpress/tv.html?loc=ID_DO_PONTO` (o link está em Pontos).
6. Teste: cadastro 18+ na maquininha → recarga → aproxime um cartão novo na torneira → digite o código na maquininha (Cartão) → aproxime de novo e sirva-se.

## 5. Como o Beer Pass protege o seu dinheiro
- A torneira só abre com saldo; o limite da servida é o que o saldo paga (e no máximo 1 L por vez, configurável).
- A válvula fecha **dentro do ESP32** ao atingir o limite — mesmo sem internet.
- O mesmo saldo não é usado em duas torneiras ao mesmo tempo (reserva).
- Se a torneira reiniciar ou perder conexão, a servida é fechada cobrando o último volume medido; o cron fecha servidas sem notícia em 10 min.
- Fluxo sem cliente (vazamento ou violação) gera alerta no Telegram.
- Limite diário por CPF continua valendo (copo + Pass).
