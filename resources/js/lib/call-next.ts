import type { CallNextBlock, CallNextCandidate } from '@/types';

// Q-4 Call next (owner decisions 2026-11-05). Wording only; the server decides who is next and re-checks it.

/** Steady across polls: never the candidate's number (Daniel, point 5). */
export const callNextLabel = (block: CallNextBlock): string =>
    block.mode === 'branch' && block.doctorName
        ? `Call next for ${block.doctorName}`
        : 'Call next';

export const callNextTitle = (candidate: CallNextCandidate): string =>
    `Call ${candidate.queueNumber} ${candidate.patientName}?`;

export const callNextDescription = (candidate: CallNextCandidate): string =>
    candidate.roomName
        ? `The waiting-room TV calls them to ${candidate.roomName}.`
        : 'The waiting-room TV sends them to the counter.';

export const callNextSwapMessage = (candidate: CallNextCandidate): string =>
    `The next patient changed to ${candidate.queueNumber} ${candidate.patientName}. Check and call again.`;

/** The same patient at the same versions; anything else needs a fresh confirmation. */
export const sameCandidate = (
    a: CallNextCandidate | null | undefined,
    b: CallNextCandidate | null | undefined,
): boolean =>
    !!a &&
    !!b &&
    a.visitNumber === b.visitNumber &&
    a.visitLockVersion === b.visitLockVersion &&
    a.queueLockVersion === b.queueLockVersion;

/** How long the new Call button stays disabled after a swap, so a double press cannot call the new patient. */
export const CALL_NEXT_SWAP_PAUSE_MS = 1000;
