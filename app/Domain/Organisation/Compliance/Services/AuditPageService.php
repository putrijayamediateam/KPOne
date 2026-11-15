<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-11c page props for the Audits tab and the checklist and settings part of Settings. Read only. A person sees the audits
 * and tasks of the branches they are assigned to; the checklist manager (Director, Administrator) sees every branch. "Last
 * month not audited" is worked out when the page opens and never stored. Notes are decrypted for those who may see the audit.
 */
final class AuditPageService
{
    public function __construct(private BranchAccessService $branches, private StaffAccessService $staff) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function auditsTab(User $actor, array $query): array
    {
        $manage = $actor->can(CompliancePermissions::CHECKLISTS_MANAGE);
        $perform = $actor->can(CompliancePermissions::AUDITS_PERFORM);
        $all = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'timezone']);
        $assigned = [];
        foreach ($all as $b) {
            $assigned[$b->id] = $perform && $this->assigned($actor, (int) $b->id);
        }
        $visible = $all->filter(fn ($b): bool => $manage || $assigned[$b->id])->values();
        $visibleIds = $visible->pluck('id')->all();
        $names = $this->staff->visibleUsers($actor);
        $latest = DB::table('compliance_checklist_versions')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();

        $audits = DB::table('compliance_audits as a')->join('branches as b', 'b.id', '=', 'a.branch_id')->whereIn('a.branch_id', $visibleIds)
            ->orderByDesc('a.month_start')->orderBy('b.name')->limit(60)->get(['a.*', 'b.name as branch_name']);
        $counts = DB::table('compliance_tasks')->whereIn('audit_id', $audits->pluck('id'))->selectRaw("audit_id, count(*) as total, count(*) filter (where status in ('open', 'done')) as unfinished")->groupBy('audit_id')->get()->keyBy('audit_id');
        $userIds = $audits->pluck('started_by_user_id')->merge($audits->pluck('submitted_by_user_id'))->filter()->unique();
        $people = $names->whereIn('id', $userIds)->pluck('name', 'id');
        $rows = $audits->map(fn ($a): array => [
            'id' => (string) $a->public_id, 'branchId' => (int) $a->branch_id, 'branchName' => (string) $a->branch_name, 'month' => substr((string) $a->month_start, 0, 7),
            'monthLabel' => CarbonImmutable::parse((string) $a->month_start)->format('F Y'), 'status' => (string) $a->status,
            'startedBy' => (string) ($people[$a->started_by_user_id] ?? 'Staff member'), 'submittedBy' => $a->submitted_by_user_id === null ? null : (string) ($people[$a->submitted_by_user_id] ?? 'Staff member'),
            'submittedAt' => $a->submitted_at === null ? null : CarbonImmutable::parse((string) $a->submitted_at)->utc()->toIso8601String(),
            'tasks' => (int) ($counts[$a->id]->total ?? 0), 'unfinishedTasks' => (int) ($counts[$a->id]->unfinished ?? 0),
        ])->values()->all();

        // Last month not audited: shown from the 1st, per branch, when the audit does not exist (never stored).
        $missed = [];
        foreach ($visible as $b) {
            $prev = AuditService::monthStart((string) $b->timezone, 'previous');
            if (! $audits->contains(fn ($a): bool => (int) $a->branch_id === (int) $b->id && substr((string) $a->month_start, 0, 7) === $prev->format('Y-m') && $a->status === 'submitted')) {
                $missed[] = ['branchId' => (int) $b->id, 'branchName' => (string) $b->name, 'monthLabel' => $prev->format('F Y'), 'canStart' => (bool) $assigned[$b->id] && $perform && ! $audits->contains(fn ($a): bool => (int) $a->branch_id === (int) $b->id && substr((string) $a->month_start, 0, 7) === $prev->format('Y-m'))];
            }
        }

        $selected = null;
        $auditId = $query['audit'] ?? null;
        if (is_string($auditId) && $auditId !== '') {
            $found = $audits->first(fn ($a): bool => (string) $a->public_id === $auditId);
            $selected = $found === null ? null : $this->detail($actor, $found, (bool) ($assigned[$found->branch_id] ?? false) && $perform, $names);
        }

        return [
            'canPerform' => $perform, 'canManage' => $manage, 'checklistReady' => $latest !== null,
            'startable' => $perform ? $visible->filter(fn ($b): bool => $assigned[$b->id])->map(fn ($b): array => ['id' => (int) $b->id, 'name' => (string) $b->name])->values()->all() : [],
            'audits' => $rows, 'missedLastMonth' => $missed, 'selected' => $selected,
            'tasks' => $this->tasks($actor, $visibleIds, $assigned, $perform, $names),
            'cancelReasons' => collect(TaskService::CANCEL_REASONS)->map(fn (string $l, string $v): array => ['value' => $v, 'label' => $l])->values()->all(),
        ];
    }

    /** @return array<string, mixed> checklist and settings for the manager (Settings tab) */
    public function settings(User $actor): array
    {
        $version = DB::table('compliance_checklist_versions')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
        $items = $version === null ? [] : DB::table('compliance_checklist_items')->where('version_id', $version->id)->orderBy('position')->get()
            ->map(fn ($i): array => ['section' => (string) $i->section, 'title' => (string) $i->title, 'help' => $i->help === null ? '' : (string) $i->help, 'photoRequired' => (bool) $i->photo_required])->all();
        $settings = DB::table('compliance_settings')->where('organisation_id', $actor->organisation_id)->first();

        return [
            'version' => $version === null ? null : (int) $version->version, 'items' => $items,
            'verifyDoneTasks' => $settings === null ? true : (bool) $settings->verify_done_tasks,
            'photoRetentionDays' => $settings?->photo_retention_days === null ? null : (int) $settings->photo_retention_days,
            'lockVersion' => $settings === null ? 0 : (int) $settings->lock_version, 'minRetentionDays' => ChecklistService::MIN_RETENTION_DAYS,
        ];
    }

    private function assigned(User $actor, int $branchId): bool
    {
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKey($branchId)->first();

        return $branch !== null && $this->branches->hasEffectiveAssignment($actor, $branch);
    }

    /** @return array<string, mixed> */
    private function detail(User $actor, \stdClass $a, bool $canWork, mixed $names): array
    {
        $items = DB::table('compliance_checklist_items')->where('version_id', $a->checklist_version_id)->orderBy('position')->get();
        $answers = DB::table('compliance_audit_answers')->where('audit_id', $a->id)->get()->keyBy('item_id');
        $photos = DB::table('compliance_audit_photos')->where('audit_id', $a->id)->whereNull('purged_at')->orderBy('id')->get(['public_id', 'item_id', 'label'])->groupBy('item_id');
        $tasks = DB::table('compliance_tasks')->where('audit_id', $a->id)->get()->keyBy('item_id');
        $sections = [];
        foreach ($items as $i) {
            $ans = $answers[$i->id] ?? null;
            $sections[(string) $i->section][] = [
                'id' => (string) $i->public_id, 'title' => (string) $i->title, 'help' => $i->help, 'photoRequired' => (bool) $i->photo_required,
                'result' => $ans?->result, 'note' => $ans === null ? null : $this->decrypt($ans->note_encrypted),
                'photos' => ($photos->get($i->id) ?? collect())->map(fn ($p): array => ['id' => (string) $p->public_id, 'label' => (string) $p->label])->values()->all(),
                'taskStatus' => ($tasks[$i->id] ?? null)?->status,
            ];
        }

        return ['id' => (string) $a->public_id, 'status' => (string) $a->status, 'lockVersion' => (int) $a->lock_version, 'branchName' => (string) DB::table('branches')->where('id', $a->branch_id)->value('name'),
            'monthLabel' => CarbonImmutable::parse((string) $a->month_start)->format('F Y'), 'verifyDoneTasks' => (bool) $a->verify_done_tasks,
            'canEdit' => $canWork && $a->status === 'open', 'sections' => collect($sections)->map(fn (array $list, string $title): array => ['title' => $title, 'items' => $list])->values()->all()];
    }

    /**
     * @param  array<int, mixed>  $branchIds
     * @param  array<int, bool>  $assigned
     * @return array<int, array<string, mixed>>
     */
    private function tasks(User $actor, array $branchIds, array $assigned, bool $perform, mixed $names): array
    {
        $rows = DB::table('compliance_tasks as t')->join('compliance_checklist_items as i', 'i.id', '=', 't.item_id')->join('branches as b', 'b.id', '=', 't.branch_id')->join('compliance_audits as a', 'a.id', '=', 't.audit_id')
            ->whereIn('t.branch_id', $branchIds)->whereIn('t.status', ['open', 'done'])->orderBy('t.created_at')->limit(100)->get(['t.*', 'i.title as item_title', 'b.name as branch_name', 'a.public_id as audit_public_id', 'a.month_start']);
        $photos = DB::table('compliance_task_photos')->whereIn('task_id', $rows->pluck('id'))->whereNull('purged_at')->orderBy('id')->get(['public_id', 'task_id', 'label'])->groupBy('task_id');
        $people = $names->whereIn('id', $rows->pluck('done_by_user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn ($t): array => [
            'id' => (string) $t->public_id, 'auditId' => (string) $t->audit_public_id, 'lockVersion' => (int) $t->lock_version, 'branchName' => (string) $t->branch_name, 'itemTitle' => (string) $t->item_title,
            'monthLabel' => CarbonImmutable::parse((string) $t->month_start)->format('F Y'), 'status' => (string) $t->status, 'verifyRequired' => (bool) $t->verify_required,
            'doneBy' => $t->done_by_user_id === null ? null : (string) ($people[$t->done_by_user_id] ?? 'Staff member'), 'doneByMe' => (int) $t->done_by_user_id === $actor->id,
            'doneNote' => $this->decrypt($t->done_note_encrypted),
            'photos' => ($photos->get($t->id) ?? collect())->map(fn ($p): array => ['id' => (string) $p->public_id, 'label' => (string) $p->label])->values()->all(),
            'canAct' => $perform && ($assigned[$t->branch_id] ?? false),
        ])->values()->all();
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
