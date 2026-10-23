<?php

namespace WPSPCORELITE\App\Auth\Access;

/**
 * Kết quả của một lần kiểm tra quyền (tương đương Illuminate\Auth\Access\Response).
 *
 * Gate::inspect() luôn trả về Response, Policy / Gate::define() có thể trả về:
 * - bool                       → Gate tự đổi thành Response::allow() / Response::deny().
 * - Response::allow()           → cho phép.
 * - Response::deny('Lý do')     → từ chối kèm message (RouteTrait hiển thị message này).
 * - Response::denyWithStatus()  → từ chối kèm HTTP status (vd: 404 để giấu tài nguyên).
 */
class Response implements \JsonSerializable {

	/**
	 * HTTP status muốn trả về khi bị từ chối (null = mặc định 403).
	 */
	protected ?int $status = null;

	public function __construct(
		protected bool       $allowed,
		protected ?string    $message = null,
		protected int|string|null $code = null
	) {}

	/*
	 *
	 */

	public static function allow(?string $message = null, int|string|null $code = null): static {
		return new static(true, $message, $code);
	}

	public static function deny(?string $message = null, int|string|null $code = null): static {
		return new static(false, $message, $code);
	}

	public static function denyWithStatus(int $status, ?string $message = null, int|string|null $code = null): static {
		return static::deny($message, $code)->withStatus($status);
	}

	public static function denyAsNotFound(?string $message = null, int|string|null $code = null): static {
		return static::denyWithStatus(404, $message, $code);
	}

	/*
	 *
	 */

	public function allowed(): bool {
		return $this->allowed;
	}

	public function denied(): bool {
		return !$this->allowed;
	}

	/**
	 * Message do Policy / Gate đặt. Null khi không có message
	 * (RouteTrait dùng null để tự tạo message chẩn đoán khi WP_DEBUG).
	 */
	public function message(): ?string {
		return $this->message;
	}

	public function code(): int|string|null {
		return $this->code;
	}

	public function status(): ?int {
		return $this->status;
	}

	public function withStatus(?int $status): static {
		$this->status = $status;
		return $this;
	}

	public function asNotFound(): static {
		return $this->withStatus(404);
	}

	/**
	 * Ném AuthorizationException nếu bị từ chối, ngược lại trả về chính nó.
	 *
	 * @throws AuthorizationException
	 */
	public function authorize(): static {
		if ($this->denied()) {
			throw (new AuthorizationException($this->message(), $this->code()))
				->setResponse($this)
				->withStatus($this->status);
		}

		return $this;
	}

	public function toArray(): array {
		return [
			'allowed' => $this->allowed(),
			'message' => $this->message(),
			'code'    => $this->code(),
			'status'  => $this->status(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function __toString(): string {
		return (string)$this->message();
	}

}
