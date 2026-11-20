/* =====================================================================
 *  BEER EXPRESS — Torneira inteligente (1 por torneira)
 *  Firmware 1.0.0 • placa ESP32-2432S028R ("Cheap Yellow Display", tela 2,8")
 *
 *  Faz tudo que o BeerPass faz na torneira, com peças baratas:
 *   • Tela mostra o chopp, o preço por litro e "Aproxime seu cartão"
 *   • Leitor RFID (RC522) lê o cartão/pulseira → servidor confere saldo e idade
 *   • Abre a válvula solenoide; o fluxômetro mede os ml; a tela mostra ml e R$
 *     em tempo real; a válvula FECHA NA INTERRUPÇÃO ao atingir o limite do saldo
 *   • Fecha sozinha após X s sem fluxo (ou se aproximar o cartão de novo) e
 *     cobra só o que saiu
 *   • Também serve copos vendidos na maquininha (fila do servidor), como o
 *     controlador central
 *   • Cartão novo → mostra um código de 4 números para vincular na maquininha
 *   • Temperatura do freezer (DS18B20), Wi-Fi, vazamento, reinício no meio
 *
 *  Bibliotecas (Arduino IDE → Gerenciar bibliotecas):
 *   LovyanGFX • MFRC522 (GithubCommunity) • WiFiManager (tzapu) • ArduinoJson 7
 *   OneWire • DallasTemperature
 *  Placa: "ESP32 Dev Module"
 *
 *  Ligações (CYD): RC522 no conector do cartão SD/pinos livres:
 *   RC522  SDA(SS)=GPIO5  SCK=GPIO18  MOSI=GPIO23  MISO=GPIO19  RST=3V3  3.3V/GND
 *   Fluxômetro sinal → GPIO35 (pull-up 10k para 3V3)
 *   Válvula (relé/MOSFET) → GPIO22      DS18B20 → GPIO27 (4k7 para 3V3)
 *  1ª vez: rede "BeerTap-XXXX" (senha beerexpress) → Wi-Fi + URL da API + chave.
 *  Reconfigurar: segure o botão BOOT por 5 s.
 * ===================================================================== */
#define LGFX_USE_V1
#include <LovyanGFX.hpp>
#include <SPI.h>
#include <MFRC522.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <esp_task_wdt.h>
#include <soc/gpio_reg.h>

#define FW_VERSION "tap-1.0.0"
#define CANAL       1          // canal desta torneira no painel (Torneiras → canal)
#define PIN_FLOW    35
#define PIN_VALVE   22
#define PIN_TEMP    27
#define PIN_CFG     0
#define PIN_LED_R   4          // LED RGB da placa (acende em LOW)
#define PIN_LED_G   16
#define PIN_LED_B   17
#define RC_SS       5
#define VALVE_ON    HIGH
#define VALVE_OFF   LOW
#define SEM_FLUXO_INICIO_MS 25000UL   // abriu e ninguém serviu → fecha
#define SEM_FLUXO_COPO_MS   6000UL    // copo pago: sem fluxo → erro (barril/CO2)

/* ---------- Tela ILI9341 da CYD (configuração dentro do sketch: nada para editar na biblioteca) ---------- */
class LGFX : public lgfx::LGFX_Device {
  lgfx::Panel_ILI9341 _panel; lgfx::Bus_SPI _bus; lgfx::Light_PWM _light;
public:
  LGFX() {
    { auto c = _bus.config(); c.spi_host = HSPI_HOST; c.spi_mode = 0; c.freq_write = 40000000; c.freq_read = 16000000;
      c.pin_sclk = 14; c.pin_mosi = 13; c.pin_miso = 12; c.pin_dc = 2; _bus.config(c); _panel.setBus(&_bus); }
    { auto c = _panel.config(); c.pin_cs = 15; c.pin_rst = -1; c.pin_busy = -1; c.panel_width = 240; c.panel_height = 320;
      c.offset_rotation = 0; c.readable = true; c.invert = false; c.rgb_order = false; c.bus_shared = false; _panel.config(c); }
    { auto c = _light.config(); c.pin_bl = 21; c.invert = false; c.freq = 44100; c.pwm_channel = 7; _light.config(c); _panel.setLight(&_light); }
    setPanel(&_panel);
  }
};
LGFX tft;
MFRC522 rfid(RC_SS, MFRC522::UNUSED_PIN);
Preferences prefs;
OneWire oneWire(PIN_TEMP);
DallasTemperature sensors(&oneWire);
String apiUrl, devKey;

