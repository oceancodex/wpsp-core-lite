<?php

namespace WPSPCORELITE\App\Routes;

/**
 * RouteTrait — bản LITE.
 *
 * KHÔNG phụ thuộc Illuminate / Symfony / Laravel Framework. Chỉ dùng PHP 8.0+ và WordPress core.
 *
 * - Response cho $next       → \WP_HTTP_Response.
 * - Kiểm tra response         → duck-typing (getStatusCode / get_status / status).
 * - Trả lỗi JSON / HTML       → wp_send_json() / wp_die().
 * - Request                   → $this->request → container → \WPSPCORELITE\App\Http\Request::capture().
 * - Throttle                  → ThrottleRequests + RateLimiter ('rate.limiter') của plugin, giống WPSPCORE:
 *                               'throttle:60,1', 'throttle:30rpm' (named limiter khai báo bằng RateLimiter::for()),
 *                               ThrottleRequests::using('30rpm'), ThrottleRequests::with(60, 1).
 *                               Bộ đếm nằm trong cache của plugin => không bao giờ đếm chung với plugin khác.
 * - Container / Facade Lite   → gọi qua method_exists, thiếu method nào thì bỏ qua bước đó.
 * - Không có Eloquent model binding (Lite không có ORM).
 */
trait RouteTrait {

	/*
	 * =====================================================================
	 * MIDDLEWARE
	 * =====================================================================
	 */

	/**
	 * Kiểm tra middleware hiện tại có phải là middleware cuối cùng trong pipeline hay không.
	 *
	 * Hàm chỉ xét các phần tử có key dạng số trong danh sách middleware,
	 * bỏ qua các phần tử cấu hình khác có key dạng chuỗi.
	 *
	 * Middleware được coi là middleware cuối cùng khi phần tử cuối cùng
	 * trong danh sách có dạng:
	 *
	 * [
	 *     TênClassMiddleware::class,
	 *     'handle'
	 * ]
	 *
	 * và tên class trùng với giá trị của tham số $currentClass.
	 *
	 * @param string $currentClass   Tên class middleware cần kiểm tra.
	 * @param mixed  $allMiddlewares Danh sách middleware của pipeline.
	 *
	 * @return bool
	 */
	public function isLastMiddleware($currentClass, $allMiddlewares) {
		if (!is_array($allMiddlewares)) {
			return false;
		}

		// Lọc chỉ lấy key dạng số (0,1,2...)
		$middlewares = array_filter($allMiddlewares, 'is_int', ARRAY_FILTER_USE_KEY);

		if (empty($middlewares)) {
			return false;
		}

		// Lấy phần tử cuối cùng
		$last = end($middlewares);

		// dạng: [ 'ClassName', 'handle' ]
		return is_array($last) && isset($last[0]) && $last[0] === $currentClass;
	}

