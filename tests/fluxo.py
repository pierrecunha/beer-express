#!/usr/bin/env python3
"""Fluxo completo: admin cadastra tudo → maquininha identifica cliente → paga → ESP32 serve → painel/estorno."""
import json, sys, urllib.request, urllib.error

A = sys.argv[1]
falhas = 0

def call(rota, body=None, h=None, metodo=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(f"{A}?r={rota}", data=data, method=metodo or ("POST" if data else "GET"),
                                 headers={"Content-Type": "application/json", **(h or {})})
    try:
        with urllib.request.urlopen(req, timeout=20) as r: return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or b"{}")

def ok(cond, nome, extra=""):
    global falhas
    print(("  ✅ " if cond else "  ❌ ") + nome + ("" if cond else f"  → {extra}"))
    if not cond: falhas += 1

print("── painel")
st, r = call("admin/setup", {"nome": "Teste", "email": "admin@teste.com", "senha": "senha12345"})
ok(st == 200 and r.get("token"), "cria administrador", r)
AD = {"Authorization": "Bearer " + r.get("token", "")}
st, r = call("admin/setup", {"nome": "X", "email": "x@x.com", "senha": "senha12345"})
ok(st == 403, "setup não pode rodar 2x", r)
def save(ent, data): return call("admin/save", {"entity": ent, "data": data}, AD)[1].get("id")
loc = save("locations", {"nome": "Ponto Teste"})
dev = save("devices", {"location_id": loc, "nome": "Freezer 1", "start_mode": "pos"})
term = save("terminais", {"location_id": loc, "nome": "POS 1", "provider": "simulado"})
prod = save("produtos", {"nome": "Pilsen", "estilo": "Pilsen", "abv": 4.8})
tap = save("torneiras", {"device_id": dev, "canal": 1, "nome": "Torneira 1", "product_id": prod, "barril_ml": 30000})
o300 = save("ofertas", {"tap_id": tap, "volume_ml": 300, "preco": 9.9, "ordem": 1})
o500 = save("ofertas", {"tap_id": tap, "volume_ml": 500, "preco": 14.9, "ordem": 2})
ok(all([loc, dev, term, prod, tap, o300, o500]), "cadastros")
cat = call("admin/catalog", h=AD)[1]
KEY = next(d["device_key"] for d in cat["devices"] if d["id"] == dev)
COD = next(t["codigo"] for t in cat["terminais"] if t["id"] == term)

print("── maquininha")
st, r = call("pos/pair", {"codigo": COD, "serial": "TESTE"})
ok(st == 200 and r.get("token"), "pareamento", r)
T = {"X-Terminal-Token": r.get("token", "")}
D = {"X-Device-Key": KEY}
m = call("pos/menu", h=T)[1]
ok(m["torneiras"][0]["disponivel"] is False, "chopeira offline → indisponível")
call("dev/poll&temp=3.1&rssi=-60&fw=teste", h=D)
m = call("pos/menu", h=T)[1]
ok(m["torneiras"][0]["disponivel"] is True, "chopeira online → disponível")
st, r = call("pos/identify", {"cpf": "529.982.247-25", "nascimento": "01/01/2015", "declara_maior": True}, T)
ok(r.get("erro") == "menor_idade", "menor de idade bloqueado", r)
st, r = call("pos/identify", {"cpf": "111.111.111-11", "nascimento": "01/01/1990", "declara_maior": True}, T)
ok(r.get("erro") == "cpf_invalido", "CPF inválido", r)
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "10/05/1990", "declara_maior": False}, T)
ok(r.get("erro") == "sem_declaracao", "sem declaração 18+", r)
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "10/05/1990", "declara_maior": True}, T)
ok(r.get("need_name") is True, "1º acesso pede nome", r)
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "10/05/1990", "declara_maior": True, "nome": "Pierre Silva"}, T)
ok(r.get("ok") and r.get("token"), "identifica com nome", r)
VT = r.get("token")
st, r = call("pos/order", {"token": VT, "metodo": "credito", "itens": [{"offer_id": o500, "qtd": 1}, {"offer_id": o300, "qtd": 1}]}, T)
ok(st == 200 and abs(r.get("total", 0) - 24.8) < 0.001 and r.get("total_centavos") == 2480, "pedido R$ 24,80", r)
REF = r.get("ref")
st, r = call("pos/paid", {"ref": REF, "metodo": "credito", "bandeira": "VISA", "final_cartao": "1234"}, T)
ok(r.get("status") == "pago", "pagamento aprovado", r)

