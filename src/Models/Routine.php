<?php

declare(strict_types=1);

namespace Padosoft\Routines\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Padosoft\Routines\Contracts\Consent\RoutineMandate;
use Padosoft\Routines\Contracts\Routine\MissedRunPolicy;
use Padosoft\Routines\Contracts\Routine\OverlapPolicy;
use Padosoft\Routines\Contracts\Routine\RoutineRef;
use Padosoft\Routines\Contracts\Routine\RoutineStatus;
use Padosoft\Routines\Contracts\Routine\TriggerKind;

/**
 * La configurazione di una routine.
 *
 * Lo stato del ciclo di vita NON è mass-assignable: si passa da `pause()`, `suspend()`, `resume()`,
 * `end()`. È deliberato — `suspend()` e `resume()` hanno regole diverse (una sospensione di sistema
 * non si annulla premendo "riprendi"), e un `update(['status' => …])` le aggirerebbe entrambe.
 *
 * @property string $id
 * @property string $owner
 * @property string|null $organization_id
 * @property string $name
 * @property string|null $description
 * @property string $target_type
 * @property array<string, mixed> $target_payload
 * @property string $trigger_kind
 * @property string|null $cron
 * @property Carbon|null $once_at
 * @property string|null $event_name
 * @property string $timezone
 * @property string $status
 * @property string|null $ended_reason
 * @property string|null $suspension_reason
 * @property string $overlap_policy
 * @property string $missed_run_policy
 * @property Carbon|null $next_run_at
 * @property string|null $last_local_occurrence
 * @property string|null $lock_token
 * @property Carbon|null $locked_until
 * @property string|null $delegation_grant_id
 * @property string|null $mandate_digest
 * @property array<string, mixed>|null $mandate
 * @property Carbon|null $mandate_granted_at
 * @property string|null $consent_confirmation_id
 * @property string|null $consent_aal
 * @property float|null $budget_per_run
 * @property float|null $budget_per_period
 * @property string|null $budget_period
 * @property string $currency
 * @property int|null $timeout_seconds
 * @property int $max_attempts
 * @property string $initiation
 * @property string|null $created_by
 * @property Carbon|null $last_fired_at
 */
class Routine extends Model
{
    use HasUlids;

    protected $table = 'routines';

