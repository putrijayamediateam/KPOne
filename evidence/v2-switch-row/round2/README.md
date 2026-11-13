# V2SwitchRow round 2 (Daniel's checks)

focus-light.png and focus-dark.png: the first switch focused with the keyboard (Tab): the ring with its offset.
Accessibility snapshot of the first row: the 'Off' word is [aria-hidden]; the switch is named 'Require date of birth' (the help line is its description).

```
light: focus ring vs card 6.43:1 (ring (10, 86, 209), card (255, 255, 255))
light: help text vs card 7.61:1
light: On/Off word vs card 17.39:1
dark: focus ring vs card 6.69:1 (ring (107, 166, 255), card (28, 31, 39))
dark: help text vs card 8.30:1
dark: On/Off word vs card 15.10:1
```

Ring: 6.43:1 light, 6.69:1 dark against the card (3:1 needed). Help text 7.61:1 light and 8.30:1 dark (4.5:1 needed). On/Off word 17.39:1 and 15.10:1.
At 320 the label wraps to four lines in the specimen; accepted by Daniel (drop the word below the switch under 360 px if real labels make it worse).
