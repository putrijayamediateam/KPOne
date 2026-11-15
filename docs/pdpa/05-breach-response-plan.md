# 05 Personal data breach response plan (DRAFT)

The 2024 amendment introduced a duty to notify the Commissioner, and in some cases the affected people, of a
personal data breach **[CONFIRM: in-force date, the definition of "significant harm" or threshold, the deadline
to notify the Commissioner (I recall 72 hours) and the deadline to notify individuals (I recall 7 days after),
and the format, from the PDPC breach notification guideline]**. Fill the bracketed numbers from the official page before approving.

## Roles (name real people)

| Role | Person | Backup |
|---|---|---|
| Incident lead (decides) | [ ] | [ ] |
| Data protection officer | [ ] | [ ] |
| Technical contact (IT owner or agency) | [ ] | [ ] |
| Communications (talks to patients/staff) | [ ] | [ ] |

## What counts as a breach

Any loss, theft, leak or unauthorised access or disclosure of personal data. Examples: a stolen or lost phone
or laptop with a logged-in KPOne session, a shared login, a message sent to the wrong patient, a record opened
without a work reason, a misconfigured server, a leaked key or password, a patient's letter given to the wrong
person, a backup copy exposed.

## Steps

**0 to 1 hour: contain**
1. Whoever notices tells the incident lead immediately. Do not hide it or fix it silently.
2. Contain: sign the account out and deactivate it in KPOne (Staff page), reset passwords, revoke provider keys, take a lost device out of use.
3. Write down what, when, who noticed, and what is known. Do not delete anything. Keep evidence.

**1 to 24 hours: assess**
1. What data? Whose? How many people? Health or ID data? Was it encrypted?
2. KPOne's **audit trail** shows who accessed what (ids and codes, not content). Ask the technical contact to extract the relevant rows.
3. Decide: is significant harm likely? If unsure, treat it as yes.

**Within the deadline: notify**
1. Notify the Commissioner via the official channel **[CONFIRM form and deadline]** if the threshold is met.
2. Notify affected people if required **[CONFIRM]**: what happened, what data, what we did, what they can do, who to contact.
3. Notify Panel/TPA or insurers if contracts require **[CHECK contracts]**. Report theft to the police where relevant.

**After: fix and learn**
1. Fix the cause. Rotate keys. Review access.
2. Write a short report: timeline, cause, impact, notifications, fixes.
3. Keep the record **[period, DECIDE]**. Update training and this plan.

## Where KPOne helps

- Every sensitive "Show", open and download is recorded before the data is released.
- Sign-in, role and branch changes are audited without credentials.
- A deactivated account is rejected immediately, including active sessions.
- `kpone:read` queries are audited by hash.

## Downloaded HR files (owner decision 2026-11-11, 08 item 14)

- Only the HR Manager and the Director download the payroll bank file and the accountant file, and KPOne records each
  download (who, when, which run and file; never the contents).
- The files come password-protected. Send the password by a different channel from the file.
- Keep downloaded files only in one locked company folder. After sending to the bank or the accountant, delete the
  copies on the laptop and in email. Never forward a file unprotected.
- A lost laptop or a mis-sent email holding one of these files is a breach: follow the steps above, and use KPOne's
  download record to say which run and which file were involved.

## Where KPOne does not help (decide)

- No automatic alert to anyone. Decide who watches for odd access **[DECIDE]**.
- Logs and backups are outside KPOne: the IT owner must be able to answer "what was accessed" from the server logs too.

## Practice

Run one small tabletop exercise before go-live: "a staff phone with a logged-in session is lost at 6 pm". Time how long each step takes.
