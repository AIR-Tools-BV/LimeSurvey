#!/usr/bin/env bash
# Applies service.yaml with the image that runs now, so a spec change never swaps the image.
set -euo pipefail

PROJECT=air-tools-prod-1cca1
REGION=europe-west1
SPEC="$(dirname "$0")/service.yaml"

REVISION=$(gcloud run services describe limesurvey --region "$REGION" --project "$PROJECT" \
  --format='value(status.latestReadyRevisionName)')
IMAGE=$(gcloud run revisions describe "$REVISION" --region "$REGION" --project "$PROJECT" \
  --format='value(spec.containers[0].image)')
if ! printf '%s' "$IMAGE" | grep -qE '^europe-west1-docker\.pkg\.dev/.+/limesurvey@sha256:[0-9a-f]{64}$'; then
  echo "Live revision $REVISION has an unexpected image: '$IMAGE'" >&2
  exit 1
fi

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
sed -E "s#^([[:space:]]*)image: europe-west1-docker\.pkg\.dev/.*/limesurvey[@:].*#\1image: ${IMAGE}#" \
  "$SPEC" > "$TMP"
if ! grep -qF "image: ${IMAGE}" "$TMP"; then
  echo "Could not put the live image into $SPEC" >&2
  exit 1
fi

echo "Applying $SPEC with the live image from $REVISION:"
echo "  $IMAGE"
gcloud run services replace "$TMP" --region "$REGION" --project "$PROJECT"
