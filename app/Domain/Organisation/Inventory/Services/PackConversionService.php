<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-52 (owner item 17 and answer 14 = A, 21 Nov 2026): a checked pack conversion per SKU ("1 bottle = 60 ml"), so
 * Dispensary (DSP-24) can hand out whole packs while every quantity stays exact in the SKU's verified base unit (STK-1
 * is unchanged). Anyone who manages inventory references proposes a version; only a CA Supervisor or the Director
 * (owner: "A for the bottle check") checks it, by typing the count blind, and never the proposer (service and
 * PostgreSQL). Append-only: versions and outcomes are never changed or deleted. Audit holds ids, versions and codes only.
 */
final class PackConversionService
{
    public const PROPOSE = 'inventory.references.manage.organisation';

    public const VERIFY = 'inventory.pack_conversions.verify.organisation';

    public const REFUSE_REASONS = ['wrong_count', 'wrong_unit', 'other'];

    /** The checker's refusal when a blind count does not match; it never repeats the proposed number. */
    public const MISMATCH_MESSAGE = 'That does not match the proposal. It was refused. Ask the proposer to check the number and propose again.';

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit) {}

    /**
     * Propose a new version: a pack unit and how many base units one pack holds, or `stop` to stop using a conversion.
     *
     * @param  array<string, mixed>  $input
     */
    public function propose(User $actor, string $skuPublicId, array $input): string
    {
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'stop' => ['sometimes', 'boolean'],
            'pack_unit' => ['exclude_if:stop,true', 'required', 'string', 'max:40', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'base_per_pack' => ['exclude_if:stop,true', 'required', 'integer', 'min:2', 'max:100000'],
        ], [
            'pack_unit.required' => 'Enter the pack unit, for example bottle.',
            'base_per_pack.required' => 'Enter how many base units are in one pack.',
            'base_per_pack.integer' => 'Enter a whole number from 2 to 100,000.',
            'base_per_pack.min' => 'Enter a whole number from 2 to 100,000.',
            'base_per_pack.max' => 'Enter a whole number from 2 to 100,000.',
        ])->validate();
        $stop = (bool) ($data['stop'] ?? false);
        $packUnit = $stop ? null : trim((string) $data['pack_unit']);
        if ($packUnit === '') {
            throw ValidationException::withMessages(['pack_unit' => 'Enter the pack unit, for example bottle.']);
        }
        $basePerPack = $stop ? null : (int) $data['base_per_pack'];
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower($skuPublicId), (int) $data['expected_version'], $packUnit, $basePerPack], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $skuPublicId, $data, $packUnit, $basePerPack, $stop, $key, $hash): string {
                $actor = $this->authority->lockForOrganisation($actor, self::PROPOSE);
                $existing = DB::table('inventory_pack_conversions')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['public_id', 'request_hash', 'proposed_by_user_id']);
                if ($existing !== null) {
                    if ($existing->request_hash !== $hash || (int) $existing->proposed_by_user_id !== $actor->id) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different proposal. Reload and try again.']);
                    }

                    return (string) $existing->public_id;
                }
                $sku = $this->lockSku($actor, $skuPublicId);
                $versions = $this->versions((int) $sku->id);
                $latest = $versions === [] ? 0 : (int) end($versions)->version;
                if ((int) $data['expected_version'] !== $latest) {
                    throw ValidationException::withMessages(['expected_version' => 'The pack conversion changed since this page was opened. Reload and try again.']);
                }
                if ($this->waiting($versions) !== null) {
                    throw ValidationException::withMessages(['pack_unit' => 'A change is already waiting for a check. It must be checked, refused or withdrawn first.']);
                }
                $baseUnit = (string) $sku->stock_unit;
                if (! DB::table('inventory_unit_contracts')->where('inventory_sku_id', $sku->id)->where('base_unit', $baseUnit)->exists()) {
                    throw ValidationException::withMessages(['pack_unit' => 'Verify this SKU\'s base stock unit on Pilot readiness before adding a pack conversion.']);
                }
                if ($packUnit !== null && Str::lower($packUnit) === Str::lower($baseUnit)) {
                    throw ValidationException::withMessages(['pack_unit' => 'The pack unit must differ from the base unit ('.$baseUnit.').']);
                }
                $current = $this->current($versions);
                if ($stop && ($current === null || $current->pack_unit === null)) {
                    throw ValidationException::withMessages(['stop' => 'There is no pack conversion in use to stop.']);
                }
                if (! $stop && $current !== null && $current->pack_unit === $packUnit && (int) $current->base_per_pack === $basePerPack) {
                    throw ValidationException::withMessages(['base_per_pack' => 'That is already the pack conversion in use.']);
                }
                $publicId = (string) Str::uuid();
                $id = DB::table('inventory_pack_conversions')->insertGetId([
                    'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'inventory_sku_id' => $sku->id, 'version' => $latest + 1,
                    'pack_unit' => $packUnit, 'base_per_pack' => $basePerPack, 'base_unit' => $baseUnit, 'proposed_by_user_id' => $actor->id,
                    'proposed_at' => now()->utc(), 'idempotency_key' => $key, 'request_hash' => $hash,
                ]);
                abort_if($this->audit->record('inventory.pack_conversion.proposed', $sku, ['pack_conversion_id' => $id, 'version' => $latest + 1, 'stop' => $stop], $actor) === null,
                    503, 'The proposal could not be recorded, so nothing was saved.');

                return $publicId;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['expected_version' => 'Someone else changed the pack conversion at the same moment. Reload and try again.']);
        }
    }

    /**
     * The blind check: the checker types the count without seeing the proposal. A match is recorded as checked; a
     * mismatch is recorded as refused (wrong_count) and the method returns 'refused' so the caller shows
     * {@see self::MISMATCH_MESSAGE}, which never repeats the proposed number.
     *
     * @param  array<string, mixed>  $input
     */
    public function check(User $actor, string $conversionPublicId, array $input): string
    {
        $data = Validator::make($input, ['idempotency_key' => ['required', 'uuid'], 'checked_base_per_pack' => ['nullable', 'integer', 'min:0', 'max:1000000']],
            ['checked_base_per_pack.integer' => 'Type a whole number.'])->validate();
        $typed = isset($data['checked_base_per_pack']) ? (int) $data['checked_base_per_pack'] : null;

        return $this->decide($actor, $conversionPublicId, (string) $data['idempotency_key'], self::VERIFY, function (stdClass $conversion) use ($typed): array {
            if ($conversion->base_per_pack === null) {
                return ['checked', null, null];
            }
            if ($typed === null) {
                throw ValidationException::withMessages(['checked_base_per_pack' => 'Count them yourself and type the number.']);
            }

            return $typed === (int) $conversion->base_per_pack ? ['checked', null, $typed] : ['refused', 'wrong_count', $typed];
        }, ['check', $typed]);
    }

    /** @param array<string, mixed> $input */
    public function refuse(User $actor, string $conversionPublicId, array $input): void
    {
        $data = Validator::make($input, ['idempotency_key' => ['required', 'uuid'], 'reason' => ['required', Rule::in(self::REFUSE_REASONS)]],
            ['reason.required' => 'Pick a reason to continue.', 'reason.in' => 'Pick a reason to continue.'])->validate();
        $this->decide($actor, $conversionPublicId, (string) $data['idempotency_key'], self::VERIFY, fn (): array => ['refused', $data['reason'], null], ['refuse', $data['reason']]);
    }

    /** @param array<string, mixed> $input */
    public function withdraw(User $actor, string $conversionPublicId, array $input): void
    {
        $data = Validator::make($input, ['idempotency_key' => ['required', 'uuid']])->validate();
        $this->decide($actor, $conversionPublicId, (string) $data['idempotency_key'], self::PROPOSE, fn (): array => ['withdrawn', null, null], ['withdraw']);
    }

    /**
     * @param  callable(stdClass): array{0: string, 1: ?string, 2: ?int}  $outcome
     * @param  list<mixed>  $payload
     */
    private function decide(User $actor, string $conversionPublicId, string $idempotencyKey, string $permission, callable $outcome, array $payload): string
    {
        $key = strtolower($idempotencyKey);
        $hash = hash('sha256', json_encode([strtolower($conversionPublicId), ...$payload], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $conversionPublicId, $key, $hash, $permission, $outcome, $payload): string {
                $actor = $this->authority->lockForOrganisation($actor, $permission);
                $existing = DB::table('inventory_pack_conversion_outcomes')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['outcome', 'request_hash', 'recorded_by_user_id']);
                if ($existing !== null) {
                    if ($existing->request_hash !== $hash || (int) $existing->recorded_by_user_id !== $actor->id) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different decision. Reload and try again.']);
                    }

                    return (string) $existing->outcome;
                }
                $found = DB::table('inventory_pack_conversions')->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower($conversionPublicId))->first(['id', 'inventory_sku_id']);
                abort_unless($found !== null, 404);
                // The SKU first, as every pack conversion write, then the version.
                $sku = InventorySku::query()->whereKey((int) $found->inventory_sku_id)->lockForUpdate()->firstOrFail();
                $conversion = DB::table('inventory_pack_conversions')->where('id', $found->id)->sole();
                if (DB::table('inventory_pack_conversion_outcomes')->where('pack_conversion_id', $conversion->id)->exists()) {
                    throw ValidationException::withMessages(['pack_conversion' => 'This change was already decided. Reload to see it.']);
                }
                $withdraw = $payload[0] === 'withdraw';
                if ($withdraw && (int) $conversion->proposed_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['pack_conversion' => 'Only the person who proposed this change can withdraw it.']);
                }
                if (! $withdraw && (int) $conversion->proposed_by_user_id === $actor->id) {
                    throw ValidationException::withMessages(['pack_conversion' => 'Someone other than the proposer must check this change.']);
                }
                [$result, $reason, $typed] = $outcome($conversion);
                $id = DB::table('inventory_pack_conversion_outcomes')->insertGetId([
                    'organisation_id' => $actor->organisation_id, 'pack_conversion_id' => $conversion->id, 'outcome' => $result, 'reason' => $reason,
                    'checked_base_per_pack' => $typed, 'recorded_by_user_id' => $actor->id, 'recorded_at' => now()->utc(), 'idempotency_key' => $key, 'request_hash' => $hash,
                ]);
                abort_if($this->audit->record('inventory.pack_conversion.'.$result, $sku, ['pack_conversion_id' => (int) $conversion->id, 'outcome_id' => $id, 'version' => (int) $conversion->version, 'reason' => $reason], $actor) === null,
                    503, 'The decision could not be recorded, so nothing was saved.');

                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['pack_conversion' => 'Someone else decided this change at the same moment. Reload to see it.']);
        }
    }

    private function lockSku(User $actor, string $skuPublicId): InventorySku
    {
        $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower($skuPublicId))->lockForUpdate()->first();
        abort_unless($sku !== null, 404);

        return $sku;
    }

    /** @return list<stdClass> each version with its outcome (or null), oldest first */
    public function versions(int $skuId): array
    {
        return array_values(DB::table('inventory_pack_conversions as c')->leftJoin('inventory_pack_conversion_outcomes as o', 'o.pack_conversion_id', '=', 'c.id')
            ->where('c.inventory_sku_id', $skuId)->orderBy('c.version')
            ->get(['c.id', 'c.public_id', 'c.version', 'c.pack_unit', 'c.base_per_pack', 'c.base_unit', 'c.proposed_by_user_id', 'c.proposed_at',
                'o.outcome', 'o.reason', 'o.recorded_by_user_id', 'o.recorded_at'])->all());
    }

    /** @param list<stdClass> $versions */
    public function current(array $versions): ?stdClass
    {
        $checked = array_values(array_filter($versions, fn (stdClass $v): bool => $v->outcome === 'checked'));

        return $checked === [] ? null : end($checked);
    }

    /** @param list<stdClass> $versions */
    public function waiting(array $versions): ?stdClass
    {
        foreach ($versions as $version) {
            if ($version->outcome === null) {
                return $version;
            }
        }

        return null;
    }
}