/* ---------- fluxômetro (ISR fecha a válvula no limite) ---------- */
volatile uint32_t pulsos = 0;
volatile uint32_t alvo = 0;                // 0 = sem limite ativo
void IRAM_ATTR onPulse() {
  pulsos++;
  // escrita direta no registrador (segura dentro da interrupção)
  if (alvo && pulsos >= alvo) { if (VALVE_OFF == LOW) REG_WRITE(GPIO_OUT_W1TC_REG, BIT(PIN_VALVE)); else REG_WRITE(GPIO_OUT_W1TS_REG, BIT(PIN_VALVE)); alvo = 0; }
}

/* ---------- estado ---------- */
enum Modo { LIVRE, PASS, COPO_ARMADO, COPO_SERVINDO, MSG };
Modo modo = LIVRE;
struct { String produto = "Beer Express", estilo = ""; float precoL = 0, ppl = 1925, abv = 0; int restante = 0; uint16_t cor = 0xFDA0; bool pass = true; } tap;
struct { int id = 0; float precoL = 0; int limite = 0; uint32_t inicio = 0, ultimoPulso = 0, ultimoReport = 0; uint32_t ult = 0; float saldo = 0; String nome; int inatividadeS = 8; } pour;
struct { int id = 0, volume = 0, alvo = 0, timeoutS = 120; String startMode = "pos"; uint32_t armadoEm = 0, inicio = 0, ultimoPulso = 0, ult = 0, ultimoReport = 0;
         uint32_t esperaInicioMs = SEM_FLUXO_COPO_MS, pausaMaxMs = 5000; } copo;
uint32_t msgAte = 0, lastPoll = 0, pollMs = 2000, lastTemp = 0, cfgPressed = 0, lastWifi = 0, lastStray = 0, strayBase = 0;
float temperatura = -127;
String ultimoUid = ""; uint32_t ultimoUidEm = 0;
String pendente = "";

/* ---------- HTTP ---------- */
bool http(const String& method, const String& route, const String& body, JsonDocument* out) {
  if (WiFi.status() != WL_CONNECTED) return false;
  WiFiClientSecure tls; tls.setInsecure(); WiFiClient plain; HTTPClient h;
  String url = apiUrl + (apiUrl.indexOf('?') >= 0 ? "&" : "?") + "r=" + route;
  if (!(apiUrl.startsWith("https") ? h.begin(tls, url) : h.begin(plain, url))) return false;
  h.setTimeout(5000); h.addHeader("X-Device-Key", devKey); h.addHeader("Content-Type", "application/json");
  int code = method == "POST" ? h.POST(body) : h.GET();
  if (out && code > 0) deserializeJson(*out, h.getString());
  h.end();
  return code == 200;
}
void post(const char* route, JsonDocument& d, bool critico) {
  String b; serializeJson(d, b);
  if (!http("POST", route, b, nullptr) && critico) { pendente = String(route) + "|" + b; prefs.putString("pend", pendente); }
}
void flushPendente() {
  if (!pendente.length()) return;
  int i = pendente.indexOf('|');
  if (http("POST", pendente.substring(0, i), pendente.substring(i + 1), nullptr)) { pendente = ""; prefs.remove("pend"); }
}
int ml(uint32_t p) { return (int)lround(p * 1000.0 / max(1.0f, tap.ppl)); }
String brl(float v) { char b[20]; snprintf(b, sizeof b, "R$ %.2f", v); String s = b; s.replace('.', ','); return s; }
void led(bool r, bool g, bool b) { digitalWrite(PIN_LED_R, !r); digitalWrite(PIN_LED_G, !g); digitalWrite(PIN_LED_B, !b); }
uint16_t hexCor(const char* h) { if (!h || h[0] != '#') return 0xFDA0; long v = strtol(h + 1, nullptr, 16); return tft.color565((v >> 16) & 255, (v >> 8) & 255, v & 255); }

