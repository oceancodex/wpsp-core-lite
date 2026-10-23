<?php

namespace WPSPCORELITE\App\Auth\Access;

/**
 * Gate cho WPSPCORELITE: PHP thuần, không cần Illuminate / Symfony.
 * API theo Illuminate\Auth\Access\Gate (Laravel 12) để code viết cho WPSPCORE dùng lại được.
 *
 * Thứ tự kiểm tra một ability:
 * 1. before() callbacks     → trả về khác null thì dừng (vd: admin luôn được phép).
 * 2. Policy                 → đối số đầu là model / tên class có Policy (đăng ký hoặc tự đoán).
 *                             Policy::before() chạy trước method của ability.
 * 3. Gate::define()          → closure, 'Class@method' hoặc [Class::class, 'method'].
 * 4. Capability WordPress    → user_can($user, $ability, ...$ids) (bật mặc định, tắt bằng withCapabilityFallback(false)).
 * 5. after() callbacks       → chỉ thay kết quả khi bước 1-4 trả về null.
 * 6. Filter "{hook_prefix}_gate_result" (khi có hook_prefix).
 *
 * User:
 * - Mặc định là wp_get_current_user(). WP_User chưa đăng nhập (ID = 0) được coi là khách (null).
 * - Khách: chỉ callback / Policy method có tham số đầu cho phép null (?WP_User $user) mới được gọi,
 *   còn lại bị từ chối mà không cần chạy callback (giống Laravel).
 *
 * Đa plugin: không dùng static, mỗi plugin tạo Gate riêng và đăng ký vào container riêng
 * (binding "gate") nên Policy / ability của plugin này không lọt sang plugin khác.
 *
 * RouteTrait (can: middleware, ->can(), controller middleware) gọi Gate qua binding "gate":
 *     inspect($ability, $arguments) → Response (allowed() + message()).
 */
class Gate {

	/**
	 * callable(): mixed - trả về user hiện tại.
	 */
	protected $userResolver;

	/**
	 * ability => Closure.
	 */
	protected array $abilities = [];

	/**
	 * ability => [class, method] cho các ability define bằng 'Class@method' (để kiểm tra khách).
	 */
	protected array $stringCallbacks = [];

	/**
	 * Model class => Policy class | Policy object.
	 */
	protected array $policies = [];

	/**
	 * Policy class => Policy object (mỗi Policy chỉ khởi tạo 1 lần cho mỗi Gate).
	 */
	protected array $policyInstances = [];

	/**
	 * Model class => Policy class đoán được | false (đã đoán nhưng không có).
	 */
	protected array $guessedPolicies = [];

	protected array $beforeCallbacks = [];

	protected array $afterCallbacks = [];

	/**
	 * callable(string $modelClass): string|array - tự quyết định tên Policy.
	 */
	protected $guessPolicyNamesUsingCallback = null;

	/**
	 * callable(string $policyClass): object - khởi tạo Policy (vd: qua container để có DI).
	 */
	protected $policyResolver = null;

	protected ?Response $defaultDenialResponse = null;

	protected bool $capabilityFallback = true;

	/**
	 * Namespace ưu tiên khi đoán Policy, vd: ['WPSPLITE\\App\\Policies'].
	 */
	protected array $policyNamespaces = [];

	/**
	 * Tiền tố hook của plugin (vd: 'wpsp_lite') → filter "{prefix}_gate_result".
	 */
	protected ?string $hookPrefix = null;

