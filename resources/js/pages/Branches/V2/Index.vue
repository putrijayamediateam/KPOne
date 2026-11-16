<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock3, MapPin } from '@lucide/vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Branches (Classic is `pages/Branches/Index.vue`, untouched). Same props and the same link to each branch;
 * read only. The branch you are working in says "Current branch" in words, not only with a coloured border.
 */
defineProps<{
    branches: Array<{
        id: number;
        code: string;
        name: string;
        timezone: string;
        isActive: boolean;
    }>;
    selectedBranchId: number | null;
}>();
usePageCrumbs(() => [{ title: 'Branches' }]);
</script>

<template>
    <Head title="Branches" />
    <PageFrame>
        <V2WorkLayout
            title="Branches"
            description="Locations available within your current permission scope."
        >
            <template #main>
                <div class="grid min-w-0 gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <section
                        v-for="branch in branches"
                        :key="branch.id"
                        :aria-label="`${branch.name} branch`"
                        class="flex min-w-0 flex-col gap-4 rounded-[14px] border bg-card p-4 sm:p-5"
                        :class="
                            selectedBranchId === branch.id
                                ? 'border-primary ring-2 ring-primary/10'
                                : ''
                        "
                    >
                        <header
                            class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2"
                        >
                            <div class="min-w-[11rem] flex-1">
                                <h2 class="text-base font-semibold break-words">
                                    {{ branch.name }}
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ branch.code }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <V2Chip
                                    v-if="selectedBranchId === branch.id"
                                    tone="info"
                                    label="Current branch"
                                />
                                <V2Chip
                                    :tone="
                                        branch.isActive ? 'success' : 'danger'
                                    "
                                    :label="
                                        branch.isActive ? 'Active' : 'Inactive'
                                    "
                                />
                            </div>
                        </header>
                        <div class="grid gap-2 text-sm text-muted-foreground">
                            <p class="flex items-center gap-2">
                                <MapPin class="size-4" aria-hidden="true" />
                                Klinik Putrijaya branch
                            </p>
                            <p class="flex items-center gap-2">
                                <Clock3 class="size-4" aria-hidden="true" />
                                {{ branch.timezone }}
                            </p>
                        </div>
                        <div>
                            <Link
                                :href="`/branches/${branch.id}`"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:w-full lg:h-9"
                                :aria-label="`View authorised branch ${branch.name}`"
                                >View authorised branch →</Link
                            >
                        </div>
                    </section>
                    <p
                        v-if="branches.length === 0"
                        class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground md:col-span-2 2xl:col-span-3"
                    >
                        No branches are available within your permission scope.
                    </p>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
