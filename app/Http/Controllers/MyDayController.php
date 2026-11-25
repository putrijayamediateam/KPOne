<?php

namespace App\Http\Controllers;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\DepartmentSpace\MyDayPage;
use App\Domain\DepartmentSpace\RoleNextThings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** DAY-1a: the person's own first page. Every signed-in human account may open it; the TV account may not. */
final class MyDayController extends Controller
{
    public function show(Request $request, MyDayPage $page): Response
    {
        $user = $request->user();
        abort_if($user->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE), 403);

        return Inertia::render('MyDay/Index', $page->for($user));
    }

    /** Clean Look homes: the live strip and "Your next things", read only, polled by the open page. */
    public function next(Request $request, RoleNextThings $next): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE), 403);

        return response()->json($next->for($user))->header('Cache-Control', 'no-store');
    }
}
