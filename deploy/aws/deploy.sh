#!/bin/bash
# Runs on the EC2 host (installed by the CloudFormation user-data as /usr/local/bin/special-deploy,
# which extracts this very file from the image it is about to run).
#
#   sudo special-deploy            # deploy :latest
#   sudo special-deploy v1.2.0     # deploy or roll back to a tag
set -euo pipefail

TAG="${1:-latest}"
HOME_DIR=/opt/special
STACK_DIR="$HOME_DIR/stack"

# shellcheck disable=SC1091
source "$HOME_DIR/instance.env"   # SPECIAL_DOMAIN, SPECIAL_AWS_REGION, SPECIAL_DYNAMODB_TABLE, SPECIAL_IMAGE_REPO, SPECIAL_LOG_GROUP, SPECIAL_SSM_PREFIX

IMAGE="$SPECIAL_IMAGE_REPO:$TAG"
REGISTRY="${SPECIAL_IMAGE_REPO%%/*}"

# Registry authentication is a host concern. A host may provide /opt/special/registry-login.sh
# (GHCR token, Docker Hub, ...); without it the image is assumed to live in this account's ECR.
if [ -x "$HOME_DIR/registry-login.sh" ]; then
    echo ">> Logging in to $REGISTRY (host script)"
    "$HOME_DIR/registry-login.sh"
else
    echo ">> Logging in to $REGISTRY (ECR)"
    aws ecr get-login-password --region "$SPECIAL_AWS_REGION" | docker login --username AWS --password-stdin "$REGISTRY" >/dev/null
fi

echo ">> Pulling $IMAGE"
docker pull "$IMAGE" >/dev/null

echo ">> Refreshing stack files from the image"
mkdir -p "$STACK_DIR"
container="$(docker create "$IMAGE")"
trap 'docker rm -f "$container" >/dev/null 2>&1 || true' EXIT
docker cp "$container:/opt/special-deploy/compose.prod.yml" "$STACK_DIR/compose.prod.yml"
docker cp "$container:/opt/special-deploy/Caddyfile" "$STACK_DIR/Caddyfile"
docker cp "$container:/opt/special-deploy/deploy.sh" "$HOME_DIR/deploy.sh"

echo ">> Reading secrets from SSM Parameter Store ($SPECIAL_SSM_PREFIX)"
APP_KEY="$(aws ssm get-parameter --region "$SPECIAL_AWS_REGION" --name "$SPECIAL_SSM_PREFIX/app-key" --with-decryption --query Parameter.Value --output text)"

umask 077
cat > "$STACK_DIR/.env" <<ENV
SPECIAL_ENV=production
SPECIAL_STORE=dynamodb
SPECIAL_APP_KEY=$APP_KEY
SPECIAL_PUBLIC_BASE_URL=https://$SPECIAL_DOMAIN
SPECIAL_DOMAIN=$SPECIAL_DOMAIN
SPECIAL_AWS_REGION=$SPECIAL_AWS_REGION
SPECIAL_DYNAMODB_TABLE=$SPECIAL_DYNAMODB_TABLE
SPECIAL_LOG_GROUP=$SPECIAL_LOG_GROUP
SPECIAL_IMAGE=$IMAGE
SPECIAL_SESSION_SECURE=1
SPECIAL_TRUST_PROXY=1
ENV

echo ">> Starting the stack"
cd "$STACK_DIR"
docker compose -f compose.prod.yml --env-file .env up -d --remove-orphans

echo ">> Waiting for the app to report healthy"
for _ in $(seq 1 30); do
    state="$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose -f compose.prod.yml ps -q app)" 2>/dev/null || echo starting)"
    if [ "$state" = "healthy" ]; then
        echo ">> Healthy. Deployed $IMAGE"
        docker image prune -f >/dev/null
        exit 0
    fi
    sleep 2
done

echo "!! The app did not become healthy. Recent logs:" >&2
docker compose -f compose.prod.yml logs --tail 40 app >&2
exit 1
