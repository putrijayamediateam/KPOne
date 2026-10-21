<?php

namespace App\Domain\Shared\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shared request boundary for explicit, audited branch report runs and downloads. */
abstract class BranchReportService
{
    public const PAGE_SIZE = 20;

    protected function supportsFilters(): bool
    {
        return true;
    }

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function basis(): string;

    /** @param array<string,mixed> $filters
     * @return array{columns:list<string>,rows:list<list<string>>,totals:array<string,string>}
     */
    abstract protected function project(User $actor, Branch $branch, array $filters): array;

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(User $actor, array $input = [], bool $run = false, bool $download = false): array
    {
        return DB::transaction(function () use ($actor, $input, $run, $download): array {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $branch = app(BranchAccessService::class)->activeBranch($actor);
            abort_unless($branch && $branch->is_active && DB::table('organisations')->where('id', $branch->organisation_id)->where('is_active', true)->exists(), 403);
            $view = 'reports.'.$this->key().'.view.branch';
            $export = 'reports.'.$this->key().'.export.branch';
            $actor = app(TransactionalActorAuthority::class)->lock($actor, $branch, $view);
            $grants = $actor->getAllPermissions()->pluck('name');
            abort_unless($grants->contains($view) && (! $download || $grants->contains($export)), 403);
            $methods = ! $this->supportsFilters() ? [] : PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->orderBy('name')->get(['id', 'name'])->map(fn ($m): array => ['value' => (string) $m->id, 'label' => $m->name])->all();
            $yesterday = CarbonImmutable::now($branch->timezone)->subDay()->toDateString();
            $filters = Validator::make($input + ['from' => $yesterday, 'to' => $yesterday, 'page' => 1, 'methods' => [], 'visit_types' => []], [
                'expected_branch_id' => [$run || $download ? 'required' : 'sometimes', 'integer', 'in:'.$branch->id],
                'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'page' => ['required', 'integer', 'min:1'], 'methods' => [$this->supportsFilters() ? 'array' : 'prohibited', 'max:100'], 'methods.*' => ['string', 'distinct', Rule::in(array_column($methods, 'value'))],
                'visit_types' => [$this->supportsFilters() ? 'array' : 'prohibited', 'max:2'], 'visit_types.*' => ['string', 'distinct', Rule::in(['consultation', 'otc'])],
                'branch' => ['prohibited'], 'doctor' => ['prohibited'], 'treatment' => ['prohibited'], 'diagnosis' => ['prohibited'],
            ])->validate();
            if (CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) >= 31) {
                throw ValidationException::withMessages(['to' => 'Choose at most 31 local dates.']);
            }
            $filters['expected_branch_id'] = $branch->id;
            $base = ['title' => $this->title(), 'basis' => $this->basis(), 'endpoint' => '/reports/'.str_replace('_', '-', $this->key()), 'branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $branch->timezone], 'canExport' => $grants->contains($export), 'options' => $this->supportsFilters() ? ['methods' => $methods, 'visit_types' => [['value' => 'consultation', 'label' => 'Consultation'], ['value' => 'otc', 'label' => 'Medicine-only (OTC)']]] : null, 'filters' => $filters, 'result' => null];
            if (! $run && ! $download) {
                return $base;
            }
            $data = $this->project($actor, $branch, $filters);
            $count = count($data['rows']);
            if ($download && $count > ReportCsv::MAX_ROWS) {
                throw ValidationException::withMessages(['export' => 'More than 5,000 rows match. Narrow the filters.']);
            }
            $page = (int) $filters['page'];
            $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
            if (! $download && $page > $pages) {
                throw ValidationException::withMessages(['page' => 'This page is unavailable. Run the report again.']);
            }
            $generated = now()->utc()->toIso8601String();
            $file = null;
            if ($download) {
                $metadata = [['Report', $this->title()], ['Branch', $branch->name], ['Timezone', $branch->timezone], ['From', $filters['from']], ['To', $filters['to']], ['Generated at UTC', $generated], ['Basis', $this->basis()], ['Payment method IDs', implode('; ', $filters['methods']) ?: 'all'], ['Visit types', implode('; ', $filters['visit_types']) ?: 'all'], ['Rows', (string) $count]];
                foreach ($data['totals'] as $label => $value) {
                    $metadata[] = ['Total '.$label, $value];
                }
                $file = ['name' => str_replace('_', '-', $this->key()).'_'.$filters['from'].'_'.$filters['to'].'.csv', 'csv' => ReportCsv::encode($data['columns'], $data['rows'], $metadata)];
            }
            abort_if(app(AuditRecorder::class)->record('reports.'.$this->key().($download ? '.exported' : '.viewed'), null, ['dataset' => $this->key(), 'branch_id' => $branch->id, 'from' => $filters['from'], 'to' => $filters['to'], 'methods' => $filters['methods'], 'visit_types' => $filters['visit_types'], 'page' => $download ? null : $page, 'row_count' => $download ? $count : count(array_slice($data['rows'], ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE))], $actor) === null, 503, 'Unable to audit report access. Try again later.');

            return $download ? $file : [...$base, 'result' => ['generatedAt' => $generated, 'count' => $count, 'page' => $page, 'pages' => $pages, 'columns' => $data['columns'], 'rows' => array_slice($data['rows'], ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), 'totals' => $data['totals']]];
        }, 3);
    }
}
