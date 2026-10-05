WHERE EACH FILE GOES (both "replace" — no database migration needed)
========================================================================
  frontend/admin/purchases.html    -> replace
  frontend/admin/admin-purchases.js -> replace

backend/api/purchases.php is UNCHANGED this round — keep the one from
round7 (the CSV column-alias fix).

WHAT CHANGED
============
Each row in "Stock entry history" now has a "View" button. Clicking it
opens a breakdown modal showing:
  - Date, source (Manual/Excel), item count, who recorded it, and any notes
  - Every single product in that purchase — not just the first 3 that
    were previously shown truncated in the table — with Quantity, Buy
    Price, and a calculated Line Total per product
  - The overall Total Cost at the bottom

This is read-only (a breakdown/summary view), so it works for both old
and new purchase records without needing any database changes.
