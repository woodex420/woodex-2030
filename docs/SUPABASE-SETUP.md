# What to send me from Supabase

You have a different project than the one in the repo. Everything below takes ~5 minutes, and everything
I need is copy-paste. **Nothing here requires you to send a secret key except the anon key, which is public
by design.**

---

## Step 1 — Tell me which project it is (5 seconds)

Open your Supabase dashboard. The URL contains the project reference:

```
https://supabase.com/dashboard/project/abcdefghijklmnopqrst/sql/new
                                      └────── this is the ref ──────┘
```

Send me just that ref (or the whole URL). **Then I can build every link below with your ref filled in, so
you don't have to hunt through menus.**

---

## Step 2 — The two values that make the app work (30 seconds)

**Link:** `https://supabase.com/dashboard/project/<REF>/settings/api`
*(Dashboard → Project Settings → API)*

| Copy this | Where on the page | Safe to share? |
|---|---|---|
| **Project URL** — `https://<ref>.supabase.co` | top of the page | ✅ yes |
| **anon / public** key (a long `eyJ…` string) | under *Project API keys* → `anon` `public` | ✅ yes, it ships in the browser anyway |
| ~~`service_role` key~~ | same section | ❌ **never** — it bypasses all security |

If the *Project URL* has no `https://`, add it.

---

## Step 3 — Run one SQL script and paste the result (2 minutes)

**Link:** `https://supabase.com/dashboard/project/<REF>/sql/new`
*(Dashboard → SQL Editor → New query)*

Paste the whole of **`DATA-REQUEST.sql`** (in this same folder), press **Run**, and paste back the results.
It is read-only — no writes, no deletes — and it deliberately excludes customer names, emails and phone
numbers, so you are not sending me personal data.

It returns, in order:

1. **Every table with its row count** ← the single most valuable result
2. Which migrations have been applied (reveals drift from the repo)
3. RLS status per table, and any table with RLS on but *no policy* (returns nothing, silently)
4. Storage buckets
5. Actual columns of the 19 tables the dashboard reads most
6. Users per role + whether `user_permissions` is populated
7. The real date range of orders/quotations/analytics

---

## Step 4 — Two things SQL can't give me (1 minute)

| Link | What I need |
|---|---|
| `https://supabase.com/dashboard/project/<REF>/functions` | Screenshot or list of **deployed** edge functions. The repo has 32 defined; I need to know which actually exist (the plan says 29 have never been called). |
| `https://supabase.com/dashboard/project/<REF>/storage/buckets` | Names + public/private of each bucket, so uploads and image URLs match reality. |

---

## Step 5 — Optional, only if you want the types to match exactly

If some tables have columns the repo's migrations don't mention, send me a schema export:

```sh
supabase db dump --schema public -f my-schema.sql     # structure only, no data
```

Or in the SQL editor:

```sql
select table_name, column_name, data_type, is_nullable, column_default
from information_schema.columns
where table_schema = 'public'
order by table_name, ordinal_position;
```

---

## Paste-back template

Copy this, fill it in, send it. Anything you don't have, leave blank.

```
PROJECT REF:           

PROJECT URL:           https://____________.supabase.co
ANON KEY:              eyJ____________ (starts with eyJ, ~200 chars)

-- paste results of DATA-REQUEST.sql below --
Q1 row counts:
Q2 applied migrations:
Q3 RLS gaps:
Q4 buckets:
Q5 columns (optional):
Q6 roles / user_permissions:
Q7 activity date range:

DEPLOYED EDGE FUNCTIONS (from /functions page):
BUCKETS (names + public/private):
```

---

## What I do with it

| I get | I can then |
|---|---|
| Project URL + anon key | Switch `packages/data` from mock to live — your app runs on real data with **zero code changes** |
| Row counts | Build in order of what is actually used: empty modules drop down the list, busy ones come first |
| Applied migrations | Fix the type definitions against reality and tell you exactly which migrations are missing |
| RLS gaps | Close any table that has RLS enabled but no policy — those return empty results and look like bugs |
| Buckets + functions | Wire uploads and the quotation PDF / WhatsApp pipelines for real instead of stubbing them |
| Roles + `user_permissions` | Build the RBAC phase on your actual roles rather than the six I inferred |

---

## Two things to know

**1. I still can't reach Supabase from this sandbox** — `api.supabase.com` and `*.supabase.co` are both
network-blocked here. That means:

- Live mode will work **on your machine and your deployment** (I'll wire it and tell you exactly how to test)
- But I can't verify it against your database from here. If you want that, the network path needs opening —
  otherwise I work from whatever you paste, which is completely workable.

**2. Please rotate the old token.** The `sbp_…` token you pasted earlier is account-scoped and should be
revoked at `https://supabase.com/dashboard/account/tokens`. Also still outstanding from the audit: the real
Stripe secret keys (`sk_test`/`sk_live`) sitting in `docs/CREDENTIALS.md` in that **public** repo, and the
tracked `.env` file.
