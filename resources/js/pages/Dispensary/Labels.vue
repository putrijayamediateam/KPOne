<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    labels: {
        clinic: string;
        branch: string;
        patient: { name: string; patientNumber: string };
        date: string;
        items: Array<{
            name: string;
            lines: string[];
            emphasis: number[];
            previewUrl: string;
        }>;
        blocked: Array<{ name: string; errors: string[] }>;
        logo: string | null;
        token: string;
        branchId: number;
        excludedCount: number;
        pageSize: { width: number; height: number };
    };
    // DSP-2: lines whose batch and expiry are not printed, with the reason (screen only).
    labelBatchNotes?: Array<{ name: string; previewUrl: string; note: string }>;
    // DSP-6: labels (by preview URL) printed before; they print with the REPRINT mark.
    labelReprints?: string[];
}>();
const page = usePage();
const batchNotes = computed(() => props.labelBatchNotes ?? []);
const reprinted = ref<string[]>([...(props.labelReprints ?? [])]);
const reprintCount = computed(
    () =>
        props.labels.items.filter((item) =>
            reprinted.value.includes(item.previewUrl),
        ).length,
);
const busy = ref(false);
const error = ref('');
const retired = ref(false);
const changed = computed(
    () => props.labels.branchId !== page.props.branchContext?.active?.id,
);
watch(changed, (value) => {
    if (value) {
        retired.value = true;
    }
});
const printLabels = async () => {
    if (
        busy.value ||
        changed.value ||
        retired.value ||
        props.labels.blocked.length
    ) {
        return;
    }

    busy.value = true;
    error.value = '';

    try {
        const url = new URL(window.location.href);
        url.searchParams.set('verify', '1');
        url.searchParams.set('token', props.labels.token);
        url.searchParams.set('branch', String(props.labels.branchId));
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('stale');
        }

        // DSP-6: the check also logged this print; lines printed before carry the REPRINT mark.
        const result = await response.json();

        if (!result.current || changed.value || retired.value) {
            throw new Error('stale');
        }

        reprinted.value = [
            ...new Set([...reprinted.value, ...(result.reprints ?? [])]),
        ];
        await nextTick();
        window.print();
    } catch {
        error.value =
            'The saved dispensing record or access changed. Reload and review before printing.';
        retired.value = true;
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Head title="Medicine label preview">
        <component :is="'style'">{{
            `@page { size: ${labels.pageSize.width}mm ${labels.pageSize.height}mm; margin: 0; }`
        }}</component>
    </Head>
    <main
        class="label-preview"
        :style="{
            '--label-width': labels.pageSize.width + 'mm',
            '--label-height': labels.pageSize.height + 'mm',
        }"
    >
        <header class="print-toolbar">
            <h1>Medicine label preview</h1>
            <p>
                Provisional {{ labels.pageSize.width }} ×
                {{ labels.pageSize.height }} mm. Confirm printer and sticker
                stock. Print at 100%, browser headers/footers off.
            </p>
            <p>
                Saved fulfilment data only. Unsaved workspace edits are not
                included. Printing does not confirm dispensing or change stock.
                Discard superseded paper labels after an edit.
            </p>
            <p v-if="!changed && !retired">
                Generated {{ labels.date }}. Live record; a reprint may differ
                from an earlier label. {{ labels.excludedCount }} pending,
                removed or zero-quantity lines excluded.
            </p>
            <p v-if="changed || retired" role="alert">
                Reload this visit to review the current authorised record.
            </p>
            <p v-if="error" role="alert">{{ error }}</p>
            <Button
                :disabled="
                    busy ||
                    changed ||
                    retired ||
                    labels.blocked.length > 0 ||
                    !labels.items.length
                "
                @click="printLabels"
                >{{
                    busy ? 'Checking saved record…' : 'Print current labels'
                }}</Button
            >
        </header>
        <template v-if="!changed && !retired">
            <section
                v-if="reprintCount"
                class="print-toolbar"
                aria-label="Printed before"
                data-testid="label-reprint-note"
            >
                <p v-if="labels.items.length === 1">
                    This label was printed before. It will print marked REPRINT.
                </p>
                <p v-else>
                    {{ reprintCount }} of these labels were printed before. They
                    will print marked REPRINT.
                </p>
            </section>
            <section
                v-if="batchNotes.length"
                class="print-toolbar"
                aria-label="Batch and expiry"
                data-testid="label-batch-notes"
            >
                <p>
                    Batch number and expiry are printed only when exactly one
                    batch was used for a medicine.
                </p>
                <ul>
                    <li v-for="note in batchNotes" :key="note.previewUrl">
                        <strong>{{ note.name }}</strong
                        >: {{ note.note }}
                    </li>
                </ul>
            </section>
            <section
                v-if="labels.blocked.length"
                class="print-toolbar"
                aria-label="Blocked labels"
                role="alert"
            >
                <h2>
                    Printing blocked — no labels in this selection will print
                </h2>
                <article v-for="(item, i) in labels.blocked" :key="i">
                    <strong>{{ item.name }}</strong>
                    <ul>
                        <li v-for="message in item.errors" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                </article>
                <p>
                    Review the saved fields in Dispensary. Do not shorten
                    clinical directions merely to fit a label. Nothing is
                    truncated or split into continuation pages.
                </p>
            </section>
            <section
                v-if="labels.blocked.length && labels.items.length"
                class="print-toolbar"
                aria-label="Individually printable labels"
            >
                <h2>Other medicines can print individually</h2>
                <p>
                    The blocked medicines above will not be included. Open and
                    review each eligible label separately.
                </p>
                <ul>
                    <li v-for="item in labels.items" :key="item.previewUrl">
                        <a :href="item.previewUrl" class="underline"
                            >Preview and print: {{ item.name }}</a
                        >
                    </li>
                </ul>
            </section>
            <section v-if="!labels.blocked.length" aria-label="Medicine labels">
                <article
                    v-for="(item, i) in labels.items"
                    :key="i"
                    class="medicine-label"
                >
                    <img
                        v-if="labels.logo"
                        :src="labels.logo"
                        alt="Logo klinik"
                        class="clinic-logo"
                    />
                    <p
                        v-for="(line, n) in item.lines"
                        :key="n"
                        class="label-line"
                        :class="{
                            'safety-emphasis': item.emphasis.includes(n),
                        }"
                    >
                        {{ line
                        }}<span
                            v-if="
                                n === item.lines.length - 1 &&
                                reprinted.includes(item.previewUrl)
                            "
                            class="reprint-mark"
                            data-testid="label-reprint-mark"
                            >REPRINT</span
                        >
                    </p>
                </article>
            </section>
        </template>
    </main>
