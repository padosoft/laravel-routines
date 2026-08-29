# CLAUDE.md — laravel-routines

Istruzioni per chi (umano o AI) modifica questo pacchetto.

## Cosa fa, in una riga

Fa girare automazioni programmate **quando l'utente non c'è**, e quando incontrano qualcosa che non
erano autorizzate a fare **si fermano e chiedono** invece di procedere o di fallire in silenzio.

## I cinque invarianti

Sono le proprietà che il pacchetto esiste per garantire. Un cambiamento che ne rompe una è una
regressione anche se tutti i test passano — e se un test lo permette, il test è sbagliato.

### 1. Fail-closed, sempre

Nel dubbio si **ferma**, non procede.

- Uno stato sconosciuto in colonna vale `Suspended`, non `Active` (`Routine::statusEnum`).
- Un mandato senza classi di azione autorizza **niente**, non tutto (`RoutineMandate::covers`).
- Un'ability senza policy definita è negata, tranne `routines.read` (`Http\Support\Permissions`).
- L'allow-list dei job è vuota per default.
- `NullDelegationBroker::tokenFor()` **lancia**: non emette un token dell'applicazione, che sarebbe
  la piena autorità.

### 2. Un'occorrenza produce un fire

La garanzia è del **database**, non del codice: l'unique `(routine_id, idempotency_key, attempt)`.
Il lock è una `UPDATE` condizionale con TTL. Non sostituire nessuno dei due con un `if`.

### 3. La chiave di idempotenza la decide il core, non il bersaglio

Un bersaglio che se la genera la genera diversa a ogni tentativo — esattamente il bug che la chiave
previene. La chiave arriva in `RoutineExecution::$idempotencyKey`.

**Regola derivata**: una ripresa dopo una pausa usa la **stessa** chiave (è lo stesso lavoro); un
"esegui adesso" ne usa una nuova (è una nuova intenzione).

### 4. `Paused` non è né successo né fallimento

È la ragione per cui `TargetOutcome` ha quattro casi. Non fonderlo con `Failed` (verrebbe
ritentato) né con `Skipped` (sparirebbe dalla coda di chi deve rispondere).

Corollario: **un rifiuto chiude come `Skipped`**, non `Failed`. Non si è rotto niente: qualcuno ha
deciso di no.

### 5. Il testo leggibile lo compone il server

`schedule_human`, `suspension_reason_label`, `tick_diagnosis`, `RoutineEscalation::$question`. Un
client che traduce da sé uno slug lo tradurrà diversamente dal CLI, dall'email e dall'audit — e
tre persone che guardano lo stesso evento leggerebbero tre cose diverse.

Per la domanda di un'escalation è più forte: **è l'evidenza di cosa è stato approvato**.
Ricomporla a valle la farebbe divergere da quella approvata, e l'approvazione non proverebbe più
niente.

## Regole di scrittura

| Regola | Perché |
|---|---|
| Il fuso è **sempre** quello del proprietario, mai quello del server | "Ogni giorno alle 6" sono le **sue** 6 |
| Entrambe le direzioni dell'ora legale, e pinnate da test | Succede due volte l'anno e nessuno se lo ricorda |
| La riga del run nasce **prima** del lavoro | Un processo ucciso a metà deve lasciare una traccia, non un'email mandata e nessun record |
| Un campo che è **evidenza** non è mass-assignable | `status`, `delegation_grant_id`, `mandate*`: si passa dai metodi, che hanno regole diverse fra loro |
| Ogni nuovo campo sul modello va nella lista `@property` | I `@property` sono l'unico contratto di tipo che ha un modello Eloquent |
| I `logger`/`Log` non ricevono mai token o segreti | Il segreto webhook non compare in nessun log |
| Un errore che il chiamante deve distinguere ha il **suo** codice | 403 ≠ 409: «non sei autorizzato» e «è ferma» sono cose diverse |

## Quando aggiungi un bersaglio

1. Implementa `RoutineTarget` da `padosoft/laravel-routines-contracts`. **Non** dipendere da
   `laravel-routines`: il bersaglio conosce il proprio dominio, non lo scheduler.
2. `type()` è **stabile per sempre**: cambiarlo orfana lo storico, che è evidenza.
3. `validate()` gira alla creazione, mentre c'è un umano davanti al form. Un payload rotto scoperto
   alle 3:00 è un fallimento silenzioso dentro un log che nessuno legge.
4. `fire()` **non lancia** per un fallimento previsto: quello è `TargetResult::failed()`.
5. Se può eccedere il mandato, restituisci `TargetResult::paused()` (o lancia `MandateExceeded`,
   che il core converte).
6. Registra dal **tuo** service provider, fuori da un eventuale guard `runningInConsole`: l'admin
   API che elenca i bersagli gira su HTTP.

## Prima di committare

```bash
COMPOSER=composer-test.json vendor/bin/pint
COMPOSER=composer-test.json vendor/bin/pest
vendor/bin/phpstan analyse
```

Se hai toccato una guardia (ora legale, lock, unique, budget, mandato): **togli la guardia e
verifica che il test fallisca**. Un test che passa in entrambi i casi non protegge niente.

## Eseguire l'analisi statica in questa sessione

Il proxy di rete non lascia scaricare `phpstan/phpstan` (è dist-only e la sua zipball torna 403),
quindi `composer require larastan` fallisce e **`vendor/bin/phpstan` non esiste**. È il motivo per
cui il gate della CI può restare rosso mentre in locale «i test passano»: i test non sono l'analisi.

Il binario però è già installato in un altro repo dell'ecosistema. Da usare così:

```bash
php /home/user/laravel-iam-contracts/vendor/phpstan/phpstan/phpstan.phar \
    analyse -c phpstan.neon.dist --no-progress --memory-limit=1G
```

Serve che `vendor/larastan` esista in questo pacchetto — un symlink a quello dell'altro repo basta —
e che **le versioni di Laravel/testbench combacino**, altrimenti il bootstrap di larastan avvia
testbench e muore su un service provider che nel framework installato non c'è:

```bash
ln -sfn /home/user/laravel-iam-contracts/vendor/larastan vendor/larastan
```

**Prima di dire «verde», esegui tutti e tre**: `pint --test`, `pest`, e questo. Il terzo è quello
che manca più facilmente, ed è quello che la CI esegue davvero.
