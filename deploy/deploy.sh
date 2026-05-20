#!/usr/bin/env bash
# One-shot deploy: provision a DO Droplet, push the code, start the stack.
#
# Usage:
#   cd /work/layout.ai
#   bash deploy/deploy.sh                # creates a fresh droplet
#   bash deploy/deploy.sh <droplet-ip>   # redeploy to existing droplet
#
# Requires:
#   - DIGITALOCEAN_TOKEN in .env (host machine, gitignored)
#   - All other tokens (GEMINI_API_KEY, CLOUDFLARE_*, REPLICATE_*) in .env
#   - An SSH key on your DO account (deploy.sh picks the first one)
#   - Local SSH client with the matching private key

set -euo pipefail
cd "$(dirname "$0")/.."

REGION="${LAYOUTAI_REGION:-fra1}"     # Frankfurt; pick fra1/ams3/nyc3/lon1 etc.
SIZE="${LAYOUTAI_SIZE:-s-4vcpu-8gb}"  # 8 GB / 4 vCPU droplet (~$48/mo)
IMAGE="${LAYOUTAI_IMAGE:-ubuntu-22-04-x64}"
NAME="${LAYOUTAI_NAME:-layoutai-prod}"

# --- Load tokens from .env ---------------------------------------------------
[[ -f .env ]] || { echo "missing .env"; exit 1; }
DO_TOKEN=$(grep -E '^DIGITALOCEAN_TOKEN=' .env | cut -d= -f2-)
[[ -n "$DO_TOKEN" ]] || { echo "DIGITALOCEAN_TOKEN missing from .env"; exit 1; }

do_api() {
    curl -sS -H "Authorization: Bearer $DO_TOKEN" -H "Content-Type: application/json" "$@"
}

