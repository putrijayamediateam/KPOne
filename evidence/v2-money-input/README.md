# V2MoneyInput specimen (feature/v2-money-input)

Six fields (required with a hint, "free" 0.00, typed 12, typed abc, a server error, disabled) at 320, 390 light and dark and 1440 light; no sideways scroll; the box is 44 px tall below 1024 px and 36 px from it (measured).
Behaviour checked in a browser at 390 (the 390 light shot is after these blurs):
- an empty box stays empty after blur (never "0.00"); cleared again stays empty
- 0 becomes 0.00 and stays an amount; "1,234.5" becomes 1234.50; ".5" becomes 0.50; 12 becomes 12.00
- "abc" keeps what was typed and shows "Enter an amount such as 12.50." (role alert) under the field; the textbox is invalid
- inputmode is decimal; the spoken description is "Amount in ringgit (RM) | <hint>"
The static shots of "Typed 12" and "Typed abc" are the states before blur. Not measured: text contrast of the RM prefix and hint (standard muted-foreground).
