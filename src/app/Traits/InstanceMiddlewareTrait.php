<?php

namespace WPSPCORELITE\App\Traits;

/**
 * InstanceMiddlewareTrait — bản LITE (không phụ thuộc Illuminate).
 *
 * Cùng API với bản WPSPCORE / Illuminate\Routing\Controller:
 *
 *     public function customProperties() {
 *         $this->middleware('can:update,user')->only('edit', 'update');
 *         $this->middleware(AuthenticationMiddleware::class)->except('index');
 *     }
 *
 * RouteTrait::authorizeControllerAction() đọc getMiddleware() và chạy các middleware áp
 * cho ACTION của route ngay trước khi gọi action. 'can:' cần Gate (binding 'gate') trong
 * container Lite; không có Gate → bị từ chối.
 *
 * LƯU Ý: KHÔNG dùng trait này cho class route (BaseRoute và con của nó): BaseRoute xử lý
 * Route::middleware(...) qua __call(), một method middleware() thật sẽ chặn cơ chế đó.
 */
trait InstanceMiddlewareTrait {

	/**
	 * [['middleware' => string|Closure|array, 'options' => ['only' => [...], 'except' => [...]]], ...]
	 */
	protected array $instanceMiddleware = [];

	/**
	 * Đăng ký controller middleware.
	 *
	 * @param \Closure|array|string $middleware
	 * @param array                 $options ['only' => [...]] / ['except' => [...]]
	 */
	public function middleware($middleware, array $options = []) {
		// (array)$closure = [] → Closure / string phải bọc thủ công.
		$middlewares = ($middleware instanceof \Closure || is_string($middleware)) ? [$middleware] : (array)$middleware;

		foreach ($middlewares as $m) {
			$this->instanceMiddleware[] = [
				'middleware' => $m,
				'options'    => &$options,
			];
		}

		// ->only() / ->except() ghi vào $options (truyền theo tham chiếu) của các entry vừa thêm.
		return new class($options) {

			public function __construct(public array &$options) {}

			public function only($methods) {
				$this->options['only'] = is_array($methods) ? $methods : func_get_args();
				return $this;
			}

			public function except($methods) {
				$this->options['except'] = is_array($methods) ? $methods : func_get_args();
				return $this;
			}

		};
	}

	/**
	 * Toàn bộ controller middleware đã đăng ký.
	 */
	public function getMiddleware() {
		return $this->instanceMiddleware;
	}

}
