# branding/

Per-client artwork that is **not** part of the shared application. Nothing
here is bundled into the SPA; a client's logo reaches its installation
either through `SITE_LOGO_FILE` in that client's `.deploy-secrets.<slug>`
(uploaded at deploy time) or by an admin in *Administrácia → Systém →
Nastavenia stránky*.

```
branding/<slug>/logo-192.png   # square PNG/JPG/WebP, ≤ 2 MB
```

The application's own neutral default icon lives in
`frontend/public/favicon.svg`.
