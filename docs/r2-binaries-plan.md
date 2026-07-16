# Plan: Ship desktop PHP binaries via Cloudflare R2 (download-on-demand)

Status: RESEARCH + DESIGN. No code changed by this document.
Repo: `nativephp/php-bin` (this clone, branch `idea/r2-binaries`).
Goal: stop bundling `bin/<os>/<arch>/php-<version>.zip` inside the package, push the
zips to Cloudflare R2 behind `bin.nativephp.com`, and have `nativephp-desktop`
download only the one binary a given build needs — mirroring the existing
**NativePHP Mobile (`nativephp-mobile-air`)** R2/CDN + `versions.json` manifest pattern.

---

## 1. How php-bin ships today + the bloat breakdown

### 1.1 Layout & matrix (working tree)
- Path scheme: `bin/<os>/<arch>/php-<major>.<minor>.zip`.
- Active matrix (15 populated zips; `mac/x86` exists but is empty):

  | OS    | Arch        | PHP versions   |
  |-------|-------------|----------------|
  | mac   | x64, arm64  | 8.3, 8.4, 8.5  |
  | linux | x64, arm64  | 8.3, 8.4, 8.5  |
  | win   | x64         | 8.3, 8.4, 8.5  |

- Each zip is ~24–25 MB (php-8.3 ≈ 24.5 MB, php-8.4 ≈ 25.2 MB, php-8.5 ≈ 25.6 MB).
- Each zip contains a single static-php-cli binary named `php` (or `php.exe` on Windows).

