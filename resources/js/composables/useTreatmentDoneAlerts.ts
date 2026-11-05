import { onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { requestJson } from '@/lib/json-client';

// Q-2: tells the attending doctor that a treatment step is done. Shares the
// not-ready sound switch (one preference) and plays a distinct two-beep tone.
// Cursors live in memory; the first read after mount only seeds the cursor,
// so a refresh never repeats an alert.
const preference = 'kpone.call-readiness-chime';
export type TreatmentUpdate = {
    cursor: number;
    ready: boolean;
    doneCount: number;
    numbers?: string[];
};
export function useTreatmentDoneAlerts(details: boolean) {
    let cursor: number | null = null;
    let branch: number | null = null;
    let audio: AudioContext | undefined;
    const doneCount = ref(0);
    const chimeOn = () => {
        try {
            return localStorage.getItem(preference) === 'true';
        } catch {
            return false;
        }
    };
    const reset = () => {
        cursor = null;
        branch = null;
        doneCount.value = 0;
    };
    const arm = async () => {
        if (!chimeOn()) {
            return;
        }

        audio ??= new AudioContext();
        await audio.resume().catch(() => undefined);
    };
    onMounted(() => {
        window.addEventListener('pointerdown', arm);
        window.addEventListener('keydown', arm);
    });
    const read = async (
        branchId: number,
        signal: AbortSignal,
    ): Promise<TreatmentUpdate | null> => {
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

        const update = await requestJson<TreatmentUpdate>(
            `/queue/treatment-updates?${params}`,
            { signal },
        );

        if (signal.aborted || branch !== requestedBranch) {
            return null;
        }

        cursor = update.cursor;
        doneCount.value = update.doneCount;

        return update;
    };
    const beep = (frequency: number, start: number) => {
        if (!audio) {
            return;
        }

        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.08, audio.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(
            0.001,
            audio.currentTime + start + 0.18,
        );
        oscillator.start(audio.currentTime + start);
        oscillator.stop(audio.currentTime + start + 0.18);
    };
    const announce = (update: TreatmentUpdate | null) => {
        if (!update?.ready) {
            return;
        }

        toast.success(
            details && update.numbers?.length
                ? `Treatment done: ${update.numbers.join(', ')}. Ready to resume.`
                : "A held patient's treatment is done. Ready to resume.",
        );

        if (!chimeOn() || !audio || audio.state !== 'running') {
            return;
        }

        beep(660, 0);
        beep(880, 0.22);
    };
    const poll = async (branchId: number, signal: AbortSignal) => {
        announce(await read(branchId, signal));
    };
    onBeforeUnmount(() => {
        reset();
        window.removeEventListener('pointerdown', arm);
        window.removeEventListener('keydown', arm);
        void audio?.close();
    });

    return { doneCount, read, announce, poll, reset };
}
