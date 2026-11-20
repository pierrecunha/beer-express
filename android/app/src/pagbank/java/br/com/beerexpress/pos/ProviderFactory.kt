package br.com.beerexpress.pos

import android.app.Activity
import android.app.Application
import br.com.uol.pagseguro.plugpagservice.wrapper.PlugPag
import br.com.uol.pagseguro.plugpagservice.wrapper.PlugPagActivationData
import br.com.uol.pagseguro.plugpagservice.wrapper.PlugPagPaymentData
import java.util.concurrent.Executors

/**
 * PagBank Moderninha Smart / Smart 2 via PlugPagServiceWrapper.
 * As chamadas do PlugPag são bloqueantes → rodam numa thread separada.
 */
object ProviderFactory {
    private val exec = Executors.newSingleThreadExecutor()
    fun init(app: Application) {}

    fun create(a: Activity): PaymentProvider = object : PaymentProvider {
        override val name = "pagbank"
        private val plugPag by lazy { PlugPag(a.applicationContext) }

        override fun pay(req: PayRequest, done: (PayResult) -> Unit) {
            exec.execute {
                try {
                    if (!plugPag.isAuthenticated()) {
                        val act = plugPag.initializeAndActivatePinpad(PlugPagActivationData(BuildConfig.PAGBANK_ACTIVATION))
                        if (act.result != PlugPag.RET_OK) { done(PayResult(false, motivo = "Ativação PagBank: ${act.errorMessage}")); return@execute }
                    }
                    val tipo = if (req.metodo == "debito") PlugPag.TYPE_DEBITO else PlugPag.TYPE_CREDITO
                    val data = PlugPagPaymentData(tipo, req.centavos.toInt(), PlugPag.INSTALLMENT_TYPE_A_VISTA, 1,
                        req.ref.filter { it.isLetterOrDigit() }.takeLast(10), false)
                    val r = plugPag.doPayment(data)
                    if (r.result == PlugPag.RET_OK)
                        done(PayResult(true, r.transactionCode, r.hostNsu, r.cardBrand, r.holder?.takeLast(4)))
                    else done(PayResult(false, motivo = r.message ?: "Pagamento não aprovado"))
                } catch (e: Exception) {
                    done(PayResult(false, motivo = "Erro PlugPag: ${e.message}"))
                }
            }
        }
    }
}
