/* =====================================================================
 *  BEER EXPRESS — Controlador da chopeira (ESP32)
 *  Firmware 1.0.0
 *
 *  O que faz:
 *   • Consulta o servidor (api.php?r=dev/poll) e recebe o próximo copo pago
 *   • Modo "flow": abre a válvula solenoide e conta os pulsos do fluxômetro;
 *     FECHA A VÁLVULA DENTRO DA INTERRUPÇÃO ao atingir o volume (precisão
 *     independente da internet)
 *   • Modo "pulse": envia N pulsos para uma controladora de chopeira externa
 *   • Botão iluminado por torneira (opcional) para o cliente iniciar
 *   • Telemetria: temperatura do freezer (DS18B20), porta (reed), Wi-Fi
 *   • Segurança: sem fluxo → fecha; tempo máximo; fluxo sem pedido → alerta;
 *     reinício no meio do serviço → reporta o volume parcial
 *
 *  Bibliotecas (Arduino IDE → Gerenciar bibliotecas):
 *   WiFiManager (tzapu) • ArduinoJson 7 • OneWire • DallasTemperature
 *  Placa: ESP32 Dev Module (core esp32 da Espressif 2.x ou 3.x)
 *
 *  1ª vez: o ESP cria a rede "BeerExpress-XXXX" (senha beerexpress).
 *  Conecte pelo celular, escolha o Wi-Fi do ponto e preencha:
 *   - URL da API: https://lavafacilexpress.com/beerexpress/api.php
 *   - Chave do dispositivo: (painel → Chopeiras e barris → Controladores)
 *  Para reconfigurar: segure o botão BOOT (GPIO0) por 5 s.
 * ===================================================================== */
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

#define FW_VERSION "1.0.0"

/* ------------------- PINAGEM (ajuste conforme sua placa) ------------------- */
#define MAX_CH 4
const uint8_t PIN_VALVE[MAX_CH]  = {26, 27, 14, 12};  // relé/MOSFET da solenoide (ou saída de pulso)
const uint8_t PIN_FLOW[MAX_CH]   = {34, 35, 32, 33};  // sinal do fluxômetro (com pull-up externo 10k p/ 3V3)
const uint8_t PIN_BTN[MAX_CH]    = {18, 19, 21, 22};  // botão iluminado (contato NA ao GND)
const uint8_t PIN_LED[MAX_CH]    = {23, 25, 13, 15};  // LED do botão (via transistor)
#define PIN_TEMP   4      // DS18B20 (resistor 4k7 entre dados e 3V3)
#define PIN_DOOR   5      // reed switch da porta do freezer (opcional, ao GND)
#define PIN_CFG    0      // botão BOOT: segure 5 s para reconfigurar Wi-Fi
#define VALVE_ON   HIGH   // inverta se o seu relé for acionado em LOW
#define VALVE_OFF  LOW

/* ------------------- parâmetros de segurança ------------------- */
#define SEM_FLUXO_MS       6000   // abriu e não passou nada → fecha (barril vazio/CO2)
#define FLUXO_PAROU_MS     5000   // parou de sair no meio → fecha
#define STRAY_ML           40     // fluxo sem pedido acima disso → alerta
#define PULSO_MS           60     // largura do pulso no modo "pulse"

/* ------------------- estado global ------------------- */
Preferences prefs;
String apiUrl, devKey;
OneWire oneWire(PIN_TEMP);
DallasTemperature sensors(&oneWire);

DRAM_ATTR uint8_t valvePins[MAX_CH];            // cópia em RAM: a ISR não pode ler da flash
volatile uint32_t pulseCount[MAX_CH] = {0};
volatile uint32_t pulseTarget[MAX_CH] = {0};   // 0 = sem alvo (válvula fechada)
float ppl[MAX_CH] = {1925, 1925, 1925, 1925};   // pulsos por litro (vem do servidor)

enum Estado { LIVRE, ARMADO, SERVINDO };
struct Copo {
  Estado estado = LIVRE;
  int id = 0, canal = 0, volume = 0, alvo = 0, timeoutS = 120;
  String modo = "flow", startMode = "button";
  uint32_t armadoEm = 0, inicioEm = 0, ultimoPulsoEm = 0, ultimoReport = 0, ultimaContagem = 0;
} copo;

uint32_t pollMs = 2000, lastPoll = 0, lastWifi = 0, lastTemp = 0, cfgPressed = 0, lastStray[MAX_CH] = {0}, strayBase[MAX_CH] = {0};
float temperatura = -127;

