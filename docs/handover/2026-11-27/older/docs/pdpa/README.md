# KPOne PDPA self-assessment pack (DRAFT)

**Status:** draft prepared 2026-11-10 from the KPOne code and docs. **Not legal advice. Not approved.**
The clinic chose to run this review itself, without a lawyer or consultant. Every item marked
**[CONFIRM]** is a fact or legal point that a person must check against an official source before it is relied on.
Nothing here authorises real patient or staff data: KPOne stays synthetic-data only until this pack is
approved and the other go-live gates are closed (`docs/BASELINE.md` section 6, items 1, 2 and 16).

## What is in the pack

| File | Purpose |
|---|---|
| [01-data-map.md](01-data-map.md) | What personal data KPOne holds, why, who sees it, and how it is protected |
| [02-notices.md](02-notices.md) | Draft privacy notices in English and Bahasa Melayu: patients, staff, job applicants |
| [03-consent-procedure.md](03-consent-procedure.md) | What the front desk and doctor say and tick, for adults and minors |
| [04-retention-schedule.md](04-retention-schedule.md) | How long each kind of data is kept, what KPOne already enforces, and what you must decide |
| [05-breach-response-plan.md](05-breach-response-plan.md) | What to do, who decides, who is told |
| [06-data-subject-requests.md](06-data-subject-requests.md) | How to answer a request to see or correct someone's data |
| [07-providers.md](07-providers.md) | Every outside party that touches data, and what to check about each |
| [08-gaps-and-decisions.md](08-gaps-and-decisions.md) | The short list of things only the owner can decide or fix |

## How to use it (suggested order)

1. Read **08** first. It is the one-page list of decisions and gaps.
2. Fill the **[CONFIRM]** items using the official sources below.
3. Check **01** and **07** are true for how you actually deploy (hosting, email, SMS providers are not chosen yet).
4. Approve the notices (**02**) and procedures (**03**, **05**, **06**). Record the approver, date and version.
5. Keep a dated copy of everything approved. This is your evidence of a reasonable, documented effort.
6. Put the approved notice text and version into KPOne (see 02, "Putting it into KPOne").

## Official sources to check

- Personal Data Protection Commissioner (JPDP / PDPC): https://www.pdp.gov.my — the Act, the Personal Data
  Protection Regulations 2013, the Codes of Practice, and the guidelines on notices, breach notification and
  the data protection officer.
- The Personal Data Protection Act 2010 (Act 709) and the **Personal Data Protection (Amendment) Act 2024**
  **[CONFIRM the Act number, which parts are in force, and the dates]**.
- Ministry of Health Malaysia: private healthcare facilities rules and medical record retention **[CONFIRM]**.
- Malaysian Medical Council: guidance on confidentiality and medical records **[CONFIRM]**.
- Your Panel/TPA contracts and your clinic insurer: they often set disclosure and retention terms.

## Limits of a self-review

- A mistake in the notice, consent or retention may not show until a complaint, breach or audit.
- The Act's 2024 amendments are recent. Re-check the official site before go-live and again each year.
- If you later decide to get help, hand this pack to the adviser; it will save their time and your fee.
