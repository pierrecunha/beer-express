package br.com.beerexpress.pos

/** Pedido de cobrança vindo da tela (pos.html). */
data class PayRequest(val ref: String, val centavos: Long, val metodo: String, val descricao: String)

/** Resultado devolvido à tela. */
data class PayResult(
    val aprovado: Boolean,
    val pagamentoId: String? = null,
    val nsu: String? = null,
    val bandeira: String? = null,
    val finalCartao: String? = null,
    val motivo: String? = null,
)

/** Cada adquirente (Mercado Pago, PagBank, simulado) implementa esta interface. */
interface PaymentProvider {
    val name: String
    /** Deve chamar [done] exatamente uma vez (em qualquer thread). */
    fun pay(req: PayRequest, done: (PayResult) -> Unit)
    /** Ativa a maquininha na adquirente (ex.: Stone Code). Quem não precisa de ativação já devolve sucesso. */
    fun activate(code: String, done: (Boolean, String) -> Unit) = done(true, "ok")
}
