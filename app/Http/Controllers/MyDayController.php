<?php

namespace App\Http\Controllers;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\DepartmentSpace\MyDayPage;
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
}
