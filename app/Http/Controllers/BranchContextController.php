<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchContextController extends Controller
{
    public function store(
        Request $request,
        BranchAccessService $access,
        AuditRecorder $audit,
    ): RedirectResponse {
        $target = $this->returnPath($request->input('return_to'));
        try {
            $validated = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
            $branch = Branch::query()->whereKey((int) $validated['branch_id'])->firstOrFail();
            $access->select($request->user(), $branch);
            $audit->record('branch_context.changed', $branch, [], $request->user(), $branch);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }

        return redirect()->to($target);
    }

    private function returnPath(mixed $value): string
    {
        // Accept root-relative paths only. Decoding must not introduce an authority,
        // control character, backslash or dot segment; external/malformed input falls home.
        if (! is_string($value) || strlen($value) > 2048) {
            return route('dashboard');
        }
        $decoded = rawurldecode($value);
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || preg_match('/[\\\\\x00-\x20\x7f]/', $decoded)
            || preg_match('~(?:^|/)\.\.?(?:/|[?#]|$)~', $decoded)
            || str_contains($decoded, '%') || parse_url($decoded) === false) {
            return route('dashboard');
        }

        return $value;
    }
}
