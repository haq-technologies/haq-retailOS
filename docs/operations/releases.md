# Releases and updates

How the code is branched, how a release is made and signed, how it reaches the shops in stages, and how a shop is updated (spec section 4) [D2, D3, S18, F16, D81]. Updates are pulled by each shop's server, never pushed, and only a release signed with the offline key is installed.

## Branches

One branch lives for ever: `main`. Every other branch is short.

| Branch | Lives | What it is for |
|---|---|---|
| `main` | For ever | The one line of work. Every commit on it has passed CI, been tried by the product owner, and can be released. The "Protect main" ruleset allows changes only through a pull request with every check green, as squash merges, with no force-push |
| `fix/…`, `feat/…`, `docs/…`, `chore/…` | A day or two | One change each: off main, back through a pull request, then deleted |
| `release/YEAR.MONTH` | Only while a release already out needs a fix and main cannot be released | Cut from that release's tag. It takes fixes only, each merged to main first and then cherry-picked. The "Protect release branches" ruleset guards it as main is guarded |

There is no `develop` branch, and no `test` or `staging` branch. That is on purpose:

- **No `develop`.** A second long branch gives code a second place to wait and a second merge to forget. It suits a team that integrates for weeks before a release. Here one person builds, CI runs on every pull request, and main is always releasable, so main does that job.
- **No branch per stage.** Test, staging and the shops are stages a release passes through, not branches. The images are built once, from one commit, and those same images, pinned by digest, go to staging, to the beta shop and to every shop. A branch per stage would build the code again at each step, and what reached a shop would not be what was tested.

Work that is not finished stays on its short branch, or is merged switched off behind a shop setting. Main is never left in a state that cannot be released.

## The stages a release passes through

| Stage | Where | It moves on when |
|---|---|---|
| 1. Pull request | CI: style, static analysis, every test suite, the upgrade path, the restore drill, the image builds and scans | Every check is green |
| 2. Tried before merge | This computer: `apps/shop-server/deploy/staging.sh --build` builds the pull request's branch and installs it as a shop would (`--fresh` first for a brand-new shop). The product owner tries it (D96) | The product owner says to merge it, and it is merged |
| 3. Main | CI builds the three images once and pushes them to GHCR, tagged with the commit (`sha-<commit>`) | The images are on GHCR |
| 4. Staging | This computer: `apps/shop-server/deploy/staging.sh` installs main's images as a shop would, before a release is made | The product owner has tried it |
| 5. Beta | The beta shop: the release is published as a pre-release, and a shop on the beta channel takes it | It has traded for some days with no fault |
| 6. Stable | Every other shop: the release is marked as the latest | |

## Channels

Each shop's server is on one channel. It decides which release a plain `sudo haq update` takes.

| Channel | A plain update takes | Use it for |
|---|---|---|
| stable | The latest release | Every shop, unless there is a reason |
| beta | The newest release, a pre-release too | The one or two shops that try a release first |
| pinned | Nothing: the shop stays where it is | A shop that must not change for now, such as in its busiest week or while a fault is looked into |

```
sudo haq channel            # shows the channel
sudo haq channel beta       # sets it
sudo haq version            # the release installed, the one before it, and the channel
```

Whatever the channel, a named release is always taken: `sudo haq update --version 2026.11.3`. The installer sets the channel with `--channel`, and stable is the default.

## The release key (once)

On the offline computer that signs releases, with OpenSSL 3:

```
tools/release/new-key.sh /path/on/the/offline/computer
```

Keep `release-private.pem` there, with a copy in a safe place. Commit only `release-public.pem`, as `apps/shop-server/deploy/release-public.pem`, so every install carries it. A server installed before that has no key and refuses every update until `install.sh` is run again.

## Making a release

Versions are YEAR.MONTH.N, such as 2026.11.3. A release is one commit: its git tag, its manifest, its images and its Windows setup all name that commit. Every release carries both ways to install a shop: the images for the mini-PC, and the Windows setup with its haq-server distribution (D86, D99).

1. Merge to main and wait for CI to finish on main: it pushes that commit's images to GHCR, and keeps the haq-server distribution it built and checked from that commit.
2. Build the release's Windows setup from that commit, with the release's version, so it installs that release's images. Then download it:

   ```
   gh workflow run windows-release.yml -f version=2026.11.3 -f commit=<the commit, in full>
   gh run list --workflow windows-release.yml --limit 1
   gh run download <that run> --name haq-retail-os-windows-2026.11.3 --dir release/2026.11.3/windows
   ```

   It takes the distribution CI built from the commit, never one built again, and checks that the distribution names that commit. Before keeping the setup it runs the setup's own test on it: a counter installed silently, the setup run again as an update, then uninstalled (D137). The folder holds `HAQ-Retail-OS-Setup-2026.11.3.exe` and `haq-server.tar.gz`.
3. Write the manifest. It names the commit, pins that commit's three images by digest, and records the hash of that commit's Compose file and of the two Windows files:

   ```
   tools/release/make-manifest.sh --version 2026.11.3 --commit <the commit> --windows release/2026.11.3/windows
   ```

   With no `--commit` it takes main as GitHub has it now, and prints the commit. It refuses Windows files built from another commit than the release's, and says which commit they were built from (D137). This computer must be signed in to GHCR (`docker login ghcr.io`, with a token that has read:packages). The folder `release/2026.11.3/` holds `manifest.json`, `compose.yaml` and the two Windows files; it is never committed.
4. Sign it on the offline computer:

   ```
   tools/release/sign.sh release/2026.11.3/manifest.json /path/to/release-private.pem
   ```

