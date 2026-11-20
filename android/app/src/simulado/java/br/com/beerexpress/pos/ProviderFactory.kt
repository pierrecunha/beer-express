package br.com.beerexpress.pos

import android.app.Activity
import android.app.AlertDialog
import android.app.Application

/** Flavor "simulado": roda em qualquer celular/tablet Android para testar o fluxo inteiro. */
object ProviderFactory {
    fun init(app: Application) {}
    fun create(a: Activity): PaymentProvider = object : PaymentProvider {
        override val name = "simulado"
        override fun pay(req: PayRequest, done: (PayResult) -> Unit) {
            AlertDialog.Builder(a).setTitle("Pagamento simulado")
                .setMessage("${req.metodo.uppercase()} de R$ %.2f\nPedido ${req.ref}".format(req.centavos / 100.0))
                .setCancelable(false)
                .setPositiveButton("Aprovar") { _, _ -> done(PayResult(true, "SIM-${req.ref}", "000000", "TESTE", "0000")) }
                .setNegativeButton("Recusar") { _, _ -> done(PayResult(false, motivo = "Recusado (simulação)")) }
                .show()
        }
    }
}
