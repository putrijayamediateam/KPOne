<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';

const props = defineProps<{
    active: 'collect' | 'close';
    about: string;
    guidance: string;
}>();
const page = usePage();
const links = computed(() => {
    const can = (permission: string) =>
        page.props.auth.permissions.includes(permission);
    const closing =
        can('payments.reconcile.branch') ||
        can('payments.cash_closing.view.branch');

    return [
        {
            key: 'collect',
            label: 'Bills to collect',
            href: '/pay-later-collections',
            visible: can('outstanding.view.branch'),
        },
        {
            key: 'close',
            label: 'Cash closing',
            href: '/cash-closings',
            visible: closing,
        },
        {
            key: 'month',
            label: 'Cash closing month',
            href: '/cash-closings/month',
            visible: closing,
        },
        {
            key: 'terminal',
            label: 'Terminal check',
            href: '/payment-reconciliations',
            visible: can('payments.reconcile.branch'),
        },
    ].filter((link) => link.visible);
});
</script>

<template>
    <PageFrame width="wide">
        <main class="min-w-0 space-y-6" aria-label="Billing">
            <slot name="header" />
            <p
                class="w-fit rounded-full bg-muted px-4 py-2 text-sm font-semibold"
            >
                Collect and close
            </p>
            <nav
                aria-label="Billing pages"
                class="flex flex-wrap gap-x-2 border-b"
            >
                <Link
                    v-for="link in links"
                    :key="link.key"
                    :href="link.href"
                    :aria-current="
                        link.key === props.active ? 'page' : undefined
                    "
                    class="inline-flex min-h-11 items-center border-b-2 px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring lg:min-h-9"
                    :class="
                        link.key === props.active
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    >{{ link.label }}</Link
                >
            </nav>
            <div
                class="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_260px]"
            >
                <section class="min-w-0 space-y-4" aria-label="Billing work">
                    <slot />
                </section>
                <aside class="min-w-0 space-y-3" aria-label="Billing guidance">
                    <details open class="rounded-xl border bg-card px-4">
                        <summary
                            class="min-h-11 cursor-pointer py-3 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            About this tab
                        </summary>
                        <p
                            class="pb-4 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ about }}
                        </p>
                    </details>
                    <section
                        v-if="$slots.context"
                        class="rounded-xl border bg-card p-4"
                        aria-label="Current view"
                    >
                        <h2 class="mb-3 text-sm font-semibold">At a glance</h2>
                        <slot name="context" />
                    </section>
                    <details class="rounded-xl border bg-card px-4">
                        <summary
                            class="min-h-11 cursor-pointer py-3 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            Good to know
                        </summary>
                        <p
                            class="pb-4 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ guidance }}
                        </p>
                    </details>
                </aside>
            </div>
        </main>
    </PageFrame>
</template>
