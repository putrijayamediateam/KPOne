<?php

/*
 * B3 soak seed: one synthetic branch day on a disposable *_soak database that already holds the demo seeders
 * (migrate:fresh --seed, KPOnePresentationSeeder, KPOneDemoSetupSeeder). Adds 7 more doctors and 17 more CAs at CHERAS,
 * 8 consultation rooms (one per doctor), and about 100 queue entries: waiting, serving, not-ready flags, removed.
 * Run: php docs/reports/b3-soak/seed.php   (refuses any database whose name does not end in _soak or _test)
 */

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Database\Seeders\KPOnePresentationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if (DB::connection()->getDriverName() !== 'pgsql' || preg_match('/_(soak|test)$/', $database) !== 1) {
    fwrite(STDERR, "Refusing to seed database {$database}\n");
    exit(65);
}

$branch = Branch::query()->where('code', 'CHERAS')->firstOrFail();
$director = User::query()->where('email', 'demo.director@kpone.test')->firstOrFail();
$demoDoctor = User::query()->where('email', 'demo.resident_doctor@kpone.test')->firstOrFail();
$demoCa = User::query()->where('email', 'demo.ca@kpone.test')->firstOrFail();
$as = function (User $user) use ($branch): void {
    Auth::login($user);
    session([BranchAccessService::SESSION_KEY => $branch->id]);
};
$department = Department::query()->where('organisation_id', $director->organisation_id)->where('code', 'CLINIC_OPERATIONS')->firstOrFail();
$as($director);

$provision = function (string $email, string $name, string $role) use ($director, $branch, $department): User {
    $existing = User::query()->where('email', $email)->first();
    if ($existing) {
        return $existing;
    }

    return app(StaffProvisioningService::class)->provision($director, [
        'name' => $name, 'email' => $email, 'credential_strategy' => 'password',
        'password' => KPOnePresentationSeeder::PASSWORD, 'password_confirmation' => KPOnePresentationSeeder::PASSWORD,
        'department_id' => $department->id, 'staff_number' => 'SOAK-'.Str::upper(Str::random(6)), 'job_title' => 'Soak '.$role,
        'is_active' => true, 'roles' => [$role],
        'assignments' => [['branch_id' => $branch->id, 'assignment_type' => 'permanent', 'is_primary' => true, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]],
    ]);
};

$doctors = [$demoDoctor];
for ($i = 1; $i <= 7; $i++) {
    $doctors[] = $provision("soak.doctor{$i}@kpone.test", "Soak Doctor {$i}", 'resident_doctor');
}
$cas = [$demoCa];
for ($i = 1; $i <= 17; $i++) {
    $cas[] = $provision("soak.ca{$i}@kpone.test", "Soak CA {$i}", 'ca');
}

foreach ([1, 2, 3] as $i) {
    $provision("soak.tv{$i}@kpone.test", "Soak TV {$i}", 'queue_display');
}

if (DB::table('queue_entries')->exists()) {
    echo "Queue entries already seeded; staff and TV accounts checked only.\n";
    exit(0);
}

foreach ($doctors as $index => $doctor) {
    $as($director);
    $room = app(QueueDisplayAdministrationService::class)->createRoom($director, $branch, ['kind' => 'consultation', 'name' => 'Bilik '.($index + 1), 'sort_order' => $index + 1]);
    $as($doctor);
    app(DoctorRoomService::class)->select($doctor, $room->id, $branch->id);
}

$as($demoCa);
$reason = app(VisitReasonService::class)->create($demoCa, 'Soak demam');
$entries = [];
for ($i = 1; $i <= 100; $i++) {
    $ca = $cas[$i % count($cas)];
    $doctor = $doctors[$i % count($doctors)];
    $as($ca);
    $patient = app(PatientAdministrationService::class)->create($ca, [
        'full_name' => "Synthetic Soak Patient {$i}", 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789',
        'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SOAK-'.Str::upper(Str::random(10))]], 'duplicate_override' => true,
    ]);
    $visit = app(VisitRegistrationService::class)->register($ca, [
        'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $branch->id, 'patient_number' => $patient->patient_number,
        'visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason_public_ids' => [$reason->public_id],
        'priority' => $i % 9 === 0 ? 'urgent' : 'normal', 'coverage_type' => 'self_pay', 'panel_id' => null, 'coverage_member_reference' => null, 'confirm_repeat' => false,
    ]);
    $entry = app(QueueEntryService::class)->enter($ca, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version]);
    $entries[] = [$visit, $entry, $doctor, $ca];
}

// Four doctors are serving a patient (the other four have nobody in the room, so Call next can be exercised); five waiting entries carry a not-ready flag.
foreach (array_slice($doctors, 0, 4) as $doctor) {
    foreach ($entries as [$visit, $entry, $assigned]) {
        if ($assigned->id === $doctor->id) {
            $as($doctor);
            app(QueueEntryService::class)->call($doctor, $visit->refresh(), ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $entry->refresh()->lock_version]);
            break;
        }
    }
}
foreach (range(20, 24) as $i) {
    [$visit, $entry, , $ca] = $entries[$i];
    if ($entry->refresh()->status !== 'waiting') {
        continue;
    }
    $as($ca);
    app(CallHoldService::class)->change($ca, $visit->refresh(), ['expected_branch_id' => $branch->id, 'queue_lock_version' => $entry->refresh()->lock_version, 'reason_code' => 'awaiting_panel_confirmation', 'idempotency_key' => (string) Str::uuid()], false);
}

echo 'queue_entries='.DB::table('queue_entries')->count().' waiting='.DB::table('queue_entries')->where('status', 'waiting')->count().' serving='.DB::table('queue_entries')->where('status', 'serving')->count().' flags='.DB::table('queue_call_holds')->whereNull('closed_at')->count().' doctors='.count($doctors).' cas='.count($cas).PHP_EOL;
