# Storefront routes

Generated from the code at commit `9b8cdca` by `docs/furniture-2030/evidence/generate-inventory.mjs`. Do not edit by hand; re-run the script.

**25 routes** declared in `apps/storefront/src/App.tsx`; **16 static public routes** listed in `apps/api/public-routes.mjs` feed the sitemap and the prerender (product pages are added from the catalogue).

| Route | Page component | In sitemap list | Title used for search engines |
|---|---|---|---|
| `/` | Index | yes | WOODEX — Premium Office Furniture in Lahore |
| `/shop` | Shop | yes | Shop Office Furniture |
| `/shop/:productId` | ProductDetail |  |  |
| `/room-packages` | RoomPackages | yes | Room Packages |
| `/virtual-showroom` | VirtualShowroom | yes | Virtual Showroom |
| `/series` | Series | yes | Collections by Series |
| `/series/:seriesId` | SeriesDetail |  |  |
| `/projects` | Projects | yes | Completed Projects |
| `/projects/:projectId` | ProjectDetail |  |  |
| `/services` | Services | yes | Design & Fit-out Services |
| `/services/:slug` | ServiceDetail |  |  |
| `/b2b` | B2B | yes | B2B & Bulk Orders |
| `/about` | About | yes | About WOODEX |
| `/contact` | Contact | yes | Contact & Showrooms |
| `/custom-design` | CustomDesign | yes | Custom Design |
| `/quotation` | Quotation | yes | Request a Quotation |
| `/showrooms` | Showrooms | yes | Showrooms |
| `/materials` | Materials | yes | Materials & Finishes |
| `/warranty` | Warranty | yes | Warranty & Care |
| `/blog` | Blog | yes | Workspace Journal |
| `/blog/:postId` | BlogPost |  |  |
| `/checkout` | Checkout |  |  |
| `/order-status` | OrderStatus |  |  |
| `/p/:slug` | PublishedPage |  |  |
| `*` | NotFound |  |  |
