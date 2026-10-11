#!/usr/bin/env bash
# Build the Special image for linux/amd64 and push it to the ECR repository created by special-stack.yaml.
#   ./deploy/aws/push-image.sh [tag] [region]
set -euo pipefail

TAG="${1:-latest}"
REGION="${2:-$(aws configure get region)}"
REPOSITORY="${SPECIAL_ECR_REPOSITORY:-special}"
[ -n "$REGION" ] || { echo "No region: pass it as the second argument or run 'aws configure'." >&2; exit 1; }

ACCOUNT="$(aws sts get-caller-identity --query Account --output text)"
REGISTRY="$ACCOUNT.dkr.ecr.$REGION.amazonaws.com"

aws ecr get-login-password --region "$REGION" | docker login --username AWS --password-stdin "$REGISTRY"

tags=(-t "$REGISTRY/$REPOSITORY:$TAG")
if sha="$(git rev-parse --short HEAD 2>/dev/null)"; then tags+=(-t "$REGISTRY/$REPOSITORY:$sha"); fi

docker build --platform linux/amd64 "${tags[@]}" .
docker push --all-tags "$REGISTRY/$REPOSITORY"

echo "Pushed $REGISTRY/$REPOSITORY:$TAG. Deploy it with: sudo special-deploy $TAG   (on the instance)"
