import { onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { requestJson } from '@/lib/json-client';

// Only this boolean preference is retained. Cursors and queue details live in memory.
const preference = 'kpone.call-readiness-chime';
type ReadyUpdate = { cursor: number; ready: boolean; numbers?: string[] };
export function useCallReadinessAlerts(details: boolean) {
    const chimeEnabled = ref(false);

    try {
        chimeEnabled.value = localStorage.getItem(preference) === 'true';
    } catch {
        /* Browser storage may be disabled. */
    }

    let cursor: number | null = null;
    let branch: number | null = null;
    let audio: AudioContext | undefined;
    const reset = () => {
        cursor = null;
        branch = null;
    };
    const armChime = async () => {
        if (!chimeEnabled.value) {
            return;
        }

        audio ??= new AudioContext();
        await audio.resume().catch(() => undefined);
    };
    onMounted(() => {
        // Browsers require a fresh user gesture even for a remembered opt-in.
        window.addEventListener('pointerdown', armChime, { once: true });
        window.addEventListener('keydown', armChime, { once: true });
    });
    const toggleChime = async () => {
        chimeEnabled.value = !chimeEnabled.value;

        try {
            localStorage.setItem(preference, String(chimeEnabled.value));
        } catch {
            /* Preference still works for this page. */
        }

        await armChime();
    };
    const read = async (
        branchId: number,
        signal: AbortSignal,
    ): Promise<ReadyUpdate | null> => {
        if (branch !== branchId) {
            reset();
            branch = branchId;
        }

        const requestedBranch = branch;
        const params = new URLSearchParams({
            expected_branch_id: String(branchId),
            details: details ? '1' : '0',
        });

        if (cursor !== null) {
            params.set('cursor', String(cursor));
        }

        const update = await requestJson<ReadyUpdate>(
            `/queue/readiness-updates?${params}`,
            { signal },
        );

        if (signal.aborted || branch !== requestedBranch) {
            return null;
        }

        cursor = update.cursor;

        return update;
    };
    const announce = (update: ReadyUpdate | null, aggregateDropped = true) => {
        if (!update?.ready || !aggregateDropped) {
            return;
        }

        toast.success(
            details && update.numbers?.length
                ? `Ready to be called: ${update.numbers.join(', ')}`
                : 'A patient is ready to be called',
        );

        if (!chimeEnabled.value || !audio || audio.state !== 'running') {
            return;
        }

        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.frequency.value = 660;
        gain.gain.setValueAtTime(0.08, audio.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.3);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.3);
    };
    const poll = async (
        branchId: number,
        signal: AbortSignal,
        aggregateDropped = true,
    ) => {
        announce(await read(branchId, signal), aggregateDropped);
    };
    onBeforeUnmount(() => {
        reset();
        window.removeEventListener('pointerdown', armChime);
        window.removeEventListener('keydown', armChime);
        void audio?.close();
    });

    return { chimeEnabled, toggleChime, read, announce, poll, reset };
}