/* ---------- telas ---------- */
const uint16_t BG = 0x0841, AMB = 0xFDA0, MUT = 0xBD55, OK = 0x3EF0, ERR = 0xFA89;
void centro(const String& s, int y, const lgfx::IFont* f, uint16_t cor) { tft.setFont(f); tft.setTextColor(cor, BG); tft.setTextDatum(textdatum_t::top_center); tft.drawString(s, 120, y); }
void telaLivre() {
  tft.fillScreen(BG);
  tft.fillRoundRect(80, 18, 80, 96, 10, tap.cor); tft.fillRoundRect(80, 18, 80, 18, 6, TFT_WHITE);
  centro(tap.produto, 128, &fonts::FreeSansBold18pt7b, TFT_WHITE);
  centro(tap.estilo + (tap.abv > 0 ? "  " + String(tap.abv, 1) + "%" : ""), 166, &fonts::FreeSans9pt7b, MUT);
  if (tap.pass && tap.precoL > 0) {
    centro(brl(tap.precoL) + " /litro", 196, &fonts::FreeSansBold18pt7b, AMB);
    tft.fillRoundRect(16, 248, 208, 54, 12, AMB);
    tft.setTextColor(BG, AMB); tft.setFont(&fonts::FreeSansBold12pt7b); tft.setTextDatum(textdatum_t::middle_center); tft.drawString("Aproxime o cartao", 120, 275);
  } else centro("Compre na maquininha", 230, &fonts::FreeSansBold12pt7b, AMB);
  if (tap.restante > 0 && tap.restante < 2000) centro("Barril acabando", 306, &fonts::Font2, ERR);
  led(false, false, false);
}
void telaMsg(const String& t1, const String& t2, const String& t3, uint16_t cor, uint32_t ms) {
  tft.fillScreen(BG);
  centro(t1, 60, &fonts::FreeSansBold18pt7b, cor);
  centro(t2, 130, &fonts::FreeSansBold24pt7b, TFT_WHITE);
  centro(t3, 200, &fonts::FreeSans9pt7b, MUT);
  modo = MSG; msgAte = millis() + ms;
}
void telaServindo(int servido, int limite, float precoL, const String& topo) {
  static int ultimoDesenho = -1;
  if (servido == ultimoDesenho && servido != 0) return;
  if (servido == 0 || ultimoDesenho < 0) { tft.fillScreen(BG); centro(topo, 14, &fonts::FreeSansBold12pt7b, AMB); tft.drawRoundRect(70, 50, 100, 150, 14, TFT_WHITE); }
  ultimoDesenho = servido;
  int h = limite > 0 ? constrain(146 * servido / limite, 0, 146) : 0;
  tft.fillRect(72, 52, 96, 146 - h, BG); tft.fillRect(72, 198 - h, 96, h, tap.cor);
  tft.fillRect(0, 214, 240, 106, BG);
  centro(String(servido) + " ml", 216, &fonts::FreeSansBold24pt7b, TFT_WHITE);
  if (precoL > 0) centro(brl(servido * precoL / 1000.0), 270, &fonts::FreeSansBold18pt7b, AMB);
  else centro("de " + String(limite) + " ml", 270, &fonts::FreeSans12pt7b, MUT);
  if (servido == 0) ultimoDesenho = 0;
}

