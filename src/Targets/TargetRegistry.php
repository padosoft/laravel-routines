<?php

declare(strict_types=1);

namespace Padosoft\Routines\Targets;

use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Contracts\Target\TargetNotRegistered;

/**
 * Il registro dei bersagli.
 *
 * Il core non ha un elenco di cosa si può lanciare: lo ricevono i pacchetti che lo sanno, dal
 * proprio service provider. È il pattern di `ReviewableRegistry` in laravel-iam-server, e la
 * ragione è la stessa: un pacchetto opzionale deve poter aggiungere un tipo senza che il core
 * cambi di una riga, e disinstallarsi senza lasciare il core con un riferimento rotto.
 *
 * Quel secondo caso è il motivo per cui `get()` lancia invece di restituire null: una routine il
 * cui bersaglio non è più registrato non è "da saltare", è **non eseguibile** — il dispatcher la
 * sospende con un motivo leggibile, invece di ritentarla per sempre.
 */
final class TargetRegistry
{
    /** @var array<string, RoutineTarget> */
    private array $targets = [];

    public function register(RoutineTarget $target): void
    {
        $type = $target->type();
        if ($type === '') {
            throw new \InvalidArgumentException('RoutineTarget::type() non può essere vuoto.');
        }
        // Registrare due volte lo stesso tipo è quasi sempre un provider caricato due volte, ma
        // può anche essere una sostituzione voluta in test: l'ultimo vince, in silenzio.
        $this->targets[$type] = $target;
    }

    public function has(string $type): bool
    {
        return isset($this->targets[$type]);
    }

    /**
     * @throws TargetNotRegistered
     */
    public function get(string $type, string $routineId = '-'): RoutineTarget
    {
        return $this->targets[$type] ?? throw new TargetNotRegistered($type, $routineId);
    }

    /** @return array<string, RoutineTarget> */
    public function all(): array
    {
        return $this->targets;
    }

    /**
     * I descrittori, per costruire la UI di creazione senza conoscere i tipi.
     *
     * @return array<string, TargetDescriptor>
     */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->targets as $type => $target) {
            $out[$type] = $target->descriptor();
        }

        return $out;
    }
}
