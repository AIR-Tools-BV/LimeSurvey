# LimeSurvey on Cloud Run

Replaces the GKE Autopilot deployment in `../k8s/limesurvey.yaml`.

## Why we moved

The cluster ran **one** single-replica pod — `maxReplicas: 1`, `strategy: Recreate`,
RWO volumes pinned to `europe-west1-d`. None of Kubernetes' value (horizontal scaling,
rolling deploys, multi-service orchestration) was in use, but we paid for the whole
platform: a regional 3-zone Autopilot control plane, 7 auto-provisioned nodes, ~100
system pods, two CSI drivers, an L7 load balancer and a Cloud SQL proxy sidecar.

Measured against that pod over 30 days:

| | Requested | Actually used |
|---|---|---|
| CPU | 3.5 vCPU | 0.038 cores median, 0.45 peak |
| Memory | 5 GiB | ~206 MiB working set |
| Upload volume | 200 GiB | **80 MB** |

The July 2026 bump from 1 → 3 cores (`431d15a0`) was treating a symptom: Autopilot forces
CPU *limits* equal to *requests*, so Apache (`MaxRequestWorkers 150`) hit a hard cgroup
wall and the admin panel hung. Cloud Run lets the app burst, so the problem is gone
structurally rather than bought around.

**Cost: ~€220–280/mo → ~€50–70/mo**, and faster under load.

## Deploy

```bash
gcloud run services replace deploy/cloudrun/service.yaml --region europe-west1
```

Images are built by the GitHub Action on tag push (`.github/workflows/gcr.yml`).
Update the `image:` tag in `service.yaml` and re-run the command above.

## Shape

Three containers, mirroring the old pod. Cloud Run sidecars share a network namespace,
so the app still reaches Redis and the SQL proxy on `127.0.0.1` and **the application
needs no code or config change** — Cloud Run serves the container on port 80 directly.

- `limesurvey` — Apache/PHP, the ingress container
- `redis` — per-instance Yii cache (as it was in-pod)
- `cloudsql-proxy` — v2, keyless via the `limesurvey-run` service account

Autoscaling is `min=0 / max=4`: traffic is invite-driven and bursty, with hours or days
of nothing, so scale-to-zero saves ~€40/mo of idle CPU+memory.

## Gotchas — all four cost real debugging time

1. **`container-dependencies` needs `startupProbe`s.** Cloud Run rejects the deployment
   unless every depended-on container declares one.

2. **The SQL proxy must bind `0.0.0.0`.** With `--address=127.0.0.1` it logs
   *"ready for new connections"* while Cloud Run's startup probe times out with
   `DEADLINE_EXCEEDED` — the probe reaches the container over its network interface,
   not loopback.

3. **Empty directories do not exist in GCS.** The upload tree is ~127 files across ~205
   directories, ~95 of them empty. `rsync` copies every file and the tree still breaks:
   `views/` contained only an empty `subviews/`, so nothing existed beneath it and
   `implicit-dirs` could not infer it. Twig then throws
   `LoaderError: The ".../views/" directory does not exist`.
   `sync-uploads.sh` creates explicit zero-byte `dir/` marker objects. Do not skip it.

4. **gcsfuse is too slow for the theme read path.** Rendering a survey stats and reads
   many small Twig templates; off the mount that took **3.0–4.0s vs 0.9s on GKE**.
   gcsfuse metadata/file cache mount options did *not* help — Cloud Run gives the file
   cache no `cache-dir`, so content reads always hit the network. `themes/`, `plugins/`
   and `admintheme/` therefore ship **inside the image** (committed under `upload/`),
   and only `surveys/` stays on the mount.

## Performance

Concurrent survey renders, Cloud Run (4 vCPU) vs the old 3-core pod:

| Concurrent | Cloud Run | GKE pod |
|---|---|---|
| 4 | 1.19 s | 1.37 s |
| 8 | 1.43 s | 2.46 s |
| 16 | 2.48 s | 3.91 s |
| 24 | 3.26 s | 7.30 s |

Sizing is driven by **concurrency, not average load**: aggregate CPU use is tiny, but a
browser opening an admin page fires many PHP requests at once. At `cpu: 1` this degraded
linearly (8 concurrent → 4.29 s) and the admin UI felt sluggish.

Cold start ≈ 5 s before the static dirs were baked into the image; the boot-time copy off
gcsfuse alone was ~6.8 s of it.

## Ingress

Cloudflare fronts the site and terminates TLS for visitors, then proxies to a Google
load balancer. (The Google-managed certs on the old GKE ingress have been
`PROVISIONING_FAILED_PERMANENTLY` for years — they never validated because the domain
resolves to Cloudflare — so Cloudflare cannot be on Full (strict), and the LB's
self-signed origin cert is equivalent to what GKE served.)

A dedicated LB was built for Cloud Run rather than reusing the GKE one, because that one
is owned by the GKE Ingress controller and would be reconciled or deleted with the cluster:

| Resource | Name |
|---|---|
| Static IP | `limesurvey-cloudrun-ip` → **34.36.174.185** |
| Serverless NEG | `limesurvey-neg` (europe-west1 → `limesurvey`) |
| Backend service | `limesurvey-backend` |
| URL map | `limesurvey-lb` |
| Frontends | `limesurvey-fr-http` (:80), `limesurvey-fr-https` (:443, self-signed) |

**Why a load balancer and not a Cloudflare Worker:** Cloud Run routes by its own
`*.run.app` hostname and 404s on any other Host, so going straight from Cloudflare to
Cloud Run needs a Host rewrite — which is **Enterprise-only** in Origin Rules. A Worker
can do it for ~€0–5/mo, but the LB preserves Host natively, keeps the architecture
identical to the GKE setup, and puts no code in the request path. €18/mo for that.

`PUBLIC_URL` is set regardless (see `service.yaml`) so LimeSurvey can never build
redirects from a proxy-supplied hostname.

## Cutover

1. `./deploy/cloudrun/sync-uploads.sh` — final `surveys/` sync + directory markers
2. Cloudflare → DNS → `survey` A record: `34.149.196.180` → **`34.36.174.185`**,
   proxy status unchanged. Takes effect in seconds; no TTL wait because Cloudflare
   proxies. **Rollback = put the old IP back.**
3. Scale the GKE deployment to 0 and run on Cloud Run through a weekday peak.
4. Only then delete the cluster, the PVCs, the old ingress/forwarding rules, the
   `34.149.196.180` address and the `limesurvey-kubernetes` service account.

## Note on secrets

`deploy/k8s/limesurvey.yaml` declared `DB_PASSWORD` via a `limesurvey-secrets` Secret,
but **that Secret was never created** — the live Deployment carried the password as a
plaintext literal in the pod spec, readable by anyone with cluster read access. Cloud Run
reads it from Secret Manager (`limesurvey-db-password`) instead. Rotate the password once
GKE is gone, since the old value was exposed in the cluster for a long time.
