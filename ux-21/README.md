# UX-21 Version 2 Clinic management hub, after Daniel's changes (never merge)

Full-page shots, device scale 1, with "Try the new layout" on. Taken as the Director at 390, 768, 1024 and 1440 px, and
as the CA at 390 and 1440 px, in light and dark.

What changed in V2 only. Classic's grouping and its tests are unchanged.
- **KPOne HR card:** the /hr pages now have their own card, after "Staff & branches", with short names such as
  "Roster" and "Leave". The full name stays as the row's title and is still matched by search.
  - Director: Staff & branches has 5 pages and KPOne HR has 17.
  - CA: Staff & branches has 2 pages and KPOne HR has 12.
- **Phones (below 640 px):** each card is a closed disclosure, "In-clinic · 11". A tap opens it, and a search opens the
  matching cards. The Director's phone page is now 914 px tall, down from about 5,900 px.
  - `-open.png`: the first card opened.
  - `-search.png`: "leave" gives "2 pages found", with the matching card open.
  - `-empty.png`: "zzzz" gives "No page matches “zzzz”." on screen, and "No pages found" is announced.
- **Cards size to their own content.**

Checks:
- No sideways scroll at any width.
- Each phone toggle's aria-controls points to its list.

Classic compare against a fresh main (56 shots): 54 are identical.
- Workspace: flicker, which matched on a re-shoot.
- Registration, 1440 px light: 61 pixels, one or two colour steps on the rounded corners of the tab count pills
  (anti-aliasing; this change does not touch Registration).
