# Publishing `drupflare/stream-http` to Packagist

Ordered steps to turn this repository into `composer require drupflare/stream-http`. Written for
the first release; the "Every release after the first" section is what you use from then on.

> [!IMPORTANT]
> **This repository has no commits yet.** `git log` on `master` reports
> `your current branch 'master' does not have any commits yet`, so step 2 is a first push, not an
> update, and step 3's `git describe` finds no tags. Both were accounted for: `release.yml`
> tolerates a tagless repository (`git describe … 2>/dev/null || echo ''`), which is the exact guard
> that let the sibling `rom` repository run its own first release at all.

## What the repository already handles

Nothing in this list needs doing again.

| Handled                                                   | Where                                 |
| --------------------------------------------------------- | ------------------------------------- |
| The package name, type, license and autoload map          | `composer.json`                       |
| No `version` field, so tags decide the version            | `composer.json` (deliberately absent) |
| The version the release workflow reads (`0.1.0`)          | `package.json`                        |
| A lean archive: tests, CI and tooling `export-ignore`d    | `.gitattributes`                      |
| `composer validate --strict` on every push                | `.github/workflows/build.yml`         |
| phpcs and `php -l` on PHP 8.3, 8.4 and 8.5                | `.github/workflows/build.yml`         |
| The 91-assertion wrapper suite on PHP 8.3, 8.4 and 8.5    | `.github/workflows/build.yml`         |
| `allow_url_fopen=1`, which `STREAM_IS_URL` needs          | `.github/workflows/build.yml`         |
| Prettier formatting                                       | `.github/workflows/prettier.yml`      |
| Tag, GitHub release and changelog, tagless-repo safe      | `.github/workflows/release.yml`       |
| `composer.lock` kept out of the repo, as a library should | `.gitignore`                          |
| The MIT license text                                      | `LICENSE`                             |

## What only you can do

Credentials and clicks. Nothing below can be scripted from inside the repository.

- Log in to the Packagist account that **already owns the `drupflare` vendor prefix**. This is not
  a free choice: `drupflare/rom` went first and is live, so it holds the prefix, and every later
  `drupflare/*` submission has to come from the same account or the halves of the project end up
  under different owners.
- Authorise Packagist against GitHub (OAuth), which is what gives you push-triggered updates.
- Click Submit on the Packagist form.
- Push the repository, then either run the `Release Project` workflow or create the tag by hand.
  The workflow reads the version from `package.json`.

---

## 0. Authentication choices, read before step 4

Three ways for a tag to reach Packagist. Pick one; they are not additive, and a half-configured
second one is how a release looks published and is not.

| Mechanism                        | What it needs                                                   | What breaks if it is absent                                                                                                                                           |
| -------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **GitHub integration (default)** | Packagist logged in with GitHub; accept the hook it offers      | Nothing; this is the path step 6 sets up                                                                                                                              |
| ~~**API token in this repo**~~   | ~~`PACKAGIST_USERNAME` + `PACKAGIST_TOKEN`~~                    | **Not wired here on purpose.** `rom` removed its equivalent step on 2026-08-12: the GitHub webhook syncs tags anyway, and a pre-submission 403 killed the release job |
| **Trusted publishing (OIDC)**    | A Packagist-side setting on the package, plus `id-token: write` | Nothing until you enable it Packagist-side                                                                                                                            |

Do not add `id-token: write` speculatively. An unused OIDC permission is a permission you have to
explain later.

---

## 1. Preflight, locally

```sh
composer validate --strict --no-check-lock
composer install
php tests/lint.php
php tests/run-suite.php
./vendor/bin/phpcs
bun install && bun run prettier:check
```

The wrapper suite must report **91 passed, 0 failed**. It needs nothing but a PHP 8.3+ binary with
`allow_url_fopen` On — no network, no server, no Drupal, no wasm runtime — because the transport is
injected and the suite injects a recording fake.

**Do not tag on a count lower than the last released one.** The assertion count is the release note
for this package; a drop means the suite was weakened rather than the wrapper simplified.

There is no PHPStan here yet. If you add one, run it with `--memory-limit=1G`: at the 128M default
it OOMs partway through and prints a small error count, which reads exactly like a pass.

## 2. Push the repository to GitHub

`origin` should be `https://github.com/drupflare/stream-http`, and the repository must be **public**
for Packagist to crawl it.

```sh
git push -u origin master
```

Confirm the GitHub Actions run is green before tagging. A tag pointing at a red commit is the one
thing you cannot fix without cutting another version.

## 3. Tag the first version

Do this **before** submitting to Packagist, so the very first crawl already finds a stable release.
Otherwise the package exists with nothing but a `dev-master` branch and every consumer has to write
`"*@dev"` until you tag.

Either run the **Release Project** workflow from the Actions tab, which reads `package.json`'s
`version` (currently `0.1.0`) and creates `v0.1.0`; or by hand:

```sh
git tag -a v0.1.0 -m "stream-http v0.1.0"
git push origin v0.1.0
```

Packagist accepts `X.Y.Z` or `vX.Y.Z`, and both produce the same Composer version. `v` matches every
other repository in this project.

**`0.x`, not `1.0.0`.** The wrapper has never run against a published fetch contract that anyone
outside this project depends on, so "stable" would be a claim the suite does not make. A `0.x`
version says the same thing as a `-beta1` suffix without needing `"minimum-stability": "beta"` in
every consumer, which is why the whole project sits in `0.*`.

## 4. Create the Packagist account and connect GitHub