print("── ESP32")
r = call("dev/poll", h=D)[1]
c = r.get("cmd") or {}
ok(c.get("volume_ml") == 500 and c.get("go") is False and c.get("pulsos_alvo") == 963, "copo 1 armado (aguarda SERVIR)", c)
call("pos/serve", {"dispense_id": c.get("id")}, T)
c = call("dev/poll", h=D)[1].get("cmd") or {}
ok(c.get("go") is True, "SERVIR na tela libera", c)
call("dev/report", {"id": c["id"], "status": "servindo", "pulsos": 400, "servido_ml": 200}, D)
s = call(f"pos/order_status&ref={REF}", h=T)[1]
ok(s["copos"][0]["servido_ml"] == 200 and s["status"] == "servindo", "progresso na tela", s)
call("dev/report", {"id": c["id"], "status": "concluido", "pulsos": 963, "servido_ml": 500}, D)
c2 = call("dev/poll", h=D)[1].get("cmd") or {}
ok(c2.get("volume_ml") == 300 and c2.get("id") != c.get("id"), "copo 2 entra na fila", c2)
call("dev/report", {"id": c2["id"], "status": "erro", "pulsos": 5, "servido_ml": 5, "erro": "sem_fluxo"}, D)
s = call(f"pos/order_status&ref={REF}", h=T)[1]
ok(s["status"] == "parcial", "falha no copo 2 → pedido parcial", s["status"])

print("── painel (pós-venda)")
st, r = call("admin/dispense_retry", {"id": c2["id"]}, AD)
ok(r.get("volume_ml") == 295, "relibera o que faltou", r)
c3 = call("dev/poll", h=D)[1].get("cmd") or {}
ok(c3.get("volume_ml") == 295, "reliberação chega ao ESP32", c3)
call("pos/serve", {"dispense_id": c3.get("id")}, T)
call("dev/report", {"id": c3["id"], "status": "concluido", "pulsos": 541, "servido_ml": 295}, D)
s = call(f"pos/order_status&ref={REF}", h=T)[1]
ok(s["status"] == "concluido", "pedido concluído após reliberar", s["status"])
d = call("admin/dashboard", h=AD)[1]
ok(d.get("ok") and int(d["hoje"]["n"]) == 1 and abs(float(d["hoje"]["s"]) - 24.8) < 0.01, "dashboard soma a venda", d.get("hoje"))
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "1990-05-10", "declara_maior": True}, T)
ok(r.get("ok") and r.get("compras") == 1, "cliente conhecido entra só com CPF + nascimento", r)
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "11/05/1990", "declara_maior": True}, T)
ok(r.get("erro") == "dados_nao_conferem", "nascimento diferente do cadastro", r)
st, r = call("admin/calibrate", {"tap_id": tap, "ml_esperado": 500, "ml_real": 520}, AD)
ok(r.get("depois") and r["depois"] < r["antes"], "calibração ajusta pulsos/L", r)
st, r = call("admin/manual_release", {"tap_id": tap, "volume_ml": 200, "motivo": "teste"}, AD)
c4 = call("dev/poll", h=D)[1].get("cmd") or {}
ok(c4.get("volume_ml") == 200 and c4.get("go") is True, "liberação manual sai liberada", c4)
call("dev/report", {"id": c4["id"], "status": "concluido", "pulsos": 370, "servido_ml": 200}, D)
o = call("admin/orders", h=AD)[1]
pid = next(p["id"] for p in o["pedidos"] if p["ref"] == REF)
st, r = call("admin/order_refund", {"id": pid}, AD)
ok(r.get("ok") and r.get("automatico") is False, "estorno (manual, sem MP)", r)

print("── foto do chopp pelo painel")
import base64, zlib, struct
def png(w, h):
    raw = b"".join(b"\x00" + b"\xf5\xb3\x01" * w for _ in range(h))
    ch = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + ch(b"IDAT", zlib.compress(raw)) + ch(b"IEND", b"")
st, r = call("admin/product_image", {"id": prod, "imagem": "data:image/png;base64," + base64.b64encode(png(80, 80)).decode()}, AD)
ok(r.get("ok") and r["imagem_url"].startswith("uploads/produtos/p%d_" % prod), "envia foto da bebida", r)
m = call("pos/menu", h=T)[1]
ok(m["torneiras"][0]["imagem_url"] == r.get("imagem_url"), "foto aparece no cardápio da maquininha", m["torneiras"][0].get("imagem_url"))
st, r2 = call("admin/product_image", {"id": prod, "imagem": "data:image/png;base64," + base64.b64encode(b"<?php echo 1; ?>" * 10).decode()}, AD)
ok(r2.get("erro") == "imagem", "recusa arquivo que não é imagem", r2)

print("── Beer Pass desligado (backlog)")
st, r = call("dev/card", {"uid": "04FFFFFFFF", "canal": 1}, D)
ok(r.get("motivo") == "pass_off", "com Pass desligado, cartão não abre torneira", r)
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "10/05/1990", "declara_maior": True}, T)
w = call("pos/wallet", {"token": r.get("token")}, T)[1]
ok(w.get("pass") is False, "maquininha não mostra recarga", w.get("pass"))
call("admin/settings_save", {"settings": {"pass_ativo": "1"}}, AD)

