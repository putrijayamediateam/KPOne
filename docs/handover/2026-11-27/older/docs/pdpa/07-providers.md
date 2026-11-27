# 07 Outside parties that may touch data (DRAFT)

A "processor" handles personal data for you. Under the amended PDPA processors have their own duties, and you
should have written terms with each one **[CONFIRM]**. For each row, find the provider's **data processing terms**
(large providers publish them), accept them in your account, and file a copy.

| Party | What they may handle | Status | What to check / do |
|---|---|---|---|
| **Hosting / server** (not chosen) | Everything | **[DECIDE]** | Country of the data centre; encryption at rest; access by the provider's staff; incident duties; data processing terms; contract end and data return |
| **Backup storage** (not chosen) | A copy of everything | **[DECIDE]** | Same as hosting; encrypted backups; separate access; retention (04) |
| **Google sign-in** | Staff work email and basic profile at sign-in | In use (optional) | Google Cloud data processing terms; only existing accounts can sign in; no patient data |
| **Email provider** for staff messages (not chosen; off by default) | Staff email addresses and message text | **[DECIDE]** | Provider terms; keep message text free of patient data; keys only in server secrets |
| **SMS / WhatsApp / OTP provider** (authorised for staff and HR only, not built) | Staff phone numbers, message text | **[DECIDE]** | Provider terms; message cap; no patient messaging |
| **WhatsApp (Meta)** click-to-chat | Patient's number and what the staff member types, once they open the chat | In use (link only) | Staff must not put clinical detail in messages; mention in the notice that WhatsApp is used on request |
| **Cloudflare** (DNS and later static website) | Visitor IPs, public website files; **no patient data** | Planned | Cloudflare terms; the key holder is Haziq |
| **YouTube (nocookie)** video on the TV | The TV's IP address | Optional | Ok to keep, or turn the video off |
| **Anthropic (Claude)** via "Ask KPOne" | Masked query results the owner reads in Claude | Owner's use, synthetic only | **Do not use with real data until reviewed.** Check Anthropic's terms, data retention and whether masked data is still personal data **[CONFIRM]** |
| **GitHub** (code) | Source code only, no real data and no `.env` | In use | Keep it that way |
| **IT agency / IT person** | Possible access to servers and database | **[DECIDE]** | Contract with confidentiality and data protection terms; named people; revoke access when they leave |
| **Panel / TPA** | Claim data you send them | In use at counter, manual | They are an independent recipient; check each contract; send only what is needed |
| **Accountant / payroll adviser** | Staff and payroll data; invoice data | Planned | Confidentiality and data protection terms |
| **Printers and sticker supplier** | None (paper) | | Dispose of misprints securely |

## Sending data outside Malaysia

List every row above whose servers are outside Malaysia (Google, Meta, Cloudflare, Anthropic, any email or SMS
provider, any cloud region). The amended Act changed the rules for cross-border transfers
**[CONFIRM what is required now: for example, a risk assessment instead of the old whitelist]**.
Record the destination country and the safeguards for each, and mention it in the notice (02).

## Provider register to complete

| Provider | Service | Country | Data | Terms filed (date) | Reviewed by |
|---|---|---|---|---|---|
| | | | | | |
