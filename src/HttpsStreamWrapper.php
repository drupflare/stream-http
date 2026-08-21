<?php

declare(strict_types=1);

namespace Drupflare\StreamHttp;

use Closure;
use Throwable;

/**
 * An http:// and https:// stream wrapper backed by an injected fetch callable.
 *
 * In a PHP-in-wasm runtime there are no sockets, so overriding an HTTP
 * client's own handler stack fixes that one client and nothing else. Any vendor or contrib code
 * calling file_get_contents('https://...') either fails with "Unable to find the wrapper" or -- on
 * a build whose glue still references a suspension helper the link removed -- dies with a JS
 * ReferenceError that PHP cannot catch. Both failures are per-call and late, which is the worst
 * shape for diagnosis. Shadowing both schemes with this class makes the outcome a documented
 * refusal instead.
 *
 * **It does not stream.** The whole body is fetched on open and
 * served from memory, because PHP's stream_read() is synchronous and a real streaming read would
 * have to suspend the interpreter mid-call. A build without suspension can only fetch-then-read.
 *
 * **The fetch is injected**, never reached for. This package has no host, no service container
 * and no framework; register() takes the callable and the wrapper calls nothing else. See README
 * for the request and reply shape.
 *
 * **Register from the host, before the framework boots.** A stream wrapper has to exist before any
 * code that uses one runs, and a service container is built too late for that.
 */
class HttpsStreamWrapper
{
	/**
	 * The two schemes this wrapper answers for.
	 */
	public const SCHEMES = ['http', 'https'];

	/**
	 * The stream context, set by PHP on the instance it creates.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The process-wide fetch, set by register() or setFetch().
	 */
	private static ?Closure $fetch = null;

	/**
	 * The last named refusal, readable after a call returned false.
	 */
	private static string $lastError = '';

	/**
	 * This instance's fetch, when one was injected through the constructor.
	 */
	private ?Closure $ownFetch = null;

	/**
	 * The fetched body.
	 */
	private string $body = '';

	/**
	 * Read offset into $body.
	 */
	private int $position = 0;

	/**
	 * Response headers from the last open, as the fetch reported them.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * HTTP status from the last open.
	 */
	private int $status = 0;

	/**
	 * Constructs a wrapper, optionally with its own fetch.
	 *
	 * @param callable|null $fetch
	 *   A fetch for this instance only. PHP constructs a registered wrapper with no arguments, so
	 *   the parameter is optional by necessity -- it exists for direct construction in a test or a
	 *   caller that wants one URL fetched through its own transport.
	 */
	public function __construct(?callable $fetch = null)
	{
		$this->ownFetch = $fetch === null ? null : Closure::fromCallable($fetch);
	}

	/**
	 * Registers this wrapper for http and https, replacing anything present.
	 *
	 * @param callable $fetch
	 *   Receives the request array and returns the reply array; see README for both shapes.
	 * @param array $schemes
	 *   Which schemes to take over. Defaults to both.
	 *
	 * @return array
	 *   The schemes actually registered, in the order given.
	 */
	public static function register(callable $fetch, array $schemes = self::SCHEMES): array
	{
		self::setFetch($fetch);
		$registered = [];
		foreach ($schemes as $scheme) {
			$scheme = (string) $scheme;
			if (in_array($scheme, stream_get_wrappers(), true)) {
				// a wrapper cannot be replaced without unregistering it first
				@stream_wrapper_unregister($scheme);
			}
			if (@stream_wrapper_register($scheme, static::class, STREAM_IS_URL)) {
				$registered[] = $scheme;
			}
		}
		return $registered;
	}

	/**
	 * Hands the schemes back to whatever PHP had, and forgets the fetch.
	 *
	 * @param array $schemes
	 *   Which schemes to restore. Defaults to both.
	 *
	 * @return array
	 *   The schemes actually restored.
	 */
	public static function unregister(array $schemes = self::SCHEMES): array
	{
		$restored = [];
		foreach ($schemes as $scheme) {
			if (@stream_wrapper_restore((string) $scheme)) {
				$restored[] = (string) $scheme;
			}
		}
		self::$fetch = null;
		return $restored;
	}

	/**
	 * Sets the process-wide fetch without touching the wrapper registry.
	 */
	public static function setFetch(callable $fetch): void
	{
		self::$fetch = Closure::fromCallable($fetch);
	}

	/**
	 * The process-wide fetch, or NULL when none has been set.
	 */
	public static function fetcher(): ?Closure
	{
		return self::$fetch;
	}

	/**
	 * The last named refusal this class produced, or the empty string.
	 *
	 * Exists because a stream function signals failure with FALSE or 0, which is indistinguishable
	 * from a real empty result, and PHP discards the warning text.
	 */
	public static function lastError(): string
	{
		return self::$lastError;
	}

