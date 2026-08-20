<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccessControlController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('AccessControl/Index', [
            'roles' => Role::query()->with('permissions:id,name')->orderBy('name')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ]),
            'permissions' => Permission::query()->orderBy('name')->pluck('name'),
        ]);
    }
}
