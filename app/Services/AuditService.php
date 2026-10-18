<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fondation C — Journal d'audit en ajout seul et chaîné (RG-20).
 *
 * Chaque entrée contient l'empreinte SHA-256 de la précédente : modifier ou
 * supprimer une ligne casse la chaîne, ce que détecte verify(). La base refuse
 * en plus toute modification (migration protect_audit_and_ledger).
 */
class AuditService
{
    private const GENESIS = 'HR-SUITE-GENESIS';

    /**
     * @param  Model|array{0:string,1:int|string}  $entity  modèle, ou [type, identifiant]
     */
    public function record(string $action, Model|array $entity, mixed $before = null, mixed $after = null, ?int $actorId = null): AuditLog
    {
        [$type, $id] = $entity instanceof Model
            ? [class_basename($entity), (string) $entity->getKey()]
            : [$entity[0], (string) $entity[1]];

        $actorId ??= Auth::id();
        $before = $this->normalize($before);
        $after = $this->normalize($after);

        return DB::transaction(function () use ($action, $type, $id, $before, $after, $actorId) {
            // Un seul écrivain à la fois sur la chaîne
            $last = AuditLog::query()->lockForUpdate()->orderByDesc('id')->first(['hash']);
            $occurredAt = now()->format('Y-m-d H:i:s.u');
            $prevHash = $last?->hash;

            return AuditLog::query()->create([
                'occurred_at' => $occurredAt,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $type,
                'entity_id' => $id,
                'before' => $before,
                'after' => $after,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'prev_hash' => $prevHash,
                'hash' => $this->hash($prevHash, $occurredAt, $actorId, $action, $type, $id, $before, $after),
            ]);
        });
    }

    /**
     * Recalcule toute la chaîne et signale la première entrée altérée.
     *
     * @return array{intact:bool, checked:int, broken_at:?int}
     */
    public function verify(): array
    {
        $prevHash = null;
        $checked = 0;
        foreach (AuditLog::query()->orderBy('id')->lazy(500) as $row) {
            $expected = $this->hash(
                $prevHash,
                $this->timestamp($row->getRawOriginal('occurred_at')),
                $row->actor_id,
                $row->action,
                $row->entity_type,
                $row->entity_id,
                $row->before,
                $row->after,
            );
            if ($row->prev_hash !== $prevHash || ! hash_equals($row->hash, $expected)) {
                return ['intact' => false, 'checked' => $checked, 'broken_at' => $row->id];
            }
            $prevHash = $row->hash;
            $checked++;
        }

        return ['intact' => true, 'checked' => $checked, 'broken_at' => null];
    }

    private function hash(?string $prev, string $occurredAt, ?int $actorId, string $action, string $type, string $id, mixed $before, mixed $after): string
    {
        $payload = $this->canonical([
            'occurred_at' => $this->timestamp($occurredAt),
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'before' => $before,
            'after' => $after,
        ]);

        return hash('sha256', ($prev ?? self::GENESIS).'|'.$payload);
    }

    /** Horodatage normalisé à la microseconde, quel que soit le format renvoyé par la base */
    private function timestamp(string $value): string
    {
        return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s.u');
    }

    /** Valeur telle qu'elle sera relue depuis la base : dates en texte, énumérations en valeur */
    private function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
    }

    /** JSON à clés triées : la base peut réordonner les clés d'un champ JSON */
    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn ($v) => $this->canonical($v), $value)).']';
            }
            ksort($value);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string) $k).':'.$this->canonical($v);
            }

            return '{'.implode(',', $parts).'}';
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
