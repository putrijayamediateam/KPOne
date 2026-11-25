<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { JsonRequestError, requestJson } from '@/lib/json-client';

// Q-16c (owner decisions 25 Nov 2026): the details of one board row, as a right-hand side panel from 1280 px and a full-screen sheet below. Read only. It is offered only where the board already shows
// the viewer the triage zone (CA, CA Supervisor, Medical Assistant and the patient's own doctor), and the server checks that again
// and records each open. Age (never the date of birth), allergies, last BP, last visit, who pays, the reason and the triage readings.
type Details = {
    visitNumber: string;
    ageYears: number | null;
    sex: string;
    allergyStatus: string;
    allergies: string[];
    lastBp: { systolic: number; diastolic: number; on: string } | null;
    lastVisit: { visitNumber: string; on: string | null } | null;
    coverageLabel: string;
    memberReference: string | null;
    reason: string | null;
    triage: {
        zone: string | null;
        recordedAt: string;
        readings: Record<string, string | null>;
    } | null;
};

const props = defineProps<{ visitNumber: string | null }>();
const emit = defineEmits<{ close: [] }>();
const details = ref<Details | null>(null);
let lastVisit: string | null = null;
const loading = ref(false);
const error = ref('');

watch(
    () => props.visitNumber,
    async (visitNumber) => {
        details.value = null;
        error.value = '';
        lastVisit = visitNumber ?? lastVisit;

        if (!visitNumber) {
            return;
        }

        loading.value = true;

        try {
            const body = await requestJson<{ details: Details }>(
                `/visits/${encodeURIComponent(visitNumber)}/queue/details`,
            );

            if (props.visitNumber === visitNumber) {
                details.value = body.details;
            }
        } catch (e) {
            error.value =
                e instanceof JsonRequestError && e.status === 403
                    ? 'You may not open the details of this patient.'
                    : 'The details could not be loaded. Try again.';
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

// Escape or Close returns focus to the row's menu button (the menu item that opened this is already gone).
const restoreFocus = (event: Event) => {
    event.preventDefault();
    document
        .querySelector<HTMLElement>(
            `[data-row-menu="${CSS.escape(lastVisit ?? '')}"]`,
        )
        ?.focus();
};
const allergyText = (d: Details) =>
    d.allergies.length > 0
        ? d.allergies.join(', ')
        : d.allergyStatus === 'no_known_allergies'
          ? 'None known'
          : 'Not yet reviewed';
const reading = (d: Details, key: string) => d.triage?.readings[key] ?? '–';
</script>

<template>
    <Sheet
        :open="visitNumber !== null"
        @update:open="(v) => !v && emit('close')"
    >
        <SheetContent
            data-testid="queue-row-details"
            side="right"
            @close-auto-focus="restoreFocus"
            class="w-full gap-3 p-4 sm:max-w-none xl:w-[28rem] xl:max-w-[28rem]"
        >
            <SheetHeader>
                <SheetTitle>Patient details</SheetTitle>
                <SheetDescription>
                    Read only. Opening this is recorded.
                </SheetDescription>
            </SheetHeader>
            <div
                v-if="loading"
                class="flex items-center gap-2 px-4 py-4 text-sm"
            >
                <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                Loading…
            </div>
            <p
                v-else-if="error"
                role="alert"
                class="px-4 text-sm text-destructive"
            >
                {{ error }}
            </p>
            <dl
                v-else-if="details"
                class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2 px-4 text-sm"
            >
                <dt class="text-muted-foreground">Age, sex</dt>
                <dd>
                    {{
                        details.ageYears === null
                            ? 'Age unknown'
                            : details.ageYears
                    }}
                    · {{ details.sex }}
                </dd>
                <dt class="text-muted-foreground">Allergies</dt>
                <dd data-testid="details-allergies">
                    {{ allergyText(details) }}
                </dd>
                <dt class="text-muted-foreground">Last BP</dt>
                <dd>
                    {{
                        details.lastBp
                            ? `${details.lastBp.systolic}/${details.lastBp.diastolic} on ${details.lastBp.on}`
                            : 'None recorded'
                    }}
                </dd>
                <dt class="text-muted-foreground">Last visit</dt>
                <dd>
                    {{
                        details.lastVisit
                            ? `${details.lastVisit.visitNumber}${details.lastVisit.on ? ` on ${details.lastVisit.on}` : ''}`
                            : 'No earlier visit'
                    }}
                </dd>
                <dt class="text-muted-foreground">Who pays</dt>
                <dd>
                    {{ details.coverageLabel }}
                    <span v-if="details.memberReference">
                        · {{ details.memberReference }}
                    </span>
                </dd>
                <dt class="text-muted-foreground">Reason</dt>
                <dd>{{ details.reason ?? 'None given' }}</dd>
                <dt class="text-muted-foreground">Vitals (triage)</dt>
                <dd v-if="details.triage" data-testid="details-triage">
                    BP {{ reading(details, 'systolic_bp') }}/{{
                        reading(details, 'diastolic_bp')
                    }}
                    · pulse {{ reading(details, 'pulse_bpm') }} · temp
                    {{ reading(details, 'temperature_celsius') }} · SpO₂
                    {{ reading(details, 'spo2_percent') }}
                    <span class="block text-xs text-muted-foreground">
                        Recorded {{ details.triage.recordedAt }}
                    </span>
                </dd>
                <dd v-else>No triage yet</dd>
            </dl>
            <SheetFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    @click="emit('close')"
                >
                    Close
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
