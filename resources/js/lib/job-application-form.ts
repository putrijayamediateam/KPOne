// HR-17a public job-application form in three steps (Daniel; HR-PLAN 19.4). These checks only help the applicant
// before Next: the server is still the only judge, with the same messages. Nothing here is kept in the browser.

export type PositionType = 'ca' | 'intern' | 'locum' | 'resident_doctor';

export type ApplicationValues = {
    position: string;
    name: string;
    phone: string;
    email: string;
    preferred_branch: string;
    start_date: string;
    education: string;
    // A number box gives v-model a number once typed, so these may be numbers.
    years_experience: string | number;
    languages: string[];
    institution: string;
    programme: string;
    intern_from: string;
    intern_to: string;
    availability: string;
    mmc_number: string;
    apc_year: string | number;
    apc_expiry: string;
    message: string;
    privacy_accepted: boolean;
};

export const STEP_COUNT = 3;

/** The fields each step asks, so a server error can open the step that holds it. */
export const STEP_FIELDS: Record<number, string[]> = {
    1: ['position', 'name', 'phone', 'email'],
    2: [
        'preferred_branch',
        'start_date',
        'education',
        'years_experience',
        'languages',
        'institution',
        'programme',
        'intern_from',
        'intern_to',
        'availability',
        'mmc_number',
        'apc_year',
        'apc_expiry',
        'message',
    ],
    3: ['cv', 'privacy_accepted', 'form'],
};

export const stepOf = (field: string): number =>
    Number(
        Object.entries(STEP_FIELDS).find(([, fields]) =>
            fields.includes(field),
        )?.[0] ?? 3,
    );

const clean = (value: string): string => value.replace(/\s+/g, ' ').trim();
const iso = /^\d{4}-\d{2}-\d{2}$/;

/** yyyy-mm-dd shifted by whole days or years, as a string, in the applicant's own calendar. */
export const shiftDate = (from: string, days: number, years = 0): string => {
    const [y, m, d] = from.split('-').map(Number);
    const date = new Date(Date.UTC(y + years, m - 1, d + days));

    return date.toISOString().slice(0, 10);
};

/**
 * Errors for one step, keyed by field, in English and Bahasa Melayu as the server words them.
 * `today` is yyyy-mm-dd.
 */
export const validateApplicationStep = (
    step: number,
    v: ApplicationValues,
    type: PositionType | null,
    today: string,
): Record<string, string> => {
    const errors: Record<string, string> = {};

    if (step === 1) {
        if (!v.position) {
            errors.position =
                'Choose the position you are applying for. / Pilih jawatan yang anda mohon.';
        }

        const name = clean(v.name);

        if (name.length < 2 || name.length > 100) {
            errors.name =
                'Enter your name (2 to 100 characters). / Masukkan nama anda (2 hingga 100 aksara).';
        }

        if (!/^\+?[0-9][0-9 -]{6,18}[0-9]$/.test(clean(v.phone))) {
            errors.phone =
                'Enter a phone number we can call, for example 012-345 6789. / Masukkan nombor telefon yang boleh dihubungi, contohnya 012-345 6789.';
        }

        const email = clean(v.email);

        if (email.length > 120 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errors.email =
                'Enter an email address, for example name@example.com. / Masukkan alamat e-mel, contohnya nama@example.com.';
        }
    }

    if (step === 2) {
        if (!v.preferred_branch) {
            errors.preferred_branch =
                'Choose a branch, or "Any branch". / Pilih cawangan, atau "Mana-mana cawangan".';
        }

        if (
            !iso.test(v.start_date) ||
            v.start_date < today ||
            v.start_date > shiftDate(today, 0, 1)
        ) {
            errors.start_date =
                'Enter the earliest date you can start, within the next year. / Masukkan tarikh terawal anda boleh mula, dalam tempoh setahun.';
        }

        if (!v.education) {
            errors.education =
                'Choose your highest education. / Pilih pendidikan tertinggi anda.';
        }

        const yearsText = String(v.years_experience ?? '').trim();
        const years = Number(yearsText);

        if (
            yearsText === '' ||
            !Number.isInteger(years) ||
            years < 0 ||
            years > 50
        ) {
            errors.years_experience =
                'Enter your years of relevant work, from 0 to 50. / Masukkan tahun pengalaman kerja berkaitan, dari 0 hingga 50.';
        }

        if (!v.languages.length) {
            errors.languages =
                'Choose the languages you speak. / Pilih bahasa yang anda tuturkan.';
        }

        if (type === 'intern') {
            if (clean(v.institution).length < 2) {
                errors.institution =
                    'Enter your college or university. / Masukkan kolej atau universiti anda.';
            }

            if (clean(v.programme).length < 2) {
                errors.programme =
                    'Enter your course or programme. / Masukkan kursus atau program anda.';
            }

            if (
                !iso.test(v.intern_from) ||
                !iso.test(v.intern_to) ||
                v.intern_to < v.intern_from ||
                v.intern_from < today
            ) {
                errors.intern_from =
                    'Enter when your internship starts and ends. / Masukkan tarikh latihan industri anda bermula dan tamat.';
            }
        }

        if (type === 'locum') {
            const length = clean(v.availability).length;

            if (length < 5 || length > 300) {
                errors.availability =
                    'Tell us the days and times you are usually free (5 to 300 characters). / Nyatakan hari dan masa anda biasanya lapang (5 hingga 300 aksara).';
            }
        }

        if (type === 'resident_doctor') {
            if (!/^[0-9]{3,7}$/.test(clean(v.mmc_number))) {
                errors.mmc_number =
                    'Enter your MMC registration number (digits only). / Masukkan nombor pendaftaran MMC anda (nombor sahaja).';
            }

            const yearText = String(v.apc_year ?? '').trim();
            const year = yearText === '' ? NaN : Number(yearText);
            const thisYear = Number(today.slice(0, 4));

            if (
                !Number.isInteger(year) ||
                year < thisYear - 1 ||
                year > thisYear + 1
            ) {
                errors.apc_year =
                    'Enter the year of your current APC. / Masukkan tahun APC semasa anda.';
            }

            if (!iso.test(v.apc_expiry)) {
                errors.apc_expiry =
                    'Enter the date your APC expires. / Masukkan tarikh APC anda tamat.';
            }
        }

        if (v.message.trim().length > 1000) {
            errors.message =
                'Use at most 1,000 characters. / Gunakan tidak lebih 1,000 aksara.';
        }
    }

    if (step === 3 && !v.privacy_accepted) {
        errors.privacy_accepted =
            'Read the privacy notice and tick the box to apply. / Baca notis privasi dan tandakan kotak untuk memohon.';
    }

    return errors;
};
