<?php

namespace App\Http\Controllers;

use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
use App\Domain\DepartmentSpace\SpaceAskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** DS-3a: one preset question in, one fixed answer out. Read only; nothing typed is interpreted. */
final class SpaceAskController extends Controller
{
    public function ask(Request $request, string $space, SpaceAskService $service, DepartmentSpaceNavigation $navigation): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:80']]);
        abort_unless($navigation->canEnter($request->user(), $space), 403);

        return response()->json($service->ask($request->user(), $space, $data['question']));
    }
}
