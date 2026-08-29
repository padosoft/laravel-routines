<?php

declare(strict_types=1);

namespace Padosoft\Routines\Testing;

use Padosoft\Routines\Contracts\Consent\MandateExceeded;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Routine\RoutineRef;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetOutcome;
use PHPUnit\Framework\Assert;

/**
 * Il contratto che un bersaglio deve rispettare, in forma eseguibile.
 *
 * Chi scrive un bersaglio scrive codice che agisce alle 3:00 senza nessuno a guardarlo, e le
 * regole che lo rendono sicuro vivevano finora solo nella prosa della documentazione. Questo
 * trait le rende asserzioni: si estende il proprio test case, si chiama un metodo, e o il
 * bersaglio le rispetta o il test e' rosso.
 *
 * Non e' una checklist di stile: ogni asserzione qui corrisponde a un modo in cui un bersaglio
 * sbagliato fallisce IN SILENZIO — agendo senza permesso, ripetendo un lavoro gia' fatto,
 * arrendendosi invece di chiedere, scoprendo alle 3:00 che il payload non andava bene.
 *
 * Una CLASSE e non un trait, deliberatamente: un trait di asserzioni entra nello spazio dei nomi
 * dei metodi del test case che lo usa, e prima o poi collide con qualcosa; queste chiamate sono
 * esplicite su chi le fa. Uso tipico:
 *
 *     public function test_it_respects_the_routine_target_contract(): void
 *     {
 *         use AssertsTargetContract;
 *
 *         public function test_it_respects_the_routine_target_contract(): void
 *         {
 *             $this->assertTargetContract(
 *                 new InvoiceReminderTarget(...),
 *                 validPayload: ['template' => 'reminder'],
 *                 invalidPayload: ['template' => ''],
 *             );
 *         }
 *     }
 */
final class TargetContract
{
    /**
     * Esegue l'intero contratto. `$outOfMandateInput` e' opzionale: passalo con un input che il
     * bersaglio riconosce come fuori dal mandato per verificare che si FERMI invece di fallire.
     *
     * @param  array<string, mixed>  $validPayload  una configurazione che il bersaglio accetta
     * @param  array<string, mixed>  $invalidPayload  una che deve rifiutare, con l'errore sul campo
     * @param  array<string, mixed>|null  $outOfMandateInput
     */
    public static function assertAll(
        RoutineTarget $target,
        array $validPayload,
        array $invalidPayload,
        ?array $outOfMandateInput = null,
    ): void {
        self::assertValidatesItsPayload($target, $validPayload, $invalidPayload);
        self::assertDeclaresItsActionClasses($target);
        self::assertIsIdempotentAcrossRetries($target, $validPayload);

        if ($outOfMandateInput !== null) {
            self::assertPausesOutsideTheMandate($target, $validPayload, $outOfMandateInput);
        }
    }

    /**
     * La validazione esiste per spostare l'errore dal log delle 3:00 al form: un bersaglio che
     * accetta qualsiasi payload rimanda la scoperta al momento in cui non c'e' nessuno a leggerla.
     *
     * @param  array<string, mixed>  $validPayload
     * @param  array<string, mixed>  $invalidPayload
     */
    public static function assertValidatesItsPayload(RoutineTarget $target, array $validPayload, array $invalidPayload): void
    {
        Assert::assertTrue(
            $target->validate($validPayload)->valid,
            'Il bersaglio rifiuta un payload che dovrebbe accettare.',
        );

        // Che il rifiuto porti almeno un errore per campo NON si asserisce qui: lo garantisce
        // gia' `ValidationResult::invalid()`, che lancia su una lista vuota. Un'asserzione che
        // non puo' fallire non protegge niente, e fa sembrare coperto cio' che copre il tipo.
        Assert::assertFalse(
            $target->validate($invalidPayload)->valid,
            'Il bersaglio accetta un payload invalido: l\'errore emergera\' al fire, alle 3:00, '
            .'invece che nel form.',
        );
    }

    /** Un bersaglio che non dichiara le proprie classi di azione non e' governabile da un mandato. */
    public static function assertDeclaresItsActionClasses(RoutineTarget $target): void
    {
        Assert::assertNotSame(
            [],
            $target->descriptor()->actionClasses,
            'Il descrittore non dichiara nessuna classe di azione: nessun mandato potra\' autorizzarlo, '
            .'e nessuna pausa potra\' dire a un umano CHE COSA sta approvando.',
        );
    }

    /**
     * La chiave di idempotenza e' stabile attraverso i retry: e' il motivo per cui la seconda
     * email non parte dopo un timeout. Un bersaglio che la ignora trasforma un ritardo di rete in
     * un secondo effetto reale, e nessun test del motore puo' accorgersene al posto suo.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertIsIdempotentAcrossRetries(RoutineTarget $target, array $payload): void
    {
        $execution = self::execution($target, $payload);

        $first = $target->fire($execution);
        $second = $target->fire($execution); // stessa chiave: per il bersaglio e' lo STESSO lavoro

        Assert::assertSame(
            $first->outcome,
            $second->outcome,
            'Rieseguire con la STESSA chiave di idempotenza da un esito diverso: il bersaglio sta '
            .'rifacendo il lavoro invece di riconoscerlo come gia\' fatto.',
        );
    }

    /**
     * Fuori dal mandato ci si FERMA, non si fallisce. E' l'errore piu' naturale da commettere
     * scrivendo un bersaglio, e trattarlo come fallimento fa arrendere la routine in silenzio
     * invece di farle chiedere a qualcuno.
     *
     * Entrambe le forme sono corrette: restituire `TargetResult::paused()` oppure lanciare
     * `MandateExceeded`, che il motore converte in pausa.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     */
    public static function assertPausesOutsideTheMandate(RoutineTarget $target, array $payload, array $input): void
    {
        $execution = self::execution($target, $payload, $input);

        try {
            $result = $target->fire($execution);
        } catch (MandateExceeded) {
            return; // il motore lo trasforma in pausa: corretto
        }

        Assert::assertSame(
            TargetOutcome::Paused,
            $result->outcome,
            'Con un input fuori dal mandato il bersaglio non si ferma: restituisci '
            .'TargetResult::paused() o lancia MandateExceeded. Fallire farebbe arrendere la '
            .'routine in silenzio; riuscire significherebbe aver agito senza permesso.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     */
    private static function execution(RoutineTarget $target, array $payload, array $input = []): RoutineExecution
    {
        return new RoutineExecution(
            routine: new RoutineRef(
                id: 'rt_contract',
                name: 'Contract check',
                owner: 'user:contract',
            ),
            runId: 'run_contract',
            reason: FireReason::Manual,
            payload: $payload,
            idempotencyKey: 'contract-key-1',
            scheduledFor: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            input: $input,
        );
    }
}
