<script setup lang="ts">
// HR-13: shown after every application, and to robots too. It shows nothing that was sent. HR-17b: after a real
// application it also shows the private link once (HR-PLAN 19.5); it is not shown again on a reload.
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{ clinicName: string; statusLink?: string | null }>();

const copied = ref(false);
const copyFailed = ref(false);
const linkBox = ref<HTMLInputElement | null>(null);
const copy = async () => {
    if (!props.statusLink) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.statusLink);
        copied.value = true;
        copyFailed.value = false;
    } catch {
        copied.value = false;
        copyFailed.value = true;
        // Some browsers refuse the clipboard: select the link so it can be copied by hand.
        linkBox.value?.select();
    }
};
</script>

<template>
    <Head title="Application sent"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto min-h-svh max-w-xl px-4 py-5 sm:px-6 sm:py-8">
        <section
            class="overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-card)]"
        >
            <div class="h-1.5 bg-brand" aria-hidden="true"></div>
            <div class="space-y-4 p-5 sm:p-8" data-test="apply-thanks">
                <p class="text-sm font-semibold text-brand-strong">
                    {{ clinicName }}
                </p>
                <h1 class="text-xl font-semibold">Thank you for applying</h1>
                <p class="text-sm">
                    We have your application. If you are shortlisted, the
                    clinic's HR team will contact you by phone or email.
                </p>

                <div
                    v-if="statusLink"
                    class="space-y-3 rounded-xl border border-border bg-card-quiet p-4"
                    data-test="apply-status-link"
                >
                    <h2 class="text-sm font-semibold">Your private link</h2>
                    <p class="text-sm">
                        Save this link to follow your application. It is shown
                        only now. Anyone with the link can see your application,
                        so keep it private.
                    </p>

                    <label for="status-link" class="sr-only"
                        >Your private link</label
                    >
                    <input
                        id="status-link"
                        ref="linkBox"
                        :value="statusLink"
                        readonly
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 font-mono text-xs"
                        @focus="linkBox?.select()"
                    />
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            data-test="apply-status-copy"
                            @click="copy"
                        >
                            Copy link
                        </button>
                        <a
                            :href="statusLink"
                            class="inline-flex min-h-11 items-center text-sm font-medium text-brand-strong underline underline-offset-4"
                            >Open it now</a
                        >
                        <span
                            v-if="copied"
                            role="status"
                            class="text-sm text-muted-foreground"
                            >Copied.</span
                        >
                        <span
                            v-if="copyFailed"
                            role="status"
                            class="text-sm text-muted-foreground"
                            data-test="apply-status-copy-failed"
                            >Your browser did not copy it. Select the link above
                            and copy it.</span
                        >
                    </div>
                </div>
                <a
                    href="/careers/apply"
                    class="inline-flex min-h-11 items-center text-sm font-medium text-brand-strong underline underline-offset-4"
                    >Apply for another position</a
                >
            </div>
        </section>
    </main>
</template>