print("── Modo Pass (crédito pré-pago + cobrança por ml)")
save("torneiras", {"id": tap, "preco_litro": 30.0, "max_ml_servida": 1000})
UID = "04A1B2C3D4E5F6"
st, r = call("dev/card", {"uid": UID, "canal": 1}, D)
ok(r.get("motivo") == "cartao_novo" and len(r.get("codigo", "")) == 4, "cartão novo gera código de vínculo", r)
COD4 = r.get("codigo")
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "10/05/1990", "declara_maior": True}, T)
VT2 = r.get("token")
st, r = call("pos/link_card", {"token": VT2, "codigo": COD4}, T)
ok(r.get("ok") and r.get("final") == "•••E5F6", "vincula cartão pela maquininha", r)
st, r = call("pos/link_card", {"token": VT2, "codigo": COD4}, T)
ok(r.get("erro") == "codigo", "código não reaproveita", r)
st, r = call("dev/card", {"uid": UID, "canal": 1}, D)
ok(r.get("motivo") == "saldo_insuficiente", "sem saldo não abre", r)
st, r = call("pos/recharge", {"token": VT2, "valor": 37, "metodo": "credito"}, T)
ok(r.get("erro") == "valor", "valor fora da lista recusado", r)
st, r = call("pos/recharge", {"token": VT2, "valor": 100, "metodo": "credito"}, T)
ok(r.get("bonus") == 5 and r.get("total_centavos") == 10000, "recarga R$100 ganha R$5 de bônus", r)
RC = r.get("ref")
call("pos/paid", {"ref": RC, "metodo": "credito", "bandeira": "MASTER", "final_cartao": "9999"}, T)
w = call("pos/wallet", {"token": VT2}, T)[1]
ok(abs(w.get("saldo", 0) - 105) < 0.001 and len(w.get("cartoes", [])) == 1, "saldo R$105 e 1 cartão", w)
st, r = call("pos/recharge", {"token": VT2, "valor": 20, "metodo": "pix"}, T)
ok(r.get("pix", {}).get("simulado") is True, "recarga por Pix (simulado)", r)
RC2 = r.get("ref")
call("pos/paid", {"ref": RC2, "metodo": "pix"}, T)
st, r = call("dev/card", {"uid": UID, "canal": 1}, D)
ok(r.get("ok") and r.get("limite_ml") == 1000 and r.get("preco_litro") == 30.0, "cartão abre a torneira (limite 1 L)", r)
POUR = r.get("pour_id")
call("dev/pour", {"pour_id": POUR, "ml": 200, "pulsos": 367}, D)
tv = call("pub/tv&loc=%d" % loc)[1]
ok(tv["torneiras"][0]["servindo_ml"] == 200 and tv["torneiras"][0]["preco_litro"] == 30.0, "BeerList/TV mostra servindo ao vivo", tv["torneiras"][0])
st, r = call("dev/card", {"uid": UID, "canal": 1}, D)
ok(r.get("pour_id") == POUR, "reaproximar o cartão retoma a mesma servida", r)
st, r = call("dev/pour", {"pour_id": POUR, "ml": 420, "pulsos": 770, "fim": True, "motivo": "inatividade"}, D)
ok(abs(r.get("valor", 0) - 12.6) < 0.001 and abs(r.get("saldo", 0) - 112.4) < 0.001, "cobra só 420 ml (R$12,60) → saldo R$112,40", r)
st, r = call("dev/pour", {"pour_id": POUR, "ml": 999, "fim": True}, D)
ok(r.get("fechada") and abs(r.get("saldo", 0) - 112.4) < 0.001, "fechamento repetido não cobra 2x", r)
cid = call("admin/customers", h=AD)[1]["clientes"][0]["id"]
cd = call(f"admin/customer&id={cid}", h=AD)[1]
ok([e["tipo"] for e in cd["extrato"]][:4] == ["consumo", "recarga", "bonus", "recarga"], "extrato: recarga, bônus, consumo", [e["tipo"] for e in cd["extrato"]])
o = call("admin/orders", h=AD)[1]
rid = next(p["id"] for p in o["pedidos"] if p["ref"] == RC2)
st, r = call("admin/order_refund", {"id": rid}, AD)
w = call("pos/wallet", {"token": VT2}, T)[1]
ok(abs(w["saldo"] - 92.4) < 0.001, "estorno da recarga de R$20 sai da carteira", w["saldo"])
st, r = call("admin/wallet_adjust", {"customer_id": cid, "valor": "-92,40", "obs": "teste zerar"}, AD)
ok(abs(r.get("saldo", 1)) < 0.001, "ajuste manual na carteira", r)
d = call("admin/dashboard", h=AD)[1]
ok(int(d["pass"]["n"]) == 1 and int(d["pass"]["ml"]) == 420, "dashboard mostra servidas por ml", d.get("pass"))
call("pos/logout", {"token": VT2}, T)
st, r = call("pos/wallet", {"token": VT2}, T)
ok(r.get("erro") == "sessao", "sair encerra a sessão do cliente", r)

