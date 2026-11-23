// DSP-23: shared wording for the doctor's check of prepared medicine.
import type { DispensarySupervisorCheck } from '@/types';

export const overrideReasonLabels: Record<string, string> = {
    doctor_off_shift: 'the doctor is off shift',
    doctor_emergency: 'the doctor is in an emergency',
    other: 'other',
};

/** "Checked by <supervisor> instead of <doctor>, <time>. Reason: <reason>." (Daniel, change 4). */
export const supervisorCheckSentence = (check: DispensarySupervisorCheck) => {
    const action =
        check.outcome === 'verified' ? 'Checked and verified' : 'Sent back';
    const who = check.by ?? 'a CA Supervisor';
    const doctor = check.doctor ?? 'the doctor';
    const reason =
        check.overrideReason === null
            ? ''
            : ` Reason: ${overrideReasonLabels[check.overrideReason] ?? 'other'}${check.overrideNote ? ` (${check.overrideNote})` : ''}.`;

    return `${action} by ${who} (CA Supervisor) instead of ${doctor}${check.at ? `, ${check.at}` : ''}.${reason}`;
};
