# stream-http

A PHP `https://` stream wrapper backed by an **injected fetch callable**, for PHP running in wasm
where there are no sockets. Extracted from `drupflare/drupflare` (the `drupflare` Drupal module,
formerly named `cfw_capability`), and now standalone.

## Status

**Published.** `drupflare/stream-http` is live on Packagist at **v0.1.0**. The three blockers that
held it back are closed:

- the namespace is `Drupflare\StreamHttp`, which is what `composer.json` autoloads from `src/`
- there is no `Host`, no `Drupal\` reference and no framework dependency; the fetch arrives through
  `register(callable $fetch)` or the constructor
- the layout is the house tabs, and `phpcs.xml.dist` was corrected to match (see below)

`composer show --tree` on an installed copy must list `php` and nothing else. A dependency on the
module it came from would mean this is not a standalone package.

`../drupflare` still ships its own copy at `src/StreamWrapper/HttpsStreamWrapper.php`, wired to
`Host::call('cfwFetch', ...)`, and there is **no sync check between the two**. In the parent project
that exact shape of duplication went silently stale twice. The two have now diverged on purpose:
this one takes an injected callable, that one reaches for the module's `Host`. Treat them as separate
implementations of one contract, not as copies.

## Divergences from the module copy

Two defects were found here and reported; **both are now fixed in `../drupflare` and in
`worker/drupal/drupflare/`, and `assets:driver` was re-run (221,818 -> 223,056 bytes)**. Verified in
this tree, not taken on trust: `drupflare/src/StreamWrapper/HttpsStreamWrapper.php:271` is
`responseMeta()`, and `:121` refuses a reply with no `body` key. Do not re-fix them. If you do touch
that file, re-run `bun run assets:driver` in `worker/` - `assets/driver.json` is the copy that
executes on the edge.

- **`stream_metadata()` -> `responseMeta()`, in both.** That name belongs to the `streamWrapper`
  prototype, where PHP calls it as `stream_metadata(string $path, int $option, mixed $value): bool`
  for `touch()`, `chmod()` and `chown()`, so a 0-argument method under that name is an
  `ArgumentCountError` waiting for the first caller who touches an `http://` path.
- **A missing `body` key is refused, in both.** `?? ''` made a host that forgot the field
  indistinguishable from a genuine 204.

**One narrower divergence remains, and it is deliberate.** The module copy checks
`array_key_exists('body', $reply)` and then casts with `(string)`, so a `null` or integer `body`
passes and becomes `''` or `'7'`. This package requires `is_string()` and refuses anything else by
name. The module's version is right for its own caller - `Host::call()` decodes JSON from a host that
always sends a string - while this package cannot know what a consumer's callable will hand back, so
it validates rather than casts. Do not "align" them by weakening this side.

## The constraint that shapes everything

**There are no sockets.** No `fsockopen`, no `curl`, no `openssl` transport. Every byte moves through
the injected callable. That means:

- The wrapper is the _only_ way `file_get_contents('https://...')` and friends can work.
- Anything the wrapper cannot do must **refuse with a named reason**, never return a plausible empty
  result. A silent `''` or `false` from a stream function is indistinguishable from a real empty
  response, and that class of failure cost this project real time to find elsewhere.
  `lastError()` exists because PHP discards the warning text.

## Registering is mandatory, and the two build failures are different

Both are measured, and they are not the same failure. Do not merge them.

- On the shipping `ASYNCIFY=0` build (`vendor/static-free-v1`), `stream_get_wrappers()` **does**
  advertise http and https, and reading through the native wrapper throws
  `ReferenceError: Asyncify is not defined` out of a wasm import. The glue has two
  `Asyncify.handleAsync(...)` call sites and declares `Asyncify` nowhere (`grep -c` for a
  declaration returns 0), so it is a free identifier that `ASYNCIFY=0` compiled out. **PHP cannot
  catch it** — `@` and `catch (Throwable)` were both measured useless from two unrelated routes — so
  the invocation dies with no PHP fatal, no `printErr` and no logger output. This is the failure the
  wrapper works around.
