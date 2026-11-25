# Cloudflare removed from the panel

## What was changed in this folder

- `cfg.php`: the Turnstile site key and secret are now empty. `turnstile_enabled()`
  is therefore false everywhere, so login, register and "add process" no longer
  load a widget or require a token. This fixes Turnstile error **110200**
  ("Domain not authorized"), which happened because the widget used here was not
  configured for `ffgoatpro.online`.
- `includes/auth.php`: Cloudflare origins were removed from the Content-Security
  Policy, and the optional `remoteip` field was dropped from the siteverify call
  (it can mismatch when a proxy hides the visitor address). The Turnstile
  functions are still present but inert; nothing is downloaded from Cloudflare.
- `includes/db.php`: the owner login/password on a fresh install and on an
  existing install (`karim`, or older `admin/admin`) is now **vinox / vinox**.
  On an existing database this is applied by the migration the next time any
  page opens, provided the owner row is still named `karim` or `admin`.

## What still has to be done in the Cloudflare dashboard

The "Just a moment..." page in front of the whole site is a **zone setting**,
not something PHP can switch off. Pick one:

### 1. Take the site off Cloudflare completely (what was asked for)

In Cloudflare → DNS → Records, change the `ffgoatpro.online` (and `www`, if it
exists) record from **Proxied** (orange cloud) to **DNS only** (grey cloud).
Point it directly at the Render origin (a CNAME to the `*.onrender.com` hostname
works). Render issues and renews the HTTPS certificate. Once DNS has settled,
requests will no longer carry `Server: cloudflare` or `Cf-Mitigated: challenge`.

### 2. Keep Cloudflare proxy but stop the challenge

- Security → Settings: set **Security Level** to Low or Essentially off and make
  sure **Under Attack Mode** is off.
- Security → WAF → Custom rules / Rate limiting: disable or delete rules whose
  action is *Managed Challenge*, *Interactive Challenge* or *Block*.
- Security → Bots: turn off **Bot Fight Mode** (or change the rule action from
  Managed Challenge to Allow).
- Zero Trust → Access: disable the Access application for this hostname, or add
  a policy that allows everyone.

### Check the result

```sh
curl -I https://ffgoatpro.online/login
```

There should be no `Cf-Mitigated: challenge` header and no `Server: cloudflare`
if the DNS-only option was chosen.

## Credentials

Log in with username `vinox` and password `vinox`. The panel's own password form
requires at least 6 characters, so `vinox` (5) cannot be re-entered there —
change it to a longer password after the first login.

If the owner row on the live database has already been renamed to something
other than `karim`/`admin`, the migration will not touch it; rename it from the
panel's Users page or directly in the database.
