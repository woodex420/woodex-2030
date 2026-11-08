# Performance

## Targets
| Metric | Target |
|---|---|
| Lighthouse Performance | ≥ 90 |
| LCP | < 2.5s |
| CLS | < 0.1 |
| INP | < 200ms |
| Total JS (compressed) | < 250 KB initial route |
| Image weight per page | < 1.2 MB |

## Images
- Use `.jpg` for photos, `.png` only for transparent assets.
- Serve hero at ≤ 1920px wide, quality ~80.
- Add `loading="lazy"` to below-the-fold images (default in `<img>`).
- Add `fetchpriority="high"` to hero LCP image.
- Prefer AVIF/WebP conversion `[planned]`.

## Code Splitting `[planned]`
- Lazy-load: `VirtualShowroom`, `CustomDesign`, `Blog`, `ProductDetail`.
- Route-based `React.lazy` + `<Suspense fallback>`.

## Fonts
- Inter via Google Fonts, preconnect + `display=swap`.
- Only load weights actually used (300, 400, 500, 600, 700, 800).

## Third-party
- No third-party scripts until GA4 + Meta Pixel are added; both async, deferred.

## Monitoring
- Lovable performance panel + PageSpeed Insights weekly.
- Real-user monitoring `[planned]` via GA4 web vitals event.
