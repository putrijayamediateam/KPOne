// The home greeting follows the branch clock, not the device clock: a device set to another time zone (or a headless
// browser on UTC) would otherwise say "Good afternoon" late at night in Kuala Lumpur.

const part = (hour: number): string =>
    hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

/** Hour 0-23 from the server's "g:i A" as-of text, which the server writes in the branch time zone. */
export const hourFromAsOf = (
    asOf: string | null | undefined,
): number | null => {
    const match = /^\s*(\d{1,2}):\d{2}\s*(AM|PM)\s*$/i.exec(asOf ?? '');

    if (!match) {
        return null;
    }

    const hour12 = Number(match[1]) % 12;

    return match[2].toUpperCase() === 'PM' ? hour12 + 12 : hour12;
};

/** Hour 0-23 in an IANA time zone, or null when the zone is missing or unknown. */
export const hourInZone = (
    timezone: string | null | undefined,
): number | null => {
    if (!timezone) {
        return null;
    }

    try {
        const text = new Intl.DateTimeFormat('en-GB', {
            hour: 'numeric',
            hourCycle: 'h23',
            timeZone: timezone,
        }).format(new Date());

        return Number(text) % 24;
    } catch {
        return null;
    }
};

export const greetingFor = (
    fullName: string | null | undefined,
    hour: number | null,
): string => {
    const first = (fullName ?? '').trim().split(/\s+/)[0];
    const text = part(hour ?? new Date().getHours());

    return first ? `${text}, ${first}` : text;
};
