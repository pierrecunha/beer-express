package br.com.beerexpress.pos

import android.annotation.SuppressLint
import android.app.AlertDialog
import android.content.Context
import android.graphics.Color
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.wifi.WifiConfiguration
import android.net.wifi.WifiManager
import android.net.wifi.WifiNetworkSuggestion
import android.provider.Settings
import android.text.InputType
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import android.webkit.*
import android.widget.EditText
import android.widget.FrameLayout
import android.speech.tts.TextToSpeech
import android.widget.TextView
import java.util.Locale
import androidx.appcompat.app.AppCompatActivity
import org.json.JSONObject

/**
 * Casca nativa do Beer Express:
 *  - Mostra a tela pos.html do servidor em tela cheia (modo quiosque)
 *  - Expõe a ponte JavaScript "BeerPOS" para a página chamar o pagamento da maquininha
 *  - A interface fica no servidor: você atualiza a tela sem reinstalar/homologar o APK
 */
class MainActivity : AppCompatActivity() {

    private lateinit var web: WebView
    private lateinit var offline: TextView
    private lateinit var provider: PaymentProvider
    private val ui = Handler(Looper.getMainLooper())
    private val retry = Runnable { web.loadUrl(posUrl) }
    private var cornerTaps = 0
    private var lastCornerTap = 0L
    private var tts: TextToSpeech? = null
    private var ttsPronto = false

    private val prefs by lazy { getSharedPreferences("beerexpress", Context.MODE_PRIVATE) }
    private val posUrl get() = prefs.getString("pos_url", null)?.takeIf { it.isNotBlank() } ?: (BuildConfig.SERVER_URL.trimEnd('/') + "/pos.html")
    private val pin get() = prefs.getString("pin", null)?.takeIf { it.length >= 4 } ?: BuildConfig.ADMIN_PIN

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        provider = ProviderFactory.create(this)
        tts = TextToSpeech(this) { st -> if (st == TextToSpeech.SUCCESS) { tts?.language = Locale("pt", "BR"); ttsPronto = true } }