print("── fluxo BeerPass: maquininha por torneira, IBU, valor 100 ml, cupom, declaração 18+")
save("produtos", {"id": prod, "cervejaria": "Haisten Beer", "ibu": 10})
term2 = save("terminais", {"location_id": loc, "tap_id": tap, "nome": "POS Torneira 1", "provider": "simulado"})
cat = call("admin/catalog", h=AD)[1]
COD2 = next(x["codigo"] for x in cat["terminais"] if x["id"] == term2)
T2 = {"X-Terminal-Token": call("pos/pair", {"codigo": COD2, "serial": "T2"})[1].get("token", "")}
cfg = call("pos/config", h=T2)[1]
ok(cfg.get("tap_id") == tap and cfg.get("verificacao") == "cpf", "maquininha presa à torneira 1", cfg.get("tap_id"))
m = call("pos/menu", h=T2)[1]["torneiras"]
ok(len(m) == 1 and m[0]["ibu"] == 10 and m[0]["cervejaria"] == "Haisten Beer" and m[0]["preco_100ml"] == 2.98, "tela da torneira: IBU, cervejaria e valor 100 ml", m[0] if m else m)
call("admin/save", {"entity": "cupons", "data": {"codigo": "beer10", "descricao": "10% off", "tipo": "percentual", "valor": 10, "minimo": 10, "ativo": 1}}, AD)
call("admin/save", {"entity": "cupons", "data": {"codigo": "CORTESIA", "tipo": "percentual", "valor": 100, "usos_max": 1, "ativo": 1}}, AD)
ok(call("pos/config", h=T2)[1].get("cupons") is True, "cupom aparece quando há cupom ativo")
call("admin/settings_save", {"settings": {"verificacao_idade": "declaracao"}}, AD)
st, r = call("pos/identify", {"declara_maior": True}, T2)
ok(r.get("ok") and r.get("declaracao") is True and r.get("token"), "modo declaração: só 'Sim, concordo'", r)
VD = r.get("token")
it = [{"offer_id": o500, "qtd": 2}]
st, r = call("pos/coupon", {"token": VD, "itens": it, "codigo": "BEER10"}, T2)
ok(abs(r.get("desconto", 0) - 2.98) < 0.001 and abs(r.get("total", 0) - 26.82) < 0.001, "cupom 10%: R$29,80 → R$26,82", r)
st, r = call("pos/coupon", {"token": VD, "itens": [{"offer_id": o300, "qtd": 1}], "codigo": "BEER10"}, T2)
ok(r.get("erro") == "cupom", "cupom respeita valor mínimo", r)
st, r = call("pos/coupon", {"token": VD, "itens": it, "codigo": "NAOEXISTE"}, T2)
ok(r.get("erro") == "cupom", "cupom inválido recusado", r)
st, r = call("pos/order", {"token": VD, "metodo": "credito", "itens": it, "cupom": "beer10"}, T2)
ok(abs(r.get("total", 0) - 26.82) < 0.001 and r.get("total_centavos") == 2682, "pedido com cupom cobra R$26,82", r)
RB = r.get("ref")
call("pos/paid", {"ref": RB, "metodo": "credito"}, T2)
cup = next(c for c in call("admin/catalog", h=AD)[1]["cupons"] if c["codigo"] == "BEER10")
ok(int(cup["usos"]) == 1, "uso do cupom contado no pagamento", cup)
st, r = call("pos/order", {"token": VD, "metodo": "", "itens": [{"offer_id": o300, "qtd": 1}], "cupom": "CORTESIA"}, T2)
ok(r.get("pago") is True and r.get("total") == 0, "cupom 100%: liberado sem pagamento", r)
st, r = call("pos/order", {"token": VD, "metodo": "", "itens": [{"offer_id": o300, "qtd": 1}], "cupom": "CORTESIA"}, T2)
ok(r.get("erro") == "cupom", "cupom esgotado (1 uso)", r)
st, r = call("pos/order", {"token": VD, "metodo": "credito", "itens": [{"offer_id": 999999, "qtd": 1}]}, T2)
ok(r.get("erro") == "oferta", "maquininha da torneira não vende outra torneira", r)
c = call("dev/poll", h=D)[1].get("cmd") or {}
ok(c.get("espera_inicio_s") == 6 and c.get("pausa_max_s") == 5, "modo SERVIR: tempos curtos", c)
call("admin/save", {"entity": "devices", "data": {"id": dev, "start_mode": "auto"}}, AD)
o = call("admin/orders", h=AD)[1]
for p in o["pedidos"]:
    if p["ref"] == RB or p["total"] == 0 and p["status"] in ("pago", "servindo"): call("admin/order_refund", {"id": p["id"]}, AD)
