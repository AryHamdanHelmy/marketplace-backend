# RAPAKU — Session Handoff

**Last updated:** 8 September 2026
**Repos:** `AryHamdanHelmy/marketplace-frontend` · `AryHamdanHelmy/marketplace-backend`
**Live:** `rapaku.vercel.app` (frontend) · Railway (backend)

---

## Brand identity

Name comes from Indonesian *lapakku* ("my stall") said with a Japanese accent: l → r, double consonant drops. **ra-pa-ku** `/rah-PAH-koo/`.

Mark: a torii gate whose right pillar is cut short and given a diagonal leg, forming an **R**. The counter inside the bowl is the gateway — never fill it.

**Tokens** (live in `src/style.css`):

| Token | Hex | Use |
|---|---|---|
| `primary` (shu) | `#DE4B2E` | primary actions, prices, badges |
| `primaryHover` | `#C23D22` | hover |
| `primaryDark` | `#A33119` | pressed / deep fills |
| `accent` (ai) | `#22405C` | links, info, secondary |
| `background` (washi) | `#F7F2EA` | page background |
| `surface` | `#FFFFFF` | cards |
| `textPrimary` (sumi) | `#171412` | body text |
| `danger` | `#9E2B25` | destructive — deliberately cooler than shu |
| `ink-50…900` | warm neutral ramp | migration target for `gray-*` |

Typeface: **Plus Jakarta Sans** (made in Jakarta — ties to the Indonesian root). Weights 400 / 600 / 700.

Voice: English UI, Indonesian locale formatting (`Rp 125.000`, `12 Mar 2026`). Say "sellers" not "merchants". Taglines: **"Open your shop"** / **"One gate. Every shop."**

---

## Frontend — done

- `src/style.css` — full Rapaku token system, replaced the PayPal-blue palette
- `src/pages/Auth.jsx` — single-screen sign in, no chrome, OAuth buttons behind `VITE_OAUTH_ENABLED`
- `src/pages/ForgotPassword.jsx` — form + sent state + 60s resend cooldown
- `src/pages/ResetPassword.jsx` — three states: invalid link / form / success
- `src/pages/Register.jsx` — two genuine steps (details → buyer or seller), password strength meter
- `src/pages/AddProduct.jsx` — browser-side image downscaling to 1600px, field errors surfaced, ghost classes fixed
- `src/App.jsx` — split into `MainLayout` (navbar + footer) and bare auth routes
- `src/api/Client.js` — fixed `throw new Error(message)` → `throw error;` so `err.errors` survives
- Deleted dead Vite boilerplate: `src/index.css`, `src/App.css`

---

## Backend — done

**Migrations run:**
- `password_reset_tokens` — was missing entirely from the customised users migration
- `stores` — shop profile, open/closed, payout account
- `withdrawals` — payout requests with account snapshot
- `shipped_at` / `completed_at` / `completed_by` on `transactions`

**Models:** `Store`, `SellerBalance`, `BalanceLog`, `Withdrawal`
`User` — fixed `softDeletes` → `SoftDeletes` (lowercase breaks on Linux), removed phantom `remember_token` / `email_verified_at`

**Services:** `BalanceService` — all balance mutations go through here, locked and idempotent

**Controllers:**
- `PasswordResetController` — uses Laravel's password broker
- `SellerStatsController` — sales, orders, rating, store health, 30-day trend, alerts
- `SellerBalanceController` — balance, ledger history, withdrawal requests
- `AuthController` — releases email on delete (closes account-takeover hole)
- `OrderController` — seller capped at `shipped`, buyer gets `confirmReceipt`

**Command:** `orders:auto-complete` — closes shipped orders after 7 days, credits seller

**Config:** `config/app.php` needs `'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173')`
`AppServiceProvider::boot()` holds `ResetPassword::createUrlUsing()`

---

## Key decisions and why

**Store health is a real formula**, not a decorative number: on-time processing 35%, fulfilment 25%, buyer rating 25%, stock availability 15%. Components without data are dropped and remaining weights rescaled, so new shops aren't punished. Breakdown ships with the score.

**Money is released on `completed`**, reached two ways — buyer taps "Order received", or the 7-day window expires. Sellers can no longer set `completed` themselves; that was an open door to withdrawing before delivery.

**Balance is debited when a withdrawal is requested**, not when approved — otherwise one balance funds many simultaneous requests. Consequence: rejecting a withdrawal must credit the money back.

**Deleted accounts release their email** (`deleted+12+ary@gmail.com`) rather than being restored on re-registration. Frees the address without letting anyone inherit the old account's order history.

**`change_percent` returns `null`** when the previous period was zero — "+100%" from nothing is misleading.

---

## Open issues

**Blocking the balance feature:**
1. **No admin side for withdrawals** — nothing can approve, complete, or reject. A rejection must call `BalanceService::credit()` or the seller's money is stuck.
2. **No store profile form** — sellers can only set bank details through tinker, so every withdrawal request currently fails.

**Known bugs:**
3. `SQLSTATE[HY093] Invalid parameter number` on `DELETE FROM cart_items WHERE id = ?` — code looks correct, needs a full stack trace from `storage/logs/laravel.log`
4. `config/database.php` has a **duplicate `'options'` key** in the `mysql` block — harmless but should be cleaned

**Deferred:**
5. ~150 ghost Tailwind classes across 17 files — being fixed manually. Map: `pastel-blue`→`primary`, `pastel-cyan`→`primaryHover`, `pastel-green`/`pastelgreen`→`success`, `darkblue`→`textPrimary`. Replace the **colour name only** so `/15` opacity and `hover:` prefixes carry over.
6. `favicon.svg` still the purple Vite logo; `index.html` title still `marketplace-frontend`
7. `Button.jsx` — `secondary` variant is `text-white` on `bg-primary/10`, invisible
8. Navbar says both "Seller Centre" and "Seller Center"
9. `SellerStatsController::storeHealth()` measures on-time using `paid_at → updated_at`; should use `shipped_at` now that it exists
10. `/terms` and `/privacy` routes referenced by Register but may not exist
11. `EmailStep.jsx` / `PasswordStep.jsx` now unused — safe to delete once login is proven
12. Seller routes rely on per-controller role checks; a `role:seller` middleware would be safer
13. Bank account numbers stored unencrypted
14. OAuth needs Laravel Socialite on the backend. **Recommend Google only** — Apple requires the $99/yr developer program

---

## Production checklist (Railway)

```
FRONTEND_URL=https://rapaku.vercel.app
MAIL_MAILER=smtp        # currently log — reset emails never reach real inboxes
```

- Run `php artisan migrate --force`
- Deploy command should include `migrate --force && config:cache`
- Cron service needed: `* * * * *` running `php artisan schedule:run`, otherwise `orders:auto-complete` never fires
- PHP `upload_max_filesize` defaults to 2M — matters for any upload path that doesn't downscale client-side

---

## Next up

Two candidates, both unblock the balance feature:

- **Store profile page** — lets sellers set shop name, logo, location, open/closed, and payout account
- **Admin withdrawals page** — approve, complete, reject with balance refund

Then: `SellerDashboard.jsx` rebuild, wiring `/seller/stats` and `/seller/balance` into the mockup layout.

---

## Working preferences

- Mobile-first, always
- One file per message, never ZIPs
- Windows / PowerShell