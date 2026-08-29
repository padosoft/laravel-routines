<?php

declare(strict_types=1);

namespace Padosoft\Routines\Targets;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Contracts\Target\ValidationResult;

/**
 * Il bersaglio incluso: mette in coda un job dell'applicazione.
 *
 * Serve a due cose. È utile di per sé — schedulare un job esistente senza scrivere codice — ed è
 * l'esempio di riferimento di come si scrive un bersaglio: `type()` stabile, `validate()` che
 * fallisce mentre c'è un umano davanti al form, `fire()` che non si inventa nulla e restituisce
 * un `TargetResult` invece di lanciare.
 *
 * **L'allow-list non è una precauzione, è il confine.** Il payload di una routine è dato:
 * arriva da un form, può arrivare da un import, e in fase 3 può arrivare da un assistente che l'ha
 * proposto. Istanziare la classe che ci scrive dentro significherebbe dare a quel dato il diritto
 * di eseguire qualsiasi cosa esista nell'applicazione. Per questo i job eseguibili si dichiarano
 * in configurazione, e un job non dichiarato è un errore di validazione, non un fire fallito.
 */
final class JobTarget implements RoutineTarget
{
    /**
     * @param  list<class-string>  $allowed  i job che una routine può mettere in coda
     */
    public function __construct(
        private readonly Bus $bus,
        private readonly array $allowed = [],
    ) {}

    public function type(): string
    {
        return 'job';
    }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor(
            label: 'Job',
            summary: "Mette in coda un job dell'applicazione, fra quelli abilitati in configurazione.",
            fields: [
                'job' => [
                    'label' => 'Classe del job',
                    'type' => 'select',
                    'required' => true,
                    'help' => 'Solo i job elencati in routines.targets.job.allowed.',
                ],
                'arguments' => [
                    'label' => 'Argomenti',
                    'type' => 'json',
                    'required' => false,
                    'help' => 'Passati al costruttore, in ordine.',
                ],
                'queue' => ['label' => 'Coda', 'type' => 'string', 'required' => false],
            ],
            actionClasses: ['job.dispatch'],
            supportsPause: false,
            reportsCost: false,
            icon: 'queue',
        );
    }

    public function validate(array $payload): ValidationResult
    {
        $errors = [];
        $job = $payload['job'] ?? null;

        if (! is_string($job) || $job === '') {
            $errors['job'] = ['Indica la classe del job.'];
        } elseif (! in_array($job, $this->allowed, true)) {
            $errors['job'] = ["Il job \"{$job}\" non è fra quelli abilitati (routines.targets.job.allowed)."];
        } elseif (! class_exists($job)) {
            $errors['job'] = ["La classe \"{$job}\" non esiste."];
        }

        if (isset($payload['arguments']) && ! is_array($payload['arguments'])) {
            $errors['arguments'] = ['Gli argomenti devono essere una lista.'];
        }

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        $job = $execution->payload('job');
        $arguments = $execution->payload('arguments', []);

        // Ri-validato al fire, non solo alla creazione: l'allow-list può essersi ristretta da
        // quando la routine è stata creata, e una routine creata quando il job era permesso non
        // deve continuare a girare dopo che è stato tolto.
        if (! is_string($job) || ! in_array($job, $this->allowed, true) || ! class_exists($job)) {
            return TargetResult::failed(
                is_string($job) && $job !== ''
                    ? "Il job \"{$job}\" non è più abilitato per le routine."
                    : 'Nessun job configurato per questa routine.'
            );
        }

        try {
            $instance = new $job(...array_values(is_array($arguments) ? $arguments : []));
        } catch (\Throwable $e) {
            return TargetResult::failed('Gli argomenti non corrispondono al costruttore del job: '.$e->getMessage());
        }

        $queue = $execution->payload('queue');
        if (is_string($queue) && $queue !== '' && method_exists($instance, 'onQueue')) {
            $instance->onQueue($queue);
        }

        $this->bus->dispatch($instance);

        return TargetResult::succeeded(
            "Job {$job} messo in coda.",
            ['job' => $job, 'queue' => is_string($queue) ? $queue : null],
        );
    }
}