call("admin/settings_save", {"settings": {"verificacao_idade": "cpf"}}, AD)
st, r = call("pos/identify", {"declara_maior": True}, T2)
ok(r.get("erro") == "cpf_invalido", "voltando ao modo CPF, o CPF é exigido de novo", r)

print("── UX de vendas: happy hour, mais pedido, economia, funil, avaliação")
call("admin/settings_save", {"settings": {"happy_hour_ativo": "1", "happy_hour_inicio": "00:00", "happy_hour_fim": "23:59", "happy_hour_dias": "1,2,3,4,5,6,7", "happy_hour_pct": "20"}}, AD)
m = call("pos/menu", h=T)
hh, of = m[1]["happy_hour"], m[1]["torneiras"][0]["ofertas"]
o5 = next(x for x in of if x["id"] == o500)
ok(hh["ativo"] and o5["preco"] == 11.92 and o5["preco_cheio"] == 14.9, "happy hour: 500 ml de R$14,90 por R$11,92", (hh, o5))
ok(o5["economia_pct"] == 9, "500 ml mostra 'economize 9%' sobre o 300 ml", o5)
st, r = call("pos/identify", {"cpf": "52998224725", "nascimento": "10/05/1990", "declara_maior": True}, T)
VH = r.get("token")
st, r = call("pos/order", {"token": VH, "metodo": "credito", "itens": [{"offer_id": o500, "qtd": 1}]}, T)
ok(abs(r.get("total", 0) - 11.92) < 0.001, "pedido cobra o preço do happy hour", r)
RH = r.get("ref")
call("pos/paid", {"ref": RH, "metodo": "credito"}, T)
EXTRAS = []
for _ in range(2):
    r = call("pos/order", {"token": VH, "metodo": "credito", "itens": [{"offer_id": o500, "qtd": 1}]}, T)[1]
    call("pos/paid", {"ref": r["ref"], "metodo": "credito"}, T); EXTRAS.append(r["ref"])
call("admin/settings_save", {"settings": {"happy_hour_ativo": "0"}}, AD)
m = call("pos/menu", h=T)[1]
ok(next(x for x in m["torneiras"][0]["ofertas"] if x["id"] == o500)["preco"] == 14.9, "fora do happy hour volta ao preço cheio")
ok(next(x for x in m["torneiras"][0]["ofertas"] if x["id"] == o500).get("mais_pedido") is True, "selo 'Mais pedido' com base nas vendas reais (3 copos de 500 ml)")
S16 = "a1b2c3d4e5f6a7b8"
for e in ["inicio", "termos", "tamanho", "inicio", "pago"]: call("pos/track", {"sessao": S16, "etapa": e}, T)
call("pos/track", {"sessao": "b1b2c3d4e5f6a7b8", "etapa": "inicio"}, T)
st, r = call("pos/rate", {"ref": RH, "nota": 2, "motivo": "espuma"}, T)
ok(r.get("ok"), "avaliação pós-compra", r)
d = call("admin/dashboard", h=AD)[1]
ok(d["funil"].get("inicio") == 2 and d["funil"].get("pago") == 1 and int(d["nps"]["n"]) == 1, "funil e avaliação no painel", (d.get("funil"), d.get("nps")))
o = call("admin/orders", h=AD)[1]
for p in o["pedidos"]:
    if p["ref"] in [RH] + EXTRAS: call("admin/order_refund", {"id": p["id"]}, AD)

