<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\ClinicInsightsService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClinicInsightsController extends Controller
{
    public function today(Request $request, ClinicInsightsService $insights): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $filters = $request->validate([
            'branch' => ['sometimes', 'string', 'max:40'],
        ]);
        $branch = $filters['branch'] ?? 'all';

        return Inertia::render('Insights/Today', [
            'branch' => $branch,
            'branches' => $insights->todayBranches($actor),
            'report' => $insights->today($actor, $branch),
        ]);
    }

    public function report(Request $request, ClinicInsightsReportService $insights, string $section): Response
    {
        abort_unless(in_array($section, ['sales', 'in-clinic', 'payments', 'inventory', 'patients'], true), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $filters = $request->validate([
            'branch' => ['sometimes', 'string', 'max:40'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'doctor' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $defaultDate = CarbonImmutable::now('Asia/Kuala_Lumpur')->subDay()->toDateString();
        $from = $filters['from'] ?? $defaultDate;
        $to = $filters['to'] ?? $defaultDate;
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        return Inertia::render('Insights/Report', [
            'filters' => [
                'section' => $section,
                'branch' => $filters['branch'] ?? 'all',
                'from' => $from,
                'to' => $to,
                'doctor' => isset($filters['doctor']) ? (int) $filters['doctor'] : null,
            ],
            'branches' => $insights->branches($actor),
            'doctors' => $insights->doctors($actor),
            'data' => $insights->report(
                $actor,
                $section,
                $filters['branch'] ?? 'all',
                $from,
                $to,
                isset($filters['doctor']) ? (int) $filters['doctor'] : null,
            ),
        ]);
    }
}
