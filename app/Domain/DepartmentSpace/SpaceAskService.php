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
    public function __construct(private BranchAccessService $branches, private PharmacySpace $pharmacy, private AuditRecorder $audit) {}

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

    /** @return array{key: string, label: string, sentence: string, columns: list<string>, rows: list<list<string>>} */
    public function ask(User $actor, string $space, string $key): array
    {
        $question = SpaceQuestions::find($key);
        abort_if($question === null || $question['space'] !== $space, 404);
        abort_unless($this->allowed($actor, $key), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_if($branch === null, 403);

        $answer = $this->{$question['builder']}($actor);
        $this->audit->record('space_ask.answered', null, ['question' => $key], $actor, $branch, $actor->organisation_id);

        return ['key' => $key, 'label' => $question['label'], 'sentence' => $answer['sentence'], 'columns' => $answer['columns'], 'rows' => $answer['rows']];
    }

    private function allowed(User $actor, string $key): bool
    {
        if (! SpaceQuestions::holdsPermission($actor, $key) || $this->branches->activeBranch($actor) === null) {
            return false;
        }

        return match ($key) {
            'pharmacy.left_to_dispense' => $this->pharmacy->toDispense($actor) !== null,
            'pharmacy.returns_waiting' => $this->pharmacy->returnsToCheck($actor) !== null,
            default => false,
        };
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function leftToDispense(User $actor): array
    {
        $list = $this->pharmacy->toDispense($actor);
        abort_if($list === null, 403);
        $total = (int) $list['total'];
        $rows = [];
        /** @var list<array<string, mixed>> $shown */
        $shown = $list['rows'];
        foreach ($shown as $row) {
            $rows[] = [(string) ($row['queueNumber'] ?? ''), (string) $row['patient'], $row['minutes'].' min'];
        }

        return [
            'sentence' => $total === 0 ? 'Nothing is waiting at Dispensary.' : ($total === 1 ? '1 patient is waiting at Dispensary.' : $total.' patients are waiting at Dispensary.').($total > count($rows) ? ' The '.count($rows).' longest waiting are shown.' : ''),
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
}