</template>

<style scoped>
.label-preview {
    min-height: 100vh;
    padding: 16px;
}
.print-toolbar {
    max-width: 680px;
    margin: 0 auto 20px;
    font-size: 14px;
}
.print-toolbar h1,
.print-toolbar h2 {
    font-weight: 600;
    margin-bottom: 8px;
}
.print-toolbar p,
.print-toolbar article {
    margin: 8px 0;
}
.medicine-label {
    box-sizing: border-box;
    width: min(100%, var(--label-width));
    min-height: var(--label-height);
    padding: 3mm;
    margin: 0 auto 16px;
    border: 1px solid #777;
    color: #171717;
    background: #fff;
}
.label-line {
    font:
        10pt/4mm 'Courier New',
        monospace;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    margin: 0;
}
.safety-emphasis {
    font-weight: 700;
}
/* DSP-6: sits at the right end of the always-short last row, so it uses no line of the label budget. */
.label-line:last-child {
    position: relative;
}
.reprint-mark {
    position: absolute;
    top: 0;
    right: 0;
    box-sizing: border-box;
    height: 4mm;
    padding: 0 1mm;
    font:
        700 8pt/3.5mm 'Courier New',
        monospace;
    border: 0.25mm solid #000;
    color: #000;
    background: #fff;
    print-color-adjust: exact;
    white-space: nowrap;
}
.clinic-logo {
    display: block;
    height: 6mm;
    max-width: 20mm;
    object-fit: contain;
    margin-bottom: 0;
}
@media print {
    .print-toolbar {
        display: none;
    }
    .label-preview {
        min-height: 0;
        padding: 0;
        background: #fff !important;
        color: #171717 !important;
    }
    .medicine-label {
        width: var(--label-width);
        height: var(--label-height);
        min-height: 0;
        border: 0;
        margin: 0;
        break-inside: avoid;
        break-after: page;
        print-color-adjust: exact;
    }
    .medicine-label:last-child {
        break-after: auto;
    }
    .label-line {
        white-space: pre;
        overflow-wrap: normal;
    }
}
</style>
