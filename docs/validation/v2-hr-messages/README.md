# V2 HR Messages (feature/v2-hr-messages)

Synthetic data only, `.test` addresses, stubbed props; nothing is sent. Shots at 1440 and 390 px, light and dark: `my` (Email off, SMS not connected, WhatsApp on), `myStaffOnly` (a staff-only account: one tab, Email on), `settings`, `settingsDirty` (a price edited, Save enabled), `sent` (page 2 of 4, a long address, every status) and `sentEmpty` (a status with no messages). No horizontal scroll and no external request.

Classic compare: Classic Messages built from main and from the branch, same props, 24 states (the same six at 390 and 1440, light and dark), 0 differing pixels.

After Suresh and Daniel: Save is filled while a card has unsaved changes, with "Changes not saved yet." beside it; the leave guard covers the Settings tab (leaving the page or switching tab asks "Discard your changes?"); on My messages the chip says "You turned it on" and a second chip says "Waiting for HR" while HR has not switched the channel on. V2 shots retaken; Classic is unchanged by these edits (compare in PR #880).
