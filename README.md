# Enhance Usage Dashboard

A lightweight, self-hosted dashboard for monitoring per-website resource usage
(CPU, memory, swap, disk IOPS, process count, connections) on
[Enhance](https://enhance.com) Application servers — similar in spirit to
CloudLinux LVE Manager's "Current Usage" view, built for Enhance's cgroup v2
architecture.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-MIT-green)

## Why this exists

Enhance isolates every website in its own cgroup v2 container, but it doesn't
ship a built-in way to see, at a glance, which sites are hitting their CPU,
memory, or I/O limits. This project reads the raw cgroup v2 accounting files
Linux already maintains and turns them into a simple table + per-site graphs.

## What it shows

Per website, per time window (1h / 24h / 7d):

| Metric | Source | Meaning |
|---|---|---|
| Avg / peak CPU | `cpu.stat` | CPU cores actually used |
| vCPU allocation | `cpu.max` | The site's CPU quota |
| Throttled count | `cpu.stat` (`nr_throttled`) | How many times the site hit its CPU limit and got paused |
| Memory used / limit | `memory.current` / `memory.max` | RAM usage vs. allocation |
| OOM killed count | `memory.events` (`oom_kill`) | How many times a process was killed for exceeding the memory limit |
| Swap used / limit | `memory.swap.current` / `memory.swap.max` | Swap usage vs. allocation |
| Swap fail count | `memory.swap.events` (`fail`) | How many times a swap allocation failed |
| Read/write IOPS | `io.stat` / `io.max` | Disk operations per second vs. limit |
| I/O throughput | `io.stat` | MB/s read and write |
| nproc | `pids.current` / `pids.max` | Process/thread count vs. limit |
| Concurrent connections | live `ss -tnp` snapshot | Established TCP connections owned by the site |

Clicking a site opens a detail view with line charts for CPU, memory, swap,
IOPS, nproc, and connections over the selected window.

## Architecture — read this before deploying

Enhance runs every website inside its own cgroup/container. **A website
container cannot read `/var/log/enhance-usage` on the host** — cgroups are
isolated by design. So this dashboard must **not** be deployed as a normal
Enhance-hosted website. Instead:

- The **logger** (`enhance-usage-logger.sh`) runs as a root cron job directly
  on the Application server (the host, not inside a website container).
- The **dashboard** (`dashboard/index.php`) runs as a **standalone systemd
  service** using PHP's built-in server, listening on its own port
  (default `8181`) — outside of Enhance's website management entirely.

