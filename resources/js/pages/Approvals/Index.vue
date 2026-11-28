<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState } from '@/components/ui/state';

type Item = { reference: string; age: string; href: string };
type SourceRow = {
    key: string;
    label: string;
    count: number;
    oldest: string | null;
    href: string;
    items: Item[];
};
type Area = { area: string; sources: SourceRow[] };
type Section = { kind: 'approval' | 'task'; areas: Area[] };

const props = defineProps<{
    branch: { id: number; name: string } | null;
    sections: Section[];
    otherBranches: Array<{
        area: string;
        branches: Array<{ name: string; count: number }>;
    }>;
}>();

const heading = (kind: Section['kind']) =>
    kind === 'approval' ? 'Approvals' : 'Tasks';
const total = computed(() =>
    props.sections.reduce(
        (sum, section) =>
            sum +
            section.areas.reduce(
                (inner, area) =>
                    inner + area.sources.reduce((n, row) => n + row.count, 0),
                0,
            ),
        0,
    ),
);
</script>

<template>
    <Head title="Approvals & tasks" />
    <PageFrame>
        <PageHeader
            title="Approvals & tasks"
            :description="`Waiting for you${branch ? ` at ${branch.name}` : ''}. Open an item to decide it on its own page.`"
        />

        <EmptyState
            v-if="sections.length === 0"
            title="Nothing is waiting for you."
            description="New approvals appear here as they are sent."
        />

        <section
            v-for="section in sections"
            :key="section.kind"
            :aria-labelledby="`appr-${section.kind}`"
            class="space-y-4"
        >
            <h2
                :id="`appr-${section.kind}`"
                class="text-[17px] font-semibold text-foreground"
            >
                {{ heading(section.kind) }}
            </h2>
            <div
                v-for="area in section.areas"
                :key="area.area"
                class="space-y-2"
            >
                <h3 class="text-[13px] font-semibold text-muted-foreground">
                    {{ area.area }}
                </h3>
                <ul class="space-y-2">
                    <li
                        v-for="row in area.sources"
                        :key="row.key"
                        class="rounded-[14px] border bg-card p-4 shadow-card sm:p-5"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">
                                    {{ row.label }}
                                </p>
                                <p class="text-[12.5px] text-muted-foreground">
                                    <span
                                        class="font-semibold text-foreground tabular-nums"
                                        >{{ row.count }}</span
                                    >
                                    waiting<template v-if="row.oldest">
                                        · {{ row.oldest }}</template
                                    >
                                </p>
                            </div>
                            <Button variant="outline" as-child
                                ><Link
                                    :href="row.href"
                                    :aria-label="`Open ${row.label}`"
                                    >Open</Link
                                ></Button
                            >
                        </div>
                        <ul
                            v-if="row.items.length"
                            class="mt-3 space-y-1 border-t pt-3"
                        >
                            <li
                                v-for="item in row.items"
                                :key="item.reference"
                                class="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <Link
                                    :href="item.href"
                                    class="inline-flex min-h-11 items-center font-medium text-link underline underline-offset-2 hover:no-underline lg:min-h-9"
                                    >{{ item.reference }}</Link
                                >
                                <span
                                    class="text-[12.5px] text-muted-foreground"
                                    >{{ item.age }}</span
                                >
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </section>

        <section
            v-if="otherBranches.length"
            aria-labelledby="appr-other"
            class="space-y-2"
        >
            <h2
                id="appr-other"
                class="text-[15px] font-semibold text-foreground"
            >
                Waiting at your other branches
            </h2>
            <ul class="space-y-1 text-sm text-muted-foreground">
                <li v-for="line in otherBranches" :key="line.area">
                    {{ line.area }}:
                    <span v-for="(b, i) in line.branches" :key="b.name"
                        >{{ i ? ', ' : '' }}{{ b.name }} {{ b.count }}</span
                    >
                </li>
            </ul>
        </section>
        <p v-if="total > 0" class="sr-only" role="status">
            {{ total }} items waiting.
        </p>
    </PageFrame>
</template>
