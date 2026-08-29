<?php

declare(strict_types=1);

namespace Padosoft\Routines\Tests\Support;

use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Contracts\Target\ValidationResult;

/** Un bersaglio che tiene il conto di quante volte è stato chiamato e con che chiave. */
final class RecordingTarget implements RoutineTarget
{
    /** @var list<RoutineExecution> */
    public array $fires = [];

    /** @param \Closure(RoutineExecution): TargetResult|null $behaviour */
    public function __construct(
        private readonly string $type = 'test',
        private readonly ?\Closure $behaviour = null,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor('Test', 'Bersaglio di prova.', actionClasses: ['test.do']);
    }

    public function validate(array $payload): ValidationResult
    {
        return ($payload['ok'] ?? true) === true
            ? ValidationResult::valid()
            : ValidationResult::invalid(['ok' => ['Il payload dice di no.']]);
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        $this->fires[] = $execution;

        return $this->behaviour !== null
            ? ($this->behaviour)($execution)
            : TargetResult::succeeded('fatto');
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (RoutineExecution $e): string => $e->idempotencyKey, $this->fires);
    }
}
