#!/usr/bin/env bash
# Sync the LimeSurvey upload payload from the live GKE pod into the GCS bucket that
# Cloud Run mounts. Run this once now, and AGAIN immediately before the DNS/LB cutover
# to pick up anything respondents uploaded in between.
#
# Only surveys/ needs to be here: themes/, plugins/ and admintheme/ ship inside the
# image (committed under upload/ in this repo) because serving them off gcsfuse made
# survey rendering ~4x slower.
#
#   ./deploy/cloudrun/sync-uploads.sh
#
set -euo pipefail

PROJECT="${PROJECT:-air-tools-prod-1cca1}"
BUCKET="${BUCKET:-air-tools-limesurvey-uploads}"
NAMESPACE="${NAMESPACE:-default}"
CONTAINER="${CONTAINER:-limesurvey-sha256-1}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

POD="$(kubectl get pods -n "$NAMESPACE" -l app=limesurvey \
        -o jsonpath='{.items[0].metadata.name}')"
echo "==> source pod: $POD"

echo "==> copying upload/ out of the pod"
kubectl cp "$NAMESPACE/$POD:/var/www/html/upload" "$STAGE" -c "$CONTAINER"
rm -rf "$STAGE/lost+found"

echo "==> syncing files to gs://$BUCKET"
gcloud storage rsync -r "$STAGE" "gs://$BUCKET" --delete-unmatched-destination-objects \
  --exclude='lost\+found.*'

# --- directory markers -------------------------------------------------------------
# GCS has no real directories: an EMPTY directory simply does not exist after an rsync,
# and gcsfuse's implicit-dirs cannot infer one with no objects beneath it. LimeSurvey's
# Twig loader calls is_dir() on theme paths and throws
#   Twig\Error\LoaderError: The ".../views/" directory does not exist
# for any such path. The upload tree is ~127 files across ~205 dirs, ~95 of them empty,
# so this step is NOT optional. We create a zero-byte object whose name ends in "/",
# which gcsfuse recognises as an explicit directory.
echo "==> creating directory markers"
TOKEN="$(gcloud auth print-access-token)"
( cd "$STAGE" && find . -type d | sed 's|^\./||' | grep -v '^\.$' | grep -v 'lost+found' ) \
  > "$STAGE/.dirs.txt"

python3 - "$STAGE/.dirs.txt" "$BUCKET" "$TOKEN" <<'PY'
import sys, urllib.parse, urllib.request
dirs_file, bucket, token = sys.argv[1], sys.argv[2], sys.argv[3]
ok = fail = 0
for line in open(dirs_file):
    d = line.strip()
    if not d:
        continue
    name = d.rstrip('/') + '/'
    url = ('https://storage.googleapis.com/upload/storage/v1/b/%s/o'
           '?uploadType=media&name=%s' % (bucket, urllib.parse.quote(name, safe='')))
    req = urllib.request.Request(url, data=b'', method='POST', headers={
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/x-directory',
        'Content-Length': '0',
    })
    try:
        urllib.request.urlopen(req, timeout=30); ok += 1
    except Exception as e:
        fail += 1
        print('  FAILED %s: %s' % (name, e), file=sys.stderr)
print('==> directory markers: %d created, %d failed' % (ok, fail))
PY

echo "==> done. Verify a themed survey renders before cutting the LB over."
