<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { requestJson } from '@/lib/json-client';
import {
    BARCODE_FORMATS,
    cameraMessages,
    cameraSupport,
    cleanScannedCode,
    isRepeat,
    scanMessage,
} from '@/lib/scan';
import type { CameraSupport, ScanLookup, ScannedSku } from '@/lib/scan';

/**
 * STK-9: scan or type a barcode to find a SKU. Works with handheld scanners that type the code and press Enter,
 * with typing, and with the browser's built-in camera barcode reader where available. It only finds; the page
 * decides what to show, and nothing is ever saved by a scan.
 */
const props = defineProps<{
    context: 'search' | 'receiving';
    /** Refocus key used after a scan-triggered page reload, so the next wedge scan lands here. */
    refocusKey: string;
    /** Optional page check; return a message to refuse the SKU (for example "not on this order"). */
    accept?: (sku: ScannedSku) => string | null;
}>();
const emit = defineEmits<{ found: [sku: ScannedSku] }>();

const inputId = `scan-${props.refocusKey}`;
const code = ref('');
const status = ref('Ready to scan.');
const failed = ref(false);
const busy = ref(false);
const starting = ref(false);
// Checked once on mount, so an unsupported device shows the reason instead of a button that only fails.
const cameraState = ref<CameraSupport>('supported');
const open = ref(false);
const video = ref<HTMLVideoElement | null>(null);
let stream: MediaStream | null = null;
let timer: number | null = null;
let unmounted = false;
let last: { code: string; at: number } | null = null;
const STORAGE_KEY = 'kpone-scan-return';

function show(text: string, isError: boolean) {
    status.value = text;
    failed.value = isError;
}

function box(): HTMLInputElement | null {
    return document.getElementById(inputId) as HTMLInputElement | null;
}

/** Select the box so the next handheld scan replaces the code instead of appending to it. */
function selectBox() {
    window.setTimeout(() => box()?.select(), 0);
}

async function lookup(raw: string) {
    const value = cleanScannedCode(raw);

    if (value === '' || busy.value || isRepeat(last, value, Date.now())) {
        selectBox();

        return;
    }

    last = { code: value, at: Date.now() };
    busy.value = true;
    show(`Looking up code ${value}…`, false);

    try {
        const result = await requestJson<ScanLookup>(
            `/inventory/scan?code=${encodeURIComponent(value)}`,
        );

        if (result.status !== 'found' || !result.sku.active) {
            show(scanMessage(result, props.context), true);

            return;
        }

        const refused = props.accept?.(result.sku) ?? null;

        if (refused) {
            show(refused, true);

            return;
        }

        const message = scanMessage(result, props.context);
        show(message, false);
        code.value = '';

        if (props.context === 'search') {
            try {
                // The search reloads the page; keep the result line and the cursor for the next scan.
                window.sessionStorage.setItem(
                    STORAGE_KEY,
                    JSON.stringify({ key: props.refocusKey, message }),
                );
            } catch {
                // Browser storage is only a convenience for refocusing.
            }
        }

        emit('found', result.sku);
    } catch {
        show('Lookup failed. Try again.', true);
    } finally {
        busy.value = false;
        selectBox();
    }
}

function stopCamera() {
    if (timer !== null) {
        window.clearTimeout(timer);
        timer = null;
    }

    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

type Detector = {
    detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
};

/** One read at a time: the next read is scheduled only after the previous one has finished. */
function scheduleRead(detector: Detector) {
    timer = window.setTimeout(async () => {
        if (!open.value || !stream || !video.value) {
            return;
        }

        const found =
            video.value.readyState >= 2
                ? await detector
                      .detect(video.value)
                      .catch(() => [] as { rawValue: string }[])
                : [];
        const read = found[0]?.rawValue;

        if (read) {
            navigator.vibrate?.(60);
            closeCamera();
            code.value = cleanScannedCode(read);
            await lookup(read);

            return;
        }

        scheduleRead(detector);
    }, 250);
}

async function startCamera() {
    if (starting.value || open.value) {
        return;
    }

    const support = cameraSupport(
        window as unknown as Parameters<typeof cameraSupport>[0],
    );

    if (support !== 'supported') {
        show(cameraMessages[support], true);

        return;
    }

    starting.value = true;

    try {
        const media = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
            audio: false,
        });

        if (unmounted) {
            media.getTracks().forEach((track) => track.stop());

            return;
        }

        stream = media;
    } catch (error) {
        const denied =
            error instanceof DOMException && error.name === 'NotAllowedError';
        show(denied ? cameraMessages.denied : cameraMessages.error, true);

        return;
    } finally {
        starting.value = false;
    }

    open.value = true;
    await nextTick();

    try {
        if (!video.value || !stream) {
            throw new Error('No video element');
        }

        video.value.srcObject = stream;
        await video.value.play();
        const BarcodeDetectorClass = (
            window as unknown as {
                BarcodeDetector: new (options: {
                    formats: string[];
                }) => Detector;
            }
        ).BarcodeDetector;
        scheduleRead(new BarcodeDetectorClass({ formats: BARCODE_FORMATS }));
    } catch {
        closeCamera();
        show(cameraMessages.error, true);
    }
}

