# 🚀 DevAgent — Railway Deployment Guide

> Estimated time: **~15 minutes** from zero to live backend.

---

## Prerequisites

- [ ] [Railway account](https://railway.app) (free tier works)
- [ ] [Railway CLI](https://docs.railway.app/guides/cli) installed
- [ ] Git repo with this backend code
- [ ] GitHub OAuth App (see Step 3)
- [ ] Anthropic API key from [console.anthropic.com](https://console.anthropic.com)

---

## Step 1 — Push backend to GitHub

Your backend needs to be in a Git repo. If it isn't yet:

```bash
cd your-backend-folder
git init
git add .
git commit -m "Initial DevAgent backend"
gh repo create devagent-backend --private --source=. --push
# Or: git remote add origin git@github.com:yourname/devagent-backend.git && git push -u origin main
```

---

## Step 2 — Create Railway project

### Option A: Via Railway dashboard (easiest)

1. Go to [railway.app/new](https://railway.app/new)
2. Click **"Deploy from GitHub repo"**
3. Select your `devagent-backend` repo
4. Railway auto-detects the `nixpacks.toml` and starts building

### Option B: Via CLI

```bash
npm install -g @railway/cli
railway login
railway init          # Creates new project
railway link          # Links to your repo
railway up            # Deploys
```

---

## Step 3 — Add MySQL database

In your Railway project dashboard:

1. Click **"+ New"** → **"Database"** → **"Add MySQL"**
2. Railway provisions a MySQL 8 instance in ~30 seconds
3. Click the MySQL service → **"Variables"** tab
4. You'll see `MYSQL_HOST`, `MYSQL_PORT`, `MYSQLPASSWORD`, etc.

**Map Railway's MySQL variables to your backend service:**

In your backend service → **Variables** tab, add:

| Variable | Value (click to copy from MySQL service) |
|----------|------------------------------------------|
| `DB_HOST` | `${{MySQL.MYSQL_HOST}}` |
| `DB_PORT` | `${{MySQL.MYSQL_PORT}}` |
| `DB_NAME` | `${{MySQL.MYSQL_DATABASE}}` |
| `DB_USER` | `${{MySQL.MYSQL_USER}}` |
| `DB_PASS` | `${{MySQL.MYSQL_PASSWORD}}` |

> **Tip:** Railway's `${{Service.VARIABLE}}` syntax automatically links variables between services.

---

## Step 4 — Set all environment variables

In your backend service → **Variables** tab, add each of these:

### Required — app won't start without these

```
ANTHROPIC_API_KEY       sk-ant-YOUR_KEY_HERE
ENCRYPTION_KEY          <run: openssl rand -hex 32>
GITHUB_CLIENT_ID        <from Step 5>
GITHUB_CLIENT_SECRET    <from Step 5>
GITHUB_REDIRECT_URI     https://YOUR_DOMAIN.up.railway.app/api/connect-github/callback
ALLOWED_ORIGINS         https://devagent-alpha.vercel.app
APP_URL                 https://YOUR_DOMAIN.up.railway.app
APP_ENV                 production
TRUST_PROXY             true
CLAUDE_MODEL            claude-sonnet-4-6
```

### Generate your ENCRYPTION_KEY right now:
```bash
openssl rand -hex 32
# Example output: a3f8b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0
```
⚠️ **Copy this and store it safely.** If you lose it after launch, all stored GitHub tokens become unreadable and every user must re-authenticate.

---

## Step 5 — Create GitHub OAuth App

1. Go to [github.com/settings/developers](https://github.com/settings/developers)
2. Click **"OAuth Apps"** → **"New OAuth App"**
3. Fill in:
   - **Application name:** `DevAgent`
   - **Homepage URL:** `https://devagent-alpha.vercel.app`
   - **Authorization callback URL:** `https://YOUR_DOMAIN.up.railway.app/api/connect-github/callback`
4. Click **"Register application"**
5. On the next page, copy **Client ID** → `GITHUB_CLIENT_ID`
6. Click **"Generate a new client secret"** → copy → `GITHUB_CLIENT_SECRET`

> Your Railway domain looks like: `devagent-backend-production.up.railway.app`
> Find it in your service → **Settings** → **Domains**

---

## Step 6 — Get your Railway domain

In Railway dashboard → your backend service → **Settings** → **Networking**:

- Click **"Generate Domain"** if you don't have one
- Copy the domain (e.g. `devagent-backend-production.up.railway.app`)
- Update `APP_URL` and `GITHUB_REDIRECT_URI` with this domain
- Update your GitHub OAuth App's callback URL

---

## Step 7 — Update the Vercel frontend

Your landing page needs to know where the backend lives.

In `devagent-alpha.vercel.app` project → Vercel dashboard → **Settings** → **Environment Variables**:

```
NEXT_PUBLIC_API_URL    https://YOUR_DOMAIN.up.railway.app
```

Or if it's a static site, update the `API` constant in `frontend/js/app.js`:

```js
const API = 'https://YOUR_DOMAIN.up.railway.app';
```

Also update the GitHub OAuth connect link in `index.html`:
```html
<!-- Change this href: -->
<a href="https://YOUR_DOMAIN.up.railway.app/api/connect-github">
```

---

## Step 8 — Verify deployment

### Check build logs
In Railway → your service → **Deployments** → click latest → **Build Logs**

You should see:
```
✓ Database is up
✓ Migrations complete
✓ PHP-FPM running
✓ Nginx running
✅ DevAgent backend is live
```

### Hit the health endpoint
```bash
curl https://YOUR_DOMAIN.up.railway.app/api/health
# Expected:
# {"status":"ok","db":true,"php":"8.3.x","ts":"2026-..."}
```

### Test CORS
```bash
curl -H "Origin: https://devagent-alpha.vercel.app" \
     -v https://YOUR_DOMAIN.up.railway.app/api/health 2>&1 | grep -i "access-control"
# Should see: Access-Control-Allow-Origin: https://devagent-alpha.vercel.app
```

---

## Step 9 — Verify migrations ran

Connect to Railway MySQL via CLI:
```bash
railway connect MySQL
# Then in MySQL shell:
SHOW TABLES;
```

Expected tables:
```
migrations
oauth_states
rate_limits
tasks
logs
users
```

Or run manually if something went wrong:
```bash
railway run php bin/migrate.php
```

---

## Troubleshooting

### Build fails: "PHP extension not found"
Check `nixpacks.toml` — all extensions are listed. Re-deploy after any changes.

### "Database unreachable" on startup
- Check `DB_HOST` is set to `${{MySQL.MYSQL_HOST}}` (Railway internal hostname)
- Ensure both services are in the **same Railway project**
- Internal hostnames only work within the same project

### SSE streaming stops after ~30 seconds
Railway's proxy has a default 30s timeout for idle connections. Add this to your service environment:
```
RAILWAY_DEPLOYMENT_TIMEOUT=300
```

### 502 Bad Gateway
PHP-FPM socket isn't ready yet. Check deployment logs for PHP-FPM errors.

### GitHub OAuth: "redirect_uri_mismatch"
The `GITHUB_REDIRECT_URI` env var must exactly match the callback URL in your GitHub OAuth App settings (including `https://` and no trailing slash).

---

## Cost estimate (Railway)

| Resource | Usage | Cost |
|----------|-------|------|
| PHP service | ~0.5 vCPU, 512MB RAM | ~$5/mo |
| MySQL | 1GB storage | ~$5/mo |
| **Total** | | **~$10/mo** |

Free tier gives $5 credit/month — enough for light testing.

---

## Quick reference commands

```bash
# Deploy latest code
railway up

# View live logs
railway logs --tail

# Run migrations manually
railway run php bin/migrate.php

# Open MySQL shell
railway connect MySQL

# Set an env variable via CLI
railway variables set ENCRYPTION_KEY=$(openssl rand -hex 32)

# Get your deployment URL
railway domain
```
