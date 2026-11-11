<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;

/**
 * DS-3a: answers one preset space question. It offers a question only to someone who holds its permission and whose
 * own page would show the answer, re-checks that on every call (403 otherwise, 404 for an unknown key), reads nothing
 * but the page's own service, and stores nothing except a structural audit row (question key and branch id).
 */
final class SpaceAskService
{
    /** The page that holds the full list behind each answer: route name and query. @var array<string, array{0: string, 1: array<string, string>}> */
    private const FULL_LIST = [
        'pharmacy.longest_waiting' => ['registration.index', []],
        'pharmacy.returns_waiting' => ['dispensary.returns-to-check', []],
        'pharmacy.stock_attention' => ['inventory.stock-review', []],
        'front_desk.longest_waiting' => ['registration.index', []],
        'front_desk.waiting_counts' => ['registration.index', []],
        'front_desk.qr_pending' => ['registration.index', ['tab' => 'qr-intake']],
    ];

    public function __construct(private BranchAccessService $branches, private PharmacySpace $pharmacy, private FrontDeskSpace $frontDesk, private AuditRecorder $audit) {}

    /**
     * The questions this person may ask in a space. A question they cannot ask is absent, never greyed.
     *
     * @return list<array{key: string, label: string}>
     */
    public function offered(User $actor, string $space): array
    {
        $out = [];
        foreach (SpaceQuestions::forSpace($space) as $key => $question) {
            if ($this->allowed($actor, $key)) {
                $out[] = ['key' => $key, 'label' => $question['label']];
            }
        }

        return $out;
    }

    /** @return array{key: string, label: string, sentence: string, columns: list<string>, rows: list<list<string>>, asOf: string, href: string} */
    public function ask(User $actor, string $space, string $key): array
    {
        $question = SpaceQuestions::find($key);
        abort_if($question === null || $question['space'] !== $space, 404);
        abort_unless($this->allowed($actor, $key), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_if($branch === null, 403);

        $answer = $this->{$question['builder']}($actor);
        $this->audit->record('space_ask.answered', null, ['question' => $key], $actor, $branch, $actor->organisation_id);

        return [
            'key' => $key,
            'label' => $question['label'],
            'sentence' => $answer['sentence'],
            'columns' => $answer['columns'],
            'rows' => $answer['rows'],
            'asOf' => now()->setTimezone($branch->timezone)->format('g:i A'),
            'href' => route(self::FULL_LIST[$key][0], self::FULL_LIST[$key][1], absolute: false),
        ];
    }

    private function allowed(User $actor, string $key): bool
    {
        if (! SpaceQuestions::holdsPermission($actor, $key) || $this->branches->activeBranch($actor) === null) {
            return false;
        }

        return match ($key) {
            'pharmacy.longest_waiting' => $this->pharmacy->toDispense($actor) !== null,
            'pharmacy.returns_waiting' => $this->pharmacy->returnsToCheck($actor) !== null,
            'pharmacy.stock_attention' => $this->pharmacy->stockWatch($actor) !== null,
            'front_desk.longest_waiting', 'front_desk.waiting_counts' => $this->frontDesk->waiting($actor) !== null,
            'front_desk.qr_pending' => $this->frontDesk->qrPending($actor) !== null,
            default => false,
        };
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function longestWaiting(User $actor): array
    {
        $list = $this->pharmacy->toDispense($actor);
        abort_if($list === null, 403);
        /** @var list<array<string, mixed>> $shown */
        $shown = array_slice($list['rows'], 0, 3);
        if ($shown === []) {
            return ['sentence' => 'Nobody is waiting at Dispensary.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach ($shown as $row) {
            $rows[] = [(string) ($row['queueNumber'] ?? ''), (string) $row['patient'], $this->pharmacy->waitText((int) $row['minutes'])];
        }
        $first = $shown[0];

        return [
            'sentence' => $first['patient'].($first['queueNumber'] ? ' ('.$first['queueNumber'].')' : '').' has waited longest: '.$this->pharmacy->waitText((int) $first['minutes']).'.',
            'columns' => ['Number', 'Patient', 'Waiting'],
            'rows' => $rows,
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function returnsWaiting(User $actor): array
    {
        $returns = $this->pharmacy->returnsToCheck($actor);
        abort_if($returns === null, 403);
        $total = (int) $returns['total'];

        return [
            'sentence' => $total === 0 ? 'Nothing is waiting for a check.' : ($total === 1 ? '1 return is waiting for a check.' : $total.' returns are waiting for a check.'),
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function stockAttention(User $actor): array
    {
        $watch = $this->pharmacy->stockWatch($actor);
        abort_if($watch === null, 403);
        $low = (int) $watch['lowStock'];
        $expiring = (int) $watch['expiring'];
        $expired = (int) $watch['expired'];

        return [
            'sentence' => $low + $expiring + $expired === 0
                ? 'No stock needs attention.'
                : "$low below reorder level, $expiring expiring in {$watch['windowDays']} days, $expired expired on hand.",
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskLongestWaiting(User $actor): array
    {
        $waiting = $this->frontDesk->waiting($actor);
        abort_if($waiting === null, 403);
        /** @var list<array<string, mixed>> $shown */
        $shown = array_slice($waiting['rows'], 0, 3);
        if ($shown === []) {
            return ['sentence' => 'Nobody is waiting.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach ($shown as $row) {
            $rows[] = [(string) ($row['queueNumber'] ?? ''), (string) $row['patient'], SpaceDisplay::waitText((int) $row['minutes'])];
        }
        $first = $shown[0];

        return [
            'sentence' => $first['patient'].($first['queueNumber'] ? ' ('.$first['queueNumber'].')' : '').' has waited longest: '.SpaceDisplay::waitText((int) $first['minutes']).'.',
            'columns' => ['Number', 'Patient', 'Waiting'],
            'rows' => $rows,
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskCounts(User $actor): array
    {
        $waiting = $this->frontDesk->waiting($actor);
        abort_if($waiting === null, 403);
        $counts = $waiting['counts'];
        $parts = [($counts['waiting'] ?? 0).' waiting', ($counts['serving'] ?? 0).' being served', ($counts['completed'] ?? 0).' completed'];
        if (isset($counts['billing'])) {
            $parts[] = $counts['billing'].' awaiting billing';
        }

        return ['sentence' => ucfirst(implode(', ', $parts)).'.', 'columns' => [], 'rows' => []];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskQrPending(User $actor): array
    {
        $qr = $this->frontDesk->qrPending($actor);
        abort_if($qr === null, 403);
        $total = (int) $qr['total'];

        return [
            'sentence' => $total === 0 ? 'No QR check-ins are waiting for review.' : ($total === 1 ? '1 QR check-in is waiting for review.' : $total.' QR check-ins are waiting for review.'),
            'columns' => [],
            'rows' => [],
        ];
    }
}
