<?php

namespace App\Domain\Agents\Assistant;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Agents\Tools\InsightsTodayTool;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dato' Arif, the Director's quick answers (AG-5). Each answer is one `director_assistant` run asked for by
 * the Director: the run is started with the Director's `ai_assistant.use.organisation` permission, every
 * read goes through AgentToolGateway with the Director's own authority (logged as a step), the table and
 * sentence are built by QuickQuestions, one decision step records which question was answered, and the run
 * is completed (or marked failed). Answers are not stored. Nothing here changes clinic data.
 */
final class DirectorAssistant
{
    public const AGENT = 'director_assistant';

    public const TASK = 'quick_answer';

    public function __construct(private AgentRunRecorder $recorder, private AgentToolGateway $gateway, private BranchAccessService $branches) {}

    /**
     * Branches the Director may pick: active branches they can select.
     *
     * @return Collection<int, Branch>
     */
    public function branchesFor(User $director): Collection
    {
        return $this->branches->availableBranches($director)->where('is_active', true)->sortBy('code')->values();
    }

    /** @param array{question?: mixed, branch?: mixed, date?: mixed} $input */
    public function answer(User $director, array $input): QuickAnswer
    {
        $key = is_string($input['question'] ?? null) && array_key_exists($input['question'], QuickQuestions::QUESTIONS) ? $input['question'] : null;
        if ($key === null) {
            throw ValidationException::withMessages(['question' => 'Choose one of the questions.']);
        }
        $question = QuickQuestions::get($key);
        if ($question['alsoNeeds'] !== null && ! $director->can($question['alsoNeeds'])) {
            throw new AuthorizationException('You may not see this answer.');
        }

        $available = $this->branchesFor($director);
        $branch = null;
        if (is_string($input['branch'] ?? null) && $input['branch'] !== '' && $input['branch'] !== 'all') {
            $branch = $available->firstWhere('code', $input['branch']);
            if ($branch === null) {
                throw ValidationException::withMessages(['branch' => 'Choose a branch you work at, or all branches.']);
            }
        }
        $today = CarbonImmutable::now($branch->timezone ?? InsightsTodayTool::DEFAULT_TIMEZONE)->toDateString();
        $date = $this->date($input['date'] ?? null, $today, $question['date']);

        $run = $this->recorder->start(self::AGENT, self::TASK, $branch, $director, AgentPermissions::ASSISTANT);
        try {
            [$results, $scope] = $this->read($run, $director, $key, $question, $branch, $available, $date, $today);
            $built = QuickQuestions::build($key, $results, $scope);
            $this->recorder->step($run, 'decision', 'Answered: '.$question['label'].'.');
            $this->recorder->complete($run);
        } catch (Throwable $exception) {
            $this->recorder->fail($run, 'quick_answer.failed');

            throw $exception;
        }

        return new QuickAnswer(
            question: $key,
            label: $question['label'],
            sentence: $built['sentence'],
            columns: $built['columns'],
            rows: array_slice($built['rows'], 0, QuickQuestions::MAX_ROWS),
            totalRows: count($built['rows']),
            totals: $built['totals'],
            scope: ['branch' => $scope['branchLabel'], 'date' => $scope['date'] ?? null, 'from' => $scope['from'] ?? null, 'to' => $scope['to'] ?? null],
            runId: $run->id,
        );
    }

    /**
     * @param  array{group: string, label: string, tool: string, date: string, alsoNeeds: string|null}  $question
     * @param  Collection<int, Branch>  $available
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function read(AiAgentRun $run, User $director, string $key, array $question, ?Branch $branch, Collection $available, ?string $date, string $today): array
    {
        $branchLabel = $branch->code ?? 'all branches';
        if (QuickQuestions::perBranchTool($key)) {
            // A `.branch` data source reads one branch at a time: "all branches" means each branch the Director works at.
            $targets = $branch !== null ? collect([$branch]) : $available->filter(fn (Branch $each): bool => $this->branches->hasEffectiveAssignment($director, $each))->values();
            if ($targets->isEmpty()) {
                throw ValidationException::withMessages(['branch' => 'You are not assigned to any branch today.']);
            }
            $results = array_values($targets->map(fn (Branch $each): array => $this->gateway->call($run, $question['tool'], $director, $each))->all());

            return [$results, ['branchLabel' => $branch !== null ? $branchLabel : implode(', ', $targets->pluck('code')->all())]];
        }
        if ($question['date'] === 'week') {
            $week = QuickQuestions::week((string) $date, $today);
            $now = $this->gateway->call($run, $question['tool'], $director, $branch, ['from' => $week['from'], 'to' => $week['to']]);
            $before = $this->gateway->call($run, $question['tool'], $director, $branch, ['from' => $week['previousFrom'], 'to' => $week['previousTo']]);

            return [[$now, $before], ['branchLabel' => $branchLabel, 'from' => $week['from'], 'to' => $week['to']]];
        }
        $input = in_array($question['date'], ['date', 'today'], true) ? ['date' => $date] : [];

        return [[$this->gateway->call($run, $question['tool'], $director, $branch, $input)], ['branchLabel' => $branchLabel, ...($date !== null ? ['date' => $date] : [])]];
    }

    private function date(mixed $value, string $today, string $mode): ?string
    {
        if ($mode === 'none') {
            return null;
        }
        if ($mode === 'today' || $value === null || $value === '') {
            return $today;
        }
        $parsed = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->toDateString() !== $value || $value > $today || $value < '2020-01-01') {
            throw ValidationException::withMessages(['date' => 'Choose a date from 2020 up to today.']);
        }

        return $value;
    }
}
