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
