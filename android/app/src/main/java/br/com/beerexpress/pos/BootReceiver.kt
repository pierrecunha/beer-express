package br.com.beerexpress.pos

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Abre o Beer Express automaticamente quando a maquininha liga. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED)
            ctx.startActivity(Intent(ctx, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
