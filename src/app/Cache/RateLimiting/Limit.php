<?php

namespace WPSPCORELITE\App\Cache\RateLimiting;

/**
 * Mirror of Illuminate\Cache\RateLimiting\Limit.
 *
 * Limit::perMinute(60)->by($userId)->response(fn($request, array $headers) => ...);
 */
class Limit {

	/**
	 * The rate limit signature key.
	 */
	public mixed $key;

	/**
	 * The maximum number of attempts allowed within the given number of seconds.
	 */
	public int $maxAttempts;

	/**
	 * The number of seconds until the rate limit is reset.
	 */
	public int $decaySeconds;

	/**
	 * The after callback used to determine if the limiter should be hit (counts only matching responses).
	 *
	 * @var callable|null
	 */
	public $afterCallback = null;

	/**
	 * The response generator callback: fn($request, array $headers) => response.
	 *
	 * @var callable|null
	 */
	public $responseCallback = null;

	public function __construct($key = '', int $maxAttempts = 60, int $decaySeconds = 60) {
		$this->key          = $key;
		$this->maxAttempts  = $maxAttempts;
		$this->decaySeconds = $decaySeconds;
	}

	/**
	 * Create a new rate limit (attempts per second, or per $decaySeconds seconds).
	 */
	public static function perSecond(int $maxAttempts, int $decaySeconds = 1): static {
		return new static('', $maxAttempts, $decaySeconds);
	}

	/**
	 * Create a new rate limit (attempts per minute, or per $decayMinutes minutes).
	 */
	public static function perMinute(int $maxAttempts, int $decayMinutes = 1): static {
		return new static('', $maxAttempts, 60 * $decayMinutes);
	}

	/**
	 * Create a new rate limit using minutes as decay time: perMinutes(5, 100) = 100 attempts per 5 minutes.
	 */
	public static function perMinutes(int $decayMinutes, int $maxAttempts): static {
		return new static('', $maxAttempts, 60 * $decayMinutes);
	}

	/**
	 * Create a new rate limit using hours as decay time.
	 */
	public static function perHour(int $maxAttempts, int $decayHours = 1): static {
		return new static('', $maxAttempts, 60 * 60 * $decayHours);
	}

	/**
	 * Create a new rate limit using days as decay time.
	 */
	public static function perDay(int $maxAttempts, int $decayDays = 1): static {
		return new static('', $maxAttempts, 60 * 60 * 24 * $decayDays);
	}

	/**
	 * Create a new unlimited rate limit.
	 */
	public static function none(): Unlimited {
		return new Unlimited();
	}

	/**
	 * Set the key of the rate limit (who/what is counted: user id, IP, email...).
	 */
	public function by($key): static {
		$this->key = $key;

		return $this;
	}

	/**
	 * Set the callback that should generate the response when the limit is exceeded.
	 */
	public function response(callable $callback): static {
		$this->responseCallback = $callback;

		return $this;
	}

	/**
	 * Set the callback to determine if the limiter should be hit, after the request ran.
	 * Example: count only failed logins => ->after(fn($response) => is_wp_error($response)).
	 */
	public function after(callable $callback): static {
		$this->afterCallback = $callback;

		return $this;
	}

	/**
	 * Key used when the limit has none, so several key-less limits of one limiter don't share a counter.
	 */
	public function fallbackKey(): string {
		$prefix = $this->decaySeconds === 60 ? '' : $this->decaySeconds . ':';

		return 'attempts:' . $prefix . $this->maxAttempts;
	}

}
