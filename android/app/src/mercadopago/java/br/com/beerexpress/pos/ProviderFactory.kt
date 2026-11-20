package br.com.beerexpress.pos

import android.app.Activity
import android.app.Application
// Pacotes do SDK nativo do Point Smart (kit de integração Mercado Pago — Main Apps).
// Se o kit vier com outro pacote, ajuste apenas estes imports.
import com.mercadolibre.android.point_integration_sdk.nativesdk.MPManager
import com.mercadolibre.android.point_integration_sdk.nativesdk.payment.data.PaymentFlowRequestData
import com.mercadolibre.android.point_integration_sdk.nativesdk.payment.data.PaymentMethod
import com.mercadolibre.android.point_integration_sdk.nativesdk.payment.data.PaymentTransactionMetadata

/**
 * Mercado Pago Point Smart / Point Smart 2 (SmartApp do tipo Main App).
 * - external_reference = ref do pedido → o servidor confere na API do MP (mp_verificar_cartao)
 * - description = ref (o MP exige descrição única por transação)
 */
object ProviderFactory {
    fun init(app: Application) { MPManager.initialize(app) }

    fun create(a: Activity): PaymentProvider = object : PaymentProvider {
        override val name = "mercadopago"
        override fun pay(req: PayRequest, done: (PayResult) -> Unit) {
            val metodo = if (req.metodo == "debito") PaymentMethod.DEBIT_CARD.name else PaymentMethod.CREDIT_CARD.name
            val data = PaymentFlowRequestData(
                amount = req.centavos / 100.0,
                description = req.ref,
                paymentMethod = metodo,
                installments = 1,
                printOnTerminal = false,
                paymentTransactionMetadata = PaymentTransactionMetadata(externalReference = req.ref),
            )
            try {
                MPManager.paymentFlow.launchPaymentFlow(paymentFlowRequestData = data) { response ->
                    response.doIfSuccess { r ->
                        val erro = r.paymentStatusError
                        if (erro.isNullOrBlank()) done(PayResult(true, r.paymentReference, null, r.paymentMethod?.name, r.paymentLastFourDigits))
                        else done(PayResult(false, motivo = erro))
                    }.doIfError { e -> done(PayResult(false, motivo = e.message ?: "Pagamento não concluído")) }
                }
            } catch (e: Exception) {
                done(PayResult(false, motivo = "Erro no SDK: ${e.message}"))
            }
        }
    }
}
