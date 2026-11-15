# HR-PHOTO-STORAGE: card shots (feature head 8663513f)

The read-only "Clock-in photo storage" card on the Version 2 attendance Settings tab (Director, HR Manager). Real V2 shell, synthetic `.test` accounts, synthetic noise photos, task-owned `*_testing` databases.

| Shot | What it shows |
|---|---|
| `photo-storage-data-390-light/dark`, `photo-storage-data-1440-light/dark` | 119 synthetic photos from 28 Jul 2026: warning chip "Retention: 7 years · draft, to confirm", the earliest delete (29 Jul 2033), the figures with their units, the rough forecast from the last three full months, "Totals only. No names." |
| `photo-storage-empty-390-light/dark`, `photo-storage-empty-1440-light/dark` | No photos yet: the chip, the rule and "No clock-in photos yet." |

The card is in Version 2 only (Classic is unchanged). It is read only: no Save, no request. No sideways scroll at 390 or 1440.

Also in this slice, with nothing to see: `php artisan hr-attendance:purge --dry-run` reports what the existing daily purge would delete today, the next date anything falls due, and a 12-month table. It deletes nothing. The real delete is unchanged and stays scheduled; nothing can be deleted before November 2033 with the first photos.
