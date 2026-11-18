// Catalogue defaults a doctor's new medicine order row starts from (owner decision 2026-11-18). Pure text work only:
// the panel turns these strings into its own pickers with the same parsers it uses for a saved order, the doctor can
// change every value, and nothing here is sent to the server by itself.

export const INDICATION_LABEL_LIMIT = 72;

export type CatalogueDefaults = {
    unit: string;
    usualQuantity?: string | number | null;
    defaultDosageAmount?: string | number | null;
    defaultDosageUnit?: string | null;
    defaultFrequency?: string | null;
    defaultDuration?: string | null;
    defaultInstruction?: string | null;
    defaultPrecaution?: string | null;
    defaultIndication?: string | null;
};

export type PrefillTexts = {
    quantity: string;
    dosage: string;
    frequency: string;
    duration: string;
    instruction: string;
    precaution: string;
    indication: string;
    /** True when the catalogue supplied at least one default, so the row says it was prefilled. */
    usedDefaults: boolean;
    /** The indication prints on the drug label, which has a 72-character budget. */
    indicationTooLong: boolean;
};

/** "12.000" becomes "12", "0.500" becomes "0.5"; empty, zero or invalid values become "". */
export const trimDecimal = (value: string | number | null | undefined) => {
    const text = String(value ?? '').trim();

    if (!/^\d+(\.\d+)?$/.test(text) || Number.parseFloat(text) <= 0) {
        return '';
    }

    return text.includes('.')
        ? text.replace(/0+$/, '').replace(/\.$/, '')
        : text;
};

const clean = (value: string | null | undefined) => (value ?? '').trim();

export const prefillTexts = (item: CatalogueDefaults): PrefillTexts => {
    const usual = trimDecimal(item.usualQuantity);
    const amount = trimDecimal(item.defaultDosageAmount);
    const dosageUnit = clean(item.defaultDosageUnit);
    const dosage = amount && dosageUnit ? `${amount} ${dosageUnit}` : '';
    const frequency = clean(item.defaultFrequency);
    const duration = clean(item.defaultDuration);
    const instruction = clean(item.defaultInstruction);
    const precaution = clean(item.defaultPrecaution);
    const indication = clean(item.defaultIndication);

    return {
        // A blank usual quantity keeps the quantity of 1 an order row has always started with.
        quantity: usual || '1',
        dosage,
        frequency,
        duration,
        instruction,
        precaution,
        indication,
        usedDefaults: Boolean(
            usual ||
            dosage ||
            frequency ||
            duration ||
            instruction ||
            precaution ||
            indication,
        ),
        indicationTooLong: indication.length > INDICATION_LABEL_LIMIT,
    };
};
