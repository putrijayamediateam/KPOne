<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClinicManagementNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ClinicManagementController extends Controller
{
    public function __invoke(Request $request, ClinicManagementNavigation $navigation): Response
    {
        $groups = $navigation->groups($request->user());
        abort_if($groups === [], 403);

        return Inertia::render('ClinicManagement/Index', ['groups' => $groups]);
    }
}
