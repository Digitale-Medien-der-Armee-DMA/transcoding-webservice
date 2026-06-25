# Recovery Plan

Status: 2026-06-25

This plan describes recovery for a clean-install deployment. There is no existing installation that must be restored during the current project phase.

The file keeps its historical name because other documents link to `docs/ROLLBACK_PLAN.md`.

## Recovery Triggers

Start recovery when one of these conditions occurs during staging or production acceptance:

- VIMP cannot submit new transcode jobs.
- Jobs remain stuck in `download` or `video`.
- Ready health stays red for more than 5 minutes without an accepted explanation.
- GPU worker starts jobs but FFmpeg/NVENC fails reproducibly.
- Callbacks to VIMP are missing or contain wrong artifact URLs.
- Logs expose API tokens or authorization values.
- Fresh database bootstrap cannot be completed or verified.

## Required Recovery Inputs

Before production acceptance, record:

- Approved Git commit SHA.
- Built image tags or build timestamp.
- Copy of the target `.env` outside the repository.
- Reverse proxy configuration.
- DB host and database name, without passwords.
- Redis target.
- Volume names for `uploaded-media`, `converted-media`, `app-storage`, and Redis.
- Whether GPU production is enabled.

## Stop Workers

Before recovery, prevent new work:

```bash
docker compose --env-file .env -f compose.yaml stop scheduler
docker compose --env-file .env -f compose.yaml stop worker-download worker-video-gpu
```

If jobs are active, document whether they are allowed to finish or are intentionally interrupted.

## Rebuild From Known Commit

Return to the approved commit:

```bash
git fetch origin master
git checkout <approved-sha>
docker compose --env-file .env -f compose.yaml build
docker compose --env-file .env -f compose.yaml up -d app web redis scheduler worker-download
```

On a GPU host, start the GPU worker only after smoke validation:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
docker compose --env-file .env -f compose.yaml up -d worker-video-gpu
```

Check:

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

## Fresh Database Recovery

If acceptance fails before production use, the preferred recovery is to recreate the fresh database and rerun bootstrap:

1. Stop scheduler and workers.
2. Stop web access at the reverse proxy or keep it internal-only.
3. Recreate the fresh database.
4. Run migrations:

   ```bash
   docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
   ```

5. Rerun documented release-specific bootstrap/seed commands, if any.
6. Recreate controlled admin credentials and VIMP user/API token.
7. Run health checks and the VIMP staging flow.

Do not plan reverse data migrations unless a later production data-retention requirement explicitly adds them.

## Volume Recovery

For a failed clean-install acceptance, volumes can be recreated if no productive VIMP jobs must be preserved:

```bash
docker compose --env-file .env -f compose.yaml down
docker volume ls | grep transcoding-webservice
```

Only remove volumes after confirming that no required artifacts must be kept. Prefer documenting the exact volume names and operator approval before removal.

## Reverse Proxy Recovery

If the stack is unhealthy:

1. Remove the new upstream from the reverse proxy or point it to a maintenance page.
2. Keep the Docker stack internal-only.
3. Fix and retest in staging before exposing the route again.

## After Recovery

Document within 30 minutes:

- Recovery trigger.
- Time of failure and recovery.
- Affected jobs and mediakeys, if any.
- Commit SHA and image tags.
- Whether DB or volumes were recreated.
- Whether VIMP can submit jobs again.

Follow-up:

- Open an issue or follow-up PR for the root cause.
- Do not accept production use again without a passed staging run.
