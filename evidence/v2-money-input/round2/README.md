# V2MoneyInput round 2 (Daniel)

After-blur shots (money-after-blur-<width>-<theme>.png): 390 dark, 1440 light, plus 390 light, 1440 dark and 320 light. Values after blur: empty stays empty; 0.00 stays 0.00; typed 12 becomes 12.00; typed abc keeps 'abc', aria-invalid true and the alert 'Enter an amount such as 12.50.'; pasted 1,25 is refused the same way (no guessing); the server error shows in red.

ArrowUp, ArrowDown and the mouse wheel on the box leave the amount unchanged (12.00 stayed 12.00): it is a text box.

Contrast measured from pixels at 2x (4.5:1 needed; a disabled control is exempt but reads as disabled):
```
1440-light: RM prefix (enabled) 6.54:1 | hint text 7.61:1 | RM prefix (disabled box, half opacity, exempt) 2.39:1 | disabled hint 7.61:1
390-dark: RM prefix (enabled) 7.22:1 | hint text 8.30:1 | RM prefix (disabled box, half opacity, exempt) 3.13:1 | disabled hint 8.30:1
1440-dark: RM prefix (enabled) 7.22:1 | hint text 8.30:1 | RM prefix (disabled box, half opacity, exempt) 3.13:1 | disabled hint 8.30:1
390-light: RM prefix (enabled) 6.54:1 | hint text 7.61:1 | RM prefix (disabled box, half opacity, exempt) 2.39:1 | disabled hint 7.61:1
```

Paste rules (unit tests): RM prefix and thousands commas only (1,250.50 becomes 1250.50). Refused: 1,25 / 1.250,50 / 1.234 / -5 / abc / ,5 / 1,2345 / 1,234,56 / 12,50 / 1 250 / RM 1 250 / 1,250.505 / 1,2,3.