/* ---------- Beer Pass: cartão ---------- */
void cartaoLido(const String& uid) {
  if (modo == PASS) { if (uid == ultimoUid) fecharPour("cartao"); return; }   // aproximou de novo = terminar
  if (modo == COPO_ARMADO || modo == COPO_SERVINDO) return;
  telaMsg("Lendo cartao...", "", "", MUT, 3000); led(false, false, true);
  JsonDocument q, r; q["uid"] = uid; q["canal"] = CANAL;
  String b; serializeJson(q, b);
  if (!http("POST", "dev/card", b, &r)) { telaMsg("Sem conexao", ":(", "Tente de novo em instantes", ERR, 4000); return; }
  if (!(r["ok"] | false)) {
    String mot = r["motivo"] | "";
    if (mot == "cartao_novo") telaMsg("Cartao novo!", String((const char*)(r["codigo"] | "----")), "Na maquininha: Cartao > digite o codigo", AMB, 20000);
    else if (mot == "saldo_insuficiente") telaMsg("Saldo insuficiente", brl(r["saldo"] | 0.0f), "Recarregue na maquininha", ERR, 6000);
    else telaMsg("Nao liberado", "", String((const char*)(r["msg"] | "")), ERR, 5000);
    led(true, false, false);
    return;
  }
  pour.id = r["pour_id"]; pour.precoL = r["preco_litro"]; pour.limite = r["limite_ml"]; pour.saldo = r["saldo"];
  pour.nome = (const char*)(r["nome"] | ""); pour.inatividadeS = r["inatividade_s"] | 8; tap.ppl = r["ppl"] | tap.ppl;
  ultimoUid = uid; modo = PASS;
  prefs.putInt("pid", pour.id);
  noInterrupts(); pulsos = 0; alvo = r["pulsos_limite"] | 0; interrupts();
  pour.inicio = pour.ultimoPulso = pour.ultimoReport = millis(); pour.ult = 0;
  digitalWrite(PIN_VALVE, VALVE_ON); led(false, true, false);
  telaServindo(0, pour.limite, pour.precoL, "Ola, " + pour.nome + "! Saldo " + brl(pour.saldo));
}
void fecharPour(const char* motivo) {
  noInterrupts(); alvo = 0; interrupts();
  digitalWrite(PIN_VALVE, VALVE_OFF); delay(500);
  uint32_t p = pulsos; int m = ml(p);
  JsonDocument d, r; d["pour_id"] = pour.id; d["ml"] = m; d["pulsos"] = p; d["fim"] = true; d["motivo"] = motivo;
  String b; serializeJson(d, b);
  bool ok = http("POST", "dev/pour", b, &r);
  if (!ok) { pendente = "dev/pour|" + b; prefs.putString("pend", pendente); }
  prefs.putInt("pid", 0);
  strayBase = pulsos;
  float valor = ok ? (r["valor"] | 0.0f) : m * pour.precoL / 1000.0;
  telaMsg(String(m) + " ml", brl(valor), ok ? "Saldo: " + brl(r["saldo"] | 0.0f) + "  Saude!" : "Registrando...", OK, 6000);
  modo = MSG; pour = decltype(pour)();
}
void cuidarPour() {
  uint32_t p = pulsos;
  if (p != pour.ult) { pour.ult = p; pour.ultimoPulso = millis(); }
  int m = ml(p);
  telaServindo(m, pour.limite, pour.precoL, "Servindo " + tap.produto);
  if (alvo == 0 && p > 0) { fecharPour("limite_saldo"); return; }                                       // ISR fechou no limite
  if (p == 0 && millis() - pour.inicio > SEM_FLUXO_INICIO_MS) { fecharPour("nao_serviu"); return; }
  if (p > 0 && millis() - pour.ultimoPulso > (uint32_t)pour.inatividadeS * 1000UL) { fecharPour("inatividade"); return; }
  if (millis() - pour.ultimoReport > 1500) {                                                           // ao vivo na TV/painel
    pour.ultimoReport = millis(); if ((millis() / 1500) % 2) prefs.putUInt("ppl", p);
    JsonDocument d; d["pour_id"] = pour.id; d["ml"] = m; d["pulsos"] = p; post("dev/pour", d, false);
  }
}

