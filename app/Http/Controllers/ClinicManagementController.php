<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\DepartmentSpace\UiVersionResolver;
use App\Domain\DepartmentSpace\V2ShellNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ClinicManagementController extends Controller
{
    public function __invoke(Request $request, ClinicManagementNavigation $navigation, UiVersionResolver $ui): Response
    {
        $groups = $navigation->groups($request->user());
        abort_if($groups === [], 403);

        // UX-21: the Version 2 hub reads the same sentence-case labels as the V2 sidebar. Classic keeps its own titles.
        if ($ui->pageFor('ClinicManagement/Index') !== 'ClinicManagement/Index') {
            foreach ($groups as &$group) {
                foreach ($group['links'] as &$link) {
                    $link['title'] = V2ShellNavigation::label($link['title']);
                }
                unset($link);
            }
            unset($group);
        }

        return Inertia::render('ClinicManagement/Index', ['groups' => $groups]);
    }
}