print("── simulador (demonstração)")
st, r = call("admin/demo_seed", {}, AD)
ok(r.get("ok") and r.get("device_key") and len(r.get("codigo", "")) == 6 and len(r.get("taps", [])) == 2, "cria ponto de demonstração", r)
DK = {"X-Device-Key": r["device_key"]}
r2 = call("admin/demo_seed", {}, AD)[1]
ok(r2.get("location_id") == r["location_id"], "não duplica a demonstração")
TD = {"X-Terminal-Token": call("pos/pair", {"codigo": r["codigo"], "serial": "SIM"})[1].get("token", "")}
call("dev/poll&temp=3", h=DK)
m = call("pos/menu", h=TD)[1]["torneiras"]
ok(len(m) == 1 and m[0]["disponivel"] and m[0]["produto"] == "Pilsen da Casa", "totem simulado presa à torneira 1", m)
call("admin/settings_save", {"settings": {"verificacao_idade": "declaracao"}}, AD)
vt = call("pos/identify", {"declara_maior": True}, TD)[1]["token"]
o = call("pos/order", {"token": vt, "metodo": "credito", "itens": [{"offer_id": m[0]["ofertas"][0]["id"], "qtd": 1}]}, TD)[1]
call("pos/paid", {"ref": o["ref"], "metodo": "credito"}, TD)
c = call("dev/poll", h=DK)[1].get("cmd") or {}
ok(c.get("go") is True and c.get("espera_inicio_s") == 120, "demo usa alavanca: liberada após pagar", c)
call("dev/report", {"id": c["id"], "status": "concluido", "servido_ml": 300, "pulsos": 578}, DK)
ok(call(f"pos/order_status&ref={o['ref']}", h=TD)[1]["status"] == "concluido", "venda simulada concluída")
call("admin/settings_save", {"settings": {"verificacao_idade": "cpf"}}, AD)
st, r = call("admin/demo_clear", {}, AD)
ok(r.get("removido") is True, "remove a demonstração", r)
st, r = call("dev/poll", h=DK)
ok(st == 401, "chopeira simulada deixa de existir", st)
ok(not any(x["ref"] == o["ref"] for x in call("admin/orders", h=AD)[1]["pedidos"]), "vendas simuladas somem do painel")

print("── painel pelo celular: editor de bebidas")
b = call("admin/bebidas", h=AD)[1]
ok(b.get("ok") and any(t["id"] == tap for t in b["torneiras"]) and b["controladores"], "lista torneiras com bebida, tamanhos e barril", b.get("mensagem"))
t1 = next(t for t in b["torneiras"] if t["id"] == tap)
PNG = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAUElEQVR42u3PQQkAAAgEsPvYP469TGAE38JgBZbpei0CAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICApcFAL/hhw4sMksAAAAASUVORK5CYII="
st, r = call("admin/bebida_salvar", {"tap_id": tap, "produto": {"id": t1["produto"]["id"], "nome": "Pilsen Premium", "estilo": "Pilsen", "abv": "5,2", "ibu": 12, "cor": "#F2D46B", "cervejaria": "Casa"},
    "ofertas": [{"id": o300, "volume_ml": 300, "preco": "10,90"}, {"volume_ml": 1000, "preco": 29.9}], "barril_ml": 50000, "trocou_barril": 1, "ativo": 1, "foto": PNG}, AD)
ok(r.get("ok"), "salva bebida + tamanhos + barril + foto de uma vez", r)
t1 = next(t for t in call("admin/bebidas", h=AD)[1]["torneiras"] if t["id"] == tap)
ok(t1["produto"]["nome"] == "Pilsen Premium" and float(t1["produto"]["abv"]) == 5.2 and (t1["produto"]["imagem_url"] or "").startswith("uploads/produtos/"), "bebida atualizada com foto", t1["produto"])
ok(sorted((o["volume_ml"], o["preco"]) for o in t1["ofertas"] if o["ativo"]) == [(300, 10.9), (1000, 29.9)], "tamanhos: editado, criado e o de 500 (já vendido) desativado", t1["ofertas"])
ok(t1["barril_ml"] == 50000 and t1["restante_ml"] == 50000, "troca de barril zera o contador", t1)
st, r = call("admin/bebida_salvar", {"tap_id": tap, "produto": {"nome": ""}, "ofertas": [{"volume_ml": 300, "preco": 9}]}, AD)
ok(st == 400 and "nome" in r.get("mensagem", ""), "sem nome não salva", r)
st, r = call("admin/bebida_salvar", {"tap_id": tap, "produto": {"nome": "X"}, "ofertas": [{"volume_ml": 300, "preco": 9}, {"volume_ml": 300, "preco": 8}]}, AD)
ok(st == 400 and "repetido" in r.get("mensagem", ""), "tamanho repetido não salva", r)
st, r = call("admin/bebida_salvar", {"tap_id": 0, "device_id": dev, "canal": 2, "produto": {"nome": "IPA Nova", "estilo": "IPA", "abv": 6.5},
    "ofertas": [{"volume_ml": 400, "preco": 16}], "barril_ml": 20000}, AD)
ok(r.get("ok") and r.get("tap_id"), "cria torneira nova com bebida pelo celular", r)
TAP2 = r.get("tap_id")
m = call("pos/menu", h=T)[1]["torneiras"]
ok(any(x["produto"] == "IPA Nova" for x in m) and any(x["produto"] == "Pilsen Premium" and x["imagem_url"] for x in m), "maquininha mostra as bebidas editadas (cardápio com todas)", [x["produto"] for x in m])
st, r = call("admin/bebida_salvar", {"tap_id": 0, "device_id": dev, "canal": 2, "produto": {"nome": "Dup"}, "ofertas": [{"volume_ml": 300, "preco": 9}]}, AD)
ok(st == 400, "não deixa duas torneiras no mesmo canal", r)

