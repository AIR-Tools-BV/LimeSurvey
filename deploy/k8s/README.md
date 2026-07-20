# LimeSurvey production deployment

`limesurvey.yaml` is the **source of truth** for the production pod running
`survey.air-tools.nl` (GKE Autopilot cluster `limesurvey`, project
`air-tools-prod-1cca1`, namespace `default`).

## Why this exists

The Deployment was managed imperatively — the CI (`.github/workflows/gcr.yml`) only
builds & pushes the image, and deploys were ad-hoc `kubectl set image`. Nothing stored
the pod's resources/replicas, so hand-tuning during an incident **silently reverted** on
the next deploy. The pod kept settling back to **cpu: 1** (a single Apache-throttled core),
and under the France/Cint launch traffic that one pod pegged its core and the **admin panel
(`/index.php/admin`) hung**.

Codifying the spec here + applying it on every deploy makes the fix permanent.

## The fix

- App container CPU **1 → 3 cores** (fits the current `e2-standard-4` node — no cost jump).
- Single replica by design: upload/config PVCs are **ReadWriteOnce** and Redis is an
  **in-pod cache**, so we scale **vertically**, not horizontally. HPA is pinned min=max=1.
  Horizontal scaling would first need RWX (Filestore) volumes + a shared/external Redis.

## One-time: create the DB-password Secret

The DB password used to be inline plaintext in the Deployment. It's now a Secret so the
manifest is safe to commit. Create it once (value is the existing `DB_PASSWORD`):

```bash
kubectl -n default create secret generic limesurvey-secrets \
  --from-literal=db-password='<CURRENT_DB_PASSWORD>'
```

(Grab the current value from the live pod if needed:
`kubectl -n default get deploy limesurvey -o jsonpath='{range .spec.template.spec.containers[?(@.name=="limesurvey-sha256-1")].env[?(@.name=="DB_PASSWORD")]}{.value}{end}'`)

## Deploy

```bash
gcloud container clusters get-credentials limesurvey --region europe-west1 --project air-tools-prod-1cca1
kubectl -n default apply -f deploy/k8s/limesurvey.yaml
# then roll the new image tag (CI does this automatically on a git tag):
kubectl -n default set image deployment/limesurvey \
  limesurvey-sha256-1=europe-west1-docker.pkg.dev/air-tools-prod-1cca1/air-tools/limesurvey:<TAG>
kubectl -n default rollout status deployment/limesurvey
```

`strategy: Recreate` means ~30–90s of downtime on each rollout (the old pod releases the
RWO disk before the new one starts). Expected.

## Related (not managed here)

- **Cloud SQL `limesurvey-v2`** is `db-g1-small` with `max_connections=1000` (memory-unsafe
  for 1.7 GB). Not the active bottleneck today (Feb metrics: ~4% CPU, 11 conns) but worth
  right-sizing to a dedicated-core tier and dropping `max_connections` to ~200 if the DB
  becomes hot. Managed via `gcloud sql instances patch`, not this manifest.
