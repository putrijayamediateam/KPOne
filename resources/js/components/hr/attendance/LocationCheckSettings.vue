<script setup lang="ts">
// HR-16a location check card (Director only). Off by default at every branch and on at one branch at a time. The
// centre is the branch's own point (pre-filled from its public location), never a person's. A change is a new version,
// so staff read the consent text again before their next press.
import { router, usePage } from '@inertiajs/vue3';
import { MapPin } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { Textarea } from '@/components/ui/textarea';
import type { LocationCheckCard } from './types';

const props = defineProps<{ card: LocationCheckCard }>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const onAt = computed(() => props.card.branches.find((b) => b.enabled) ?? null);
const form = reactive({
    branchId: String(onAt.value?.id ?? props.card.branches[0]?.id ?? ''),
    centreLat: '',
    centreLng: '',
    radius: '',
    consentEn: '',
    consentBm: '',
    processing: false,
    confirmOff: false,
    key: crypto.randomUUID(),
});
const branch = computed(
    () =>
        props.card.branches.find((b) => String(b.id) === form.branchId) ?? null,
);
const fill = () => {
    const b = branch.value;
    form.centreLat = b?.centreLat ?? '';
    form.centreLng = b?.centreLng ?? '';
    form.radius = String(b?.radiusMetres ?? props.card.suggestedRadius);
    form.consentEn = b?.consentEn ?? props.card.draftConsentEn;
    form.consentBm = b?.consentBm ?? props.card.draftConsentBm;
};
fill();
watch(() => [form.branchId, props.card], fill);

const branchOptions = computed(() =>
    props.card.branches.map((b) => ({
        value: String(b.id),
        label: b.enabled ? `${b.name} (on)` : b.name,
    })),
);
const blockedBy = computed(() =>
    onAt.value && String(onAt.value.id) !== form.branchId ? onAt.value : null,
);

const put = (enabled: boolean) => {
    form.processing = true;
    router.put(
        '/hr/attendance/location-check',
        {
            branch_id: form.branchId,
            enabled,
            centre_lat: form.centreLat,
            centre_lng: form.centreLng,
            radius_metres: form.radius,
            consent_en: form.consentEn,
            consent_bm: form.consentBm,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                form.key = crypto.randomUUID();
                form.confirmOff = false;
            },
            onFinish: () => (form.processing = false),
        },
    );
};
</script>

<template>
    <section
        aria-labelledby="location-check-title"
        class="flex flex-col gap-4"
        data-test="location-check-settings"
    >
        <h2 id="location-check-title" class="text-base font-semibold">
            Location check at clock-in
        </h2>
        <p class="max-w-prose text-sm text-muted-foreground">
            Off at every branch until you turn it on, and on at one branch at a
            time. When it is on, a press there keeps only inside, outside or not
            available, with how accurate the reading was. Nobody's location is
            stored, and a refusal never stops a clock-in.
        </p>
        <p class="text-sm text-muted-foreground">
            Synthetic data only until the PDPA review. The consent text below is
            a draft for that review with the Data Protection Officer.
        </p>
        <ul
            class="flex flex-col gap-1 text-sm"
            data-test="location-check-status"
        >
            <li
                v-for="b in card.branches"
                :key="b.id"
                class="flex flex-wrap items-center gap-2"
            >
                <span>{{ b.name }}</span>
                <StatusBadge
                    :status="b.enabled ? 'on' : 'off'"
                    :label="
                        b.enabled
                            ? `On since ${b.since}, ${b.radiusMetres} m`
                            : 'Off'
                    "
                    :tone="b.enabled ? 'accent' : 'neutral'"
                />
            </li>
        </ul>
        <form
            class="grid max-w-xl gap-4 rounded-xl border bg-card p-4"
            @submit.prevent="put(true)"
        >
            <div class="grid gap-2">
                <OperationalSelect
                    id="location-check-branch"
                    v-model="form.branchId"
                    :options="branchOptions"
                    label="Branch"
                    placeholder="Choose a branch"
                />
                <InputError :message="errors.branch_id" />
            </div>
            <p
                v-if="blockedBy"
                class="text-sm text-muted-foreground"
                data-test="location-check-blocked"
            >
                It is on at {{ blockedBy.name }}. Turn it off there first: it
                runs at one branch at a time.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="location-check-lat">Branch latitude</Label>
                    <Input
                        id="location-check-lat"
                        v-model="form.centreLat"
                        inputmode="decimal"
                        aria-describedby="location-check-centre-help"
                    />
                    <InputError :message="errors.centre_lat" />
                </div>
                <div class="grid gap-2">
                    <Label for="location-check-lng">Branch longitude</Label>
                    <Input
                        id="location-check-lng"
                        v-model="form.centreLng"
                        inputmode="decimal"
                        aria-describedby="location-check-centre-help"
                    />
                    <InputError :message="errors.centre_lng" />
                </div>
            </div>
            <p
                id="location-check-centre-help"
                class="-mt-2 text-xs text-muted-foreground"
            >
                The branch's own point, filled in from its public location where
                one is set. It is not anyone's location.
            </p>
            <div class="grid gap-2">
                <Label for="location-check-radius">Radius (metres)</Label>
                <Input
                    id="location-check-radius"
                    v-model="form.radius"
                    inputmode="numeric"
                    class="max-w-40"
                    aria-describedby="location-check-radius-help"
                />
                <p
                    id="location-check-radius-help"
                    class="text-xs text-muted-foreground"
                >
                    From 25 to 2,000 m. KPOne suggests
                    {{ card.suggestedRadius }} m; indoor readings can be rough.
                </p>
                <InputError :message="errors.radius_metres" />
            </div>
            <div class="grid gap-2">
                <Label for="location-check-consent-en"
                    >Consent text (English)</Label
                >
                <Textarea
                    id="location-check-consent-en"
                    v-model="form.consentEn"
                    rows="6"
                    maxlength="2000"
                />
                <InputError :message="errors.consent_en" />
            </div>
            <div class="grid gap-2">
                <Label for="location-check-consent-bm"
                    >Consent text (Bahasa Melayu)</Label
                >
                <Textarea
                    id="location-check-consent-bm"
                    v-model="form.consentBm"
                    rows="6"
                    maxlength="2000"
                    lang="ms"
                />
                <InputError :message="errors.consent_bm" />
            </div>
            <p class="text-xs text-muted-foreground">
                Saving a change asks staff to read the consent text again before
                their next press.
            </p>
            <InputError :message="errors.enabled" />
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <Button
                    v-if="branch?.enabled"
                    type="button"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="location-check-off"
                    @click="form.confirmOff = true"
                >
                    Turn off at {{ branch.name }}
                </Button>
                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing || !!blockedBy"
                    data-test="location-check-save"
                >
                    {{
                        form.processing
                            ? 'Saving…'
                            : branch?.enabled
                              ? 'Save changes'
                              : 'Turn on at this branch'
                    }}
                </Button>
            </div>
        </form>
        <OperationalConfirmDialog
            v-model:open="form.confirmOff"
            :title="`Turn off the location check at ${branch?.name ?? 'this branch'}?`"
            description="Presses there stop keeping a location result. Earlier results stay on Review."
            confirm-label="Turn off"
            processing-label="Turning off…"
            :processing="form.processing"
            @confirm="put(false)"
        >
            <template #icon>
                <MapPin class="size-5" />
            </template>
        </OperationalConfirmDialog>
    </section>
</template>