function closeCamera() {
    stopCamera();
    open.value = false;
}

function onOpenChange(value: boolean) {
    if (!value) {
        closeCamera();
    }
}

function onVisibility() {
    if (document.visibilityState === 'hidden') {
        closeCamera();
    }
}

onMounted(() => {
    cameraState.value = cameraSupport(
        window as unknown as Parameters<typeof cameraSupport>[0],
    );
    document.addEventListener('visibilitychange', onVisibility);

    try {
        const saved = JSON.parse(
            window.sessionStorage.getItem(STORAGE_KEY) ?? 'null',
        ) as { key?: unknown; message?: unknown } | null;

        if (saved?.key === props.refocusKey) {
            window.sessionStorage.removeItem(STORAGE_KEY);

            if (typeof saved.message === 'string' && saved.message !== '') {
                show(saved.message, false);
            }

            box()?.focus();
        }
    } catch {
        // Ignore unavailable browser storage.
    }
});
onBeforeUnmount(() => {
    unmounted = true;
    document.removeEventListener('visibilitychange', onVisibility);
    stopCamera();
});
</script>

<template>
    <div class="min-w-0 space-y-2 rounded-lg border p-3">
        <div class="flex flex-wrap items-end gap-2">
            <label
                :for="inputId"
                class="min-w-[min(100%,18rem)] flex-1 basis-72"
                >Scan or type barcode<Input
                    :id="inputId"
                    v-model="code"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    enterkeyhint="search"
                    maxlength="100"
                    :aria-describedby="`${inputId}-help ${inputId}-status`"
                    :aria-invalid="failed"
                    class="mt-1 min-h-11"
                    @keydown.enter.prevent.stop="lookup(code)"
            /></label>
            <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                <Button
                    type="button"
                    class="min-h-11"
                    :disabled="busy"
                    @click="lookup(code)"
                >
                    Look up
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    :disabled="starting || cameraState !== 'supported'"
                    :aria-describedby="
                        cameraState === 'supported'
                            ? undefined
                            : `${inputId}-camera`
                    "
                    @click="startCamera"
                >
                    Use camera
                </Button>
            </div>
        </div>
        <p :id="`${inputId}-help`" class="text-xs text-muted-foreground">
            Use a handheld scanner, type the code, or use the camera. Tap or
            click the box before scanning. Scanning only finds the SKU; it does
            not save anything.
        </p>
        <p
            v-if="cameraState !== 'supported'"
            :id="`${inputId}-camera`"
            class="text-xs text-muted-foreground"
        >
            {{ cameraMessages[cameraState] }}
        </p>
        <div :id="`${inputId}-status`" class="min-w-0 text-sm break-words">
            <p role="status" aria-live="polite" class="text-muted-foreground">
                {{ failed ? '' : status }}
            </p>
            <p role="alert" aria-live="assertive" class="text-destructive">
                {{ failed ? status : '' }}
            </p>
        </div>
        <Dialog :open="open" @update:open="onOpenChange">
            <DialogContent
                class="max-h-[90dvh] max-w-[calc(100%-2rem)] sm:max-w-lg"
            >
                <DialogHeader>
                    <DialogTitle>Scan with camera</DialogTitle>
                    <DialogDescription
                        >Point the camera at the barcode. Hold
                        steady.</DialogDescription
                    >
                </DialogHeader>
                <div class="relative overflow-hidden rounded">
                    <video
                        ref="video"
                        class="aspect-[4/3] max-h-[60dvh] w-full bg-black object-cover"
                        muted
                        playsinline
                    ></video>
                    <div
                        class="pointer-events-none absolute inset-x-8 top-1/2 h-1/3 -translate-y-1/2 rounded border-2 border-white/80"
                        aria-hidden="true"
                    ></div>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 w-full"
                    @click="closeCamera"
                >
                    Cancel
                </Button>
            </DialogContent>
        </Dialog>
    </div>
</template>
