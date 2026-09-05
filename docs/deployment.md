# ShepardOne deployment (Docker + Nginx + GitHub Actions → VPS)

Dual-environment layout on one AlmaLinux (or Ubuntu) VPS:

```
Internet → host nginx :80/:443 (TLS)
              ├─ your-domain.example      → 127.0.0.1:8080  (/var/www/prod)
              └─ dev.your-domain.example  → 127.0.0.1:8081  (/var/www/dev)
```

Each directory runs its **own** Compose project (separate MySQL, Redis, volumes, containers).

| Path | Env file | Compose project | Host port |
|------|----------|-----------------|-----------|
| `/var/www/prod` | `.env.production` | `shepardone-prod` | `127.0.0.1:8080` |
| `/var/www/dev` | `.env.dev` | `shepardone-dev` | `127.0.0.1:8081` |

## Architecture (per environment)

```
host nginx → nginx container → php-fpm (app)
                                  ↓
                         MySQL 8.4 + Redis 7
                                  ↓
                    queue worker + scheduler
```

## One-time VPS setup

### 1. Fix ownership (if you already created the dirs as root)

You ran `chown` as root, so `$USER` was `root`. Prefer the `deploy` user:

```bash
# as root
useradd -m -s /bin/bash deploy 2>/dev/null || true
usermod -aG docker deploy
chown -R deploy:deploy /var/www/prod /var/www/dev
```

### 2. Bootstrap Docker

```bash
scp scripts/vps-bootstrap.sh root@YOUR_VPS_IP:/root/
ssh root@YOUR_VPS_IP
bash /root/vps-bootstrap.sh
```

Creates `deploy`, installs Docker, opens 22/80/443, prepares `/var/www/prod` and `/var/www/dev`.

### 3. Place compose files in both directories

From your laptop (after Docker is installed):

```bash
# Production
scp docker-compose.yml deploy@YOUR_VPS_IP:/var/www/prod/
scp scripts/deploy.sh deploy@YOUR_VPS_IP:/var/www/prod/scripts/
ssh deploy@YOUR_VPS_IP 'chmod +x /var/www/prod/scripts/deploy.sh'

# Dev / staging
scp docker-compose.yml deploy@YOUR_VPS_IP:/var/www/dev/
scp scripts/deploy.sh deploy@YOUR_VPS_IP:/var/www/dev/scripts/
ssh deploy@YOUR_VPS_IP 'chmod +x /var/www/dev/scripts/deploy.sh'
```

### 4. Create env files (different secrets per env)

Generate two different keys on your Mac:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

```bash
# on VPS as deploy
cp .env.production.example /var/www/prod/.env.production   # or scp from laptop
cp .env.dev.example        /var/www/dev/.env.dev
nano /var/www/prod/.env.production
nano /var/www/dev/.env.dev
```

Must differ between prod and dev:

- `COMPOSE_PROJECT_NAME` (`shepardone-prod` vs `shepardone-dev`)
- `HTTP_PORT` (`127.0.0.1:8080` vs `127.0.0.1:8081`)
- `APP_KEY`, `APP_URL`, `DB_*` passwords, `MYSQL_ROOT_PASSWORD`
- `DB_DATABASE` (`shepardone` vs `shepardone_dev`)

Set `APP_IMAGE` / `NGINX_IMAGE` to your GHCR paths (lowercase), e.g. `ghcr.io/myuser/shepard-one`.

### 5. Host nginx + TLS (AlmaLinux)

```bash
# as root
dnf -y install epel-release
dnf -y install nginx certbot python3-certbot-nginx
cp /path/to/repo/docker/nginx/host-vhosts.conf.example /etc/nginx/conf.d/shepardone.conf
# edit server_name values
nginx -t && systemctl enable --now nginx
certbot --nginx -d your-domain.example -d dev.your-domain.example
```

### 6. GitHub configuration

#### Secrets

| Secret | Value |
|--------|--------|
| `VPS_HOST` | VPS IP / hostname |
| `VPS_USER` | `deploy` |
| `VPS_SSH_KEY` | private key for `deploy` |
| `VPS_PROD_PATH` | `/var/www/prod` (optional; this is the default) |
| `VPS_DEV_PATH` | `/var/www/dev` (optional; this is the default) |
| `GHCR_READ_TOKEN` | PAT with `read:packages` if images are private |

#### Environments

Create GitHub Environments named **`production`** and **`development`**.

#### Branch → environment mapping

| Branch / action | Deploys to |
|-----------------|------------|
| Push `main` / `master` | `/var/www/prod` |
| Push `develop` | `/var/www/dev` |
| Manual workflow | choose production or development |

Image channel tags: prod → `:latest`, develop → `:dev`, plus immutable `:sha-…` tags.

### 7. SSH key for Actions

```bash
# on Mac
ssh-keygen -t ed25519 -C "shepardone-deploy" -f ~/.ssh/shepardone_deploy -N ""

# on VPS as deploy
mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo 'ssh-ed25519 AAAA…' >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Put the **private** key in GitHub secret `VPS_SSH_KEY`.

## Manual first start (before CI)

```bash
# as deploy — production
cd /var/www/prod
docker compose --env-file .env.production up --build -d
docker compose --env-file .env.production --profile migrate run --rm migrate

# as deploy — dev
cd /var/www/dev
docker compose --env-file .env.dev up --build -d
docker compose --env-file .env.dev --profile migrate run --rm migrate

curl -I http://127.0.0.1:8080/healthz
curl -I http://127.0.0.1:8081/healthz
```

Later deploys:

```bash
cd /var/www/prod && ENVIRONMENT=prod ./scripts/deploy.sh
cd /var/www/dev  && ENVIRONMENT=dev  ./scripts/deploy.sh
```

## Useful commands

```bash
# List both stacks
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'

cd /var/www/prod
docker compose --env-file .env.production logs -f --tail=100 app

cd /var/www/dev
docker compose --env-file .env.dev ps
```

## Backups

Back up **each** MySQL volume separately:

```bash
cd /var/www/prod
docker compose --env-file .env.production exec -T mysql \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" shepardone | gzip > ~/backup-prod-$(date +%F).sql.gz

cd /var/www/dev
docker compose --env-file .env.dev exec -T mysql \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" shepardone_dev | gzip > ~/backup-dev-$(date +%F).sql.gz
```

## Files reference

| Path | Purpose |
|------|---------|
| `Dockerfile` | Multi-stage app + nginx images |
| `docker-compose.yml` | Shared stack definition (`COMPOSE_PROJECT_NAME` isolates envs) |
| `.env.production.example` | Template for `/var/www/prod/.env.production` |
| `.env.dev.example` | Template for `/var/www/dev/.env.dev` |
| `docker/nginx/host-vhosts.conf.example` | Host nginx for both domains |
| `scripts/vps-bootstrap.sh` | Docker + `/var/www/{prod,dev}` |
| `scripts/deploy.sh` | `ENVIRONMENT=prod\|dev` rollout |
| `.github/workflows/deploy.yml` | main→prod, develop→dev |
