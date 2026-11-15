<?php

namespace App\Domain\Visit\Services;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Note rules and append-only retry evidence, used inside the authorized Visit aggregate transaction. */
class VisitPanelNoteService
{
    public function lockCommands(User $actor): void
    {
        Organisation::query()->whereKey($actor->organisation_id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function value(array $attributes, ?Visit $visit = null): ?string
    {
        if ($attributes['coverage_type'] !== 'panel') {
            return null;
        }
        if (! array_key_exists('coverage_panel_note', $attributes)) {
            return $visit?->panel_id === (int) $attributes['panel_id'] ? $visit->coverage_panel_note : null;
        }
        $note = trim((string) ($attributes['coverage_panel_note'] ?? ''));
        if ($note !== '' && (int) ($attributes['panel_note_panel_id'] ?? 0) !== (int) $attributes['panel_id']) {
            throw ValidationException::withMessages(['coverage_panel_note' => 'The Panel changed. Clear the previous note and enter a note for the selected Panel.']);
        }

        return $note === '' ? null : $note;
    }

    /** @param array<string, mixed> $attributes */
    public function hash(User $actor, string $operation, array $attributes, ?Visit $visit = null): string
    {
        return hash('sha256', json_encode([$operation, $actor->id, $visit?->id, $attributes], JSON_THROW_ON_ERROR));
    }

    public function replay(User $actor, Visit $visit, string $key, string $hash): bool
    {
        $command = DB::table('visit_panel_note_commands')->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)->lockForUpdate()->first();
        if (! $command) {
            return false;
        }
        if ((int) $command->visit_id !== $visit->id || (int) $command->branch_id !== $visit->branch_id || ! hash_equals((string) $command->payload_hash, $hash)) {
            throw ValidationException::withMessages(['panel_note_idempotency_key' => 'This form was already used with different details. Reload before saving.']);
        }

        return true;
    }

    public function record(User $actor, Visit $visit, string $key, string $hash): void
    {
        DB::table('visit_panel_note_commands')->insert([
            'organisation_id' => $actor->organisation_id, 'branch_id' => $visit->branch_id,
            'visit_id' => $visit->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc(),
        ]);
    }

    public function assertUnusedKey(User $actor, string $key): void
    {
        if (DB::table('visit_panel_note_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->exists()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This form key was already used. Start a new form.']);
        }
    }
}
