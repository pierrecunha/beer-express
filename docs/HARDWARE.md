# Hardware e instalação — Beer Express (modelo Beer Pass)

Dois jeitos de vender, que funcionam juntos no mesmo ponto:

| | **Beer Pass** (igual BeerPass/myTapp) | **Copo fechado** |
|---|---|---|
| Como paga | Recarrega crédito na maquininha (cartão ou Pix) — **backlog, desligado** | Paga o copo (tamanhos e preços do painel) na maquininha da torneira |
| Como serve | Aproxima o cartão/pulseira na torneira e se serve à vontade | Toca SERVIR e a torneira libera o volume exato |
| Cobrança | Por ml servido (R$/litro), debitado do saldo | Valor fixo do copo |
| Verificação 18+ | Uma vez, no cadastro (CPF + nascimento + nome) | "Sim, concordo" (igual BeerPass) ou CPF + nascimento — escolha no painel |

## 1. Maquininha — uma por torneira (igual BeerPass)

**Compre: PagBank Moderninha Smart 2**, a mesma usada pelo BeerPass. Fica presa num suporte logo acima da torneira e a **tela dela é a tela da torneira**: foto do chopp, tipo, teor alcoólico, IBU e valor por 100 ml. O cliente toca na imagem → aceita os termos 18+ → escolhe o tamanho → quantidade e cupom → paga (crédito, débito ou Pix na própria maquininha) → puxa a alavanca da torneira.

- Painel → Maquininhas → **Presa à torneira** = a torneira dela. Sem torneira definida, a maquininha mostra o cardápio do ponto inteiro (útil para 1 maquininha servindo várias torneiras).
- Painel → Configurações → **Pix = "Na própria maquininha"** (PagBank) — não precisa de conta Mercado Pago.
- App: flavor `pagbank` (SDK PlugPag). O app precisa ser liberado pelo PagBank para rodar em produção — fale com o time de parceiros/desenvolvedores do PagBank antes de comprar várias.
- Alternativa: Mercado Pago Point Smart 2 (flavor `mercadopago`; Pix pelo QR do servidor).
- **Não servem:** Moderninha Pro, Point Pro/Mini, Ton T1/T2 (não rodam app próprio).
- Monte a maquininha num suporte com o carregador ligado (é portátil, com bateria).

### Como o cliente se serve (modo alavanca)
Painel → Controladores → **Como o cliente se serve = Alavanca da torneira**. Depois do pagamento a válvula é liberada; o cliente inclina o copo e puxa a alavanca normalmente; o fluxômetro **fecha a válvula no volume pago**. Se ninguém puxar a alavanca em 2 min (configurável), o copo fica como "tempo esgotado" e você pode reliberar ou estornar pelo painel.

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
