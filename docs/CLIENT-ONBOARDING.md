# Onboarding a new client (club)

One codebase, one installation per club. A client is:

| Per client | Shared |
|---|---|
| domain + Websupport hosting (SFTP) | the code in this repo |
| managed Postgres database | migrations (run per database on every deploy) |
| sending mailbox (SMTP account) | e-mail templates (the club name is a setting) |
| `.deploy-secrets.<slug>` file (gitignored) | `scripts/deploy-rezervacie.sh` |
| `site_config` settings row + logo, edited by the club's admin | everything else |

Nothing about a club lives in the code (SITE-004 in
`docs/spec/13-site-configuration.md`). If you find yourself typing a club's
name, e-mail or URL into a `.vue`/`.php` file, stop: add a field to
`App\Services\SiteConfig` instead (see AGENTS.md → *One codebase, many
clients*).

## Checklist

### 1. Infrastructure (outside this repo)

- Websupport (or equivalent) hosting with PHP 8.3+, SFTP access, the
  subdomain's docroot layout — see `backend-php/deploy/REZERVACIE-LODENICAKVS.md`
  for the all-in-docroot layout.
- Managed Postgres 14+ with the `btree_gist` and `pgcrypto` extensions
  available (the first migration enables them).
- A mailbox the app may send from (SMTP host/port/user/password), with SPF
  (and ideally DMARC) on the club's domain.
- DNS for the subdomain pointing at the hosting.

### 2. The secrets file

```bash
cp .deploy-secrets.example .deploy-secrets.<slug>
chmod 600 .deploy-secrets.<slug>
$EDITOR .deploy-secrets.<slug>
```

Fill in:

- `DEPLOY_SFTP_*`, `DEPLOY_LARAVEL_APP_REMOTE`, `DEPLOY_DOCROOT_REMOTE`
  (discover with `scripts/deploy-rezervacie.sh --client <slug> --explore`)
- `PROD_DB_*`, `PROD_DOMAIN`
- `SITE_NAME` (**required**) and the rest of the `SITE_*` block: short name,
  club name, contact e-mail, address, links to the club's rulebook / GDPR
  documents / statutes, the operator sentence for the registration form,
  the member-ID example, module switches (`SITE_FEATURE_TRAFFIC_LIGHT` is
  only for Danube clubs), default theme
- `ADMIN_EMAIL` + `ADMIN_PASSWORD` — the first admin (**required for the
  first install**; ignored afterwards)
- `MAIL_USERNAME`, `MAIL_FROM_ADDRESS`, `MAIL_PASSWORD` — the sending mailbox
- optionally `SITE_LOGO_FILE` → put the club's square logo (png/jpg/webp,
  ≤ 2 MB) under `branding/<slug>/` and point at it

Every `SITE_*` value is only the install-time default; the club's admin can
change all of them later in the app.

### 3. First deploy

```bash
# VPN off — port 22 must be reachable.
scripts/deploy-rezervacie.sh --client <slug>
```

The script builds, uploads, then runs `migrate`, `db:seed` and the cache
rebuild on the server. On a fresh database the seed creates the admin
from `ADMIN_*`, the default content pages (rules, FAQ, privacy) with the
club's name substituted, and **no demo boats**. If `ADMIN_*` is missing the
seed step fails on purpose — add the values and re-run.

Persist the printed `DEPLOY_APP_KEY` into the secrets file so later
deploys keep the same key.

### 4. First login

1. Open `https://<domain>/`, log in as `ADMIN_EMAIL`, change the password
   in *Môj profil*.
2. *Administrácia → Systém → Nastavenia stránky*: check names, contacts,
   links, modules, theme; upload the logo if it did not come with the
   deploy.
3. *Informácie*: read the generated *Pravidlá rezervácie*, *Otázky a
   odpovede* and the privacy page; edit them in place.
4. *Administrácia → Správa → Číselník členov*: import the member roster
   (CSV) so self-registrations auto-approve; or invite members from
   *Používatelia*.
5. *Administrácia → Systém → Diagnostika e-mailov*: send a test e-mail.
6. Add the boats under *Lode*.

### 5. CI deploys (optional)

Create a GitHub Environment named after the client with the same
`REZERVACIE_*` variables/secrets the KVŠ environment uses (list at the top
of `.github/workflows/deploy-rezervacie.yml`, including
`REZERVACIE_SITE_NAME`). Run *Deploy a client site* with
`environment=<name>`.

## Releasing to every client

```bash
scripts/deploy-all.sh            # lists the clients, asks, deploys one by one
scripts/deploy-all.sh --only kvs # a subset
```

Each client's database gets exactly the migrations it has not run yet.
Write migrations additively (AGENTS.md); a migration that would break one
client breaks the rollout for the rest, which is the point.

## Removing a client

Delete the hosting, database and mailbox with the provider; delete
`.deploy-secrets.<slug>`, `branding/<slug>/` and the GitHub Environment.
Nothing in the code changes.
