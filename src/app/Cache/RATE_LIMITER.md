# Hướng dẫn sử dụng RateLimiter — WPSPCORELITE

Cập nhật: 2026-10-18

RateLimiter giới hạn số lần một hành động được phép xảy ra trong một khoảng thời gian: đăng nhập sai, gửi OTP, gọi API, gửi form... Nó mô phỏng `Illuminate\Cache\RateLimiter`, `Illuminate\Cache\RateLimiting\Limit` và middleware `ThrottleRequests` của Laravel 12, không cần Illuminate hay Symfony.

Mọi ví dụ dùng namespace của template `WPSPLITE`. Plugin tạo bằng `composer create-project wpsp-lite plugin-cua-toi` thay `WPSPLITE` bằng namespace của plugin đó.

## Mục lục

1. [Tổng quan](#1-tổng-quan)
2. [Cấu hình](#2-cấu-hình)
3. [Cách gọi RateLimiter](#3-cách-gọi-ratelimiter)
4. [API của RateLimiter](#4-api-của-ratelimiter)
5. [Limit: mô tả một giới hạn](#5-limit-mô-tả-một-giới-hạn)
6. [Named limiters](#6-named-limiters)
7. [Middleware ThrottleRequests](#7-middleware-throttlerequests)
   - [Dùng `throttle:` trong Route](#71-dùng-throttle-trong-route)
8. [Trường hợp thực tế](#8-trường-hợp-thực-tế)
9. [Tính atomic, giới hạn và lưu ý](#9-tính-atomic-giới-hạn-và-lưu-ý)
10. [Tra cứu nhanh](#10-tra-cứu-nhanh)

---

## 1. Tổng quan

| Class | Vai trò | Tương đương Laravel |
| --- | --- | --- |
| `WPSPLITE\App\Widen\Support\Facades\RateLimiter` | Facade gọi static trong plugin | `Illuminate\Support\Facades\RateLimiter` |
| `WPSPLITE\App\Widen\Cache\RateLimiter` | RateLimiter của plugin (kế thừa core) | — |
| `WPSPCORELITE\App\Cache\RateLimiter` | Bộ đếm: `attempt`, `hit`, `tooManyAttempts`... | `Illuminate\Cache\RateLimiter` |
| `WPSPCORELITE\App\Cache\RateLimiting\Limit` | Mô tả giới hạn: `Limit::perMinute(60)->by($ip)` | `Illuminate\Cache\RateLimiting\Limit` |
| `WPSPCORELITE\App\Cache\RateLimiting\GlobalLimit` | Giới hạn chung cho mọi người | `GlobalLimit` |
| `WPSPCORELITE\App\Cache\RateLimiting\Unlimited` | Không giới hạn (`Limit::none()`) | `Unlimited` |
| `WPSPCORELITE\App\Http\Middleware\ThrottleRequests` | Middleware giới hạn request | `Illuminate\Routing\Middleware\ThrottleRequests` |
| `WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException` | Lỗi HTTP 429 | `Illuminate\Http\Exceptions\ThrottleRequestsException` |

**Cách hoạt động.** Với mỗi key (ví dụ `login:1.2.3.4`), RateLimiter lưu hai giá trị trong cache của plugin:

| Key trong cache | Giá trị |
| --- | --- |
| `{key}` | số lần đã hit trong cửa sổ thời gian hiện tại |
| `{key}:timer` | thời điểm (Unix) cửa sổ kết thúc |

Hit đầu tiên mở một cửa sổ dài `$decaySeconds` giây. Khi cửa sổ hết hạn, bộ đếm tự về 0. Đây là kiểu **fixed window**, giống Laravel.

**Cách ly giữa các plugin là tự động.** Bộ đếm nằm trong cache của plugin, nên mang prefix của plugin (mặc định lấy theo tên thư mục). Ví dụ với key `login:1.2.3.4`:

| Plugin | Dòng trong `wp_options` |
| --- | --- |
| `plugins/plugin-a` | `_transient_plugin_a_.login:1.2.3.4` |
| `plugins/plugin-b` | `_transient_plugin_b_.login:1.2.3.4` |

Named limiter (`RateLimiter::for()`) cũng thuộc về instance RateLimiter của từng plugin, nên `for('api', ...)` ở hai plugin là hai limiter khác nhau.

---

## 2. Cấu hình

Không cần viết thêm class nào. Plugin tạo từ template đã có sẵn:

- `app/Widen/Cache/RateLimiter.php`
- `app/Widen/Support/Facades/RateLimiter.php`
- binding `rate.limiter` trong `Application`

Cấu hình nằm trong `config/cache.php` của plugin:

```php
return [

	'default' => 'transient',

	// ...stores, prefix, class...

	'limiter'       => null,   // store chứa bộ đếm; null = 'default'
	'limiter_class' => \WPSPLITE\App\Widen\Cache\RateLimiter::class,

];
```

| Khoá | Mặc định | Ý nghĩa |
| --- | --- | --- |
| `limiter` | `null` | Tên store giữ bộ đếm. `null` = store `default`. |
| `limiter_class` | `WPSPCORELITE\App\Cache\RateLimiter` | Class được bind làm `rate.limiter`; phải kế thừa class core. |

Chọn store cho `limiter`:

| Store | Dùng cho RateLimiter? |
| --- | --- |
| `transient` | Được — mặc định, chạy trên mọi host, đếm atomic |
| `object` (có Redis/Memcached drop-in) | Tốt nhất về tốc độ, đếm atomic |
| `file` | Được, đếm atomic (flock) |
| `phpfastcache` Redis/Files | Được, nhưng **không atomic** (xem mục 9) |
| `array`, `null` | **Không** — `array` mất bộ đếm sau mỗi request, `null` không lưu gì |

Ví dụ: cache chính dùng `file`, còn bộ đếm dùng `transient`:

```php
'default' => 'file',
'limiter' => 'transient',
```

---

## 3. Cách gọi RateLimiter

**Facade (cách thường dùng):**

```php
use WPSPLITE\App\Widen\Support\Facades\RateLimiter;
use WPSPCORELITE\App\Cache\RateLimiting\Limit;

RateLimiter::attempt('send-otp:' . $phone, 3, fn() => sendOtp($phone), 600);
```

**Từ container:**

```php
$limiter = $app->make('rate.limiter');
$limiter = $app->make(\WPSPCORELITE\App\Cache\RateLimiter::class);   // cùng object (alias, singleton)
```

Có thể type-hint `WPSPCORELITE\App\Cache\RateLimiter` trong constructor của service hoặc middleware để container tự inject.

**Tự tạo (ví dụ trong test):**

```php
use WPSPCORELITE\App\Cache\Cache;
use WPSPCORELITE\App\Cache\RateLimiter as RateLimiterCore;

$limiter = new RateLimiterCore(Cache::make(['default' => 'array', 'prefix' => 'test_'])->store());
```

**Thay limiter của facade (test):**

```php
RateLimiter::swap($limiter);
RateLimiter::getFacadeRoot();   // object hiện tại
```

---

## 4. API của RateLimiter

Tham số `$key` là chuỗi tự đặt, nên chứa **hành động** và **đối tượng bị giới hạn**: `"login:{$ip}"`, `"otp:{$phone}"`, `"export:user:{$userId}"`. Tham số `$decaySeconds` nhận `int` (giây), `DateInterval` hoặc `DateTimeInterface`.

### `attempt($key, $maxAttempts, Closure $callback, $decaySeconds = 60)`

Chạy closure nếu chưa vượt giới hạn, rồi tính một lần hit.

```php
$sent = RateLimiter::attempt(
	'send-message:' . get_current_user_id(),
	$perMinute = 5,
	function () use ($message) {
		send_message($message);
	}
);

if (!$sent) {
	wp_send_json_error('Bạn gửi quá nhanh, vui lòng chờ một chút.', 429);
}
```

| Kết quả | Khi nào |
| --- | --- |
| `false` | Đã vượt giới hạn, closure **không** chạy |
| `true` | Closure đã chạy và trả về `null` |
| giá trị closure trả về | Closure đã chạy và trả về khác `null` |

Nếu closure có thể trả về `false`, hãy kiểm tra trước bằng `tooManyAttempts()` để phân biệt.

### `tooManyAttempts($key, $maxAttempts)`

`true` nếu số lần hit đã đạt `$maxAttempts` và cửa sổ chưa hết hạn.

```php
if (RateLimiter::tooManyAttempts('login:' . $ip, 5)) {
	$seconds = RateLimiter::availableIn('login:' . $ip);
	return new WP_Error('too_many_attempts', "Thử lại sau {$seconds} giây.");
}
```

### `hit($key, $decaySeconds = 60)`

Tăng bộ đếm thêm 1 và trả về số lần **sau khi tăng**. Hit đầu tiên mở cửa sổ `$decaySeconds` giây.

```php
RateLimiter::hit('login:' . $ip, 15 * MINUTE_IN_SECONDS);
```

### `increment($key, $decaySeconds = 60, $amount = 1)` / `decrement(...)`

Giống `hit()` nhưng tăng hoặc giảm theo `$amount`. Dùng khi một hành động "tốn" nhiều đơn vị.

```php
// Mỗi user được xuất tối đa 10.000 dòng mỗi giờ
$rows = count($ids);
if (RateLimiter::attempts("export:{$userId}") + $rows > 10000) {
	wp_die('Vượt hạn mức xuất dữ liệu trong giờ.');
}
RateLimiter::increment("export:{$userId}", HOUR_IN_SECONDS, $rows);
```

### `attempts($key)`

Số lần đã hit trong cửa sổ hiện tại (`0` nếu chưa có).

### `remaining($key, $maxAttempts)` / `retriesLeft(...)`

Số lần còn được phép: `max(0, $maxAttempts - attempts)`.

```php
$left = RateLimiter::remaining('otp:' . $phone, 3);
echo "Bạn còn {$left} lần gửi mã.";
```

### `availableIn($key)`

Số giây đến khi cửa sổ kết thúc (`0` nếu không bị giới hạn).

### `resetAttempts($key)`

Đưa bộ đếm về 0 nhưng giữ timer. Dùng khi muốn tính lại từ đầu trong cùng cửa sổ.

### `clear($key)`

Xoá cả bộ đếm và timer. Dùng sau khi đăng nhập thành công, sau khi admin mở khoá cho user...

```php
add_action('wp_login', function (string $username) {
	RateLimiter::clear('login:' . strtolower($username) . '|' . plugin_client_ip());
});
```

### `cleanRateLimiterKey($key)`

Chuẩn hoá key như Laravel: bỏ dấu (`vé` → `ve`). Các method khác tự gọi hàm này, bạn hiếm khi cần gọi trực tiếp.

### `getCache()`

Repository cache đang giữ bộ đếm.

---

## 5. Limit: mô tả một giới hạn

`Limit` dùng trong named limiter (mục 6) và middleware (mục 7).

```php
use WPSPCORELITE\App\Cache\RateLimiting\Limit;
```

| Factory | Ý nghĩa |
| --- | --- |
| `Limit::perSecond(10)` | 10 lần / giây |
| `Limit::perSecond(10, 5)` | 10 lần / 5 giây |
| `Limit::perMinute(60)` | 60 lần / phút |
| `Limit::perMinute(60, 5)` | 60 lần / 5 phút |
| `Limit::perMinutes(5, 100)` | 100 lần / 5 phút (thứ tự tham số ngược với `perMinute`) |
| `Limit::perHour(1000)` | 1000 lần / giờ |
| `Limit::perHour(1000, 6)` | 1000 lần / 6 giờ |
| `Limit::perDay(5)` | 5 lần / ngày |
| `Limit::perDay(5, 7)` | 5 lần / 7 ngày |
| `Limit::none()` | không giới hạn (`Unlimited`) |
| `new GlobalLimit(100, 60)` | 100 lần / phút, dùng chung cho mọi người |
| `new Limit('key', 10, 30)` | khởi tạo đầy đủ: key, số lần, số giây |

| Method | Ý nghĩa |
| --- | --- |
| `->by($key)` | Đếm riêng theo `$key` (IP, user id, email...). Không gọi `by()` = một bộ đếm chung cho tất cả. |
| `->response(fn($request, array $headers) => ...)` | Response trả về khi vượt giới hạn (thay cho lỗi 429 mặc định) |
| `->after(fn($response) => bool)` | Chỉ tính hit khi closure trả `true` sau khi request chạy xong, ví dụ chỉ đếm lần đăng nhập **sai** |

Các thuộc tính public: `key`, `maxAttempts`, `decaySeconds`, `responseCallback`, `afterCallback`.

```php
Limit::perMinute(5)
	->by($request->ip())
	->response(fn($request, array $headers) => new WP_REST_Response(
		['message' => 'Quá nhiều yêu cầu.'], 429, $headers
	));
```

---

## 6. Named limiters

Đặt tên cho một cấu hình giới hạn, rồi dùng tên đó ở nhiều nơi.

### `for($name, Closure $callback)`

Closure nhận `$request` và trả về một trong các giá trị sau:

| Trả về | Middleware xử lý |
| --- | --- |
| một `Limit` | áp dụng giới hạn đó |
| mảng `Limit` | áp dụng **tất cả**, vượt cái nào cũng bị chặn |
| `Limit::none()` | không giới hạn |
| giá trị khác (response) | trả thẳng giá trị đó, không chạy request |

```php
use WPSPLITE\App\Widen\Support\Facades\RateLimiter;
use WPSPCORELITE\App\Cache\RateLimiting\Limit;

// Đăng ký một lần khi plugin boot (provider boot() hoặc hook plugins_loaded / init)
RateLimiter::for('api', function ($request) {
	return get_current_user_id()
		? Limit::perMinute(120)->by('user:' . get_current_user_id())
		: Limit::perMinute(30)->by('ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''));
});

RateLimiter::for('uploads', fn($request) => [
	Limit::perMinute(10)->by('user:' . get_current_user_id()),
	Limit::perDay(200)->by('user:' . get_current_user_id()),
]);

RateLimiter::for('admin', fn($request) => current_user_can('manage_options')
	? Limit::none()
	: Limit::perMinute(20)->by('ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''))
);
```

Tên có thể là enum:

```php
enum Limiter: string {
	case Api   = 'api';
	case Login = 'login';
}

RateLimiter::for(Limiter::Api, fn($request) => Limit::perMinute(60));
```

### `limiter($name)` / `limiters()`

```php
$callback = RateLimiter::limiter('api');   // Closure hoặc null
$names    = RateLimiter::limiters();       // ['api', 'uploads', 'admin']
```

### Khai báo trong AppServiceProvider (giống Laravel)

Named limiter khai báo trong `boot()` của `AppServiceProvider` của plugin, rồi dùng ở middleware route bằng `throttle:{tên}`:

```php
<?php

namespace WPSPLITE\App\Providers;

use WPSPCORELITE\App\Cache\RateLimiting\Limit;
use WPSPCORELITE\App\Support\ServiceProvider;
use WPSPLITE\App\Widen\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider {

	public function boot() {
		RateLimiter::for('30rpm', function ($request) {
			return Limit::perMinute(30);
		});

		RateLimiter::for('api', function ($request) {
			return get_current_user_id()
				? Limit::perMinute(120)->by('user:' . get_current_user_id())
				: Limit::perMinute(30)->by('ip:' . $request->ip());
		});

		RateLimiter::for('login', fn($request) => Limit::perMinutes(15, 5)->by($request->ip()));
	}

}
```

Cũng có thể gọi thẳng qua container thay vì facade: `$this->app->make('rate.limiter')->for('30rpm', ...)`. Hai cách cho cùng một object (singleton `rate.limiter` của plugin).

Lưu ý:

- `Limit::perMinute(30)` **không** gọi `by()` nghĩa là **một bộ đếm chung cho mọi người**: cả site chỉ được 30 request mỗi phút vào route đó. Muốn mỗi người dùng hay mỗi IP có 30 request riêng, hãy thêm `->by($request->ip())` hoặc `->by('user:' . get_current_user_id())`.
- `$request` là request của framework (`WPSPCORELITE\App\Http\Request`), có `ip()`, `user()`, `getHost()`...
- Named limiter chỉ thuộc về plugin khai báo nó. `throttle:30rpm` ở plugin khác sẽ không thấy limiter này.

**Đăng ký ở đâu:** trong `boot()` của `AppServiceProvider` (như trên), hoặc trong hook `plugins_loaded` / `init`. Named limiter phải được đăng ký **trước** khi middleware hoặc code dùng đến nó.

---

## 7. Middleware ThrottleRequests

```php
use WPSPCORELITE\App\Http\Middleware\ThrottleRequests;
use WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException;
```

### Chữ ký

```php
public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
```

| Cách gọi | Ý nghĩa |
| --- | --- |
| `handle($req, $next)` | 60 request / phút |
| `handle($req, $next, 10, 1)` | 10 request / phút |
| `handle($req, $next, 100, 60, 'export')` | 100 request / giờ, bộ đếm riêng tên `export` |
| `handle($req, $next, '10\|60', 1)` | khách 10, user đã đăng nhập 60 request / phút |
| `handle($req, $next, 'api')` | dùng named limiter `api` |

Khi không dùng named limiter, request được nhận diện theo user đang đăng nhập (`$request->user()` hoặc `get_current_user_id()`). Nếu chưa đăng nhập thì theo host và IP.

Chuỗi middleware cho định nghĩa route (giống Laravel):

```php
ThrottleRequests::using('api');      // "WPSPCORELITE\App\Http\Middleware\ThrottleRequests:api"
ThrottleRequests::with(10, 1);       // "...ThrottleRequests:10,1"
```

Engine middleware của router cần resolve class qua container (để inject `RateLimiter`) và truyền các tham số sau dấu `:` vào `handle()`.

### Kết quả

**Chưa vượt:** response được thêm header, nếu response hỗ trợ (`WP_REST_Response::header()`, object có `->headers->set()`, hoặc `header()` của PHP khi chưa gửi output):

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
```

**Vượt giới hạn:** ném `ThrottleRequestsException`:

| Method | Trả về |
| --- | --- |
| `getStatusCode()` | `429` |
| `getHeaders()` | `X-RateLimit-Limit`, `X-RateLimit-Remaining` (0), `Retry-After`, `X-RateLimit-Reset` |
| `retryAfter()` | số giây phải chờ |
| `getResponse()` | response từ `Limit::response()`, hoặc `null` |
| `toWpError()` | `WP_Error('too_many_requests', ..., ['status' => 429])` |
| `sendHeaders()` | gửi status 429 và header bằng `header()` của PHP |

Handler lỗi của plugin nên bắt exception này và trả về mã 429.

### 7.1 Dùng `throttle` trong Route

`RouteTrait` hiểu middleware `throttle` giống WPSPCORE. Nó chạy `ThrottleRequests` với `rate.limiter` của plugin, nên named limiter khai báo trong `AppServiceProvider` dùng được ngay:

```php
use WPSPCORELITE\App\Http\Middleware\ThrottleRequests;

Route::middleware([
	['relation' => 'OR', 'throttle:30rpm', EditorCapability::class],
	['relation' => 'AND', AdministratorCapability::class],
])->get('\/test\/(?P<slug>[^\/\?]+)(?:\?(?P<queries>.*))?', [rewrite_demo::class, 'index'])->name('index');
```

| Cách viết | Ý nghĩa |
| --- | --- |
| `'throttle'` | 60 request / phút |
| `'throttle:60,1'` | 60 request / 1 phút |
| `'throttle:100,60,export'` | 100 request / giờ, bộ đếm riêng tên `export` |
| `'throttle:10\|60,1'` | khách 10, user đã đăng nhập 60 request / phút |
| `'throttle:30rpm'` | named limiter `30rpm` (khai báo bằng `RateLimiter::for('30rpm', ...)`) |
| `ThrottleRequests::using('30rpm')` | giống `'throttle:30rpm'` |
| `ThrottleRequests::with(60, 1)` | giống `'throttle:60,1'` |
| `['throttle:30rpm']` | dạng mảng, giống dạng chuỗi |

Hành vi:

- **Mỗi request chỉ đếm một lần cho mỗi chữ ký throttle**, kể cả khi `isPassedMiddleware()` được gọi nhiều lần trong cùng request.
- **Chưa vượt:** middleware PASS, và header `X-RateLimit-Limit`, `X-RateLimit-Remaining` được gửi về client.
- **Vượt giới hạn:** request dừng ngay với mã 429, kèm `Retry-After`, `X-RateLimit-Reset`. Client muốn JSON (`_wantsJson()`) nhận JSON; ngược lại là trang `wp_die()`. Giống WPSPCORE, việc dừng xảy ra ngay cả khi throttle nằm trong block `OR`.
- **`Limit::response()`** thay cho thông báo mặc định:
  - trả `WP_REST_Response` / `WP_HTTP_Response`: được gửi đúng status, header và data;
  - trả mảng: gửi JSON 429;
  - trả chuỗi: dùng làm thông báo lỗi.
- **`throttle:{tên}` chưa khai báo:** khi bật `WP_DEBUG` sẽ có cảnh báo `_doing_it_wrong`. Giống Laravel, tên khi đó bị hiểu thành số: `'30rpm'` → 30 lần mỗi phút, còn `'abc'` → 0, tức là chặn mọi request.
- **Bộ đếm nằm trong cache của plugin:** cùng IP, cùng `throttle:60,1` ở hai plugin là hai bộ đếm riêng.

### Dùng trong WordPress REST API

```php
use WPSPLITE\App\Widen\Support\Facades\RateLimiter;
use WPSPCORELITE\App\Http\Middleware\ThrottleRequests;
use WPSPCORELITE\App\Http\Exceptions\ThrottleRequestsException;

function plugin_throttled(string $limiter, callable $handler): callable {
	return function (WP_REST_Request $request) use ($limiter, $handler) {
		$throttle = new ThrottleRequests(RateLimiter::getFacadeRoot());

		try {
			return $throttle->handle($request, fn($req) => rest_ensure_response($handler($req)), $limiter);
		}
		catch (ThrottleRequestsException $e) {
			return $e->getResponse() ?? new WP_REST_Response(
				['code' => 'too_many_requests', 'message' => 'Quá nhiều yêu cầu, thử lại sau ' . $e->retryAfter() . ' giây.'],
				429,
				$e->getHeaders()
			);
		}
	};
}

add_action('rest_api_init', function () {
	register_rest_route('plugin-cua-toi/v1', '/tours', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => plugin_throttled('api', fn(WP_REST_Request $r) => plugin_search_tours($r->get_param('q'))),
	]);
});
```

Trả `WP_REST_Response` (thay vì `WP_Error`) để header `Retry-After` thật sự được gửi về client.

### Băm key

Mặc định key của named limiter được băm `md5`. Muốn xem key dạng chữ khi debug:

```php
ThrottleRequests::shouldHashKeys(false);   // key dạng "api:ip:1.2.3.4"
```

---

## 8. Trường hợp thực tế

Các ví dụ giả định đã có:

```php
use WPSPLITE\App\Widen\Support\Facades\RateLimiter;
use WPSPCORELITE\App\Cache\RateLimiting\Limit;

function plugin_client_ip(): string {
	return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}
```

### 8.1 Chống dò mật khẩu khi đăng nhập WordPress

Khoá 15 phút sau 5 lần sai, tính theo cặp tên đăng nhập và IP. Đăng nhập đúng thì xoá bộ đếm.

```php
function plugin_login_key(string $username): string {
	return 'login:' . strtolower(trim($username)) . '|' . plugin_client_ip();
}

// Priority 99: chạy sau khi WordPress đã kiểm tra mật khẩu (priority 20), để ghi đè kết quả.
add_filter('authenticate', function ($user, $username) {
	if ($username && RateLimiter::tooManyAttempts(plugin_login_key($username), 5)) {
		$minutes = (int) ceil(RateLimiter::availableIn(plugin_login_key($username)) / 60);
		return new WP_Error('too_many_attempts', "Sai quá nhiều lần. Thử lại sau {$minutes} phút.");
	}
	return $user;
}, 99, 2);

add_action('wp_login_failed', function (string $username) {
	RateLimiter::hit(plugin_login_key($username), 15 * MINUTE_IN_SECONDS);
});

add_action('wp_login', function (string $username) {
	RateLimiter::clear(plugin_login_key($username));
});
```

### 8.2 Gửi OTP / SMS

Tối đa 3 lần mỗi 10 phút cho mỗi số điện thoại, và 10 lần mỗi giờ cho mỗi IP (chặn một người dò nhiều số).

```php
function plugin_send_otp(string $phone): array {
	$byPhone = 'otp:phone:' . preg_replace('/\D/', '', $phone);
	$byIp    = 'otp:ip:' . plugin_client_ip();

	if (RateLimiter::tooManyAttempts($byIp, 10)) {
		return ['ok' => false, 'message' => 'Quá nhiều yêu cầu từ mạng của bạn.'];
	}

	$sent = RateLimiter::attempt($byPhone, 3, fn() => plugin_sms_gateway_send($phone), 10 * MINUTE_IN_SECONDS);

	if ($sent === false) {
		return ['ok' => false, 'message' => 'Thử lại sau ' . RateLimiter::availableIn($byPhone) . ' giây.'];
	}

	RateLimiter::hit($byIp, HOUR_IN_SECONDS);

	return ['ok' => true, 'left' => RateLimiter::remaining($byPhone, 3)];
}
```

### 8.3 Form liên hệ qua admin-ajax

```php
add_action('wp_ajax_nopriv_plugin_contact', 'plugin_handle_contact');
add_action('wp_ajax_plugin_contact', 'plugin_handle_contact');

function plugin_handle_contact() {
	check_ajax_referer('plugin_contact');

	$key = 'contact:' . plugin_client_ip();

	if (RateLimiter::tooManyAttempts($key, 3)) {
		wp_send_json_error([
			'message' => 'Bạn đã gửi nhiều lần, vui lòng chờ ' . RateLimiter::availableIn($key) . ' giây.',
		], 429);
	}

	RateLimiter::hit($key, HOUR_IN_SECONDS);

	plugin_store_contact($_POST);
	wp_send_json_success();
}
```

### 8.4 REST API: user đăng nhập được nhiều hơn khách

```php
RateLimiter::for('api', function ($request) {
	$userId = get_current_user_id();

	return $userId
		? Limit::perMinute(300)->by('user:' . $userId)
		: Limit::perMinute(30)->by('ip:' . plugin_client_ip());
});

// register_rest_route(..., ['callback' => plugin_throttled('api', ...)]) — xem mục 7
```

### 8.5 Gói dịch vụ: giới hạn theo plan

```php
RateLimiter::for('reports', function ($request) {
	$userId = get_current_user_id();

	return match (get_user_meta($userId, 'plan', true)) {
		'pro'      => Limit::none(),
		'business' => Limit::perDay(500)->by('user:' . $userId),
		default    => Limit::perDay(20)->by('user:' . $userId)->response(
			fn($request, $headers) => new WP_REST_Response(
				['message' => 'Gói miễn phí chỉ có 20 báo cáo mỗi ngày. Nâng cấp để dùng thêm.'],
				429,
				$headers
			)
		),
	};
});
```

### 8.6 Nhiều tầng giới hạn cùng lúc

Tải file: tối đa 10 lần mỗi phút **và** 200 lần mỗi ngày mỗi user, cộng một trần chung 1000 lần mỗi phút cho toàn site.

```php
RateLimiter::for('downloads', fn($request) => [
	Limit::perMinute(1000),                                            // chung cho mọi người
	Limit::perMinute(10)->by('user:' . get_current_user_id()),
	Limit::perDay(200)->by('user:' . get_current_user_id()),
]);
```

### 8.7 Chỉ đếm khi thất bại (`after`)

Mã giảm giá: chỉ những lần nhập **sai** mới bị tính, tối đa 5 lần sai mỗi 10 phút.

```php
RateLimiter::for('coupon', fn($request) => Limit::perMinutes(10, 5)
	->by('coupon:' . plugin_client_ip())
	->after(fn($response) => $response instanceof WP_REST_Response && $response->get_status() === 422)
);
```

### 8.8 Tôn trọng hạn mức của API bên ngoài

Nhà cung cấp chỉ cho 100 request mỗi phút cho cả site. Dùng một key chung và chờ khi hết lượt.

```php
function plugin_call_supplier(string $path): ?array {
	$key = 'supplier-api';

	for ($try = 0; $try < 3; $try++) {
		if (!RateLimiter::tooManyAttempts($key, 100)) {
			RateLimiter::hit($key, 60);
			$res = wp_remote_get('https://api.supplier.example' . $path, ['timeout' => 10]);
			return is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
		}

		sleep(min(5, max(1, RateLimiter::availableIn($key))));
	}

	return null;   // vẫn hết lượt: để cron lần sau xử lý
}
```

### 8.9 Email đặt lại mật khẩu

```php
add_filter('lostpassword_errors', function (WP_Error $errors, $user) {
	if ($user instanceof WP_User) {
		$key = 'lostpassword:' . $user->ID;

		if (RateLimiter::tooManyAttempts($key, 3)) {
			$errors->add('too_many_requests', 'Bạn đã yêu cầu quá nhiều lần, thử lại sau 1 giờ.');
		}
		else {
			RateLimiter::hit($key, HOUR_IN_SECONDS);
		}
	}

	return $errors;
}, 10, 2);
```

### 8.10 Bình luận

```php
add_filter('pre_comment_approved', function ($approved, array $data) {
	$key = 'comment:' . ($data['user_id'] ?: plugin_client_ip());

	if (RateLimiter::tooManyAttempts($key, 5)) {
		return new WP_Error('comment_flood', 'Bạn bình luận quá nhanh.', 429);
	}

	RateLimiter::hit($key, 10 * MINUTE_IN_SECONDS);

	return $approved;
}, 10, 2);
```

### 8.11 Giới hạn chặt tuyệt đối (không lọt request nào)

`attempt()` kiểm tra rồi mới tính hit, nên hai request đến đúng cùng lúc có thể cùng lọt qua (xem mục 9). Khi cần chặt tuyệt đối (thanh toán, phát voucher), hãy tính hit trước rồi so sánh, vì `hit()` atomic và trả về số thứ tự của chính request đó:

```php
$key = 'claim-voucher:' . $voucherId;

if (RateLimiter::hit($key, DAY_IN_SECONDS) > 100) {
	wp_send_json_error('Voucher đã hết lượt.', 429);
}

plugin_issue_voucher($voucherId, get_current_user_id());
```

### 8.12 Admin mở khoá cho user

```php
add_action('admin_post_plugin_unlock_user', function () {
	check_admin_referer('plugin_unlock_user');

	if (!current_user_can('manage_options')) {
		wp_die('Không có quyền.');
	}

	$user = get_userdata((int) $_POST['user_id']);

	foreach (['login:' . strtolower($user->user_login) . '|' . sanitize_text_field($_POST['ip'])] as $key) {
		RateLimiter::clear($key);
	}

	wp_safe_redirect(wp_get_referer());
	exit;
});
```

---

## 9. Tính atomic, giới hạn và lưu ý

- **Đếm atomic:** `hit()`, `increment()` và `decrement()` an toàn giữa nhiều request đồng thời trên `transient`, `object` (có Redis/Memcached drop-in) và `file`. Trên `phpfastcache` thì không.
- **`attempt()` và `tooManyAttempts()` + `hit()`** có một khe nhỏ giữa lúc kiểm tra và lúc tính hit (Laravel cũng vậy): vài request đến đúng cùng lúc có thể vượt giới hạn một chút. Với chống spam hay brute force thì không sao; khi cần chặt tuyệt đối, dùng mẫu ở mục 8.11.
- **Fixed window:** giới hạn 60/phút có thể cho tối đa khoảng 120 request trong 2 giây sát ranh giới hai cửa sổ. Cần mượt hơn thì dùng cửa sổ ngắn hơn, ví dụ `perSecond(1)` thay cho `perMinute(60)`.
- **IP sau proxy/CDN:** sau Cloudflare hay một reverse proxy, `REMOTE_ADDR` là IP của proxy, nên mọi người dùng sẽ chung một bộ đếm. Hãy lấy IP thật từ header mà proxy của bạn đặt (`CF-Connecting-IP`, `X-Forwarded-For`), **chỉ khi** request thực sự đi qua proxy đó.
- **Key nên có tiền tố hành động** (`login:`, `otp:`), để hai tính năng không dùng chung bộ đếm.
- **Không cần thêm prefix plugin vào key:** cache của plugin đã tự tách.
- **Store `array` hoặc `null`** cho `limiter` sẽ làm giới hạn vô hiệu.
- **Đồng hồ:** thời điểm hết hạn dùng `time()` của server; các server khác nhau trong cùng cụm phải đồng bộ giờ (NTP).

---

## 10. Tra cứu nhanh

| Method | Trả về | Mục |
| --- | --- | --- |
| `RateLimiter::attempt($key, $max, fn, $decay = 60)` | `false` hoặc kết quả closure (`true` nếu `null`) | 4 |
| `RateLimiter::tooManyAttempts($key, $max)` | `bool` | 4 |
| `RateLimiter::hit($key, $decay = 60)` | số lần sau khi tăng | 4 |
| `RateLimiter::increment($key, $decay = 60, $amount = 1)` | số lần sau khi tăng | 4 |
| `RateLimiter::decrement($key, $decay = 60, $amount = 1)` | số lần sau khi giảm | 4 |
| `RateLimiter::attempts($key)` | `int` | 4 |
| `RateLimiter::remaining($key, $max)` / `retriesLeft` | `int` | 4 |
| `RateLimiter::availableIn($key)` | giây còn lại | 4 |
| `RateLimiter::resetAttempts($key)` | `bool` | 4 |
| `RateLimiter::clear($key)` | `void` | 4 |
| `RateLimiter::cleanRateLimiterKey($key)` | `string` | 4 |
| `RateLimiter::getCache()` | Repository | 4 |
| `RateLimiter::for($name, fn($request) => Limit\|Limit[]\|response)` | RateLimiter | 6 |
| `Route::middleware([['throttle:30rpm']])` | middleware route | 7.1 |
| `RateLimiter::limiter($name)` | `Closure\|null` | 6 |
| `RateLimiter::limiters()` | tên các limiter | 6 |
| `RateLimiter::getFacadeRoot()` / `swap($limiter)` | facade | 3 |
| `Limit::perSecond / perMinute / perMinutes / perHour / perDay / none` | Limit | 5 |
| `->by($key)` / `->response(fn)` / `->after(fn)` | Limit | 5 |
| `(new ThrottleRequests($limiter))->handle($req, $next, ...)` | response hoặc ném 429 | 7 |
| `ThrottleRequests::using($name)` / `with($max, $minutes, $prefix)` | chuỗi middleware | 7 |
| `ThrottleRequests::shouldHashKeys(bool)` | `void` | 7 |
| `$app->make('rate.limiter')` | RateLimiter của plugin | 3 |
