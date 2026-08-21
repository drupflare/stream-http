<?php

/**
 * @file
 * Drives HttpsStreamWrapper against a fake fetch.
 *
 * The whole point of the package is that the transport is injected,
 * so the suite injects one it can inspect: every request the wrapper builds is recorded, and every
 * reply the wrapper has to cope with is scripted. That makes the refusal paths -- which are the
 * paths that matter, because a stream function signals failure with FALSE or 0 -- testable without
 * a socket, a server or a wasm runtime.
 *
 * What `php -l` cannot see: a stream wrapper is resolved by method name at runtime, so renaming
 * stream_read means PHP simply never calls it, with no error anywhere. Only driving the class
 * through fopen()/fread()/fseek() proves the prototype is still satisfied.
 *
 * Usage:
 *   php tests/run-suite.php
 */

declare(strict_types=1);

use Drupflare\StreamHttp\HttpsStreamWrapper;

require dirname(__DIR__) . '/src/HttpsStreamWrapper.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What the assertion claims.
 * @param bool $condition
 *   Whether it holds.
 * @param mixed $detail
 *   Printed on failure, so a red line says what was actually seen.
 */
function ok(string $label, bool $condition, mixed $detail = null): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
		return;
	}
	$fail++;
	echo "  FAIL $label";
	if ($detail !== null) {
		echo ' -- ' . (is_scalar($detail) ? (string) $detail : json_encode($detail));
	}
	echo "\n";
}

/**
 * A fetch that records what it was asked for and answers from a script.
 *
 * @param array $reply
 *   The reply to return for every call.
 *
 * @return array
 *   `[$fetch, $calls]` where `$calls` is an ArrayObject the caller can read afterwards.
 */
function recorder(array $reply): array
{
	$calls = new ArrayObject();
	$fetch = static function (array $request) use ($reply, $calls): array {
		$calls->append($request);
		return $reply;
	};
	return [$fetch, $calls];
}

/**
 * Builds the reply shape a healthy transport returns.
 *
 * @param string $body
 *   The response body.
 * @param int $status
 *   The HTTP status.
 * @param array $headers
 *   Response headers.
 *
 * @return array
 *   A reply the wrapper accepts.
 */
function reply(string $body, int $status = 200, array $headers = []): array
{
	return ['ok' => true, 'status' => $status, 'headers' => $headers, 'body' => $body];
}

// #region preflight
echo "Preflight\n";
ok('PHP is 8.3 or newer', PHP_VERSION_ID >= 80300, PHP_VERSION);
// STREAM_IS_URL wrappers are gated by allow_url_fopen, so a suite run with it Off would fail
// every fopen() for a reason that has nothing to do with this class
ok('allow_url_fopen is On, which STREAM_IS_URL needs', (bool) ini_get('allow_url_fopen'));
ok('the class loaded from the package PSR-4 root', class_exists(HttpsStreamWrapper::class));
// #endregion
// #region registration
echo "\nRegistration\n";
[$fetch, $calls] = recorder(reply('hello'));
$registered = HttpsStreamWrapper::register($fetch);
ok(
	'register() claims http and https',
	$registered === ['http', 'https'],
	implode(',', $registered),
);
ok('https resolves to a wrapper', in_array('https', stream_get_wrappers(), true));
ok('the fetch is readable back', HttpsStreamWrapper::fetcher() instanceof Closure);

$restored = HttpsStreamWrapper::unregister();
ok('unregister() restores both schemes', $restored === ['http', 'https'], implode(',', $restored));
ok('unregister() forgets the fetch', HttpsStreamWrapper::fetcher() === null);

$one = HttpsStreamWrapper::register($fetch, ['https']);
ok('register() can take one scheme', $one === ['https'], implode(',', $one));
HttpsStreamWrapper::unregister(['https']);
// #endregion
// #region reading through the wrapper
echo "\nReading\n";
[$fetch, $calls] = recorder(reply('the body', 201, ['content-type' => 'text/plain']));
HttpsStreamWrapper::register($fetch);

ok(
	'file_get_contents() returns the fetched body',
	@file_get_contents('https://example.test/a') === 'the body',
	@file_get_contents('https://example.test/a'),
);
ok('the fetch saw one request per open', $calls->count() >= 1, $calls->count());
$first = $calls[0];
ok(
	'the request carries the URL verbatim',
	($first['url'] ?? null) === 'https://example.test/a',
	$first['url'] ?? null,
);
ok('the default method is GET', ($first['method'] ?? null) === 'GET', $first['method'] ?? null);
ok(
	'the default is to follow redirects',
	($first['redirect'] ?? null) === 'follow',
	$first['redirect'] ?? null,
);
// array_key_exists rather than ??, because `?? 'x'` cannot tell a null value from an absent key
ok(
	'a GET carries a body key set to null',
	array_key_exists('body', $first) && $first['body'] === null,
	$first,
);

