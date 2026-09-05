#!/usr/bin/env bash
# ShepardOne VPS rollout for /var/www/prod or /var/www/dev.
#
# Usage:
#   ENVIRONMENT=prod ./scripts/deploy.sh
#   ENVIRONMENT=dev  ./scripts/deploy.sh
#   ENV_FILE=.env.production ./scripts/deploy.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

ENVIRONMENT="${ENVIRONMENT:-}"
if [[ -z "${ENV_FILE:-}" ]]; then
  case "${ENVIRONMENT}" in
    prod|production)
      ENV_FILE=".env.production"
      ;;
    dev|development|staging)
      ENV_FILE=".env.dev"
      ;;
    "")
      if [[ -f .env.production ]]; then
        ENV_FILE=".env.production"
      elif [[ -f .env.dev ]]; then
        ENV_FILE=".env.dev"
      else
        echo "Set ENVIRONMENT=prod|dev or ENV_FILE=..." >&2
        exit 1
      fi
      ;;
    *)
      echo "Unknown ENVIRONMENT=${ENVIRONMENT} (use prod or dev)" >&2
      exit 1
      ;;
  esac
fi

COMPOSE=(docker compose --env-file "${ENV_FILE}")

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE} in ${ROOT_DIR}" >&2
  echo "Copy .env.production.example or .env.dev.example and configure secrets." >&2
  exit 1
fi

# Prefer explicit exports from CI; otherwise read from env file for local runs
if [[ -z "${APP_IMAGE:-}" ]]; then
  APP_IMAGE="$(grep -E '^APP_IMAGE=' "${ENV_FILE}" | head -1 | cut -d= -f2-)"
fi
if [[ -z "${NGINX_IMAGE:-}" ]]; then
  NGINX_IMAGE="$(grep -E '^NGINX_IMAGE=' "${ENV_FILE}" | head -1 | cut -d= -f2-)"
fi
if [[ -z "${IMAGE_TAG:-}" ]]; then
  IMAGE_TAG="$(grep -E '^IMAGE_TAG=' "${ENV_FILE}" | head -1 | cut -d= -f2- || true)"
fi
IMAGE_TAG="${IMAGE_TAG:-latest}"

APP_IMAGE="${APP_IMAGE:?Set APP_IMAGE}"
NGINX_IMAGE="${NGINX_IMAGE:?Set NGINX_IMAGE}"
export APP_IMAGE NGINX_IMAGE IMAGE_TAG

PROJECT="$(grep -E '^COMPOSE_PROJECT_NAME=' "${ENV_FILE}" | head -1 | cut -d= -f2- || true)"
PROJECT="${PROJECT:-shepardone}"

echo "==> Deploying ShepardOne"
echo "    dir=${ROOT_DIR}"
echo "    env_file=${ENV_FILE}"
echo "    project=${PROJECT}"
echo "    APP_IMAGE=${APP_IMAGE}:${IMAGE_TAG}"
echo "    NGINX_IMAGE=${NGINX_IMAGE}:${IMAGE_TAG}"

if [[ -n "${GHCR_TOKEN:-}" ]]; then
  echo "==> Logging in to GHCR"
  echo "${GHCR_TOKEN}" | docker login ghcr.io -u "${GHCR_USER:-github}" --password-stdin
fi

echo "==> Ensuring database and Redis are up"
"${COMPOSE[@]}" up -d --wait mysql redis

echo "==> Pulling application images"
"${COMPOSE[@]}" pull nginx app queue scheduler

echo "==> Running database migrations"
"${COMPOSE[@]}" --profile migrate run --rm migrate

echo "==> Starting application stack"
"${COMPOSE[@]}" up -d --remove-orphans nginx app queue scheduler mysql redis

echo "==> Stack status"
"${COMPOSE[@]}" ps

HTTP_BIND="$(grep -E '^HTTP_PORT=' "${ENV_FILE}" | head -1 | cut -d= -f2- || true)"
HTTP_BIND="${HTTP_BIND:-80}"
HEALTH_URL="http://127.0.0.1:${HTTP_BIND##*:}/healthz"

sleep 3
if curl -fsS "${HEALTH_URL}" >/dev/null 2>&1; then
  echo "==> Health check OK (${HEALTH_URL})"
else
  echo "Warning: ${HEALTH_URL} not ready yet. Check logs:" >&2
  echo "  docker compose --env-file ${ENV_FILE} logs -f --tail=100" >&2
fi

echo "==> Pruning dangling images"
docker image prune -f >/dev/null || true

echo "==> Deploy complete (${PROJECT} @ ${IMAGE_TAG})"
