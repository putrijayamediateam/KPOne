<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { PageHeader } from '@/components/ui/page-header';

/**
 * DSP-23 (owner answer 12 = A): the branch's "doctor checks prepared medicine" switch. The Director and the branch's
 * CA Supervisor turn it on or off; each change is recorded. Daniel's note 8: a 44 px target with the word On or Off
 * beside it and one sentence saying what it changes; it saves with a confirm toast.
 */
const props = defineProps<{
    branch: { id: number; name: string };
    settings: { doctorCheckRequired: boolean; lockVersion: number };
    settingsChange?: { changedAt: string; changedBy: string } | null;
    waitingCasesUrl?: string | null;
}>();

const busy = ref(false);
const errors = ref<Record<string, string>>({});
const on = computed(() => props.settings.doctorCheckRequired);

const toggle = () => {
    busy.value = true;
    errors.value = {};
    router.put(
        '/dispensary-settings',
        {
            expected_branch_id: props.branch.id,
            doctor_check_required: !on.value,
            lock_version: props.settings.lockVersion,
            retry_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onError: (failed) => (errors.value = failed),
            onFinish: () => (busy.value = false),
        },
    );
};
</script>

<template>
    <Head title="Dispensary settings" />
    <main
        class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Dispensary settings"
            :description="`${branch.name} · These settings apply to this branch only.`"
        />
        <section
            class="rounded-2xl border border-border bg-card p-5 text-sm shadow-sm"
            data-testid="doctor-check-setting"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="min-w-0">
                    <h2 id="doctor-check-label" class="text-base font-semibold">
                        Doctor checks prepared medicine before payment
                    </h2>
                    <p
                        id="doctor-check-help"
                        class="mt-2 leading-6 text-muted-foreground"
                    >
                        When on, the CA sends each consultation case to the
                        attending doctor, who verifies it before the visit goes
                        to billing. Stock is taken out when the doctor verifies.
                        OTC visits are not affected.
                    </p>
                    <InputError
                        :message="
                            errors.doctor_check_required ??
                            errors.expected_branch_id
                        "
                    />
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="on"
                        aria-labelledby="doctor-check-label"
                        aria-describedby="doctor-check-help"
                        :disabled="busy"
                        class="relative inline-flex h-11 w-[4.5rem] shrink-0 items-center rounded-full border-2 border-transparent p-1.5 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                        :class="on ? 'bg-brand' : 'bg-muted-foreground/40'"
                        data-testid="doctor-check-switch"
                        @click="toggle"
                    >
                        <span
                            class="block size-7 rounded-full bg-background shadow transition-transform"
                            :class="on ? 'translate-x-7' : 'translate-x-0'"
                            aria-hidden="true"
                        />
                    </button>
                    <span class="w-7 font-medium" aria-hidden="true">{{
                        on ? 'On' : 'Off'
                    }}</span>
                </div>
            </div>
            <dl
                class="mt-4 flex flex-wrap items-start justify-between gap-2 border-t border-border pt-4"
            >
                <dt class="text-muted-foreground">Last changed</dt>
                <dd class="min-w-0 break-words text-foreground">
                    <template v-if="settingsChange">
                        {{ settingsChange.changedAt }} by
                        {{ settingsChange.changedBy }}
                    </template>
                    <template v-else>Not available</template>
                </dd>
            </dl>
        </section>
        <section
            aria-labelledby="settings-access-heading"
            class="rounded-2xl border border-border bg-muted/30 p-5 text-sm"
        >
            <h2 id="settings-access-heading" class="font-semibold">
                About this setting
            </h2>
            <p class="mt-2 leading-6 text-muted-foreground">
                Only staff authorized to manage this branch's Dispensary
                settings can change it. Changes are recorded.
            </p>
            <p class="mt-2 leading-6 text-muted-foreground">
                Cases already waiting for the doctor's verification stay waiting
                if this setting is turned off.
            </p>
        </section>
        <Link
            v-if="waitingCasesUrl"
            :href="waitingCasesUrl"
            class="inline-flex min-h-11 w-fit max-w-full items-center rounded-full border border-border bg-card px-4 py-2 text-left text-sm font-medium text-foreground outline-offset-2 hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring lg:min-h-9"
            data-testid="settings-waiting-cases"
        >
            See cases waiting for the doctor's check
        </Link>
    </main>
</template>
