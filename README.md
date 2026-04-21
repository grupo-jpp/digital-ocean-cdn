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

### Via Composer

```sh
composer require atrocore/digital-ocean-cdn
```

### Manual

1. Copy the module into your AtroCore installation under `vendor/atrocore/digital-ocean-cdn`.
2. Run:

```sh
php console.php clear-cache
php console.php migrate DigitalOceanCdn
```

3. Rebuild the admin UI if necessary.

---

## Configuration

1. In the admin panel, go to **Administration → Storages**.
2. (Optional but recommended) Go to **Administration → Connections** and create a **Digital Ocean Spaces** connection.
3. Click **Create Storage** and select **Digital Ocean Spaces** as the type.
4. Fill in the fields:

| Field           | Key             | Description                                             |
| --------------- | --------------- | ------------------------------------------------------- |
| Region (DO)     | `doRegion`      | e.g. `nyc3`, `ams3`, `sfo3`                             |
| Bucket / Space  | `doBucket`      | Name of your Space                                      |
| Spaces Endpoint | `doEndpoint`    | e.g. `https://nyc3.digitaloceanspaces.com`              |
| CDN Endpoint    | `doCdnEndpoint` | e.g. `https://my-space.nyc3.cdn.digitaloceanspaces.com` |
| Access Key      | `doAccessKey`   | DigitalOcean Spaces access key                          |
| Secret Key      | `doSecretKey`   | DigitalOcean Spaces secret key                          |
| Path Prefix     | `doPathPrefix`  | Optional folder prefix inside the Space                 |
| (Connection) Bucket | `doSpacesBucket` | Optional default bucket from a saved connection      |

> If **CDN Endpoint** is provided, `getUrl()` returns a direct CDN URL; otherwise a presigned URL is generated.

---

## How it works

The module is bootstrapped by `DigitalOceanCdn\Module` with load order `5200`. It registers the storage class through `app/Resources/metadata/app/storages.json`, and AtroCore picks it up automatically as an available storage option.

Object keys are built as:

```
{doPathPrefix}/{file.path}
```

---

## Localization

- English: `app/Resources/i18n/en_US/Storage.json`
- Portuguese (Brazil): `app/Resources/i18n/pt_BR/Storage.json`

---

## Release

Current version: **1.0.0**

## License

MIT — see `LICENSE`.

Copyright (c) 2026 Grupo JPP
