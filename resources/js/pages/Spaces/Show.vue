<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SpacePassThroughLayout from '@/layouts/SpacePassThroughLayout.vue';

type Space = { key: string; title: string };

// The space shell draws its own chrome, so it opts out of the staff layouts without touching the shared layout map.
defineOptions({ layout: SpacePassThroughLayout });

const props = defineProps<{ space: Space; spaces: Space[] }>();

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
            <section class="space-card" aria-labelledby="work-now">
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
