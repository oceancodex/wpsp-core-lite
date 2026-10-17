<?php

namespace WPSPCORELITE\App\Http\Exceptions;

/**
 * Mirror of Illuminate\Http\Exceptions\ThrottleRequestsException (HTTP 429), without Symfony.
 *
 * Thrown by ThrottleRequests. Carries the rate limit headers, and the custom response
 * when the limit defined one with Limit::response().
 */
class ThrottleRequestsException extends \RuntimeException {

	protected array $headers;
	protected mixed $response;

	public function __construct(string $message = 'Too Many Attempts.', ?\Throwable $previous = null, array $headers = [], int $code = 0, mixed $response = null) {
		parent::__construct($message, $code, $previous);

		$this->headers  = $headers;
		$this->response = $response;
	}

	public function getStatusCode(): int {
		return 429;
	}

	public function getHeaders(): array {
		return $this->headers;
	}

	public function setHeaders(array $headers): static {
		$this->headers = $headers;
		return $this;
	}

	/**
	 * Custom response from Limit::response(), or null.
	 */
	public function getResponse(): mixed {
		return $this->response;
	}

	/**
	 * Seconds until the client may retry.
	 */
	public function retryAfter(): int {
		return (int) ($this->headers['Retry-After'] ?? 0);
	}

	/**
	 * For WordPress REST API callbacks: return $e->toWpError().
	 */
	public function toWpError(): mixed {
		if (!class_exists('WP_Error')) {
			return null;
		}

		return new \WP_Error('too_many_requests', $this->getMessage(), [
			'status'  => 429,
			'headers' => $this->headers,
		]);
	}

	/**
	 * Send status + headers when nothing else renders the exception.
	 */
	public function sendHeaders(): void {
		if (headers_sent()) {
			return;
		}

		http_response_code(429);

		foreach ($this->headers as $name => $value) {
			header($name . ': ' . $value);
		}
	}

}