- A **naive `-sJSPI` build with no `-sSUPPORT_LONGJMP=wasm`** fails differently and earlier: every
  run dies, including `<?php echo PHP_VERSION;`, with `SuspendError: trying to suspend JS frames`,
  because `pib_run` opens a `zend_try` (setjmp) before entering the VM so an `invoke_*` JS frame
  always sits underneath. `-sSUPPORT_LONGJMP=wasm` is the fix, isolated with a standalone C probe,
  and it is compile-time not link-time. This is a build-flag problem, not a wrapper problem.

Registration happens from the **HOST**, before the framework boots — in the parent project from
`worker/src/drupal/site-php.ts`, around the `HttpsStreamWrapper::register()` call. A stream wrapper
has to exist before any code that uses one runs, and a service container is built too late for that.
The class docblock used to claim a service provider did it; it never did.

## PHP's streamWrapper prototype mandates snake_case

`stream_open`, `stream_read`, `stream_write`, `stream_eof`, `stream_stat`, `url_stat`,
`stream_close` and the rest are looked up **literally by name** by the engine. Renaming them to
camelCase silently stops the wrapper working - it does not error, it just never gets called.

This is why `phpcs.xml.dist` carries a file-scoped exclusion of
`Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps` over `src/*`. That exclusion is
correct here specifically, not a convenience. Do not remove it, and do not "fix" the method names.

It is also why `php -l` is not a sufficient gate and `tests/run-suite.php` drives the class through
real `fopen()`/`fread()`/`fseek()` calls wherever it can.

## Formatting: prettier owns layout, phpcs owns meaning

PHP is formatted by `@prettier/plugin-php` at the house style - **TABS rendered 4 wide, 100-char
lines** - the same as every other language here, NOT 2-space Drupal layout. The `.prettierrc`
override that forced `useTabs: false, tabWidth: 2, braceStyle: 1tbs` on PHP was wrong and was
corrected; the PHP override now carries only `parser: php`.

phpcs cannot also be right about layout, so `phpcs.xml.dist` excludes the whitespace, brace-position
and casing sniffs with the reasoning inline, and keeps everything semantic.

Two reversals from what this file used to say, both because the tree moved from spaces to tabs:

- **`Drupal.Arrays.Array.ArrayIndentation` is now excluded.** Under tabs it asserts "parent indent +
  2 spaces" against a file with no indent spaces, so it can never pass and carries no signal.
- **`Drupal.Classes.UseGlobalClass.RedundantUseStatement` is excluded**, because the house rule is
  always `use` then the short name, with no leading-backslash inline reference even for `Closure` or
  `Throwable`. Prior art: `rom/phpcs.xml.dist:52` excludes exactly this sniff.

A malformed `phpcs.xml.dist` can **fail silently and report a fake pass**. Verify any ruleset change
by loading it. `--` inside an XML comment is invalid; this repo hit that once while the exclusions
above were being added, and phpcs did report the ruleset as invalid rather than passing, so the
failure mode is at least loud on this version.

## Rules

- Never silence PHPStan or phpcs with an ignore, a baseline entry, an inline `@var`, a cast, or a
  widened type. Fix the cause; if the rule contradicts Gregory's style, disable the rule and say
  which one and why.
- The package must stay framework-free. No `Drupal\`, no container, no `Host`.
- Comments: lowercase, terse, one line, no trailing period, only where the WHY is non-obvious. PHP
  docblocks are the exception - Drupal's capital-and-full-stop rule is correct in a `/** */` block,
  and phpcs enforces it.
- Every behaviour change ships with its test in the same change. The suite is one file
  (`tests/run-suite.php`) and the count is the release note: **91 passed, 0 failed** today. Do not
  tag on a lower number.

## Commands

```
composer test              # php -l over the tree, then the 91-assertion suite
php tests/run-suite.php    # the suite alone
./vendor/bin/phpcs
bunx prettier --check .
```