/* ---------- copo vendido na maquininha ---------- */
void reportCopo(const char* st, const char* erro = "") {
  uint32_t p = pulsos; JsonDocument d; d["id"] = copo.id; d["status"] = st; d["pulsos"] = p; d["servido_ml"] = ml(p);
  if (erro[0]) d["erro"] = erro;
  post("dev/report", d, strcmp(st, "servindo") != 0);
}
void iniciarCopo() {
  noInterrupts(); pulsos = 0; alvo = copo.alvo; interrupts();
  digitalWrite(PIN_VALVE, VALVE_ON); led(false, true, false);
  copo.inicio = copo.ultimoPulso = copo.ultimoReport = millis(); copo.ult = 0; modo = COPO_SERVINDO;
  prefs.putInt("cid", copo.id);
  reportCopo("servindo");
  telaServindo(0, copo.volume, 0, copo.startMode == "auto" ? "Puxe a alavanca!" : "Servindo seu copo");
}
void fimCopo(const char* st, const char* erro = "") {
  noInterrupts(); alvo = 0; interrupts(); digitalWrite(PIN_VALVE, VALVE_OFF); delay(400);
  reportCopo(st, erro); prefs.putInt("cid", 0); strayBase = pulsos;
  if (!strcmp(st, "concluido")) telaMsg("Pronto!", String(ml(pulsos)) + " ml", "Saude! Beba com moderacao", OK, 4000);
  else telaMsg("Ops!", "", String(erro), ERR, 6000);
  copo = decltype(copo)();
}
void cuidarCopo() {
  if (modo == COPO_ARMADO) {
    if ((millis() / 400) % 2) led(false, false, true); else led(false, false, false);
    if (millis() - copo.armadoEm > (uint32_t)copo.timeoutS * 1000UL) { reportCopo("timeout", "cliente nao iniciou"); copo = decltype(copo)(); modo = LIVRE; telaLivre(); }
    return;
  }
  uint32_t p = pulsos;
  if (p != copo.ult) { copo.ult = p; copo.ultimoPulso = millis(); }
  telaServindo(ml(p), copo.volume, 0, "Servindo seu copo");
  if (alvo == 0 || p >= (uint32_t)copo.alvo) fimCopo("concluido");
  else if (p == 0 && millis() - copo.inicio > copo.esperaInicioMs) fimCopo(copo.startMode == "auto" ? "timeout" : "erro", copo.startMode == "auto" ? "cliente nao puxou a alavanca" : "sem_fluxo (barril vazio ou CO2?)");
  else if (p > 0 && millis() - copo.ultimoPulso > copo.pausaMaxMs) fimCopo("erro", "fluxo_interrompido");
  else if (millis() - copo.inicio > 20000UL + (uint32_t)copo.volume * 60UL + copo.esperaInicioMs) fimCopo("erro", "tempo_maximo");
  else if (millis() - copo.ultimoReport > 1000) { copo.ultimoReport = millis(); reportCopo("servindo"); }
}