	/**
	 * Opens a URL by fetching it whole.
	 *
	 * @param string $path
	 *   The URL, exactly as the caller wrote it.
	 * @param string $mode
	 *   The fopen() mode; anything that is not a read is refused.
	 * @param int $options
	 *   Stream flags; STREAM_REPORT_ERRORS asks for a warning.
	 * @param string|null $opened_path
	 *   Set to the URL when PHP asked for it.
	 *
	 * @return bool
	 *   TRUE once the body is in memory.
	 */
	public function stream_open(
		string $path,
		string $mode,
		int $options,
		?string &$opened_path,
	): bool {
		if (!str_starts_with($mode, 'r')) {
			// a write would be a POST with no way to signal completion through the stream API, so
			// it is refused rather than silently dropped
			return $this->fail(
				$options,
				sprintf('%s is read-only, so mode "%s" is refused', static::class, $mode),
			);
		}

		$fetch = $this->ownFetch ?? self::$fetch;
		if ($fetch === null) {
			return $this->fail(
				$options,
				sprintf(
					'%s has no fetch: call %s::register($fetch) first',
					static::class,
					static::class,
				),
			);
		}

		try {
			$reply = $fetch($this->request($path));
		} catch (Throwable $e) {
			return $this->fail(
				$options,
				sprintf('fetch threw for %s: %s: %s', $path, get_class($e), $e->getMessage()),
			);
		}

		if (!is_array($reply)) {
			return $this->fail(
				$options,
				sprintf(
					'fetch returned %s for %s where an array was expected',
					get_debug_type($reply),
					$path,
				),
			);
		}
		if (($reply['ok'] ?? false) !== true) {
			return $this->fail(
				$options,
				sprintf(
					'fetch failed for %s: %s',
					$path,
					(string) ($reply['error'] ?? 'no reason given'),
				),
			);
		}
		// a bodiless reply must say so with an empty string. Defaulting an absent key to '' would
		// make a transport that forgot the field indistinguishable from a genuinely empty response
		if (!is_string($reply['body'] ?? null)) {
			return $this->fail(
				$options,
				sprintf(
					'fetch for %s reported ok with a %s body; send an empty string for no body',
					$path,
					get_debug_type($reply['body'] ?? null),
				),
			);
		}

		$this->body = $reply['body'];
		$this->status = (int) ($reply['status'] ?? 0);
		$this->headers = self::stringMap($reply['headers'] ?? []);
		$this->position = 0;
		if ($opened_path !== null) {
			$opened_path = $path;
		}

		return true;
	}

