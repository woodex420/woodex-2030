# Routing `[current]`

Router: `react-router-dom` v6 (BrowserRouter). Defined in `src/App.tsx`.

## Route Table

| Path | Component | Type | Indexable |
|---|---|---|---|
| `/` | `Index` | Static | ✅ |
| `/shop` | `Shop` | Static | ✅ |
| `/shop/:productId` | `ProductDetail` | Dynamic | ✅ |
| `/room-packages` | `RoomPackages` | Static | ✅ |
| `/virtual-showroom` | `VirtualShowroom` | Static | ✅ |
| `/series` | `Series` | Static | ✅ |
| `/series/:seriesId` | `SeriesDetail` | Dynamic | ✅ |
| `/projects` | `Projects` | Static | ✅ |
| `/projects/:projectId` | `ProjectDetail` | Dynamic | ✅ |
| `/services` | `Services` | Static | ✅ |
| `/services/:slug` | `ServiceDetail` | Dynamic | ✅ |
| `/custom-design` | `CustomDesign` | Static | ✅ |
| `/b2b` | `B2B` | Static | ✅ |
| `/about` | `About` | Static | ✅ |
| `/contact` | `Contact` | Static | ✅ |
| `/quotation` | `Quotation` | Static | ✅ |
| `/showrooms` | `Showrooms` | Static | ✅ |
| `/materials` | `Materials` | Static | ✅ |
| `/warranty` | `Warranty` | Static | ✅ |
| `/blog` | `Blog` | Static | ✅ |
| `/blog/:postId` | `BlogPost` | Dynamic | ✅ |
| `/checkout` | `Checkout` | Static | ❌ |
| `*` | `NotFound` | 404 | ❌ |

## Providers Order (`App.tsx`)
```text
QueryClientProvider
└── QuoteProvider
    └── CartProvider
        └── TooltipProvider
            └── BrowserRouter
                └── Routes
```

## `[planned]`
- Lazy-load heavy pages via `React.lazy` + `<Suspense>` — target: Services, VirtualShowroom, Blog list.
- Add scroll-restoration wrapper.
- Add breadcrumb component driven by route metadata.
