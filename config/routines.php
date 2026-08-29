<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Tick
    |---------------------------------------------------------------------------
    | `schedule` registra `routines:tick` nello scheduler di Laravel al posto tuo.
    | Mettilo a false se preferisci gestirlo tu (supervisor, cron di sistema, k8s).
    */
    'tick' => [
        'schedule' => true,
        'limit' => 100,
    ],

    /*
    |---------------------------------------------------------------------------
    | Lock
    |---------------------------------------------------------------------------
    | Per quanto un worker tiene una routine prima di essere considerato morto.
    | Deve essere piu' lungo del fire piu' lento che ti aspetti: troppo corto e due
    | worker girano insieme; troppo lungo e una routine bloccata resta ferma a lungo.
    */
    'lock_seconds' => 900,

    /*
    |---------------------------------------------------------------------------
    | Recupero delle occorrenze perse
    |---------------------------------------------------------------------------
    | Il tetto e' una scelta di sicurezza, non di performance: una routine ogni 5
    | minuti ferma per un giorno ha 288 occorrenze arretrate, e recuperarle tutte
    | significa mandare 288 email in trenta secondi.
    */
    'catch_up_cap' => 25,

    /*
    |---------------------------------------------------------------------------
    | Tentativi
    |---------------------------------------------------------------------------
    | Backoff esponenziale a partire da questa base: 1', 2', 4', 8'...
    */
    'retry_base_seconds' => 60,

    /*
    |---------------------------------------------------------------------------
    | Bersagli
    |---------------------------------------------------------------------------
    */
    'targets' => [

        'job' => [
            'enabled' => true,

            /*
            | I job che una routine puo' mettere in coda.
            |
            | Vuoto per default, ed e' deliberato: il payload di una routine e' dato
            | (form, import, in futuro una proposta di un assistente), e istanziare
            | la classe che ci trovi dentro significherebbe dare a quel dato il
            | diritto di eseguire qualsiasi cosa esista nell'applicazione. Elenca qui
            | cosa e' lecito schedulare.
            */
            'allowed' => [
                // App\Jobs\SendDailyDigest::class,
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Tetti predefiniti
    |---------------------------------------------------------------------------
    | Valori proposti dalla UI quando si crea una routine. Non sono un limite
    | globale: il limite vero e' quello scritto sulla singola routine.
    */
    'defaults' => [
        'timezone' => null,      // null = config('app.timezone')
        'max_attempts' => 3,
        'timeout_seconds' => 300,
        'currency' => 'EUR',
    ],
];