# --- Resolve or create the droplet ------------------------------------------
DROPLET_IP="${1:-}"
if [[ -z "$DROPLET_IP" ]]; then
    echo "→ Picking SSH key..."
    # Pick the key matching the LOCAL ~/.ssh/id_ed25519 by fingerprint so we
    # can actually SSH into the droplet later. Override the path with
    # LAYOUTAI_SSH_PUBKEY if your key lives elsewhere.
    PUBKEY_PATH="${LAYOUTAI_SSH_PUBKEY:-$HOME/.ssh/id_ed25519.pub}"
    [[ -f "$PUBKEY_PATH" ]] || { echo "  no local pubkey at $PUBKEY_PATH"; exit 1; }
    LOCAL_FP=$(ssh-keygen -E md5 -lf "$PUBKEY_PATH" | awk '{print $2}' | sed 's/MD5://')
    SSH_KEY_ID=$(do_api https://api.digitalocean.com/v2/account/keys | python3 -c "
import sys,json
fp = '${LOCAL_FP}'
keys = json.load(sys.stdin)['ssh_keys']
match = [k for k in keys if k['fingerprint'] == fp]
if not match:
    print('NO_MATCH', file=sys.stderr); sys.exit(1)
print(match[0]['id'])
")
    [[ -z "$SSH_KEY_ID" ]] && { echo "  no DO key matching local fingerprint $LOCAL_FP"; exit 1; }
    echo "  using ssh key id=$SSH_KEY_ID (fp=$LOCAL_FP)"

    echo "→ Creating droplet ($NAME, $SIZE, $REGION)..."
    USER_DATA=$(cat deploy/cloud-init.yaml | python3 -c "import sys,json; print(json.dumps(sys.stdin.read()))")
    PAYLOAD=$(printf '{
      "name": "%s",
      "region": "%s",
      "size": "%s",
      "image": "%s",
      "ssh_keys": [%s],
      "tags": ["layoutai"],
      "user_data": %s
    }' "$NAME" "$REGION" "$SIZE" "$IMAGE" "$SSH_KEY_ID" "$USER_DATA")

    DROPLET_ID=$(do_api -X POST -d "$PAYLOAD" https://api.digitalocean.com/v2/droplets | python3 -c "import sys,json; print(json.load(sys.stdin)['droplet']['id'])")
    echo "  droplet id=$DROPLET_ID"

    echo "→ Waiting for droplet to boot + cloud-init to install Docker (3–5 min)..."
    until DROPLET_IP=$(do_api https://api.digitalocean.com/v2/droplets/$DROPLET_ID | python3 -c "import sys,json; d=json.load(sys.stdin)['droplet']; [print(n['ip_address']) for n in d['networks']['v4'] if n['type']=='public']" 2>/dev/null) && [[ -n "$DROPLET_IP" ]]; do
        sleep 5
    done
    echo "  ip=$DROPLET_IP"

    echo "→ Waiting for cloud-init marker (this is the slow part — Docker install)..."
    until ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 root@$DROPLET_IP "test -f /var/lib/cloud/layoutai-ready" 2>/dev/null; do
        sleep 10
        printf "."
    done
    echo "  ready"
fi

# --- Generate a sanitised .env for the droplet ------------------------------
echo "→ Generating production .env..."
APP_KEY=$(grep -E '^APP_KEY=' .env | cut -d= -f2-)
[[ -n "$APP_KEY" ]] || { echo "APP_KEY missing from .env"; exit 1; }
cat > deploy/.env.prod <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=$APP_KEY
APP_URL=http://$DROPLET_IP
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=layoutai
DB_USERNAME=layoutai
DB_PASSWORD=layoutai

REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
FILESYSTEM_DISK=public

GEMINI_API_KEY=$(grep -E '^GEMINI_API_KEY=' .env | cut -d= -f2-)
GEMINI_MODEL=$(grep -E '^GEMINI_MODEL=' .env | cut -d= -f2- | sed 's/^$/gemini-2.5-flash/')
GEMINI_COMBINED_MODEL=gemini-3.5-flash
CLOUDFLARE_API_TOKEN=$(grep -E '^CLOUDFLARE_API_TOKEN=' .env | cut -d= -f2-)
CLOUDFLARE_ACCOUNT_ID=$(grep -E '^CLOUDFLARE_ACCOUNT_ID=' .env | cut -d= -f2-)
RUNMYPRINT_ENDPOINT=https://www.runmyprint.com/test/image2.php
RENDERER_URL=http://renderer:3000

CREATIVE_SCORING_PROVIDER=$(grep -E '^CREATIVE_SCORING_PROVIDER=' .env | cut -d= -f2- | sed 's/^$/mock/')
REPLICATE_API_TOKEN=$(grep -E '^REPLICATE_API_TOKEN=' .env | cut -d= -f2-)
REPLICATE_TRIBE_MODEL=$(grep -E '^REPLICATE_TRIBE_MODEL=' .env | cut -d= -f2-)
REPLICATE_TRIBE_DEPLOYMENT=$(grep -E '^REPLICATE_TRIBE_DEPLOYMENT=' .env | cut -d= -f2-)
EOF
# Strip any CRLF carryover from a Windows-edited deploy.sh — Gemini and
# Replicate both reject API tokens that have a trailing \r.
sed -i 's/\r$//' deploy/.env.prod

# --- rsync code + deploy directory --------------------------------------------
echo "→ Pushing code to $DROPLET_IP..."
rsync -az --delete \
    --exclude '.git' \
    --exclude 'tmp/' \
    --exclude '.env' \
    --exclude 'app/.env' \
    --exclude 'app/vendor/' \
    --exclude 'app/node_modules/' \
    --exclude 'app/storage/app/public/generated/' \
    --exclude 'app/storage/logs/*' \
    --exclude 'app/storage/framework/cache/*' \
    --exclude 'app/storage/framework/sessions/*' \
    --exclude 'app/storage/framework/views/*' \
    --exclude 'docker/scorer/.cog/' \
    -e "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" \
    ./ root@$DROPLET_IP:/opt/layoutai/

# Move the sanitised .env into place (deploy.sh writes it on the *host* then ships it)
scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null deploy/.env.prod root@$DROPLET_IP:/opt/layoutai/deploy/.env

# --- Start the stack --------------------------------------------------------
echo "→ Building images + bringing the stack up (first build takes 5–10 min)..."
ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$DROPLET_IP "
    cd /opt/layoutai
    docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env build
    docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env up -d
    docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env exec -T app php artisan migrate --force
    docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env exec -T app php artisan storage:link || true
    docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env ps
"

rm -f deploy/.env.prod

echo
echo "=========================================="
echo "  layout.ai is live at: http://$DROPLET_IP"
echo "=========================================="
