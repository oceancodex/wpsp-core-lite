<?php

namespace WPSPCORELITE\App\Http\Middleware;

use Closure;
use WPSPCORELITE\App\Cache\RateLimiter;
use WPSPCORELITE\App\Cache\RateLimiting\Limit;
use WPSPCORELITE\App\Cache\RateLimiting\Unlimited;
use WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException;

/**
 * Mirror of Illuminate\Routing\Middleware\ThrottleRequests.
 *
 *   handle($request, $next)                     60 requests / minute
 *   handle($request, $next, 10, 1)              10 requests / minute
 *   handle($request, $next, 100, 60, 'export')  100 requests / hour, own counter "export"
 *   handle($request, $next, 'api')              named limiter registered with RateLimiter::for('api', ...)
 *
 * Route definitions: ThrottleRequests::using('api'), ThrottleRequests::with(10, 1).
 *
 * Over the limit => ThrottleRequestsException (HTTP 429, headers Retry-After, X-RateLimit-*).
 * Under the limit => X-RateLimit-Limit / X-RateLimit-Remaining are added to the response when it supports headers
 * (WP_REST_Response::header(), a Response with ->headers->set(), or header() when nothing is sent yet).
 */
class ThrottleRequests {

	/**
	 * Whether rate limiter keys should be hashed.
	 */
	protected static bool $shouldHashKeys = true;

	protected RateLimiter $limiter;

	public function __construct(RateLimiter $limiter) {
		$this->limiter = $limiter;
	}

	/**
	 * Middleware string using the given named rate limiter: ThrottleRequests::using('api').
	 */
	public static function using(string $name): string {
		return static::class . ':' . $name;
	}

	/**
	 * Middleware string with the given rate limit: ThrottleRequests::with(10, 1, 'login').
	 */
	public static function with(int $maxAttempts = 60, int $decayMinutes = 1, string $prefix = ''): string {
		return static::class . ':' . implode(',', func_get_args());
	}

