<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Assistant\DirectorAssistant;
use App\Domain\Agents\Assistant\QuickQuestions;
use App\Domain\Agents\Tools\InsightsTodayTool;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * AG-5: Dato' Arif's quick answers for the Director. The page lists the preset questions; pressing "Answer"
 * reloads it with the question, branch and date in the address, and the answer is computed for that request
 * only (one agent run, nothing stored).
 */
class AgentAssistantController extends Controller
{
    public function index(Request $request, DirectorAssistant $assistant): Response
    {
        $director = $request->user();
        $answer = null;
        $error = null;
        if ($request->filled('question')) {
            try {
                $answer = $assistant->answer($director, $request->only(['question', 'branch', 'date']))->toArray();
            } catch (ValidationException $exception) {
                $error = collect($exception->errors())->flatten()->first();
            } catch (AuthorizationException $exception) {
                $error = $exception->getMessage();
            } catch (LogicException) {
                // The aggregate guard refused a data source result; the run is already marked failed.
                $error = 'This answer was refused because the figures did not pass the privacy check.';
            }
        }

        return Inertia::render('Agents/Assistant', [
            'groups' => collect(QuickQuestions::GROUPS)->map(fn (string $label, string $group): array => [
                'key' => $group,
                'label' => $label,
                'questions' => collect(QuickQuestions::QUESTIONS)->filter(fn (array $q): bool => $q['group'] === $group && ($q['alsoNeeds'] === null || $director->can($q['alsoNeeds'])))
                    ->map(fn (array $q, string $key): array => ['key' => $key, 'label' => $q['label'], 'date' => $q['date']])->values()->all(),
            ])->filter(fn (array $group): bool => $group['questions'] !== [])->values()->all(),
            'branches' => $assistant->branchesFor($director)->map(fn ($branch): array => ['code' => $branch->code, 'name' => $branch->name])->values()->all(),
            'today' => CarbonImmutable::now(InsightsTodayTool::DEFAULT_TIMEZONE)->toDateString(),
            'filters' => [
                'question' => is_string($request->query('question')) ? $request->query('question') : null,
                'branch' => is_string($request->query('branch')) ? $request->query('branch') : 'all',
                'date' => is_string($request->query('date')) ? $request->query('date') : null,
            ],
            'answer' => $answer,
            'error' => $error,
        ]);
    }
}