1. Log in at <https://packagist.org/> with the account that owns `drupflare/rom`.
2. Use **Log in with GitHub** (or link GitHub from <https://packagist.org/profile/>). This is the
   step that lets Packagist install the push hook for you in step 6.

## 5. Submit the package

1. Go to <https://packagist.org/packages/submit>.
2. Enter `https://github.com/drupflare/stream-http`.
3. Submit. Packagist crawls immediately, reads `composer.json` from the default branch, and
   registers the name it finds **there** — `drupflare/stream-http`. It does not take the name from
   the repository URL, so the repository being called `stream-http` and the package being called
   `drupflare/stream-http` are two independent facts that happen to agree.

If Packagist reports the name is taken, do not work around it by renaming to something close. Stop,
check who owns it, and pick the name deliberately: the package name is in every consumer's
`composer.json` and is the one thing that cannot be changed quietly.

## 6. Turn on auto-updating

With GitHub connected in step 4, Packagist offers to install the hook itself — accept it, then
verify on the package page that it says the package is auto-updated.

By hand, under **Settings -> Webhooks -> Add webhook**:

| Field        | Value                                                            |
| ------------ | ---------------------------------------------------------------- |
| Payload URL  | `https://packagist.org/api/github?username=<packagist-username>` |
| Content type | `application/json`                                               |
| Secret       | your Packagist API token                                         |
| Events       | Just the push event (tags arrive as pushes)                      |

Without a hook, Packagist crawls roughly **once a week**, so a new tag can take days to become
installable. The symptom to remember: `composer require` cannot find a version you know you tagged.

## 7. Verify the publish

```sh
composer show drupflare/stream-http --all
```

Then, in a scratch directory, install it for real:

```sh
mkdir /tmp/stream-http-check && cd /tmp/stream-http-check
composer require drupflare/stream-http:0.* --prefer-source
php -r 'require "vendor/autoload.php";
	$w = new Drupflare\StreamHttp\HttpsStreamWrapper(
		fn(array $r): array => ["ok" => true, "body" => "it works"]
	);
	$p = null;
	$w->stream_open("https://example.com/", "r", 0, $p);
	echo $w->stream_read(8), PHP_EOL;'
```

Five things to confirm, in this order:

1. `composer show` lists the version you tagged, not only `dev-master`.
2. The class autoloads as `Drupflare\StreamHttp\HttpsStreamWrapper`. This is the check that would
   have caught the original blocker: the class was in `namespace Drupal\cfw_capability\StreamWrapper`
   while `composer.json` autoloaded `Drupflare\StreamHttp\` from `src/`, so it could not be loaded
   from the package **at all** and `php -l` was blind to it.
3. Nothing pulls in Drupal. `composer show --tree` should list `php` and nothing else. A dependency
   on the module it was extracted from would mean this is not a standalone package.
4. Run the suite from the installed copy with `--prefer-source`, then re-install **without** it and
   confirm the archive is lean: no `tests/`, no `.github/`, no `phpcs.xml.dist`, no `package.json`.
   What should remain is `src/`, `composer.json`, `LICENSE` and `README.md`.
5. Verify the `export-ignore` rules themselves with `git check-attr export-ignore -- <path>` before
   tagging. A directory marked `export-ignore` reports `unspecified` for the files inside it, which
   is why `.gitattributes` lists both the directory and its `/**` glob.

## 8. How constraints resolve afterwards

| Consumer writes                         | Resolves to                                                         |
| --------------------------------------- | ------------------------------------------------------------------- |
| `"drupflare/stream-http": "0.*"`        | any `0.x`; **the right choice in the beta window**                  |
| `"drupflare/stream-http": "^0.1"`       | `0.1.*` only — a caret on a `0.x` version pins the **minor**        |
| `"drupflare/stream-http": "^1.0"`       | any `1.x`; nothing satisfies it yet, so it refuses to resolve       |
| `"drupflare/stream-http": "dev-master"` | the branch tip; needs `"minimum-stability": "dev"` or a `@dev` flag |
| `"drupflare/stream-http": "*@dev"`      | what an **untagged path repository** needs                          |

The second row is the one that catches people, and it is why `0.*` is written everywhere rather than
the caret that reads more natural.

Once this package is on Packagist, any `repositories` path entry pointing at a sibling directory
comes out of the consuming project. A path repository silently wins over Packagist, which is a good
way to ship a site pinned to whatever was on your laptop.

## 9. Every release after the first

1. Land the change on `master` with CI green and the assertion count at or above the last release's.
2. Decide the number. For this package the breaking-change surface is **wider than the PHP API**,
   because the fetch contract is the real interface: treat a change to the request keys, a change to
   the reply keys, a new required field, or any new refusal where the wrapper used to answer as
   breaking. **While the package is `0.x` there is no major slot for those** — consumers write
   `0.*`, so a breaking change lands as a minor and reaches them silently. Bump the minor and say so
   in the changelog; the number cannot carry that signal on its own.
3. Bump `version` in `package.json`. It is the single source the release workflow reads, and
   forgetting it re-tags the previous version.
4. Run the **Release Project** workflow, or tag and push by hand.
5. If the fetch contract moved, name the `drupflare/drupflare` and `drupflare/worker` versions that
   implement the new shape. The two sides were written independently and agree key for key; a
   release that changes one without saying which version of the other it needs turns that into a
   silent break.

## 10. Why Packagist and not drupal.org

Worth writing down, because the package came out of a Drupal module.

drupal.org has its own packaging pipeline and its own project namespace, and contrib hosted there
must be **GPL-2.0-or-later**. This is MIT, it is **not** a Drupal module — it has no `.info.yml`, no
`Drupal\` namespace and no `drupal/core` requirement — and it lives with the rest of the project on
GitHub. So Packagist is the distribution channel and `composer require drupflare/stream-http` is the
install.
