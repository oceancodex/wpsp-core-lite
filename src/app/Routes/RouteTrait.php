<?php

namespace WPSPCORELITE\App\Routes;

use WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException;
use WPSPCORELITE\App\Http\Middleware\ThrottleRequests;
use WPSPCORELITE\App\Http\Request;

/**
 * RouteTrait — bản LITE.
 *
 * KHÔNG phụ thuộc Illuminate / Symfony / Laravel Framework. Chỉ dùng PHP 8.0+ và WordPress core.
 *
 * - Response cho $next       → \WP_HTTP_Response.
 * - Container / DI            → container Lite (make / call) nếu có, ngược lại manual DI bằng Reflection.
 * - Ngữ cảnh toàn cục         → chỉ Lite Facade (đã tách theo plugin qua $this->funcs->_getApplication()).
 * - Throttle                  → Lite ThrottleRequests + RateLimiter ('rate.limiter') của plugin.
 *                               Bộ đếm nằm trong cache của plugin => không bao giờ đếm chung với plugin khác.
 * - Không có Eloquent model binding (Lite không có ORM).
 *
 * ---------------------------------------------------------------------
 * CẤU TRÚC FILE (giống nhau giữa WPSPCORE và WPSPCORELITE)
 * ---------------------------------------------------------------------
 *
 * Từ section MIDDLEWARE đến hết section UTILS: GIỐNG HỆT NHAU giữa 2 bản.
 * Section "RIÊNG CỦA BẢN ..." ở cuối file: các method cùng tên, cùng vai trò
 * nhưng khác cách cài đặt do nền tảng (Illuminate hay PHP thuần).
 * Khi compare 2 file, khác biệt chỉ nằm ở: docblock đầu file, các dòng `use`
 * và section cuối.
 *
 * Khi sửa phần chung: sửa ở 1 bản rồi copy nguyên văn sang bản kia.
 *
 * ---------------------------------------------------------------------
 * NGỮ NGHĨA CHUNG (giữ 2 bản giống nhau khi port code)
 * ---------------------------------------------------------------------
 *
 * 1. Middleware trả redirect (3xx) mà KHÔNG gọi $next → FAIL, và response đó
 *    được ghi nhớ để nơi gọi gửi đi (sendBlockedMiddlewareResponse()).
 * 2. Closure middleware nhận thêm $args (tham số thứ 3).
 * 3. Hook args của WordPress (add_action / add_filter) được map theo signature,
 *    có xét type, và ưu tiên cao nhất. Placeholder '' của do_action() không
 *    đối số được bỏ qua (trừ route Filters).
 * 4. Throttle đếm đúng 1 lần cho mỗi (request, signature).
 * 5. [Class, 'method'] ở top-level là middleware lá; truyền 1 middleware đơn lẻ được.
 * 6. Phân quyền kiểu Laravel: middleware 'can:update,post', RouteData::can(),
 *    controller middleware (authorizeResource(), ->middleware()->only(), HasMiddleware)
 *    chạy ngay trước action của route. Xem section AUTHORIZATION.
 *
 * ---------------------------------------------------------------------
 * BẤT BIẾN QUAN TRỌNG (KHÔNG ĐƯỢC PHÁ KHI TỐI ƯU)
 * ---------------------------------------------------------------------
 *
 * 1. Mỗi lần gọi callback, ngữ cảnh container PHẢI trỏ về đúng application
 *    của plugin đang chạy (xem bindContainerContext()). Ngữ cảnh được gắn
 *    TẠI THỜI ĐIỂM GỌI callback, không chỉ lúc tạo closure: giữa 2 thời điểm
 *    đó plugin khác có thể đã chạy.
 *
 * 2. Request dùng để bind PHẢI là request hiện tại của plugin ($this->request,
 *    đã gắn session). Chỉ capture() khi không có. Request mới không có session:
 *    back() không lấy được URL trước, còn errors / old input không đọc được.
 *
 * 3. Dữ liệu theo request (kết quả throttle, response bị chặn) được nhớ theo
 *    TỪNG request object (attributes / WeakMap), không theo spl_object_id.
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
	protected function isLastMiddleware($currentClass, $allMiddlewares) {
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
	 * Block có thể lồng nhau vô hạn cấp (phần tử con là mảng có key 'relation').
	 * Đánh giá có short-circuit: AND dừng ở FAIL đầu tiên, OR dừng ở PASS đầu tiên,
	 * nên các middleware phía sau sẽ KHÔNG chạy.
	 *
	 * Phần tử top-level có thể là:
	 * - Mảng block (có hoặc không có 'relation', mặc định AND).
	 * - Middleware lá: string / Closure / [ClassName::class, 'method'].
	 *
	 * Middleware hỗ trợ các định dạng:
	 *
	 * - Closure                       → function($request, $next, $args)
	 * - ClassName::class
	 * - [ClassName::class, 'method']
	 * - 'throttle:60,1' / 'throttle:30rpm' / ['throttle:api'] / ThrottleRequests::using('api')
	 * - 'can:update,post' / 'can:create,App\Models\Post' / Authorize::using('update', 'post')
	 *   (Gate / Policy, xem runCanMiddleware())
	 *
	 * Giá trị trả về của middleware:
	 *
	 * - true / false                        : PASS / FAIL
	 * - \WP_Error                           : FAIL
	 * - Response status >= 400              : FAIL
	 * - Response 3xx mà KHÔNG gọi $next     : FAIL (middleware đang chặn + redirect, vd: redirect login)
	 * - Response còn lại / null             : PASS
	 *
	 * Khi FAIL vì một Response, response đó được ghi nhớ:
	 * nơi gọi dùng sendBlockedMiddlewareResponse() để gửi redirect / lỗi đi.
	 *
	 * Thông tin block hiện tại sẽ được truyền vào `$args['current_block_middleware']`
	 * (middleware dạng class nhận qua DI param tên `$args`, Closure nhận ở tham số thứ 3).
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
	 * Luồng xử lý:
	 *
	 * isPassedMiddleware()
	 *  └─ evaluateMiddlewareBlock()      block AND / OR
	 *      └─ evaluateMiddlewareNode()   block con hoặc middleware lá
	 *          ├─ normalizeMiddlewareLeaf()
	 *          └─ runMiddlewareLeaf()
	 *              ├─ runThrottleMiddleware()
	 *              ├─ runCanMiddleware()
	 *              └─ isPassedResult()
	 *
	 * @param array $middlewares Danh sách middleware block cần kiểm tra.
	 * @param mixed $request     Request hiện tại. Nếu null sẽ tự động lấy từ container.
	 * @param array $args        Dữ liệu bổ sung được truyền vào middleware.
	 *
	 * @return bool Trả về true nếu toàn bộ middleware đều PASS, ngược lại false.
	 */
	protected function isPassedMiddleware($middlewares = [], $request = null, $args = []) {
		$request = $request ?? $this->resolveCurrentRequest();

		// Middleware gọi helper / facade (auth(), redirect(), session(), RateLimiter::...)
		// → phải chạy trong đúng container của plugin này, không phải plugin chạy trước.
		$this->bindContainerContext();

		// Mỗi lần đánh giá bắt đầu sạch: bỏ response bị chặn và message phân quyền
		// của lần đánh giá trước (không để lý do cũ lọt sang route / lần kiểm tra khác).
		$this->forgetBlockedMiddlewareResponse($request);
		$this->requestStash($request, $this->authorizationMessageKey(), null);

		// Không có middleware → pass
		if (empty($middlewares)) {
			return true;
		}

		// Cho phép truyền 1 middleware đơn lẻ (string / Closure) thay vì mảng.
		// Không dùng (array) vì (array)$closure = [] → middleware bị bỏ qua.
		if (!is_array($middlewares)) {
			$middlewares = [$middlewares];
		}

		$args = (array)$args;

		// Mỗi phần tử top-level là 1 block. Route PASS khi TẤT CẢ block PASS.
		foreach ($middlewares as $blockMiddleware) {
			$passed = (is_array($blockMiddleware) && !$this->isClassMethodLeaf($blockMiddleware))
				? $this->evaluateMiddlewareBlock($blockMiddleware, $args, $request)
				: $this->evaluateMiddlewareNode($blockMiddleware, $args, $request); // top-level lá: string / Closure / [Class, 'method']

			if (!$passed) {
				return false;
			}
		}

		$this->forgetBlockedMiddlewareResponse($request);

		return true;
	}

	/**
	 * Mảng có phải middleware lá dạng [ClassName::class, 'method'] hay không.
	 *
	 * Chỉ trả true khi KHÔNG thể là block: đúng 2 phần tử key 0/1, phần tử sau KHÔNG
	 * phải class, và:
	 * - phần tử đầu là class tồn tại, phần tử sau là method của class đó; hoặc
	 * - phần tử đầu là 'can:...' / 'throttle:...' (dạng [middleware, 'handle'] do
	 *   BaseRoute / RouteData chuẩn hoá ra).
	 * Một block [A, 'x'] với 'x' không phải class thì vốn đã luôn FAIL,
	 * nên nhận diện này không làm thay đổi route nào đang chạy đúng.
	 */
	protected function isClassMethodLeaf(array $node): bool {
		if (
			count($node) !== 2
			|| !isset($node[0], $node[1])
			|| !is_string($node[0])
			|| !is_string($node[1])
			|| class_exists($node[1])
		) {
			return false;
		}

		if ($this->canSignature($node[0]) !== null || $this->throttleSignature($node[0]) !== null) {
			return true;
		}

		return class_exists($node[0]) && method_exists($node[0], $node[1]);
	}

	/**
	 * Đánh giá 1 node: middleware lá hoặc block con (mảng có key 'relation').
	 *
	 * Mảng KHÔNG có 'relation' ở cấp này được hiểu là middleware lá dạng
	 * [Class, 'method'] / ['throttle:...'].
	 */
	protected function evaluateMiddlewareNode($node, array $args, $request): bool {
		if (is_array($node) && array_key_exists('relation', $node)) {
			return $this->evaluateMiddlewareBlock($node, $args, $request);
		}

		$mw = $this->normalizeMiddlewareLeaf($node, $args);

		// Dạng không hợp lệ → bỏ qua, coi như PASS để không chặn nhầm route.
		if ($mw === null) {
			return true;
		}

		return $this->runMiddlewareLeaf($mw, $request);
	}

	/**
	 * Đánh giá 1 block theo relation (mặc định AND).
	 * Các phần tử con có thể là middleware lá hoặc block con lồng nhau, đệ quy vô hạn cấp.
	 */
	protected function evaluateMiddlewareBlock(array $block, array $args, $request): bool {
		$relation = strtoupper((string)($block['relation'] ?? 'AND'));

		// Block gốc (còn 'relation') cho middleware đọc.
		$args['current_block_middleware'] = $block;

		$children = $block;
		unset($children['relation']);

		if (empty($children)) {
			return true;
		}

		if ($relation === 'OR') {
			// Nhánh OR thua rồi nhánh sau thắng: response "chặn" và message phân quyền của
			// nhánh thua không phải lý do route bị chặn → khôi phục trạng thái trước block.
			$before        = $this->getBlockedMiddlewareResponse($request);
			$beforeMessage = $this->requestStash($request, $this->authorizationMessageKey());

			foreach ($children as $child) {
				if ($this->evaluateMiddlewareNode($child, $args, $request)) {
					$this->requestStash($request, $this->blockedMiddlewareResponseKey(), $before);
					$this->requestStash($request, $this->authorizationMessageKey(), $beforeMessage);
					return true;
				}
			}
			return false;
		}

		// AND
		foreach ($children as $child) {
			if (!$this->evaluateMiddlewareNode($child, $args, $request)) return false;
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
			if ($can = $this->canSignature($mw[0])) {
				return ['type' => 'can', 'value' => $can, 'args' => $args];
			}
			if ($throttle = $this->throttleSignature($mw[0])) {
				return ['type' => 'throttle', 'value' => $throttle, 'args' => $args];
			}
			return ['type' => 'class', 'class' => $mw[0], 'method' => $mw[1] ?? 'handle', 'args' => $args];
		}

		if (is_string($mw)) {
			if ($can = $this->canSignature($mw)) {
				return ['type' => 'can', 'value' => $can, 'args' => $args];
			}
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

		if (is_a(ltrim($parts[0], '\\'), ThrottleRequests::class, true)) {
			return isset($parts[1]) && $parts[1] !== '' ? 'throttle:' . $parts[1] : 'throttle';
		}

		return null;
	}

	/**
	 * 'can:update,post' → giữ nguyên.\
	 * Authorize::using('update', 'post') (dạng "Illuminate\Auth\Middleware\Authorize:update,post") → 'can:update,post'.\
	 * Không phải can → null.
	 */
	protected function canSignature(string $mw): ?string {
		if (str_starts_with($mw, 'can:')) {
			return $mw;
		}

		$parts = explode(':', $mw, 2);

		if (isset($parts[1]) && $parts[1] !== '' && ltrim($parts[0], '\\') === 'Illuminate\\Auth\\Middleware\\Authorize') {
			return 'can:' . $parts[1];
		}

		return null;
	}

	/**
	 * Chạy 1 middleware đã normalize, trả về true (PASS) / false (FAIL).
	 * FAIL vì một Response → ghi nhớ response đó (xem sendBlockedMiddlewareResponse()).
	 */
	protected function runMiddlewareLeaf(array $mw, $request): bool {
		if ($mw['type'] === 'throttle') {
			return $this->runThrottleMiddleware($mw['value'], $request);
		}

		if ($mw['type'] === 'can') {
			return $this->runCanMiddleware($mw['value'], $request, $mw['args']);
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

			$instance = $this->containerMake($class);
			$method   = method_exists($instance, $mw['method']) ? $mw['method'] : 'handle';

			$res = $this->callWithContainer([$instance, $method], [
				'request' => $request,
				'next'    => $next,
				'args'    => $mw['args'] ?? null,
			]);
		}

		$passed = $this->isPassedResult($res, $nextCalled);

		if (!$passed) {
			$this->rememberBlockedMiddlewareResponse($request, $res);
		}

		return $passed;
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
	 * Chạy throttle: ThrottleRequests + RateLimiter của plugin.\
	 * Mỗi signature (vd: 'throttle:60,1', 'throttle:30rpm') chỉ đếm đúng 1 lần / request:
	 * cùng 1 signature xuất hiện ở nhiều block / nhiều route trong 1 request chỉ tăng bộ đếm 1 lần.
	 *
	 * - 'throttle'               → 60 request / phút
	 * - 'throttle:60,1'          → 60 request / 1 phút
	 * - 'throttle:100,60,export' → 100 request / giờ, bộ đếm riêng "export"
	 * - 'throttle:10|60,1'       → khách 10, user đăng nhập 60 / phút
	 * - 'throttle:30rpm'         → named limiter: RateLimiter::for('30rpm', fn($request) => Limit::perMinute(30))
	 *
	 * Vượt giới hạn / lỗi → dừng request luôn (xem respondThrottleFailure()):
	 * 429 (JSON nếu client muốn JSON, ngược lại wp_die) + Retry-After / X-RateLimit-*.
	 */
	protected function runThrottleMiddleware(string $value, $request): bool {
		$memoKey = '_' . $this->hookPrefix() . '_throttle.' . $value;
		$cached  = $this->requestStash($request, $memoKey);

		if ($cached !== null) {
			return (bool)$cached; // dùng lại kết quả, không increment/ghi cache lần nữa
		}

		$parts      = explode(':', $value, 2);
		$parameters = (isset($parts[1]) && $parts[1] !== '') ? array_map('trim', explode(',', $parts[1])) : [];
		$passed     = false;

		try {
			$middleware = $this->makeThrottleMiddleware($parameters);

			$response = $middleware->handle($request, function() {
				return $this->makeNextResponse();
			}, ...$parameters);

			// X-RateLimit-* được gắn vào response giả của $next → gửi ra client.
			$this->sendHeaders($this->rateLimitHeaders($response));

			$passed = true;
		}
		catch (\Throwable $e) {
			$this->respondThrottleFailure($e);
		}

		$this->requestStash($request, $memoKey, $passed);

		return $passed;
	}

	/**
	 * Lấy các header Retry-After / X-RateLimit-* từ response (duck-typing).
	 * Chỉ lấy các header này để không gửi nhầm Cache-Control / Date của response giả.
	 */
	protected function rateLimitHeaders($response): array {
		if (!is_object($response)) {
			return [];
		}

		if (method_exists($response, 'get_headers')) {
			$headers = (array)$response->get_headers();
		}
		elseif (isset($response->headers) && is_object($response->headers) && method_exists($response->headers, 'all')) {
			$headers = (array)$response->headers->all();
		}
		else {
			return [];
		}

		return array_filter($headers, function($name) {
			$name = strtolower((string)$name);
			return str_starts_with($name, 'x-ratelimit-') || $name === 'retry-after';
		}, ARRAY_FILTER_USE_KEY);
	}

	/*
	 * =====================================================================
	 * AUTHORIZATION (GATE / POLICY)
	 * =====================================================================
	 *
	 * 3 cách dùng, cùng 1 cơ chế:
	 *
	 * 1. Middleware ở route:  Route::middleware('can:update,post')->get(...)
	 *                         Route::get(...)->middleware(Authorize::using('update', 'post'))
	 * 2. RouteData::can():    Route::get(...)->can('update', 'post')   (giống Laravel 12)
	 * 3. Controller middleware trong page class (chạy ngay trước action của route):
	 *    $this->authorizeResource(Post::class, 'post');                 (AuthorizesRequests)
	 *    $this->middleware('can:update,post')->only('edit', 'update');
	 *    hoặc implements HasMiddleware + static middleware() (Laravel 11+).
	 *
	 * Tham số model ('post') được resolve giống Authorize middleware của Laravel:
	 * - Có dấu "\"      → tên class, giữ nguyên (viewAny / create): 'can:create,App\Models\Post'.
	 * - Trong nháy      → chuỗi literal: "can:edit,'draft'".
	 * - Còn lại         → tham số route cùng tên (xem resolveMiddlewareParameter()):
	 *                     action khai báo kiểu Model → model binding; ngược lại giá trị thô.
	 */

	/**
	 * Chạy middleware 'can:ability,model1,model2...'.
	 *
	 * Từ chối → FAIL. Message của Policy (Response::deny('...')) được nhớ lại để nơi gọi
	 * hiển thị (xem lastAuthorizationMessage()), kèm action "{app}_authorization_denied".
	 */
	protected function runCanMiddleware(string $value, $request, array $args): bool {
		$parts   = array_map('trim', explode(',', substr($value, 4)));
		$ability = array_shift($parts);

		if ($ability === '') {
			return false;
		}

		$arguments = [];
		foreach ($parts as $model) {
			if ($model !== '') {
				$arguments[] = $this->resolveAuthorizationArgument($model, $request, $args);
			}
		}

		[$allowed, $message] = $this->authorizeAbility($ability, $arguments, $request);

		if (!$allowed) {
			// WP_DEBUG + Gate từ chối mà không có message (thường là Gate không gọi tới Policy):
			// tự chẩn đoán để biết vì sao (user, đối số, Policy...).
			if (!$message && defined('WP_DEBUG') && WP_DEBUG) {
				$message = $value . ' is denied. ' . $this->describeAuthorizationContext($ability, $arguments);
			}

			$this->requestStash($request, $this->authorizationMessageKey(), $message ?: 'This action is unauthorized.');

			if (function_exists('do_action')) {
				do_action($this->hookPrefix() . '_authorization_denied', $ability, $arguments, $message, $request);
			}
		}

		return $allowed;
	}

	/**
	 * 1 tham số của 'can:' → đối số cho Gate (giống Authorize::getModel() của Laravel).
	 */
	protected function resolveAuthorizationArgument(string $model, $request, array $args) {
		$model = trim($model);

		// Tên class (vd: App\Models\Post) → giữ nguyên, Gate tìm Policy theo class.
		if (str_contains($model, '\\')) {
			return $model;
		}

		$value = $this->resolveMiddlewareParameter($model, $request, $args);

		if ($value !== null) {
			return $value;
		}

		// Class không có namespace (vd: \WP_Post::class → 'WP_Post'): không có tham số route cùng tên
		// → coi là tên class, để ->can('view', \WP_Post::class) tìm được Policy như tên class có namespace.
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $model) && (class_exists($model) || interface_exists($model))) {
			return $model;
		}

		// Chuỗi literal trong nháy: 'draft' / "draft".
		return preg_match('/^[\'"](.*)[\'"]$/', $model, $matches) ? $matches[1] : null;
	}

	/**
	 * Giá trị tham số route $name tại thời điểm chạy middleware.
	 *
	 * Thứ tự:
	 * 1) $args['parameters'] — params đã bind (controller middleware chạy ngay trước action).
	 * 2) Action của route (RouteData::$callback) khai báo param $name kiểu Model
	 *    → model binding từ named capture / query / POST (xem resolveModelParam()).
	 * 3) Giá trị thô: named capture → query → POST (đã urldecode).
	 *
	 * Route chưa chạy getCallParams() khi middleware được đánh giá, nên phải tự
	 * so khớp fullPath với URL hiện tại (xem routeMatchSources()).
	 */
	protected function resolveMiddlewareParameter(string $name, $request, array $args) {
		if (isset($args['parameters']) && is_array($args['parameters']) && array_key_exists($name, $args['parameters'])) {
			return $args['parameters'][$name];
		}

		$route = $args['route'] ?? null;

		if (!$route instanceof RouteData) {
			return null;
		}

		$sources = $this->routeMatchSources($route, $request);
		$param   = $this->routeActionParameter($route, $name);

		if ($param !== null && ($className = $this->getClassFromType($param->getType()))) {
			[$isModel, $model] = $this->resolveModelParam($param, $className, $sources);
			if ($isModel) {
				return $model;
			}
		}

		foreach (['named', 'query', 'post'] as $source) {
			if (array_key_exists($name, $sources[$source])) {
				$value = $sources[$source][$name];
				return is_string($value) ? urldecode($value) : $value;
			}
		}

		return null;
	}

	/**
	 * Nguồn dữ liệu của route cho request hiện tại (giống nhánh "route khớp" của getCallParams()):
	 * ['named' => named captures, 'query' => GET, 'post' => POST, 'args' => RouteData::$args].
	 */
	protected function routeMatchSources(RouteData $route, $request): array {
		$requestPath = (is_object($request) && method_exists($request, 'getRequestUri'))
			? (string)$request->getRequestUri()
			: (string)($_SERVER['REQUEST_URI'] ?? '');

		$fullPath  = (string)$route->fullPath;
		$regexPath = $this->funcs->_regexPath($fullPath, $route->args['force_regex'] ?? false);
		$matches   = [];

		if (!empty($regexPath)) {
			@preg_match('#' . $regexPath . '#iu', $requestPath, $matches)
			|| @preg_match('#' . $fullPath . '#iu', $requestPath, $matches);
		}

		return [
			'named' => array_filter((array)$matches, 'is_string', ARRAY_FILTER_USE_KEY),
			'query' => $this->requestBag($request, 'query'),
			'post'  => $this->requestBag($request, 'request'),
			'args'  => (array)$route->args,
		];
	}

	/**
	 * ReflectionParameter tên $name của action route ([Class, 'method']), hoặc null.
	 */
	protected function routeActionParameter(RouteData $route, string $name): ?\ReflectionParameter {
		$callback = $route->callback;

		try {
			if ($callback instanceof \Closure) {
				$reflection = new \ReflectionFunction($callback);
			}
			elseif (is_array($callback) && isset($callback[0], $callback[1]) && is_string($callback[1]) && method_exists($callback[0], $callback[1])) {
				$reflection = $this->reflectCallable($callback[0], $callback[1]);
			}
			else {
				return null;
			}
		}
		catch (\Throwable $e) {
			return null;
		}

		foreach ($reflection->getParameters() as $param) {
			if ($param->getName() === $name) {
				return $param;
			}
		}

		return null;
	}

	/**
	 * Message từ chối của lần kiểm tra quyền gần nhất trên request (Policy deny message).
	 */
	protected function lastAuthorizationMessage($request = null): ?string {
		$message = $this->requestStash($request ?? $this->resolveCurrentRequest(), $this->authorizationMessageKey());

		return is_string($message) ? $message : null;
	}

	protected function authorizationMessageKey(): string {
		return '_' . $this->hookPrefix() . '_authorization_message';
	}

	/**
	 * Lỗi xảy ra khi Gate / Policy chạy (không phải kết quả "không có quyền").
	 *
	 * - WP_DEBUG: ném lại để dev thấy đúng nguyên nhân (Ignition / trang lỗi). Với 2 lỗi
	 *   hay gặp, giữ NGUYÊN loại lỗi (vẫn là \Error, không bị catch (\Exception) nuốt mất)
	 *   nhưng thêm gợi ý cách sửa vào đầu message (lỗi gốc giữ ở getPrevious()).
	 * - Production: ghi error_log, từ chối với message chung.
	 *
	 * @return array{0: bool, 1: ?string}
	 */
	protected function handleAuthorizationError(\Throwable $e, string $ability, array $arguments = []): array {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$hint = $this->authorizationErrorHint($e, $ability, $arguments);

			if ($hint !== null && $e instanceof \ArgumentCountError) {
				throw new \ArgumentCountError($hint . ' — ' . $e->getMessage(), 0, $e);
			}

			if ($hint !== null && $e instanceof \TypeError) {
				throw new \TypeError($hint . ' — ' . $e->getMessage(), 0, $e);
			}

			throw $e;
		}

		error_log(sprintf('[%s] can:%s error while calling Gate / Policy: %s in %s:%d', static::class, $ability, $e->getMessage(), $e->getFile(), $e->getLine()));

		return [false, null];
	}

	/**
	 * Gợi ý cách sửa cho lỗi Gate / Policy hay gặp, hoặc null.
	 */
	protected function authorizationErrorHint(\Throwable $e, string $ability, array $arguments): ?string {
		$classArgument = null;
		foreach ($arguments as $argument) {
			if (is_string($argument) && class_exists($argument)) {
				$classArgument = $argument;
				break;
			}
		}

		// ->can('view', Model::class): Gate bỏ tên class → Policy::view($user) thiếu đối số model.
		if ($e instanceof \ArgumentCountError && $classArgument !== null) {
			return sprintf(
				"can:%s was passed CLASS NAME [%s]: Gate drops the class name and calls Policy::%s(\$user) without a model. "
				. "Ability does not need a model → use viewAny / create, or declare ?Model \$model = null in the Policy. "
				. "Ability needs a specific model → pass the route parameter name: ->can('%s', 'user') and type-hint the action with Model (UsersModel \$user)",
				$ability, $classArgument, $ability, $ability
			);
		}

		// Tham số đầu (user) của Policy không nhận kiểu user hiện tại (vd: WPUsersModel từ fallback WordPress).
		if ($e instanceof \TypeError && str_contains($e->getMessage(), 'Argument #1')) {
			return sprintf(
				"can:%s: user type does not match the Policy's first parameter. In wp-admin / front-end, the user may be the WordPress "
				. "fallback model (e.g. WPUsersModel) → declare a union type, e.g. UsersModel|WPUsersModel \$user",
				$ability
			);
		}

		return null;
	}

	/**
	 * Mô tả ngắn 1 đối số của Gate để chẩn đoán: Model#id, 'chuỗi' (string), null...
	 */
	protected function describeAuthorizationArgument($argument): string {
		if ($argument === null) {
			return 'null';
		}

		if (is_object($argument)) {
			$key = method_exists($argument, 'getKey') ? $argument->getKey() : null;
			return get_class($argument) . ($key !== null ? '#' . $key : '');
		}

		if (is_string($argument)) {
			return class_exists($argument) ? $argument . ' (class)' : "'" . $argument . "' (string)";
		}

		return var_export($argument, true) . ' (' . get_debug_type($argument) . ')';
	}

	/*
	 * =====================================================================
	 * BLOCKED MIDDLEWARE RESPONSE
	 * =====================================================================
	 */

	/**
	 * Response mà middleware đã dùng để chặn route ở lần isPassedMiddleware() gần nhất
	 * (vd: redirect('/login'), back()->withErrors(...), response 403).
	 * null nếu route PASS hoặc middleware chỉ trả false / \WP_Error.
	 */
	protected function getBlockedMiddlewareResponse($request = null) {
		$request  = $request ?? $this->resolveCurrentRequest();
		$response = $this->requestStash($request, $this->blockedMiddlewareResponseKey());

		return is_object($response) ? $response : null;
	}

	/**
	 * Gửi response đã chặn route ra client. Trả về true nếu đã gửi.
	 *
	 * Nơi gọi nên exit ngay sau khi hàm trả true:
	 *
	 *     if (!$this->isPassedMiddleware($middlewares, $request, $args)) {
	 *         if ($this->sendBlockedMiddlewareResponse($request)) exit;
	 *         wp_die(...403...);
	 *     }
	 *
	 * Trả false (không gửi gì) khi:
	 * - không có response, hoặc header đã gửi;
	 * - response là redirect về CHÍNH URL đang truy cập (sẽ thành vòng lặp redirect vô hạn,
	 *   vd: back() khi không có trang trước, redirect()->refresh());
	 * - filter "{app}_send_blocked_middleware_response" trả false (tắt hẳn tính năng):
	 *       add_filter('{app}_send_blocked_middleware_response', '__return_false');
	 *
	 * Action "{app}_before_send_middleware_response" chạy ngay trước khi gửi:
	 * nếu session được lưu ở cuối request bằng cơ chế riêng, có thể lưu tại đây
	 * để flash data của withErrors() / with() không bị mất. Sau khi gửi, header
	 * (kể cả Set-Cookie) không thể thêm được nữa.
	 */
	protected function sendBlockedMiddlewareResponse($request = null): bool {
		$request  = $request ?? $this->resolveCurrentRequest();
		$response = $this->getBlockedMiddlewareResponse($request);

		if ($response === null || headers_sent()) {
			return false;
		}

		if ($this->isRedirectToCurrentUrl($response, $request)) {
			return false;
		}

		if (
			function_exists('apply_filters')
			&& !apply_filters($this->hookPrefix() . '_send_blocked_middleware_response', true, $response, $request)
		) {
			return false;
		}

		if (function_exists('do_action')) {
			do_action($this->hookPrefix() . '_before_send_middleware_response', $response, $request);
		}

		// Response có send(): Symfony / Illuminate / Lite Response, RedirectResponse...
		// Symfony send(bool $flush = true) gọi fastcgi_finish_request(): cắt kết nối ngay,
		// mọi header / cookie gửi sau đó (vd: lưu session lúc shutdown) bị mất.
		// → truyền $flush = false khi có tham số này, để request kết thúc bình thường bằng exit.
		if (method_exists($response, 'send')) {
			$response->send(...$this->responseSendArgs($response));
			return true;
		}

		// \WP_HTTP_Response / \WP_REST_Response.
		$status = $this->getResponseStatusCode($response);
		if ($status === null) {
			return false;
		}

		function_exists('status_header') ? status_header($status) : http_response_code($status);

		if (method_exists($response, 'get_headers')) {
			$this->sendHeaders((array)$response->get_headers());
		}

		if (method_exists($response, 'get_data')) {
			$data = $response->get_data();
			if (is_scalar($data)) {
				echo $data;
			}
			elseif ($data !== null) {
				$this->sendHeaders(['Content-Type' => 'application/json; charset=utf-8']);
				echo function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
			}
		}

		return true;
	}

	/**
	 * Ghi nhớ response chặn route. Chỉ giữ response ĐẦU TIÊN
	 * (AND dừng ở FAIL đầu tiên nên đó chính là lý do bị chặn).
	 */
	protected function rememberBlockedMiddlewareResponse($request, $res): void {
		if (!is_object($res) || $this->getResponseStatusCode($res) === null) {
			return;
		}

		if ($this->getBlockedMiddlewareResponse($request) !== null) {
			return;
		}

		$this->requestStash($request, $this->blockedMiddlewareResponseKey(), $res);
	}

	protected function forgetBlockedMiddlewareResponse($request): void {
		$this->requestStash($request, $this->blockedMiddlewareResponseKey(), null);
	}

	protected function blockedMiddlewareResponseKey(): string {
		return '_' . $this->hookPrefix() . '_blocked_middleware_response';
	}

	/**
	 * Response có phải redirect (3xx) về chính URL đang truy cập hay không.
	 * So sánh path + query (query không phân biệt thứ tự), bỏ qua scheme / host / dấu "/" cuối.
	 */
	protected function isRedirectToCurrentUrl($response, $request): bool {
		$status = $this->getResponseStatusCode($response);

		if ($status === null || $status < 300 || $status >= 400) {
			return false;
		}

		$target = null;

		if (method_exists($response, 'getTargetUrl')) {
			$target = $response->getTargetUrl();
		}
		elseif (isset($response->headers) && is_object($response->headers) && method_exists($response->headers, 'get')) {
			$target = $response->headers->get('Location');
		}
		elseif (method_exists($response, 'get_headers')) {
			$headers = array_change_key_case((array)$response->get_headers(), CASE_LOWER);
			$target  = $headers['location'] ?? null;
		}

		if (!is_string($target) || $target === '') {
			return false;
		}

		$current = (is_object($request) && method_exists($request, 'getRequestUri'))
			? (string)$request->getRequestUri()
			: (string)($_SERVER['REQUEST_URI'] ?? '');

		return $this->urlCompareKey($target) === $this->urlCompareKey($current);
	}

	/**
	 * Khoá so sánh URL: path (bỏ "/" cuối) + query đã sắp xếp.
	 */
	protected function urlCompareKey(string $url): string {
		$parts = parse_url($url);

		if ($parts === false) {
			return $url;
		}

		$path = rtrim($parts['path'] ?? '', '/');
		parse_str($parts['query'] ?? '', $query);
		ksort($query);

		return ($path === '' ? '/' : $path) . '?' . http_build_query($query);
	}

	/**
	 * Đối số cho $response->send(): [false] nếu send() có tham số $flush (Symfony 6.4+),
	 * ngược lại [] (Symfony cũ / Lite Response).
	 */
	protected function responseSendArgs($response): array {
		try {
			$parameters = (new \ReflectionMethod($response, 'send'))->getParameters();
		}
		catch (\Throwable $e) {
			return [];
		}

		return (isset($parameters[0]) && $parameters[0]->getName() === 'flush') ? [false] : [];
	}

	/*
	 * =====================================================================
	 * HTTP / REQUEST HELPERS
	 * =====================================================================
	 */

	/**
	 * Lấy HTTP status code từ response bất kỳ (duck-typing).
	 * Hỗ trợ Symfony / Illuminate / Lite Response, \WP_HTTP_Response, \WP_REST_Response.
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
	 * Container / application của plugin hiện tại.
	 *
	 * Kiểm tra is_object trước: method_exists(null, ...) ném TypeError ở PHP 8.
	 */
	protected function currentApp() {
		return (is_object($this->funcs) && method_exists($this->funcs, '_getApplication'))
			? $this->funcs->_getApplication()
			: null;
	}

	/**
	 * Lấy request hiện tại: $this->request → container → Request::capture().
	 *
	 * Luôn ưu tiên $this->request: đây là request đã gắn session của plugin,
	 * nơi chứa flash data (errors, old input) của redirect()->withErrors().
	 */
	protected function resolveCurrentRequest() {
		if (!empty($this->request)) {
			return $this->request;
		}

		$app = $this->currentApp();

		if ($app && method_exists($app, 'bound') && method_exists($app, 'make') && $app->bound('request')) {
			return $app->make('request');
		}

		if (class_exists(Request::class) && method_exists(Request::class, 'capture')) {
			return Request::capture();
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
	 * Lưu / đọc 1 giá trị trong vòng đời 1 request.
	 *
	 * - requestStash($request, $key)          → đọc (null nếu chưa có).
	 * - requestStash($request, $key, $value)  → ghi; $value = null là xoá.
	 *
	 * Dùng WeakMap theo request object: mỗi request có ngăn nhớ riêng, tự xoá khi
	 * request bị giải phóng (spl_object_id có thể bị tái sử dụng cho request mới →
	 * dùng nhầm dữ liệu của request cũ). Request không phải object → bộ nhớ tĩnh.
	 *
	 * KHÔNG ghi vào $request->attributes: response bị chặn giữ tham chiếu ngược tới
	 * request / session → attributes phình to, Debugbar / log dump request sẽ hết bộ nhớ;
	 * đồng thời attributes còn được dùng để bind param (requestBag('attributes')).
	 */
	protected function requestStash($request, string $key, ...$value) {
		static $store = null, $global = [];
		$store ??= new \WeakMap();

		$write = count($value) > 0;
		$value = $value[0] ?? null;

		if (!is_object($request)) {
			if (!$write) {
				return $global[$key] ?? null;
			}
			if ($value === null) {
				unset($global[$key]);
			}
			else {
				$global[$key] = $value;
			}
			return $value;
		}

		$memo = $store[$request] ?? [];

		if (!$write) {
			return $memo[$key] ?? null;
		}

		if ($value === null) {
			unset($memo[$key]);
		}
		else {
			$memo[$key] = $value;
		}
		$store[$request] = $memo;

		return $value;
	}

	/**
	 * Prefix "{app}" của plugin hiện tại = Funcs::_getAppShortName().
	 *
	 * Dùng cho tên action / filter VÀ key lưu dữ liệu theo request (requestStash()).
	 * Mỗi plugin tạo bằng composer create-project từ WPSP / WPSPLITE có app short name
	 * riêng → hook và key không đụng nhau giữa các plugin.
	 *
	 * Vì sao key cũng cần prefix: requestStash() dùng static WeakMap trong trait. Class
	 * dùng trait (BaseInstances) chỉ nạp 1 lần cho cả process dù nhiều plugin cùng require
	 * core → các plugin dùng CHUNG biến static đó. Thiếu prefix, plugin này sẽ đọc nhầm
	 * kết quả throttle / response bị chặn của plugin kia trên cùng request.
	 *
	 * Không có funcs (hiếm) → defaultHookPrefix() của từng bản.
	 */
	protected function hookPrefix(): string {
		return (is_object($this->funcs) && method_exists($this->funcs, '_getAppShortName'))
			? $this->funcs->_getAppShortName()
			: $this->defaultHookPrefix();
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
	 * @param mixed $callback        Callback cần chuẩn hóa.
	 * @param array $constructParams Các tham số truyền vào constructor của class.
	 *
	 * @return callable Callback đã được chuẩn hóa và sẵn sàng để thực thi.
	 *
	 * @throws \RuntimeException Khi callback không hợp lệ.
	 */
	protected function prepareRouteCallback($callback, $constructParams = []) {
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
	 * Sử dụng hàm này khi cần gọi "Callback Dependencies Injection" trong các class callback của Route.\
	 * Ví dụ:
	 * - Route::get('/my-page', [MyClass::class, 'myMethod']);
	 *
	 * Lúc này myMethod được gọi với DI tự động.\
	 * Nhưng trong myMethod chúng ta lại muốn gọi tiếp method khác, ví dụ: $this->secondMethod()
	 * Nếu không sử dụng hàm này, thì secondMethod() sẽ không được "Dependencies Injection".
	 *
	 * Closure trả về nhận các đối số WordPress truyền vào (hook args) và map chúng
	 * vào signature của method. Params được build LÚC CLOSURE CHẠY (lazy) để lấy
	 * đúng request / route tại thời điểm WordPress thực sự gọi callback.
	 *
	 * $class có thể là instance hoặc tên class (tự khởi tạo qua container).
	 */
	protected function prepareCallbackFunction($method, $path, $fullPath, $class = null, $args = []): \Closure {
		return function(...$wpParams) use ($method, $path, $fullPath, $class, $args) {
			$requestPath = $this->request->getRequestUri();

			// Nếu truyền tên class thay vì instance, tự khởi tạo class với DI.
			$targetInstance = $class;
			if (is_string($class) && class_exists($class)) {
				$targetInstance = $this->containerMake($class);
			}

			// build callback [instance, method]
			$callback = [$targetInstance ?? $this, $method];

			if (!isset($args['route'])) {
				$args['route'] = $this->extraParams['route'] ?? null;
			}

			// build params
			$callParams = $this->buildParametersForCallable($callback, $path, $fullPath, $requestPath, $args, $wpParams);

			// call
			return $this->resolveAndCall($callback, $callParams);
		};
	}

	/*
	 * =====================================================================
	 * PARAM BINDING
	 * =====================================================================
	 */

	/**
	 * Build params for callable (route callback).\
	 * Hàm này xử lý rất nhiều trường hợp params của method, bao gồm:
	 * - Detect callback type
	 * - Reflection callback signature (có cache)
	 * - WordPress hook args (add_action / add_filter) theo signature
	 * - Regex route matching / Ajax route compatibility
	 * - Fallback param build khi route không match
	 * - Regex capture parsing (named + positional)
	 * - Request source aggregation (attributes, POST, GET)
	 * - Primitive param binding
	 * - Class param binding (Request, __wpspConstruct, model binding nếu có)
	 * - Metadata injection
	 * - Request → route parameter bridging
	 *
	 * Thứ tự ưu tiên giá trị cho param PRIMITIVE (khi route match):
	 *   0) WP hook args → 1) named capture (?P<name>) → 2) request attributes
	 *   → 3) POST → 4) query string → 5) positional capture → 6) default value → null
	 *
	 * Param kiểu class: xem resolveClassParam() (khác nhau giữa CORE / LITE).
	 * Param kiểu class không được resolve tại đây → KHÔNG đưa vào $callParams,
	 * container / manual DI tự inject khi gọi.
	 *
	 * Khác biệt có chủ đích giữa 2 nhánh:
	 * - Route KHÔNG match: params < meta < $args (args ghi đè).
	 * - Route match      : meta ghi đè; $args chỉ điền vào key chưa có (isset).
	 */
	protected function getCallParams($path, $fullPath, $requestPath, $callbackOrClass, $method = null, $args = [], $wpParams = []) {
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

		// "Actions" / "Filters" không có request path (add_action / add_filter) → luôn coi như đang ở đúng path.
		if (str_ends_with($calledClass, 'Actions') || str_ends_with($calledClass, 'Filters')) {
			$requestPath = $fullPath;
		}

		// Chỉ thực sự bind dữ liệu khi đang truy cập đúng "path" / "fullPath",
		// tránh thực thi code không cần thiết khi đang ở URL khác.
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

				// Hook args vẫn được dùng dù route không khớp.
				if (array_key_exists($name, $hookArgs)) {
					$callParams[$name] = $hookArgs[$name];
					continue;
				}

				if ($className = $this->getClassFromType($param->getType())) {
					// $sources = null: route không khớp → không model binding.
					[$resolved, $value] = $this->resolveClassParam($param, $className, $method, null);
					if ($resolved) {
						$callParams[$name] = $value;
					}
					continue;
				}

				// Primitive → NULL
				$callParams[$name] = null;
			}

			// Thứ tự ghi đè: params < meta < args. Phần dư hook args (variadic) nối vào cuối.
			return array_merge($callParams, $meta, $args, $this->variadicHookArgs($hookArgs));
		}

		/*
		 * ----- Route khớp: bind đầy đủ -----
		 */
		// QUAN TRỌNG: request đã gắn session, không capture request mới.
		$baseRequest = $this->resolveCurrentRequest();

		// Named groups (?P<name>) và positional captures (1..n).
		$named      = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
		$positional = array_values(array_filter($matches, fn($k) => is_int($k) && $k > 0, ARRAY_FILTER_USE_KEY));

		$query = $this->requestBag($baseRequest, 'query');      // GET
		$post  = $this->requestBag($baseRequest, 'request');    // POST
		$attr  = $this->requestBag($baseRequest, 'attributes'); // attributes

		$sources    = ['named' => $named, 'query' => $query, 'post' => $post, 'args' => $args];
		$callParams = [];
		$posIndex   = 0;

		foreach ($parameters as $param) {
			$name = $param->getName();

			// 0) Hook args của WordPress luôn ưu tiên cao nhất, không urldecode.
			if (array_key_exists($name, $hookArgs)) {
				$callParams[$name] = $hookArgs[$name];
				continue;
			}

			// Param kiểu class: Request / __wpspConstruct / model binding, còn lại để container DI.
			if ($className = $this->getClassFromType($param->getType())) {
				[$resolved, $value] = $this->resolveClassParam($param, $className, $method, $sources);
				if ($resolved) {
					$callParams[$name] = $value;
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
			// 7) else null
			else {
				$value = null;
			}

			// Nếu là string, decode URL-encoded values (an toàn)
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

		// Expose toàn bộ named captures (dù method không khai báo param cụ thể)
		// — giúp lấy được $routeParams['endpoint'] trong middleware hoặc log.
		foreach ($named as $k => $v) {
			if (!array_key_exists($k, $callParams)) {
				$callParams[$k] = is_string($v) ? urldecode($v) : $v;
			}
		}

		// Set parameters cho route.
		if (isset($args['route']) && $args['route'] instanceof RouteData) {
			$args['route']->parameters = $this->routeParametersFrom($callParams);
		}

		// Phần dư của hook args cho tham số variadic (key số → nối vào cuối).
		foreach ($this->variadicHookArgs($hookArgs) as $value) {
			$callParams[] = $value;
		}

		return $callParams;
	}

	/**
	 * Tham số lưu vào RouteData::$parameters (đọc lại bằng $request->route('id'),
	 * Debugbar, log...).
	 *
	 * Bỏ 'route' và object Request: Request giữ session, route resolver, container...
	 * Dump nó (print_r / var_export trong Debugbar collector) sẽ duyệt cả đồ thị object
	 * của plugin → chuỗi hàng GB → "Allowed memory size exhausted", trang load mãi.
	 */
	protected function routeParametersFrom(array $callParams): array {
		unset($callParams['route']);

		return array_filter($callParams, fn($value) => !($value instanceof Request));
	}

	/**
	 * Resolve 1 param kiểu class trong getCallParams().
	 *
	 * Thứ tự:
	 * 1) Request → request hiện tại (xem resolveRequestForType()).
	 * 2) Eloquent Model → model binding, chỉ khi route khớp ($sources !== null)
	 *    và chỉ ở bản có ORM (xem resolveModelParam()).
	 * 3) "__wpspConstruct" → tự khởi tạo class (xem injectConstructProperty()).
	 *
	 * Trong "__wpspConstruct", giá trị resolve được ở bước 1 / 2 cũng được gán
	 * thành property cùng tên, giống bước 3.
	 *
	 * @param array|null $sources ['named', 'query', 'post', 'args'] khi route khớp, null khi không khớp.
	 *
	 * @return array{0: bool, 1: mixed} [đã resolve?, giá trị]. Chưa resolve → container / manual DI tự inject.
	 */
	protected function resolveClassParam(\ReflectionParameter $param, string $className, $method, ?array $sources): array {
		$name        = $param->getName();
		$isConstruct = $method === '__wpspConstruct';

		// 1) Request
		$request = $this->resolveRequestForType($className);
		if ($request !== null) {
			if ($isConstruct) {
				$this->assignConstructProperty($name, $request, $className);
			}
			return [true, $request];
		}

		// 2) Model binding
		if ($sources !== null) {
			[$isModel, $model] = $this->resolveModelParam($param, $className, $sources);
			if ($isModel) {
				if ($isConstruct && is_object($model)) {
					$this->assignConstructProperty($name, $model, $className);
				}
				return [true, $model];
			}
		}

		// 3) __wpspConstruct
		if ($isConstruct && class_exists($className)) {
			$instance = $this->injectConstructProperty($name, $className);
			if ($instance !== null) {
				return [true, $instance];
			}
		}

		return [false, null];
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

		$this->assignConstructProperty($name, $instance, $className);

		return $instance;
	}

	/**
	 * Gán giá trị thành property trong __wpspConstruct.
	 * Gán lỗi = lỗi khai báo của dev → WP_DEBUG thì ném lại, ngược lại log.
	 */
	protected function assignConstructProperty(string $name, $value, string $className): void {
		try {
			$this->assignInjectedProperty($name, $value);
		}
		catch (\Throwable $e) {
			$this->handleInjectionError($e, $className, $name, true);
		}
	}

	/**
	 * Gán property được inject, hỗ trợ private / protected / readonly
	 * khai báo ở bất kỳ class nào trong hierarchy.
	 *
	 * Cách làm: tìm class khai báo property (đi ngược lên parent), rồi bind
	 * closure vào scope của class đó để có quyền ghi private/readonly.
	 * Property static → bỏ qua. Không khai báo → dynamic property.
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
	 * - Còn lại             → log (logger 'log' của container nếu có, ngược lại error_log)
	 *                         + action "{app}_injection_failed".
	 */
	protected function handleInjectionError(\Throwable $e, string $className, string $name, bool $rethrow): void {
		$message = sprintf(
			'[%s] Can not inject [%s] into property $%s: %s',
			static::class, $className, $name, $e->getMessage()
		);

		if ($rethrow && defined('WP_DEBUG') && WP_DEBUG) {
			throw new \LogicException($message, 0, $e);
		}

		$logged = false;
		try {
			$app = $this->currentApp();

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
			do_action($this->hookPrefix() . '_injection_failed', $className, $name, $e, $this);
		}
	}

	/*
	 * =====================================================================
	 * WORDPRESS HOOK ARGS
	 * =====================================================================
	 */

	/**
	 * Map đối số WordPress hook (add_action / add_filter) vào signature theo thứ tự.
	 *
	 * - Param không type / type builtin → nhận đối số hiện tại.
	 * - Param type class → chỉ nhận nếu đối số là instance của class đó (vd: \WP_Post $post),
	 *   ngược lại để DI (vd: TestService $service, Request $request) và KHÔNG tiêu thụ đối số.
	 * - Param nullable nhận được null từ WP → nhận null.
	 * - Param variadic → nhận toàn bộ phần còn lại (key số).
	 *
	 * Nhờ vậy cả 2 cách viết đều đúng:
	 * - updatePost($post_id, $post, $update, Request $request, TestService $testService)
	 * - updatePost(TestService $testService, $post_id, \WP_Post $post, $update, Request $request)
	 *
	 * do_action() gọi KHÔNG có đối số (hook trang admin của add_menu_page(),
	 * wp_ajax_*, template_redirect...) vẫn truyền '' cho callback. '' đơn lẻ này
	 * được coi là "không có hook args", TRỪ route Filters
	 * (apply_filters('x', '') là giá trị thật cần giữ).
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

		if ($this->isWpEmptyHookPlaceholder($wpParams)) {
			return [];
		}

		$total  = count($wpParams);
		$mapped = [];
		$i      = 0;

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
	 * [''] là placeholder WordPress tự thêm khi do_action() không có đối số
	 * (WP_Hook: `if (empty($arg)) $arg[] = '';`). Không áp dụng cho route Filters.
	 */
	protected function isWpEmptyHookPlaceholder(array $wpParams): bool {
		return count($wpParams) === 1
			&& $wpParams[0] === ''
			&& !str_ends_with(static::class, 'Filters');
	}

	/**
	 * Phần hook args dành cho tham số variadic (các key dạng số).
	 */
	protected function variadicHookArgs(array $hookArgs): array {
		return array_values(array_filter($hookArgs, 'is_int', ARRAY_FILTER_USE_KEY));
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

	/*
	 * =====================================================================
	 * ROUTE / REFLECTION
	 * =====================================================================
	 */

	/**
	 * Đưa route hiện tại vào request để callback có thể gọi $request->route('id').\
	 * Ví dụ:
	 * - /wpsp/posts/{id}
	 *
	 * Trong callback có thể gọi:
	 *
	 * protected function posts(Request \$request) {\
	 * ㅤ\$id = $request->route('id');\
	 * }
	 */
	protected function setRouteResolver() {
		$route = $this->funcs->_getRouteManager()->currentRoute() ?? null;

		if (!$route || !in_array($route->type, ['AdminPages', 'Apis', 'Ajaxs', 'FrontPages', 'RewriteFrontPages'])) {
			return;
		}

		if (!method_exists($this->request, 'setRouteResolver')) {
			return;
		}

		if (strtoupper($this->request->getMethod()) !== strtoupper($route->method)) {
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

	/**
	 * Lấy tên class từ một ReflectionType.
	 *
	 * Chỉ các kiểu đối tượng (class/interface) mới được trả về. Các kiểu
	 * dựng sẵn của PHP như string, int, bool, float, array... sẽ bị bỏ qua.
	 * Đối với Union Type, hàm sẽ trả về class đầu tiên tìm thấy.
	 *
	 * Ví dụ:
	 * - LoggerInterface      => "LoggerInterface"
	 * - string               => null
	 * - Logger|NullLogger    => "Logger"
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
	protected function resolveCallback($callback, $callParams = []) {
		return $this->resolveAndCall($callback, $callParams, false);
	}

	/**
	 * Call callback với Dependency Injection.\
	 * "callParams" có thể được chuẩn bị bằng method getCallParams().
	 *
	 * - $call = true : gọi ngay, trả kết quả callback.
	 * - $call = false: trả Closure để WordPress gọi sau (add_menu_page, add_action...).
	 *   Closure này nhận hook args của WordPress và map vào signature.
	 *
	 * Ngữ cảnh container được gắn ngay (giữ hành vi cũ) VÀ gắn lại tại thời điểm
	 * callback thực sự chạy, vì giữa 2 thời điểm đó plugin khác có thể đã chạy.
	 */
	protected function resolveAndCall($callback, $callParams = [], $call = true, $method = null) {
		// Set route resolver.
//		$this->setRouteResolver();

		$this->bindContainerContext();

		if (!$call) {
			return function(...$wpParams) use ($callback, $callParams) {
				return $this->invokeResolved($callback, $this->withHookArgs($callback, (array)$callParams, $wpParams));
			};
		}

		return $this->invokeResolved($callback, (array)$callParams);
	}

	/**
	 * Gắn ngữ cảnh container, kiểm tra controller middleware (Policy...) rồi gọi callback với DI.
	 */
	protected function invokeResolved($callback, array $callParams) {
		$this->bindContainerContext();

		$this->authorizeControllerAction($callback, $callParams);

		return $this->callWithContainer($callback, $this->paramsForContainerCall($callback, $callParams));
	}

	/**
	 * Chạy controller middleware của page class ngay trước ACTION của route.
	 *
	 * Controller middleware được khai báo trong page class, thường ở customProperties():
	 * - $this->authorizeResource(UsersModel::class, 'user');   (AuthorizesRequests)
	 *       index → can:viewAny,UsersModel   show → can:view,user      create / store → can:create,UsersModel
	 *       edit / update → can:update,user  destroy → can:delete,user
	 * - $this->middleware('can:update,user')->only('edit', 'update');   (InstanceMiddlewareTrait)
	 * - implements HasMiddleware + public static function middleware(): array   (Laravel 11+)
	 *
	 * Chỉ áp dụng khi method đang gọi CHÍNH LÀ action của route (RouteData::$callback[1]),
	 * vd: 'show', 'update', hoặc 'index' khi trang admin render. Các method vòng đời
	 * (__wpspConstruct, customProperties, init, matchedCurrentAccess...) không bị chặn.
	 *
	 * Tham số model ('user') lấy từ $callParams đã bind (model binding của action).
	 * Bị từ chối → gửi response middleware (nếu có) hoặc dừng với 403 + message của Policy.
	 */
	protected function authorizeControllerAction($callback, array $callParams): void {
		if (!is_array($callback) || !is_object($callback[0] ?? null) || !is_string($callback[1] ?? null)) {
			return;
		}

		[$instance, $method] = $callback;
		$route = $callParams['route'] ?? null;

		if (!$route instanceof RouteData || !is_array($route->callback) || ($route->callback[1] ?? null) !== $method) {
			return;
		}

		$middlewares = $this->instanceMiddlewareFor($instance, $method);

		if (empty($middlewares)) {
			return;
		}

		$request = $this->resolveCurrentRequest();

		// 1 block AND: mọi controller middleware áp cho action đều phải PASS.
		if ($this->isPassedMiddleware([$middlewares], $request, ['route' => $route, 'parameters' => $callParams])) {
			return;
		}

		if ($this->sendBlockedMiddlewareResponse($request)) {
			exit;
		}

		$this->abortWithStatus(
			$this->lastAuthorizationMessage($request) ?? 'This action is unauthorized.',
			403,
			'403 - Forbidden'
		);
	}

	/**
	 * Danh sách controller middleware của $instance áp cho $method.
	 *
	 * - getMiddleware(): [['middleware' => ..., 'options' => ['only' => [...], 'except' => [...]]], ...]
	 *   (Illuminate\Routing\Controller / InstanceMiddlewareTrait).
	 * - HasMiddleware: static middleware() trả Middleware (->only() / ->except()), string hoặc Closure.
	 */
	protected function instanceMiddlewareFor($instance, string $method): array {
		$result = [];

		if (method_exists($instance, 'getMiddleware')) {
			foreach ((array)$instance->getMiddleware() as $entry) {
				if (is_array($entry) && array_key_exists('middleware', $entry)
					&& $this->middlewareAppliesTo((array)($entry['options'] ?? []), $method)) {
					$result[] = $entry['middleware'];
				}
			}
		}

		if (is_a($instance, 'Illuminate\\Routing\\Controllers\\HasMiddleware')) {
			foreach ((array)$instance::middleware() as $middleware) {
				if (is_object($middleware) && !$middleware instanceof \Closure && property_exists($middleware, 'middleware')) {
					$options = array_filter(['only' => $middleware->only ?? null, 'except' => $middleware->except ?? null], fn($v) => $v !== null);
					if ($this->middlewareAppliesTo($options, $method)) {
						$result[] = $middleware->middleware;
					}
				}
				else {
					$result[] = $middleware;
				}
			}
		}

		return $result;
	}

	/**
	 * Kiểm tra options only / except của controller middleware.
	 */
	protected function middlewareAppliesTo(array $options, string $method): bool {
		if (isset($options['only']) && !in_array($method, (array)$options['only'], true)) {
			return false;
		}

		if (isset($options['except']) && in_array($method, (array)$options['except'], true)) {
			return false;
		}

		return true;
	}

	/**
	 * Container kiểu Laravel (BoundMethod) nối MỌI param thừa (array_values) vào cuối
	 * danh sách đối số. Với method thường, PHP bỏ qua đối số thừa nên vô hại. Nhưng
	 * với method có tham số variadic (...$rest), $rest sẽ nhận cả path, full_path, route...
	 *
	 * Chỉ với callback CÓ variadic: giữ key là tên param của callback và key số
	 * (hook args cho variadic), bỏ các key chuỗi thừa. Callback khác giữ nguyên.
	 */
	protected function paramsForContainerCall($callback, array $callParams): array {
		try {
			$parameters = $this->reflectCallable($callback)->getParameters();
		}
		catch (\Throwable $e) {
			return $callParams;
		}

		$hasVariadic = false;
		$names       = [];

		foreach ($parameters as $param) {
			$names[$param->getName()] = true;
			if ($param->isVariadic()) {
				$hasVariadic = true;
			}
		}

		if (!$hasVariadic) {
			return $callParams;
		}

		return array_filter($callParams, fn($k) => is_int($k) || isset($names[$k]), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * Trả về callback với Dependency Injection.\
	 * Tự động hoàn toàn.
	 */
	protected function autoResolveCallback($path, $fullPath, $requestPath, $callbackOrClass, $method = null, $args = []) {
		return $this->autoResolveAndCall($path, $fullPath, $requestPath, $callbackOrClass, $method, $args, false);
	}

	/**
	 * Gọi callback với Dependency Injection.\
	 * Tự động hoàn toàn.
	 */
	protected function autoResolveAndCall($path, $fullPath, $requestPath, $callbackOrClass, $method = null, $args = [], $call = true) {
		$class  = is_array($callbackOrClass) ? $callbackOrClass[0] : $callbackOrClass;
		$method = $method ?? (is_array($callbackOrClass) ? ($callbackOrClass[1] ?? null) : null);
		$method = $method ?? '__instanceConstruct';

		if ($class && $method && method_exists($class, $method)) {
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
	protected function normalizeCallback($callback) {
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
	protected function buildParametersForCallable($callback, $path, $fullPath, $requestPath, $args = [], $wpParams = []) {
		[$class, $method] = $this->normalizeCallback($callback);

		// Closure: normalizeCallback trả [null, Closure] → reflect chính Closure.
		if ($class === null) {
			return $this->getCallParams($path, $fullPath, $requestPath, $method, null, $args, $wpParams);
		}

		return $this->getCallParams($path, $fullPath, $requestPath, $class, $method, $args, $wpParams);
	}

	/*
	 * =====================================================================
	 * RIÊNG CỦA BẢN LITE (PHP thuần + WordPress core)
	 * =====================================================================
	 *
	 * Cùng tên, cùng vai trò, cùng thứ tự với section tương ứng của bản CORE.
	 */

	/**
	 * Response mặc định mà $next trả về cho middleware.
	 */
	protected function makeNextResponse() {
		return new \WP_HTTP_Response('OK', 200);
	}

	/**
	 * Khởi tạo class với DI constructor.
	 * Container Lite có make() thì dùng, ngược lại manual DI (manualMakeClass()).
	 */
	protected function containerMake(string $class) {
		$app = $this->currentApp();

		return ($app && method_exists($app, 'make'))
			? $app->make($class)
			: $this->manualMakeClass($class);
	}

	/**
	 * Gọi callback với DI (theo tên param, type-hint, default value).
	 * Container Lite có call() thì dùng, ngược lại manual DI (manualResolveAndCall()).
	 */
	protected function callWithContainer($callback, array $params) {
		$app = $this->currentApp();

		return ($app && method_exists($app, 'call'))
			? $app->call($callback, $params)
			: $this->manualResolveAndCall($callback, $params);
	}

	/**
	 * Đồng bộ Lite Facade về container của plugin hiện tại.
	 *
	 * Lite không có Container::setInstance() / Model resolver toàn cục. Lite Facade
	 * lấy root qua $this->funcs->_getApplication() nên vốn đã tách theo plugin;
	 * bước này chỉ là lớp bảo vệ cho Facade dùng state static.
	 *
	 * Chỉ ghi khi khác, và xoá cache resolved instance khi đổi container
	 * (nếu Lite Facade có các method tương ứng).
	 */
	protected function bindContainerContext(): void {
		$container = $this->currentApp();

		if (!$container) {
			return;
		}

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
	 * Nếu $className là Lite Request (kể cả class con) thì trả về request hiện tại; ngược lại null.
	 *
	 * - Type là class cha của request hiện tại → trả về chính instance đó.
	 * - Type là class con → createFrom() (nếu có) để chép dữ liệu sang, cache theo request + class.
	 *
	 * Cache dùng WeakMap theo request object: spl_object_id() được tái sử dụng sau khi
	 * request cũ bị giải phóng, nên request mới có thể nhận nhầm bản copy của request cũ.
	 */
	protected function resolveRequestForType(string $className) {
		if (!is_a($className, Request::class, true)) {
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

		static $copies = null;
		$copies ??= new \WeakMap();

		$perRequest = $copies[$current] ?? [];

		if (!isset($perRequest[$className])) {
			try {
				$perRequest[$className] = $className::createFrom($current);
			}
			catch (\Throwable $e) {
				return null;
			}
			$copies[$current] = $perRequest;
		}

		return $perRequest[$className];
	}

	/**
	 * Lite không có ORM → không có model binding.
	 *
	 * @return array{0: bool, 1: mixed} Luôn [false, null].
	 */
	protected function resolveModelParam(\ReflectionParameter $param, string $className, array $sources): array {
		return [false, null];
	}

	/**
	 * Hỏi Gate của Lite (binding 'gate') cho 'can:' middleware, theo duck-typing:
	 * inspect() (có message) → allows().
	 *
	 * Không có Gate → TỪ CHỐI (an toàn hơn cho qua) và báo dev bằng _doing_it_wrong().
	 *
	 * @return array{0: bool, 1: ?string} [được phép?, message]
	 */
	protected function authorizeAbility(string $ability, array $arguments, $request): array {
		$app  = $this->currentApp();
		$gate = null;

		try {
			if ($app && method_exists($app, 'bound') && method_exists($app, 'make') && $app->bound('gate')) {
				$gate = $app->make('gate');
			}

			if ($gate && method_exists($gate, 'inspect')) {
				$response = $gate->inspect($ability, $arguments);
				return [
					(bool)(method_exists($response, 'allowed') ? $response->allowed() : $response),
					method_exists($response, 'message') ? $response->message() : null,
				];
			}

			if ($gate && method_exists($gate, 'allows')) {
				return [(bool)$gate->allows($ability, $arguments), null];
			}
		}
		catch (\Throwable $e) {
			return $this->handleAuthorizationError($e, $ability, $arguments);
		}

		if (function_exists('_doing_it_wrong')) {
			_doing_it_wrong('can:' . esc_html($ability), 'No Gate (binding "gate") is defined for permission checks. Route has been denied.', '');
		}

		return [false, null];
	}

	/**
	 * Chẩn đoán vì sao Gate từ chối (chỉ dùng khi WP_DEBUG): đối số và Gate có hay không.
	 */
	protected function describeAuthorizationContext(string $ability, array $arguments): string {
		$app     = $this->currentApp();
		$hasGate = $app && method_exists($app, 'bound') && $app->bound('gate');
		$args    = implode(', ', array_map([$this, 'describeAuthorizationArgument'], $arguments));

		if (!$hasGate) {
			return 'Arguments: [' . $args . '] | Gate: no binding "gate"';
		}

		$gate = $app->make('gate');

		// Gate của WPSPCORELITE (WPSPCORELITE\App\Auth\Access\Gate) tự mô tả sẽ dùng Policy / define / capability nào.
		if (method_exists($gate, 'explain')) {
			try {
				return 'Arguments: [' . $args . '] | ' . $gate->explain($ability, $arguments);
			}
			catch (\Throwable) {
			}
		}

		return 'Arguments: [' . $args . '] | Gate: ' . get_debug_type($gate);
	}

	/**
	 * Lite ThrottleRequests với RateLimiter của plugin.
	 * Báo cho dev khi dùng named limiter chưa khai báo.
	 */
	protected function makeThrottleMiddleware(array $parameters) {
		$limiter = $this->throttleRateLimiter($parameters);

		$this->warnUnknownNamedLimiter($limiter, $parameters);

		return new ThrottleRequests($limiter);
	}

	/**
	 * RateLimiter cho ThrottleRequests: binding 'rate.limiter' của Application (chứa named limiters
	 * khai báo trong AppServiceProvider). Không có binding → tạo từ cache của plugin.
	 * $parameters: tham số throttle (giữ cùng chữ ký với bản CORE, bản LITE không cần dùng).
	 */
	protected function throttleRateLimiter(array $parameters = []): \WPSPCORELITE\App\Cache\RateLimiter {
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
	 * Throttle vượt giới hạn / lỗi → dừng request.
	 *
	 * - ThrottleRequestsException → 429 (xem respondTooManyRequests()).
	 * - Lỗi khác → 500 (message chỉ hiện khi WP_DEBUG).
	 */
	protected function respondThrottleFailure(\Throwable $e): void {
		if ($e instanceof ThrottleRequestsException) {
			$this->respondTooManyRequests($e);
		}

		$this->abortWithStatus($this->safeErrorMessage($e), 500, '500 - Internal Server Error');
	}

	/**
	 * Dừng request với status code: JSON nếu client muốn JSON, ngược lại wp_die().\
	 * Luôn dừng hẳn để không có output nào bị nối thêm sau response lỗi.
	 */
	protected function abortWithStatus(string $message, int $status, string $title, array $headers = []): void {
		if ($this->funcs->_wantsJson()) {
			$this->sendHeaders($headers);
			wp_send_json($this->funcs->_response(false, $message, $status), $status);
			exit;
		}

		$this->sendHeaders($headers);

		wp_die('<h1>' . $title . '</h1><p>' . $message . '</p>', $title, [
			'back_link' => true,
			'response'  => $status,
		]);

		exit;
	}

	/**
	 * Prefix dự phòng khi không có funcs (xem hookPrefix()).
	 */
	protected function defaultHookPrefix(): string {
		return 'wpsp_lite';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Chỉ có ở bản LITE
	 * ---------------------------------------------------------------------
	 */

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
				sprintf('Rate limiter [%s] not yet declared. Call RateLimiter::for(\'%s\', ...) in AppServiceProvider::boot().', esc_html($name), esc_html($name)),
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
	protected function respondTooManyRequests(ThrottleRequestsException $e): void {
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

		$this->abortWithStatus($message, 429, '429 - Too Many Requests', $headers);
	}

	/**
	 * Manual DI dựa trên Reflection (khi container không có call()).
	 *
	 * Thứ tự cho mỗi param: variadic (key số) → có sẵn trong $callParams
	 * → Request → instance cùng type trong $callParams → tự make class
	 * → default value → null.
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
	 * ---------------------------------------------------------------------
	 * Tương thích ngược (bản LITE cũ). Plugin con có thể đang gọi các method này.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * @deprecated Dùng requestStash().
	 */
	protected function requestMemo($request, string $key, ?bool $value = null): ?bool {
		if (func_num_args() >= 3) {
			$this->requestStash($request, $key, $value);
			return $value;
		}

		$cached = $this->requestStash($request, $key);

		return $cached === null ? null : (bool)$cached;
	}

	/**
	 * @deprecated Dùng resolveClassParam().
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
	 * @deprecated Dùng bindContainerContext().
	 */
	protected function syncContainerState($container): void {
		$this->bindContainerContext();
	}

}