	/**
	 * Kiểm tra xem route hiện tại có vượt qua toàn bộ middleware hay không.
	 *
	 * Middleware được tổ chức thành nhiều "block middleware".
	 * Mỗi block có thể chứa một hoặc nhiều middleware và có thể định nghĩa
	 * quan hệ đánh giá thông qua key `relation`:
	 *
	 * - AND: tất cả middleware trong block phải PASS.
	 * - OR : chỉ cần một middleware trong block PASS.
	 *
	 * Route chỉ được coi là PASS khi tất cả các block middleware đều PASS.
	 *
	 * Middleware hỗ trợ các định dạng:
	 *
	 * - Closure                       → function($request, $next, $args)
	 * - ClassName::class
	 * - [ClassName::class, 'method']
	 * - 'throttle:60,1' / 'throttle:30rpm' / ['throttle:api'] / ThrottleRequests::using('api')
	 *
	 * Giá trị trả về của middleware:
	 *
	 * - true / false                        : PASS / FAIL
	 * - \WP_Error                           : FAIL
	 * - Response status >= 400              : FAIL
	 * - Response 3xx mà KHÔNG gọi $next     : FAIL (middleware đang chặn + redirect, vd: redirect login)
	 * - Response còn lại / null             : PASS
	 *
	 * Thông tin block hiện tại sẽ được truyền vào `$args['current_block_middleware']`.
	 *
	 * Ví dụ:
	 *
	 * [
	 *     [
	 *         'relation' => 'AND',
	 *         AuthMiddleware::class,
	 *         VerifiedMiddleware::class,
	 *     ],
	 *     [
	 *         'relation' => 'OR',
	 *         AdminMiddleware::class,
	 *         ManagerMiddleware::class,
	 *     ],
	 * ]
	 *
	 * Trong ví dụ trên:
	 * - AuthMiddleware và VerifiedMiddleware đều phải PASS.
	 * - AdminMiddleware hoặc ManagerMiddleware chỉ cần một PASS.
	 *
	 * @param array $middlewares Danh sách middleware block cần kiểm tra.
	 * @param mixed $request     Request hiện tại. Nếu null sẽ tự động lấy từ container.
	 * @param array $args        Dữ liệu bổ sung được truyền vào middleware.
	 *
	 * @return bool Trả về true nếu toàn bộ middleware đều PASS, ngược lại false.
	 */
	public function isPassedMiddleware($middlewares = [], $request = null, $args = []) {
		// Không có middleware → pass
		if (empty($middlewares)) {
			return true;
		}

		$app     = $this->currentApp();
		$request = $request ?? $this->resolveCurrentRequest();
		$args    = (array)$args;

		foreach ($middlewares as $blockMiddleware) {
			$passed = is_array($blockMiddleware)
				? $this->evaluateMiddlewareBlock($blockMiddleware, $args, $request, $app)
				: $this->evaluateMiddlewareNode($blockMiddleware, $args, $request, $app); // top-level dạng string / Closure

			if (!$passed) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Đánh giá 1 node: middleware lá hoặc block con (mảng có key 'relation').
	 */
	protected function evaluateMiddlewareNode($node, array $args, $request, $app): bool {
		if (is_array($node) && array_key_exists('relation', $node)) {
			return $this->evaluateMiddlewareBlock($node, $args, $request, $app);
		}

		$mw = $this->normalizeMiddlewareLeaf($node, $args);

		// Dạng không hợp lệ → bỏ qua, coi như PASS để không chặn nhầm route.
		if ($mw === null) {
			return true;
		}

		return $this->runMiddlewareLeaf($mw, $request, $app);
	}

	/**
	 * Đánh giá 1 block theo relation (mặc định AND).
	 */
	protected function evaluateMiddlewareBlock(array $block, array $args, $request, $app): bool {
		$relation = strtoupper((string)($block['relation'] ?? 'AND'));
		unset($block['relation']);

		if (empty($block)) {
			return true;
		}

		$args['current_block_middleware'] = $block;

		if ($relation === 'OR') {
			foreach ($block as $child) {
				if ($this->evaluateMiddlewareNode($child, $args, $request, $app)) return true;
			}
			return false;
		}

		// AND
		foreach ($block as $child) {
			if (!$this->evaluateMiddlewareNode($child, $args, $request, $app)) return false;
		}
		return true;
	}

	/**
	 * Chuẩn hoá 1 middleware "lá" (Closure / string / [class, method]) thành dạng runtime.
	 */
	protected function normalizeMiddlewareLeaf($mw, array $args): ?array {
		if ($mw instanceof \Closure) {
			return ['type' => 'closure', 'closure' => $mw, 'args' => $args];
		}

		if (is_array($mw) && isset($mw[0]) && is_string($mw[0])) {
			if ($throttle = $this->throttleSignature($mw[0])) {
				return ['type' => 'throttle', 'value' => $throttle, 'args' => $args];
			}
			return ['type' => 'class', 'class' => $mw[0], 'method' => $mw[1] ?? 'handle', 'args' => $args];
		}

		if (is_string($mw)) {
			if ($throttle = $this->throttleSignature($mw)) {
				return ['type' => 'throttle', 'value' => $throttle, 'args' => $args];
			}
			return ['type' => 'class', 'class' => $mw, 'method' => 'handle', 'args' => $args];
		}

		return null;
	}

	/**
	 * 'throttle', 'throttle:60,1', 'throttle:30rpm' → giữ nguyên.\
	 * ThrottleRequests::using('30rpm') / ThrottleRequests::with(60, 1) (dạng "Class:tham số") → 'throttle:30rpm'.\
	 * Không phải throttle → null.
	 */
	protected function throttleSignature(string $mw): ?string {
		if ($mw === 'throttle' || str_starts_with($mw, 'throttle:')) {
			return $mw;
		}

		$parts = explode(':', $mw, 2);

		if (is_a(ltrim($parts[0], '\\'), \WPSPCORELITE\App\Http\Middleware\ThrottleRequests::class, true)) {
			return isset($parts[1]) && $parts[1] !== '' ? 'throttle:' . $parts[1] : 'throttle';
		}

		return null;
	}

	/**
	 * Chạy 1 middleware đã normalize, trả về true (PASS) / false (FAIL).
	 */
	protected function runMiddlewareLeaf(array $mw, $request, $app): bool {
		if ($mw['type'] === 'throttle') {
			return $this->runThrottleMiddleware($mw['value'], $request);
		}

		// Theo dõi middleware có gọi $next hay không → phân biệt "cho qua" và "chặn + redirect".
		$nextCalled = false;
		$next       = function() use (&$nextCalled) {
			$nextCalled = true;
			return $this->makeNextResponse();
		};

		if ($mw['type'] === 'closure') {
			// Tham số thừa được PHP bỏ qua nếu closure không khai báo $args.
			$res = ($mw['closure'])($request, $next, $mw['args']);
		}
		else {
			$class = $mw['class'];

			if (!class_exists($class)) {
				return false;
			}

			$instance = ($app && method_exists($app, 'make'))
				? $app->make($class)
				: $this->manualMakeClass($class);

			$method = method_exists($instance, $mw['method']) ? $mw['method'] : 'handle';
			$params = [
				'request' => $request,
				'next'    => $next,
				'args'    => $mw['args'] ?? null,
			];

			$res = ($app && method_exists($app, 'call'))
				? $app->call([$instance, $method], $params)
				: $this->manualResolveAndCall([$instance, $method], $params);
		}

		return $this->isPassedResult($res, $nextCalled);
	}

	/**
	 * Quy đổi kết quả trả về của middleware thành PASS (true) / FAIL (false).
	 */
	protected function isPassedResult($res, bool $nextCalled = true): bool {
		if (is_bool($res)) {
			return $res;
		}

		if ($res instanceof \WP_Error) {
			return false;
		}

		$status = $this->getResponseStatusCode($res);

		if ($status === null) {
			return true;
		}

		if ($status >= 400) {
			return false;
		}

		// Redirect mà không đi qua $next = middleware đang chặn request.
		if ($status >= 300 && !$nextCalled) {
			return false;
		}

		return true;
	}

	/**
	 * Chạy throttle giống WPSPCORE: ThrottleRequests + RateLimiter của plugin.\
	 * Mỗi signature (vd: 'throttle:60,1', 'throttle:30rpm') chỉ đếm đúng 1 lần / request.
	 *
	 * - 'throttle'               → 60 request / phút
	 * - 'throttle:60,1'          → 60 request / 1 phút
	 * - 'throttle:100,60,export' → 100 request / giờ, bộ đếm riêng "export"
	 * - 'throttle:10|60,1'       → khách 10, user đăng nhập 60 / phút
	 * - 'throttle:30rpm'         → named limiter: RateLimiter::for('30rpm', fn($request) => Limit::perMinute(30))
	 *
	 * Vượt giới hạn → dừng request với 429 (JSON nếu client muốn JSON, ngược lại wp_die) + Retry-After / X-RateLimit-*.
	 */
	protected function runThrottleMiddleware(string $value, $request): bool {
		$memoKey = '_wpsp_throttle.' . $value;
		$cached  = $this->requestMemo($request, $memoKey);

		if ($cached !== null) {
			return $cached;
		}

		$parts      = explode(':', $value, 2);
		$parameters = (isset($parts[1]) && $parts[1] !== '') ? array_map('trim', explode(',', $parts[1])) : [];
		$passed     = false;

		try {
			$limiter    = $this->throttleRateLimiter();
			$middleware = new \WPSPCORELITE\App\Http\Middleware\ThrottleRequests($limiter);

			$this->warnUnknownNamedLimiter($limiter, $parameters);

			$response = $middleware->handle($request, function() {
				return $this->makeNextResponse();
			}, ...$parameters);

			// X-RateLimit-* được gắn vào response giả của $next → gửi ra client.
			if (is_object($response) && method_exists($response, 'get_headers')) {
				$this->sendHeaders((array)$response->get_headers());
			}

			$passed = true;
		}
		catch (\WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException $e) {
			$this->respondTooManyRequests($e);
		}
		catch (\Throwable $e) {
			$this->abortWithStatus($this->safeErrorMessage($e), 500, '500 - Internal Server Error.');
		}

		$this->requestMemo($request, $memoKey, $passed);

		return $passed;
	}

	/**
	 * RateLimiter của plugin: binding 'rate.limiter' của Application (chứa named limiters khai báo trong
	 * AppServiceProvider). Không có binding → tạo từ cache của plugin.
	 */
	protected function throttleRateLimiter(): \WPSPCORELITE\App\Cache\RateLimiter {
		$app = $this->currentApp();

		if ($app && method_exists($app, 'bound') && method_exists($app, 'make')) {
			if ($app->bound('rate.limiter')) {
				return $app->make('rate.limiter');
			}

			if ($app->bound('cache')) {
				$cache = $app->make('cache');
				return new \WPSPCORELITE\App\Cache\RateLimiter($cache->store($cache->getConfig('limiter') ?: null));
			}
		}

		// Không có container: cache của plugin theo mainPath (prefix = tên thư mục plugin).
		static $fallback = [];

		$mainPath = property_exists($this, 'mainPath') ? $this->mainPath : null;
		$key      = (string)$mainPath;

		if (!isset($fallback[$key])) {
			$cache          = new \WPSPCORELITE\App\Cache\Cache($mainPath);
			$fallback[$key] = new \WPSPCORELITE\App\Cache\RateLimiter($cache->store($cache->getConfig('limiter') ?: null));
		}

		return $fallback[$key];
	}

	/**
	 * 'throttle:30rpm' mà chưa khai báo RateLimiter::for('30rpm') → giống Laravel, ThrottleRequests hiểu
	 * thành (int)'30rpm' = 30 / phút. Báo cho dev biết (WP_DEBUG) thay vì im lặng.
	 */
	protected function warnUnknownNamedLimiter(\WPSPCORELITE\App\Cache\RateLimiter $limiter, array $parameters): void {
		$name = $parameters[0] ?? null;

		if ($name === null || is_numeric($name) || str_contains($name, '|') || $limiter->limiter($name) !== null) {
			return;
		}

		if (function_exists('_doing_it_wrong')) {
			_doing_it_wrong(
				'throttle:' . esc_html($name),
				sprintf('Rate limiter [%s] chưa được khai báo. Hãy gọi RateLimiter::for(\'%s\', ...) trong AppServiceProvider::boot().', esc_html($name), esc_html($name)),
				''
			);
		}
	}

	/**
	 * Trả 429. Limit::response() có thể trả về:
	 * - \WP_HTTP_Response / \WP_REST_Response / object có get_status() → gửi đúng status, header, data.
	 * - chuỗi → dùng làm thông báo lỗi.
	 * - mảng → JSON.
	 * Không có response riêng → thông báo mặc định (JSON hoặc wp_die).
	 */
	protected function respondTooManyRequests(\WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException $e): void {
		$custom  = $e->getResponse();
		$headers = $e->getHeaders();

		if (is_object($custom) && method_exists($custom, 'get_status')) {
			$this->sendHeaders(array_merge($headers, method_exists($custom, 'get_headers') ? (array)$custom->get_headers() : []));
			wp_send_json(method_exists($custom, 'get_data') ? $custom->get_data() : null, (int)$custom->get_status());
			exit;
		}

		if (is_array($custom)) {
			$this->sendHeaders($headers);
			wp_send_json($custom, 429);
			exit;
		}

		$message = is_string($custom) && $custom !== '' ? $custom : $e->getMessage();

		$this->abortWithStatus($message, 429, '429 - Too Many Requests.', $headers);
	}

	/*
	 * =====================================================================
	 * HTTP HELPERS
	 * =====================================================================
	 */

	/**
	 * Response mặc định mà $next trả về cho middleware.
	 */
	protected function makeNextResponse() {
		return new \WP_HTTP_Response('OK', 200);
	}

	/**
	 * Lấy HTTP status code từ response bất kỳ (duck-typing).
	 */
	protected function getResponseStatusCode($res): ?int {
		if (!is_object($res)) {
			return null;
		}
		if (method_exists($res, 'getStatusCode')) {
			return (int)$res->getStatusCode();
		}
		if (method_exists($res, 'get_status')) {
			return (int)$res->get_status();
		}
		if (method_exists($res, 'status')) {
			return (int)$res->status();
		}
		return null;
	}

	/**
	 * Dừng request với status code: JSON nếu client muốn JSON, ngược lại wp_die().\
	 * Luôn dừng hẳn để không có output nào bị nối thêm sau response lỗi.
	 */
	protected function abortWithStatus(string $message, int $status, string $title, array $headers = []): void {
		$this->sendHeaders($headers);

		if ($this->funcs->_wantsJson()) {
			wp_send_json($this->funcs->_response(false, $message, $status), $status);
			exit;
		}

		wp_die($message, $title, [
			'back_link' => true,
			'response'  => $status,
		]);

		exit;
	}

	/**
	 * Gửi header nếu chưa gửi output.
	 */
	protected function sendHeaders(array $headers): void {
		if (empty($headers) || headers_sent()) {
			return;
		}

		foreach ($headers as $name => $value) {
			header($name . ': ' . (is_array($value) ? implode(', ', $value) : $value));
		}
	}

	/**
	 * Không lộ message exception nội bộ ra ngoài khi không bật WP_DEBUG.
	 */
	protected function safeErrorMessage(\Throwable $e): string {
		return (defined('WP_DEBUG') && WP_DEBUG) ? $e->getMessage() : 'Internal Server Error.';
	}

	/**
	 * Container / application của plugin (có thể null trong Lite).
	 */
	protected function currentApp() {
		return method_exists($this->funcs, '_getApplication') ? $this->funcs->_getApplication() : null;
	}

	/**
	 * Lấy request hiện tại: $this->request → container → Lite Request::capture().
	 */
	protected function resolveCurrentRequest() {
		if (!empty($this->request)) {
			return $this->request;
		}

		$app = $this->currentApp();

		if ($app && method_exists($app, 'bound') && method_exists($app, 'make') && $app->bound('request')) {
			return $app->make('request');
		}

		$liteRequest = \WPSPCORELITE\App\Http\Request::class;
		if (class_exists($liteRequest) && method_exists($liteRequest, 'capture')) {
			return $liteRequest::capture();
		}

		return null;
	}

	/**
	 * Đọc 1 "bag" của request (query / request / attributes) dưới dạng mảng.\
	 * Request không có bag → fallback superglobal đã wp_unslash() (WP luôn addslashes $_GET/$_POST).
	 */
	protected function requestBag($request, string $bag): array {
		if (
			$request
			&& isset($request->{$bag})
			&& is_object($request->{$bag})
			&& method_exists($request->{$bag}, 'all')
		) {
			return (array)$request->{$bag}->all();
		}

		switch ($bag) {
			case 'query':
				return wp_unslash($_GET);
			case 'request':
				return wp_unslash($_POST);
			default:
				return [];
		}
	}

	/**
	 * Ghi nhớ giá trị trong vòng đời 1 request.\
	 * Ưu tiên request attributes (nếu Lite Request có), ngược lại dùng bộ nhớ tĩnh theo request.
	 *
	 * Gọi 2 tham số → đọc (null nếu chưa có). Gọi 3 tham số → ghi.
	 */
	protected function requestMemo($request, string $key, ?bool $value = null): ?bool {
		// WeakMap: mỗi request object có ngăn nhớ riêng, tự xoá khi request bị giải phóng
		// (spl_object_id có thể bị tái sử dụng cho request mới => dùng nhầm kết quả throttle cũ).
		static $store = null, $global = [];
		$store ??= new \WeakMap();

		$write = func_num_args() >= 3;
		$bag   = ($request && isset($request->attributes) && is_object($request->attributes)) ? $request->attributes : null;

		if ($bag && method_exists($bag, 'has') && method_exists($bag, 'get') && method_exists($bag, 'set')) {
			if ($write) {
				$bag->set($key, $value);
				return $value;
			}
			return $bag->has($key) ? (bool)$bag->get($key) : null;
		}

		if (!is_object($request)) {
			if ($write) {
				return $global[$key] = $value;
			}
			return $global[$key] ?? null;
		}

		$memo = $store[$request] ?? [];

		if ($write) {
			$memo[$key]       = $value;
			$store[$request]  = $memo;
			return $value;
		}

		return $memo[$key] ?? null;
	}

	/*
	 * =====================================================================
	 * CALLBACK PREPARATION
	 * =====================================================================
	 */

	/**
	 * Chuẩn bị callback cho route trước khi thực thi.
	 *
	 * Hỗ trợ các dạng callback:
	 *
	 * - Closure
	 * - [ClassName::class, 'method']
	 *
	 * Nếu callback là Closure, hàm sẽ trả về nguyên bản.
	 * Nếu callback là mảng chứa tên class và method, một instance của class
	 * sẽ được khởi tạo bằng các tham số truyền vào thông qua `$constructParams`,
	 * sau đó trả về dưới dạng callable `[object, method]`.
	 *
	 * Ví dụ:
	 *
	 * prepareRouteCallback(function () {});
	 *
	 * prepareRouteCallback([
	 *     UserController::class,
	 *     'index'
	 * ]);
	 *
	 * Nếu callback không thuộc các định dạng được hỗ trợ,
	 * RuntimeException sẽ được ném ra.
	 *
	 * @param mixed $callback Callback cần chuẩn hóa.
	 * @param array $constructParams Các tham số truyền vào constructor của class.
	 *
	 * @return callable Callback đã được chuẩn hóa và sẵn sàng để thực thi.
	 *
	 * @throws \RuntimeException Khi callback không hợp lệ.
	 */
	public function prepareRouteCallback($callback, $constructParams = []) {
		if ($callback instanceof \Closure) {
			return $callback;
		}

		if (is_array($callback)) {
			$class = new $callback[0](...($constructParams ?? []));
			return [$class, $callback[1] ?? null];
		}

		throw new \RuntimeException("Invalid callback");
	}

	/**
	 * Chuẩn bị callback cho các function đặc biệt, ví dụ: add_menu_page(), add_action()...\
	 * Closure trả về nhận các đối số WordPress truyền vào (hook args) và map chúng vào signature.
	 */
	public function prepareCallbackFunction($method, $path, $fullPath, $class = null, $args = []): \Closure {
		return function(...$wpParams) use ($method, $path, $fullPath, $class, $args) {
			$requestPath = $this->request->getRequestUri();

			// Nếu truyền tên class thay vì instance, tự khởi tạo class với DI.
			$targetInstance = $class;
			if (is_string($class) && class_exists($class)) {
				$container      = $this->currentApp();
				$targetInstance = ($container && method_exists($container, 'make'))
					? $container->make($class)
					: $this->manualMakeClass($class);
			}

			$callback = [$targetInstance ?? $this, $method];

			if (!isset($args['route'])) {
				$args['route'] = $this->extraParams['route'] ?? null;
			}

			$callParams = $this->buildParametersForCallable($callback, $path, $fullPath, $requestPath, $args, $wpParams);

			return $this->resolveAndCall($callback, $callParams);
		};
	}

	/**
	 * Build params for callable (route callback).
	 *
	 * - Reflection signature (có cache)
	 * - Regex route matching / Ajax route compatibility
	 * - Fallback param build khi route không match
	 * - WordPress hook args (add_action / add_filter) theo vị trí
	 * - Regex capture parsing (named + positional)
	 * - Request source aggregation (attributes, POST, GET)
	 * - Primitive param binding
	 * - Metadata injection
	 * - Request → route parameter bridging
	 */
	public function getCallParams($path, $fullPath, $requestPath, $callbackOrClass, $method = null, $args = [], $wpParams = []) {
		if ($callbackOrClass instanceof \Closure) {
			$method = null;
		}

		$args        = (array)$args;
		$parameters  = $this->reflectCallable($callbackOrClass, $method)->getParameters();
		$hookArgs    = $this->mapHookArgs($parameters, (array)$wpParams);
		$calledClass = static::class;

		// Path đã là regex pattern (có thể chứa (?P<name>...)) → KHÔNG escape.
		$forceRegex = $args['route']->args['force_regex'] ?? false;
		$regexPath  = $this->funcs->_regexPath($fullPath, $forceRegex);
		$matches    = [];
		$passed     = false;

		// Route "Ajaxs" với method POST → so khớp action.
		if (str_ends_with($calledClass, 'Ajaxs') && $this->request->getMethod() === 'POST') {
			$passed = ($this->request->all()['action'] ?? null) === $fullPath;
		}

		// "Actions" / "Filters" không có request path → luôn coi như đang ở đúng path.
		if (str_ends_with($calledClass, 'Actions') || str_ends_with($calledClass, 'Filters')) {
			$requestPath = $fullPath;
		}

		// Chỉ thực sự bind dữ liệu khi đang truy cập đúng "path" / "fullPath".
		if (
			!empty($regexPath)
			&& (
				@preg_match('#' . $regexPath . '#iu', $requestPath, $matches)
				|| @preg_match('#' . $fullPath . '#iu', $requestPath, $matches)
				|| $fullPath == $requestPath
			)
		) {
			$passed = true;
		}

		$meta = [
			'path'            => $path,
			'path_regex'      => $this->funcs->_regexPath($path),
			'full_path'       => $fullPath,
			'full_path_regex' => $this->funcs->_regexPath($fullPath),
			'request_path'    => $requestPath,
		];

		/*
		 * ----- Route KHÔNG khớp: primitive = null, class để container inject -----
		 */
		if (!$passed) {
			$callParams = [];

			foreach ($parameters as $param) {
				$name = $param->getName();

				if (array_key_exists($name, $hookArgs)) {
					$callParams[$name] = $hookArgs[$name];
					continue;
				}

				if ($className = $this->getClassFromType($param->getType())) {
					$special = $this->resolveSpecialClassParam($name, $className, $method);
					if ($special !== null) {
						$callParams[$name] = $special;
					}
					continue;
				}

				$callParams[$name] = null;
			}

			// Thứ tự ghi đè giữ như bản cũ: params < meta < args.
			return array_merge($callParams, $meta, $args, $this->variadicHookArgs($hookArgs));
		}

		/*
		 * ----- Route khớp: bind đầy đủ -----
		 */
		$baseRequest = $this->resolveCurrentRequest();

		$named      = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
		$positional = array_values(array_filter($matches, fn($k) => is_int($k) && $k > 0, ARRAY_FILTER_USE_KEY));

		$query = $this->requestBag($baseRequest, 'query');      // GET
		$post  = $this->requestBag($baseRequest, 'request');    // POST
		$attr  = $this->requestBag($baseRequest, 'attributes'); // attributes

		$callParams = [];
		$posIndex   = 0;

		foreach ($parameters as $param) {
			$name = $param->getName();

			// 0) Hook args của WordPress luôn ưu tiên cao nhất, không urldecode.
			if (array_key_exists($name, $hookArgs)) {
				$callParams[$name] = $hookArgs[$name];
				continue;
			}

			// Param kiểu class: Request / __wpspConstruct, còn lại để container DI.
			if ($className = $this->getClassFromType($param->getType())) {
				$special = $this->resolveSpecialClassParam($name, $className, $method);
				if ($special !== null) {
					$callParams[$name] = $special;
				}
				continue;
			}

			// 1) Nếu có named capture trùng tên param -> ưu tiên
			if (array_key_exists($name, $named)) {
				$value = $named[$name];
			}
			// 2) attributes (request attributes)
			elseif (array_key_exists($name, $attr)) {
				$value = $attr[$name];
			}
			// 3) POST (body)
			elseif (array_key_exists($name, $post)) {
				$value = $post[$name];
			}
			// 4) Query string
			elseif (array_key_exists($name, $query)) {
				$value = $query[$name];
			}
			// 5) Positional capture fallback
			elseif (isset($positional[$posIndex])) {
				$value = $positional[$posIndex++];
			}
			// 6) Default value from signature
			elseif ($param->isDefaultValueAvailable()) {
				$value = $param->getDefaultValue();
			}
			else {
				$value = null;
			}

			$callParams[$name] = is_string($value) ? urldecode($value) : $value;
		}

		// Meta luôn ghi đè.
		$callParams = array_merge($callParams, $meta);

		// Args chỉ bổ sung, không ghi đè giá trị đã có.
		foreach ($args as $argKey => $argValue) {
			if (!isset($callParams[$argKey])) {
				$callParams[$argKey] = $argValue;
			}
		}

		// Expose toàn bộ named captures cho middleware / log.
		foreach ($named as $k => $v) {
			if (!array_key_exists($k, $callParams)) {
				$callParams[$k] = is_string($v) ? urldecode($v) : $v;
			}
		}

		// Set parameters cho route.
		if (isset($args['route']) && $args['route'] instanceof RouteData) {
			$routeParameters = $callParams;
			unset($routeParameters['route']);
			$args['route']->parameters = $routeParameters;
		}

		// Phần dư của hook args cho tham số variadic (key số → nối vào cuối).
		foreach ($this->variadicHookArgs($hookArgs) as $value) {
			$callParams[] = $value;
		}

		return $callParams;
	}

	/**
	 * Tham số kiểu class "đặc biệt":
	 * - Request (kể cả class con) → request hiện tại.
	 * - "__wpspConstruct" → tự khởi tạo class và gán thành property.
	 *
	 * @return mixed|null null nếu không thuộc trường hợp đặc biệt (để container / manual DI xử lý).
	 */
	protected function resolveSpecialClassParam(string $name, string $className, $method) {
		if ($requestInstance = $this->resolveRequestForType($className)) {
			return $requestInstance;
		}

		if ($method === '__wpspConstruct' && class_exists($className)) {
			return $this->injectConstructProperty($name, $className);
		}

		return null;
	}

	/**
	 * Khởi tạo service cho tham số của __wpspConstruct và gán thành property.
	 *
	 * - Khởi tạo lỗi  → log, trả null để container / manual DI làm fallback.
	 * - Gán lỗi       → lỗi khai báo của dev: WP_DEBUG thì ném lại, ngược lại log.
	 *
	 * @return object|null Instance đã tạo, hoặc null nếu khởi tạo thất bại.
	 */
	protected function injectConstructProperty(string $name, string $className) {
		if ($name === '' || !class_exists($className)) {
			return null;
		}

		try {
			$instance = new $className($this->mainPath, $this->rootNamespace, $this->prefixEnv, $this->extraParams);
		}
		catch (\Throwable $e) {
			$this->handleInjectionError($e, $className, $name, false);
			return null;
		}

		try {
			$this->assignInjectedProperty($name, $instance);
		}
		catch (\Throwable $e) {
			$this->handleInjectionError($e, $className, $name, true);
		}

		return $instance;
	}

	/**
	 * Gán property được inject, hỗ trợ private / protected / readonly
	 * khai báo ở bất kỳ class nào trong hierarchy.
	 */
	protected function assignInjectedProperty(string $name, $value): void {
		$declaringClass = null;
		$ref            = new \ReflectionClass($this);

		do {
			if ($ref->hasProperty($name)) {
				$prop = $ref->getProperty($name);
				if ($prop->isStatic()) return;
				if ($prop->getDeclaringClass()->getName() === $ref->getName()) {
					$declaringClass = $ref->getName();
					break;
				}
			}
		}
		while ($ref = $ref->getParentClass());

		if ($declaringClass === null) {
			$this->{$name} = $value; // không khai báo → dynamic property như cũ
			return;
		}

		\Closure::bind(function($n, $v) {
			$this->{$n} = $v;
		}, $this, $declaringClass)($name, $value);
	}

	/**
	 * Xử lý lỗi auto-inject trong __wpspConstruct.
	 *
	 * - $rethrow + WP_DEBUG → ném LogicException (giữ exception gốc qua $previous).
	 * - Còn lại             → log (logger của container nếu có, ngược lại error_log)
	 *                         + action "{app}_injection_failed".
	 */
	protected function handleInjectionError(\Throwable $e, string $className, string $name, bool $rethrow): void {
		$message = sprintf(
			'[%s] Không inject được [%s] vào property $%s: %s',
			static::class, $className, $name, $e->getMessage()
		);

		if ($rethrow && defined('WP_DEBUG') && WP_DEBUG) {
			throw new \LogicException($message, 0, $e);
		}

		// Log: container 'log' (CORE, hoặc Lite nếu có) → error_log.
		$logged = false;
		try {
			$app = (is_object($this->funcs) && method_exists($this->funcs, '_getApplication'))
				? $this->funcs->_getApplication()
				: null;

			if ($app && method_exists($app, 'bound') && method_exists($app, 'make') && $app->bound('log')) {
				$app->make('log')->error($message, ['exception' => $e]);
				$logged = true;
			}
		}
		catch (\Throwable $logError) {
		}

		if (!$logged) {
			error_log($message);
		}

		// Hook cho plugin tự xử lý (Sentry, Query Monitor...).
		if (function_exists('do_action')) {
			$prefix = (is_object($this->funcs) && method_exists($this->funcs, '_getAppShortName'))
				? $this->funcs->_getAppShortName()
				: 'wpsp';

			do_action($prefix . '_injection_failed', $className, $name, $e, $this);
		}
	}

	/**
	 * Map đối số WordPress hook (add_action / add_filter) vào signature theo thứ tự.
	 *
	 * - Param không type / type builtin → nhận đối số hiện tại.
	 * - Param type class → chỉ nhận nếu đối số là instance của class đó (vd: \WP_Post $post),
	 *   ngược lại để DI (vd: MyService $service) và KHÔNG tiêu thụ đối số.
	 * - Param nullable nhận được null từ WP → nhận null.
	 * - Param variadic → nhận toàn bộ phần còn lại (key số).
	 *
	 * @param \ReflectionParameter[] $parameters
	 *
	 * @return array<string|int, mixed>
	 */
	protected function mapHookArgs(array $parameters, array $wpParams): array {
		if (empty($wpParams)) {
			return [];
		}

		$wpParams = array_values($wpParams);
		$total    = count($wpParams);
		$mapped   = [];
		$i        = 0;

		foreach ($parameters as $param) {
			if ($i >= $total) {
				break;
			}

			if ($param->isVariadic()) {
				foreach (array_slice($wpParams, $i) as $rest) {
					$mapped[] = $rest;
				}
				break;
			}

			$value     = $wpParams[$i];
			$className = $this->getClassFromType($param->getType());

			if ($className !== null) {
				if ($value instanceof $className || ($value === null && $param->allowsNull())) {
					$mapped[$param->getName()] = $value;
					$i++;
				}
				continue;
			}

			$mapped[$param->getName()] = $value;
			$i++;
		}

		return $mapped;
	}

	/**
	 * Phần hook args dành cho tham số variadic (các key dạng số).
	 */
	protected function variadicHookArgs(array $hookArgs): array {
		return array_values(array_filter($hookArgs, 'is_int', ARRAY_FILTER_USE_KEY));
	}

	/**
	 * Reflection cho callable, cache theo Class::method (Closure không cache được).
	 */
	protected function reflectCallable($callbackOrClass, $method = null): \ReflectionFunctionAbstract {
		static $cache = [];

		if ($callbackOrClass instanceof \Closure) {
			return new \ReflectionFunction($callbackOrClass);
		}

		if (is_array($callbackOrClass)) {
			$method          = $callbackOrClass[1] ?? $method;
			$callbackOrClass = $callbackOrClass[0];
		}

		$class = is_object($callbackOrClass) ? get_class($callbackOrClass) : $callbackOrClass;

		return $cache[$class . '::' . $method] ??= new \ReflectionMethod($class, $method);
	}

	/*
	 * =====================================================================
	 * MANUAL DI (khi container không có call())
	 * =====================================================================
	 */

	/**
	 * Tự động Resolve Dependency Injection dựa trên Reflection.
	 */
	protected function manualResolveAndCall($callback, array $callParams = []) {
		if ($callback instanceof \Closure) {
			$reflection = new \ReflectionFunction($callback);
			$instance   = null;
		}
		elseif (is_array($callback)) {
			[$classOrInstance, $method] = $callback;

			$instance   = is_object($classOrInstance) ? $classOrInstance : $this->manualMakeClass($classOrInstance);
			$reflection = $this->reflectCallable($instance, $method);
		}
		else {
			throw new \InvalidArgumentException("Unsupported callback type for manual DI.");
		}

		$resolvedArgs = [];

		foreach ($reflection->getParameters() as $param) {
			// Variadic → nhận toàn bộ giá trị key số còn lại trong $callParams.
			if ($param->isVariadic()) {
				foreach (array_filter($callParams, 'is_int', ARRAY_FILTER_USE_KEY) as $value) {
					$resolvedArgs[] = $value;
				}
				break;
			}

			$paramName = $param->getName();
			$className = $this->getClassFromType($param->getType());

			// 1. Đã có sẵn trong $callParams
			if (array_key_exists($paramName, $callParams)) {
				$resolvedArgs[] = $callParams[$paramName];
				continue;
			}

			// 2. Class type-hint
			if ($className && class_exists($className)) {
				if ($requestInstance = $this->resolveRequestForType($className)) {
					$resolvedArgs[] = $requestInstance;
					continue;
				}

				foreach ($callParams as $argVal) {
					if ($argVal instanceof $className) {
						$resolvedArgs[] = $argVal;
						continue 2;
					}
				}

				$resolvedArgs[] = $this->manualMakeClass($className);
				continue;
			}

			// 3. Default value / 4. null
			$resolvedArgs[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
		}

		return $instance === null
			? $reflection->invokeArgs($resolvedArgs)
			: $reflection->invokeArgs($instance, $resolvedArgs);
	}

	/**
	 * Tự khởi tạo class và inject dependency vào constructor (đệ quy).
	 */
	protected function manualMakeClass(string $className) {
		if (!class_exists($className)) {
			throw new \RuntimeException("Class {$className} does not exist.");
		}

		$reflector = new \ReflectionClass($className);

		if (!$reflector->isInstantiable()) {
			throw new \RuntimeException("Class {$className} is not instantiable.");
		}

		$constructor = $reflector->getConstructor();

		if (is_null($constructor)) {
			return new $className();
		}

		$constructorParams = [];
		foreach ($constructor->getParameters() as $param) {
			$typeClass = $this->getClassFromType($param->getType());

			if ($typeClass && class_exists($typeClass)) {
				$constructorParams[] = $this->resolveRequestForType($typeClass) ?? $this->manualMakeClass($typeClass);
			}
			elseif ($param->isDefaultValueAvailable()) {
				$constructorParams[] = $param->getDefaultValue();
			}
			else {
				$constructorParams[] = null;
			}
		}

		return $reflector->newInstanceArgs($constructorParams);
	}

	/*
	 * =====================================================================
	 * ROUTE / REQUEST
	 * =====================================================================
	 */

	/**
	 * Đưa route hiện tại vào request để callback có thể gọi $request->route('id').
	 */
	public function setRouteResolver() {
		$route = $this->funcs->_getRouteManager()->currentRoute() ?? null;

		if (!$route || !in_array($route->type, ['AdminPages', 'Apis', 'Ajaxs', 'FrontPages', 'RewriteFrontPages'])) {
			return;
		}

		if (!method_exists($this->request, 'setRouteResolver')) {
			return;
		}

		if ($this->request->getMethod() !== strtoupper($route->method)) {
			return;
		}

		$requestPath = $this->request->getRequestUri();
		$fullPath    = $route->fullPath;
		$regexPath   = $this->funcs->_regexPath($fullPath, $route->args['force_regex'] ?? false);

		if (
			empty($regexPath)
			|| !(
				@preg_match('#' . $regexPath . '#iu', $requestPath)
				|| @preg_match('#' . $fullPath . '#iu', $requestPath)
			)
		) {
			return;
		}

		if (
			@preg_match('/' . $route->fullPathRegex . '/iu', $requestPath)
			|| @preg_match('/' . $fullPath . '/iu', $requestPath)
			|| @preg_match($route->fullPathRegex, $requestPath)
		) {
			$this->request->setRouteResolver(fn() => $route);
		}
	}

	/**
	 * Nếu $className là Lite Request (kể cả class con) thì trả về request hiện tại; ngược lại null.
	 *
	 * - Type là class cha của request hiện tại → trả về chính instance đó.
	 * - Type là class con → createFrom() (nếu có) để chép dữ liệu sang, cache theo request + class.
	 */
	protected function resolveRequestForType(string $className) {
		if (!is_a($className, \WPSPCORELITE\App\Http\Request::class, true)) {
			return null;
		}

		$current = $this->resolveCurrentRequest();

		if (!$current) {
			return null;
		}

		if ($current instanceof $className) {
			return $current;
		}

		if (!method_exists($className, 'createFrom')) {
			return null;
		}

		static $copies = [];
		$key = spl_object_id($current) . '|' . $className;

		if (!isset($copies[$key])) {
			try {
				$copies[$key] = $className::createFrom($current);
			}
			catch (\Throwable $e) {
				return null;
			}
		}

		return $copies[$key];
	}

	/**
	 * Lấy tên class từ một ReflectionType (bỏ qua builtin; union type → class đầu tiên).
	 */
	protected function getClassFromType(?\ReflectionType $type): ?string {
		if ($type instanceof \ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}

		if ($type instanceof \ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
					return $t->getName();
				}
			}
		}

		return null;
	}

	/*
	 * =====================================================================
	 * RESOLVE & CALL
	 * =====================================================================
	 */

	/**
	 * Trả về Closure chứa callback đã được resolve Dependency Injection.
	 */
	public function resolveCallback($callback, $callParams = []) {
		return $this->resolveAndCall($callback, $callParams, false);
	}

	/**
	 * Call callback với Dependency Injection.
	 *
	 * Khi $call = false: trả về Closure nhận hook args của WordPress,
	 * container/facade được đồng bộ TẠI THỜI ĐIỂM GỌI (không phải lúc tạo closure).
	 */
	public function resolveAndCall($callback, $callParams = [], $call = true, $method = null) {
		if (!$call) {
			return function(...$wpParams) use ($callback, $callParams) {
				return $this->invokeResolved($callback, $this->withHookArgs($callback, (array)$callParams, $wpParams));
			};
		}

		return $this->invokeResolved($callback, (array)$callParams);
	}

	/**
	 * Gọi callback qua container (nếu có call()) hoặc manual resolver.
	 */
	protected function invokeResolved($callback, array $callParams) {
		$container = $this->currentApp();

		if ($container) {
			$this->syncContainerState($container);

			if (method_exists($container, 'call')) {
				return $container->call($callback, $callParams);
			}
		}

		return $this->manualResolveAndCall($callback, $callParams);
	}

	/**
	 * Gộp hook args của WordPress vào $callParams theo signature của callback.
	 */
	protected function withHookArgs($callback, array $callParams, array $wpParams): array {
		if (empty($wpParams)) {
			return $callParams;
		}

		try {
			$reflection = $this->reflectCallable($callback);
		}
		catch (\Throwable $e) {
			return $callParams;
		}

		return array_merge($callParams, $this->mapHookArgs($reflection->getParameters(), $wpParams));
	}

	/**
	 * Đồng bộ Lite Facade về container của plugin hiện tại.\
	 * Chỉ ghi khi khác, và xoá cache resolved instance khi đổi container
	 * (nếu Lite Facade có các method tương ứng).
	 */
	protected function syncContainerState($container): void {
		$facade = \WPSPCORELITE\App\Support\Facades\Facade::class;

		if (!class_exists($facade) || !method_exists($facade, 'setFacadeApplication')) {
			return;
		}

		if (method_exists($facade, 'getFacadeApplication') && $facade::getFacadeApplication() === $container) {
			return;
		}

		if (method_exists($facade, 'clearResolvedInstances')) {
			$facade::clearResolvedInstances();
		}

		$facade::setFacadeApplication($container);
	}

	/**
	 * Trả về callback với Dependency Injection.\
	 * Tự động hoàn toàn.
	 */
	public function autoResolveCallback($path, $fullPath, $requestPath, $callbackOrClass, $method = null, $args = []) {
		return $this->autoResolveAndCall($path, $fullPath, $requestPath, $callbackOrClass, $method, $args, false);
	}

	/**
	 * Gọi callback với Dependency Injection.\
	 * Tự động hoàn toàn.
	 */
	public function autoResolveAndCall($path, $fullPath, $requestPath, $callbackOrClass, $method = null, $args = [], $call = true) {
		$class  = is_array($callbackOrClass) ? $callbackOrClass[0] : $callbackOrClass;
		$method = $method ?? (is_array($callbackOrClass) ? ($callbackOrClass[1] ?? null) : null);
		$method = $method ?? '__instanceConstruct';

		if ($class && method_exists($class, $method)) {
			$callback = $this->prepareCallbackFunction($method, $path, $fullPath, $class, $args);
			return $this->resolveAndCall($callback, [], $call, $method);
		}

		return null;
	}

	/*
	 * =====================================================================
	 * UTILS
	 * =====================================================================
	 */

	/**
	 * Chuẩn hóa callback để trả về [class, method].
	 */
	public function normalizeCallback($callback) {
		if ($callback instanceof \Closure) {
			return [null, $callback];
		}

		if (is_array($callback) && is_object($callback[0]) && is_string($callback[1])) {
			return [$callback[0], $callback[1]];
		}

		throw new \RuntimeException("Invalid callback format");
	}

	/**
	 * Build params for callable (route callback).
	 */
	public function buildParametersForCallable($callback, $path, $fullPath, $requestPath, $args = [], $wpParams = []) {
		[$class, $method] = $this->normalizeCallback($callback);

		// Closure: normalizeCallback trả [null, Closure] → reflect chính Closure.
		if ($class === null) {
			return $this->getCallParams($path, $fullPath, $requestPath, $method, null, $args, $wpParams);
		}

		return $this->getCallParams($path, $fullPath, $requestPath, $class, $method, $args, $wpParams);
	}

}