/* ---------- poll ---------- */
void poll() {
  JsonDocument r;
  String q = "dev/poll&fw=" FW_VERSION "&rssi=" + String(WiFi.RSSI()) + "&heap=" + String(ESP.getFreeHeap()) + "&up=" + String(millis() / 1000);
  if (temperatura > -100) q += "&temp=" + String(temperatura, 2);
  if (!http("GET", q, "", &r)) { pollMs = 4000; return; }
  pollMs = r["poll_ms"] | 2000;
  bool mudou = false;
  for (JsonObject t : r["taps"].as<JsonArray>()) {
    if ((t["canal"] | 0) != CANAL) continue;
    String prod = t["produto"] | "Chopp"; float pl = t["preco_litro"].isNull() ? 0 : (float)t["preco_litro"];
    mudou = prod != tap.produto || pl != tap.precoL || (int)(t["restante_ml"] | 0) / 1000 != tap.restante / 1000;
    tap.produto = prod; tap.estilo = (const char*)(t["estilo"] | ""); tap.abv = t["abv"].isNull() ? 0 : (float)t["abv"];
    tap.precoL = pl; tap.ppl = t["ppl"] | 1925.0f; tap.restante = t["restante_ml"] | 0; tap.cor = hexCor(t["cor"] | "#F5B301");
  }
  tap.pass = r["pass"] | true;
  JsonObject cmd = r["cmd"];
  if (!cmd.isNull() && (modo == LIVRE || modo == MSG || (modo == COPO_ARMADO && copo.id == (int)cmd["id"]))) {
    if ((int)cmd["canal"] != CANAL) return;
    if (modo != COPO_ARMADO) {
      copo.id = cmd["id"]; copo.volume = cmd["volume_ml"]; copo.alvo = cmd["pulsos_alvo"]; copo.timeoutS = cmd["timeout_s"] | 120;
      copo.startMode = (const char*)(cmd["start_mode"] | "pos"); copo.armadoEm = millis(); modo = COPO_ARMADO;
      copo.esperaInicioMs = (uint32_t)(cmd["espera_inicio_s"] | 6) * 1000UL; copo.pausaMaxMs = (uint32_t)(cmd["pausa_max_s"] | 5) * 1000UL;
      tft.fillScreen(BG); centro("Seu copo esta pronto", 40, &fonts::FreeSansBold12pt7b, AMB);
      centro(String(copo.volume) + " ml", 100, &fonts::FreeSansBold24pt7b, TFT_WHITE);
      centro("Coloque o copo inclinado", 180, &fonts::FreeSans12pt7b, MUT);
      centro("e toque SERVIR na maquininha", 210, &fonts::FreeSans12pt7b, MUT);
    }
    if (cmd["go"] | false) iniciarCopo();
    return;
  }
  if (cmd.isNull() && modo == COPO_ARMADO) { modo = LIVRE; telaLivre(); return; }
  if (mudou && modo == LIVRE) telaLivre();
}

/* ---------- vazamento: fluxo com a torneira livre ---------- */
void vigiar() {
  if (modo != LIVRE && modo != MSG) return;
  uint32_t extra = pulsos - strayBase;
  if (ml(extra) >= 40 && millis() - lastStray > 60000) {
    lastStray = millis(); JsonDocument d; d["canal"] = CANAL; d["ml"] = ml(extra); d["pulsos"] = extra; post("dev/stray", d, false); strayBase = pulsos;
  }
}

void portal(bool forcar) {
  WiFiManager wm;
  WiFiManagerParameter pUrl("url", "URL da API", apiUrl.c_str(), 150), pKey("key", "Chave do dispositivo", devKey.c_str(), 64);
  wm.addParameter(&pUrl); wm.addParameter(&pKey); wm.setConfigPortalTimeout(300);
  String ap = "BeerTap-" + String((uint32_t)ESP.getEfuseMac(), HEX).substring(0, 4);
  tft.fillScreen(BG); centro("Configurar Wi-Fi", 100, &fonts::FreeSansBold12pt7b, AMB); centro("Rede: " + ap, 140, &fonts::FreeSans9pt7b, TFT_WHITE); centro("Senha: beerexpress", 165, &fonts::FreeSans9pt7b, TFT_WHITE);
  bool ok = forcar ? wm.startConfigPortal(ap.c_str(), "beerexpress") : wm.autoConnect(ap.c_str(), "beerexpress");
  if (strlen(pUrl.getValue())) { apiUrl = pUrl.getValue(); prefs.putString("url", apiUrl); }
  if (strlen(pKey.getValue())) { devKey = pKey.getValue(); prefs.putString("key", devKey); }
  if (!ok) ESP.restart();
}