print("── dono de quiosque (vê e edita só o dele)")
loc2 = save("locations", {"nome": "Quiosque do João"})
dev2 = save("devices", {"location_id": loc2, "nome": "Freezer João"})
st, r = call("admin/usuario_salvar", {"nome": "João", "email": "joao@teste.com", "senha": "senha12345", "role": "dono", "pontos": [loc2]}, AD)
ok(r.get("ok"), "admin cria dono com 1 quiosque", r)
DN = {"Authorization": "Bearer " + call("admin/login", {"email": "joao@teste.com", "senha": "senha12345"})[1].get("token", "")}
tapJ = save("torneiras", {"device_id": dev2, "canal": 1, "nome": "Torneira 1", "product_id": t1["produto"]["id"], "barril_ml": 30000})   # admin pôs a bebida da plataforma
st, r = call("admin/bebida_salvar", {"tap_id": tapJ, "produto": {"id": t1["produto"]["id"], "nome": "Pilsen do João", "abv": 4.5},
    "ofertas": [{"volume_ml": 300, "preco": 8}]}, DN)
ok(r.get("ok"), "dono edita a torneira do quiosque dele", r)
st, r2 = call("admin/bebida_salvar", {"tap_id": 0, "device_id": dev2, "canal": 2, "produto": {"nome": "Weiss do João", "estilo": "Weiss"}, "ofertas": [{"volume_ml": 500, "preco": 15}]}, DN)
ok(r2.get("ok"), "dono cria torneira nova no quiosque dele", r2)
ok(r.get("produto_id") != t1["produto"]["id"], "bebida de outro ponto vira cópia do dono (não altera o outro quiosque)", r)
ok(next(t for t in call("admin/bebidas", h=AD)[1]["torneiras"] if t["id"] == tap)["produto"]["nome"] == "Pilsen Premium", "bebida do outro ponto continua igual")
bd = call("admin/bebidas", h=DN)[1]
ok({t["location_id"] for t in bd["torneiras"]} == {loc2} and len(bd["torneiras"]) == 2 and all(c["location_id"] == loc2 for c in bd["controladores"]), "dono só vê as torneiras dele", [t["loc"] for t in bd["torneiras"]])
st, r = call("admin/bebida_salvar", {"tap_id": tap, "produto": {"nome": "Hack"}, "ofertas": [{"volume_ml": 300, "preco": 1}]}, DN)
ok(st == 403, "dono não edita torneira de outro ponto", r)
st, r = call("admin/settings_save", {"settings": {"marca_nome": "Hack"}}, DN); ok(st == 403, "dono não mexe nas configurações gerais", r)
st, r = call("admin/save", {"entity": "locations", "data": {"nome": "Hack"}}, DN); ok(st == 403, "dono não cria ponto", r)
ok(call("admin/orders", h=DN)[1]["total"] == 0 and call("admin/dashboard", h=DN)[1]["hoje"]["n"] == 0, "dono não vê vendas dos outros")
ok(all(d.get("device_key") is None for d in call("admin/catalog", h=DN)[1]["devices"]), "dono não vê a chave secreta do ESP32")
ok(call("admin/dashboard&location_id=" + str(loc), h=AD)[1]["hoje"]["n"] >= 1, "admin filtra o painel por quiosque")
st, r = call("admin/dashboard&location_id=" + str(loc), h=DN); ok(st == 403, "dono não abre o painel de outro quiosque", r)
r = call("admin/torneira_remover", {"tap_id": TAP2}, AD)[1]
ok(r.get("ok") and r.get("desativada") is False, "remove torneira que nunca vendeu", r)

print("── portal público")
call("admin/save", {"entity": "locations", "data": {"id": loc, "lat": -15.79, "lng": -47.88, "horario": "Todos os dias"}}, AD)
st, r = call("pub/site")
ok(st == 200 and r.get("ok") and any(p["id"] == loc and p["lat"] for p in r["pontos"]), "portal lista os pontos com mapa", r.get("pontos"))
ok(any(b["nome"] == "Pilsen Gelada" or b["a_partir"] for b in r["cardapio"]), "portal mostra o cardápio das torneiras", r.get("cardapio"))
ok(not any(p["nome"].startswith("Demonstração") for p in r["pontos"]), "ponto de demonstração não aparece no portal")
st, r = call("pub/lead", {"tipo": "espaco", "nome": "Maria", "contato": "61 99999-8888", "cidade": "Asa Sul"})
ok(r.get("ok"), "portal recebe contato de quem tem espaço", r)
st, r = call("pub/lead", {"tipo": "avise", "nome": "M", "contato": "x"}); ok(st == 400, "contato inválido é recusado", r)
ok(call("admin/leads", h=AD)[1]["leads"][0]["nome"] == "Maria", "contato aparece no painel")
ok(call("admin/leads", h=DN)[0] == 403, "dono de quiosque não vê os contatos do portal")

