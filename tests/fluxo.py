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

print("── segurança")
for i in range(6): st, r = call("pos/identify", {"cpf": "123.456.789-00", "nascimento": "01/01/1990", "declara_maior": True}, T)
ok(r.get("erro") == "terminal_bloqueado", "força bruta bloqueia a maquininha", r)
st, _ = call("admin/dashboard"); ok(st == 401, "painel sem login → 401")
st, _ = call("dev/poll", h={"X-Device-Key": "errada"}); ok(st == 401, "ESP32 com chave errada → 401")
st, _ = call("pos/menu", h={"X-Terminal-Token": "errado"}); ok(st == 401, "maquininha não pareada → 401")
st, r = call("health"); ok(st == 200 and r.get("status") == "OK", "health OK", r)

print(f"\n{'fluxo: ok' if not falhas else f'fluxo: {falhas} falha(s)'}")
sys.exit(1 if falhas else 0)
