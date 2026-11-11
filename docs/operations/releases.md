# Releases and updates

How a release is made, signed and installed (spec section 4) [D2, D3, S18, F16]. Updates are pulled by each shop's server, never pushed, and only a release signed with the offline key is installed.

## The release key (once)

On the offline computer that signs releases, with OpenSSL 3:

```
tools/release/new-key.sh /path/on/the/offline/computer
```

Keep `release-private.pem` there, with a copy in a safe place. Commit only `release-public.pem`, as `apps/shop-server/deploy/release-public.pem`, so every install carries it. A server installed before that has no key and refuses every update until `install.sh` is run again.

## Making a release

1. Merge to main; CI publishes the app, web and db images to GHCR.
2. Write the manifest. It pins the three images by digest and records the hash of the Compose file:

   ```
   tools/release/make-manifest.sh --version 2026.11.1
   ```

   Versions are YEAR.MONTH.N. The folder `release/2026.11.1/` holds `manifest.json` and `compose.yaml`; it is never committed.
3. Sign it on the offline computer:

   ```
   tools/release/sign.sh release/2026.11.1/manifest.json /path/to/release-private.pem
   ```

4. Publish the three files as a GitHub release:

   ```
   gh release create 2026.11.1 release/2026.11.1/manifest.json release/2026.11.1/manifest.json.sig release/2026.11.1/compose.yaml --title 2026.11.1
   ```

   Answer **No** when it asks whether this is a prerelease: the updater takes GitHub's latest release, which never includes a prerelease.

5. Publishing it starts the Release workflow (D70). It checks the release with the updater's own checks against the release public key on main, then gives the three images the version as their tag on GHCR, pointing at the manifest's digests; a version's tag is never moved. A new shop then installs exactly the signed images with `install.sh --tag 2026.11.1`. Check it is green:

   ```
   gh run list --workflow Release --limit 1
   ```

   For a release published before the workflow existed, run it by hand: `gh workflow run release.yml -f version=2026.11.1`.

## Updating a shop

On the shop's server, with internet (a phone hotspot is enough):

```
sudo /opt/haq-retail-os/bin/update.sh
```

Without internet, copy the release's three files to a flash drive and run `update.sh --release /media/usb/2026.11.1`; the images still need a connection, or a server that already has them.

The updater checks the signature and the Compose file's hash, refuses an older release, pulls the images by digest with the shop's own read-only GHCR token (`secrets/ghcr_user` and `secrets/ghcr_token` in the install folder, if the images are private), takes a full backup, switches, and runs the health check. If the check fails it rolls back the code only: migrations only expand, so the previous release runs on the new schema, and no sale made since the update is lost. If a migration itself failed, the backup taken just before is there for `restore.sh`.

## Rehearsed

On 6 November 2026, on staging with a throwaway key: a signed release installed after a full backup, and a release whose health check was made to fail rolled back to the previous one with the server still answering. `tools/release/dry-run.sh` runs in CI on every pull request: a signed release checks out, and a changed manifest, a changed Compose file, another key's signature and an older release are refused.