/* ------------------- interrupções do fluxômetro ------------------- */
void IRAM_ATTR onPulse(int ch) {
  pulseCount[ch]++;
  if (pulseTarget[ch] && pulseCount[ch] >= pulseTarget[ch]) {   // atingiu o volume → fecha já
    uint8_t pin = valvePins[ch];   // escrita direta no registrador (segura dentro da interrupção; pinos < 32)
    if (VALVE_OFF == LOW) REG_WRITE(GPIO_OUT_W1TC_REG, BIT(pin)); else REG_WRITE(GPIO_OUT_W1TS_REG, BIT(pin));
    pulseTarget[ch] = 0;
  }
}
void IRAM_ATTR isr0() { onPulse(0); }
void IRAM_ATTR isr1() { onPulse(1); }
void IRAM_ATTR isr2() { onPulse(2); }
void IRAM_ATTR isr3() { onPulse(3); }

/* ------------------- HTTP ------------------- */
bool http(const String& method, const String& route, const String& body, JsonDocument* out) {
  if (WiFi.status() != WL_CONNECTED) return false;
  WiFiClientSecure tls; tls.setInsecure();   // TLS sem validar certificado (ver README p/ fixar o certificado)
  WiFiClient plain;
  HTTPClient h;
  String url = apiUrl + (apiUrl.indexOf('?') >= 0 ? "&" : "?") + "r=" + route;
  bool ok = apiUrl.startsWith("https") ? h.begin(tls, url) : h.begin(plain, url);
  if (!ok) return false;
  h.setTimeout(4000);
  h.addHeader("X-Device-Key", devKey);
  h.addHeader("Content-Type", "application/json");
  int code = method == "POST" ? h.POST(body) : h.GET();
  bool success = code == 200;
  if (out && code > 0) deserializeJson(*out, h.getString());
  h.end();
  return success;
}

/* Relatórios pendentes: se a internet cair, reenvia até o servidor confirmar */
String pendente = "";
void report(int id, const char* status, uint32_t pulsos, int ml, const char* erro = "") {
  JsonDocument d;
  d["id"] = id; d["status"] = status; d["pulsos"] = pulsos; d["servido_ml"] = ml;
  if (erro[0]) d["erro"] = erro;
  String b; serializeJson(d, b);
  if (!http("POST", "dev/report", b, nullptr) && strcmp(status, "servindo") != 0) {
    pendente = b; prefs.putString("pend", b);
  }
}
void flushPendente() {
  if (pendente.length() && http("POST", "dev/report", pendente, nullptr)) { pendente = ""; prefs.remove("pend"); }
}

int mlDe(int ch, uint32_t pulsos) { return (int)lround(pulsos * 1000.0 / max(1.0f, ppl[ch])); }

/* ------------------- LEDs ------------------- */
void leds() {
  for (int i = 0; i < MAX_CH; i++) {
    bool on = false;
    if (copo.estado == ARMADO && copo.canal - 1 == i) on = (millis() / 400) % 2;   // pisca: "aperte aqui"
    if (copo.estado == SERVINDO && copo.canal - 1 == i) on = true;
    digitalWrite(PIN_LED[i], on);
  }
}

