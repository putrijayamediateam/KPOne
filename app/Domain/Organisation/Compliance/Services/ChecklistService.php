<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-11c the clinic's own monthly checklist and the audit settings (docs/teams/hr/HR-11-DESIGN.md section 7). KPOne ships no
 * item. Each save adds a new checklist version (append-only); an audit already started keeps the version it began with.
 */
final class ChecklistService
{
    public const MAX_ITEMS = 200;

    public const MIN_RETENTION_DAYS = 30;

    public function __construct(private ComplianceSupport $support) {}

    /**
     * Saves the whole checklist as a new version. Items: section, title, optional help, and whether a photo is required
     * when the item fails.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveChecklist(User $actor, array $input): int
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $raw = $input['items'] ?? null;
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['items' => 'Add between 1 and '.self::MAX_ITEMS.' items.']);
        }
        $items = [];
        foreach (array_values($raw) as $i => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["items.$i" => 'Check this item.']);
            }
            $section = $this->support->text($row['section'] ?? null, "items.$i.section", 80, true);
            $title = $this->support->text($row['title'] ?? null, "items.$i.title", 160, true);
            $help = $this->support->text($row['help'] ?? null, "items.$i.help", 240);
            if (mb_strlen((string) $section) < 2 || mb_strlen((string) $title) < 2) {
                throw ValidationException::withMessages(["items.$i.title" => 'Enter at least 2 characters.']);
            }
            $items[] = ['section' => $section, 'title' => $title, 'help' => $help, 'photo_required' => filter_var($row['photo_required'] ?? false, FILTER_VALIDATE_BOOL)];
        }

        return DB::transaction(function () use ($actor, $key, $items): int {
            $actor = $this->support->lock($actor, CompliancePermissions::CHECKLISTS_MANAGE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['compliance_checklist:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'checklist', $items]);
            if (($replay = $this->support->replay($actor, $key, 'checklist', $hash)) !== null) {
                return (int) $replay;
            }
            $version = (int) DB::table('compliance_checklist_versions')->where('organisation_id', $actor->organisation_id)->max('version') + 1;
            $versionId = (int) DB::table('compliance_checklist_versions')->insertGetId(['organisation_id' => $actor->organisation_id, 'version' => $version, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            foreach ($items as $position => $item) {
                DB::table('compliance_checklist_items')->insert([...$item, 'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'version_id' => $versionId, 'position' => $position + 1]);
            }
            $this->support->remember($actor, $key, 'checklist', $hash, (string) $version);
            $this->support->audit->record('compliance.checklist_saved', null, ['version' => $version, 'item_count' => count($items)], $actor, null, $actor->organisation_id);

            return $version;
        }, 3);
    }

    /** @param  array<string, mixed>  $input */
    public function saveSettings(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $verify = filter_var($input['verify_done_tasks'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($verify === null) {
            throw ValidationException::withMessages(['verify_done_tasks' => 'Choose whether done tasks are verified.']);
        }
        $daysInput = $input['photo_retention_days'] ?? null;
        $days = null;
        if ($daysInput !== null && $daysInput !== '') {
            $days = filter_var($daysInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_RETENTION_DAYS, 'max_range' => 3650]]);
            if ($days === false) {
                throw ValidationException::withMessages(['photo_retention_days' => 'Enter a whole number of days from '.self::MIN_RETENTION_DAYS.' to 3650, or leave it empty to keep photos.']);
            }
        }
        DB::transaction(function () use ($actor, $input, $key, $verify, $days): void {
            $actor = $this->support->lock($actor, CompliancePermissions::CHECKLISTS_MANAGE);
            $hash = $this->support->hash([$actor->id, 'audit_settings', $verify, $days]);
            if ($this->support->replay($actor, $key, 'audit_settings', $hash) !== null) {
                return;
            }
            $row = DB::table('compliance_settings')->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('compliance_settings')->insert(['organisation_id' => $actor->organisation_id, 'verify_done_tasks' => $verify, 'photo_retention_days' => $days,
                    'updated_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            } else {
                $this->support->assertLock($row, $input);
                DB::table('compliance_settings')->where('id', $row->id)->update(['verify_done_tasks' => $verify, 'photo_retention_days' => $days,
                    'updated_by_user_id' => $actor->id, 'lock_version' => $row->lock_version + 1, 'updated_at' => now()->utc()]);
            }
            $this->support->remember($actor, $key, 'audit_settings', $hash, 'saved');
            $this->support->audit->record('compliance.audit_settings_saved', null, ['verify_done_tasks' => $verify, 'retention_set' => $days !== null], $actor, null, $actor->organisation_id);
        }, 3);
    }
}