Run the logger on **every** Application server where websites actually
execute. If you have multiple Application servers, each one gets its own
dashboard instance (or see [Multi-server setups](#multi-server-setups) below).

### How website ownership is resolved

Enhance names its cgroups by UUID (`/sys/fs/cgroup/websites/<uuid>/`), not by
Linux username. The logger resolves the owner of each cgroup by reading the
first running process's UID from `cgroup.procs` — the same way you'd
introspect it manually with `ps`. This is more reliable than trying to guess
cgroup paths from usernames.

## Requirements

- An Enhance Application server running a cgroup v2 kernel (default on modern
  Enhance installs)
- PHP CLI (7.4+) — used both for the logger's helper calls and to serve the
  dashboard
- Bash, awk, ss (from `iproute2`, installed by default on most distros)
- Root access (cgroup files under `/sys/fs/cgroup` require root to read some
  fields, and cron/systemd setup needs root)

## Installation

```bash
git clone https://github.com/<your-username>/enhance-usage-dashboard.git
cd enhance-usage-dashboard
sudo bash install.sh
```

This copies the scripts to `/opt/enhance-usage`, installs the systemd unit,
and prints the two manual steps you still need to do:

### 1. Set an admin password

```bash
php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT), PHP_EOL;"
```

Paste the resulting hash into `/opt/enhance-usage/dashboard/index.php`:

```php
$ADMIN_PASSWORD_HASH = 'paste-your-hash-here';
```

### 2. Add the logger to cron

```bash
crontab -e
```

```
*/5 * * * * /opt/enhance-usage/enhance-usage-logger.sh >> /var/log/enhance-usage/cron.log 2>&1
```

Every 5 minutes is a reasonable default. Lower it to `*/1 * * * *` for more
granular data — this is still cron-based sampling, not real-time
per-second monitoring like `lvetop`.

### 3. Start the dashboard

```bash
sudo systemctl enable --now enhance-usage-dashboard
```

Visit `http://<server-ip>:8181/` and log in with the password you set above.

## Securing the dashboard

The dashboard runs on a plain HTTP port with password protection, which is
**not** encrypted in transit. Do one of the following before exposing it
beyond localhost:

- **Firewall it to your own IP** (quickest):
  ```bash
  ufw allow from <your-ip> to any port 8181 proto tcp
  ufw deny 8181
  ```
- **Put it behind an nginx reverse proxy with HTTPS** (recommended for
  anything beyond quick personal use):
  ```nginx
  server {
      listen 443 ssl;
      server_name usage.yourdomain.com;
      ssl_certificate     /path/to/fullchain.pem;
      ssl_certificate_key /path/to/privkey.pem;
      location / {
          proxy_pass http://127.0.0.1:8181;
      }
  }
  ```

## Multi-server setups

Each Application server runs its own logger + dashboard, independently. If
you have multiple Application servers and want a single combined view:

1. Run the logger on every server as usual.
2. On a central server, `rsync` each server's `/var/log/enhance-usage/*.csv`
   into per-server subfolders, e.g.:
   ```bash
   rsync -az app1:/var/log/enhance-usage/ /var/log/enhance-usage/app1/
   rsync -az app2:/var/log/enhance-usage/ /var/log/enhance-usage/app2/
   ```
3. Point a single dashboard instance's `$LOG_DIR` at the combined parent
   folder (this requires a small tweak to `list_sites()` in `index.php` to
   walk subfolders — not included by default, since most users run one
   dashboard per server).

## CLI report (no web server needed)

For a quick terminal summary without the dashboard:

```bash
# One site
/opt/enhance-usage/enhance-usage-report.sh --user somesite1 --days 1

# All sites
/opt/enhance-usage/enhance-usage-report.sh --all --days 7
```

## Data format & retention

Each website gets its own CSV at `/var/log/enhance-usage/<unix_user>.csv`.
Rows older than 35 days are pruned automatically on every logger run. If you
upgrade from an older version of this project with a different CSV column
layout, the logger detects the mismatched header and automatically archives
the old file as `<user>.<timestamp>.old.csv` before starting a fresh one —
your historical data isn't deleted, just set aside.

## Troubleshooting

**Table is empty / "not enough data in this window"**
The dashboard needs at least 2 data points inside the selected window to
compute a rate (e.g. CPU cores used per second). Right after installing,
wait for 2 cron cycles (~10 minutes at the default `*/5`) before expecting
data.

**Logger prints `WARN: could not determine owner for cgroup ... - skipping`**
This means no running process was found in that cgroup at the moment the
logger ran (e.g. a site with zero traffic and no persistent workers). This is
usually harmless and resolves itself once the site has active processes.

**Charts don't render / console shows `Chart is not defined`**
The dashboard bundles Chart.js locally (`dashboard/chart.umd.min.js`) so it
works without internet access. If you deleted or didn't copy that file,
re-copy it from this repo into `/opt/enhance-usage/dashboard/`.

**Nothing shows up at all, dashboard won't load**
Check the service is running: `systemctl status enhance-usage-dashboard`.
Check the port isn't already in use, or change the port in
`systemd/enhance-usage-dashboard.service` and re-run
`systemctl daemon-reload && systemctl restart enhance-usage-dashboard`.

## Known limitations

- Sampling resolution is tied to your cron interval, not real-time.
- Concurrent connection counts come from a live `ss -tnp` snapshot at logger
  run time, not a continuous measurement — brief connection spikes between
  runs won't be captured.
- "Entry processes" and CloudLinux-style "faults by node" concepts don't have
  a direct cgroup v2 equivalent and aren't included; this dashboard reports
  what the kernel's cgroup v2 controllers expose directly.

## Contributing

Issues and PRs welcome. This is a single-file dashboard by design — please
keep `dashboard/index.php` dependency-free (no Composer, no build step) so it
stays a simple drop-in.

## License

MIT — see [LICENSE](LICENSE).
