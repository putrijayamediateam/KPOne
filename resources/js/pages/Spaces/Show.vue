<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SpacePassThroughLayout from '@/layouts/SpacePassThroughLayout.vue';

type Space = { key: string; title: string };
type Pharmacy = {
    branchName: string | null;
    toDispense: {
        total: number;
        rows: Array<{
            visitNumber: string;
            patientName: string;
            visitType: string;
            status: string;
            minutes: number;
            medicineCount: number;
            href: string;
        }>;
    } | null;
    returnsToCheck: { total: number } | null;
    links: Array<{ title: string; href: string }>;
};

// The space shell draws its own chrome, so it opts out of the staff layouts without touching the shared layout map.
defineOptions({ layout: SpacePassThroughLayout });

const props = defineProps<{
    space: Space;
    spaces: Space[];
    pharmacy?: Pharmacy | null;
}>();

const groups = computed(() => {
    const layout: Array<[string, string[]]> = [
        ['Clinic', ['front_desk', 'pharmacy', 'consultation']],
        ['Money', ['billing', 'panel']],
        ['Operations', ['stock', 'hr', 'management']],
        ['You', ['me']],
    ];

    return layout
        .map(([title, keys]) => ({
            title,
            items: props.spaces.filter((s) => keys.includes(s.key)),
        }))
        .filter((g) => g.items.length > 0);
});
</script>

<template>
    <Head :title="space.title" />
    <div class="space-shell">
        <aside class="space-side" aria-label="Spaces">
            <Link href="/" class="space-logo"
                ><span class="space-logo-mark" aria-hidden="true">K</span
                >KPOne</Link
            >
            <nav v-for="g in groups" :key="g.title" :aria-label="g.title">
                <div class="space-group">{{ g.title }}</div>
                <Link
                    v-for="s in g.items"
                    :key="s.key"
                    :href="`/spaces/${s.key}`"
                    class="space-item"
                    :class="{ 'is-on': s.key === space.key }"
                    :aria-current="s.key === space.key ? 'page' : undefined"
                >
                    {{ s.title }}
                </Link>
            </nav>
        </aside>
        <main id="main-content" class="space-main" tabindex="-1">
            <h1 class="space-title">{{ space.title }}</h1>
            <template v-if="pharmacy">
                <p v-if="pharmacy.branchName" class="space-muted">
                    {{ pharmacy.branchName }}
                </p>
                <section
                    v-if="pharmacy.toDispense"
                    class="space-card"
                    aria-labelledby="to-dispense"
                >
                    <h2 id="to-dispense">
                        To dispense ({{ pharmacy.toDispense.total }})
                    </h2>
                    <p
                        v-if="pharmacy.toDispense.rows.length === 0"
                        class="space-muted"
                    >
                        Nothing is waiting at Dispensary.
                    </p>
                    <ul v-else class="space-list">
                        <li
                            v-for="row in pharmacy.toDispense.rows"
                            :key="row.visitNumber"
                        >
                            <Link :href="row.href" class="space-row">
                                <span class="space-row-main">
                                    {{ row.patientName }}
                                    <span class="space-muted">{{
                                        row.visitNumber
                                    }}</span>
                                </span>
                                <span class="space-muted">
                                    {{ row.medicineCount }} medicine(s),
                                    {{ row.minutes }} min
                                </span>
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="pharmacy.returnsToCheck"
                    class="space-card"
                    aria-labelledby="returns"
                >
                    <h2 id="returns">
                        Returns to check ({{ pharmacy.returnsToCheck.total }})
                    </h2>
                </section>
                <section
                    v-if="pharmacy.links.length"
                    class="space-card"
                    aria-labelledby="open-pages"
                >
                    <h2 id="open-pages">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in pharmacy.links" :key="l.href">
                            <Link :href="l.href" class="space-row">{{
                                l.title
                            }}</Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="!pharmacy.toDispense && !pharmacy.returnsToCheck"
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                </section>
            </template>
            <section v-else class="space-card" aria-labelledby="work-now">
                <h2 id="work-now">Work now</h2>
                <p class="space-muted">
                    This space is being built. Your existing screens are
                    unchanged.
                </p>
            </section>
            <section class="space-card space-recent" aria-labelledby="recent">
                <h2 id="recent">Recent activity</h2>
                <p class="space-muted">Recent activity will appear here.</p>
            </section>
        </main>
    </div>
</template>