$fh = @fopen('https://example.test/b', 'r');
ok('fopen() succeeds', is_resource($fh));
ok('fread() serves a prefix', fread($fh, 3) === 'the', 'first three bytes');
ok('ftell() advanced by what was read', ftell($fh) === 3, ftell($fh));
ok('feof() is FALSE mid-body', feof($fh) === false);
ok('fseek(SEEK_SET) rewinds', fseek($fh, 0) === 0 && fread($fh, 3) === 'the');
ok('fseek(SEEK_CUR) is relative', fseek($fh, 1, SEEK_CUR) === 0 && ftell($fh) === 4, ftell($fh));
ok(
	'fseek(SEEK_END) lands on the length',
	fseek($fh, 0, SEEK_END) === 0 && ftell($fh) === 8,
	ftell($fh),
);
ok('feof() is TRUE at the end', feof($fh) === true);
ok('a read at EOF is the empty string, not FALSE', fread($fh, 8) === '');
ok('fseek() past the end is refused', @fseek($fh, 99) === -1);
ok(
	'the refusal is named',
	str_contains(HttpsStreamWrapper::lastError(), 'outside a 8-byte body'),
	HttpsStreamWrapper::lastError(),
);
ok('fstat() reports the real size', fstat($fh)['size'] === 8, fstat($fh)['size']);

$meta = stream_get_meta_data($fh);
ok(
	'wrapper_data is the wrapper instance',
	($meta['wrapper_data'] ?? null) instanceof HttpsStreamWrapper,
);
$response = $meta['wrapper_data']->responseMeta();
ok(
	'responseMeta() reports the status',
	($response['status'] ?? null) === 201,
	$response['status'] ?? null,
);
ok(
	'responseMeta() reports the headers',
	($response['headers']['content-type'] ?? null) === 'text/plain',
	$response['headers'] ?? null,
);
ok('fwrite() through a read handle writes nothing', @fwrite($fh, 'x') === 0);
ok(
	'the write refusal is named',
	str_contains(HttpsStreamWrapper::lastError(), 'read-only'),
	HttpsStreamWrapper::lastError(),
);
fclose($fh);

$before = $calls->count();
ok('file_exists() answers TRUE without a fetch', file_exists('https://example.test/c'));
ok('url_stat() spent no request', $calls->count() === $before, $calls->count() - $before);
// url_stat() reports size 0 on purpose rather than spending a request, so filesize() on a URL
// nobody opened reads 0. This pins that trade-off rather than leaving it to be rediscovered
clearstatcache();
ok(
	'filesize() on an unopened URL is 0, the documented cost of not spending a request',
	@filesize('https://example.test/d') === 0,
	@filesize('https://example.test/d'),
);
HttpsStreamWrapper::unregister();
// #endregion
// #region the stream context reaches the fetch
echo "\nStream context\n";
[$fetch, $calls] = recorder(reply('posted'));
HttpsStreamWrapper::register($fetch);
$context = stream_context_create([
	'http' => [
		'method' => 'post',
		'header' => "X-One: 1\r\nX-Two: 2\r\n\r\nnot-a-header\r\n",
		'content' => 'a=1',
		'follow_location' => 0,
	],
]);
@file_get_contents('https://example.test/post', false, $context);
$request = $calls[$calls->count() - 1];
ok(
	'the method is upper-cased',
	($request['method'] ?? null) === 'POST',
	$request['method'] ?? null,
);
ok(
	'a CRLF header string becomes a map',
	($request['headers']['X-One'] ?? null) === '1',
	$request['headers'] ?? null,
);
ok(
	'every header line is taken',
	($request['headers']['X-Two'] ?? null) === '2',
	$request['headers'] ?? null,
);
ok(
	'a line with no colon is dropped',
	!array_key_exists('not-a-header', $request['headers'] ?? []),
	$request['headers'] ?? null,
);
ok(
	'the header count is exactly the two real ones',
	count($request['headers'] ?? []) === 2,
	$request['headers'] ?? null,
);
ok(
	'content becomes the request body',
	($request['body'] ?? null) === 'a=1',
	$request['body'] ?? null,
);
ok(
	'follow_location 0 becomes manual',
	($request['redirect'] ?? null) === 'manual',
	$request['redirect'] ?? null,
);

