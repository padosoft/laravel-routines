---
name: routines-review
description: Revisiona una modifica a laravel-routines contro i cinque invarianti del pacchetto. Usala dopo aver scritto codice che tocca schedulazione, dispatch, mandato, delega, budget, trigger o Admin API.
---

# Revisione di una modifica a laravel-routines

Non è una checklist di stile: è la lista delle cose che, se si rompono, si rompono **in silenzio**.
Per ognuna, cerca il pattern e chiediti la domanda.

## 1. Fail-closed

```bash
grep -rnE '(tryFrom\([^)]*\)\s*\?\?|allows\(|covers\(|in_array\()' src/
```

- Un `tryFrom(...) ?? X`: `X` è lo stato **più restrittivo**? (`Suspended`, non `Active`.)
- Un elenco vuoto: significa «niente» o «tutto»? Deve significare **niente**.
- Un `catch` che continua invece di fermarsi: cosa succede se quel ramo scatta in produzione?

## 2. Un'occorrenza, un fire

```bash
grep -rn "idempotency_key\|lock_token\|unique(" src/ database/
```

- Un nuovo percorso che apre un run passa da `openRun()`? Se costruisce un `RoutineRun` a mano,
  bypassa il vincolo unique — che è **l'unica** cosa che regge fra due tick concorrenti.
- Un nuovo lock ha un TTL? Senza, un worker morto blocca la routine per sempre e nessun errore lo dice.

## 3. La chiave

- La chiave viene dal core (`$execution->idempotencyKey`)? Un bersaglio che se la genera la genera
  diversa a ogni tentativo.
- Ripresa dopo pausa → **stessa** chiave. "Esegui adesso" → chiave **nuova**. Retry → **stessa**.
  Se il diff ne cambia uno, era voluto?

## 4. Paused

```bash
grep -rn "TargetOutcome::" src/
```

- Un nuovo `match` sugli esiti gestisce tutti e quattro i casi? Un `default` che ingloba `Paused`
  lo fa sparire dalla coda di chi deve rispondere.
- Un rifiuto chiude come `Skipped`? `Failed` lo farebbe ritentare.

## 5. Testo dal server

```bash
grep -rn "_label\|_human\|diagnosis\|question" src/Http/
```

- Un nuovo slug esposto all'API ha la sua controparte leggibile?
- La `question` di un'escalation viene dal bersaglio, non ricomposta a valle?

## Fusi e ora legale

```bash
grep -rn "DateTimeZone\|timezone\|setTimezone" src/
```

- Un nuovo calcolo di date usa `$routine->timezone` o quello del server?
- Se tocca `RoutineScheduler`: i due test dell'ora legale passano ancora? **Toglila e verifica che
  falliscano** — un test che passa in entrambi i casi non protegge niente.

## Evidenza

```bash
grep -n "fillable" src/Models/*.php
```

- Un campo che prova qualcosa (`status`, `delegation_grant_id`, `mandate*`, `resolved_*`,
  `webhook_secret`) **non** è mass-assignable.
- Ogni nuovo campo è nella lista `@property` del modello.

## Segreti e log

```bash
grep -rniE "logger|Log::" src/ | grep -iE "secret|token|password"
```

Zero risultati. Il segreto webhook non compare in nessun log, e il token delegato nemmeno.

## Infine

```bash
COMPOSER=composer-test.json vendor/bin/pint --test
COMPOSER=composer-test.json vendor/bin/pest
vendor/bin/phpstan analyse
```
