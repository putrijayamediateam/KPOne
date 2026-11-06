<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    labels: {
        clinic: string;
        branch: string;
        patient: { name: string };
        date: string;
        items: Array<{
            name: string;
            lines: string[];
            emphasis: number[];
            // DSP-10: the thick rule follows this many header rows; the controlled line prints below the border.
            ruleAfter: number;
            controlled: string | null;
            previewUrl: string;
        }>;
        blocked: Array<{ name: string; errors: string[] }>;
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
watch(
    () => props.labelReprints,
    (urls) => {
        reprinted.value = [...new Set([...reprinted.value, ...(urls ?? [])])];
    },
);
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
        // DSP-6b: the check logs the print, so it is a POST to this page's own address plus /print.
        const url = new URL(window.location.href);
        url.pathname = `${url.pathname.replace(/\/$/, '')}/print`;
        url.search = '';
        const csrf =
            window.document
                ?.querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                token: props.labels.token,
                branch: props.labels.branchId,
            }),
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
                Label size {{ labels.pageSize.width }} ×
                {{ labels.pageSize.height }} mm (provisional). Confirm printer
                and sticker stock. Print at 100%, browser headers/footers off.
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
            <p
                v-if="!changed && !retired && reprintCount"
                aria-live="polite"
                data-testid="label-reprint-note"
            >
                {{
                    labels.items.length === 1
                        ? 'This label was printed before. It will print marked REPRINT.'
                        : reprintCount === 1
                          ? '1 of these labels was printed before. It will print marked REPRINT.'
                          : `${reprintCount} of these labels were printed before. They will print marked REPRINT.`
                }}
            </p>
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
                v-if="batchNotes.length"
                class="print-toolbar"
                aria-label="Expiry"
                data-testid="label-batch-notes"
            >
                <p>
                    The expiry is printed only when exactly one batch was used
                    for a medicine; otherwise the label shows "EXP: -".
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
                class="print-toolbar grid gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3"
                aria-label="Blocked labels"
                role="alert"
            >
                <h2 class="font-semibold text-destructive">
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
                    <div class="label-box">
                        <span
                            v-if="reprinted.includes(item.previewUrl)"
                            class="reprint-mark"
                            data-testid="label-reprint-mark"
                            >REPRINT</span
                        >
                        <p
                            v-for="(line, n) in item.lines"
                            :key="n"
                            class="label-line"
                            :class="{
                                'safety-emphasis': item.emphasis.includes(n),
                                'label-rule': n === item.ruleAfter - 1,
                                'label-bottom': n === item.lines.length - 1,
                            }"
                        >
                            {{ line }}
                        </p>
                    </div>
                    <p
                        v-if="item.controlled"
                        class="controlled-line"
                        data-testid="label-controlled"
                    >
                        {{ item.controlled }}
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
/* DSP-8, DD-24: phone and tablet tap target of at least 44 px (design guide, section 12); screen only. */
@media screen and (max-width: 1023.98px) {
    .print-toolbar :deep(button),
    .print-toolbar :deep(a) {
        display: inline-flex;
        align-items: center;
        min-height: 44px;
    }
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
/* DSP-10: the clinic's landscape label (owner decisions 2026-11-06). Monospace 9 pt rows of 48 cells, at most 13 rows
   inside a rounded border; a strip below the border is always kept for the controlled-medicine line. */
.medicine-label {
    box-sizing: border-box;
    width: var(--label-width);
    height: var(--label-height);
    padding: 1.5mm 1.5mm 0;
    margin: 0 auto 16px;
    color: #171717;
    background: #fff;
    outline: 1px dashed #bbb;
}
.label-box {
    position: relative;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    height: 55mm;
    padding: 1.5mm 2mm;
    border: 0.4mm solid #000;
    border-radius: 3mm;
}
.label-line {
    font:
        9pt/3.8mm 'Courier New',
        Courier,
        'Liberation Mono',
        monospace;
    white-space: pre;
    margin: 0;
}
.label-rule {
    padding-bottom: 0.4mm;
    border-bottom: 0.6mm solid #000;
    margin-bottom: 0.4mm;
}
.label-bottom {
    margin-top: auto;
}
.safety-emphasis {
    font-weight: 700;
}
/* DSP-6, DSP-10: reserved cells at the top right of the first row (the clinic name stays clear of them). */
.reprint-mark {
    position: absolute;
    top: 1.7mm;
    right: 2.5mm;
    box-sizing: border-box;
    height: 3.4mm;
    padding: 0 1mm;
    font:
        700 7pt/3mm 'Courier New',
        Courier,
        'Liberation Mono',
        monospace;
    border: 0.25mm solid #000;
    color: #000;
    background: #fff;
    print-color-adjust: exact;
    white-space: nowrap;
}
.controlled-line {
    height: 4.5mm;
    margin: 0;
    font:
        700 9pt/4.5mm 'Courier New',
        Courier,
        'Liberation Mono',
        monospace;
    text-align: center;
    white-space: nowrap;
}
/* The screen preview scales the true-size label to fit a phone; printing is always 100%. */
@media screen and (max-width: 420px) {
    .medicine-label {
        zoom: 0.9;
    }
}
/* On a wide screen the preview shows the label at 150% so staff can read it; printing is always 100%. */
@media screen and (min-width: 1280px) {
    .medicine-label {
        zoom: 1.5;
    }
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
        outline: 0;
        margin: 0;
        break-inside: avoid;
        break-after: page;
        print-color-adjust: exact;
    }
    .medicine-label:last-child {
        break-after: auto;
    }
}
</style>
