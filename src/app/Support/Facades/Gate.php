<?php

namespace WPSPCORELITE\App\Support\Facades;

use WPSPCORELITE\App\Auth\Access\Gate as GateCore;
use WPSPCORELITE\BaseInstances;

/**
 * Facade cho 'gate' - mô phỏng Illuminate\Support\Facades\Gate.
 *
 * Gọi static:  Gate::allows('edit_posts'), Gate::policy(\WP_Post::class, PostPolicy::class)
 *              Gate::define('approve-order', fn(\WP_User $user, $id) => ...)
 *              Gate::authorize('update', [\WP_Post::class, $postId])
 * Lấy object:  Gate::getFacadeRoot()   (\WPSPCORELITE\App\Auth\Access\Gate)
 * Thay object: Gate::swap($gate)
 *
 * Root của facade: $this->funcs->_getApplication('gate') - Gate mà Application của plugin đã bind sẵn
 * (cùng object RouteTrait dùng cho can:), nên Policy / define đăng ký qua facade có hiệu lực ngay cho route.
 *
 * Khác Cache / RateLimiter facade (có chủ đích): root được lấy từ Application ở MỖI lần gọi,
 * không giữ lại lúc khởi tạo facade. Facade có thể được tạo trước khi Application của plugin sẵn sàng;
 * nếu giữ lại, Policy đăng ký sau đó sẽ nằm trong một Gate khác với Gate mà route dùng.
 * Gate là singleton trong container, nên mỗi lần gọi chỉ tốn 1 lần tra mảng.
 *
 * Không dùng class này làm type-hint cho DI; hãy type-hint
 * \WPSPCORELITE\App\Auth\Access\Gate (giống Laravel: type-hint Illuminate\Contracts\Auth\Access\Gate).
 *
 * @method static bool has(\UnitEnum|string|array $ability)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate define(\UnitEnum|string $ability, callable|array|string $callback)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate resource(string $name, string $class, array|null $abilities = null)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate policy(string $class, string|object $policy)
 * @method static array policies()
 * @method static \WPSPCORELITE\App\Auth\Access\Gate guessPolicyNamesUsing(callable $callback)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate resolvePoliciesUsing(callable $resolver)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate before(callable $callback)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate after(callable $callback)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate defaultDenialResponse(\WPSPCORELITE\App\Auth\Access\Response $response)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate withCapabilityFallback(bool $enabled = true)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate setUserResolver(callable $userResolver)
 * @method static \WPSPCORELITE\App\Auth\Access\Gate forUser(mixed $user)
 * @method static array abilities()
 * @method static bool allows(\UnitEnum|string|iterable $ability, mixed $arguments = [])
 * @method static bool denies(\UnitEnum|string|iterable $ability, mixed $arguments = [])
 * @method static bool check(\UnitEnum|string|iterable $abilities, mixed $arguments = [])
 * @method static bool any(\UnitEnum|string|iterable $abilities, mixed $arguments = [])
 * @method static bool none(\UnitEnum|string|iterable $abilities, mixed $arguments = [])
 * @method static \WPSPCORELITE\App\Auth\Access\Response authorize(\UnitEnum|string $ability, mixed $arguments = [])
 * @method static \WPSPCORELITE\App\Auth\Access\Response inspect(\UnitEnum|string $ability, mixed $arguments = [])
 * @method static mixed raw(\UnitEnum|string $ability, mixed $arguments = [])
 * @method static \WPSPCORELITE\App\Auth\Access\Response allowIf(mixed $condition, string|null $message = null, int|string|null $code = null)
 * @method static \WPSPCORELITE\App\Auth\Access\Response denyIf(mixed $condition, string|null $message = null, int|string|null $code = null)
 * @method static string explain(\UnitEnum|string $ability, mixed $arguments = [])
 * @method static object|null getPolicyFor(object|string $class)
 * @method static array guessPolicyName(string $class)
 * @method static object resolvePolicy(string|object $policy)
 * @method static mixed resolveUser()
 *
 * @see \WPSPCORELITE\App\Auth\Access\Gate
 */
abstract class Gate extends BaseInstances {

	/**
	 * Gate thay thế (swap() / setFacade($gate)). Null = luôn lấy từ Application.
	 */
	private ?GateCore $swapped = null;

	/**
	 * Gate dự phòng khi không có Application (vd: gọi trước khi plugin bootstrap).
	 */
	private ?GateCore $fallback = null;

	/*
	 *
	 */

	public function getFacade(): ?GateCore {
		return $this->swapped
			?? $this->resolveFromApplication()
			?? ($this->fallback ??= $this->newGateInstance());
	}

	public function setFacade(?GateCore $gate = null) {
		$this->swapped = $gate;
	}

	/**
	 * Gate dùng khi Application chưa có (hoặc không có binding 'gate').
	 * Gate này KHÔNG phải Gate mà route dùng: Policy / define đăng ký vào đây không áp cho can:.
	 * Plugin có thể ghi đè để tạo Gate theo ý mình.
	 */
	protected function newGateInstance(): GateCore {
		if (function_exists('_doing_it_wrong')) {
			_doing_it_wrong(
				static::class,
				'Application của plugin chưa sẵn sàng (hoặc không có binding "gate"): Gate facade đang dùng Gate tạm, không phải Gate của route.',
				''
			);
		}

		return new GateCore();
	}

	protected function resolveFromApplication(): ?GateCore {
		try {
			$gate = $this->funcs?->_getApplication('gate');
		}
		catch (\Throwable $e) {
			return null;
		}

		return $gate instanceof GateCore ? $gate : null;
	}

	/*
	 * Facade-level methods (Laravel Facade API).
	 */

	public function _getFacadeRoot(): ?GateCore {
		return $this->getFacade();
	}

	/**
	 * Thay Gate phía sau facade (test, mock). Gate::swap(null) để quay lại dùng Gate của Application.
	 */
	public function _swap(?GateCore $gate): void {
		$this->swapped = $gate;
	}

	/*
	 *
	 */

	public function __call($method, $arguments) {
		return static::__callStatic($method, $arguments);
	}

	public static function __callStatic($method, $arguments) {
		$instance = static::wpspInstance();

		$underlineMethod = '_' . $method;
		if (method_exists($instance, $underlineMethod)) {
			return $instance->$underlineMethod(...$arguments);
		}

		return $instance->getFacade()?->$method(...$arguments);
	}

}
