<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * DS-6: the Consultation space home, for the doctor's own queue. Every widget reads the Queue board's own service
 * (QueueDirectoryService), which keeps its own permission and branch checks and shows a doctor only their own
 * patients. A patient shows as a queue number and a first name with an initial, never the full name.
 */
final class ConsultationSpace
{
    public const LIST_LIMIT = 5;

    public function __construct(private BranchAccessService $branches, private QueueDirectoryService $queue) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $snapshot = $this->snapshot($actor);
        $seeAll = route('queue.index', absolute: false);

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'waiting' => $snapshot === null ? null : ['total' => $snapshot['waitingTotal'], 'limit' => self::LIST_LIMIT, 'seeAllHref' => $seeAll, 'rows' => array_slice($snapshot['waiting'], 0, self::LIST_LIMIT)],
            'held' => $snapshot === null ? null : ['total' => count($snapshot['held']), 'limit' => self::LIST_LIMIT, 'seeAllHref' => $seeAll, 'rows' => array_slice($snapshot['held'], 0, self::LIST_LIMIT)],
            'next' => $this->nextLine($snapshot),
            'primaryAction' => $snapshot === null ? null : ['title' => 'Open my queue', 'href' => $seeAll],
            'links' => [],
        ];
    }

    /**
     * The Call next status, left out when it only repeats the first row of the list (Call next skips patients
     * marked not ready, so the candidate is never one of those).
     *
     * @param  array{waiting: list<array<string, mixed>>, next: array<string, mixed>|null}|null  $snapshot
     * @return array{ready: bool, text: string}|null
     */
    private function nextLine(?array $snapshot): ?array
    {
        $next = $snapshot['next'] ?? null;
        if ($next === null || ($next['ready'] && ($snapshot['waiting'][0]['queueNumber'] ?? null) === ($next['number'] ?? null))) {
            return null;
        }

        return ['ready' => (bool) $next['ready'], 'text' => (string) $next['text']];
    }

    /**
     * The doctor's own waiting patients (today first, then still-waiting carry-over), held consultations and Call next state, from the Queue
     * board's own snapshot. Null for anyone who cannot open that board or has no active branch.
     *
     * @return array{waitingTotal: int, waiting: list<array<string, mixed>>, held: list<array<string, mixed>>, next: array<string, mixed>|null}|null
     */
    public function snapshot(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('queue.view.own')) {
            return null;
        }
        $data = $this->queue->snapshot($actor);
        $row = fn (array $r): array => [
            'queueNumber' => (string) $r['queueNumber'],
            'patient' => SpaceDisplay::shortName((string) $r['patientName']),
            'urgent' => ($r['priority'] ?? null) === 'urgent',
            'waitText' => $r['waitingMinutes'] === null ? null : SpaceDisplay::waitText((int) $r['waitingMinutes']),
            'minutes' => (int) ($r['waitingMinutes'] ?? 0),
            'notReady' => is_array($r['callHold'] ?? null) && ($r['callHold']['active'] ?? false) ? (string) ($r['callHold']['reason'] ?? 'Not ready') : null,
            'href' => route('visits.show', ['visit' => $r['visitNumber']], absolute: false),
        ];
        $waiting = array_map($row, array_merge($this->all($data['waiting']['data']), $this->all($data['carryOver']['data'])));
        $held = [];
        foreach ($this->all($data['serving']) as $r) {
            if (! ($r['isHeld'] ?? false)) {
                continue;
            }
            $minutes = (int) floor((now()->diffInSeconds(CarbonImmutable::parse((string) $r['holdStartedAt']), true)) / 60);
            $held[] = [
                'queueNumber' => (string) $r['queueNumber'],
                'patient' => SpaceDisplay::shortName((string) $r['patientName']),
                'heldText' => 'On hold '.SpaceDisplay::duration($minutes).(($r['isHeldTooLong'] ?? false) ? '. Held for more than 30 minutes' : ''),
                'tooLong' => (bool) ($r['isHeldTooLong'] ?? false),
                'href' => route('visits.show', ['visit' => $r['visitNumber']], absolute: false),
            ];
        }
        $next = null;
        if (is_array($data['callNext'] ?? null)) {
            $c = $data['callNext']['candidate'] ?? null;
            $next = [
                'ready' => ($data['callNext']['state'] ?? null) === 'ready',
                'number' => is_array($c) ? (string) $c['queueNumber'] : null,
                'text' => is_array($c)
                    ? 'Next: '.$c['queueNumber'].' '.SpaceDisplay::shortName((string) $c['patientName'])
                    : (string) ($data['callNext']['reason'] ?? ''),
            ];
        }

        return ['waitingTotal' => (int) $data['waiting']['total'] + (int) $data['carryOver']['total'], 'waiting' => $waiting, 'held' => $held, 'next' => $next];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(mixed $source): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($source instanceof Collection ? $source->all() : (array) $source);

        return $rows;
    }
}
