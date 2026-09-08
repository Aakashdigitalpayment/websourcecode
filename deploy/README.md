# Nginx deploy notes (Apache .htaccess is ignored on nginx)

## Required
Include `deploy/nginx-security.conf` inside your `server { }` block so these
Apache protections still apply:

- Deny `/includes/`, `/cache/`, `/logs/`, `/scripts/`, `/database/`, `/core/`, `/deploy/`, `/vendor/`
- Deny credential files (`.cred*`, `.auth-secret`, `.env`, `.git`)
- Block `member/session-check.php`
- Block `install.php` when `install.lock` or `includes/database.local.php` exists
- Uploads: no PHP execution, `autoindex off`
- `/sitemap.xml` → `sitemap.php`, `/robots.txt` → `robots.php`, `/manifest.json` → `manifest.php`

## Example
See `deploy/nginx-site.example.conf`.

## Verify
```bash
sudo nginx -t
curl -I https://YOUR_HOST/includes/config.php   # expect 403
curl -I https://YOUR_HOST/scripts/smoke-security.php  # expect 403
curl -I https://YOUR_HOST/assets/uploads/           # expect 403 or empty listing
```