    /** @var list<string> */
    protected $fillable = [
        'owner', 'organization_id', 'name', 'description',
        'target_type', 'target_payload',
        'trigger_kind', 'cron', 'once_at', 'event_name', 'timezone',
        'overlap_policy', 'missed_run_policy',
        'budget_per_run', 'budget_per_period', 'budget_period', 'currency',
        'timeout_seconds', 'max_attempts', 'initiation', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'trigger_kind' => 'cron',
        'timezone' => 'UTC',
        'overlap_policy' => 'skip',
        'missed_run_policy' => 'catch_up',
        'currency' => 'EUR',
        'max_attempts' => 3,
        'initiation' => 'human_request',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_payload' => 'array',
            'mandate' => 'array',
            'once_at' => 'datetime',
            'next_run_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_fired_at' => 'datetime',
            'mandate_granted_at' => 'datetime',
            'budget_per_run' => 'float',
            'budget_per_period' => 'float',
            'max_attempts' => 'integer',
            'timeout_seconds' => 'integer',
        ];
    }

    /** @return HasMany<RoutineRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(RoutineRun::class);
    }

    // ── Vista tipizzata ──────────────────────────────────────────────────────

    public function ref(): RoutineRef
    {
        return new RoutineRef($this->id, $this->name, $this->owner, $this->organization_id);
    }

    public function statusEnum(): RoutineStatus
    {
        // Uno stato sconosciuto vale Suspended, non Active: fail-closed. Un valore corrotto in
        // colonna non deve trasformarsi nel permesso di girare.
        return RoutineStatus::tryFrom($this->status) ?? RoutineStatus::Suspended;
    }

    public function triggerKind(): TriggerKind
    {
        return TriggerKind::tryFrom($this->trigger_kind) ?? TriggerKind::Manual;
    }

    public function overlapPolicy(): OverlapPolicy
    {
        return OverlapPolicy::tryFrom($this->overlap_policy) ?? OverlapPolicy::Skip;
    }

    public function missedRunPolicy(): MissedRunPolicy
    {
        return MissedRunPolicy::tryFrom($this->missed_run_policy) ?? MissedRunPolicy::CatchUp;
    }

    /** Il mandato con cui gira, se ne ha uno. */
    public function mandateObject(): ?RoutineMandate
    {
        $m = $this->mandate;
        if (! is_array($m) || $m === []) {
            return null;
        }
        $classes = [];
        foreach ($m['action_classes'] ?? [] as $c) {
            if (is_string($c) && $c !== '') {
                $classes[] = $c;
            }
        }
        $notAfter = $m['not_after'] ?? null;

        return new RoutineMandate(
            targetType: is_string($m['target_type'] ?? null) ? $m['target_type'] : $this->target_type,
            payloadDigest: is_string($m['payload_digest'] ?? null) ? $m['payload_digest'] : '',
            actionClasses: $classes,
            budgetCeiling: isset($m['budget_ceiling']) ? (float) $m['budget_ceiling'] : null,
            notAfter: is_string($notAfter) ? new \DateTimeImmutable($notAfter) : null,
            currency: is_string($m['currency'] ?? null) ? $m['currency'] : $this->currency,
        );
    }

    /** Il digest canonico del payload configurato, su cui si vincola il consenso. */
    public function payloadDigest(): string
    {
        return hash('sha256', self::canonicalJson($this->target_payload));
    }

    // ── Ciclo di vita ────────────────────────────────────────────────────────

    /** Scelta dell'utente. Reversibile da lui. */
    public function pause(): void
    {
        if ($this->statusEnum()->isTerminal()) {
            return;
        }
        $this->forceFill(['status' => RoutineStatus::Paused->value, 'next_run_at' => null])->save();
    }

    /**
     * Imposta dal SISTEMA: budget esaurito, anomalia, proprietario disabilitato.
     *
     * Separata da pause() perché la ripresa ha regole diverse: una sospensione si toglie quando la
     * causa è rimossa, non quando l'utente preme un pulsante.
     */
    public function suspend(string $reason): void
    {
        if ($this->statusEnum()->isTerminal()) {
            return;
        }
        $this->forceFill([
            'status' => RoutineStatus::Suspended->value,
            'suspension_reason' => $reason,
            'next_run_at' => null,
        ])->save();
    }

    public function resume(): void
    {
        if ($this->statusEnum()->isTerminal()) {
            return;
        }
        $this->forceFill([
            'status' => RoutineStatus::Active->value,
            'suspension_reason' => null,
        ])->save();
    }

    /** Terminale: da qui non si torna, se ne crea una nuova. */
    public function end(string $reason): void
    {
        $this->forceFill([
            'status' => RoutineStatus::Ended->value,
            'ended_reason' => $reason,
            'next_run_at' => null,
        ])->save();
    }

    // ── Lock ─────────────────────────────────────────────────────────────────

    /** Il lock è scaduto (o non c'è)? Un TTL passato vale come nessun lock: il worker è morto. */
    public function isLocked(?\DateTimeImmutable $now = null): bool
    {
        if ($this->lock_token === null || $this->locked_until === null) {
            return false;
        }

        return $this->locked_until->toDateTimeImmutable() > ($now ?? new \DateTimeImmutable);
    }

    public static function newLockToken(): string
    {
        return (string) Str::ulid();
    }

    /**
     * JSON canonico: chiavi ordinate ricorsivamente, così due payload semanticamente identici
     * producono lo stesso digest e un semplice riordino non invalida un consenso valido.
     */
    public static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            if (! $isList) {
                ksort($value);
            }
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = $isList
                    ? self::canonicalJson($v)
                    : json_encode((string) $k, JSON_THROW_ON_ERROR).':'.self::canonicalJson($v);
            }

            return $isList ? '['.implode(',', $parts).']' : '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
