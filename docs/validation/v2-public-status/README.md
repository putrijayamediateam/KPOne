# V2 public QR Status (PR #807) evidence

Synthetic props, six states (pending, review, accepted, next/otc, called, rejected) at 1440, 390 and 320 px, light and dark.
No sideways scroll, no staff-shell text, no storage, no external requests (the script prints nothing when clean).
Classic shots with the switch off: 34 of 36 pixel-identical to `main`; the two differences (called, 390, light and dark) are one 26x26 px area, the banner's pulsing icon.

## Update after Daniel's review (head of feature/v2-public-status)
- Branch addresses wrap onto two lines at 390 and 320; "Buka peta" is a 44 px link.
- Semak status shows "Menyemak…" while checking.
- Switch-OFF compare with reduced motion emulated and CSS animations frozen in both builds (main vs branch Classic, called and accepted, 390 and 320, light and dark): 8 compared, 0 differing pixels.
- The V2 pulse uses motion-safe:animate-pulse, so it stops under prefers-reduced-motion.
