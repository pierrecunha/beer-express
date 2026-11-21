# Instalar o app na maquininha (Sunmi P2 / Stone)

## Uma vez só (você, no GitHub/painel)
1. **APK de teste (simulado):** já é gerado sozinho (Actions → `apk`). Serve para testar o fluxo inteiro sem cobrar cartão.
2. **APK com cartão real da Stone:** peça à Stone o *token do repositório privado do SDK (PackageCloud)* e grave em
   GitHub → Settings → Secrets → Actions → `PACKAGECLOUD_READ_TOKEN`. Rode o workflow `apk` de novo: aparece `beerexpress-stone.apk`.
   > O código da integração Stone foi escrito conforme o SDK 4.x, mas só se confirma compilando e testando numa P2 real. Se o build falhar, o log do Actions mostra onde.
3. **Receita:** em Painel → Configurações, escolha NFE.io ou Serpro e cole a chave. Sem isso, as vendas nas maquininhas reais ficam bloqueadas (de propósito: proibido vender a menores).
4. No painel (Mais → Equipamentos → Maquininhas): cadastre a maquininha, escolha *Stone*, preencha o **Stone Code** (opcional) e anote o **código de pareamento**.

## Na maquininha (ou por quem está no ponto)
1. Abra `…/beerexpress/instalar.html` (QR code ou link por WhatsApp) e baixe o APK.
2. Instale (permitir fonte desconhecida se pedir) e abra.
3. O assistente guia: **Wi-Fi → código de pareamento → Stone Code → PIN**. Pronto.

## Depois
- Painel → Maquininhas: botões **Recarregar** e **Reiniciar** à distância.
- Atualizar o app: mexeu em `android/`, o GitHub gera o APK novo; instale por cima (mesma assinatura, guardada no servidor em `~/beerexpress_keys`).
- Refazer instalação: 5 toques no canto superior esquerdo → PIN → *Refazer instalação*.
