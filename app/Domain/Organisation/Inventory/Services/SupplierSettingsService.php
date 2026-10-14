<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class SupplierSettingsService
{
    public const PERMISSION = 'catalogue.settings.manage.organisation';

    public function __construct(
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $a */
    public function create(User $actor, array $a): InventorySupplier
    {
        $data = Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'contacts' => ['required', 'array', 'min:1', 'max:20'],
            'contacts.*.name' => ['nullable', 'string', 'max:200'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.country' => ['required', 'string', 'size:2'],
            'code' => ['prohibited'], 'organisation_id' => ['prohibited'], 'is_active' => ['prohibited'],
        ])->validate();
        $contacts = [];
        foreach ($data['contacts'] as $index => $contact) {
            $name = trim((string) ($contact['name'] ?? ''));
            $phone = $this->phone($contact['phone'] ?? null, $contact['country'], "contacts.{$index}.phone");
            if ($name !== '' || $phone !== null) {
                $contacts[] = ['name' => $name === '' ? null : $name, 'phone' => $phone];
            }
        }
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the company name.']);
        }
        $hash = hash('sha256', json_encode([$name, $contacts, (int) $data['expected_branch_id']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $contacts, $name, $hash): InventorySupplier {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            $existing = $this->replay($actor, $branchId, $data['idempotency_key'], 'create', $hash);
            if ($existing !== null) {
                return $existing;
            }
            $supplier = new InventorySupplier;
            $supplier->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                'code' => 'SUP-'.Str::upper((string) Str::uuid()), 'name' => $name,
                'contact_name' => $contacts[0]['name'] ?? null, 'business_phone' => $contacts[0]['phone'] ?? null,
                'is_active' => true, 'lock_version' => 1,
            ])->save();
            foreach (array_slice($contacts, 1) as $index => $contact) {
                DB::table('inventory_supplier_contacts')->insert([
                    'organisation_id' => $actor->organisation_id, 'inventory_supplier_id' => $supplier->id,
                    'position' => $index + 2, ...$contact, 'created_at' => now()->utc(), 'updated_at' => now()->utc(),
                ]);
            }
            $this->remember($actor, $branchId, $supplier, $data['idempotency_key'], 'create', $hash);
            $this->audit->record('inventory.supplier.created', $supplier, ['code' => $supplier->code, 'is_active' => true, 'contact_count' => count($contacts)], $actor);

            return $supplier;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function setActive(User $actor, InventorySupplier $routeSupplier, array $a): InventorySupplier
    {
        $data = Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'active' => ['required', 'boolean'],
        ])->validate();
        $active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        $hash = hash('sha256', json_encode([$routeSupplier->id, $active, (int) $data['lock_version'], (int) $data['expected_branch_id']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $routeSupplier, $data, $active, $hash): InventorySupplier {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            $existing = $this->replay($actor, $branchId, $data['idempotency_key'], 'status', $hash);
            if ($existing !== null) {
                return $existing;
            }
            $supplier = InventorySupplier::query()->whereKey($routeSupplier->id)
                ->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($supplier->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['supplier' => 'This supplier has changed. Reload before continuing.']);
            }
            if ($supplier->is_active !== $active) {
                $supplier->forceFill(['is_active' => $active, 'lock_version' => $supplier->lock_version + 1])->save();
                $this->audit->record($active ? 'inventory.supplier.activated' : 'inventory.supplier.deactivated', $supplier,
                    ['is_active' => $active, 'lock_version' => $supplier->lock_version], $actor);
            }
            $this->remember($actor, $branchId, $supplier, $data['idempotency_key'], 'status', $hash);

            return $supplier;
        }, 3);
    }

    /** @return array{User, int} */
    private function context(User $actor, mixed $expectedBranch): array
    {
        $branch = $this->authority->branch($actor, $expectedBranch);
        // Serialise scoped idempotency keys across actors, including simultaneous first use.
        Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, self::PERMISSION);

        return [$actor, $branch->id];
    }

    private function replay(User $actor, int $branchId, string $key, string $operation, string $hash): ?InventorySupplier
    {
        $command = DB::table('inventory_supplier_settings_commands')->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($command === null) {
            return null;
        }
        if ($command->branch_id !== $branchId || $command->operation !== $operation || $command->payload_hash !== $hash) {
            throw ValidationException::withMessages(['supplier' => 'This request key was already used with different details.']);
        }

        return InventorySupplier::query()->whereKey($command->inventory_supplier_id)
            ->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
    }

    private function remember(User $actor, int $branchId, InventorySupplier $supplier, string $key, string $operation, string $hash): void
    {
        DB::table('inventory_supplier_settings_commands')->insert([
            'organisation_id' => $actor->organisation_id, 'branch_id' => $branchId,
            'inventory_supplier_id' => $supplier->id, 'idempotency_key' => $key, 'operation' => $operation,
            'payload_hash' => $hash, 'created_at' => now()->utc(),
        ]);
    }

    private function phone(?string $value, string $country, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        if (! in_array($country, $util->getSupportedRegions(), true) || preg_match('/\A[+()0-9 .-]+\z/D', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a valid phone number for the selected country.']);
        }
        try {
            $number = $util->parse($value, $country);
            if (! $util->isValidNumber($number)) {
                throw new NumberParseException(NumberParseException::NOT_A_NUMBER, 'Invalid phone');
            }

            return $util->format($number, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            throw ValidationException::withMessages([$field => 'Enter a valid phone number for the selected country.']);
        }
    }
}
