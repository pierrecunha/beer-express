package br.com.beerexpress.pos

import android.app.Activity
import android.app.AlertDialog
import android.app.Application
import android.content.Context
import stone.application.StoneStart
import stone.application.enums.Action
import stone.application.enums.InstalmentTransactionEnum
import stone.application.enums.TypeOfTransactionEnum
import stone.application.interfaces.StoneActionCallback
import stone.application.interfaces.StoneCallbackInterface
import stone.database.transaction.TransactionObject
import stone.environment.Environment
import stone.providers.ActiveApplicationProvider
import stone.providers.PosTransactionProvider
import stone.utils.Stone

/**
 * Stone (Sunmi P2 e outras maquininhas Stone) via Stone SDK 4.x.
 *  - Ativação: o Stone Code vem do servidor (painel) ou é digitado no assistente de instalação.
 *  - Cobrança: crédito/débito pela leitora da própria maquininha.
 * Se o painel marcar a maquininha como "Simulado", este mesmo APK roda a simulação (útil para testar o fluxo).
 */
object ProviderFactory {
    fun init(app: Application) {
        try { Stone.setAppName("Beer Express"); Stone.setEnvironment(Environment.PRODUCTION); StoneStart.init(app) } catch (_: Throwable) {}
    }

    private fun providerNome(a: Context) = a.getSharedPreferences("beerexpress", Context.MODE_PRIVATE).getString("provider", "stone")

    fun create(a: Activity): PaymentProvider = object : PaymentProvider {
        override val name get() = providerNome(a) ?: "stone"

        override fun activate(code: String, done: (Boolean, String) -> Unit) {
            if (name != "stone") return done(true, "ok")
            try {
                val p = ActiveApplicationProvider(a)
                p.dialogMessage = "Ativando a maquininha…"
                p.dialogTitle = "Stone"
                p.useDefaultUI(false)
                p.connectionCallback = object : StoneCallbackInterface {
                    override fun onSuccess() { done(true, "Maquininha ativada na Stone") }
                    override fun onError() { done(false, "Não foi possível ativar. Confira o Stone Code e a internet.") }
                }
                p.activate(code.trim())
            } catch (e: Throwable) { done(false, "Erro na ativação: ${e.message}") }
        }

        override fun pay(req: PayRequest, done: (PayResult) -> Unit) {
            if (name != "stone") return simulado(a, req, done)
            try {
                val tx = TransactionObject().apply {
                    amount = req.centavos.toString()
                    instalmentTransaction = InstalmentTransactionEnum.ONE_INSTALMENT
                    typeOfTransaction = if (req.metodo == "debito") TypeOfTransactionEnum.DEBIT else TypeOfTransactionEnum.CREDIT
                    isCapture = true
                    initiatorTransactionKey = req.ref
                }
                val user = Stone.getUserModel(0) ?: return done(PayResult(false, motivo = "Maquininha não ativada na Stone"))
                val p = PosTransactionProvider(a, tx, user)
                p.connectionCallback = object : StoneActionCallback {
                    override fun onStatusChanged(action: Action?) {}
                    override fun onSuccess() {
                        done(PayResult(true, tx.acquirerTransactionKey ?: tx.initiatorTransactionKey, tx.authorizationCode,
                            tx.cardBrand, tx.cardHolderNumber?.takeLast(4)))
                    }
                    override fun onError() { done(PayResult(false, motivo = p.listOfErrors?.toString() ?: "Pagamento não aprovado")) }
                }
                p.execute()
            } catch (e: Throwable) { done(PayResult(false, motivo = "Erro Stone: ${e.message}")) }
        }
    }

    private fun simulado(a: Activity, req: PayRequest, done: (PayResult) -> Unit) {
        AlertDialog.Builder(a).setTitle("Pagamento simulado")
            .setMessage("${req.metodo.uppercase()} de R$ %.2f\nPedido ${req.ref}".format(req.centavos / 100.0)).setCancelable(false)
            .setPositiveButton("Aprovar") { _, _ -> done(PayResult(true, "SIM-${req.ref}", "000000", "TESTE", "0000")) }
            .setNegativeButton("Recusar") { _, _ -> done(PayResult(false, motivo = "Recusado (simulação)")) }.show()
    }
}
