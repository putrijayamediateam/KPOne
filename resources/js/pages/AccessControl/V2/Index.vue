<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronRight, KeyRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Field from '@/components/v2/V2Field.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatRoleLabel } from '@/lib/displayLabels';

/**
 * Version 2 Access Control (Classic is `pages/AccessControl/Index.vue`, untouched). Same props, read only: it shows the
 * roles and the permissions each one holds, and changes nothing. V2 only adds a box that narrows the permission names on
 * screen (nothing is sent anywhere) and lets a role be folded away.
 */
const props = defineProps<{
    roles: Array<{ id: number; name: string; permissions: string[] }>;
    permissions: string[];
}>();
usePageCrumbs(() => [{ title: 'Access Control' }]);

const search = ref('');
const term = computed(() => search.value.trim().toLowerCase());
const shown = computed(() =>
    props.roles
        .map((role) => ({
            ...role,
            matches: term.value
                ? role.permissions.filter((permission) =>
                      permission.toLowerCase().includes(term.value),
                  )
                : role.permissions,
        }))
        .filter((role) => !term.value || role.matches.length > 0),
);
</script>

<template>
    <Head title="Access Control" />
    <PageFrame>
        <V2WorkLayout
            title="Access Control"
            description="Roles group permissions; permission scope remains explicit."
        >
            <template #main>
                <div class="grid gap-4 sm:grid-cols-2">
                    <V2Stat
                        label="Configured roles"
                        :value="String(roles.length)"
                    />
                    <V2Stat
                        label="Explicit permissions"
                        :value="String(permissions.length)"
                    />
                </div>

                <section
                    aria-labelledby="matrix-title"
                    class="grid min-w-0 gap-4 rounded-[14px] border bg-card p-4 sm:p-5"
                >
                    <header>
                        <h2 id="matrix-title" class="text-base font-semibold">
                            Role permission matrix
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Technical administration is intentionally separate
                            from future clinical-content access.
                        </p>
                    </header>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Find a permission"
                        hint="Narrows the permission names shown below. Nothing is saved."
                    >
                        <input
                            :id="id"
                            v-model="search"
                            type="search"
                            autocomplete="off"
                            :aria-describedby="describedBy"
                            class="min-h-11 w-full rounded-[10px] border bg-background px-3 text-sm lg:min-h-9"
                        />
                    </V2Field>
                    <p
                        v-if="term"
                        role="status"
                        class="text-sm text-muted-foreground"
                        data-testid="filter-status"
                    >
                        {{ shown.length }} of {{ roles.length }} roles hold a
                        permission that matches "{{ search.trim() }}".
                    </p>
                    <div class="divide-y">
                        <details
                            v-for="role in shown"
                            :key="role.id"
                            open
                            class="group py-4 first:pt-0 last:pb-0"
                        >
                            <summary
                                class="flex min-h-11 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 rounded-[10px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <ChevronRight
                                    class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90"
                                    aria-hidden="true"
                                />
                                <KeyRound
                                    class="size-4 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <span class="font-medium">{{
                                    formatRoleLabel(role.name)
                                }}</span>
                                <span class="text-xs text-muted-foreground">
                                    {{
                                        term
                                            ? `${role.matches.length} of ${role.permissions.length} permissions`
                                            : `${role.permissions.length} permissions`
                                    }}
                                </span>
                            </summary>
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                <li
                                    v-for="permission in role.matches"
                                    :key="permission"
                                    class="rounded-[8px] border bg-muted/40 px-2 py-1 font-mono text-[11px] break-all"
                                >
                                    {{ permission }}
                                </li>
                            </ul>
                        </details>
                        <p
                            v-if="shown.length === 0"
                            class="py-4 text-sm text-muted-foreground"
                        >
                            No role holds a permission that matches your search.
                        </p>
                    </div>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
