MANDATORY DOCKER JUDGE
======================

Trust boundary
--------------
JudgeEngine prepares only DockerSandbox opaque, in-process handles. Compilation,
syntax checks, official judging, rejudging, daemon custom runs and synchronous
fallbacks ALL use Linux containers. There is no switch for host execution and no
missing-image/toolchain fallback. Infrastructure failures become SYS_ERR/SYS_ERROR;
compilation errors remain CE; runtime failures/resource limits remain RE/TLE.
Expected test output remains solely in the trusted PHP comparator.

No source, executable, application folder, .env, Docker socket, credentials,
configuration, dependency directory or host path is bind-mounted. The only input
is bounded source/stdin delivered by Docker attach. No contestant artifacts are
copied out. stdout/stderr alone return to the judge.

Every container uses a pinned inspected Linux image ID, --pull=never, network=none,
IPC disabled, UID/GID 65534, zero capabilities, no-new-privileges, default seccomp,
read-only root, fresh noexec /tmp, finite CPU/memory/swap/PIDs/file-descriptor and
file-size limits, no Docker logging, and a fixed /work directory. Docker engine
security/cgroup support is checked before using an image. Image-declared VOLUMEs
are rejected (preventing implicit unbounded writable storage).

The unique per-job /work volume is a bounded 64 MiB tmpfs local-driver volume,
created with uid=65534,gid=65534,mode=0700,nosuid,nodev. Compilation mounts it RW;
every runtime is a NEW container mounting it RO. A small, equally hardened,
source-less lease container holds its mount: otherwise Docker unmounts and empties
local tmpfs when compilation stops. The lease self-expires after 15 minutes;
requests too close to expiry become SYS_ERR, never a misleading missing-file RE.

Limits
------
Source: 256 KiB. Adapter stdin: 1 MiB. Custom API stdin: 60,000 bytes (TEXT column).
Captured stdout: 64 KiB; stderr: 32 KiB. Excess output produces RE, compiler output
excess produces CE. Comparison sees raw bytes; stored diagnostics are UTF-8-safe.
Compiler: 30 seconds, 512 MiB RAM. Runtime: problem's time_limit_ms (1..60,000),
memory_limit_kb (16..1024 MiB). Custom runs always use 10 seconds and 256 MiB,
regardless of daemon/fallback (larger budget for baked educational libraries). Java -Xmx follows problem memory; cgroup adds a
fixed 128 MiB allowance for JVM non-heap overhead (metaspace/code-cache/stack).
Python/Node/PHP/C/C++ use the requested total container memory; no swap is allowed.
Each container has 1 CPU, 64 PIDs, 64 open files, 16 MiB maximum individual files,
16 MiB fresh /tmp. Heavy optional numerical packages use one BLAS/OpenMP thread.

Docker startup is excluded from the internal timeout. The host also enforces a
wall deadline (runtime + 3 seconds) and output checks. A timeout/output stop force-
removes the ENTIRE container and descendants, not merely the local Docker CLI.
Normal completion, exceptions and shutdown handlers remove containers/volumes and
private CLI spool files. A killed PHP worker/engine outage can still require an
operator to remove labeled stale containers/volumes; do not prune other jobs.

Configuration and deployment
----------------------------
No third-party image is implicitly trusted. Build/review/provision images as an
operator, then configure full immutable image IDs or repo@sha256 digests. The
runtime NEVER downloads images or installs packages.

Configuration precedence:
  1. AJ_SANDBOX_IMAGE_C / CPP / JAVA / PYTHON / PHP / JS (individual env overrides).
  2. Fixed protected backend/config/sandbox_images.php, returning a PHP array
     of those lowercase language keys to full image IDs/repository digests.
  3. No configured image => SYS_ERR (no execution).

Use sandbox_images.example.php as the nonsecret shape. Restrict the config and
code/Docker CLI permissions to trusted operators. AJ_SANDBOX_DOCKER optionally
selects a trusted Docker executable, including an absolute path containing spaces.
The same protected config optionally accepts 'docker_binary' => an absolute CLI
path; AJ_SANDBOX_DOCKER still overrides it. This supports Apache service PATH
without reading/modifying secret .env files. An absent/broken engine or Windows
containers fails closed. Deployments must deny
HTTP access to backend/config and deployment files; never store secrets in images.
Restart ALL old judge_daemon.php processes, and Apache/opcache if applicable, after
deployment; an already loaded old PHP daemon will not reload code automatically.
Do not change source/verdicts of historical runs to test deployment. A retry or
rejudge creates a new judging_runs row; completed/error runs are not claimed again.

Image recipe: offline wrapper, narrow context
--------------------------------------------
The Dockerfile here adds NO packages and copies NO application files. It only
wraps an already reviewed, locally provisioned Linux toolchain image. Required
utilities are /usr/bin/env, /usr/bin/timeout, /bin/sh, cat, sleep and each language
compiler/interpreter. Supply a non-alpine/glibc Linux image with coreutils.
Build from THIS directory only; .dockerignore excludes everything else.

Important BuildKit detail: a bare sha256 image ID as FROM may be parsed as a remote
repository name, even with --pull=false. To keep provisioning strictly local, first
verify the base with docker image inspect, tag that exact ID to a localhost-only
build name, then use that LOCAL verified alias as BASE_IMAGE:

  docker image inspect <reviewed-full-base-ID>
  docker tag <reviewed-full-base-ID> localhost/algojudge-build-base:<unique-suffix>
  docker build --pull=false --network=none \
    --build-arg BASE_IMAGE=localhost/algojudge-build-base:<unique-suffix> \
    -t algojudge-sandbox-python:local backend/sandbox/docker
  docker image inspect algojudge-sandbox-python:local --format '{{.Id}}'

Configure the resulting immutable ID, NOT the build tag. Do not use --pull,
remote builders or an unprovisioned base without the operator's permission.

Library compatibility
---------------------
Host Windows Python packages and backend/sandbox/nodejs/node_modules are NOT
visible in Linux containers. Python -I ignores host PYTHONPATH and uses only
packages already baked into the chosen Linux image. Extra numpy/pandas/etc must
be reviewed and baked ahead of deployment (including pinned versions/hashes).
The runtime cannot reach PyPI, npm, databases or the internet.

npm modules must be in /opt/judge/node_modules inside the read-only image.
The wrapper relocates pre-existing /tmp/node_modules there at BUILD time; a fresh
/tmp runtime mount deliberately hides any packages left in /tmp. Node standard
modules and fs.readFileSync(0, 'utf8') remain supported. This recipe installs
nothing. Java currently uses JDK standard library only, PHP -n uses compiled-in
extensions; no host libraries/configuration are forwarded.

Verification
------------
CLI tests (configure image pins in isolated process environment):
  php backend/tests/docker_sandbox_smoke.php
  php backend/tests/docker_sandbox_isolation.php
  php backend/tests/docker_sandbox_workflow.php

The workflow suite refuses any database other than the explicit test database
algojudge_test_security_features_20261114_a94e on port 3307 and rolls back its own
rows. It bypasses .env loading. Do NOT point legacy mandatory tests at production.

Residual operational boundary
-----------------------------
Docker isolation substantially narrows the previous direct-host execution path,
but is not a claim that Linux/Docker kernel escapes are impossible. Keep the
engine/kernel patched, use a dedicated restricted judge service/host, protect the
Docker API and config, limit concurrent jobs at deployment, and monitor stale
resources. Docker access itself is privileged; a compromised trusted PHP web
application is outside this contestant-code boundary. For hostile multi-tenant
production, consider a separate judge host and stronger VM/container sandbox.
