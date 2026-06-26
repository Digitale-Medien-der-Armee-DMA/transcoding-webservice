# Release Checklist

Status: 2026-06-25

This checklist is the Go/No-Go gate for a clean-install production acceptance. It does not replace approval by operations and VIMP owners.

## Code And CI

- [ ] Release commit is based on current `master`.
- [ ] GitHub Actions are green.
- [ ] No uncommitted changes exist on the release host.
- [ ] `composer.lock` and `package-lock.json` match the approved commit.
- [ ] No secrets were committed.
- [ ] PR or release notes list known residual risks.

## Configuration

- [ ] `.env` exists only on the target host.
- [ ] `APP_KEY` is set.
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.
- [ ] Fresh external production database is configured.
- [ ] Redis target is configured.
- [ ] `ADMIN_UPLOADS_ENABLED=false`.
- [ ] `SECURITY_LOG_SCRUBBING_ENABLED=true`.
- [ ] Source URL allowlist decision is documented.
- [ ] Default admin credentials are replaced or rotation is documented before production use.

## Infrastructure

- [ ] Docker Compose version is documented.
- [ ] Host has enough disk for `uploaded-media` and `converted-media`.
- [ ] Volume layout is documented.
- [ ] Reverse proxy points to the planned `HTTP_BIND`.
- [ ] Fresh DB bootstrap path is documented.
- [ ] Rebuild/recovery path is documented.
- [ ] Images, Compose files, and `.env` backup location are known.

## GPU And FFmpeg

- [ ] CPU FFmpeg smoke passed.
- [ ] GPU FFmpeg smoke passed on the target host if GPU production is planned.
- [ ] NVIDIA driver version is documented.
- [ ] NVIDIA Container Toolkit works.
- [ ] `GPU_GUARD_ENABLED` decision is documented.
- [ ] `GPU_GUARD_MIN_FREE_MB` matches the target card or is consciously disabled.

## Application

- [ ] Compose config validates.
- [ ] Build succeeds.
- [ ] Fresh database migrations succeed.
- [ ] Release-specific bootstrap/seed steps are completed, if any.
- [ ] `/internal/health/live` is `ok`.
- [ ] `/internal/health/ready` is `ok`.
- [ ] `/internal/metrics` returns DB, queue, worker, storage, and FFmpeg values.
- [ ] Workers and scheduler are running.
- [ ] Download and video queue names match the environment.

## VIMP Staging

- [ ] VIMP staging user and API token are configured.
- [ ] Test transcode succeeds.
- [ ] Callback arrives in VIMP staging.
- [ ] Status endpoint returns the expected status.
- [ ] Finished endpoint marks only the expected generated file.
- [ ] Delete flow removes only the expected test mediakey.
- [ ] No tokens or authorization values appear in logs.

## Load Test

- [ ] LT-001 single-job baseline passed.
- [ ] LT-002 small parallelism passed.
- [ ] LT-003 GPU saturation is conservatively passed or explicitly not applicable.
- [ ] LT-004 failure path passed.
- [ ] LT-005 delete/cleanup preparation passed.
- [ ] No blocking findings from `docs/LOAD_TEST_PLAN.md` remain open.

## Go/No-Go

- [ ] VIMP owners are reachable.
- [ ] Operations owners are reachable.
- [ ] Monitoring is active.
- [ ] Recovery plan is available.
- [ ] Go/No-Go decision is documented.

## After Production Acceptance

- [ ] First production transcodes are observed.
- [ ] No unexpected worker restarts occur.
- [ ] No token leaks appear in logs.
- [ ] Queue lengths normalize.
- [ ] GPU/VRAM values are within expected range.
- [ ] Final note with commit, time, and findings is recorded.
