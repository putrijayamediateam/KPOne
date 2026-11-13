# V2Stat round 2 (feature/v2-stat)

Specimen with the real minus sign (−RM 20.00, chip 'Short'), an unavailable value in words ('Not available' with a 'To confirm' chip) and a long value with a tone (RM 1,234,567.89 with a chip) at 320: the chip drops below the number and the number stays on one line (measured height 32 px for every value at 320, 390 and 1440).

Accessibility tree of the second card: group "Cash counted, RM 1,240.00, Matches, Matches expected" (label, value, status, detail).

Chip contrast measured from pixels at 2x (text against the chip background, 4.5:1 needed; the dot needs 3:1). Neutral has no chip.

```
light | Matches | text 17.39:1 | dot 6.63:1
light | Near limit | text 17.39:1 | dot 7.78:1
light | Short | text 17.39:1 | dot 6.54:1
light | To confirm | text 17.39:1 | dot 7.78:1
light | Up on last year | text 17.39:1 | dot 6.63:1
dark | Matches | text 15.10:1 | dot 9.71:1
dark | Near limit | text 15.10:1 | dot 7.72:1
dark | Short | text 15.10:1 | dot 7.22:1
dark | To confirm | text 15.10:1 | dot 7.72:1
dark | Up on last year | text 15.10:1 | dot 9.71:1
```

The chip text is the foreground colour in every tone, so it is the same in each row; the dot carries the tone.
