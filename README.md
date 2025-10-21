# Family Portal Deployment

This repository includes automation for provisioning the Family Portal application on a Raspberry Pi using `scripts/bootstrap_pi.sh`.

## Prerequisites

- Synchronize this repository onto the Raspberry Pi before running the bootstrap script (for example, via `git clone` or `rsync`).
- Ensure the user running the script can execute `sudo` without password prompts for the required operations.
- The Pi must have internet access for apt and Python package installation.

## Usage

1. SSH into the Raspberry Pi where the repository has been synced.
2. From the repository root, run the bootstrap script:
   ```bash
   bash scripts/bootstrap_pi.sh
   ```
3. The script will:
   - Install required apt packages (Python tooling, image libraries, nginx, rsync).
   - Copy the repository into `/opt/family-portal`.
   - Create a Python virtual environment at `/opt/family-portal/.venv` and install application dependencies.
   - Provision upload directories under `/var/lib/family-portal`.
   - Initialize Alembic configuration if it is missing.
   - Write the `family-portal.service` systemd unit and reload/enable/start it.

Once complete, the application runs via systemd and listens on port 8080. You can inspect its status with:

```bash
sudo systemctl status family-portal.service
```

If you wish to configure nginx as a reverse proxy or add HTTPS, extend the provisioning steps as needed.

## Backups and disaster recovery

Nightly application backups can be taken with the `scripts/backup.sh` helper. The script creates a timestamped tarball containing the application install directory (`/opt/family-portal`) and uploaded media (`/var/lib/family-portal`) inside `/var/backups/family-portal`, pruning archives older than 30 days by default.

1. Copy the sample systemd units into place and reload systemd:
   ```bash
   sudo cp deploy/systemd/family-portal-backup.* /etc/systemd/system/
   sudo systemctl daemon-reload
   ```
2. Enable and start the nightly timer:
   ```bash
   sudo systemctl enable --now family-portal-backup.timer
   ```
3. (Optional) Trigger an immediate backup and follow its logs:
   ```bash
   sudo systemctl start family-portal-backup.service
   sudo journalctl -u family-portal-backup.service -f
   ```

You can confirm that new archives are being produced by listing the backup directory:

```bash
sudo ls -lh /var/backups/family-portal
```

To restore from a backup archive, stop the running service, extract the desired tarball, and start the service again:

```bash
sudo systemctl stop family-portal.service
sudo tar -xzf /var/backups/family-portal/family-portal-<timestamp>.tar.gz -C /
sudo systemctl start family-portal.service
```

The archive contains absolute paths, so extracting at `/` reinstates both the application code under `/opt/family-portal` and the uploads under `/var/lib/family-portal`. Adjust the paths or override the script's environment variables (`APP_ROOT`, `DATA_ROOT`, `BACKUP_ROOT`, and `RETENTION_DAYS`) if your deployment differs from the defaults.
