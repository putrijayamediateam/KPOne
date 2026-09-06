<?php

namespace Tests\Feature\Visit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitReasonCatalogueTest extends VisitTestCase
{
    public function test_catalogue_normalizes_duplicates_and_is_scoped_to_organisation(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $service = app(VisitReasonService::class);
        $first = $service->create($ca, 'Pregnancy   Scan');
        $same = $service->create($ca, ' pregnancy scan ');

        $this->assertSame($first->id, $same->id);
        $this->assertSame('Pregnancy Scan', $first->name);
        $this->assertSame('pregnancy scan', $first->normalized_name);
        $this->assertDatabaseCount('visit_reason_catalogue_items', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'visit_reason.created', 'subject_id' => $first->id]);

        $otherBranch = $this->otherBranch();
        $otherActor = $this->actor('ca', $otherBranch);
        $this->selectBranch($otherActor, $otherBranch);
        $other = $service->create($otherActor, 'PREGNANCY SCAN');

        $this->assertNotSame($first->id, $other->id);
        $this->assertDatabaseCount('visit_reason_catalogue_items', 2);
    }

    public function test_catalogue_routes_are_authorized_searchable_and_do_not_leak_other_organisations(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        app(VisitReasonService::class)->create($ca, 'Sakit tekak');

        $this->getJson(route('visit-reasons.index', ['query' => 'TEK']))
            ->assertOk()->assertJsonPath('data.0.name', 'Sakit tekak');

        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);
        $this->getJson(route('visit-reasons.index'))->assertForbidden();
        $this->postJson(route('visit-reasons.store'), ['name' => 'Forbidden'])->assertForbidden();
    }

    public function test_structured_visit_has_one_primary_four_additional_and_immutable_snapshots(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $service = app(VisitReasonService::class);
        $reasons = collect(['Fever', 'Cough', 'Follow-Up', 'Vaccination', 'Medical Check-Up'])
            ->map(fn (string $name) => $service->create($ca, $name));

        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => $reasons->pluck('public_id')->all(),
        ]);

        $this->assertSame('Fever', $visit->visit_reason);
        $this->assertSame([1, 2, 3, 4, 5], $visit->reasonAssignments()->pluck('position')->all());
        $this->assertSame($reasons->pluck('name')->all(), $visit->reasonAssignments()->pluck('label_snapshot')->all());
        $reasons->first()->forceFill(['name' => 'Changed catalogue wording'])->save();
        $this->assertSame('Fever', $visit->reasonAssignments()->first()->label_snapshot);

        $detail = app(VisitDirectoryService::class)->detail($ca, $visit);
        $this->assertSame('Fever', $detail['visitReasons']['primary']);
        $this->assertSame(['Cough', 'Follow-Up', 'Vaccination', 'Medical Check-Up'], $detail['visitReasons']['additional']);
        $boardVisit = collect(app(VisitDirectoryService::class)->search($ca, [])['data'])
            ->firstWhere('visitNumber', $visit->visit_number);
        $this->assertSame('Fever · Cough · Follow-Up · Vaccination · Medical Check-Up', $boardVisit['visitReasonExcerpt']);
    }

    public function test_sixth_duplicate_and_foreign_reason_are_rejected(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $service = app(VisitReasonService::class);
        $ids = collect(range(1, 6))->map(fn (int $i) => $service->create($ca, 'Reason '.$i)->public_id)->all();
        $base = [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
        ];

        foreach ([
            [...$base, 'visit_reason_public_ids' => $ids],
            [...$base, 'idempotency_key' => (string) Str::uuid(), 'visit_reason_public_ids' => [$ids[0], $ids[0]]],
        ] as $attributes) {
            try {
                app(VisitRegistrationService::class)->register($ca, $attributes);
                $this->fail('Invalid Visit Reason selection was accepted.');
            } catch (ValidationException $exception) {
                $this->assertTrue(collect(array_keys($exception->errors()))->contains(
                    fn (string $key): bool => str_starts_with($key, 'visit_reason_public_ids'),
                ));
            }
        }

        $otherBranch = $this->otherBranch();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $foreign = $service->create($other, 'Foreign reason');
        $this->selectBranch($ca);
        try {
            app(VisitRegistrationService::class)->register($ca, [
                ...$base,
                'idempotency_key' => (string) Str::uuid(),
                'visit_reason_public_ids' => [$foreign->public_id],
            ]);
            $this->fail('Foreign Visit Reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('visit_reason_public_ids', $exception->errors());
        }
    }

    public function test_primary_can_change_without_mutating_catalogue_or_snapshot_labels(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $service = app(VisitReasonService::class);
        $first = $service->create($ca, 'Fever');
        $second = $service->create($ca, 'Cough');
        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$first->public_id, $second->public_id],
        ]);

        $updated = app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$second->public_id, $first->public_id],
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
        ], $ca);

        $this->assertSame('Cough', $updated->visit_reason);
        $this->assertSame(['Cough', 'Fever'], $updated->reasonAssignments()->pluck('label_snapshot')->all());
        $this->assertSame(['Fever', 'Cough'], [$first->refresh()->name, $second->refresh()->name]);
        $audit = AuditLog::query()->where('event', 'visit.reasons.updated')->sole();
        $this->assertSame(2, $audit->metadata['selected_reason_count']);
        $this->assertStringNotContainsString('Fever', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_unrelated_edit_preserves_snapshot_and_primary_compatibility_mirror_after_catalogue_rename(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $reason = app(VisitReasonService::class)->create($ca, 'Fever');
        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$reason->public_id],
        ]);
        $reason->forceFill(['name' => 'Pyrexia'])->save();

        $updated = app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$reason->public_id],
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
        ], $ca);

        $this->assertSame('Fever', $updated->reasonAssignments()->sole()->label_snapshot);
        $this->assertSame('Fever', $updated->visit_reason);
    }

    public function test_reorder_remove_and_add_preserve_retained_snapshots_and_snapshot_only_new_assignments(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $service = app(VisitReasonService::class);
        $fever = $service->create($ca, 'Fever');
        $cough = $service->create($ca, 'Cough');
        $newReason = $service->create($ca, 'Sore throat');
        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$fever->public_id, $cough->public_id],
        ]);
        $fever->forceFill(['name' => 'Pyrexia'])->save();
        $cough->forceFill(['name' => 'Tussis'])->save();
        $newReason->forceFill(['name' => 'Throat pain'])->save();

        $updated = app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$cough->public_id, $newReason->public_id],
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
        ], $ca);

        $this->assertSame(['Cough', 'Throat pain'], $updated->reasonAssignments()->pluck('label_snapshot')->all());
        $this->assertSame('Cough', $updated->visit_reason);
    }

    public function test_inactive_retained_reason_keeps_snapshot_during_unrelated_edit(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $reason = app(VisitReasonService::class)->create($ca, 'Historical active reason');
        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$reason->public_id],
        ]);
        $reason->forceFill(['name' => 'Changed inactive label', 'is_active' => false])->save();

        $updated = app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$reason->public_id],
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
        ], $ca);

        $this->assertSame('Historical active reason', $updated->reasonAssignments()->sole()->label_snapshot);
        $this->assertSame('Historical active reason', $updated->visit_reason);
    }

    public function test_inactive_reason_is_not_searchable_or_assignable(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $reason = app(VisitReasonService::class)->create($ca, 'Inactive synthetic reason');
        $reason->forceFill(['is_active' => false])->save();

        $this->getJson(route('visit-reasons.index', ['query' => 'inactive']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        try {
            app(VisitReasonService::class)->resolve($ca, [$reason->public_id], true);
            $this->fail('Inactive Visit Reason was assignable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('visit_reason_public_ids', $exception->errors());
        }
    }

    public function test_new_consultation_and_quick_create_cannot_bypass_structured_reason(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $base = [
            ...$this->visitAttributes($this->patient($ca)),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
        ];
        try {
            app(VisitRegistrationService::class)->register($ca, $base);
            $this->fail('Missing structured reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('visit_reason_public_ids', $exception->errors());
        }

        $reason = app(VisitReasonService::class)->create($ca, 'Fever');
        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$base,
            'idempotency_key' => (string) Str::uuid(),
            'patient_number' => null,
            'quick_patient' => [
                'full_name' => 'Synthetic R1-B Quick', 'sex' => 'unknown', 'mobile_phone' => '+60123456789',
                'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-R1B-QUICK']],
                'duplicate_override' => true,
            ],
            'visit_reason_public_ids' => [$reason->public_id],
        ]);
        $this->assertSame('Fever', $visit->visit_reason);
    }

    public function test_legacy_free_text_visit_remains_exact_and_is_not_catalogue_migrated(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $patient = $this->patient($ca);
        $visit = Visit::factory()->create([
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->branch->id,
            'patient_id' => $patient->id,
            'visit_type' => 'consultation',
            'visit_reason' => "  Historical wording\nkept exactly  ",
            'assigned_doctor_user_id' => $doctor->id,
            'registered_by_user_id' => $ca->id,
            'updated_by_user_id' => $ca->id,
        ]);
        $this->selectBranch($ca);

        $detail = app(VisitDirectoryService::class)->detail($ca, $visit);
        $this->assertSame("  Historical wording\nkept exactly  ", $detail['visitReasons']['legacy']);
        $this->assertNull($detail['visitReasons']['primary']);

        $updated = app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
        ], $ca);
        $this->assertSame("  Historical wording\nkept exactly  ", $updated->visit_reason);
        $this->assertDatabaseCount('visit_reason_assignments', 0);
        $this->assertDatabaseCount('visit_reason_catalogue_items', 0);
    }

    private function otherBranch(): Branch
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYN-'.Str::upper(Str::random(8)), 'name' => 'Synthetic other organisation', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-DEPT', 'name' => 'Synthetic Department', 'is_active' => true])->save();

        return $branch;
    }
}
