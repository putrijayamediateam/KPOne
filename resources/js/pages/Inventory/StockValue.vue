<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';

/**
 * STK-28: the stock value report is built in the new layout only (Daniel, 2026-11-15). With "Try the new layout" off, a
 * Director lands here instead of a blank page: one sentence and one button that turns the new layout on. No figures are
 * shown on this page.
 */
const page = usePage();
const busy = ref(false);
const turnOn = () => {
    busy.value = true;
    router.put(
        '/spaces/preference',
        {
            enabled: true,
            lock_version: page.props.workspace?.spaces?.lockVersion ?? 0,
        },
        {
            preserveScroll: true,
            // The switch lands in the spaces home; come straight back to this report instead.
            onSuccess: () => router.visit('/inventory/stock-value'),
            onFinish: () => (busy.value = false),
        },
    );
};
</script>

<template>
    <Head title="Stock value and cost" />
    <PageFrame>
        <PageHeader
            title="Stock value and cost"
            description="This report is in the new layout. Turn on the new layout to open it."
        />
        <p
            class="text-sm text-muted-foreground"
            data-testid="stock-value-turn-on-note"
        >
            This turns the new layout on for all your pages. You can turn it off
            again with Back to Classic at the top of any page (on a phone, in
            the menu).
        </p>
        <div>
            <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:h-9"
                data-testid="stock-value-turn-on"
                :disabled="busy"
                @click="turnOn"
            >
                Turn on the new layout
            </button>
        </div>
    </PageFrame>
</template>
