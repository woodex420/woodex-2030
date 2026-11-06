# Arena-local preview notes

This directory contains the full project tree from `blackibexofficial-blip/woodex-reimagined`, `main` at commit `e97d69f10e6537c84712d9c97abd52b50cd29015`. The user confirmed they are a Woodex founder and authorized complete reuse. The upstream repository was not modified; `.git`, dependency folders, build output and credentials were not copied.

## Local-only environment adjustments

- `vite.config.ts` binds to `0.0.0.0:5175`, requires that port, and allows Arena's dynamic preview host. The existing Agency OS preview mapping remains Dashboard `:3001`, API `:3002`, Storefront `:5174`.
- 117 package-lock tarball URLs that pointed to an inaccessible internal npm mirror were changed to the equivalent `registry.npmjs.org` URLs. Package versions and integrity hashes were preserved. Dependencies installed with `npm ci --registry=https://registry.npmjs.org`.

## Run and verification

```sh
npm run dev -- --host 0.0.0.0 --port 5175 --strictPort
```

- `npm run build`: passes after fixing the malformed dynamic rotate class, moving the font import before Tailwind directives, and code-splitting page routes. Only the stale Browserslist-data warning remains; the largest initial app chunk is now below 500 kB.
- `npm run lint`: passes with 10 warnings and no errors. Local lint cleanup replaces explicit `any`/CommonJS patterns and empty interfaces, and excludes the generated MCP bundle.
- `npx tsc --noEmit -p tsconfig.app.json`: passes.
- `npm ci` reported 31 dependency vulnerabilities (10 moderate, 21 high). No automatic audit fixes were applied.
- HTTP route smoke checks returned `200` for the principal reference routes and Vite transforms. Browser-level interaction testing remains blocked because no browser binary is installed.

This is an isolated preview, not a production deployment or a replacement of the Agency OS storefront.