/* ------------------- serviço ------------------- */
void persistir() {
  prefs.putInt("cid", copo.estado == SERVINDO ? copo.id : 0);
  prefs.putInt("cch", copo.canal);
}
void iniciar() {
  int ch = copo.canal - 1;
  copo.estado = SERVINDO; copo.inicioEm = copo.ultimoPulsoEm = millis(); copo.ultimaContagem = 0;
  persistir();
  if (copo.modo == "pulse") {                       // controladora externa
    report(copo.id, "servindo", 0, 0);
    for (int i = 0; i < copo.alvo; i++) {
      digitalWrite(PIN_VALVE[ch], VALVE_ON); delay(PULSO_MS);
      digitalWrite(PIN_VALVE[ch], VALVE_OFF); delay(PULSO_MS);
      esp_task_wdt_reset();
    }
    report(copo.id, "concluido", copo.alvo, copo.volume);
    copo = Copo(); persistir();
    return;
  }
  noInterrupts(); pulseCount[ch] = 0; pulseTarget[ch] = copo.alvo; interrupts();
  digitalWrite(PIN_VALVE[ch], VALVE_ON);
  report(copo.id, "servindo", 0, 0);
}
void finalizar(const char* status, const char* erro = "") {
  int ch = copo.canal - 1;
  noInterrupts(); pulseTarget[ch] = 0; interrupts();
  digitalWrite(PIN_VALVE[ch], VALVE_OFF);
  delay(400);                                       // escoamento final ainda conta
  uint32_t p = pulseCount[ch];
  report(copo.id, status, p, mlDe(ch, p), erro);
  strayBase[ch] = pulseCount[ch];
  copo = Copo(); persistir();
}
void cuidarDoCopo() {
  if (copo.estado == ARMADO) {
    int ch = copo.canal - 1;
    bool botao = digitalRead(PIN_BTN[ch]) == LOW;
    if (botao && copo.startMode != "auto") { delay(30); if (digitalRead(PIN_BTN[ch]) == LOW) iniciar(); }
    else if (millis() - copo.armadoEm > (uint32_t)copo.timeoutS * 1000UL) { report(copo.id, "timeout", 0, 0, "cliente nao iniciou"); copo = Copo(); }
    return;
  }
  if (copo.estado != SERVINDO) return;
  int ch = copo.canal - 1;
  uint32_t p = pulseCount[ch];
  if (p != copo.ultimaContagem) { copo.ultimaContagem = p; copo.ultimoPulsoEm = millis(); }
  uint32_t maxMs = 20000UL + (uint32_t)copo.volume * 60UL;   // 500 ml → 50 s
  if (p >= (uint32_t)copo.alvo || pulseTarget[ch] == 0) finalizar("concluido");
  else if (p == 0 && millis() - copo.inicioEm > SEM_FLUXO_MS) finalizar("erro", "sem_fluxo (barril vazio ou CO2?)");
  else if (p > 0 && millis() - copo.ultimoPulsoEm > FLUXO_PAROU_MS) finalizar("erro", "fluxo_interrompido");
  else if (millis() - copo.inicioEm > maxMs) finalizar("erro", "tempo_maximo");
  else if (millis() - copo.ultimoReport > 1000) {          // progresso para a tela do cliente
    copo.ultimoReport = millis();
    prefs.putUInt("cpl", p);
    report(copo.id, "servindo", p, mlDe(ch, p));
  }
}

/* Fluxo com a chopeira livre = vazamento / válvula travada / torneira forçada */
void vigiarFluxoSemPedido() {
  for (int i = 0; i < MAX_CH; i++) {
    if (copo.estado == SERVINDO && copo.canal - 1 == i) continue;
    uint32_t extra = pulseCount[i] - strayBase[i];
    if (mlDe(i, extra) >= STRAY_ML && millis() - lastStray[i] > 60000) {
      lastStray[i] = millis();
      JsonDocument d; d["canal"] = i + 1; d["ml"] = mlDe(i, extra); d["pulsos"] = extra;
      String b; serializeJson(d, b); http("POST", "dev/stray", b, nullptr);
      strayBase[i] = pulseCount[i];
    }
  }
}

/* ------------------- poll ------------------- */
void poll() {
  JsonDocument r;
  String q = "dev/poll&fw=" FW_VERSION "&rssi=" + String(WiFi.RSSI()) + "&heap=" + String(ESP.getFreeHeap()) +
             "&up=" + String(millis() / 1000) + "&door=" + String(digitalRead(PIN_DOOR) == HIGH ? 1 : 0);
  if (temperatura > -100) q += "&temp=" + String(temperatura, 2);
  if (!http("GET", q, "", &r)) { pollMs = 3000; return; }
  pollMs = r["poll_ms"] | 2000;
  for (JsonObject t : r["taps"].as<JsonArray>()) {
    int c = (t["canal"] | 0) - 1;
    if (c >= 0 && c < MAX_CH) ppl[c] = t["ppl"] | 1925.0f;
  }
  JsonObject cmd = r["cmd"];
  if (cmd.isNull()) { if (copo.estado == ARMADO) copo = Copo(); return; }   // cancelado no servidor
  int id = cmd["id"];
  if (copo.estado == LIVRE || copo.id != id) {
    if (copo.estado == SERVINDO) return;                                    // termina o atual antes
    copo = Copo();
    copo.id = id; copo.canal = cmd["canal"]; copo.volume = cmd["volume_ml"]; copo.alvo = cmd["pulsos_alvo"];
    copo.modo = (const char*)(cmd["modo"] | "flow"); copo.startMode = (const char*)(cmd["start_mode"] | "button");
    copo.timeoutS = cmd["timeout_s"] | 120; copo.estado = ARMADO; copo.armadoEm = millis();
    if (copo.canal < 1 || copo.canal > MAX_CH) { report(id, "erro", 0, 0, "canal_invalido"); copo = Copo(); return; }
  }
  if (copo.estado == ARMADO && (cmd["go"] | false)) iniciar();              // SERVIR na tela / automático
}

