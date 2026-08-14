# 🌐 stream-http

> An `https://` stream wrapper for PHP builds that have no sockets

[![Packagist](https://img.shields.io/packagist/v/drupflare/stream-http)](https://packagist.org/packages/drupflare/stream-http)
[![Build](https://github.com/drupflare/stream-http/actions/workflows/build.yml/badge.svg)](https://github.com/drupflare/stream-http/actions/workflows/build.yml)
[![Coverage](https://github.com/drupflare/stream-http/actions/workflows/coverage.yml/badge.svg)](https://github.com/drupflare/stream-http/actions/workflows/coverage.yml)
[![Prettier](https://github.com/drupflare/stream-http/actions/workflows/prettier.yml/badge.svg)](https://github.com/drupflare/stream-http/actions/workflows/prettier.yml)
[![codecov](https://codecov.io/gh/drupflare/stream-http/branch/master/graph/badge.svg)](https://codecov.io/gh/drupflare/stream-http)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.3-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

`file_get_contents('https://...')` works again in a PHP-in-wasm runtime, because **you** hand the
package the fetch. No sockets, no cURL, no OpenSSL transport, no framework, and no dependency on
the host it was extracted from.

---

## 📋 Table of Contents

- [Install](#-install)
- [Quick Start](#-quick-start)
- [The Fetch Contract](#-the-fetch-contract)
- [Registering From the Host](#-registering-from-the-host)
- [Out of Scope](#-out-of-scope)
- [API](#-api)
- [Reading Response Metadata](#-reading-response-metadata)
- [Testing](#-testing)
- [Related Repositories](#-related-repositories)
- [License](#-license)

---

## 📥 Install

```sh
composer require drupflare/stream-http:0.*
```

---

## 🚀 Quick Start

```php
use Drupflare\StreamHttp\HttpsStreamWrapper;

// whatever your runtime can actually do; this one is a Cloudflare Worker host call
HttpsStreamWrapper::register(static function (array $request): array {
  // perform the request here and return the reply shape below
  return ['ok' => true, 'status' => 200, 'headers' => [], 'body' => 'hello'];
});

echo file_get_contents('https://example.com/'); // hello
```

Nothing else is needed. `fopen()`, `fread()`, `fseek()`, `feof()`, `filesize()`, `file_exists()`
and `stream_get_contents()` all work from that point on, for both `http://` and `https://`.

---

## 🔌 The Fetch Contract

One callable and two array shapes are the entire integration surface.

**The request the wrapper builds:**

| Key        | Type           | Notes                                                         |
| ---------- | -------------- | ------------------------------------------------------------- |
| `url`      | `string`       | verbatim, exactly as the caller wrote it                      |
| `method`   | `string`       | upper-cased; `GET` unless a stream context says otherwise     |
| `headers`  | `array`        | name to value, from the context's `header` string **or** list |
| `body`     | `string\|null` | the context's `content`, or `null`                            |
| `redirect` | `string`       | `follow` or `manual`, from the context's `follow_location`    |

**The reply it accepts:**

| Key       | Type     | Notes                                                      |
| --------- | -------- | ---------------------------------------------------------- |
| `ok`      | `bool`   | anything other than `true` is a refusal                    |
| `body`    | `string` | **required when `ok`** — send `''` for a bodiless response |
| `status`  | `int`    | optional; cast, and reported through `responseMeta()`      |
| `headers` | `array`  | optional; non-string values are dropped rather than cast   |
| `error`   | `string` | the named reason, when `ok` is not `true`                  |

`body` is required, not defaulted to `''`. A default would make a transport that omitted the field
look like a genuinely empty 204.

---

## 🥇 Registering From the Host

**A stream wrapper has to exist before any code that uses one runs.** A service container is built
too late for that, so a service provider or a module hook is the wrong place. In the project this
was extracted from, registration happens from the JavaScript host, in
`worker/src/drupal/site-php.ts`, around the `HttpsStreamWrapper::register()` call in the boot
script — before Drupal's kernel exists.

---

## 🚫 Out of Scope

- **It does not stream.** The whole body is fetched on open and served from memory, because PHP's
  `stream_read()` is synchronous and a real streaming read would have to suspend the interpreter
  mid-call. A build without suspension can only fetch-then-read.
- **It does not write.** A write would be a POST with no way to signal completion through the
  stream API, so a non-`r` mode is refused by name and `stream_write()` returns 0.
- **`url_stat()` spends no request.** PHP stats speculatively, and a `HEAD` per stat would cost a
  request each time, so `file_exists()` answers "yes, unknown size" and `filesize()` on a URL
  nobody opened reads 0. That trade-off is pinned by an assertion rather than left to be
  rediscovered.

---

## 🧭 API

| Member                              | What it does                                                         |
| ----------------------------------- | -------------------------------------------------------------------- |
| `register(callable $fetch, ?array)` | sets the fetch and takes over the schemes; returns what it claimed   |
| `unregister(?array $schemes)`       | restores PHP's own wrappers and forgets the fetch                    |
| `setFetch(callable $fetch)`         | sets the process-wide fetch without touching the registry            |
| `fetcher()`                         | the process-wide fetch, or `null`                                    |
| `lastError()`                       | the last named refusal, because a stream call only returns `false`   |
| `new HttpsStreamWrapper(?callable)` | an instance with its own fetch, which wins over the process-wide one |
| `responseMeta()`                    | `status` and `headers` of the response this instance is serving      |
| `SCHEMES`                           | `['http', 'https']`, the registrar's default                         |

**Why both a constructor and a static registrar.** PHP constructs a registered wrapper with **no
arguments** — `stream_wrapper_register()` takes the class name, not an instance — so a
constructor-only design cannot work for the registered path. The static registrar is the production
route; the constructor parameter is the escape hatch that makes the class drivable directly, which
is how the suite exercises it without touching the wrapper registry at all.

---

## 📇 Reading Response Metadata

PHP populates `$http_response_header` only for its own http wrapper, and a userland wrapper cannot
set it. What PHP does give you is the wrapper instance itself:

```php
use Drupflare\StreamHttp\HttpsStreamWrapper;

$fh = fopen('https://example.com/', 'r');
$meta = stream_get_meta_data($fh);
// wrapper_data IS the HttpsStreamWrapper instance for a userland wrapper
['status' => $status, 'headers' => $headers] = $meta['wrapper_data']->responseMeta();
```

> [!NOTE]
> This method is **not** called `stream_metadata`. That name belongs to the `streamWrapper`
> prototype, where PHP invokes it as `stream_metadata(string $path, int $option, mixed $value)` for
> `touch()`, `chmod()` and `chown()`. A 0-argument method under that name is an
> `ArgumentCountError` waiting for the first caller who touches an `http://` path.

---

Every byte moves through the injected callable, so anything the wrapper cannot do refuses with a
reason rather than returning a plausible empty result. A silent `''` or `false` out of a stream
function cannot be told apart from a real empty response.

PHP discards the warning text, so the reason is also readable afterwards:

```php
use Drupflare\StreamHttp\HttpsStreamWrapper;

$fh = @fopen('https://example.com/', 'w');
if ($fh === false) {
  // "…HttpsStreamWrapper is read-only, so mode "w" is refused"
  echo HttpsStreamWrapper::lastError();
}
```

Every one of these is refused by name and covered by the suite:

| Situation                              | Named reason contains                     |
| -------------------------------------- | ----------------------------------------- |
| a non-read `fopen()` mode              | `is read-only, so mode "w" is refused`    |
| no fetch was ever set                  | `::register($fetch) first`                |
| the fetch threw                        | the exception class and its message       |
| the fetch returned a non-array         | `where an array was expected`             |
| `ok` is false or absent                | the reply's `error`, or `no reason given` |
| `ok` with a missing or non-string body | `send an empty string for no body`        |
| a seek outside the body                | `outside a N-byte body`                   |
| a write through a read handle          | `read-only`                               |

An explicitly empty body (`'body' => ''`) opens normally, which is the control that keeps the six
malformed-reply assertions from being vacuous.

---

## 🧪 Testing

```sh
composer install
composer test     # php -l over the tree, then 91 assertions
composer coverage # the same suite under xdebug or pcov, writing Clover to coverage/
./vendor/bin/phpcs
bun install && bun run prettier:check
```

The suite injects a recording fetch, so every request the wrapper builds is inspected and every
malformed reply is scripted. No socket, no server, no wasm runtime.

A stream wrapper is resolved by method name at runtime: rename `stream_read` and PHP never calls
it, with no error anywhere. The suite therefore drives the class through `fopen()`, `fread()` and
`fseek()` wherever it can, rather than calling methods directly.

---

## 🔗 Related Repositories

| Repository                                                      | What it is                                                    |
| --------------------------------------------------------------- | ------------------------------------------------------------- |
| [`drupflare/worker`](https://github.com/drupflare/worker)       | the consumer: Drupal 11 on Cloudflare Workers                 |
| [`drupflare/drupflare`](https://github.com/drupflare/drupflare) | the Drupal module this was extracted from; wires `Host::call` |
| [`drupflare/cartridge`](https://github.com/drupflare/cartridge) | the host side: running a blocking interpreter in a DO         |
| [`drupflare/phasm`](https://github.com/drupflare/phasm)         | builds the interpreter, including the JSPI flags above        |

---

## 📄 License

MIT (c) Gregory Mitchell 2026. See [LICENSE](LICENSE).