### 1.2 Bloat breakdown (the real numbers)
- Working tree `bin/` = **356 MB** (15 zips × ~24 MB).
- `.git` = **4.1 GB** → `git count-objects -vH`: pack ≈ **3.29 GiB**, loose ≈ **831 MiB**, 36,627 in-pack objects.
- **~91% of the repo is git history, not the current files.** Cause: the weekly build
  workflow opens PRs that commit 15 fresh ~24 MB zips every Friday. Each weekly merge adds
  ~356 MB of new, non-deltifiable blobs (compressed binaries don't delta well). History grows ~0.35 GB/week.

So slimming has two independent parts: (a) stop committing zips going forward, and
(b) shrink the existing 4.1 GB history.

### 1.3 How the zips are built
- Tool: **static-php-cli (SPC) v2.8.5** (`crazywhalecc/static-php-cli`).
- Workflows (all in `.github/workflows/`):
  - `build-php-mac.yml` — `macos-15-intel` → mac/x64, `macos-latest` → mac/arm64
  - `build-php-linux.yml` — `ubuntu-latest` → linux/x64, `ubuntu-24.04-arm` → linux/arm64
  - `build-php-win.yml` — `windows-2025` → win/x64
  - `build-php.yml` — manual orchestrator (`workflow_dispatch`), same matrix
  - `update-ca-file.yml` — daily `cacert.pem` refresh from curl.se
- Trigger: weekly cron `0 0 * * 5` (Fri 00:00 UTC) + manual dispatch with a version choice.
- Each job: download SPC → `spc doctor` → read `php-extensions.txt` / `php-libraries.txt`
  (+ platform overrides in `build-meta/build-*-<os>.json`) → `spc download` → `spc build --build-cli`
  → zip the binary into `bin/<os>/<arch>/php-<version>.zip` → copy `license-files/*` and
  `build-meta/build-{extensions,libraries}-<os>.json`.
- **Critical step (the bloat source):** each job finishes with
  `peter-evans/create-pull-request@v8` on branch `update-php-<version>-<os>-<arch>`, committing the
  zip + metadata back into the repo. Merging these PRs is what grows git history. **Binaries are
  committed to git — they are NOT attached to GitHub Releases today.** (`.gitignore` only excludes
  `.DS_Store`, `.idea`, `vendor`.)

### 1.4 How it's published / consumed
- Published as Composer package `nativephp/php-bin` (Packagist) and npm `@nativephp/php-bin`.
- Consumed by `nativephp-desktop` as a **direct composer `require`**:
  `nativephp-desktop/composer.json` → `"nativephp/php-bin": "^1.0"`. So `composer install` in any
  desktop app pulls **the entire `bin/` tree (356 MB)** into `vendor/nativephp/php-bin/`, even though
  a single build needs exactly one zip.

### 1.5 Exactly how desktop consumes a binary (the injection point)
- Path locator: `nativephp-desktop/src/Builder/Concerns/LocatesPhpBinary.php`
  - `binaryPackageDirectory()` → `env('NATIVEPHP_PHP_BINARY_PATH', 'vendor/nativephp/php-bin/')`
  - `phpBinaryPath()` → `sourcePath(binaryPackageDirectory().'bin/')`
- OS/arch chosen at **build time**: `src/Drivers/Electron/Traits/OsAndArch.php`
  (`getDefaultOs()` maps `PHP_OS_FAMILY`; `getArchitectureForOs()`: win→x64, mac/linux→x64+arm64).
- PHP version = **host PHP** (`PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION`), wired as env vars in
  `src/Drivers/Electron/Commands/BuildCommand.php` and `src/Drivers/Electron/Traits/ExecuteCommand.php`:
  `NATIVEPHP_PHP_BINARY_VERSION`, `NATIVEPHP_PHP_BINARY_PATH`, `NATIVEPHP_BUILD_PATH`.
- **The actual extraction is in JS:** `nativephp-desktop/resources/electron/php.js`:
  ```js
  const binarySrcDir = join(phpBinaryPath, platform.os, platform.arch, 'php-' + phpVersion + '.zip');
  // unzip (yauzl) → NATIVEPHP_BUILD_PATH/php/{php|php.exe}, chmod 0755
  ```
  Invoked from `resources/electron/electron-builder.mjs` `beforePack` hook
  (`exec("node php.js --<os> --<arch>")`); the extracted binary is then bundled via
  `extraResources` and located at runtime in `resources/electron/src/main/index.js`.
- After copy, `src/Builder/Concerns/PrunesVendorDirectory.php` deletes
  `app/vendor/nativephp/php-bin` from the build output (so the 356 MB isn't shipped in the app —
  but it *is* still downloaded into every dev's `vendor/`).

**=> The single best injection point for download-on-demand is `php.js` + `phpBinaryPath()`/`BuildCommand`:**
resolve the zip locally if present, otherwise fetch the one needed zip from R2 into a cache and point
`binarySrcDir` at it. Nothing else in the pipeline needs to change.

---

## 2. The pattern to mirror: NativePHP Mobile R2/CDN pipeline

There was no R2 upload pipeline found in the mobile repos themselves — but the **client download +
manifest** pattern is fully implemented in `nativephp-mobile-air`, and it already targets
`bin.nativephp.com`. That is the reference.

### 2.1 Serving conventions (already live)
- Custom domain: **`https://bin.nativephp.com`** (used for iOS today:
  `nativephp-mobile/src/Traits/InstallsIos.php` → `zipUrl = 'https://bin.nativephp.com/nativephp-ios-2.0.0-php8.4.zip'`).
- Android (older, hardcoded) uses a CloudFront CDN:
  `nativephp-mobile/src/Traits/InstallsAndroid.php` →
  `https://d23y5k23b3lz91.cloudfront.net/android/<codename>/jniLibs[F].zip`.
- Files are served **public over a custom domain** (no presigning).

### 2.2 The manifest pattern (the one to copy) — `nativephp-mobile-air`
File: `nativephp-mobile-air/src/Traits/InstallsAndroid.php` (mirror in `InstallsIos.php`):
- Branch selector: `getBinaryBranch()` → `env('NATIVEPHP_BIN_BRANCH', 'main')`.
- Manifest URL: `https://bin.nativephp.com/{branch}/versions.json`.
- Manifest shape used by the code:
  ```json
  { "versions": { "8.3": { "android": ["...url...", "...url-icu..."], "ios": [...] },
                  "8.4": { ... }, "8.5": { ... } } }
  ```
- PHP version detection: `detectPhpVersion()` parses the app's `composer.json` `require.php`
  constraint, highest-match-wins (8.5 → 8.4 → 8.3 fallback).
- Variant selection: pick the URL containing `-icu.` (or not) — generalizable to any variant tag.
- Cache: `base_path('nativephp/binaries')`, file keyed by `basename(parse_url($url, PHP_URL_PATH))`;
  **skips re-download if the cached file exists**.
- Download: Guzzle `request('GET', $url, ['sink' => $zipFile, 'connect_timeout' => 60, 'timeout' => 600])`.
- Integrity: opens the result with `ZipArchive` to confirm it's a real zip; deletes partial/garbage
  files on failure. (No checksum yet — see gap below.)
- Offline/fallback: on manifest or download failure it `error()`s and returns; no retry, no bundled fallback.
- UX: Laravel Prompts `components->task('Downloading ...')` spinner + `twoColumnDetail` for
  PHP version / ICU / size.

### 2.3 What mobile does NOT have yet (so we should add for desktop)
- A committed/automated **R2 upload** workflow (the manifest is consumed, but its producer wasn't found in-repo).
- **Checksums** (sha256) in the manifest and verification on the client.
- Retry/backoff and a bundled fallback.

---

## 3. Cloudflare R2 mechanics (only what the design needs)
- R2 is S3-compatible. Two practical ways to serve: a **public bucket bound to a custom domain**
  (recommended — `bin.nativephp.com` already exists for mobile) or presigned URLs (not needed; the
  PHP binaries are public artifacts). The default `*.r2.dev` domain is rate-limited and not for production.
- Upload from CI with either **Wrangler** (`wrangler r2 object put`) or any **S3 client / `aws s3`**
  against the R2 S3 endpoint `https://<account_id>.r2.cloudflarestorage.com`.
- Auth: an R2 API token / access-key pair stored as GitHub Actions secrets; never in the repo.
- Set long cache TTL on immutable zips, short TTL on `versions.json`.

Sources:
- Cloudflare S3 API compatibility / public buckets + custom domain (Cloudflare docs/community).
- `cloudflare/wrangler-action` and `wrangler r2 object put` for CI uploads.

---

## 4. Proposed design

### 4.1 R2 bucket + URL scheme (mirror mobile, desktop namespace)
Public bucket bound to `bin.nativephp.com`. Object layout:

```
bin.nativephp.com/{branch}/versions.json                 # manifest (short TTL)
bin.nativephp.com/desktop/{php}/php-{php}-{os}-{arch}.zip # immutable binaries (long TTL)
# e.g. desktop/8.3/php-8.3-mac-arm64.zip
```

- `{branch}` mirrors mobile's `NATIVEPHP_BIN_BRANCH` (default `main`) so prod/preview channels coexist.
- Filenames embed os+arch+version so they're globally unique and cache-bustable.
- A single shared `versions.json` can hold both `mobile` and `desktop` keys, or desktop gets its own
  manifest; recommend a `desktop` section to keep one domain, one manifest convention.

Manifest shape (extends mobile's, adds checksums):
```json
{
  "versions": {
    "8.3": {
      "desktop": {
        "mac":   { "x64": {"url": "...", "sha256": "...", "size": 24561234},
                   "arm64": {"url": "...", "sha256": "...", "size": 24550111} },
        "linux": { "x64": {...}, "arm64": {...} },
        "win":   { "x64": {...} }
      }
    },
    "8.4": { ... }, "8.5": { ... }
  }
}
```
(Object-per-arch with explicit `sha256`/`size` — richer than mobile's bare URL array, but a superset,
so a shared manifest stays compatible.)

### 4.2 Upload process (CI) — add to php-bin, don't replace the build
- New step/workflow `.github/workflows/publish-r2.yml`, triggered on **push to `main`** (i.e. after a
  build PR merges) and/or on a release tag. Keep the existing SPC build workflows exactly as-is —
  they still produce the zips; we just change where they land.
- Steps:
  1. Compute `sha256` for each `bin/<os>/<arch>/php-<version>.zip`.
  2. Upload each zip to `desktop/{php}/php-{php}-{os}-{arch}.zip` via `wrangler r2 object put`
     (or `aws s3 cp` to the R2 endpoint).
  3. Regenerate `versions.json` for the branch from what's present (merge, don't clobber other channels)
     and upload it last with a short cache TTL.
- Secrets (names to create in repo settings; values hidden): `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`,
  `R2_SECRET_ACCESS_KEY`, `R2_BUCKET` (+ optionally `CLOUDFLARE_API_TOKEN` for wrangler).
- **Transitional:** in the first phase the build PRs can still commit zips so nothing breaks; once R2
  is authoritative, switch the build workflow to upload artifacts directly and **stop committing zips**.

### 4.3 Download-on-demand in `nativephp-desktop`
- **When:** at **build time**, inside `native:build` — specifically right before `php.js` runs. (Build
  time, not install or first-run: the binary must be present when electron-builder packs the app, and
  the desktop flow already selects os/arch/version there.) Optionally expose `php artisan native:bin:fetch`
  to pre-warm the cache.
- **New PHP class** (e.g. `src/Builder/Concerns/FetchesPhpBinary.php` or a `PhpBinaryResolver`),
  modeled on `nativephp-mobile-air`'s `installPHPAndroid()`:
  1. Resolve `{php}` = `PHP_MAJOR.MINOR` (already computed), `{os}`/`{arch}` from `OsAndArch`.
  2. If `vendor/nativephp/php-bin/bin/<os>/<arch>/php-<php>.zip` exists (legacy fat package or local
     build), use it — **zero behavior change** for anyone on the old package.
  3. Else fetch `versions.json` from `https://bin.nativephp.com/{branch}/versions.json`
     (`NATIVEPHP_BIN_BRANCH`, default `main`), look up `[php][desktop][os][arch]`.
  4. Download the zip into a cache dir (see below) if not already cached; verify `sha256` against the
     manifest; on mismatch delete + fail (and as a weaker fallback, verify it's a valid zip like mobile does).
  5. Point the build at the cached zip by setting `NATIVEPHP_PHP_BINARY_PATH` to the cache root (so the
     existing `php.js` `join(phpBinaryPath, os, arch, 'php-'+version+'.zip')` resolves unchanged) **or**
     pass the resolved absolute zip path through a new env var consumed by `php.js`.
- **Cache location:** per-user, shared across projects, e.g.
  `~/.nativephp/bin/<os>/<arch>/php-<php>.zip` (macOS/Linux) / `%LOCALAPPDATA%\nativephp\bin\...`
  (Windows). Keyed by os/arch/version (+ optional sha). Reused across builds and apps — much better than
  mobile's per-project `nativephp/binaries`.
- **os/arch/version detection:** reuse the existing desktop logic verbatim (`OsAndArch`,
  `PHP_MAJOR/MINOR_VERSION`) — no new detection code, and it already supports cross-arch builds via the
  `--x64`/`--arm64`/`--mac`/`--win` flags `php.js` reads.
- **Integrity:** sha256 from manifest (stronger than mobile). Keep mobile's "is it a real zip?" check as a backstop.
- **Offline/fallback:** if a local zip exists, never hit the network. If offline and the cache already
  has the needed zip, use it. If offline with an empty cache, fail with a clear, actionable message
  (which file, which URL, how to pre-fetch). Add a couple of retries with backoff (improvement over mobile).
- **Progress UX:** Laravel Prompts `components->task('Downloading PHP 8.3 (mac/arm64)…')` + a size /
  cached-vs-downloaded `twoColumnDetail`, matching mobile's look.

### 4.4 Slimming php-bin
- **Stop shipping `bin/` in the installable package.** Options, in order of preference:
  1. Add a `.gitattributes`/archive-exclude or a Composer `archive.exclude` so `bin/**` is excluded from
     the Composer dist tarball, while R2 holds the real artifacts. The package keeps build tooling
     (`.github/`, `build-meta/`, `php-extensions.txt`, `php-libraries.txt`, `license-files/`, `cacert.pem`).
  2. Eventually move zips out of the working tree entirely and have CI upload straight to R2.
- **Existing git-history bloat (the 4.1 GB):** this is the bulk of the win and must be handled
  separately (history rewrite — *out of scope for this read-only doc, but flagged*):
  - Use `git filter-repo` (preferred) or BFG to strip historical `bin/**/*.zip` blobs, then `gc`.
  - This **rewrites SHAs** → force-push + every clone re-clones. Coordinate with the team; tag the
    pre-rewrite state first. Composer consumers pin by version tag, not SHA, so published releases are
    unaffected as long as release tags are preserved.
  - Alternatively, start a fresh history / new default branch and archive the old one. Lower risk, loses blame.

### 4.5 Backward compatibility & rollout (versioned)
- **php-bin stays at `^1.x` shipping the zips** during transition → all existing desktop installs keep
  working untouched.
- Ship a **php-bin `2.0`** that no longer bundles `bin/` (artifacts live on R2). Desktop only requires
  `^2.0` once its download-on-demand code is released.
- Desktop's resolver checks local-first, so a desktop version with download-on-demand works against
  **both** fat `php-bin` 1.x (uses local zip) and slim 2.x (downloads). No flag day.
- Old php-bin releases keep working forever because their zips remain in their published dist tarballs;
  R2 is purely additive for new versions.

---

## 5. Smallest first slice (build this first)
1. Stand up the R2 bucket + `bin.nativephp.com` desktop prefix (reuse existing mobile domain/account).
2. **One-off manual upload** of the current 15 zips to `desktop/{php}/php-{php}-{os}-{arch}.zip` and a
   hand-written `versions.json` with sha256s. (Proves serving + manifest before touching CI.)
3. In `nativephp-desktop`, add the **resolver** (`FetchesPhpBinary`) with **local-first, then download,
   then sha256 verify**, caching to `~/.nativephp/bin/...`, and wire it into `native:build` so `php.js`
   gets a valid `NATIVEPHP_PHP_BINARY_PATH`. Gate behind an env flag (`NATIVEPHP_BIN_SOURCE=r2|local`)
   defaulting to local so it's opt-in initially.
4. Only after that works end-to-end: automate the R2 upload in php-bin CI, then plan the history rewrite.

This slice is shippable behind a flag, changes no defaults, and proves the whole download path with the
real domain before any slimming or history surgery.

---

## 6. Decisions & open questions

### Decided
- **R2 specifics:** desktop **reuses** the existing mobile R2 setup — bucket `nativephplibs`,
  account `713f4e1d515cf082921cdf5122bf1739`, domain `bin.nativephp.com`. `R2_*` secrets live in the
  `NativePHP/php-bin` GitHub Actions secrets (`R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY`).
- **Public vs presigned:** public (binaries are open-source artifacts).
- **Versioning/manifest:** desktop gets its **own separate manifest** at `{branch}/desktop/versions.json`
  — mobile and desktop serve different purposes and are not merged. `NATIVEPHP_BIN_BRANCH` convention adopted.
- **Matrix to host:** the 15 active combos (mac x64/arm64, linux x64/arm64, win x64 × 8.3/8.4/8.5). The
  `mac-x86` slot is kept but empty; missing builds are ignored (CI fills R2 on its next run). `win/arm64` later.
- **Checksum source of truth:** sha256 generated in CI at upload time, written into the manifest (+ `.sha256` sidecar).
- **Supply-chain integrity** (manifest/binary signing): deferred — sha256-from-origin for now, revisit later.

### Still open
- **Upload trigger:** the CI workflows currently upload on their existing build trigger; confirm whether to
  also publish on release tags.
- **Git-history slimming approach:** `git filter-repo`/BFG rewrite (smallest repo, breaks SHAs) vs fresh
  history vs leave history and only slim going forward. Deferred (high blast radius); do last.
- **PHP version source:** desktop derives the version from host PHP; mobile derives from `composer.json`
  `require.php`. Keep them divergent or unify?

## 7. Risks
- **History rewrite is destructive:** force-push invalidates every existing clone/fork and rewrites SHAs;
  must preserve release tags so Composer/Packagist installs of old versions keep resolving. High-blast-radius;
  do last, with backups.
- **Build-time network dependency:** desktop builds (incl. CI) now need network + R2 availability. Mitigate
  with the per-user cache, local-first resolution, retries, and a clear offline error. An R2 outage during a
  release window blocks builds — consider a mirror/fallback origin.
- **Supply-chain / integrity:** a fetched binary is executed and shipped to end users. sha256 from a manifest
  served off the same domain only protects against corruption, not a compromised origin. Consider signing the
  manifest or binaries longer-term.
- **Manifest drift / partial uploads:** publishing zips and `versions.json` non-atomically can point the
  manifest at a not-yet-uploaded object. Upload binaries first, manifest last; consider versioned manifest
  filenames.
- **Cost/egress:** R2 has zero egress fees, but a popular package downloading ~24 MB per build per dev is
  real bandwidth; the per-user cache is what keeps this sane.
- **Cache poisoning / stale cache:** os/arch/version cache key is safe, but if a binary is ever rebuilt under
  the same name the cache won't refresh — include sha in the key or bust on mismatch.
- **`mobile-air` vs `mobile` divergence:** two different mobile download implementations exist
  (`nativephp-mobile` hardcoded URLs vs `nativephp-mobile-air` manifest). Mirror **mobile-air**; confirm it's
  the current/blessed one before standardizing on it.
