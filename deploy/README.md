# Deploying layout.ai to DigitalOcean

One-shot Droplet deploy. The whole flow is in `deploy.sh`.

## What `deploy.sh` does

1. Reads your DO API token + Replicate / Gemini / Cloudflare tokens from
   `.env` on your local machine.
2. Calls the DO API to provision an **8 GB / 4 vCPU Ubuntu 22.04 Droplet**
   in Frankfurt (override with `LAYOUTAI_REGION`, `LAYOUTAI_SIZE`).
3. Uses `cloud-init.yaml` to install Docker + Docker Compose + UFW on the
   droplet at boot. Drops a marker file when ready.
4. Polls SSH until the marker file exists.
5. Generates a **sanitised production `.env`** locally (`deploy/.env.prod`)
   with `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=http://<ip>`,
   and your service tokens. **This file is gitignored.**
6. `rsync`'s the codebase to `/opt/layoutai/` on the droplet, excluding
   `vendor/`, `node_modules/`, `tmp/`, and any `.env` files.
7. `scp`'s `.env.prod` over to `/opt/layoutai/deploy/.env` on the droplet.
8. Runs `docker compose -f deploy/docker-compose.prod.yml build && up -d`
   on the droplet, plus `migrate` and `storage:link`.
9. Prints the public URL.

## Run it

```bash
cd /work/layout.ai
bash deploy/deploy.sh
```

First run takes 8–12 minutes:
* 3–5 min for the droplet to boot + cloud-init to install Docker
* 5–7 min for the first Docker build (Composer install + PHP extensions)

Re-deploys to the same droplet (after IP is known):

```bash
bash deploy/deploy.sh 203.0.113.42
```

This rsyncs only changed files + restarts the stack, ~30 seconds.

## What's in the production stack

| Service | Image | Notes |
| --- | --- | --- |
| `app` | `layoutai/app:prod` (built from `deploy/Dockerfile.app.prod`) | PHP 8.4-FPM. Code baked into the image. |
| `nginx` | `nginx:1.27-alpine` | Public on `:80`. Config at `deploy/nginx.conf`. |
| `postgres` | `postgres:16-alpine` | Volume `postgres-data` for persistence. |
| `redis` | `redis:7-alpine` | Volume `redis-data` for AOF. |
| `worker` | `layoutai/app:prod` | 8 replicas of `queue:work`. |
| `scheduler` | `layoutai/app:prod` | `schedule:work` for the hourly top-up cron. |
| `renderer` | `layoutai/renderer:prod` | Node + Playwright. |

## Operating notes

### Logs
```bash
ssh root@<ip> "cd /opt/layoutai && docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env logs -f app"
```

### Restart workers (e.g. after env change)
```bash
ssh root@<ip> "cd /opt/layoutai && docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env restart worker scheduler"
```

### Trigger top-up manually
```bash
ssh root@<ip> "cd /opt/layoutai && docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env exec -T app php artisan layout:top-up-campaigns"
```

### Destroy the droplet
```bash
curl -X DELETE -H "Authorization: Bearer $DO_TOKEN" "https://api.digitalocean.com/v2/droplets/$DROPLET_ID"
```

## TLS / a real domain

The MVP deploy serves over plain HTTP on `:80`. To enable HTTPS:

1. Point a domain's A record at the droplet IP.
2. SSH in, install `certbot` and the `nginx` plugin.
3. Run `certbot --nginx -d yourdomain.com`.
4. Update `APP_URL` in `/opt/layoutai/deploy/.env` to `https://yourdomain.com`.
5. Restart the app + workers.

A more thorough setup uses Caddy or a sidecar `nginx-proxy + acme-companion`
but for first-launch the manual certbot path is fine.

