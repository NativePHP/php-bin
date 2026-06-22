# R2 Binaries Contract (NativePHP Desktop)

This is the **producer→consumer contract** for NativePHP **desktop** PHP binaries.
The producer is this repo's CI (`nativephp/php-bin`); the consumer is the desktop
half (`nativephp-desktop`'s binary resolver). It mirrors the NativePHP **Mobile**
pipeline (`nativephp-php-bin-mobile` → `nativephp-mobile-air`) and stays
backward-compatible with its manifest shape.

> Status: producer half implemented on branch `idea/r2-binaries`. The consumer
> half is built against this document.

---

## 1. Manifest URL

```
https://bin.nativephp.com/{branch}/versions.json
```

- `{branch}` is the git branch the build ran on (`github.ref_name`), e.g. `main`.
  Each branch publishes to its own directory so prod/preview channels coexist.
- The desktop consumer selects the branch via `NATIVEPHP_BIN_BRANCH`
  (default `main`) — identical to mobile's
  `nativephp-mobile-air/src/Traits/InstallsAndroid.php::getBinaryBranch()`.
- Production manifest: `https://bin.nativephp.com/main/versions.json`.

The manifest is uploaded **last** (after every binary it references), with
`Cache-Control: no-cache, no-store` so consumers always see the current set.

---

## 2. `versions.json` schema

```jsonc
{
  "updated_at": "2026-06-22T00:00:00Z",      // ISO-8601 UTC, when the manifest was regenerated
  "versions": {
    "<phpVersion>": {                          // PHP minor: "8.3", "8.4", "8.5"
      "<platformKey>": [ <entry>, ... ]        // platformKey = "{os}-{arch}" (see §4)
    }
  }
}
```

Shape: `versions[phpVersion][platformKey] = [entries]`.

### Entry forms (backward-compatible)

An entry is **either** a plain URL string (mobile-compatible) **or** an object:

```jsonc
// Rich object form (what this producer emits):
{ "url": "https://bin.nativephp.com/main/mac/arm64/php-8.3.zip",
  "sha256": "3f1c...e9",          // hex SHA-256 of the zip; verify after download
  "size": 24563319 }              // exact byte size of the zip

// Plain-string form (also valid; mobile manifests use this):
"https://bin.nativephp.com/main/mac/arm64/php-8.3.zip"
```

Consumers MUST accept both forms: if an entry is a string, treat it as `url` with
no integrity metadata; if an object, prefer `sha256`/`size` for verification.

The value is always an **array** (even with a single binary) — this matches the
mobile manifest, where the array holds variants (e.g. ICU vs non-ICU). Desktop
currently has one binary per `(phpVersion, platformKey)`, so the array has 0 or 1
entries today, but consumers must iterate it.

### Example (`8.3` block, abridged)

```json
{
  "updated_at": "2026-06-22T00:00:00Z",
  "versions": {
    "8.3": {
      "mac-arm64":  [ { "url": "https://bin.nativephp.com/main/mac/arm64/php-8.3.zip",  "sha256": "<hex>", "size": 24563319 } ],
      "mac-x64":    [ { "url": "https://bin.nativephp.com/main/mac/x64/php-8.3.zip",    "sha256": "<hex>", "size": 24550111 } ],
      "mac-x86":    [],
      "linux-x64":  [ { "url": "https://bin.nativephp.com/main/linux/x64/php-8.3.zip",  "sha256": "<hex>", "size": 23994607 } ],
      "linux-arm64":[ { "url": "https://bin.nativephp.com/main/linux/arm64/php-8.3.zip","sha256": "<hex>", "size": 24407355 } ],
      "win-x64":    [ { "url": "https://bin.nativephp.com/main/win/x64/php-8.3.zip",    "sha256": "<hex>", "size": 24196039 } ]
    },
    "8.4": { "...": "..." },
    "8.5": { "...": "..." }
  }
}
```

- **`mac-x86` is always present** even though it is empty today (project decision),
  so consumers can rely on the full slot set existing.
- A platformKey maps to `[]` when no binary for that slot is in R2 yet.

---

## 3. PHP versions

`8.3`, `8.4`, `8.5` (the build matrix). Consumers derive the needed PHP minor
from the host PHP (`PHP_MAJOR_VERSION.PHP_MINOR_VERSION`) at build time.

---

## 4. `platformKey` convention

`platformKey = "{os}-{arch}"` — a flat key, lowercase, hyphen-separated:

| platformKey   | os    | arch  | Built on (runner)            |
|---------------|-------|-------|------------------------------|
| `mac-arm64`   | mac   | arm64 | `macos-latest`               |
| `mac-x64`     | mac   | x64   | `macos-15-intel`             |
| `mac-x86`     | mac   | x86   | (slot reserved, empty today) |
| `linux-x64`   | linux | x64   | `ubuntu-latest`              |
| `linux-arm64` | linux | arm64 | `ubuntu-24.04-arm`           |
| `win-x64`     | win   | x64   | `windows-2025`               |

`os` ∈ {`mac`, `linux`, `win`}; `arch` ∈ {`arm64`, `x64`, `x86`}. The os/arch
naming matches the in-repo `bin/<os>/<arch>/` layout exactly (so a consumer can
map `platformKey` ↔ object path by replacing `-` with `/`).

---

## 5. R2 object path & naming

Public bucket bound to the custom domain `bin.nativephp.com` (the same
Cloudflare R2 setup the mobile pipeline uses; S3 endpoint
`https://713f4e1d515cf082921cdf5122bf1739.r2.cloudflarestorage.com`, bucket
`nativephplibs`).

```
bin.nativephp.com/{branch}/{os}/{arch}/php-{phpVersion}.zip          # binary  (immutable)
bin.nativephp.com/{branch}/{os}/{arch}/php-{phpVersion}.zip.sha256   # checksum sidecar
bin.nativephp.com/{branch}/versions.json                             # manifest (short TTL)
```

Examples:

```
bin.nativephp.com/main/mac/arm64/php-8.3.zip
bin.nativephp.com/main/mac/arm64/php-8.3.zip.sha256
bin.nativephp.com/main/win/x64/php-8.5.zip
bin.nativephp.com/main/versions.json
```

- The path mirrors the original in-repo `bin/<os>/<arch>/php-<ver>.zip` layout,
  so nothing about the directory structure changes — only the storage location.
- Each zip contains a single static-php-cli binary named `php` (or `php.exe` on
  Windows), unchanged from the committed-zip era.
- `*.zip` and `*.zip.sha256` objects are immutable
  (`Cache-Control: public, max-age=31536000, immutable`); `versions.json` is
  `no-cache, no-store`.
- The `.sha256` sidecar holds the hex digest followed by the filename
  (`<sha256>  php-8.3.zip`), i.e. the standard `sha256sum` output line. The
  manifest copies just the hex digest into each entry's `sha256` field.

### Cross-channel coexistence (shared bucket)

Mobile already uses this bucket/domain with paths
`{branch}/{minor}/{android,ios}/...`. Desktop uses `{branch}/{os}/{arch}/...`
where `os ∈ {mac, linux, win}`, so **binary paths never collide** with mobile's
`{minor}/...` (minor = `8.3` etc.). The **`{branch}/versions.json` filename is
shared** between mobile and desktop — see the open question in §7.

---

## 6. Upload trigger (producer)

Same trigger as the mobile build (`nativephp-php-bin-mobile/.github/workflows/build.yml`):

- **Schedule:** weekly cron `0 0 * * 5` (Fridays 00:00 UTC). PHP releases drop on
  Thursdays, so Friday catches a fresh release within a day.
- **Manual:** `workflow_dispatch` with a `version` choice (`all` | `8.3` | `8.4` | `8.5`).

Per-platform workflows (`build-php-mac.yml`, `build-php-linux.yml`,
`build-php-win.yml`) each:

1. Build the zip with static-php-cli (unchanged).
2. **Upload binaries FIRST** (`aws s3 cp` to the R2 S3 endpoint) — the zip and its
   `.sha256` sidecar.
3. A dependent `manifest` job regenerates `versions.json` from the **full R2
   listing** (so it reflects every platform actually present) and uploads it
   **LAST**. This ordering guarantees the manifest never points at a
   not-yet-uploaded object.

There is also a manual `build-php.yml` orchestrator (`workflow_dispatch` only)
covering the whole matrix in one run, with the same upload + manifest behavior.

### Secrets / auth (names only)

| Secret name            | Used as env var          | Purpose                  |
|------------------------|--------------------------|--------------------------|
| `R2_ACCESS_KEY_ID`     | `AWS_ACCESS_KEY_ID`      | Cloudflare R2 access key |
| `R2_SECRET_ACCESS_KEY` | `AWS_SECRET_ACCESS_KEY`  | Cloudflare R2 secret key |

`AWS_DEFAULT_REGION=auto`. These are the **same secret names** the mobile build
uses; the standard `aws` CLI talks to R2 via `--endpoint-url`. No tokens are
stored in the repo. (The previous `PAT` secret used by the now-removed
`create-pull-request` step is no longer needed by these workflows.)

---

## 7. Notes / open questions for the consumer half

- **Shared `versions.json` collision:** mobile and desktop both write
  `{branch}/versions.json` but with different shapes
  (`versions[minor].{android,ios}` vs `versions[minor][platformKey]`). As written,
  whichever pipeline runs last overwrites the other's manifest. Resolve by one of:
  a distinct desktop manifest path (e.g. `{branch}/desktop/versions.json` or
  `{branch}/versions-desktop.json`), or a **merged** manifest where the manifest
  generator reads the existing object and merges desktop keys into it. **This
  producer currently writes the desktop-only manifest to
  `{branch}/versions.json`** — confirm the desired path before going live.
- **Bucket / account / endpoint** (`nativephplibs`,
  `713f4e1d515cf082921cdf5122bf1739`) are copied from the mobile workflow; confirm
  desktop is meant to share that exact bucket and account.
- **Consumer integrity check:** verify the downloaded zip's SHA-256 against the
  manifest `sha256`; on mismatch, delete and fail. Keep mobile's "is it a real
  zip?" `ZipArchive` open-check as a backstop for plain-string entries.
- **`win-arm64`** is not built today; add a `win-arm64` platformKey when it is.