        web = WebView(this).apply {
            setBackgroundColor(Color.parseColor("#0E0B07"))
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.cacheMode = WebSettings.LOAD_DEFAULT
            settings.mediaPlaybackRequiresUserGesture = false
            settings.textZoom = 100
            addJavascriptInterface(Bridge(), "BeerPOS")
            webViewClient = object : WebViewClient() {
                override fun onReceivedError(v: WebView, req: WebResourceRequest, err: WebResourceError) {
                    if (req.isForMainFrame && req.url.scheme != "file") showOffline()
                }
                override fun onReceivedSslError(v: WebView, h: SslErrorHandler, e: SslError) { h.cancel(); showOffline() }
                override fun onPageFinished(v: WebView, url: String) { offline.visibility = View.GONE }
                override fun shouldOverrideUrlLoading(v: WebView, req: WebResourceRequest): Boolean {
                    // Só navega dentro do próprio servidor
                    return req.url.scheme != "file" && req.url.host != android.net.Uri.parse(posUrl).host
                }
            }
            webChromeClient = WebChromeClient()
        }
        offline = TextView(this).apply {
            text = "Sem conexão com o servidor.\nTentando novamente…"
            setTextColor(Color.WHITE); textSize = 18f; gravity = android.view.Gravity.CENTER
            setBackgroundColor(Color.parseColor("#0E0B07")); visibility = View.GONE
        }
        setContentView(FrameLayout(this).apply { addView(web); addView(offline) })
        abrirInicio()
        immersive()
    }

    /** Primeira vez (ou depois de "refazer instalação"): assistente. Depois: a tela da maquininha. */
    private fun abrirInicio() {
        if (prefs.getBoolean("setup_ok", false)) web.loadUrl(posUrl) else web.loadUrl("file:///android_asset/setup.html")
    }

    private fun js(code: String) = ui.post { web.evaluateJavascript(code, null) }

    private fun showOffline() {
        offline.visibility = View.VISIBLE
        ui.removeCallbacks(retry)
        ui.postDelayed(retry, 5000)
    }

    /* ---------- ponte JS ↔ nativo ---------- */
    override fun onDestroy() { tts?.shutdown(); super.onDestroy() }

    inner class Bridge {
        /** Voz do totem (português do Brasil) — a página chama BeerPOS.speak("texto"). */
        @JavascriptInterface fun speak(texto: String) { if (ttsPronto) tts?.speak(texto, TextToSpeech.QUEUE_FLUSH, null, "be") }
        @JavascriptInterface fun getToken(): String = prefs.getString("terminal_token", "") ?: ""
        @JavascriptInterface fun saveToken(t: String) { prefs.edit().putString("terminal_token", t).apply { if (t.isNotEmpty()) putBoolean("setup_ok", true) }.apply() }

        /* ----- assistente de instalação (setup.html) ----- */
        @JavascriptInterface fun online(): Boolean {
            val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val c = cm.getNetworkCapabilities(cm.activeNetwork) ?: return false
            return c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        }
        /** HTTP feito pelo app (sem CORS). Resposta: window.__http(id, status, corpo). */
        @JavascriptInterface fun http(id: String, method: String, url: String, body: String) {
            thread {
                var status = 0; var out = ""
                try {
                    val c = URL(url).openConnection() as HttpURLConnection
                    c.requestMethod = method; c.connectTimeout = 12000; c.readTimeout = 20000
                    c.setRequestProperty("Content-Type", "application/json")
                    if (body.isNotEmpty()) { c.doOutput = true; c.outputStream.use { it.write(body.toByteArray()) } }
                    status = c.responseCode
                    out = (if (status in 200..399) c.inputStream else c.errorStream)?.bufferedReader()?.readText() ?: ""
                } catch (e: Exception) { out = e.message ?: "sem conexão" }
                js("window.__http(${JSONObject.quote(id)}, $status, ${JSONObject.quote(out)})")
            }
        }
        /** Conecta no Wi-Fi do ponto. Android 10+: sugestão de rede (o sistema pode pedir um OK); antes: configuração direta. */
        @JavascriptInterface fun wifiConnect(ssid: String, pass: String): String {
            return try {
                val wm = applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
                if (Build.VERSION.SDK_INT >= 29) {
                    val b = WifiNetworkSuggestion.Builder().setSsid(ssid).setIsAppInteractionRequired(false)
                    if (pass.isNotEmpty()) b.setWpa2Passphrase(pass)
                    wm.removeNetworkSuggestions(emptyList())
                    val r = wm.addNetworkSuggestions(listOf(b.build()))
                    if (r == WifiManager.STATUS_NETWORK_SUGGESTIONS_SUCCESS || r == WifiManager.STATUS_NETWORK_SUGGESTIONS_ERROR_ADD_DUPLICATE) "ok" else "erro:$r"
                } else {
                    @Suppress("DEPRECATION") run {
                        val cfg = WifiConfiguration().apply {
                            SSID = "\"$ssid\""
                            if (pass.isEmpty()) allowedKeyManagement.set(WifiConfiguration.KeyMgmt.NONE) else preSharedKey = "\"$pass\""
                        }
                        val id = wm.addNetwork(cfg)
                        if (id == -1) "erro:addNetwork" else { wm.disconnect(); wm.enableNetwork(id, true); wm.reconnect(); "ok" }
                    }
                }
            } catch (e: Exception) { "erro:${e.message}" }
        }
        @JavascriptInterface fun openWifiSettings() { ui.post { try { startActivity(Intent(Settings.ACTION_WIFI_SETTINGS)) } catch (_: Exception) {} } }
        /** Ativa a maquininha na adquirente (Stone Code). Resposta: window.__stone(ok, mensagem). */
        @JavascriptInterface fun stoneActivate(code: String) {
            ui.post { provider.activate(code) { ok, msg -> js("window.__stone($ok, ${JSONObject.quote(msg)})") } }
        }
        /** Guarda o que o assistente descobriu: {url, pin, provider, token}. */
        @JavascriptInterface fun saveSetup(json: String) {
            val j = JSONObject(json)
            prefs.edit().apply {
                if (j.optString("url").isNotBlank()) putString("pos_url", j.getString("url").trimEnd('/') + "/pos.html")
                if (j.optString("pin").length >= 4) putString("pin", j.getString("pin"))
                if (j.optString("provider").isNotBlank()) putString("provider", j.getString("provider"))
                if (j.optString("token").isNotBlank()) putString("terminal_token", j.getString("token"))
                putBoolean("setup_ok", true)
            }.apply()
        }
        /** Abre a tela da maquininha (fim do assistente). */
        @JavascriptInterface fun finishSetup() { ui.post { web.loadUrl(posUrl) } }
        /** Comando remoto do painel: reinicia o app. */
        @JavascriptInterface fun restart() {
            ui.post {
                packageManager.getLaunchIntentForPackage(packageName)?.let { startActivity(Intent.makeRestartActivityTask(it.component)) }
                Runtime.getRuntime().exit(0)
            }
        }
        @JavascriptInterface fun pinAtual(): String = pin
        @JavascriptInterface fun info(): String = JSONObject()
            .put("serial", deviceSerial())
            .put("provider", prefs.getString("provider", provider.name))
            .put("serverUrl", BuildConfig.SERVER_URL)
            .put("appVersion", BuildConfig.VERSION_NAME)
            .put("model", Build.MANUFACTURER + " " + Build.MODEL).toString()

        /** Chamado pela página: {"ref","centavos","metodo":"credito|debito","descricao"} */
        @JavascriptInterface fun pay(json: String) {
            val j = JSONObject(json)
            val req = PayRequest(j.getString("ref"), j.getLong("centavos"), j.getString("metodo"), j.optString("descricao"))
            ui.post {
                provider.pay(req) { r ->
                    val out = JSONObject().put("aprovado", r.aprovado).put("pagamento_id", r.pagamentoId ?: "")
                        .put("nsu", r.nsu ?: "").put("bandeira", r.bandeira ?: "").put("final_cartao", r.finalCartao ?: "")
                        .put("motivo", r.motivo ?: "")
                    ui.post { web.evaluateJavascript("window.__payResult(${JSONObject.quote(out.toString())})", null) }
                }
            }
        }
    }

    @SuppressLint("HardwareIds")
    private fun deviceSerial(): String = try {
        if (Build.VERSION.SDK_INT >= 26) Build.getSerial() else @Suppress("DEPRECATION") Build.SERIAL
    } catch (e: Exception) {
        android.provider.Settings.Secure.getString(contentResolver, android.provider.Settings.Secure.ANDROID_ID)
    }

    /* ---------- menu técnico: 5 toques no canto superior esquerdo + PIN ---------- */
    override fun dispatchTouchEvent(ev: MotionEvent): Boolean {
        if (ev.action == MotionEvent.ACTION_DOWN && ev.x < 90 && ev.y < 90) {
            val now = System.currentTimeMillis()
            cornerTaps = if (now - lastCornerTap < 800) cornerTaps + 1 else 1
            lastCornerTap = now
            if (cornerTaps >= 5) { cornerTaps = 0; askPin() }
        }
        return super.dispatchTouchEvent(ev)
    }

    private fun askPin() {
        val pin = EditText(this).apply { inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD; hint = "PIN" }
        AlertDialog.Builder(this).setTitle("Menu técnico").setView(pin)
            .setPositiveButton("Entrar") { _, _ -> if (pin.text.toString() == this.pin) techMenu() }
            .setNegativeButton("Cancelar", null).show()
    }

    private fun techMenu() {
        val itens = arrayOf("Recarregar tela", "Alterar URL do servidor", "Desparear maquininha", "Refazer instalação (Wi-Fi, código, Stone)", "Sair do app")
        AlertDialog.Builder(this).setTitle("Beer Express ${BuildConfig.VERSION_NAME} • ${provider.name}")
            .setItems(itens) { _, i ->
                when (i) {
                    0 -> web.reload()
                    1 -> {
                        val e = EditText(this).apply { setText(posUrl) }
                        AlertDialog.Builder(this).setTitle("URL da tela (pos.html)").setView(e)
                            .setPositiveButton("Salvar") { _, _ -> prefs.edit().putString("pos_url", e.text.toString().trim()).apply(); web.loadUrl(posUrl) }
                            .setNegativeButton("Cancelar", null).show()
                    }
                    2 -> { prefs.edit().remove("terminal_token").apply(); web.reload() }
                    3 -> { prefs.edit().remove("terminal_token").putBoolean("setup_ok", false).apply(); abrirInicio() }
                    4 -> finishAffinity()
                }
            }.show()
    }

    /* ---------- tela cheia ---------- */
    @Suppress("DEPRECATION")
    private fun immersive() {
        window.decorView.systemUiVisibility = (View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or View.SYSTEM_UI_FLAG_FULLSCREEN
                or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN)
    }
    override fun onWindowFocusChanged(hasFocus: Boolean) { super.onWindowFocusChanged(hasFocus); if (hasFocus) immersive() }
    @Deprecated("Deprecated in Java")
    override fun onBackPressed() { /* bloqueado no quiosque */ }
    override fun onResume() { super.onResume(); web.onResume() }
    override fun onPause() { web.onPause(); super.onPause() }
}
