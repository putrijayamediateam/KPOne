<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import SpacePassThroughLayout from '@/layouts/SpacePassThroughLayout.vue';

type Space = { key: string; title: string };

// The space shell draws its own chrome, so it opts out of the staff layouts without touching the shared layout map.
defineOptions({ layout: SpacePassThroughLayout });

const props = defineProps<{
    space: Space;
    spaces: Space[];
    existing?: { title: string; href: string } | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user!);
const branches = computed(() => page.props.branchContext);
const hub = computed(
    () => page.props.workspace?.navigation.clinicManagement === true,
);

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

const changeBranch = (event: Event) => {
    const branchId = Number((event.target as HTMLSelectElement).value);

    if (branchId) {
        router.post(
            '/branch-context',
            { branch_id: branchId, return_to: page.url },
            { preserveScroll: true },
        );
    }
};

// 390 px: the nav is one scrolling row. Fade the right edge while more is hidden and bring the current item into view.
const side = ref<HTMLElement | null>(null);
const more = ref(false);
const measure = () => {
    const el = side.value;
    more.value = !!el && el.scrollLeft + el.clientWidth < el.scrollWidth - 2;
};
onMounted(async () => {
    await nextTick();
    side.value
        ?.querySelector<HTMLElement>('[aria-current="page"]')
        ?.scrollIntoView({ block: 'nearest', inline: 'center' });
    measure();
});
</script>

<template>
    <Head :title="space.title" />
    <div class="space-shell">
        <div
            ref="side"
            class="space-side"
            :class="{ 'has-more': more }"
            @scroll.passive="measure"
        >
            <Link href="/" class="space-logo"
                ><span class="space-logo-mark" aria-hidden="true">K</span
                >KPOne</Link
            >
            <nav aria-label="Spaces" class="space-nav">
                <div v-for="g in groups" :key="g.title" class="space-block">
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
                </div>
                <div v-if="hub" class="space-block">
                    <div class="space-group">More</div>
                    <Link href="/clinic-management" class="space-item"
                        >Clinic management</Link
                    >
                </div>
            </nav>
        </div>
        <main id="main-content" class="space-main" tabindex="-1">
            <div class="space-top">
                <label
                    v-if="branches?.active"
                    class="space-branch"
                    for="space-branch"
                >
                    <span class="space-muted">Branch</span>
                    <select
                        id="space-branch"
                        class="space-select"
                        :value="branches.active.id"
                        @change="changeBranch"
                    >
                        <option
                            v-for="b in branches.available"
                            :key="b.id"
                            :value="b.id"
                        >
                            {{ b.name }}
                        </option>
                    </select>
                </label>
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <button
                            type="button"
                            class="space-account"
                            aria-label="Account menu"
                        >
                            <UserInfo :user="user" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="w-56 max-sm:[&_[role=menuitem]]:min-h-11"
                    >
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <h1 class="space-title">{{ space.title }}</h1>
            <section class="space-card" aria-labelledby="work-now">
                <h2 id="work-now">Work now</h2>
                <p class="space-muted">
                    This space is being built. Your existing screens are
                    unchanged.
                </p>
                <p v-if="existing" class="space-existing">
                    <Link :href="existing.href"
                        >Open the existing {{ existing.title }} screens</Link
                    >
                </p>
            </section>
            <section class="space-card space-recent" aria-labelledby="recent">
                <h2 id="recent">Recent activity</h2>
                <p class="space-muted">Recent activity will appear here.</p>
            </section>
        </main>
    </div>
</template>
