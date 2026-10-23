<?php

namespace WPSPCORELITE\App\Auth\Access;

/**
 * Ném ra bởi Gate::authorize() / Response::authorize() khi bị từ chối
 * (tương đương Illuminate\Auth\Access\AuthorizationException).
 *
 * Dùng trong controller:
 *     try { $gate->authorize('update', $post); }
 *     catch (AuthorizationException $e) { wp_die($e->getMessage(), '', ['response' => $e->status() ?? 403]); }
 */
class AuthorizationException extends \Exception {

	protected ?Response $response = null;

	protected ?int $status = null;

	public function __construct(?string $message = null, int|string|null $code = null, ?\Throwable $previous = null) {
		parent::__construct($message ?? 'This action is unauthorized.', 0, $previous);

		// \Exception chỉ nhận code kiểu int → giữ code dạng chuỗi trong Response.
		$this->code = is_int($code) ? $code : 0;

		if ($code !== null && !is_int($code)) {
			$this->response = Response::deny($message, $code);
		}
	}

	public function response(): ?Response {
		return $this->response;
	}

	public function setResponse(Response $response): static {
		$this->response = $response;
		return $this;
	}

	public function withStatus(?int $status): static {
		$this->status = $status;
		return $this;
	}

	public function asNotFound(): static {
		return $this->withStatus(404);
	}

	public function hasStatus(): bool {
		return $this->status !== null;
	}

	public function status(): ?int {
		return $this->status;
	}

	/**
	 * Đổi exception thành Response::deny() (Gate::inspect() dùng khi Policy ném exception này).
	 */
	public function toResponse(): Response {
		return Response::deny($this->message, $this->response?->code() ?? $this->code)->withStatus($this->status);
	}

}
