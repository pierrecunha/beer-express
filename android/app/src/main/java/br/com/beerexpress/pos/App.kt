package br.com.beerexpress.pos

import android.app.Application

class App : Application() {
    override fun onCreate() {
        super.onCreate()
        ProviderFactory.init(this)   // inicializa o SDK da adquirente (MP/PagBank) quando houver
    }
}