	/**
	 * @param callable|null $userResolver Mặc định: wp_get_current_user().
	 * @param array         $options      [
	 *     'hook_prefix'         => 'wpsp_lite',
	 *     'capability_fallback' => true,
	 *     'policy_namespaces'   => ['WPSPLITE\\App\\Policies'],
	 *     'policy_resolver'     => fn(string $class) => $app->make($class),
	 *     'policies'            => [PostsModel::class => PostPolicy::class],
	 * ]
	 */
	public function __construct(?callable $userResolver = null, array $options = []) {
		$this->userResolver = $userResolver ?? static function() {
			return function_exists('wp_get_current_user') ? wp_get_current_user() : null;
		};

		$this->hookPrefix         = $options['hook_prefix'] ?? null;
		$this->capabilityFallback = (bool)($options['capability_fallback'] ?? true);
		$this->policyNamespaces   = array_map(fn($ns) => trim((string)$ns, '\\'), (array)($options['policy_namespaces'] ?? []));
		$this->policyResolver     = $options['policy_resolver'] ?? null;

		foreach ((array)($options['policies'] ?? []) as $class => $policy) {
			$this->policy($class, $policy);
		}
	}

	/*
	 *
	 */

	/**
	 * Ability đã được define chưa (mảng = tất cả đều phải có).
	 */
	public function has(string|\UnitEnum|array $ability): bool {
		foreach (is_array($ability) ? $ability : [$ability] as $item) {
			if (!isset($this->abilities[$this->normalizeAbility($item)])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Định nghĩa ability.
	 *
	 *     $gate->define('publish-post', fn(\WP_User $user, $postId) => user_can($user, 'publish_posts'));
	 *     $gate->define('update-post', 'WPSPLITE\App\Policies\PostPolicy@update');
	 *     $gate->define('update-post', [PostPolicy::class, 'update']);
	 *     $gate->define(Ability::Manage, fn($user) => ...);  // enum
	 */
	public function define(string|\UnitEnum $ability, callable|array|string $callback): static {
		$ability = $this->normalizeAbility($ability);

		// [Class::class, 'method'] → Policy được khởi tạo khi cần (giống 'Class@method').
		if (is_array($callback) && is_string($callback[0] ?? null) && class_exists($callback[0])) {
			$callback = $callback[0] . '@' . ($callback[1] ?? '__invoke');
		}

		if (is_string($callback) && (str_contains($callback, '@') || class_exists($callback))) {
			[$class, $method] = array_pad(explode('@', $callback, 2), 2, '__invoke');

			$this->stringCallbacks[$ability] = [$class, $method];
			$this->abilities[$ability]       = $this->buildAbilityCallback($ability, $class, $method);

			return $this;
		}

		if (!is_callable($callback)) {
			throw new \InvalidArgumentException(sprintf(
				'Callback của ability [%s] phải là callable, chuỗi "Class@method" hoặc [Class::class, \'method\'].', $ability
			));
		}

		unset($this->stringCallbacks[$ability]);
		$this->abilities[$ability] = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);

		return $this;
	}

	/**
	 * Định nghĩa nhóm ability theo Policy: posts.viewAny, posts.view, posts.create, posts.update, posts.delete.
	 *
	 *     $gate->resource('posts', PostPolicy::class);
	 *     $gate->resource('posts', PostPolicy::class, ['publish' => 'publish']);
	 */
	public function resource(string $name, string $class, ?array $abilities = null): static {
		$abilities ??= [
			'viewAny' => 'viewAny',
			'view'    => 'view',
			'create'  => 'create',
			'update'  => 'update',
			'delete'  => 'delete',
		];

		foreach ($abilities as $ability => $method) {
			$this->define($name . '.' . $ability, $class . '@' . $method);
		}

		return $this;
	}

	/**
	 * Đăng ký Policy cho model class (hoặc lớp cha / interface của model).
	 */
	public function policy(string $class, string|object $policy): static {
		$this->policies[ltrim($class, '\\')] = $policy;
		$this->guessedPolicies               = [];

		return $this;
	}

	public function policies(): array {
		return $this->policies;
	}

	/**
	 * Tự quyết định tên Policy cho model, vd:
	 *     $gate->guessPolicyNamesUsing(fn($class) => 'WPSPLITE\\App\\Policies\\' . basename(str_replace('\\', '/', $class)) . 'Policy');
	 */
	public function guessPolicyNamesUsing(callable $callback): static {
		$this->guessPolicyNamesUsingCallback = $callback;
		$this->guessedPolicies               = [];

		return $this;
	}

	/**
	 * Khởi tạo Policy qua container (có DI cho constructor của Policy).
	 */
	public function resolvePoliciesUsing(callable $resolver): static {
		$this->policyResolver  = $resolver;
		$this->policyInstances = [];

		return $this;
	}

	public function before(callable $callback): static {
		$this->beforeCallbacks[] = $callback;
		return $this;
	}

	public function after(callable $callback): static {
		$this->afterCallbacks[] = $callback;
		return $this;
	}

	/**
	 * Response dùng khi bị từ chối mà Policy / callback chỉ trả về false.
	 *     $gate->defaultDenialResponse(Response::denyAsNotFound());
	 */
	public function defaultDenialResponse(Response $response): static {
		$this->defaultDenialResponse = $response;
		return $this;
	}

	/**
	 * Bật / tắt fallback sang capability WordPress (user_can) khi không có Policy / define.
	 */
	public function withCapabilityFallback(bool $enabled = true): static {
		$this->capabilityFallback = $enabled;
		return $this;
	}

	public function setUserResolver(callable $userResolver): static {
		$this->userResolver = $userResolver;
		return $this;
	}

	/**
	 * Gate cho user khác (không ảnh hưởng Gate gốc).
	 *     $gate->forUser(get_user_by('id', 5))->allows('update', $post);
	 */
	public function forUser(mixed $user): static {
		$gate               = clone $this;
		$gate->userResolver = static fn() => $user;

		return $gate;
	}

	public function abilities(): array {
		return $this->abilities;
	}

	/*
	 *
	 */

	public function allows(string|\UnitEnum|iterable $ability, mixed $arguments = []): bool {
		return $this->check($ability, $arguments);
	}

	public function denies(string|\UnitEnum|iterable $ability, mixed $arguments = []): bool {
		return !$this->allows($ability, $arguments);
	}

	/**
	 * Tất cả ability đều được phép.
	 */
	public function check(string|\UnitEnum|iterable $abilities, mixed $arguments = []): bool {
		foreach ($this->wrapAbilities($abilities) as $ability) {
			if (!$this->inspect($ability, $arguments)->allowed()) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ít nhất 1 ability được phép.
	 */
	public function any(string|\UnitEnum|iterable $abilities, mixed $arguments = []): bool {
		foreach ($this->wrapAbilities($abilities) as $ability) {
			if ($this->inspect($ability, $arguments)->allowed()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Không ability nào được phép.
	 */
	public function none(string|\UnitEnum|iterable $abilities, mixed $arguments = []): bool {
		return !$this->any($abilities, $arguments);
	}

	/**
	 * Kiểm tra và ném AuthorizationException nếu bị từ chối.
	 *
	 * @throws AuthorizationException
	 */
	public function authorize(string|\UnitEnum $ability, mixed $arguments = []): Response {
		return $this->inspect($ability, $arguments)->authorize();
	}

	/**
	 * Kết quả đầy đủ (allowed + message + status). RouteTrait gọi hàm này.
	 */
	public function inspect(string|\UnitEnum $ability, mixed $arguments = []): Response {
		try {
			$result = $this->raw($ability, $arguments);

			if ($result instanceof Response) {
				return $result;
			}

			if ($result) {
				return Response::allow();
			}

			return $this->defaultDenialResponse ? clone $this->defaultDenialResponse : Response::deny();
		}
		catch (AuthorizationException $e) {
			return $e->toResponse();
		}
	}

	/**
	 * Kết quả thô (bool / Response / null) sau before → Policy / define / capability → after → filter.
	 */
	public function raw(string|\UnitEnum $ability, mixed $arguments = []): mixed {
		$ability   = $this->normalizeAbility($ability);
		$arguments = is_array($arguments) ? array_values($arguments) : [$arguments];
		$user      = $this->resolveUser();

		$result = $this->callBeforeCallbacks($user, $ability, $arguments);

		if ($result === null) {
			$result = $this->callAuthCallback($user, $ability, $arguments);
		}

		$result = $this->callAfterCallbacks($user, $ability, $arguments, $result);

		if ($this->hookPrefix && function_exists('apply_filters')) {
			$result = apply_filters($this->hookPrefix . '_gate_result', $result, $ability, $arguments, $user, $this);
		}

		return $result;
	}

	/**
	 * Cho phép khi điều kiện đúng, ngược lại ném AuthorizationException.
	 *     $gate->allowIf(fn($user) => $user && $user->ID === $ownerId, 'Không phải chủ sở hữu.');
	 *
	 * @throws AuthorizationException
	 */
	public function allowIf(mixed $condition, ?string $message = null, int|string|null $code = null): Response {
		return $this->authorizeOnDemand($condition, $message, $code, true);
	}

	/**
	 * Từ chối khi điều kiện đúng.
	 *
	 * @throws AuthorizationException
	 */
	public function denyIf(mixed $condition, ?string $message = null, int|string|null $code = null): Response {
		return $this->authorizeOnDemand($condition, $message, $code, false);
	}

	/**
	 * Mô tả Gate sẽ dùng gì để kiểm tra ability (cho WP_DEBUG / chẩn đoán, không chạy Policy).
	 */
	public function explain(string|\UnitEnum $ability, mixed $arguments = []): string {
		$ability   = $this->normalizeAbility($ability);
		$arguments = is_array($arguments) ? array_values($arguments) : [$arguments];
		$user      = $this->resolveUser();
		$who       = 'User: ' . $this->describeUser($user);
		$note      = '';

		$first  = $arguments[0] ?? null;
		$policy = is_object($first) || is_string($first) ? $this->getPolicyFor($first) : null;

		if ($policy) {
			$method = $this->formatAbilityToMethod($ability);

			if (is_callable([$policy, $method])) {
				$guest = $user === null && !$this->methodAllowsGuests($policy, $method)
					? ' — khách (chưa đăng nhập) bị từ chối: tham số đầu của method cần cho phép null (?WP_User $user)'
					: '';

				return $who . ' | Policy: ' . get_class($policy) . '::' . $method . '()' . $guest;
			}

			$note = ' (Policy ' . get_class($policy) . ' không có method ' . $method . '())';
		}
		elseif (is_string($first) && $this->looksLikeClass($first) && class_exists(ltrim($first, '\\'))) {
			$note = ' (chưa có Policy cho ' . ltrim($first, '\\') . ': Gate::policy(' . ltrim($first, '\\') . '::class, XPolicy::class) hoặc config auth.policies)';
		}

		if (isset($this->abilities[$ability])) {
			return $who . " | Gate::define('" . $ability . "')" . $note;
		}

		if ($this->capabilityFallback && $this->toWpUser($user)) {
			return $who . " | Không có Policy / Gate::define → capability WordPress user_can('" . $ability . "')" . $note;
		}

		return $who . " | Không tìm thấy Policy / Gate::define" . ($this->capabilityFallback ? '' : ' (capability fallback đang tắt)') . " cho '" . $ability . "'" . $note;
	}

	/*
	 *
	 */

	/**
	 * Policy cho model / tên class: đăng ký trực tiếp → lớp cha / interface → tự đoán theo namespace.
	 */
	public function getPolicyFor(object|string $class): ?object {
		if (is_object($class)) {
			$class = get_class($class);
		}

		$class = ltrim($class, '\\');

		if (isset($this->policies[$class])) {
			return $this->resolvePolicy($this->policies[$class]);
		}

		// Chuỗi không phải tên class (vd: '5' từ tham số route) → không có Policy, không autoload.
		if (!$this->looksLikeClass($class) || !(class_exists($class) || interface_exists($class))) {
			return null;
		}

		foreach (array_merge(class_parents($class) ?: [], class_implements($class) ?: []) as $parent) {
			if (isset($this->policies[$parent])) {
				return $this->resolvePolicy($this->policies[$parent]);
			}
		}

		if (!array_key_exists($class, $this->guessedPolicies)) {
			$this->guessedPolicies[$class] = $this->findGuessedPolicy($class);
		}

		return $this->guessedPolicies[$class] ? $this->resolvePolicy($this->guessedPolicies[$class]) : null;
	}

	/**
	 * Tên Policy có thể có của model, theo thứ tự ưu tiên. Vd: WPSPLITE\App\Models\UsersModel →
	 * WPSPLITE\App\Policies\UsersModelPolicy, WPSPLITE\App\Policies\UsersPolicy, WPSPLITE\App\Models\Policies\..., ...
	 */
	public function guessPolicyName(string $class): array {
		if ($this->guessPolicyNamesUsingCallback) {
			return array_values(array_filter((array)call_user_func($this->guessPolicyNamesUsingCallback, $class)));
		}

		$pos       = strrpos($class, '\\');
		$namespace = $pos === false ? '' : substr($class, 0, $pos);
		$basename  = $pos === false ? $class : substr($class, $pos + 1);

		$names = [$basename . 'Policy'];
		if (str_ends_with($basename, 'Model') && $basename !== 'Model') {
			$names[] = substr($basename, 0, -5) . 'Policy';
		}

		$namespaces = $this->policyNamespaces;

		$wrapped = '\\' . $namespace . '\\';
		if (str_contains($wrapped, '\\Models\\')) {
			$namespaces[] = trim(str_replace('\\Models\\', '\\Policies\\', $wrapped), '\\');
			$namespaces[] = trim(str_replace('\\Models\\', '\\Models\\Policies\\', $wrapped), '\\');
		}

		// App\Models\Sub\Post → App\Models\Sub\Policies, App\Models\Policies, App\Policies, Policies.
		$segments = $namespace === '' ? [] : explode('\\', $namespace);
		for ($i = count($segments); $i >= 0; $i--) {
			$namespaces[] = trim(implode('\\', array_slice($segments, 0, $i)) . '\\Policies', '\\');
		}

		$candidates = [];
		foreach (array_unique($namespaces) as $ns) {
			foreach ($names as $name) {
				$candidates[] = $ns . '\\' . $name;
			}
		}

		return array_values(array_unique($candidates));
	}

	/**
	 * Policy class đầu tiên (theo guessPolicyName) có tồn tại, hoặc false.
	 */
	protected function findGuessedPolicy(string $class): string|false {
		foreach ($this->guessPolicyName($class) as $candidate) {
			if ($this->looksLikeClass($candidate) && class_exists($candidate)) {
				return $candidate;
			}
		}

		return false;
	}

	/**
	 * Khởi tạo Policy (1 lần cho mỗi Gate).
	 */
	public function resolvePolicy(string|object $policy): object {
		if (is_object($policy)) {
			return $policy;
		}

		if (!isset($this->policyInstances[$policy])) {
			$this->policyInstances[$policy] = $this->policyResolver
				? call_user_func($this->policyResolver, $policy)
				: new $policy();
		}

		return $this->policyInstances[$policy];
	}

	/**
	 * User hiện tại. WP_User chưa đăng nhập (ID = 0), 0, false, '' → null (khách).
	 */
	public function resolveUser(): mixed {
		$user = call_user_func($this->userResolver);

		if ($user instanceof \WP_User) {
			$exists = method_exists($user, 'exists') ? $user->exists() : !empty($user->ID);
			return $exists ? $user : null;
		}

		return $user ?: null;
	}

	/*
	 *
	 */

	protected function callBeforeCallbacks($user, string $ability, array $arguments): mixed {
		foreach ($this->beforeCallbacks as $before) {
			if (!$this->canBeCalledWithUser($user, $before)) {
				continue;
			}

			$result = $before($user, $ability, $arguments);

			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	protected function callAfterCallbacks($user, string $ability, array $arguments, mixed $result): mixed {
		foreach ($this->afterCallbacks as $after) {
			if (!$this->canBeCalledWithUser($user, $after)) {
				continue;
			}

			$afterResult = $after($user, $ability, $result, $arguments);

			$result ??= $afterResult;
		}

		return $result;
	}

	protected function callAuthCallback($user, string $ability, array $arguments): mixed {
		$callback = $this->resolveAuthCallback($user, $ability, $arguments);

		return $callback($user, ...$arguments);
	}

	/**
	 * Chọn callback: Policy → Gate::define → capability WordPress → luôn null (từ chối).
	 */
	protected function resolveAuthCallback($user, string $ability, array $arguments): callable {
		$first = $arguments[0] ?? null;

		if ((is_object($first) || is_string($first))
			&& ($policy = $this->getPolicyFor($first))
			&& ($callback = $this->resolvePolicyCallback($user, $ability, $arguments, $policy))) {
			return $callback;
		}

		if (isset($this->stringCallbacks[$ability])) {
			[$class, $method] = $this->stringCallbacks[$ability];

			if ($this->canBeCalledWithUser($user, $class, $method)) {
				return $this->abilities[$ability];
			}
		}
		elseif (isset($this->abilities[$ability]) && $this->canBeCalledWithUser($user, $this->abilities[$ability])) {
			return $this->abilities[$ability];
		}

		if ($this->capabilityFallback && !isset($this->abilities[$ability])
			&& ($capability = $this->resolveCapabilityCallback($user, $ability, $arguments))) {
			return $capability;
		}

		return static fn() => null;
	}

	/**
	 * Callback gọi Policy::before() rồi Policy::{ability}(). Null khi Policy không có method này.
	 */
	protected function resolvePolicyCallback($user, string $ability, array $arguments, object $policy): ?\Closure {
		$method = $this->formatAbilityToMethod($ability);

		if (!is_callable([$policy, $method])) {
			return null;
		}

		return function() use ($user, $ability, $arguments, $policy, $method) {
			$result = $this->callPolicyBefore($policy, $user, $ability, $arguments);

			if ($result !== null) {
				return $result;
			}

			return $this->callPolicyMethod($policy, $method, $user, $arguments);
		};
	}

	/**
	 * Policy::before($user, $ability, ...$arguments): khác null thì dùng luôn kết quả này.
	 */
	protected function callPolicyBefore(object $policy, $user, string $ability, array $arguments): mixed {
		if (!method_exists($policy, 'before') || !$this->canBeCalledWithUser($user, $policy, 'before')) {
			return null;
		}

		return $policy->before($user, $ability, ...$arguments);
	}

	protected function callPolicyMethod(object $policy, string $method, $user, array $arguments): mixed {
		// Đối số đầu là TÊN CLASS (vd: ->can('create', PostsModel::class)) → bỏ đi, Policy đã biết model của nó.
		// Vì vậy Policy::view($user, $post) gọi bằng tên class sẽ thiếu $post: dùng viewAny / create,
		// hoặc khai báo ?Model $post = null.
		if (isset($arguments[0]) && is_string($arguments[0]) && $this->looksLikeClass($arguments[0]) && class_exists($arguments[0])) {
			array_shift($arguments);
		}

		if (!$this->canBeCalledWithUser($user, $policy, $method)) {
			return null;
		}

		return $policy->{$method}($user, ...$arguments);
	}

	/**
	 * Fallback: capability WordPress. Đối số là model / WP_Post / số → ID (cho meta cap như edit_post).
	 */
	protected function resolveCapabilityCallback($user, string $ability, array $arguments): ?\Closure {
		$wpUser = $this->toWpUser($user);

		if (!$wpUser || !function_exists('user_can')) {
			return null;
		}

		$capabilityArgs = [];
		foreach ($arguments as $argument) {
			if (is_object($argument)) {
				$id = $argument->ID ?? $argument->id ?? null;
				if (is_numeric($id)) {
					$capabilityArgs[] = (int)$id;
				}
			}
			elseif (is_int($argument) || (is_string($argument) && ctype_digit($argument))) {
				$capabilityArgs[] = (int)$argument;
			}
			elseif (is_string($argument) && $this->looksLikeClass($argument) && class_exists($argument)) {
				continue;
			}
			elseif (is_scalar($argument)) {
				$capabilityArgs[] = $argument;
			}
		}

		return static fn() => user_can($wpUser, $ability, ...$capabilityArgs);
	}

	/**
	 * Đổi user của Gate thành WP_User (cho user_can). Model có ID / id → get_userdata().
	 */
	protected function toWpUser($user): ?\WP_User {
		if ($user instanceof \WP_User) {
			return $user;
		}

		$id = is_object($user) ? ($user->ID ?? $user->id ?? null) : $user;

		if (is_numeric($id) && (int)$id > 0 && function_exists('get_userdata')) {
			$wpUser = get_userdata((int)$id);
			return $wpUser instanceof \WP_User ? $wpUser : null;
		}

		return null;
	}

	/**
	 * @throws AuthorizationException
	 */
	protected function authorizeOnDemand(mixed $condition, ?string $message, int|string|null $code, bool $allowWhenResponseIs): Response {
		$user = $this->resolveUser();

		if ($condition instanceof \Closure) {
			$response = $this->canBeCalledWithUser($user, $condition)
				? $condition($user)
				: new Response(false, $message, $code);
		}
		else {
			$response = $condition;
		}

		$response = $response instanceof Response
			? $response
			: new Response((bool)$response === $allowWhenResponseIs, $message, $code);

		return $response->authorize();
	}

	/*
	 *
	 */

	/**
	 * Có user → luôn gọi được. Khách → chỉ khi tham số đầu cho phép null.
	 */
	protected function canBeCalledWithUser($user, $class, ?string $method = null): bool {
		if ($user !== null) {
			return true;
		}

		if ($method !== null) {
			return $this->methodAllowsGuests($class, $method);
		}

		return $this->callbackAllowsGuests($class);
	}

	protected function methodAllowsGuests(object|string $class, string $method): bool {
		try {
			$reflection = new \ReflectionMethod($class, $method);
		}
		catch (\ReflectionException) {
			return false;
		}

		$parameters = $reflection->getParameters();

		return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
	}

	protected function callbackAllowsGuests(callable $callback): bool {
		try {
			$parameters = (new \ReflectionFunction(\Closure::fromCallable($callback)))->getParameters();
		}
		catch (\ReflectionException) {
			return false;
		}

		return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
	}

	/**
	 * ?WP_User $user, WP_User|null $user, $user = null → cho phép khách.
	 */
	protected function parameterAllowsGuests(\ReflectionParameter $parameter): bool {
		return ($parameter->hasType() && $parameter->allowsNull())
			|| ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null);
	}

	/**
	 * 'view-any' → 'viewAny' (method của Policy), các ability khác giữ nguyên.
	 */
	protected function formatAbilityToMethod(string $ability): string {
		if (!str_contains($ability, '-')) {
			return $ability;
		}

		return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $ability))));
	}

	protected function normalizeAbility(string|\UnitEnum $ability): string {
		if ($ability instanceof \BackedEnum) {
			return (string)$ability->value;
		}

		if ($ability instanceof \UnitEnum) {
			return $ability->name;
		}

		return $ability;
	}

	protected function wrapAbilities(string|\UnitEnum|iterable $abilities): array {
		if (is_string($abilities) || $abilities instanceof \UnitEnum) {
			return [$abilities];
		}

		return is_array($abilities) ? $abilities : iterator_to_array($abilities, false);
	}

	protected function buildAbilityCallback(string $ability, string $class, string $method): \Closure {
		return function($user, ...$arguments) use ($ability, $class, $method) {
			$policy = $this->resolvePolicy($class);

			$result = $this->callPolicyBefore($policy, $user, $ability, $arguments);

			if ($result !== null) {
				return $result;
			}

			return $policy->{$method}($user, ...$arguments);
		};
	}

	protected function looksLikeClass(string $value): bool {
		return (bool)preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $value);
	}

	protected function describeUser($user): string {
		if ($user === null) {
			return 'khách (chưa đăng nhập)';
		}

		if (is_object($user)) {
			$id = $user->ID ?? $user->id ?? null;
			return get_class($user) . ($id !== null ? '#' . $id : '');
		}

		return get_debug_type($user);
	}

}
