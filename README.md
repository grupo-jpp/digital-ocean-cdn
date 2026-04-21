# Digital Ocean CDN

Digital Ocean Spaces (S3-compatible) CDN integration for **AtroCore / AtroPIM / AtroDAM**.

This module adds a new **File Storage** type called **Digital Ocean Spaces**, allowing you to store files (images, documents, assets) directly in a DigitalOcean Space and optionally deliver them through the DigitalOcean CDN.

---

## Features

- New storage type `digitalOceanSpaces` registered via `app/Resources/metadata/app/storages.json`.
- Full implementation of `DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage`:
  - `upload()` — uploads files to the Space with `public-read` ACL.
  - `delete()` — removes objects from the Space.
  - `getContents()` — fetches object contents.
  - `getUrl()` — returns the CDN URL (if configured) or a presigned URL valid for 20 minutes.
  - `exists()` — checks whether the object exists.
- Uses the official [`aws/aws-sdk-php`](https://github.com/aws/aws-sdk-php) S3 client (Spaces is S3-compatible).
- Per-storage client caching for performance.
- Translations available in `en_US` and `pt_BR`.

---

## Requirements

- AtroCore `~2.1.3`
- PHP 8.1+
- `aws/aws-sdk-php` `^3.300`
- A DigitalOcean account with a Space and API keys

---

## Installation

> This module is distributed as a Git repository. To install via Composer you **must have a published Git tag** (e.g. `v1.0.7`). Composer resolves versions from tags.

### Option A — Composer with private/public Git repository (recommended)

1. In the AtroCore project `composer.json`, add the repository:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/grupo-jpp/digital-ocean-cdn.git"
    }
  ]
}
```

2. Require the module:

```sh
composer require grupo-jpp/digital-ocean-cdn:^1.0
```

> Private repository? Configure a token first:
>
> ```sh
> composer config --global --auth github-oauth.github.com <PERSONAL_ACCESS_TOKEN>
> ```

### Option B — Manual install

1. Clone or copy the module into:

```
vendor/grupo-jpp/digital-ocean-cdn
```

2. Register it in the project autoload if needed and run:

```sh
php console.php clear-cache
php console.php migrate DigitalOceanCdn
php console.php rebuild
```

### Option C — Inside a Docker/Coolify container

```sh
WEB=$(docker ps --format "{{.Names}}" | grep "^web-")
PROJ=$(docker exec $WEB ls /var/www | grep -v html)

docker exec $WEB sh -c "cd /var/www/$PROJ && composer require grupo-jpp/digital-ocean-cdn:^1.0 \
  && composer dump-autoload -o \
  && php console.php clear-cache \
  && php console.php rebuild"
```

---

// ...existing code...

## Release

Current version: **1.0.7**

### Publishing a new version

Composer installs from **Git tags**. After committing changes, create a tag and push it:

```sh
# bump the version in composer.json (optional but recommended)
git add .
git commit -m "Release 1.0.8"

# create annotated tag
git tag -a v1.0.8 -m "Release 1.0.8"

# push commits + tags
git push origin main --follow-tags
```

Then create the GitHub Release (optional, for changelog):

```sh
gh release create v1.0.8 --title "v1.0.8" --notes "Changelog..."
```

After the tag is published, upgrade on the target environment:

```sh
composer update grupo-jpp/digital-ocean-cdn
php console.php clear-cache && php console.php rebuild
```

### Versioning

This project follows **Semantic Versioning** (`MAJOR.MINOR.PATCH`):

- `MAJOR` — breaking changes in storage/connection API
- `MINOR` — new features (sync, new fields) keeping backward compatibility
- `PATCH` — bug fixes and internal adjustments

## Configuration

### Creating a Connection

1. Go to **Administration → Connections** and create a new connection of type **Digital Ocean Spaces**.
2. Fill in the fields:

| Field               | Example                                                       | Notes                                                    |
| ------------------- | ------------------------------------------------------------- | -------------------------------------------------------- |
| Region              | `sfo3`, `nyc3`, `ams3`                                        | Region code of your Space                                |
| **Endpoint**        | `https://sfo3.digitaloceanspaces.com`                         | ⚠️ Use the **regional** endpoint, **without** the bucket |
| Bucket              | `jpp-product-manager`                                         | Bucket/Space name                                        |
| Access Key          | `DO00...`                                                     | From DigitalOcean API → Spaces Keys                      |
| Secret Key          | `••••••••`                                                    | The secret paired with the access key                    |
| CDN Endpoint (opt.) | `https://jpp-product-manager.sfo3.cdn.digitaloceanspaces.com` | Used to build public URLs, if enabled                    |

> ❌ **Do not** put `https://<bucket>.<region>.digitaloceanspaces.com` in the Endpoint — the SDK will duplicate the bucket and SSL will fail with `cURL error 60`.
> ✅ Correct: `https://<region>.digitaloceanspaces.com` + bucket informed separately.

3. Click **Test Connection**. It should return success.

### Creating a Storage

1. Go to **Administration → Storages** → create a new storage of type **Digital Ocean Spaces**.
2. Link it to the connection created above (or fill credentials directly, depending on your version).
3. Enable **Sync** if you want automatic upload of newly created files.

---

## License

MIT — see `LICENSE`.

Copyright (c) 2026 Grupo JPP
