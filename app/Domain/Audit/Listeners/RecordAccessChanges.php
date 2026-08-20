<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AccessChangeActorContext;
use App\Domain\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RecordAccessChanges
{
    public function __construct(
        private AuditRecorder $audit,
        private AccessChangeActorContext $actors,
    ) {}

    public function roleAttached(RoleAttachedEvent $event): void
    {
        $this->record('access.role.attached', $event->model, 'roles', $event->rolesOrIds, Role::class);
    }

    public function roleDetached(RoleDetachedEvent $event): void
    {
        $this->record('access.role.detached', $event->model, 'roles', $event->rolesOrIds, Role::class);
    }

    public function permissionAttached(PermissionAttachedEvent $event): void
    {
        $this->record('access.permission.attached', $event->model, 'permissions', $event->permissionsOrIds, Permission::class);
    }

    public function permissionDetached(PermissionDetachedEvent $event): void
    {
        $this->record('access.permission.detached', $event->model, 'permissions', $event->permissionsOrIds, Permission::class);
    }

    /** @param class-string<Model> $modelClass */
    private function record(string $event, Model $subject, string $key, mixed $values, string $modelClass): void
    {
        $this->audit->record(
            $event,
            $subject,
            [$key => $this->names($values, $modelClass)],
            $this->actors->current(),
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private function names(mixed $values, string $modelClass): array
    {
        $items = $values instanceof Collection ? $values : collect(Arr::wrap($values));
        $names = [];

        foreach ($items->filter(fn ($item) => $item instanceof Model) as $model) {
            $name = $model->getAttribute('name');

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $identifiers = $items->reject(fn ($item) => $item instanceof Model)->values();

        if ($identifiers->isNotEmpty()) {
            $resolvedNames = $modelClass::query()
                ->whereIn(is_numeric($identifiers->first()) ? 'id' : 'name', $identifiers->all())
                ->pluck('name')
                ->filter(fn ($name) => is_string($name))
                ->all();
            $names = [...$names, ...$resolvedNames];
        }

        return array_values(array_unique($names));
    }
}
