<?php

declare(strict_types=1);

namespace Padosoft\Routines;

use Padosoft\Routines\Contracts\Target\ValidationResult;

/**
 * Il payload o lo schedule non reggono.
 *
 * Porta gli errori campo-per-campo perché il destinatario è un form, non un log: un'eccezione con
 * un messaggio unico costringerebbe la UI a indovinare quale casella evidenziare.
 */
final class InvalidRoutine extends \InvalidArgumentException
{
    /** @param array<string, list<string>> $errors */
    private function __construct(public readonly array $errors, string $message)
    {
        parent::__construct($message);
    }

    /** @param array<string, list<string>> $errors */
    public static function fromErrors(array $errors): self
    {
        $flat = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $m) {
                $flat[] = $field.': '.$m;
            }
        }

        return new self($errors, implode(' · ', $flat));
    }

    public static function fromValidation(ValidationResult $result): self
    {
        return new self($result->errors, $result->summary());
    }
}
