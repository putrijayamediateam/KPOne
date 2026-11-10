<?php

namespace App\Domain\HR\Letters\Services;

use App\Domain\HR\Letters\LetterPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HR-10a page props for /hr/letters: My letters for everyone with their own staff file, and Issued and Templates for the
 * Director and HR Manager. Lists carry no letter text; a letter's text is returned only by opening it (recorded first).
 */
final class LettersPageService
{
    public function __construct(private LetterService $letters) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canIssue = $actor->can(LetterPermissions::ISSUE);
        $tabs = array_values(array_filter([$actor->can(RecordPermissions::VIEW_OWN) ? 'mine' : null, $canIssue ? 'issued' : null, $canIssue ? 'templates' : null]));
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], $tabs, true) ? $query['tab'] : ($canIssue ? 'issued' : ($tabs[0] ?? 'mine'));
        $zone = $this->letters->zone((int) $actor->organisation_id);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'canIssue' => $canIssue,
            'mine' => $actor->can(RecordPermissions::VIEW_OWN) ? $this->mine($actor, $zone) : [],
            'issued' => $canIssue ? $this->issued($actor, $zone) : [],
            'templates' => $canIssue ? $this->templates($actor) : [],
            'workers' => $canIssue ? $this->workers($actor) : [],
            'types' => LetterService::TYPES,
            'voidReasons' => LetterService::VOID_REASONS,
            'placeholders' => LetterService::PLACEHOLDERS,
            'detailsTypes' => LetterService::DETAILS_TYPES,
            'today' => CarbonImmutable::now($zone)->toDateString(),
        ];
    }

    /** How many of your own letters are current, for the My HR details link. */
    public function mineCount(User $actor): int
    {
        if (! $actor->can(RecordPermissions::VIEW_OWN)) {
            return 0;
        }

        return DB::table('hr_letters as l')->join('hr_workers as w', 'w.id', '=', 'l.worker_id')->where('l.organisation_id', $actor->organisation_id)
            ->where('w.user_id', $actor->id)->whereNull('l.voided_at')->count();
    }

    /** @return list<array<string, mixed>> */
    private function mine(User $actor, string $zone): array
    {
        $rows = $this->base($actor)->where('w.user_id', $actor->id)->limit(100)->get();

        return array_values($rows->map(fn (\stdClass $r): array => $this->row($r, $zone))->all());
    }

    /** @return list<array<string, mixed>> */
    private function issued(User $actor, string $zone): array
    {
        $rows = $this->base($actor)->limit(200)->get();
        $workers = Worker::query()->with(['user.roles'])->whereIn('id', $rows->pluck('worker_id')->unique()->all())->get()->keyBy('id');
        $issuers = DB::table('users')->whereIn('id', $rows->pluck('issued_by_user_id')->unique()->all())->pluck('name', 'id');

        // HR-10c (Suresh, M3): letters to someone the viewer may not manage are left out, so a Director's or HR Manager's
        // warning or show-cause letter does not show other managers that a case exists. Recipients see their own in My letters.
        $rows = $rows->filter(function (\stdClass $r) use ($workers, $actor): bool {
            $worker = $workers->get($r->worker_id);

            return $worker instanceof Worker && ($worker->user === null || $this->letters->refusalFor($actor, $worker->user) === null);
        });

        return array_values($rows->map(function (\stdClass $r) use ($zone, $workers, $issuers, $actor): array {
            $worker = $workers->get($r->worker_id);
            $refusal = ! $worker instanceof Worker ? 'This staff file cannot take letters.' : ($worker->user === null ? null : $this->letters->refusalFor($actor, $worker->user));

            return [...$this->row($r, $zone), 'recipient' => $worker instanceof Worker ? $this->name($worker) : 'Staff member', 'workerId' => $worker?->public_id,
                'issuedBy' => (string) ($issuers[$r->issued_by_user_id] ?? 'Staff member'), 'lockVersion' => (int) $r->lock_version, 'refusal' => $refusal];
        })->all());
    }

    /** @return list<array<string, mixed>> */
    private function templates(User $actor): array
    {
        $rows = DB::table('hr_letter_templates as t')->join('hr_letter_template_versions as v', fn ($j) => $j->on('v.template_id', '=', 't.id')->on('v.version', '=', 't.current_version'))
            ->where('t.organisation_id', $actor->organisation_id)->orderByRaw('t.retired_at IS NOT NULL')->orderBy('v.title')->limit(100)
            ->get(['t.public_id', 't.type', 't.current_version', 't.retired_at', 't.lock_version', 'v.title', 'v.title_bm', 'v.body_en', 'v.body_bm']);

        return array_values($rows->map(fn (\stdClass $t): array => ['id' => (string) $t->public_id, 'type' => (string) $t->type, 'typeLabel' => LetterService::TYPES[$t->type] ?? (string) $t->type,
            'title' => (string) $t->title, 'titleBm' => (string) $t->title_bm, 'version' => (int) $t->current_version, 'retired' => $t->retired_at !== null, 'lockVersion' => (int) $t->lock_version,
            'bodyEn' => (string) $t->body_en, 'bodyBm' => (string) $t->body_bm, 'hasDetails' => str_contains((string) $t->body_en.$t->body_bm, '{details}')])->all());
    }

    /** @return list<array{id:string, name:string, kind:string, refusal:?string}> */
    private function workers(User $actor): array
    {
        $workers = Worker::query()->with(['user.roles'])->where('organisation_id', $actor->organisation_id)->limit(500)->get();

        return array_values($workers->filter(fn (Worker $w): bool => $w->user_id === null || ($w->user !== null && $w->user->is_active))
            ->map(fn (Worker $w): array => ['id' => (string) $w->public_id, 'name' => $this->name($w), 'kind' => (string) $w->kind,
                'refusal' => $w->user === null ? null : $this->letters->refusalFor($actor, $w->user)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
    }

    private function base(User $actor): Builder
    {
        return DB::table('hr_letters as l')->join('hr_workers as w', 'w.id', '=', 'l.worker_id')
            ->join('hr_letter_template_versions as v', 'v.id', '=', 'l.template_version_id')
            ->where('l.organisation_id', $actor->organisation_id)->orderByDesc('l.issued_at')->orderByDesc('l.id')
            ->leftJoin('hr_letters as r', 'r.replaces_letter_id', '=', 'l.id')
            ->select(['l.id', 'l.public_id', 'l.worker_id', 'l.type', 'l.language', 'l.reference', 'l.issued_at', 'l.issued_by_user_id', 'l.given_on',
                'l.voided_at', 'l.void_reason', 'l.lock_version', 'l.replaces_letter_id', 'v.title', 'v.title_bm', 'r.reference as replaced_by', 'r.voided_at as replaced_by_voided_at']);
    }

    /** @return array<string, mixed> */
    private function row(\stdClass $r, string $zone): array
    {
        $status = $r->voided_at === null ? 'current' : ($r->void_reason === 'replaced' ? 'replaced' : 'void');

        return ['id' => (string) $r->public_id, 'reference' => (string) $r->reference, 'title' => LetterService::title($r, (string) $r->language), 'type' => (string) $r->type,
            'typeLabel' => LetterService::TYPES[$r->type] ?? (string) $r->type, 'language' => $r->language === 'en' ? 'English' : 'Malay',
            'issuedOn' => CarbonImmutable::parse((string) $r->issued_at, 'UTC')->setTimezone($zone)->format('j M Y'),
            'givenOn' => $r->given_on === null ? null : CarbonImmutable::parse((string) $r->given_on)->format('j M Y'),
            'status' => $status, 'voidReason' => $status === 'void' ? (LetterService::VOID_REASONS[$r->void_reason] ?? (string) $r->void_reason) : null,
            // A replaced letter names its replacement, and says when that one was voided too, so HR knows to issue again (Suresh, m3).
            'replacedBy' => $r->replaced_by === null ? null : (string) $r->replaced_by.($r->replaced_by_voided_at !== null ? ' (void)' : '')];
    }

    /** The account name, or a locum's display name, as Staff files shows it; the legal name stays behind Show (Suresh, B1). */
    private function name(Worker $worker): string
    {
        return $worker->name();
    }
}
