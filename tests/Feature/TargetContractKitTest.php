<?php

declare(strict_types=1);

use Padosoft\Routines\Contracts\Consent\MandateExceeded;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Contracts\Target\ValidationResult;
use Padosoft\Routines\Testing\TargetContract;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Un kit di asserzioni che passa su qualsiasi cosa non serve a niente: ogni caso qui verifica
 * che il contratto FALLISCA sul bersaglio che lo viola. Sono i test del kit, non del motore.
 */
/** Un bersaglio che rispetta il contratto: valida, dichiara, ricorda, e si ferma. */
class WellBehavedTarget implements RoutineTarget
{
    /** @var list<string> */
    public array $done = [];

    public function type(): string
    {
        return 'well-behaved';
    }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor('Ok', 'Rispetta il contratto.', actionClasses: ['demo.send']);
    }

    public function validate(array $payload): ValidationResult
    {
        return ($payload['template'] ?? '') !== ''
            ? ValidationResult::valid()
            : ValidationResult::invalid(['template' => ['Serve un template.']]);
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        if (($execution->input['action'] ?? null) === 'demo.destroy') {
            throw new MandateExceeded('demo.destroy');
        }

        if (in_array($execution->idempotencyKey, $this->done, true)) {
            return TargetResult::succeeded('gia\' fatto');
        }
        $this->done[] = $execution->idempotencyKey;

        return TargetResult::succeeded('fatto');
    }
}

it('passa su un bersaglio che rispetta il contratto', function (): void {
    TargetContract::assertAll(
        new WellBehavedTarget,
        validPayload: ['template' => 'reminder'],
        invalidPayload: ['template' => ''],
        outOfMandateInput: ['action' => 'demo.destroy'],
    );
});

it('fallisce se il bersaglio accetta qualsiasi payload', function (): void {
    // Un bersaglio senza validazione sposta l'errore dal form al log delle 3:00.
    $target = new class extends WellBehavedTarget
    {
        public function validate(array $payload): ValidationResult
        {
            return ValidationResult::valid();
        }
    };

    expect(fn () => TargetContract::assertValidatesItsPayload($target, ['template' => 'x'], ['template' => '']))
        ->toThrow(AssertionFailedError::class);
});

it('fallisce se il bersaglio non dichiara classi di azione', function (): void {
    // Senza, nessun mandato puo' autorizzarlo e nessuna pausa puo' dire cosa si sta approvando.
    $target = new class extends WellBehavedTarget
    {
        public function descriptor(): TargetDescriptor
        {
            return new TargetDescriptor('Muto', 'Non dichiara niente.');
        }
    };

    expect(fn () => TargetContract::assertDeclaresItsActionClasses($target))
        ->toThrow(AssertionFailedError::class);
});

it('fallisce se il bersaglio rifa il lavoro alla stessa chiave', function (): void {
    // E' il difetto che trasforma un timeout di rete in una seconda email davvero mandata.
    $target = new class extends WellBehavedTarget
    {
        private int $n = 0;

        public function fire(RoutineExecution $execution): TargetResult
        {
            return ++$this->n === 1 ? TargetResult::succeeded('fatto') : TargetResult::failed('duplicato');
        }
    };

    expect(fn () => TargetContract::assertIsIdempotentAcrossRetries($target, ['template' => 'x']))
        ->toThrow(AssertionFailedError::class);
});

it('fallisce se fuori dal mandato il bersaglio fallisce invece di fermarsi', function (): void {
    // Fallire fa arrendere la routine in silenzio; riuscire significherebbe aver agito senza
    // permesso. L'unica risposta giusta e' chiedere.
    $target = new class extends WellBehavedTarget
    {
        public function fire(RoutineExecution $execution): TargetResult
        {
            return TargetResult::failed('non posso');
        }
    };

    expect(fn () => TargetContract::assertPausesOutsideTheMandate($target, ['template' => 'x'], ['action' => 'demo.destroy']))
        ->toThrow(AssertionFailedError::class);
});

it('accetta sia la pausa esplicita sia MandateExceeded', function (): void {
    // Sono due modi di dire la stessa cosa, e il motore li tratta identici: un kit che ne
    // accettasse solo uno costringerebbe a riscrivere bersagli corretti.
    $paused = new class extends WellBehavedTarget
    {
        public function fire(RoutineExecution $execution): TargetResult
        {
            return TargetResult::paused('Posso?');
        }
    };

    TargetContract::assertPausesOutsideTheMandate($paused, ['template' => 'x'], ['action' => 'demo.destroy']);
    TargetContract::assertPausesOutsideTheMandate(new WellBehavedTarget, ['template' => 'x'], ['action' => 'demo.destroy']);

    expect(true)->toBeTrue(); // arrivarci senza asserzioni fallite E' l'esito
});