	/**
	 * Serves bytes from the response already held in memory.
	 *
	 * There is no incremental read: stream_open() fetched the whole body, because a fetch returns a
	 * complete response rather than a socket.
	 *
	 * @param int $count
	 *   How many bytes PHP is asking for.
	 *
	 * @return string
	 *   Up to $count bytes, or the empty string at EOF.
	 */
	public function stream_read(int $count): string
	{
		if ($count <= 0) {
			return '';
		}
		$chunk = substr($this->body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	/**
	 * Refuses writes; this wrapper is read-only.
	 *
	 * @param string $data
	 *   Ignored, but its length is named in the refusal.
	 *
	 * @return int
	 *   Always 0, which is how PHP learns the write did not happen.
	 */
	public function stream_write(string $data): int
	{
		self::$lastError = sprintf(
			'%s is read-only; %d byte(s) were not written',
			static::class,
			strlen($data),
		);
		return 0;
	}

	/**
	 * Reports whether the read position has reached the end of the body.
	 *
	 * @return bool
	 *   TRUE once every byte has been read.
	 */
	public function stream_eof(): bool
	{
		return $this->position >= strlen($this->body);
	}

	/**
	 * Reports the current read position.
	 *
	 * @return int
	 *   Offset in bytes from the start of the body.
	 */
	public function stream_tell(): int
	{
		return $this->position;
	}

	/**
	 * Moves the read position, refusing to land outside the body.
	 *
	 * @param int $offset
	 *   Offset to seek to, interpreted according to $whence.
	 * @param int $whence
	 *   One of SEEK_SET, SEEK_CUR or SEEK_END.
	 *
	 * @return bool
	 *   TRUE on success, FALSE if the target is out of range.
	 */
	public function stream_seek(int $offset, int $whence = SEEK_SET): bool
	{
		$target = match ($whence) {
			SEEK_CUR => $this->position + $offset,
			SEEK_END => strlen($this->body) + $offset,
			default => $offset,
		};
		if ($target < 0 || $target > strlen($this->body)) {
			self::$lastError = sprintf(
				'seek to %d is outside a %d-byte body',
				$target,
				strlen($this->body),
			);
			return false;
		}
		$this->position = $target;
		return true;
	}

	/**
	 * Describes the open stream for fstat() and filesize().
	 *
	 * @return array
	 *   A stat array; only size and mode are meaningful here.
	 */
	public function stream_stat(): array
	{
		// size is what file_get_contents() and filesize() actually read
		return ['size' => strlen($this->body), 'mode' => 0100444];
	}

	/**
	 * Describes a URL without opening it, for file_exists() and friends.
	 *
	 * @param string $path
	 *   The URL being stat'd.
	 * @param int $flags
	 *   Stat flags from PHP; not used, since no request is made.
	 *
	 * @return array|false
	 *   A stat array claiming the resource exists with an unknown size.
	 */
	public function url_stat(string $path, int $flags): array|false
	{
		// a HEAD would cost a request per stat, and PHP stats speculatively, so this reports
		// "exists, unknown size" rather than spending one
		return ['size' => 0, 'mode' => 0100444];
	}

	/**
	 * Releases the buffered body.
	 */
	public function stream_close(): void
	{
		$this->body = '';
		$this->position = 0;
	}

	/**
	 * The status and headers of the response this instance is serving.
	 *
	 * Reachable from a file handle as
	 * `stream_get_meta_data($fh)['wrapper_data']->responseMeta()`, because PHP puts the wrapper
	 * instance itself in `wrapper_data` for a userland wrapper. `$http_response_header` is
	 * populated only by PHP's own http wrapper and cannot be set from here.
	 *
	 * NOT called `stream_metadata`: that name belongs to the streamWrapper prototype, where it is
	 * `stream_metadata(string $path, int $option, mixed $value): bool` and PHP invokes it for
	 * touch(), chmod() and chown() on the URL. A 0-argument method under that name is an
	 * ArgumentCountError waiting for the first caller who touches an http:// path.
	 *
	 * @return array
	 *   `status` and `headers`.
	 */
	public function responseMeta(): array
	{
		return ['status' => $this->status, 'headers' => $this->headers];
	}

	/**
	 * Builds the request array from the URL and the stream context.
	 *
	 * @param string $path
	 *   The URL.
	 *
	 * @return array
	 *   The request the fetch receives.
	 */
	private function request(string $path): array
	{
		$request = [
			'url' => $path,
			'method' => 'GET',
			'headers' => [],
			'body' => null,
			'redirect' => 'follow',
		];
		if (!is_resource($this->context)) {
			return $request;
		}

		$http = stream_context_get_options($this->context)['http'] ?? [];
		if (!is_array($http)) {
			return $request;
		}
		$request['method'] = strtoupper((string) ($http['method'] ?? 'GET'));
		$request['headers'] = self::normaliseHeaders($http['header'] ?? []);
		$request['body'] = is_string($http['content'] ?? null) ? $http['content'] : null;
		// PHP's own default is follow_location = 1, so an absent key means follow
		$request['redirect'] = $http['follow_location'] ?? 1 ? 'follow' : 'manual';
		return $request;
	}

	/**
	 * Records the reason, emits a warning unless the caller asked for silence, and fails the call.
	 *
	 * @param int $options
	 *   Stream flags from PHP.
	 * @param string $message
	 *   The named reason.
	 *
	 * @return bool
	 *   Always FALSE.
	 */
	private function fail(int $options, string $message): bool
	{
		self::$lastError = $message;
		if ($options & STREAM_REPORT_ERRORS) {
			trigger_error($message, E_USER_WARNING);
		}
		return false;
	}

	/**
	 * Turns PHP's header option -- a string or a list -- into a name => value map.
	 *
	 * @param string|array $header
	 *   Either CRLF-joined lines or a list of them.
	 *
	 * @return array
	 *   Header name to value, both trimmed.
	 */
	private static function normaliseHeaders(string|array $header): array
	{
		$lines = is_array($header) ? $header : (preg_split('/\r?\n/', $header) ?: []);
		$out = [];
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line === '' || !str_contains($line, ':')) {
				continue;
			}
			[$name, $value] = explode(':', $line, 2);
			$out[trim($name)] = trim($value);
		}
		return $out;
	}

	/**
	 * Narrows whatever the fetch reported as headers to a string map.
	 *
	 * @param mixed $headers
	 *   The reply's headers member.
	 *
	 * @return array
	 *   Header name to value; anything not stringable is dropped rather than cast.
	 */
	private static function stringMap(mixed $headers): array
	{
		if (!is_array($headers)) {
			return [];
		}
		$out = [];
		foreach ($headers as $name => $value) {
			if (!is_string($name) || is_array($value) || is_object($value)) {
				continue;
			}
			$out[$name] = (string) $value;
		}
		return $out;
	}
}
