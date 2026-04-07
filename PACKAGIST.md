# Publishing to Packagist

This document walks you through listing **orderlyconnect/astm-e1394-91**
on [Packagist](https://packagist.org) and wiring up the GitHub repository.

---

## Step 1 — Create the GitHub repository

1. Go to https://github.com/new
2. **Repository name:** `astm-e1394-91`
3. **Owner:** `OrderlyConnect`
4. Set to **Public** (Packagist requires a publicly accessible repository)
5. **Do not** initialise with README, .gitignore, or licence — we have them already
6. Click **Create repository**

---

## Step 2 — Push your code

```bash
cd /path/to/astm-parser

git init
git add .
git commit -m "feat: initial release v1.0.0"

git remote add origin https://github.com/OrderlyConnect/astm-e1394-91.git
git push -u origin main
```

---

## Step 3 — Tag the first release

Packagist and Composer work on tags. Create a [semver](https://semver.org) tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

---

## Step 4 — Register on Packagist

1. Create a free account at https://packagist.org/register/
2. Click **Submit** in the top navigation
3. Paste your repository URL:
   ```
   https://github.com/OrderlyConnect/astm-e1394-91
   ```
4. Click **Check** — Packagist will detect your `composer.json`
5. Click **Submit** to publish

Your package will be available immediately as:

```bash
composer require orderlyconnect/astm-e1394-91
```

---

## Step 5 — Connect the GitHub webhook (auto-updates)

Without a webhook, Packagist will only update your package when you
trigger it manually.  The webhook keeps it in sync automatically.

### Option A — Packagist GitHub App (recommended)

1. Go to https://packagist.org/profile/ → **GitHub OAuth**
2. Authorise Packagist to access your repositories
3. Packagist will install the webhook automatically

### Option B — Manual webhook

1. In your GitHub repo, go to **Settings → Webhooks → Add webhook**
2. **Payload URL:**
   ```
   https://packagist.org/api/github?username=YOUR_PACKAGIST_USERNAME
   ```
3. **Content type:** `application/json`
4. **Secret:** your Packagist API token (from https://packagist.org/profile/)
5. **Events:** select **Just the push event**
6. Click **Add webhook**

---

## Step 6 — Create releases on GitHub

When you're ready to ship a new version:

```bash
# Bump version in CHANGELOG.md, then:
git add CHANGELOG.md
git commit -m "chore: release v1.0.1"
git tag v1.0.1
git push origin main --tags
```

The `release.yml` GitHub Actions workflow will automatically create a
GitHub Release with generated release notes.

Packagist will pick up the new tag via the webhook within seconds.

---

## Step 7 — Add Packagist badges to README

Replace the placeholder badge URLs in `README.md` with your real ones once
the package is live:

```markdown
[![Latest Version on Packagist](https://img.shields.io/packagist/v/orderlyconnect/astm-e1394-91.svg)](https://packagist.org/packages/orderlyconnect/astm-e1394-91)
[![Total Downloads](https://img.shields.io/packagist/dt/orderlyconnect/astm-e1394-91.svg)](https://packagist.org/packages/orderlyconnect/astm-e1394-91)
[![PHP Version](https://img.shields.io/packagist/php-v/orderlyconnect/astm-e1394-91.svg)](https://packagist.org/packages/orderlyconnect/astm-e1394-91)
```

---

## Version numbering

Follow [Semantic Versioning](https://semver.org):

| Change | Version bump |
|---|---|
| Bug fix, no API change | Patch: `1.0.0` → `1.0.1` |
| New feature, backward-compatible | Minor: `1.0.0` → `1.1.0` |
| Breaking change | Major: `1.0.0` → `2.0.0` |

---

## Branch strategy

| Branch | Purpose |
|---|---|
| `main` | Stable, always releasable |
| `develop` | Integration branch for next release |
| `feature/*` | Individual feature branches |
| `hotfix/*` | Emergency bug fixes off `main` |

---

## Files required by Packagist

| File | Status |
|---|---|
| `composer.json` with `name`, `description`, `license`, `autoload` | ✅ Present |
| `LICENSE` | ✅ Present |
| `README.md` | ✅ Present |
| At least one git tag | Create in Step 3 |

---

## Checklist before first publish

- [ ] `composer validate --strict` passes
- [ ] `composer test` passes (167 tests green)
- [ ] `composer.json` has correct `name: "orderlyconnect/astm-e1394-91"`
- [ ] Repository is public on GitHub
- [ ] At least one semver tag pushed (`v1.0.0`)
- [ ] Webhook configured (Step 5)
- [ ] CHANGELOG.md updated for `1.0.0`