// the list form of the header option, which PHP accepts equally
$listContext = stream_context_create(['http' => ['header' => ['X-Three: 3', '', 'bad']]]);
@file_get_contents('https://example.test/list', false, $listContext);
$request = $calls[$calls->count() - 1];
ok(
	'a header list works too',
	($request['headers']['X-Three'] ?? null) === '3',
	$request['headers'] ?? null,
);
ok(
	'an empty header line is dropped',
	count($request['headers'] ?? []) === 1,
	$request['headers'] ?? null,
);
HttpsStreamWrapper::unregister();
// #endregion
// #region every refusal is named
echo "\nRefusals\n";
[$fetch, $calls] = recorder(reply('body'));
HttpsStreamWrapper::register($fetch);
$countBefore = $calls->count();
ok('a write mode is refused', @fopen('https://example.test/w', 'w') === false);
ok(
	'the mode refusal names the mode',
	str_contains(HttpsStreamWrapper::lastError(), 'mode "w" is refused'),
	HttpsStreamWrapper::lastError(),
);
ok(
	'a refused mode spends no request',
	$calls->count() === $countBefore,
	$calls->count() - $countBefore,
);
HttpsStreamWrapper::unregister();

// no fetch at all: the wrapper is registered directly, bypassing register()
stream_wrapper_unregister('https');
stream_wrapper_register('https', HttpsStreamWrapper::class, STREAM_IS_URL);
ok('an unset fetch is refused', @fopen('https://example.test/none', 'r') === false);
ok(
	'the missing-fetch refusal says what to call',
	str_contains(HttpsStreamWrapper::lastError(), '::register($fetch) first'),
	HttpsStreamWrapper::lastError(),
);
stream_wrapper_restore('https');

$thrower = static function (array $request): array {
	throw new RuntimeException('the transport is down');
};
HttpsStreamWrapper::register($thrower);
ok(
	'a throwing fetch is refused rather than propagated',
	@fopen('https://example.test/throw', 'r') === false,
);
ok(
	'the refusal names the exception class and message',
	str_contains(HttpsStreamWrapper::lastError(), 'RuntimeException: the transport is down'),
	HttpsStreamWrapper::lastError(),
);
HttpsStreamWrapper::unregister();

// every malformed reply, and the substring its refusal must contain
$malformed = [
	'a non-array reply' => [static fn(array $r): string => 'nope', 'where an array was expected'],
	'ok false with a reason' => [
		static fn(array $r): array => ['ok' => false, 'error' => 'HTTP 503'],
		'HTTP 503',
	],
	'ok false with no reason' => [static fn(array $r): array => ['ok' => false], 'no reason given'],
	'ok missing entirely' => [static fn(array $r): array => ['body' => 'x'], 'no reason given'],
	'ok true with a null body' => [
		static fn(array $r): array => ['ok' => true, 'body' => null],
		'send an empty string',
	],
	'ok true with no body key' => [
		static fn(array $r): array => ['ok' => true],
		'send an empty string',
	],
	'ok true with an int body' => [
		static fn(array $r): array => ['ok' => true, 'body' => 7],
		'with a int body',
	],
];
foreach ($malformed as $label => [$badFetch, $expected]) {
	HttpsStreamWrapper::register($badFetch);
	$opened = @fopen('https://example.test/bad', 'r');
	ok("$label is refused", $opened === false);
	ok(
		"$label is refused by name",
		str_contains(HttpsStreamWrapper::lastError(), $expected),
		HttpsStreamWrapper::lastError(),
	);
	HttpsStreamWrapper::unregister();
}

