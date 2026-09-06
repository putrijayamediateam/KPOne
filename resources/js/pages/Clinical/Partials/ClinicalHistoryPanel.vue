<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type {
    ClinicalHistoryDetailPage,
    ClinicalHistorySummary,
} from '@/types';

defineProps<{ history: ClinicalHistorySummary[] }>();
const selected = ref<ClinicalHistoryDetailPage | null>(null);
const selectedUrl = ref<string | null>(null);
const loadingUrl = ref<string | null>(null);
const error = ref('');
let controller: AbortController | null = null;

const display = (value: number | string | null, suffix = '') =>
    value === null ? 'Not recorded' : `${value}${suffix}`;
const load = async (item: ClinicalHistorySummary) => {
    controller?.abort();
    const request = new AbortController();
    controller = request;
    loadingUrl.value = item.viewUrl;
    error.value = '';

    try {
        const response = await fetch(item.viewUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            signal: request.signal,
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Historical Encounter unavailable.');
        }

        selected.value = (await response.json()) as ClinicalHistoryDetailPage;
        selectedUrl.value = item.viewUrl;
    } catch (caught) {
        if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
            selected.value = null;
            selectedUrl.value = null;
            error.value = 'This previous consultation is unavailable.';
        }
    } finally {
        if (controller === request) {
            controller = null;
            loadingUrl.value = null;
        }
    }
};
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <div class="flex min-h-0 flex-col lg:max-h-[calc(100vh-5rem)]">
        <div class="flex items-center justify-between border-b pb-2">
            <div>
                <h2 class="font-semibold">Patient History</h2>
                <p class="text-xs text-muted-foreground">
                    Read-only previous consultations
                </p>
            </div>
            <Button
                v-if="selected"
                type="button"
                size="sm"
                variant="ghost"
                @click="
                    selected = null;
                    selectedUrl = null;
                "
            >
                <ArrowLeft class="size-4" /> Recent visits
            </Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto py-3 lg:pr-1">
            <p
                v-if="error"
                role="alert"
                class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
            >
                {{ error }}
            </p>

            <template v-if="!selected">
                <div v-if="history.length" class="divide-y border-y">
                    <div
                        v-for="item in history"
                        :key="item.viewUrl"
                        class="flex items-center gap-3 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">
                                {{
                                    new Date(
                                        item.startedAt,
                                    ).toLocaleDateString()
                                }}
                            </div>
                            <div class="truncate text-xs text-muted-foreground">
                                {{ item.branch }} ·
                                {{ item.attendingClinician }}
                            </div>
                            <div
                                v-if="item.visitReason"
                                class="truncate text-xs text-muted-foreground"
                            >
                                Visit Reason: {{ item.visitReason }}
                            </div>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="loadingUrl !== null"
                            @click="load(item)"
                        >
                            <LoaderCircle
                                v-if="loadingUrl === item.viewUrl"
                                class="size-4 animate-spin"
                            />
                            View
                        </Button>
                    </div>
                </div>
                <p
                    v-else
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    No previous Encounters are available for this Patient.
                </p>
            </template>

            <article v-else class="space-y-4 text-sm">
                <header class="border-b pb-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <p class="font-semibold">Previous Consultation</p>
                            <p class="text-xs text-muted-foreground">
                                Read only
                            </p>
                        </div>
                        <Link
                            v-if="selectedUrl"
                            :href="selectedUrl"
                            class="text-xs font-medium text-emerald-800 hover:underline"
                            >Open full page</Link
                        >
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground">Date</dt>
                            <dd>
                                {{
                                    new Date(
                                        selected.encounter.startedAt,
                                    ).toLocaleString()
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Branch</dt>
                            <dd>{{ selected.branch.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Clinician</dt>
                            <dd>{{ selected.encounter.attendingClinician }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Visit</dt>
                            <dd class="font-mono">
                                {{ selected.visit.visitNumber }}
                            </dd>
                        </div>
                    </dl>
                </header>

                <section>
                    <h3
                        class="mb-2 text-xs font-semibold tracking-wide uppercase"
                    >
                        Vitals
                    </h3>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground">
                                Blood pressure
                            </dt>
                            <dd>
                                {{
                                    selected.vitals.systolicBp !== null &&
                                    selected.vitals.diastolicBp !== null
                                        ? `${selected.vitals.systolicBp} / ${selected.vitals.diastolicBp} mmHg`
                                        : 'Not recorded'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Pulse</dt>
                            <dd>
                                {{ display(selected.vitals.pulseBpm, ' bpm') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Temperature</dt>
                            <dd>
                                {{
                                    display(
                                        selected.vitals.temperatureCelsius,
                                        ' °C',
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">SpO₂</dt>
                            <dd>
                                {{ display(selected.vitals.spo2Percent, '%') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Weight</dt>
                            <dd>
                                {{ display(selected.vitals.weightKg, ' kg') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Height / BMI</dt>
                            <dd>
                                {{ display(selected.vitals.heightCm, ' cm') }} ·
                                {{ display(selected.vitals.bmi) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section>
                    <h3
                        class="mb-2 text-xs font-semibold tracking-wide uppercase"
                    >
                        Clinical Note
                    </h3>
                    <p
                        class="rounded-md bg-muted/25 p-3 leading-relaxed whitespace-pre-wrap"
                    >
                        {{
                            selected.encounter.clinicalNote ??
                            'No clinical note recorded.'
                        }}
                    </p>
                </section>

                <section>
                    <h3
                        class="mb-2 text-xs font-semibold tracking-wide uppercase"
                    >
                        Diagnoses
                    </h3>
                    <ol
                        v-if="selected.diagnoses.length"
                        class="divide-y border-y"
                    >
                        <li
                            v-for="(diagnosis, index) in selected.diagnoses"
                            :key="`${index}-${diagnosis.diagnosisText}`"
                            class="py-2"
                        >
                            <div class="font-medium">
                                {{ index + 1 }}. {{ diagnosis.diagnosisText }}
                                <span
                                    v-if="diagnosis.isPrimary"
                                    class="text-[11px] text-emerald-800"
                                    >Primary</span
                                >
                            </div>
                            <div
                                v-if="
                                    diagnosis.diagnosisCode &&
                                    diagnosis.codeSystem
                                "
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ diagnosis.codeSystem }} ·
                                {{ diagnosis.diagnosisCode }}
                            </div>
                        </li>
                    </ol>
                    <p v-else class="text-muted-foreground">
                        No diagnosis recorded.
                    </p>
                </section>
            </article>
        </div>
    </div>
</template>
