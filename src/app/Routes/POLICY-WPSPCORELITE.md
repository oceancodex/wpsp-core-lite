# Policy trong WPSPCORELITE

Cập nhật: 2026-10-23 (Gate bind sẵn trong Application, có facade `Gate`) · Áp dụng cho: WPSPCORELITE (PHP thuần, không Illuminate / Symfony) và các plugin tạo từ WPSPLITE

WPSPCORELITE có Gate riêng viết bằng PHP thuần (`WPSPCORELITE\App\Auth\Access\Gate`). API của nó theo Laravel 12, và có thêm fallback sang capability WordPress. Route được chặn bằng `->can()`, `Route::can()` hoặc `->middleware('can:...')`. WPSPLITE không có Model, nên Policy nhận **id thô từ URL**, và user là `WP_User`.

Cần PHP 8.0 trở lên. Muốn dùng enum làm ability thì cần PHP 8.1.

## Mục lục

1. [Cách hoạt động](#1-cách-hoạt-động)
2. [Cài đặt: các file cần có](#2-cài-đặt-các-file-cần-có)
3. [Gate có sẵn: binding, facade, config](#3-gate-có-sẵn-binding-facade-config)
4. [Chọn cách phân quyền](#4-chọn-cách-phân-quyền)
5. [Cách 1: Capability WordPress](#5-cách-1-capability-wordpress)
6. [Cách 2: Gate::define](#6-cách-2-gatedefine)
7. [Cách 3: Policy](#7-cách-3-policy)
8. [Phân quyền trên route](#8-phân-quyền-trên-route)
9. [Đối số của `can`](#9-đối-số-của-can)
10. [Phân quyền trong controller / page class](#10-phân-quyền-trong-controller--page-class)
11. [Dùng Gate trong code](#11-dùng-gate-trong-code)
12. [before, after, filter](#12-before-after-filter)
13. [Khách (chưa đăng nhập)](#13-khách-chưa-đăng-nhập)
14. [Khi bị từ chối](#14-khi-bị-từ-chối)
15. [Nhiều plugin trong cùng một site](#15-nhiều-plugin-trong-cùng-một-site)
16. [Tham chiếu API](#16-tham-chiếu-api)
17. [Lỗi thường gặp và chẩn đoán](#17-lỗi-thường-gặp-và-chẩn-đoán)

---

## 1. Cách hoạt động

Khi một route có `can:update,\WP_Post,post`, RouteTrait làm lần lượt:

1. Tách chuỗi thành ability `update` và các đối số (`\WP_Post`, `post`).
2. Đổi đối số thành giá trị: `\WP_Post` là tên class, `post` là giá trị tham số route (`'5'`).
3. Lấy Gate từ binding `'gate'` của container plugin, gọi `inspect('update', ['\WP_Post', '5'])`.
4. Gate kiểm tra theo thứ tự:
   1. Các callback `before()`. Trả về khác `null` thì dừng.
   2. Policy, nếu đối số đầu là model hoặc tên class có Policy. `Policy::before()` chạy trước method của ability.
   3. `Gate::define()`.
   4. Capability WordPress: `user_can($user, 'update', ...)`.
   5. Các callback `after()`. Chỉ thay kết quả khi các bước trên trả về `null`.
   6. Filter `{hook_prefix}_gate_result`.
5. Được phép thì chạy action. Bị từ chối thì gửi response riêng của middleware (nếu có), nếu không trả 403 với message.

**So với WPSPCORE:**

| | WPSPCORE | WPSPCORELITE |
| --- | --- | --- |
| Gate | Laravel, có sẵn | `WPSPCORELITE\App\Auth\Access\Gate`, Application bind sẵn (`'gate'`) |
| User | `UsersModel` / `WPUsersModel` | `WP_User` |
| Tham số route | Bind thành Eloquent model | Id thô (chuỗi) |
| Fallback capability WordPress | Có, chỉ cho `can` của route | Có, cả route lẫn `Gate::` |
| `HasMiddleware`, `authorizeResource()` | Có | Không |

`can` hoạt động như nhau trên mọi loại route có gọi `isPassedMiddleware()`:

| Loại route | Khi bị từ chối |
| --- | --- |
| AdminPages | Trang 403 (có lý do khi `WP_DEBUG`) |
| FrontPages | Gửi response riêng của middleware; không có thì để WordPress xử lý tiếp |
| Ajaxs | `wp_send_json` 403 kèm message |

---

## 2. Cài đặt: các file cần có

| Class | Nội dung |
| --- | --- |
| `WPSPCORELITE\App\Auth\Access\Gate` | Gate |
| `WPSPCORELITE\App\Auth\Access\Response` | Kết quả kiểm tra (`allow`, `deny`, `denyAsNotFound`...) |
| `WPSPCORELITE\App\Auth\Access\AuthorizationException` | Ném ra bởi `authorize()` |
| `WPSPCORELITE\App\Auth\Access\HandlesAuthorization` | Trait cho Policy (`$this->allow()`, `$this->deny()`) |
| `WPSPCORELITE\App\Application` | Bind sẵn `'gate'` (singleton, alias `Gate::class`) |
| `WPSPCORELITE\App\Support\Facades\Gate` | Facade `Gate::...` |
| `WPSPLITE\App\Widen\Support\Facades\Gate` (plugin) | Facade của plugin, kế thừa facade core (giống `Cache`) |
| `WPSPCORELITE\App\Routes\RouteTrait` | Bản có đọc binding `'gate'` và `Gate::explain()` |
| `WPSPCORELITE\App\Routes\RouteData` | Có `->can()` |
| `WPSPCORELITE\App\Routes\BaseRoute` | Bản vá có `Route::can()` trước HTTP verb |
| `WPSPCORELITE\App\Traits\ControllerMiddlewareTrait` | `$this->middleware()` trong page class |

---

## 3. Gate có sẵn: binding, facade, config

`Application` của WPSPCORELITE bind sẵn `'gate'`, nên plugin **không cần tự đăng ký Gate**. Mỗi plugin có Application riêng, nên cũng có Gate riêng:

| Lấy Gate | Ghi chú |
| --- | --- |
| `Gate::allows(...)` | Facade của plugin (`WPSPLITE\App\Widen\Support\Facades\Gate`) |
| `$this->funcs->_getApplication('gate')` | |
| `$app->make('gate')` / `$app->make(\WPSPCORELITE\App\Auth\Access\Gate::class)` | Alias, cùng một object |
| `public function boot(\WPSPCORELITE\App\Auth\Access\Gate $gate)` | DI trong provider / controller |

RouteTrait (`can:`, `->can()`, controller middleware) và facade dùng **cùng một Gate**. Vì vậy Policy / ability đăng ký qua facade có hiệu lực ngay cho route.

Gate được tạo lười (lần `make('gate')` đầu tiên) với cấu hình mặc định:

| Thiết lập | Giá trị mặc định |
| --- | --- |
| User | `wp_get_current_user()` (khách = `null`) |
| `hook_prefix` | `Funcs::_getAppShortName()`; không có thì tên thư mục plugin (`plugin-cua-toi` → `plugin_cua_toi`) |
| Capability fallback | Bật |
| Namespace đoán Policy trước tiên | `{RootNamespace}\App\Policies` (vd: `WPSPLITE\App\Policies`) |
| Khởi tạo Policy | Qua container, nên constructor của Policy có DI |

### 3.1. Đăng ký Policy / ability trong provider

Đăng ký trong `boot()` của `AppServiceProvider` của plugin:

```php
<?php

namespace WPSPLITE\App\Providers;

use WPSPCORELITE\App\Auth\Access\Response;
use WPSPCORELITE\App\ServiceProvider;
use WPSPLITE\App\Policies\PostPolicy;
use WPSPLITE\App\Policies\ReportPolicy;
use WPSPLITE\App\Widen\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider {

	public function boot() {
		Gate::policy(\WP_Post::class, PostPolicy::class);

		Gate::define('view-dashboard', fn(\WP_User $user) => user_can($user, 'edit_posts'));
		Gate::define('export-report', ReportPolicy::class . '@export');

		Gate::before(fn(\WP_User $user, string $ability) => user_can($user, 'manage_options') ? true : null);

		// Bị từ chối mà Policy chỉ trả false → dùng Response này.
		// Gate::defaultDenialResponse(Response::deny('Bạn không có quyền truy cập.'));
	}

}
```

Không dùng facade thì type-hint Gate, vì `boot()` được gọi qua `$app->call()` nên có DI:

```php
use WPSPCORELITE\App\Auth\Access\Gate;

public function boot(Gate $gate) {
	$gate->policy(\WP_Post::class, PostPolicy::class);
}
```

### 3.2. Config `config/auth.php` (tuỳ chọn)

```php
<?php

return [
	// Policy cho class (giống $policies trong AuthServiceProvider của Laravel).
	'policies'            => [
		\WP_Post::class => \WPSPLITE\App\Policies\PostPolicy::class,
		\WP_User::class => \WPSPLITE\App\Policies\UserPolicy::class,
	],

	// Không có Policy / define → hỏi user_can() của WordPress.
	'capability_fallback' => true,

	// Thêm namespace để tự đoán Policy (sau {RootNamespace}\App\Policies).
	'policy_namespaces'   => [],

	// Class Gate riêng của plugin, phải kế thừa \WPSPCORELITE\App\Auth\Access\Gate.
	// 'gate_class'       => \WPSPLITE\App\Widen\Auth\Gate::class,
];
```

### 3.3. Thay Gate hoàn toàn

Plugin vẫn có thể tự bind Gate, ghi đè binding có sẵn:

```php
public function register() {
	$this->app->singleton('gate', function ($app) {
		return new \WPSPCORELITE\App\Auth\Access\Gate(
			fn() => wp_get_current_user(),
			['hook_prefix' => 'my_plugin', 'capability_fallback' => false]
		);
	});
}
```

Tuỳ chọn của constructor `Gate`:

| Tuỳ chọn | Mặc định khi tự tạo | Ý nghĩa |
| --- | --- | --- |
| `hook_prefix` | `null` | Có giá trị thì áp filter `{hook_prefix}_gate_result` |
| `capability_fallback` | `true` | Không có Policy / define thì gọi `user_can()` |
| `policy_namespaces` | `[]` | Namespace thử đầu tiên khi tự đoán Policy |
| `policy_resolver` | `new $class()` | Hàm khởi tạo Policy |
| `policies` | `[]` | `[class => policyClass]` |

### 3.4. Facade

Facade core `WPSPCORELITE\App\Support\Facades\Gate` theo cùng mẫu với `Cache`, `RateLimiter`. Plugin dùng facade con trong `Widen`, kế thừa facade core giống hệt cách `WPSPLITE\App\Widen\Support\Facades\Cache` kế thừa `WPSPCORELITE\App\Support\Facades\Cache`:

```php
<?php

namespace WPSPLITE\App\Widen\Support\Facades;

// Giống hệt Widen\Support\Facades\Cache của plugin: cùng trait / wpspInstance(), chỉ đổi class cha.
class Gate extends \WPSPCORELITE\App\Support\Facades\Gate {
	// use InstancesTrait;  (như các facade Widen khác)
}
```

Điểm khác các facade khác: root của facade được lấy từ Application **ở mỗi lần gọi**, không giữ lại lúc khởi tạo. Facade có thể được tạo trước khi Application sẵn sàng; nếu giữ lại, Policy đăng ký sau đó sẽ nằm trong Gate khác với Gate của route.

```php
Gate::getFacadeRoot();   // \WPSPCORELITE\App\Auth\Access\Gate của plugin
Gate::swap($fakeGate);   // test / mock
Gate::swap(null);        // quay lại Gate của Application
```

---

## 4. Chọn cách phân quyền

| Trường hợp | Cách nên dùng |
| --- | --- |
| Quyền theo role / capability (`manage_options`, `edit_posts`) | Capability WordPress |
| Quyền trên 1 bài viết / user / term cụ thể (`edit_post`, `delete_user`) | Capability WordPress (meta cap) |
| Luật riêng, 1–2 ability | `Gate::define()` |
| Một loại đối tượng có nhiều luật cần gom lại | Policy |

Meta cap của WordPress đã xử lý sẵn chuyện ai sở hữu bài viết, `edit_others_posts`, trạng thái bài viết... Bạn bổ sung luật qua filter `map_meta_cap`, và plugin quản lý role vẫn chỉnh được. Vì vậy đây là lựa chọn đầu tiên.

---

## 5. Cách 1: Capability WordPress

Không cần Policy hay define, chỉ cần đăng ký Gate với `capability_fallback` bật (mặc định).

```php
// Capability thường
Route::get('settings', [Settings::class, 'index'])->can('manage_options');
Route::get('drafts', [Drafts::class, 'index'])->can('edit_posts');

// Meta cap: WordPress tự xét tác giả, edit_others_posts...
Route::get('posts\/(?P<post>\d+)\/edit', [Posts::class, 'edit'])->can('edit_post', 'post');
Route::get('posts\/(?P<post>\d+)\/delete', [Posts::class, 'delete'])->can('delete_post', 'post');
Route::get('users\/(?P<user>\d+)', [Users::class, 'show'])->can('edit_user', 'user');
Route::get('terms\/(?P<term>\d+)', [Terms::class, 'edit'])->can('edit_term', 'term');

// Cả nhóm route
Route::can('edit_posts')->group(function() {
	Route::get('drafts', [Drafts::class, 'index']);
	Route::get('drafts\/(?P<post>\d+)', [Drafts::class, 'show'])->can('edit_post', 'post');
});
```

Trước khi gọi `user_can()`, Gate đổi đối số:

| Đối số | Truyền vào `user_can()` |
| --- | --- |
| `'5'`, `5` | `5` (số) |
| Object có `ID` hoặc `id` (`WP_Post`, `WP_User`...) | `ID` của object |
| Tên class | Bỏ qua |
| Chuỗi khác | Giữ nguyên |

`user_can()` chỉ được gọi khi user đã đăng nhập; khách luôn bị từ chối ở bước này. Trên multisite, super admin có mọi capability (trừ `do_not_allow`).

Tắt fallback khi muốn chỉ dùng Policy / define: `$gate->withCapabilityFallback(false)` hoặc `'capability_fallback' => false`.

---

## 6. Cách 2: Gate::define

Dùng cho luật riêng không có sẵn capability. Callback nhận user, sau đó là các đối số của `can` (id thô từ URL).

```php
// Closure
$gate->define('approve-order', function (\WP_User $user, $orderId) {
	return user_can($user, 'edit_shop_orders')
		&& (int)get_post_meta((int)$orderId, '_assigned_to', true) === $user->ID;
});

// 'Class@method'
$gate->define('export-report', 'WPSPLITE\App\Policies\ReportPolicy@export');

// [Class::class, 'method']
$gate->define('export-report', [ReportPolicy::class, 'export']);

// Class có __invoke
$gate->define('view-stats', StatsPermission::class);

// Enum
enum Ability: string { case ManageSite = 'manage-site'; }
$gate->define(Ability::ManageSite, fn(\WP_User $user) => user_can($user, 'manage_options'));

// Nhóm ability theo Policy:
// posts.viewAny, posts.view, posts.create, posts.update, posts.delete
$gate->resource('posts', PostPolicy::class);
$gate->resource('posts', PostPolicy::class, ['publish' => 'publish']); // chỉ tạo posts.publish (thay danh sách mặc định)
```

Trên route:

```php
Route::get('orders\/(?P<order>\d+)\/approve', [Orders::class, 'approve'])->can('approve-order', 'order');
// → fn(\WP_User $user, '9')

Route::get('reports\/export', [Reports::class, 'export'])->can('export-report');
Route::get('admin\/site', [Site::class, 'index'])->can(Ability::ManageSite);
Route::get('posts\/(?P<post>\d+)\/edit', [Posts::class, 'edit'])->can('posts.update', 'post');
```

Với `'Class@method'` và `[Class, 'method']`, `Policy::before()` của class đó cũng chạy trước method.

---

## 7. Cách 3: Policy

### 7.1. Viết Policy

```php
<?php

namespace WPSPLITE\App\Policies;

use WPSPCORELITE\App\Auth\Access\HandlesAuthorization;

class PostPolicy {

	use HandlesAuthorization;

	/**
	 * Chạy trước mọi method.
	 * true / false = quyết định luôn, null = chạy tiếp method của ability.
	 */
	public function before(\WP_User $user, string $ability) {
		return user_can($user, 'edit_others_posts') ? true : null;
	}

	/**
	 * Không cần đối tượng cụ thể. ?WP_User → khách cũng được kiểm tra.
	 */
	public function viewAny(?\WP_User $user): bool {
		return true;
	}

	public function create(\WP_User $user): bool {
		return user_can($user, 'edit_posts');
	}

	/**
	 * $post là id thô từ URL ('5'). LITE không tự bind model, Policy tự tìm bản ghi.
	 */
	public function update(\WP_User $user, $post) {
		$post = $this->findPost($post);

		if (!$post) {
			return $this->denyAsNotFound();
		}

		return (int)$post->post_author === $user->ID
			? $this->allow()
			: $this->deny('Bạn không phải tác giả bài viết này.');
	}

	public function delete(\WP_User $user, $post) {
		$post = $this->findPost($post);

		return $post && user_can($user, 'delete_post', $post->ID)
			? $this->allow()
			: $this->deny('Bạn không được xoá bài viết này.');
	}

	protected function findPost($post): ?\WP_Post {
		return $post instanceof \WP_Post ? $post : get_post((int)$post);
	}

}
```

**Quy tắc khi viết:**

| Quy tắc | Lý do |
| --- | --- |
| Tham số đầu là `\WP_User` | Gate dùng `wp_get_current_user()` |
| Tham số sau nhận id thô, tự tìm bản ghi | LITE không có model binding |
| Method không cần đối tượng chỉ có 1 tham số (`viewAny`, `create`) | Gọi bằng tên class, Gate bỏ tên class đi trước khi gọi method |
| Muốn cho khách: `?\WP_User $user` | Tham số đầu không cho null thì Gate tự từ chối khách |
| Cần message thì trả `Response` | `bool` chỉ cho message mặc định |
| Ability có dấu gạch (`view-any`) gọi method camelCase (`viewAny`) | Giống Laravel |

**Các kiểu kết quả:**

```php
return true;
return false;
return $this->allow();
return $this->deny('Lý do hiển thị cho người dùng.');
return $this->denyWithStatus(410, 'Bài viết đã bị gỡ.');
return $this->denyAsNotFound();

// Không dùng trait:
use WPSPCORELITE\App\Auth\Access\Response;
return Response::deny('Lý do', 'ERR_NOT_OWNER');

// Ném exception cũng được: Gate đổi thành Response::deny()
throw new \WPSPCORELITE\App\Auth\Access\AuthorizationException('Lý do');
```

### 7.2. Gắn Policy với class

Policy được tìm theo **đối số đầu tiên** của `can` (tên class hoặc object). WPSPLITE không có Model, nên khoá thường là class của WordPress hoặc một class đánh dấu của plugin.

```php
// Class WordPress
$gate->policy(\WP_Post::class, PostPolicy::class);
$gate->policy(\WP_User::class, UserPolicy::class);
$gate->policy(\WP_Term::class, TermPolicy::class);

// Class đánh dấu của plugin (class rỗng, chỉ dùng làm khoá)
namespace WPSPLITE\App\Resources;
final class Order {}

$gate->policy(\WPSPLITE\App\Resources\Order::class, OrderPolicy::class);

// Lớp cha / interface: mọi class con dùng chung Policy
$gate->policy(\WPSPLITE\App\Resources\Ownable::class, OwnablePolicy::class);

// Object Policy có sẵn
$gate->policy(\WP_Post::class, new PostPolicy($someService));
```

**Tự đoán Policy:** nếu chưa đăng ký, Gate thử theo thứ tự:

1. Các namespace trong `policy_namespaces`.
2. `...\Models\X` → `...\Policies\XPolicy` và `...\Models\Policies\XPolicy`.
3. `Policies` ở từng cấp namespace của class.

Với mỗi namespace, Gate thử tên `XPolicy`, rồi `XPolicy` bỏ hậu tố `Model` (`UsersModel` → `UsersPolicy`). Class WordPress không có namespace (`WP_Post`) thì nên đăng ký bằng `policy()`.

Tự quyết định tên Policy:

```php
$gate->guessPolicyNamesUsing(fn(string $class) =>
	'WPSPLITE\\App\\Policies\\' . basename(str_replace('\\', '/', $class)) . 'Policy'
);
```

### 7.3. Dùng Policy trên route

```php
// Không cần đối tượng: đối số là tên class
Route::get('posts', [Posts::class, 'index'])->can('viewAny', \WP_Post::class);
Route::get('posts\/create', [Posts::class, 'create'])->can('create', \WP_Post::class);
// → PostPolicy::viewAny($user), PostPolicy::create($user)

// Cần đối tượng: tên class + tên tham số route
Route::get('posts\/(?P<post>\d+)\/edit', [Posts::class, 'edit'])->can('update', [\WP_Post::class, 'post']);
// → PostPolicy::update($user, '5')

// Class có namespace: dùng ::class bình thường
Route::get('orders\/(?P<order>\d+)', [Orders::class, 'show'])->can('view', [Order::class, 'order']);
```

**Tên class:** `\WP_Post::class`, `'\WP_Post'` và `'WP_Post'` đều được hiểu là tên class. RouteTrait thử tham số route cùng tên trước; không có thì nếu class tồn tại, coi là tên class. Chỉ khi plugin có tham số route tên trùng tên class (vd: `(?P<WP_Post>...)`) mới cần viết `'\WP_Post'` để chắc chắn.

---

## 8. Phân quyền trên route

Ba cách viết dưới đây tạo ra cùng một middleware:

```php
// 1. Sau HTTP verb (RouteData::can) — khuyên dùng
Route::get('posts\/(?P<post>\d+)\/edit', [Posts::class, 'edit'])->can('edit_post', 'post');

// 2. Trước HTTP verb (BaseRoute::can)
Route::can('edit_post', 'post')->get('posts\/(?P<post>\d+)\/edit', [Posts::class, 'edit']);

// 3. Chuỗi middleware
Route::middleware('can:edit_post,post')->get('posts\/(?P<post>\d+)\/edit', [Posts::class, 'edit']);
```

**Nhóm route:**

```php
Route::can('edit_posts')->group(function() {
	Route::get('drafts', [Drafts::class, 'index']);
	Route::get('drafts\/export', [Drafts::class, 'export'])->can('export-report');
});
```

Route trong nhóm phải qua cả quyền của nhóm lẫn quyền riêng của route.

**Nhiều đối số và enum:**

```php
->can('update', ['\WP_Post', 'post'])     // can:update,\WP_Post,post
->can('transfer', ['from', 'to'])         // define('transfer', fn($user, $from, $to) => ...)
->can(Ability::ManageSite)                // BackedEnum → value, UnitEnum → name
```

**Kết hợp với middleware khác (AND / OR, lồng nhau):**

```php
// Đăng nhập VÀ có quyền
->middleware(['relation' => 'AND', AuthMiddleware::class, 'can:edit_posts'])

// Admin HOẶC tác giả
->middleware(['relation' => 'OR', 'can:manage_options', 'can:update,\WP_Post,post'])

// Lồng nhau, kèm throttle
->middleware([
	'relation' => 'AND',
	'throttle:30,1',
	['relation' => 'OR', 'can:manage_options', 'can:edit_post,post'],
])
```

Trong nhánh OR, message của nhánh bị từ chối không lọt sang khi nhánh khác đã cho qua.

---

## 9. Đối số của `can`

Mỗi đối số sau ability được hiểu theo thứ tự ưu tiên:

| Đối số | Được hiểu là | Ví dụ |
| --- | --- | --- |
| Có dấu `\` | Tên class | `'\WP_Post'`, `Order::class` |
| Trùng tên tham số route | Giá trị tham số (chuỗi) | `(?P<post>\d+)` → `'5'` |
| Trùng tên query / POST | Giá trị đó | `admin.php?page=wpsp_lite_posts&post=5` |
| Trong dấu nháy | Chuỗi literal | `can:publish,'draft'` |
| Tên class không namespace có tồn tại (`WP_Post`) | Tên class | `\WP_Post::class` |
| Còn lại | `null` (giống Laravel) | `can:publish,draft` → `null`; muốn chuỗi thì viết `'draft'` |

**Trang wp-admin (AdminPages):** URL có dạng `admin.php?page=...`, nên tham số thường nằm trên query string. `can:edit_post,post` đọc `post` từ query khi route không có tham số regex cùng tên.

**An toàn:** giá trị lấy từ URL (`'5'`, `'../../etc'`) không bao giờ bị đem đi autoload như tên class. Gate chỉ autoload chuỗi có dạng tên class hợp lệ.

---

## 10. Phân quyền trong controller / page class

LITE có `ControllerMiddlewareTrait`, nhưng không có `HasMiddleware` và `authorizeResource()` vì cả hai đều là của Illuminate. RouteTrait chỉ kiểm tra khi method được gọi đúng là action của route, nên các method vòng đời (`customProperties`, `init`...) không bị chặn.

```php
<?php

use WPSPCORELITE\App\Traits\ControllerMiddlewareTrait;

class wpsp_lite_posts /* extends class page của bạn */ {

	use ControllerMiddlewareTrait;

	public function customProperties() {
		// Từng action
		$this->middleware('can:viewAny,\WP_Post')->only('index');
		$this->middleware('can:update,\WP_Post,post')->only('edit', 'update');
		$this->middleware('can:delete_post,post')->only('destroy');

		// Mọi action trừ index
		$this->middleware('can:edit_posts')->except('index');

		// Closure middleware
		$this->middleware(function ($request, $next) {
			return current_user_can('export') ? $next($request) : false;
		})->only('export');
	}

	public function index() { ... }
	public function edit($post) { ... }    // $post = '5'
	public function update($post) { ... }
	public function destroy($post) { ... }
	public function export() { ... }

}
```

`ControllerMiddlewareTrait` chỉ đặt trên page / controller class, **không** đặt trên `BaseRoute`.

**Thay cho `authorizeResource()`:** khai báo tay theo bảng ánh xạ của Laravel.

```php
public function customProperties() {
	$this->middleware('can:viewAny,\WP_Post')->only('index');
	$this->middleware('can:view,\WP_Post,post')->only('show');
	$this->middleware('can:create,\WP_Post')->only('create', 'store');
	$this->middleware('can:update,\WP_Post,post')->only('edit', 'update');
	$this->middleware('can:delete,\WP_Post,post')->only('destroy');
}
```

---

## 11. Dùng Gate trong code

```php
use WPSPCORELITE\App\Auth\Access\AuthorizationException;

use WPSPLITE\App\Widen\Support\Facades\Gate;

// Facade: Gate::allows(...), Gate::inspect(...) ... — hoặc lấy object:
$gate = $this->funcs->_getApplication('gate');

// Đúng / sai
$gate->allows('edit_posts');
$gate->denies('update', [\WP_Post::class, $postId]);

// Nhiều ability
$gate->check(['edit_posts', 'publish_posts']); // tất cả
$gate->any(['edit_posts', 'manage_options']);  // ít nhất 1
$gate->none(['delete_posts']);                 // không cái nào

// Kết quả kèm message / status
$response = $gate->inspect('update', [\WP_Post::class, $postId]);
if ($response->denied()) {
	wp_die(esc_html($response->message() ?? 'Không có quyền.'), '', ['response' => $response->status() ?? 403]);
}

// Ném exception
try {
	$gate->authorize('approve-order', $orderId);
}
catch (AuthorizationException $e) {
	wp_die(esc_html($e->getMessage()), '', ['response' => $e->status() ?? 403]);
}

// Kiểm tra cho user khác (không ảnh hưởng Gate gốc)
$gate->forUser(get_user_by('id', 5))->allows('edit_posts');

// Điều kiện tại chỗ (ném AuthorizationException khi không đạt)
$gate->allowIf(fn(?\WP_User $user) => $user && $user->ID === $ownerId, 'Không phải chủ sở hữu.');
$gate->denyIf(fn(?\WP_User $user) => !$user, 'Vui lòng đăng nhập.');

// Kết quả thô (bool / Response / null)
$raw = $gate->raw('update', [\WP_Post::class, $postId]);

// Thông tin
$gate->has('approve-order');
$gate->abilities();
$gate->policies();
$gate->getPolicyFor(\WP_Post::class);

// Chẩn đoán: Gate sẽ dùng Policy / define / capability nào (không chạy Policy)
$gate->explain('update', [\WP_Post::class, $postId]);
```

`\WP_Post::class` và `'\WP_Post'` dùng được ở cả route lẫn code PHP.

---

## 12. before, after, filter

```php
// Chạy trước mọi kiểm tra. Khác null thì dừng.
$gate->before(function (\WP_User $user, string $ability, array $arguments) {
	if (user_can($user, 'manage_options')) {
		return true;
	}

	return null;
});

// Chạy sau mọi kiểm tra. Chỉ thay kết quả khi chưa có kết quả (null).
$gate->after(function (\WP_User $user, string $ability, $result, array $arguments) {
	error_log(sprintf('%s: %s', $ability, var_export($result, true)));

	return null; // không thay kết quả
});

// Filter cuối cùng (cần 'hook_prefix'), cho plugin / theme khác chỉnh kết quả
add_filter('wpsp_lite_gate_result', function ($result, $ability, $arguments, $user, $gate) {
	return $result;
}, 10, 5);
```

Khi `capability_fallback` bật, ability chưa có Policy / define vẫn có kết quả từ `user_can()` (`true` / `false`, không phải `null`), nên `after()` gần như chỉ dùng để ghi log. Muốn `after()` quyết định cho ability chưa định nghĩa thì tắt fallback.

`before` / `after` có tham số đầu là `\WP_User` (không cho null) sẽ không chạy cho khách. Muốn chạy cho cả khách thì khai báo `?\WP_User $user`.

---

## 13. Khách (chưa đăng nhập)

- `wp_get_current_user()` trả `WP_User` có `ID = 0`. Gate coi đây là khách (`null`).
- Callback hoặc method Policy chỉ được gọi cho khách khi tham số đầu cho phép null: `?\WP_User $user`, `\WP_User|null $user`, hoặc `$user = null`. Không cho null thì Gate từ chối mà không gọi.
- Capability fallback không chạy cho khách.

```php
$gate->define('view-public-page', fn(?\WP_User $user) => true);

class PostPolicy {
	public function view(?\WP_User $user, $post): bool {
		$post = get_post((int)$post);
		return $post && ($post->post_status === 'publish' || ($user && (int)$post->post_author === $user->ID));
	}
}
```

---

## 14. Khi bị từ chối

Thứ tự xử lý:

1. Middleware nào chặn bằng response riêng (redirect, `WP_HTTP_Response`...) thì response đó được gửi. Redirect về chính URL hiện tại bị bỏ qua để tránh vòng lặp.
2. Không có response riêng:
   - AdminPages: trang 403 với message.
   - Ajaxs: JSON 403 kèm message.
   - FrontPages: để WordPress xử lý tiếp.
   - Controller middleware: 403 với message, hoặc `This action is unauthorized.`

Message lấy từ `Response::deny('...')` của Policy / define. Không có message thì dùng `defaultDenialResponse()` nếu có đặt, không thì message mặc định. Khi `WP_DEBUG`, message được thay bằng thông tin chẩn đoán.

```php
$message = $this->lastAuthorizationMessage($this->request);

// Mỗi lần can bị từ chối
add_action($prefix . '_authorization_denied', function ($ability, $arguments, $message, $request) {
	error_log('Từ chối ' . $ability . ': ' . $message);
}, 10, 4);

// Trước khi gửi response chặn của middleware
add_action($prefix . '_before_send_middleware_response', function ($response, $request) { ... }, 10, 2);

// Trả false để không gửi response chặn
add_filter($prefix . '_send_blocked_middleware_response', function ($send, $response, $request) {
	return $send;
}, 10, 3);
```

---

## 15. Nhiều plugin trong cùng một site

- Gate không dùng biến static. Mỗi plugin có Application riêng, Application bind Gate riêng, nên Policy / ability của plugin này không lọt sang plugin khác.
- Facade `Gate` của plugin lấy Gate từ Application của chính plugin đó (qua `funcs`).
- Policy chỉ khởi tạo 1 lần cho mỗi Gate.
- `hook_prefix` mặc định là `_getAppShortName()` (hoặc tên thư mục plugin), nên filter `{prefix}_gate_result` không trùng giữa các plugin.
- Hai plugin cùng đăng ký Policy cho `\WP_Post` vẫn độc lập, vì mỗi Gate có danh sách Policy riêng.

---

## 16. Tham chiếu API

**Gate:**

| Method | Trả về | Ghi chú |
| --- | --- | --- |
| `define($ability, $callback)` | `$this` | Closure, `'Class@method'`, `[Class, 'method']`, invokable; ability là string hoặc enum |
| `resource($name, $class, $abilities = null)` | `$this` | `name.viewAny`, `name.view`, `name.create`, `name.update`, `name.delete` |
| `policy($class, $policy)` | `$this` | Policy là tên class hoặc object |
| `before($callback)` / `after($callback)` | `$this` | |
| `guessPolicyNamesUsing($callback)` | `$this` | |
| `resolvePoliciesUsing($resolver)` | `$this` | |
| `defaultDenialResponse(Response $r)` | `$this` | |
| `withCapabilityFallback(bool $enabled = true)` | `$this` | |
| `setUserResolver($callback)` | `$this` | |
| `forUser($user)` | `Gate` | Bản sao cho user khác |
| `allows` / `denies` | `bool` | |
| `check` / `any` / `none` | `bool` | Nhận mảng ability |
| `inspect($ability, $arguments = [])` | `Response` | RouteTrait gọi hàm này |
| `authorize($ability, $arguments = [])` | `Response` | Ném `AuthorizationException` |
| `raw($ability, $arguments = [])` | `mixed` | |
| `allowIf($condition, $message, $code)` / `denyIf(...)` | `Response` | Ném khi không đạt |
| `has($ability)` | `bool` | |
| `abilities()` / `policies()` | `array` | |
| `getPolicyFor($class)` | `?object` | |
| `guessPolicyName($class)` | `array` | Danh sách tên Policy sẽ thử |
| `resolveUser()` | `mixed` | User hiện tại, `null` nếu khách |
| `explain($ability, $arguments = [])` | `string` | Chẩn đoán |

**Response:**

| Method | Ghi chú |
| --- | --- |
| `Response::allow($message = null, $code = null)` | |
| `Response::deny($message = null, $code = null)` | |
| `Response::denyWithStatus($status, $message = null, $code = null)` | |
| `Response::denyAsNotFound($message = null, $code = null)` | Status 404 |
| `allowed()` / `denied()` | |
| `message()` / `code()` / `status()` | `message()` là `null` khi không đặt |
| `withStatus($status)` / `asNotFound()` | |
| `authorize()` | Ném `AuthorizationException` khi bị từ chối |
| `toArray()` | |

**AuthorizationException:** `getMessage()`, `status()`, `hasStatus()`, `response()`, `withStatus()`, `asNotFound()`, `toResponse()`.

---

## 17. Lỗi thường gặp và chẩn đoán

Khi bật `WP_DEBUG`, lỗi trong Gate / Policy được ném ra kèm gợi ý cách sửa thay vì bị giấu thành 403. Khi tắt, request bị từ chối và lỗi ghi vào `error_log`.

| Hiện tượng | Nguyên nhân | Cách sửa |
| --- | --- | --- |
| Mọi `can:` đều 403, có cảnh báo `_doing_it_wrong` | Binding `'gate'` bị ghi đè bằng thứ không phải Gate, hoặc đang dùng `Application` cũ chưa bind `'gate'` | Dùng `Application` bản mới; kiểm tra provider có `singleton('gate', ...)` không |
| Policy đăng ký qua facade không áp cho route | Facade dùng Gate tạm vì Application chưa sẵn sàng (có cảnh báo `_doing_it_wrong`) | Đăng ký trong `boot()` của provider, không đăng ký ở top-level file plugin |
| `->can('view', \WP_Post::class)` bị 403 | (RouteTrait cũ) `WP_Post` bị hiểu là tên tham số route → `null`; hoặc chưa đăng ký Policy cho `WP_Post`; hoặc Policy không có method `view` | Dùng RouteTrait mới; `Gate::policy(\WP_Post::class, PostPolicy::class)`; Policy có `view(\WP_User $user)` (hoặc dùng `viewAny`) |
| Policy nhận chuỗi `'5'` thay vì object | LITE không có model binding | Tự tìm bản ghi: `get_post((int)$id)` |
| `ArgumentCountError: Too few arguments to ...Policy::update()` | Truyền tên class mà không có tham số route: Gate bỏ tên class, thiếu đối số | Thêm tên tham số: `->can('update', ['\WP_Post', 'post'])`; hoặc khai báo `$post = null` |
| `TypeError: Argument #1 ... must be of type ...` | Tham số đầu của Policy không phải `\WP_User` | Khai báo `\WP_User $user` (hoặc `?\WP_User`) |
| Khách bị 403 dù Policy trả `true` | Tham số đầu không cho phép null | `?\WP_User $user` |
| `can:manage_options` bị từ chối với admin | Đã tắt `capability_fallback`, hoặc có `define('manage_options')` / Policy chặn trước | Bật lại fallback; kiểm tra bằng `explain()` |
| `Route::can(...)->get()` tạo route lạ, không chặn | `BaseRoute` cũ chưa xử lý `can` trong `__call()` | Dùng `BaseRoute.php` bản vá |
| Fatal error thay vì trang lỗi | Route class chỉ `catch (\Exception)`, không bắt `\Error` | Sửa Policy theo gợi ý trong message; chỉ hiện khi `WP_DEBUG` |

**Message chẩn đoán** (chỉ khi `WP_DEBUG`, Policy không đặt message):

```
can:update bị từ chối. Đối số: ['\WP_Post' (string), '6' (string)] | User: WP_User#2 | Policy: WPSPLITE\App\Policies\PostPolicy::update()
can:nothing bị từ chối. Đối số: [] | User: WP_User#2 | Không tìm thấy Policy / Gate::define (capability fallback đang tắt) cho 'nothing'
can:edit_posts bị từ chối. Đối số: [] | Gate: không có binding "gate"
```

Đọc message này theo thứ tự:

1. **Gate:** có binding `"gate"` không.
2. **User:** đúng user không, hay là `khách (chưa đăng nhập)`.
3. **Đối số:** có tên class (có dấu `\`) và giá trị tham số đúng không.
4. **Phần cuối:** Gate dùng Policy nào, `define` nào, hay `user_can()`.

Kiểm tra trực tiếp trong code:

```php
error_log($gate->explain('update', ['\WP_Post', '5']));
error_log(print_r($gate->guessPolicyName(\WPSPLITE\App\Resources\Order::class), true));
```
