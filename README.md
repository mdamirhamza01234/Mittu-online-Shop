# Mittu Online Shop

Single-file customer shop (`index.html`) — JioMart-style. Host on GitHub Pages.

- Catalogue (269 items) comes from the inventory PDF; **price + stock are live** from Firebase `publicShop/stock`,
  published by the billing app (Countam-calculator-) whenever the Stock Record is saved.
- Customer orders are written to Firebase `shopOrders/`.
- `database.rules.json`: merge these into Firebase Console -> Realtime Database -> Rules
  (keep your existing rules for `users/`, `currentBill`, `customerRequest`, `staffOrders`).
- Optional product photo: set `imageUrl` (https) on a stock item; the shop shows it instead of the emoji.