void setup() {
  pinMode(PIN_VALVE, OUTPUT); digitalWrite(PIN_VALVE, VALVE_OFF);          // primeiro de tudo: válvula fechada
  pinMode(PIN_LED_R, OUTPUT); pinMode(PIN_LED_G, OUTPUT); pinMode(PIN_LED_B, OUTPUT); led(false, false, false);
  pinMode(PIN_FLOW, INPUT); attachInterrupt(digitalPinToInterrupt(PIN_FLOW), onPulse, FALLING);
  pinMode(PIN_CFG, INPUT_PULLUP);
  Serial.begin(115200);
  tft.init(); tft.setRotation(0); tft.setBrightness(255); tft.fillScreen(BG);
  centro("Beer Express", 140, &fonts::FreeSansBold18pt7b, AMB);
  SPI.begin(18, 19, 23, RC_SS); rfid.PCD_Init();
  prefs.begin("beertap", false);
  apiUrl = prefs.getString("url", "https://lavafacilexpress.com/beerexpress/api.php");
  devKey = prefs.getString("key", ""); pendente = prefs.getString("pend", "");
  WiFi.mode(WIFI_STA);
  portal(devKey.isEmpty());
  sensors.begin(); sensors.setWaitForConversion(false); sensors.requestTemperatures();

  // Reiniciou no meio de uma servida? Fecha cobrando o último volume salvo
  int pid = prefs.getInt("pid", 0), cid = prefs.getInt("cid", 0); uint32_t last = prefs.getUInt("ppl", 0);
  if (pid) { JsonDocument d; d["pour_id"] = pid; d["ml"] = ml(last); d["pulsos"] = last; d["fim"] = true; d["erro"] = "reinicio_durante_servico"; post("dev/pour", d, true); prefs.putInt("pid", 0); }
  if (cid) { JsonDocument d; d["id"] = cid; d["status"] = "erro"; d["pulsos"] = last; d["servido_ml"] = ml(last); d["erro"] = "reinicio_durante_servico"; post("dev/report", d, true); prefs.putInt("cid", 0); }

#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt = {.timeout_ms = 30000, .idle_core_mask = 0, .trigger_panic = true}; esp_task_wdt_reconfigure(&wdt);
#else
  esp_task_wdt_init(30, true);
#endif
  esp_task_wdt_add(NULL);
  poll(); telaLivre();
}

void loop() {
  esp_task_wdt_reset();
  if (digitalRead(PIN_CFG) == LOW) { if (!cfgPressed) cfgPressed = millis(); else if (millis() - cfgPressed > 5000 && modo == LIVRE) portal(true); } else cfgPressed = 0;
  if (WiFi.status() != WL_CONNECTED && modo == LIVRE && millis() - lastWifi > 10000) { lastWifi = millis(); WiFi.reconnect(); }
  if (millis() - lastTemp > 10000) { lastTemp = millis(); float t = sensors.getTempCByIndex(0); if (t > -100 && t < 80) temperatura = t; sensors.requestTemperatures(); }

  // Leitor RFID (ignora o mesmo cartão por 2 s para não "repicar")
  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid; for (byte i = 0; i < rfid.uid.size; i++) { if (rfid.uid.uidByte[i] < 16) uid += "0"; uid += String(rfid.uid.uidByte[i], HEX); }
    uid.toUpperCase(); rfid.PICC_HaltA();
    if (uid != ultimoUid || millis() - ultimoUidEm > 2000) { ultimoUidEm = millis(); cartaoLido(uid); }
    ultimoUidEm = millis();
  }

  if (modo == PASS) cuidarPour();
  else if (modo == COPO_ARMADO || modo == COPO_SERVINDO) cuidarCopo();
  else if (modo == MSG && millis() > msgAte) { modo = LIVRE; telaLivre(); }

  if (modo != PASS && modo != COPO_SERVINDO && millis() - lastPoll > pollMs) { lastPoll = millis(); flushPendente(); poll(); vigiar(); }
  delay(10);
}
