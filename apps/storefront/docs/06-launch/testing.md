# Testing

## Manual QA Matrix
| Area | Cases |
|---|---|
| Nav | Mega-menus open/close, mobile drawer, all links resolve |
| Home | Slider autoplay + arrows + dots, section anchors |
| Shop | Filters apply, pagination, empty state |
| PDP | Gallery, zoom, 360, variants, Add-to-Cart, Add-to-Quote |
| Cart | Slide-out open, qty change, remove, subtotal, checkout link |
| Quote basket | Add/remove, 75% advance calc, PDF download, WhatsApp share |
| Quotation form | Validation, success toast, WhatsApp handoff |
| Contact | Validation, success state |
| Blog | List → detail, related links |
| Services | List → detail, FAQ accordion |
| Custom Design | Sections render, CTAs route to quotation |
| Virtual Showroom | Hotspots clickable → add to quote |
| 404 | Unknown URL renders NotFound |

## Cross-browser
Chrome, Safari, Firefox, Edge (latest 2 versions). iOS Safari 16+, Android Chrome.

## Devices
- Mobile: 375 (iPhone SE), 390 (iPhone 14), 412 (Pixel).
- Tablet: 768, 820.
- Desktop: 1280, 1440, 1920.

## Accessibility
- Keyboard-only pass on nav, forms, cart, quote basket.
- Screen reader smoke test on Home + PDP + Contact.
- Axe DevTools: 0 critical issues per page.

## Automated `[planned]`
- Playwright smoke suite: home renders, PDP loads, quote add + submit, contact submit, checkout submit.
- Vitest for utility functions (price calc, WhatsApp URL builder).
