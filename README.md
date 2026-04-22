<!-- filepath: /Users/ederoliveira/Sites/digital-ocean-cdn/README.md -->

# Digital Ocean CDN

DigitalOcean Spaces (S3-compatible) storage integration for **AtroCore / AtroPIM / AtroDAM**.

This module adds a new **Connection** type and a new **File Storage** type called **Digital Ocean Spaces**, allowing you to store files (images, documents, assets) directly in a DigitalOcean Space and deliver them through the DigitalOcean CDN.

---

## Features

- New connection type `doSpaces` (region, endpoint, bucket, access key, secret key, optional CDN endpoint).
- New storage type `digitalOceanSpaces` implemented at `DigitalOceanCdn\Core\FileStorage\DigitalOceanSpacesStorage`.
- Uses the official [`aws/aws-sdk-php`](https://github.com/aws/aws-sdk-php) S3 client (Spaces is S3-compatible).
- Secret key is stored encrypted (AtroCore password field) and decrypted at runtime.
- Upload pipeline:
  - Single `PutObject` for files up to 100 MB.
  - Automatic `MultipartUploader` for files above 100 MB.
  - Chunked uploads (frontend protocol) staged locally and then pushed to Spaces.
- Public URLs built from the CDN endpoint with automatic bucket host injection.
- Thumbnail support for JPG, PNG, GIF, WebP, AVIF, PDF and SVG (original is cached locally while the thumbnail is generated).
- Scheduled job `CleanDoSpacesCache` that cleans local cache/temp/chunks directories daily.
- Translations: `en_US`, `pt_BR`.

---

## Requirements

- AtroCore `~2.1.3`
- PHP 8.1+
- `aws/aws-sdk-php` `^3.300`
- PHP extension `gd` (for image thumbnails)
- A DigitalOcean account with a Space and API keys

---

## Installation

> This module is distributed as a Git repository. Composer resolves versions from **Git tags** (e.g. `v1.0.0`).

### Option A — Composer (recommended)

1. In your AtroCore project `composer.json`, add the repository:

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
   php console.php clear-cache
   php console.php rebuild
   ```

> For private repositories, configure a token first:
>
> ```sh
> composer config --global --auth github-oauth.github.com <PERSONAL_ACCESS_TOKEN>
> ```

### Option B — Manual install

1. Copy the module into `vendor/grupo-jpp/digital-ocean-cdn`.
2. Make sure the autoload is registered and run:

   ```sh
   composer dump-autoload -o
   php console.php clear-cache
   php console.php rebuild
   ```

### Option C — Inside a Docker container

```sh
WEB=$(docker ps --format "{{.Names}}" | grep "^web-")
PROJ=$(docker exec "$WEB" ls /var/www | grep -v html)

docker exec "$WEB" sh -c "cd /var/www/$PROJ && \
  composer require grupo-jpp/digital-ocean-cdn:^1.0 && \
  composer dump-autoload -o && \
  php console.php clear-cache && \
  php console.php rebuild"
```

> Always run PHP/Composer commands as the web user (e.g. `www-data`). If you run anything as `root`, fix permissions afterwards:
>
> ```sh
> chown -R www-data:www-data data/
> chmod -R 775 data/
> ```

---

## Configuration

### 1. Create a Connection

Go to **Administration → Connections** and create a new connection of type **Digital Ocean Spaces**:

| Field               | Example                                                | Notes                                                                 |
| ------------------- | ------------------------------------------------------ | --------------------------------------------------------------------- |
| Region              | `<region>`                                             | Region code of your Space (e.g. `nyc3`, `sfo3`, `ams3`).              |
| **Endpoint**        | `https://<region>.digitaloceanspaces.com`              | ⚠️ Use the **regional** endpoint, **without** the bucket in the host. |
| Bucket              | `<bucket-name>`                                        | Bucket / Space name.                                                  |
| Access Key          | `DO00...`                                              | From DigitalOcean API → Spaces Keys.                                  |
| Secret Key          | `••••••••`                                             | The secret paired with the access key (stored encrypted).             |
| CDN Endpoint (opt.) | `https://<bucket>.<region>.cdn.digitaloceanspaces.com` | Used to build public URLs.                                            |

> ❌ Do **not** put `https://<bucket>.<region>.digitaloceanspaces.com` in the Endpoint — the SDK would duplicate the bucket in the host and SSL would fail with `cURL error 60`.
> ✅ Correct: `https://<region>.digitaloceanspaces.com` and inform the bucket in its own field.

Click **Test Connection**. It should return success.

### 2. Create a Storage

Go to **Administration → Storages** and create a new storage of type **Digital Ocean Spaces**:

- **Connection**: the one created above.
- **Bucket**: same as in the connection.
- **Key Prefix** _(optional)_: e.g. `uploads/` to organize objects inside the bucket.
- **CDN Endpoint** _(optional)_: overrides the one defined on the connection.

### 3. Use the Storage

Point the relevant Files (or the default File storage) to the new storage. New uploads will go directly to DigitalOcean Spaces.

---

## Public URLs

`getUrl()` builds the public URL in this order:

1. `doSpacesCdnEndpoint` on the Connection, if set.
2. `cdnEndpoint` on the Storage, if set.
3. Fallback: `https://<bucket>.<endpoint-host>/<key>`.

If the configured CDN endpoint does not contain the bucket in the host, the module injects it automatically. Example:

- Configured: `https://nyc3.cdn.digitaloceanspaces.com`
- Generated: `https://<bucket>.nyc3.cdn.digitaloceanspaces.com/<key>`

---

## Thumbnails

The core AtroCore thumbnail pipeline expects the original file to exist on the local filesystem. To support that from a remote storage, this module:

1. Downloads the original object from Spaces into `data/.do-spaces-cache/` (on demand).
2. Delegates to `Atro\Core\Utils\Thumbnail` to generate the thumbnail.
3. For types not supported by the resizer (e.g. SVG), the original is copied into the public thumbnails folder so the UI can still render it.

Supported types (defined by the core): JPG, PNG, GIF, WebP, AVIF, PDF, SVG.

> Video thumbnails (MOV, MP4, …) are **not** generated — this is a core limitation, not specific to this module. A separate `video-thumbnails` module (using `ffmpeg`) would be needed.

---

## Local cache directories

The module creates and uses the following directories under `data/`:

| Directory                    | Purpose                                                    |
| ---------------------------- | ---------------------------------------------------------- |
| `data/.do-spaces-cache/`     | Original files downloaded from Spaces to build thumbnails. |
| `data/.do-spaces-pdf-cache/` | Images extracted from PDFs when building thumbnails.       |
| `data/.do-spaces-tmp/`       | Temporary files during uploads.                            |
| `data/.do-spaces-chunks/`    | Staging area for chunked uploads before pushing to Spaces. |

A scheduled job cleans these directories daily (see below).

---

## Scheduled cleanup job

The module registers a scheduled job named **`CleanDoSpacesCache`**:

- Default cron: `0 3 * * *` (every day at 03:00).
- TTL: files older than **7 days** are removed from the cache directories above.
- Empty sub-directories are removed afterwards.

To activate it, go to **Administration → Scheduled Jobs → Create** and select:

- **Job**: `CleanDoSpacesCache`
- **Scheduling**: `0 3 * * *` (or any cron expression you prefer)
- **Status**: Active

You can also trigger it manually for testing:

```sh
php console.php cron
```

---

## Troubleshooting

### `NoSuchBucket` when opening a file

Public URL is missing the bucket in the host. Check the CDN Endpoint (on the Connection or on the Storage) — the module will inject the bucket automatically, but if both endpoints are empty the fallback expects a regional endpoint.

### `cURL error 60` on Test Connection

The endpoint includes the bucket in the host. Use `https://<region>.digitaloceanspaces.com` and inform the bucket in its own field.

### Thumbnail not generated

1. Is the MIME type supported by the core? (JPG / PNG / GIF / WebP / AVIF / PDF / SVG).
2. Is the `gd` extension installed and enabled? `php -m | grep gd`.
3. Check the log:

   ```sh
   tail -f data/logs/atro-*.log
   ```

### `Permission denied` on `data/cache/*`

Someone ran PHP as `root`. Fix with:

```sh
chown -R www-data:www-data data/
chmod -R 775 data/
```

### Encrypted secret / login failures to Spaces

The secret key is stored encrypted by the AtroCore password field mechanism. This module decrypts it at runtime using `Connection::decryptPassword`. If you downgraded or migrated the instance, re-enter the secret key and save the Connection again.

---

## Limitations

- `scan()` is not implemented (no automatic discovery of objects already in the bucket).
- Folder operations are no-ops: the bucket layout is flat and organized via `Key Prefix`.
- Video thumbnails are not generated (core limitation).
- Only `public-read` ACL is currently used for uploaded objects.

---

## Release

Composer installs this module from **Git tags**. To publish a new version:

```sh
# (optional) bump the version in composer.json
git add .
git commit -m "Release 1.0.1"

git tag -a v1.0.1 -m "Release 1.0.1"
git push origin main --follow-tags
```

Optionally create a GitHub Release for the changelog:

```sh
gh release create v1.0.1 --title "v1.0.1" --notes "Changelog..."
```

Upgrade on a target environment:

```sh
composer update grupo-jpp/digital-ocean-cdn
php console.php clear-cache && php console.php rebuild
```

### Versioning

This project follows **Semantic Versioning** (`MAJOR.MINOR.PATCH`):

- `MAJOR` — breaking changes in storage / connection API.
- `MINOR` — new features kept backward-compatible.
- `PATCH` — bug fixes and internal adjustments.

---

## License

MIT — see `LICENSE`.
