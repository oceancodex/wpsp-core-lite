<?php

namespace WPSPCORELITE\App\Auth\Access;

use WPSPCORELITE\App\Auth\Access\Gate;
use WPSPCORELITE\App\Auth\Access\Response;

/**
 * AuthorizesRequests — bản LITE (không phụ thuộc Illuminate).
 *
 * Cùng API với Illuminate\Foundation\Auth\Access\AuthorizesRequests (Laravel 12):
 *
 *     class wpsp_lite_tab_users extends BaseAdminPage {
 *         use InstancesTrait, InstanceMiddlewareTrait, AuthorizesRequests;
 *
 *         public function customProperties() {
 *             $this->authorizeResource(UsersModel::class, 'user');
 *         }
 *     }
 *
 * authorizeResource() đăng ký controller middleware 'can:...' (qua InstanceMiddlewareTrait::middleware()):
 *
 *     index           → can:viewAny,{Model}           Policy::viewAny($user)
 *     show            → can:view,{Model},{param}      Policy::view($user, $value)
 *     create, store   → can:create,{Model}            Policy::create($user)
 *     edit, update    → can:update,{Model},{param}    Policy::update($user, $value)
 *     destroy         → can:delete,{Model},{param}    Policy::delete($user, $value)
 *
 * RouteTrait::authorizeControllerAction() chạy các middleware này ngay trước action của route.
 * Bản LITE KHÔNG có model binding: {param} là giá trị thô (vd: '5' từ ?user=5 hoặc (?P<user>\d+)),
 * Policy tự tìm bản ghi.
 *
 * Cần dùng kèm InstanceMiddlewareTrait (cung cấp middleware() / getMiddleware()).
 */
trait AuthorizesRequests {

	/**
	 * Kiểm tra quyền, ném AuthorizationException nếu bị từ chối.
	 *
	 *     $this->authorize('update', [UsersModel::class, $id]);
	 *
	 * @throws \WPSPCORELITE\App\Auth\Access\AuthorizationException
	 */
	public function authorize($ability, $arguments = []): Response {
		[$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);

		return $this->authorizationGate()->authorize($ability, $arguments);
	}

	/**
	 * Kiểm tra quyền cho user khác.
	 *
	 * @throws \WPSPCORELITE\App\Auth\Access\AuthorizationException
	 */
	public function authorizeForUser($user, $ability, $arguments = []): Response {
		[$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);

		return $this->authorizationGate()->forUser($user)->authorize($ability, $arguments);
	}

	/**
	 * Tự áp Policy cho các action resource (giống Laravel 12).
	 *
	 * @param string|string[]      $model     Tên class (vd: UsersModel::class, \WP_Post::class) hoặc mảng nhiều class.
	 * @param string|string[]|null $parameter Tên tham số route / query chứa id (vd: 'user').
	 *                                        Null → snake_case tên class (UsersModel → 'users_model').
	 * @param array                $options   ['except' => [...]] (như Laravel; 'only' bị thay bằng danh sách action).
	 */
	public function authorizeResource($model, $parameter = null, array $options = [], $request = null): void {
		if (!method_exists($this, 'middleware')) {
			throw new \LogicException(sprintf(
				'%s::authorizeResource() cần InstanceMiddlewareTrait (method middleware()).',
				static::class
			));
		}

		$model     = is_array($model) ? implode(',', $model) : $model;
		$parameter = is_array($parameter) ? implode(',', $parameter) : $parameter;
		$parameter = $parameter ?: $this->resourceParameterName($model);

		// Class WordPress không namespace (\WP_Post::class = 'WP_Post'): thêm "\" để can: hiểu là tên class.
		$modelArgument = implode(',', array_map(function($class) {
			$class = trim($class);
			return (!str_contains($class, '\\') && class_exists($class)) ? '\\' . $class : $class;
		}, explode(',', $model)));

		$middleware = [];

		foreach ($this->resourceAbilityMap() as $method => $ability) {
			// Khác Laravel: LITE không có model binding nên action cần đối tượng truyền CẢ tên class lẫn tham số
			// ('can:view,UsersModel,user'). Gate tìm Policy theo tên class, bỏ tên class đi rồi gọi
			// Policy::view($user, '5'). Laravel chỉ truyền tham số vì nó đã là model (Policy tìm theo model).
			$modelName = in_array($method, $this->resourceMethodsWithoutModels(), true)
				? $modelArgument
				: $modelArgument . ',' . $parameter;

			$middleware["can:{$ability},{$modelName}"][] = $method;
		}

		foreach ($middleware as $middlewareName => $methods) {
			$this->middleware($middlewareName, $options)->only($methods);
		}
	}

	/**
	 * Action → ability của Policy (ghi đè để thêm / đổi, vd: 'export' => 'export').
	 */
	protected function resourceAbilityMap(): array {
		return [
			'index'   => 'viewAny',
			'show'    => 'view',
			'create'  => 'create',
			'store'   => 'create',
			'edit'    => 'update',
			'update'  => 'update',
			'destroy' => 'delete',
		];
	}

	/**
	 * Action không cần đối tượng cụ thể → truyền tên class thay vì tham số.
	 */
	protected function resourceMethodsWithoutModels(): array {
		return ['index', 'create', 'store'];
	}

	/*
	 *
	 */

	/**
	 * 'App\Models\UsersModel' → 'users_model' (giống Str::snake(class_basename()) của Laravel).
	 */
	protected function resourceParameterName(string $model): string {
		$first    = trim(explode(',', $model)[0]);
		$basename = basename(str_replace('\\', '/', $first));

		return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
	}

	/**
	 * authorize('update', $id) / authorize($id) (ability = tên method đang gọi, giống Laravel).
	 */
	protected function parseAbilityAndArguments($ability, $arguments): array {
		if (is_string($ability) && !str_contains($ability, '\\')) {
			return [$ability, $arguments];
		}

		if ($ability instanceof \UnitEnum) {
			return [$ability, $arguments];
		}

		$method = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'] ?? '';

		return [$this->normalizeGuessedAbilityName($method), $ability];
	}

	protected function normalizeGuessedAbilityName(string $ability): string {
		$map = $this->resourceAbilityMap();

		return $map[$ability] ?? $ability;
	}

	/**
	 * Gate của plugin hiện tại (binding 'gate' trong Application của plugin).
	 */
	protected function authorizationGate(): Gate {
		$gate = null;

		if (isset($this->funcs) && is_object($this->funcs) && method_exists($this->funcs, '_getApplication')) {
			$gate = $this->funcs->_getApplication('gate');
		}

		if (!$gate instanceof Gate) {
			throw new \LogicException('Không có Gate (binding "gate") trong Application của plugin.');
		}

		return $gate;
	}

}