	/**
	 * Handle an incoming request.
	 *
	 * @throws ThrottleRequestsException
	 */
	public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '') {
		if (is_string($maxAttempts) && !is_numeric($maxAttempts) && !is_null($limiter = $this->limiter->limiter($maxAttempts))) {
			return $this->handleRequestUsingNamedLimiter($request, $next, $maxAttempts, $limiter);
		}

		return $this->handleRequest($request, $next, [
			(object) [
				'key'              => $prefix . $this->resolveRequestSignature($request),
				'maxAttempts'      => $this->resolveMaxAttempts($request, $maxAttempts),
				'decaySeconds'     => (int) round(60 * (float) $decayMinutes),
				'afterCallback'    => null,
				'responseCallback' => null,
			],
		]);
	}

	/**
	 * Handle an incoming request using a named rate limiter.
	 */
	protected function handleRequestUsingNamedLimiter($request, Closure $next, string $limiterName, Closure $limiter) {
		$limiterResponse = $limiter($request);

		if ($limiterResponse instanceof Unlimited) {
			return $next($request);
		}

		// A limiter may also return a response directly (it is sent as-is).
		if (!$limiterResponse instanceof Limit && !is_array($limiterResponse)) {
			return $limiterResponse;
		}

		$limits = [];

		foreach (is_array($limiterResponse) ? $limiterResponse : [$limiterResponse] as $limit) {
			$key = ($limit->key === '' || $limit->key === null) && is_array($limiterResponse) && count($limiterResponse) > 1
				? $limit->fallbackKey()
				: (string) $limit->key;

			$limits[] = (object) [
				'key'              => static::$shouldHashKeys ? md5($limiterName . $key) : $limiterName . ':' . $key,
				'maxAttempts'      => $limit->maxAttempts,
				'decaySeconds'     => $limit->decaySeconds,
				'afterCallback'    => $limit->afterCallback,
				'responseCallback' => $limit->responseCallback,
			];
		}

		return $this->handleRequest($request, $next, $limits);
	}

	/**
	 * Handle an incoming request with the given limits.
	 *
	 * @throws ThrottleRequestsException
	 */
	protected function handleRequest($request, Closure $next, array $limits) {
		foreach ($limits as $limit) {
			if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
				throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
			}

			if (!$limit->afterCallback) {
				$this->limiter->hit($limit->key, $limit->decaySeconds);
			}
		}

		$response = $next($request);

		foreach ($limits as $limit) {
			if ($limit->afterCallback && ($limit->afterCallback)($response)) {
				$this->limiter->hit($limit->key, $limit->decaySeconds);
			}

			$response = $this->addHeaders(
				$response,
				$limit->maxAttempts,
				$this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
			);
		}

		return $response;
	}

	/**
	 * Resolve the number of attempts if the user is authenticated or not ("10|60" = guests|users).
	 */
	protected function resolveMaxAttempts($request, $maxAttempts): int {
		if (is_string($maxAttempts) && str_contains($maxAttempts, '|')) {
			$parts       = explode('|', $maxAttempts, 2);
			$maxAttempts = $this->currentUserId($request) ? $parts[1] : $parts[0];
		}

		return (int) $maxAttempts;
	}

	/**
	 * Resolve request signature: the logged-in user, otherwise host + IP.
	 */
	protected function resolveRequestSignature($request): string {
		if ($userId = $this->currentUserId($request)) {
			return sha1((string) $userId);
		}

		return sha1($this->requestHost($request) . '|' . $this->requestIp($request));
	}

	/**
	 * Create a 'too many attempts' exception.
	 */
	protected function buildException($request, string $key, int $maxAttempts, ?callable $responseCallback = null): ThrottleRequestsException {
		$retryAfter = $this->limiter->availableIn($key);

		$headers = $this->getHeaders(
			$maxAttempts,
			$this->calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
			$retryAfter
		);

		$response = $responseCallback ? $responseCallback($request, $headers) : null;

		return new ThrottleRequestsException('Too Many Attempts.', null, $headers, 0, $response);
	}

	/**
	 * Add the limit header information to the given response.
	 */
	protected function addHeaders($response, int $maxAttempts, int $remainingAttempts, ?int $retryAfter = null) {
		$headers = $this->getHeaders($maxAttempts, $remainingAttempts, $retryAfter);

		foreach ($headers as $name => $value) {
			if (is_object($response) && isset($response->headers) && is_object($response->headers) && method_exists($response->headers, 'set')) {
				$response->headers->set($name, (string) $value);
			}
			elseif (is_object($response) && method_exists($response, 'header')) {
				$response->header($name, (string) $value);
			}
			elseif (!headers_sent()) {
				header($name . ': ' . $value);
			}
		}

		return $response;
	}

	/**
	 * Get the limit headers information.
	 */
	protected function getHeaders(int $maxAttempts, int $remainingAttempts, ?int $retryAfter = null): array {
		$headers = [
			'X-RateLimit-Limit'     => $maxAttempts,
			'X-RateLimit-Remaining' => $remainingAttempts,
		];

		if (!is_null($retryAfter)) {
			$headers['Retry-After']       = $retryAfter;
			$headers['X-RateLimit-Reset'] = time() + $retryAfter;
		}

		return $headers;
	}

	/**
	 * Calculate the number of remaining attempts.
	 */
	protected function calculateRemainingAttempts(string $key, int $maxAttempts, ?int $retryAfter = null): int {
		return is_null($retryAfter) ? $this->limiter->retriesLeft($key, $maxAttempts) : 0;
	}

	/**
	 * Specify whether rate limiter keys should be hashed.
	 */
	public static function shouldHashKeys(bool $shouldHashKeys = true): void {
		static::$shouldHashKeys = $shouldHashKeys;
	}

	/*
	 * Request helpers (work with the WPSPCORELITE Request, WP_REST_Request or nothing).
	 */

	protected function currentUserId($request): int|string|null {
		if (is_object($request) && method_exists($request, 'user')) {
			try {
				$user = $request->user();
			}
			catch (\Throwable $e) {
				$user = null;
			}

			if (is_object($user)) {
				if (method_exists($user, 'getAuthIdentifier')) {
					return $user->getAuthIdentifier();
				}

				return $user->ID ?? $user->id ?? null;
			}
		}

		if (function_exists('get_current_user_id') && ($id = get_current_user_id())) {
			return $id;
		}

		return null;
	}

	protected function requestIp($request): string {
		if (is_object($request) && method_exists($request, 'ip') && ($ip = $request->ip())) {
			return (string) $ip;
		}

		return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
	}

	protected function requestHost($request): string {
		if (is_object($request) && method_exists($request, 'getHost') && ($host = $request->getHost())) {
			return (string) $host;
		}

		return (string) ($_SERVER['HTTP_HOST'] ?? '');
	}

}