/* ------------------- setup ------------------- */
void portalConfig(bool forcar) {
  WiFiManager wm;
  WiFiManagerParameter pUrl("url", "URL da API", apiUrl.c_str(), 150);
  WiFiManagerParameter pKey("key", "Chave do dispositivo", devKey.c_str(), 64);
  wm.addParameter(&pUrl); wm.addParameter(&pKey);
  wm.setConfigPortalTimeout(300);
  String ap = "BeerExpress-" + String((uint32_t)ESP.getEfuseMac(), HEX).substring(0, 4);
  bool ok = forcar ? wm.startConfigPortal(ap.c_str(), "beerexpress") : wm.autoConnect(ap.c_str(), "beerexpress");
  if (strlen(pUrl.getValue())) { apiUrl = pUrl.getValue(); prefs.putString("url", apiUrl); }
  if (strlen(pKey.getValue())) { devKey = pKey.getValue(); prefs.putString("key", devKey); }
  if (!ok) ESP.restart();
}

void setup() {
  Serial.begin(115200);
  for (int i = 0; i < MAX_CH; i++) {
    valvePins[i] = PIN_VALVE[i];
    pinMode(PIN_VALVE[i], OUTPUT); digitalWrite(PIN_VALVE[i], VALVE_OFF);   // primeiro de tudo: válvulas fechadas
    pinMode(PIN_LED[i], OUTPUT); digitalWrite(PIN_LED[i], LOW);
    pinMode(PIN_BTN[i], INPUT_PULLUP);
    pinMode(PIN_FLOW[i], INPUT);   // GPIO 34-39 não têm pull-up interno → use 10k externo
  }
  attachInterrupt(digitalPinToInterrupt(PIN_FLOW[0]), isr0, FALLING);
  attachInterrupt(digitalPinToInterrupt(PIN_FLOW[1]), isr1, FALLING);
  attachInterrupt(digitalPinToInterrupt(PIN_FLOW[2]), isr2, FALLING);
  attachInterrupt(digitalPinToInterrupt(PIN_FLOW[3]), isr3, FALLING);
  pinMode(PIN_DOOR, INPUT_PULLUP);
  pinMode(PIN_CFG, INPUT_PULLUP);

  prefs.begin("beerexpress", false);
  apiUrl = prefs.getString("url", "https://lavafacilexpress.com/beerexpress/api.php");
  devKey = prefs.getString("key", "");
  pendente = prefs.getString("pend", "");

  WiFi.mode(WIFI_STA);
  portalConfig(devKey.isEmpty());
  sensors.begin(); sensors.setWaitForConversion(false); sensors.requestTemperatures();

  // Reiniciou no meio de um copo? Reporta o parcial para o servidor decidir (reliberar/estornar)
  int cid = prefs.getInt("cid", 0);
  if (cid) {
    int ch = constrain(prefs.getInt("cch", 1), 1, MAX_CH) - 1;
    uint32_t p = prefs.getUInt("cpl", 0);
    report(cid, "erro", p, mlDe(ch, p), "reinicio_durante_servico");
    prefs.putInt("cid", 0);
  }

#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt = {.timeout_ms = 30000, .idle_core_mask = 0, .trigger_panic = true};
  esp_task_wdt_reconfigure(&wdt);
#else
  esp_task_wdt_init(30, true);
#endif
  esp_task_wdt_add(NULL);
  Serial.println("Beer Express " FW_VERSION " pronto");
}

/* ------------------- loop ------------------- */
void loop() {
  esp_task_wdt_reset();

  if (digitalRead(PIN_CFG) == LOW) { if (!cfgPressed) cfgPressed = millis(); else if (millis() - cfgPressed > 5000 && copo.estado == LIVRE) portalConfig(true); }
  else cfgPressed = 0;

  if (WiFi.status() != WL_CONNECTED && copo.estado != SERVINDO && millis() - lastWifi > 10000) { lastWifi = millis(); WiFi.reconnect(); }

  if (millis() - lastTemp > 10000) {
    lastTemp = millis();
    float t = sensors.getTempCByIndex(0);
    if (t > -100 && t < 80) temperatura = t;
    sensors.requestTemperatures();
  }

  cuidarDoCopo();
  leds();

  if (millis() - lastPoll > pollMs && copo.estado != SERVINDO) {
    lastPoll = millis();
    flushPendente();
    poll();
    if (copo.estado == LIVRE) vigiarFluxoSemPedido();
  }
  delay(10);
}