5. Publish the five files as a pre-release, on the manifest's commit. The manifest tool prints this line with the commit filled in:

   ```
   gh release create 2026.11.3 release/2026.11.3/manifest.json release/2026.11.3/manifest.json.sig release/2026.11.3/compose.yaml release/2026.11.3/HAQ-Retail-OS-Setup-2026.11.3.exe release/2026.11.3/haq-server.tar.gz --title 2026.11.3 --target <commit> --prerelease
   ```

   Publishing starts the Release workflow (D70). It checks the release with the updater's own checks against the release public key on main, and checks the Windows setup and distribution against the SHA-256 the manifest signs for each, and that the distribution was built from the manifest's commit (D99, D137). Only then does it check that the tag is on the commit the manifest names and give the three images the version as their tag on GHCR, pointing at the manifest's digests; a release whose Windows files are wrong tags nothing. A version's tag is never moved. Check it is green:

   ```
   gh run list --workflow Release --limit 1
   ```

6. Update the beta shop, or name the release at any shop: `sudo haq update --version 2026.11.3`. A shop on the beta channel takes it with a plain `sudo haq update`.
7. When it has traded with no fault, mark it as the latest. From then a plain update on a stable shop takes it:

   ```
   gh release edit 2026.11.3 --prerelease=false --latest
   ```

For the very first shop there is no other shop to protect, so steps 5 and 7 can be one: publish without `--prerelease`. Either way, the release that goes live is marked as the latest. Since 22 November 2026 GitHub's latest is 2026.11.4, the first release that carries the Windows setup (D138); 2026.11.2 must never go live (D76).

Before taking a release's Windows setup to a shop, check it against the signed manifest on a computer with the repository (the Release workflow has checked it too):

```
gh release download 2026.11.3 --dir release/2026.11.3
tools/release/check-windows.sh --release release/2026.11.3
```

## A fix for a release already out

First choice: fix it on main and release main. That is the normal path, and it is right whenever main can be released.

If main holds work that must not reach the shops yet:

1. Fix it on main first, through a pull request as always. A fix that is only on a release branch is lost at the next release.
2. Make the release branch from the release's tag, once:

   ```
   git switch -c release/2026.11 2026.11.3
   git push -u origin release/2026.11
   ```

3. Cherry-pick the fix onto a short branch off it, and open a pull request against `release/2026.11`:

   ```
   git switch -c fix/the-fault-2026.11 release/2026.11
   git cherry-pick -x <the fix's commit on main>
   gh pr create --base release/2026.11
   ```

4. When it is merged, CI pushes the branch's images, tagged with the commit. Make the release from that commit, then sign and publish it as above:

   ```
   tools/release/make-manifest.sh --version 2026.11.4 --commit <the commit on release/2026.11>
   ```

5. When every shop has moved past 2026.11, delete the branch. Its tags stay.

Releases still in use are tested: CI's upgrade-path job starts from each release a shop may still run (`OLDER` in `.github/workflows/ci.yml`). Add a release there when it goes to a shop, and take it away when no shop runs it.

## Updating a shop

On the shop's server, with internet (a phone hotspot is enough):

```
sudo haq update                         # the release the shop's channel gives
sudo haq update --version 2026.11.3     # this release, whatever the channel
```

Without internet, copy the release's three files to a flash drive and run `sudo haq update --release /media/usb/2026.11.3`; the images still need a connection, or a server that already has them.

The updater checks the signature and the Compose file's hash, refuses an older release, pulls the images by digest with the shop's own read-only GHCR token (`secrets/ghcr_user` and `secrets/ghcr_token` in the install folder, if the images are private), takes a full backup, switches, and runs the health check. If the check fails, or a migration fails, it rolls back the code only, and no sale made since the update is lost. A failed migration leaves nothing behind, and the ones before it stay. The previous release's own migrate job runs first and leaves any table the new release made to it, with no grant to the app (D105). The updater says the previous release runs again only once it has started; if it has not, call HAQ support with the output of `sudo haq check`. Restoring the backup does not undo a migration, because `restore.sh` replays every archived WAL segment.

Migrations only go forward: `migrate:rollback`, `migrate:reset` and `migrate:refresh` refuse, and a change is undone by a new migration (D107). They only expand: a table or column is dropped only in a release after the one whose code stopped using it, so the release before always runs on the new schema (D106). The rollback floor is the first release after 2026.11.3, the first with D95's migration. No code-only rollback goes below it, because 2026.11.3's back office reads the pilot tables D95 dropped; no shop ran 2026.11.3.

After an update the server keeps the images of the release it runs and of the one before it, and removes older ones. The back office's server health page shows the release installed.

A release that finishes a change an earlier release began names the oldest release that can go straight to it (`--upgrade-from` when its manifest is written). A shop further behind is told to take that release first.

The updater and the other tools in the install folder come from the release the shop was installed from. A fix to the updater itself reaches a shop when `install.sh` is run again from a newer release.

## Rehearsed

On 6 November 2026, on staging with a throwaway key: a signed release installed after a full backup, and a release whose health check was made to fail rolled back to the previous one with the server still answering. `tools/release/dry-run.sh` runs in CI on every pull request: a signed release checks out, and a changed manifest, a changed Compose file, another key's signature and an older release are refused. Since 15 November 2026 it also checks that a release's tag must be on its manifest's commit, that a shop too far behind is sent to the release in between, and that a pinned shop stays where it is (D81). Since 19 November 2026 it also checks that a new shop's first install refuses what the updater refuses and installs nothing while it checks, and that a release's Windows setup and distribution must be the files its manifest signs (D99). The same day, the updater's checks were run against the published releases 2026.11.1 and 2026.11.2 by name, by the stable channel and by the beta channel, changing nothing. Since 21 November 2026 the upgrade-path test also runs a real code-only rollback in CI, from the code just before each change: its migrate job, grant check and data export on the newer schema, then its sales (D105).
