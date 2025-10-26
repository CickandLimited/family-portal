# Nginx deployment notes

This directory contains an example `family-portal.conf` that can be used to serve the application through Nginx on the same host that runs the FastAPI service.

## Installing the configuration

1. Ensure the application code is checked out at `/opt/family-portal` (adjust the `alias` path if you install the app elsewhere) and that the backend service is reachable on port `8080`.
2. Copy the configuration into `/etc/nginx/sites-available` and create the `sites-enabled` symlink.
3. Reload Nginx.

The `scripts/install_nginx_config.sh` helper automates these steps. Run it with `sudo` on the host:

```bash
sudo ./scripts/install_nginx_config.sh
```

The script copies the configuration, creates/updates the symlink in `/etc/nginx/sites-enabled`, verifies the Nginx syntax (`nginx -t`), and reloads the service.

Only one `default_server` should be active on the host. The provided `family-portal.conf` is designed to be that default server and assumes the application root lives at `/opt/family-portal` with static assets served from `app/static/`.

If you prefer to perform the setup manually:

```bash
sudo install -D -m 0644 deploy/nginx/family-portal.conf /etc/nginx/sites-available/family-portal.conf
sudo ln -sfn /etc/nginx/sites-available/family-portal.conf /etc/nginx/sites-enabled/family-portal.conf
sudo nginx -t
sudo systemctl reload nginx
```

## Optional HTTPS for local networks

Families that want to expose the portal on their local network with HTTPS have two lightweight options:

### 1. Self-signed certificate

1. Generate a key and certificate (replace the DNS name with whatever you advertise to clients, e.g. `family-portal.local`).

    ```bash
    sudo openssl req -x509 -nodes -days 825 \
      -newkey rsa:4096 \
      -keyout /etc/ssl/private/family-portal.key \
      -out /etc/ssl/certs/family-portal.crt \
      -subj "/CN=family-portal.local"
    ```

2. Update `family-portal.conf` to add an `listen 443 ssl` server that references the new files.
3. Import the certificate on each client device so browsers trust it. (On iOS/macOS use the Keychain, on Android/Linux import through system settings.)
4. Reload Nginx: `sudo systemctl reload nginx`.

### 2. mDNS with automated certs

If your router supports [mDNS/Bonjour](https://en.wikipedia.org/wiki/Multicast_DNS), advertise the service as `family-portal.local` and obtain a certificate via [mkcert](https://github.com/FiloSottile/mkcert) or a LAN certificate authority you control:

1. Install `mkcert` on the host and trust its root CA on all client devices.
2. Create a certificate: `mkcert family-portal.local`.
3. Place the `.pem` files in `/etc/ssl/localcerts/` (or similar) and reference them in a TLS-enabled server block in `family-portal.conf`.
4. Reload Nginx.

> **Note:** Public CAs such as Let's Encrypt do not issue certificates for `.local` domains. For remote access over the internet consider using a domain name you control and DNS-based validation.
