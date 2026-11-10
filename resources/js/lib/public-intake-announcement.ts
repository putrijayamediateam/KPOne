export type StatusAnnouncementInput = {
    message: string;
    queueNumber: string | null;
    ahead: number | null;
};

/**
 * What a screen reader hears on the patient QR status page when something changes: the status message, the queue
 * number and the patients ahead. The wait estimate and the other branches' counts are left out because they change
 * on most polls. When the patient has been called, the call banner announces it alone, so nothing is returned.
 */
export function statusAnnouncement(
    status: StatusAnnouncementInput,
    called: boolean,
): string | null {
    if (called) {
        return null;
    }

    const parts = [status.message];

    if (status.queueNumber) {
        parts.push(`Nombor queue anda ${status.queueNumber}.`);
    }

    if (status.ahead !== null) {
        parts.push(
            status.ahead > 0
                ? `Pesakit di hadapan anda: ${status.ahead}.`
                : 'Anda seterusnya dalam giliran.',
        );
    }

    return parts.join(' ');
}