// an empty body is a legitimate answer and must NOT be refused, which is the control that makes
// the six assertions above non-vacuous
HttpsStreamWrapper::register(static fn(array $r): array => reply(''));
$empty = @fopen('https://example.test/empty', 'r');
ok('an explicitly empty body opens', is_resource($empty));
ok('an empty body reads as the empty string', stream_get_contents($empty) === '');
ok('an empty body is at EOF immediately', feof($empty) === true);
fclose($empty);
HttpsStreamWrapper::unregister();
// #endregion
// #region the constructor takes a fetch, so the class is drivable without the registry
echo "\nInjection without registration\n";
$seen = [];
$direct = new HttpsStreamWrapper(static function (array $request) use (&$seen): array {
	$seen[] = $request;
	return reply('direct body');
});
$openedPath = null;
ok(
	'stream_open() succeeds on an injected fetch',
	$direct->stream_open('https://example.test/direct', 'r', 0, $openedPath),
);
ok(
	'the injected fetch was the one called',
	($seen[0]['url'] ?? null) === 'https://example.test/direct',
	$seen[0] ?? null,
);
ok('the whole body is readable', $direct->stream_read(11) === 'direct body');
ok('stream_read(0) is the empty string', $direct->stream_read(0) === '');
ok('stream_eof() is TRUE once drained', $direct->stream_eof() === true);
ok('stream_tell() reports the offset', $direct->stream_tell() === 11, $direct->stream_tell());
ok(
	'stream_stat() reports the size',
	$direct->stream_stat()['size'] === 11,
	$direct->stream_stat()['size'],
);
ok(
	'url_stat() claims the resource exists',
	is_array($direct->url_stat('https://example.test/x', 0)),
);
$direct->stream_close();
ok(
	'stream_close() releases the body',
	$direct->stream_stat()['size'] === 0,
	$direct->stream_stat()['size'],
);
ok('the process-wide fetch was never set', HttpsStreamWrapper::fetcher() === null);

// an instance fetch wins over the process-wide one
HttpsStreamWrapper::setFetch(static fn(array $r): array => reply('global'));
$override = new HttpsStreamWrapper(static fn(array $r): array => reply('instance'));
$openedPath = null;
$override->stream_open('https://example.test/which', 'r', 0, $openedPath);
ok('the instance fetch wins over the process-wide one', $override->stream_read(8) === 'instance');
$fallback = new HttpsStreamWrapper();
$openedPath = null;
$fallback->stream_open('https://example.test/which', 'r', 0, $openedPath);
ok(
	'an instance with no fetch falls back to the process-wide one',
	$fallback->stream_read(6) === 'global',
);
HttpsStreamWrapper::unregister();
// #endregion
// #region what the reply's headers member is allowed to be
echo "\nReply headers\n";
$wrapper = new HttpsStreamWrapper(
	static fn(array $r): array => [
		'ok' => true,
		'body' => '',
		'status' => '304',
		'headers' => [
			'etag' => 'W/"1"',
			'x-num' => 7,
			'x-list' => ['a'],
			'x-obj' => new stdClass(),
			3 => 'no-name',
		],
	],
);
$openedPath = null;
$wrapper->stream_open('https://example.test/h', 'r', 0, $openedPath);
$meta = $wrapper->responseMeta();
ok('a numeric-string status is cast to int', $meta['status'] === 304, $meta['status']);
ok(
	'a string header survives',
	($meta['headers']['etag'] ?? null) === 'W/"1"',
	$meta['headers'] ?? null,
);
ok(
	'a scalar header is stringified',
	($meta['headers']['x-num'] ?? null) === '7',
	$meta['headers'] ?? null,
);
ok(
	'an array header is dropped, not cast',
	!array_key_exists('x-list', $meta['headers']),
	$meta['headers'] ?? null,
);
ok(
	'an object header is dropped, not cast',
	!array_key_exists('x-obj', $meta['headers']),
	$meta['headers'] ?? null,
);
ok(
	'a non-string header name is dropped',
	!array_key_exists(3, $meta['headers']),
	$meta['headers'] ?? null,
);

$noHeaders = new HttpsStreamWrapper(static fn(array $r): array => ['ok' => true, 'body' => 'x']);
$openedPath = null;
$noHeaders->stream_open('https://example.test/none', 'r', 0, $openedPath);
ok(
	'a reply with no headers member reports an empty map',
	$noHeaders->responseMeta()['headers'] === [],
);
ok('a reply with no status member reports 0', $noHeaders->responseMeta()['status'] === 0);

$badHeaders = new HttpsStreamWrapper(
	static fn(array $r): array => ['ok' => true, 'body' => 'x', 'headers' => 'nope'],
);
$openedPath = null;
$badHeaders->stream_open('https://example.test/badh', 'r', 0, $openedPath);
ok(
	'a non-array headers member is dropped rather than refused',
	$badHeaders->responseMeta()['headers'] === [],
);
// #endregion
// #region the schemes constant, which the registrar defaults to
echo "\nSchemes\n";
ok(
	'SCHEMES is exactly http and https',
	HttpsStreamWrapper::SCHEMES === ['http', 'https'],
	implode(',', HttpsStreamWrapper::SCHEMES),
);
ok('the native wrappers are back at the end', in_array('https', stream_get_wrappers(), true));
// #endregion
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
