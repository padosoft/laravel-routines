<?php

declare(strict_types=1);

namespace Padosoft\Routines\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Contracts\Target\TargetOutcome;

/**
 * Un fire. È un FATTO, non una configurazione: si scrive una volta e si chiude, non si riapre.
 *
 * La riga nasce PRIMA che il bersaglio parta, non dopo. Sembra un dettaglio e non lo è: se si
 * scrivesse a esito noto, un processo ucciso a metà non lascerebbe traccia, e un fire che ha già
 * mandato l'email risulterebbe mai avvenuto — che è il modo più diretto per mandarla due volte.
 * L'unique `(routine_id, idempotency_key, attempt)` fa il resto: due tick concorrenti provano a
 * inserire la stessa riga e il database ne lascia passare una.
 *
 * @property string $id
 * @property string $routine_id
 * @property string $reason
 * @property string|null $outcome
 * @property int $attempt
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string $idempotency_key
 * @property string|null $message
 * @property array<string, mixed>|null $metadata
 * @property string|null $external_ref
 * @property float|null $cost
 * @property string|null $pending_approval_id
 * @property string|null $resume_token
 * @property string|null $correlation_id
 * @property string|null $action_class
 * @property string|null $question
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property Carbon|null $escalated_at
 * @property string|null $escalation_error
 * @property Carbon|null $retry_at
 * @property Routine|null $routine
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoutineRun extends Model
{
    use HasUlids;

    protected $table = 'routine_runs';

    /** @var list<string> */
    protected $fillable = [
        'routine_id', 'reason', 'attempt', 'scheduled_for', 'started_at',
        'idempotency_key', 'correlation_id', 'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'resolved_at' => 'datetime',
            'escalated_at' => 'datetime',
            'retry_at' => 'datetime',
            'cost' => 'float',
            'attempt' => 'integer',
        ];
    }

    /** Il fire con questo id, o null. Vedi Routine::findById. */
    public static function findById(string $id): ?self
    {
        return static::query()->whereKey($id)->first();
    }

    /** @return BelongsTo<Routine, $this> */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function reasonEnum(): FireReason
    {
        return FireReason::tryFrom($this->reason) ?? FireReason::Scheduled;
    }

    public function outcomeEnum(): ?TargetOutcome
    {
        return $this->outcome === null ? null : TargetOutcome::tryFrom($this->outcome);
    }

    /**
     * Ferma, in attesa di una risposta umana.
     *
     * Distinta da `outcome === paused` da sola, perché un fire già risolto resta `paused` nello
     * storico: è ciò che è successo, e riscriverlo cancellerebbe l'evidenza che qualcuno aveva
     * chiesto. Quello che cambia è che ora ha una risposta.
     */
    public function isAwaitingHuman(): bool
    {
        return $this->outcome === TargetOutcome::Paused->value && $this->resolved_at === null;
    }

    /** Il fire è ancora aperto: partito e mai chiuso. */
    public function isRunning(): bool
    {
        return $this->outcome === null;
    }

    public function durationMs(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) round(($this->finished_at->getTimestampMs() - $this->started_at->getTimestampMs()));
    }

    /**
     * La chiave che rende un fire ripetibile senza raddoppiare l'effetto.
     *
     * Deriva dall'OCCORRENZA, non dall'istante di esecuzione: un catch-up delle 6:00 lanciato alle
     * 9:00 porta la chiave delle 6:00, quindi se il fire delle 6:00 era in realtà già partito prima
     * del crash, il bersaglio se ne accorge. Per i fire senza occorrenza (manuale, webhook) la
     * chiave la fornisce chi chiama, o è casuale: due click su "esegui adesso" sono due intenzioni
     * distinte, e fonderle sarebbe sbagliato quanto duplicare.
     */
    public static function scheduledKey(string $routineId, \DateTimeImmutable $occurrence): string
    {
        return substr(hash('sha256', $routineId.'@'.$occurrence->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')), 0, 40);
    }
}