print("── simulador sem senha")
st, r = call("pub/demo", {})
ok(st == 200 and r.get("device_key") and r.get("codigo"), "simulador abre sem login de administrador", r)
r2 = call("pub/demo_modo", {"torneira": 0})[1]
ok(r2.get("tap_id") is None, "troca para cardápio com todas sem login", r2)
r2 = call("pub/demo_modo", {"torneira": 1})[1]
ok(r2.get("tap_id") == r["taps"][0], "volta para maquininha da torneira", r2)
ok(not any(p["nome"].startswith("Demonstração") for p in call("pub/site")[1]["pontos"]), "demonstração continua fora do portal")
call("admin/demo_clear", {}, AD)

print("── operação: custo, limpeza, validade, relatório")
t1 = next(t for t in call("admin/bebidas", h=AD)[1]["torneiras"] if t["id"] == tap)
ok(t1["cuidados"]["limpeza_restam"] in (13, 14) and t1["cuidados"]["validade_restam"] in (29, 30), "torneira nova começa com limpeza e validade em dia", t1["cuidados"])
pr = {"id": t1["produto"]["id"], "nome": t1["produto"]["nome"]}
ofs = [{"id": o["id"], "volume_ml": o["volume_ml"], "preco": o["preco"]} for o in t1["ofertas"] if o["ativo"]]
st, r = call("admin/bebida_salvar", {"tap_id": tap, "produto": pr, "ofertas": ofs, "custo_barril": "800,00", "validade_dias": 20, "limpeza_dias": 7}, AD)
ok(r.get("ok"), "salva custo do barril, validade e limpeza", r)
t1 = next(t for t in call("admin/bebidas", h=AD)[1]["torneiras"] if t["id"] == tap)
ok(t1["custo_barril"] == 800.0 and t1["cuidados"]["validade_dias"] == 20 and t1["cuidados"]["limpeza_dias"] == 7, "custo e prazos guardados", t1["cuidados"])
st, r = call("admin/limpeza_feita", {"tap_id": tap}, AD); ok(r.get("ok"), "registra limpeza feita", r)
rel = call("admin/relatorio", h=AD)[1]
pil = next((p for p in rel["produtos"] if p["produto"].startswith("Pilsen")), None)
ok(rel.get("ok") and pil and pil["custo"] is not None and abs(pil["margem"] - (pil["receita"] - pil["custo"])) < 0.02, "relatório calcula custo e margem por bebida", pil)
ok(abs(rel["receita"] - sum(p["receita"] for p in rel["produtos"])) < 0.02, "total do relatório bate com a soma das bebidas (já com desconto de cupom)", (rel["receita"], rel["produtos"]))
ok(isinstance(rel["semana"], list) and isinstance(rel["horas"], list) and rel["ticket"] >= 0, "relatório traz dia da semana, hora e ticket médio")
d = call("admin/dashboard", h=AD)[1]
ok(all("limpeza_restam" in b and "previsao_dias" in b for b in d["barris"]), "início traz limpeza, validade e previsão do barril")
st, r = call("admin/relatorio&location_id=" + str(loc), h=AD); ok(r.get("ok"), "relatório filtra por ponto", r)

st, r = call("admin/limpeza_feita", {"tap_id": tap}, DN); ok(st == 403, "dono não registra limpeza em torneira de outro ponto", r)
st, r = call("admin/relatorio&location_id=" + str(loc), h=DN); ok(st == 403, "dono não abre relatório de outro ponto", r)
print("── segurança")
for i in range(6): st, r = call("pos/identify", {"cpf": "123.456.789-00", "nascimento": "01/01/1990", "declara_maior": True}, T)
ok(r.get("erro") == "terminal_bloqueado", "força bruta bloqueia a maquininha", r)
st, _ = call("admin/dashboard"); ok(st == 401, "painel sem login → 401")
st, _ = call("dev/poll", h={"X-Device-Key": "errada"}); ok(st == 401, "ESP32 com chave errada → 401")
st, _ = call("pos/menu", h={"X-Terminal-Token": "errado"}); ok(st == 401, "maquininha não pareada → 401")
st, r = call("health"); ok(st == 200 and r.get("status") == "OK", "health OK", r)

print(f"\n{'fluxo: ok' if not falhas else f'fluxo: {falhas} falha(s)'}")
sys.exit(1 if falhas else 0)
