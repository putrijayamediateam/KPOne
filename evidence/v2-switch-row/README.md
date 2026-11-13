# V2SwitchRow specimen (feature/v2-switch-row)

Four rows (off, on, disabled with its reason, a long label and help line) at 320, 390 light and dark, 1440 light and dark. No sideways scroll; the switch is 56x44 px below 1024 px and 56x36 px from 1024 px (measured).

Behaviour checked in a browser at 390: click toggles, Space toggles, Enter toggles, a click on the label toggles; a click on the disabled switch does nothing. Accessibility snapshot: switch named by its label.

Contrast measured from pixels at 2x (3:1 needed for the control boundary and the thumb):
```
light switch 1 (off): track edge vs card 3.80:1 | thumb vs track fill 6.03:1
light switch 2 (on): track edge vs card 6.43:1 | thumb vs track fill 6.43:1
light switch 4 (off): track edge vs card 3.80:1 | thumb vs track fill 6.03:1
dark switch 1 (off): track edge vs card 5.09:1 | thumb vs track fill 7.23:1
dark switch 2 (on): track edge vs card 6.69:1 | thumb vs track fill 7.05:1
dark switch 4 (off): track edge vs card 5.09:1 | thumb vs track fill 7.23:1
```
The disabled switch is at half opacity (exempt); its reason replaces the help line in words. Not measured: the On/Off word and help-line text (they use the standard foreground and muted-foreground tokens).
