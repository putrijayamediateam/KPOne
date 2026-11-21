# DSP-24 screenshots (synthetic preview data)

Code head: 9bacf9ba3 on feature/dsp-24-unit-conversion. 1440 and 390 px, light and dark, deviceScaleFactor 2. Evidence only; not for merge.

Measurements (width, theme, state, sideways overflow in px and checks):

```
1440 light doctor-panel {"overflow":0,"prepared":"2 bottles (120 ml) · Dose 5 ml · 3 times a day · 3 days"}
1440 light ca-bottles {"overflow":0,"line":"2 bottles = 120 ml","batch":"Selected: 540 ml (9 bottles) on hand · exp 21 Nov 2027 · SYR-2611A · Cheras Dispensary (Demo)","radioHeights":[16,16]}
1440 light ca-bottles-error {"overflow":0,"line":"Whole bottles only, 1 to 99."}
1440 light ca-ml {"overflow":0,"label":"Quantity (ml)"}
1440 light ca-completed {"overflow":0}
1440 light label-preview {"overflow":0}
1440 light invoices-page {"overflow":0}
390 light doctor-panel {"overflow":0,"prepared":"2 bottles (120 ml) · Dose 5 ml · 3 times a day · 3 days"}
390 light ca-bottles {"overflow":0,"line":"2 bottles = 120 ml","batch":"Selected: 540 ml (9 bottles) on hand · exp 21 Nov 2027 · SYR-2611A · Cheras Dispensary (Demo)","radioHeights":[44,44]}
390 light ca-bottles-error {"overflow":0,"line":"Whole bottles only, 1 to 99."}
390 light ca-ml {"overflow":0,"label":"Quantity (ml)"}
390 light ca-completed {"overflow":0}
390 light label-preview {"overflow":0}
390 light invoices-page {"overflow":0}
1440 dark doctor-panel {"overflow":0,"prepared":"2 bottles (120 ml) · Dose 5 ml · 3 times a day · 3 days"}
1440 dark ca-bottles {"overflow":0,"line":"2 bottles = 120 ml","batch":"Selected: 540 ml (9 bottles) on hand · exp 21 Nov 2027 · SYR-2611A · Cheras Dispensary (Demo)","radioHeights":[16,16]}
1440 dark ca-bottles-error {"overflow":0,"line":"Whole bottles only, 1 to 99."}
1440 dark ca-ml {"overflow":0,"label":"Quantity (ml)"}
1440 dark ca-completed {"overflow":0}
1440 dark label-preview {"overflow":0}
1440 dark invoices-page {"overflow":0}
390 dark doctor-panel {"overflow":0,"prepared":"2 bottles (120 ml) · Dose 5 ml · 3 times a day · 3 days"}
390 dark ca-bottles {"overflow":0,"line":"2 bottles = 120 ml","batch":"Selected: 540 ml (9 bottles) on hand · exp 21 Nov 2027 · SYR-2611A · Cheras Dispensary (Demo)","radioHeights":[44,44]}
390 dark ca-bottles-error {"overflow":0,"line":"Whole bottles only, 1 to 99."}
390 dark ca-ml {"overflow":0,"label":"Quantity (ml)"}
390 dark ca-completed {"overflow":0}
390 dark label-preview {"overflow":0}
390 dark invoices-page {"overflow":0}
390 dark ca-sent-back-differs {"overflow":0,"differs":"Doctor ordered 60 ml. This is 120 ml."}
1440 light ca-sent-back-differs {"overflow":0}
390 light ca-sent-back-differs {"overflow":0}
1440 dark ca-sent-back-differs {"overflow":0}
1440 light {"overflow":0,"qty":false,"row":false}
390 light {"overflow":0,"qty":false,"row":false}
1440 dark {"overflow":0,"qty":false,"row":false}
390 dark {"overflow":0,"qty":false,"row":false}
1440 light {"overflow":0,"qty":false,"row":false}
390 light {"overflow":0,"qty":false,"row":false}
1440 dark {"overflow":0,"qty":false,"row":false}
390 dark {"overflow":0,"qty":false,"row":false}
```

## Update: Save line disabled while the bottles number is invalid (code 28f96a31d)

```
1440/390 light/dark: saveDisabledAt100=true, saveDisabledAt3=false, overflow 0
```

## Update: Save all lines refuses an invalid bottles number (code 3f6596ba1)

```
1440/390 light/dark: writes 0, message "Demo Paracetamol syrup: enter whole bottles, 1 to 99, before saving. Nothing was saved.", overflow 0
```
