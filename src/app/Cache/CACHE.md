# Hướng dẫn sử dụng Cache — WPSPCORELITE

Cập nhật: 2026-10-18

## 1. Tổng quan

Bộ Cache mô phỏng `Illuminate\Cache` của Laravel 12, không cần Illuminate hay Symfony. Code gọi `Cache::...` giữ nguyên dù store là transient, file, object cache hay phpfastcache: chỉ đổi `'default'` trong `config/cache.php`.

| Class | Vai trò | Tương đương Laravel |
| --- | --- | --- |
| `{PLUGIN}\App\Widen\Support\Facades\Cache` | Facade gọi static trong plugin | `Illuminate\Support\Facades\Cache` |
| `{PLUGIN}\App\Widen\Cache\Cache` | Object cache của plugin, đọc `config/cache.php` | binding `'cache'` |
| `WPSPCORELITE\App\Cache\CacheManager` | Tạo và giữ các store theo config | `CacheManager` |
| `WPSPCORELITE\App\Cache\Repository` | API dùng hằng ngày: `get`, `put`, `remember`... | `Repository` |
| `WPSPCORELITE\App\Cache\TaggedCache` | Kết quả của `Cache::tags(...)` | `TaggedCache` |
| `WPSPCORELITE\App\Cache\Stores\*` | Nơi lưu thật: Transient, ObjectCache, File, PhpFastCache, Array, Null, Failover, Memoized | các `*Store` |
| `WPSPCORELITE\App\Cache\Locks\*` | Atomic lock | `Lock`, `CacheLock`... |
| `WPSPCORELITE\App\Cache\Events\*` | Event: `CacheHit`, `KeyWritten`... | `Illuminate\Cache\Events\*` |

Mỗi plugin tự cách ly bằng `prefix` trong config. Mọi tên khác đều suy ra từ đó, nên hai plugin do hai người viết không bao giờ đè hay xoá cache của nhau, kể cả khi dùng chung Redis database 0 hoặc chung thư mục.

| Thứ được tạo ra | Với `prefix` = `plugin_a_` |
| --- | --- |
| Key transient | `_transient_plugin_a_.{key}` |
| Lock (wp_options) | `plugin_a_.lock.{name}` |
| Group object cache | `plugin_a_cache`, `plugin_a_cache_locks` |
| Key Redis / Memcached | `plugin_a_.{version}.{key}` |
| Thư mục file | `{path}/plugin_a/data`, `{path}/plugin_a/files` |
| Hook event | `plugin_a_cache`, `plugin_a_cache/cache_hit`... |

Dấu `.` sau prefix là ranh giới: prefix chỉ được chứa `A-Z a-z 0-9 _ -`, nên `plugin_a_.` không bao giờ khớp `plugin_a_pro_.`. `Cache::flush()` của plugin này chỉ xoá cache của chính nó.

## 2. Cài đặt trong một plugin

Plugin được tạo từ `wpsp-lite` bằng `composer create-project`, và đã require sẵn `wpsp-core-lite`:

```bash
composer create-project wpsp-lite plugin-cua-toi
```

Plugin mới đã có sẵn `app/Widen/Cache/Cache.php` (Cache của plugin, đọc `config/cache.php`) và `app/Widen/Support/Facades/Cache.php` (facade), với namespace của plugin. Bạn không cần viết thêm class nào. `Application` của plugin tự bind `cache` (CacheManager) và `cache.store` (store mặc định) từ `config/cache.php`, và facade dùng chính binding đó.

**Cách ly giữa các plugin là tự động:** khi `prefix` để `null` (mặc định của template), prefix được lấy theo tên thư mục plugin, ví dụ `plugins/plugin-cua-toi` → `plugin_cua_toi_`, `plugins/wpsp-lite` → `wpsp_lite_`. Một site WordPress không thể có hai plugin trùng thư mục, nên mọi plugin tạo từ cùng template vẫn có key, lock và thư mục cache riêng. Chỉ đặt `prefix` khi muốn cố định một giá trị.

**Config** (`plugin-cua-toi/config/cache.php`):

```php
<?php

return [

	'default' => 'transient',

	'stores' => [
		'transient' => ['driver' => 'transient'],
		'object'    => ['driver' => 'object'],
		'file'      => ['driver' => 'file'],
		'array'     => ['driver' => 'array'],
		'null'      => ['driver' => 'null'],
	],

	'prefix' => null,      // null = theo tên thư mục plugin

	'class' => \WPSPLITE\App\Widen\Cache\Cache::class,   // class được bind làm 'cache'

	'events' => false,

];
```

| Khoá | Giá trị | Ghi chú |
| --- | --- | --- |
| `default` | tên một store trong `stores` | Store dùng khi gọi `Cache::get()` không chỉ định store. Không có chế độ `auto`. |
| `stores` | mảng `tên => ['driver' => ..., ...]` | Tên tự đặt; một driver có thể dùng cho nhiều store (ví dụ `phpfastcache` cho cả `files` và `redis`). |
| `prefix` | `null` hoặc chuỗi | `null` = theo tên thư mục plugin (khuyến nghị). Ký tự ngoài `A-Z a-z 0-9 _ -` bị bỏ. |
| `class` | tên class | Class `Application` bind làm `'cache'`; phải kế thừa `WPSPCORELITE\App\Cache\Cache`. Bỏ trống = class core. |
| `events` | `false` / `true` / tên hook | Xem mục 11. |
| `limiter` | `null` hoặc tên store | Store giữ bộ đếm của RateLimiter (`null` = `default`). Xem `RATE_LIMITER.md`. |
| `limiter_class` | tên class | Class `Application` bind làm `'rate.limiter'`; phải kế thừa `WPSPCORELITE\App\Cache\RateLimiter`. |

Tuỳ chọn riêng của từng store:

| Driver | Tuỳ chọn | Mặc định | Ý nghĩa |
| --- | --- | --- | --- |
| `transient` | `network` | `false` | `true` = site transient, dùng chung cho cả mạng multisite |
| `transient` | `atomic_increments` | `true` | `increment`/`decrement` chạy dưới lock ngắn |
| `transient` | `lock_lottery` | `[2, 100]` | Xác suất dọn lock hết hạn mỗi lần lấy lock |
| `transient` | `lock_timeout` | `86400` | Thời gian sống tối đa của lock tạo với 0 giây |
| `transient` | `lock_group` | `{base}_cache_locks` | Group lock khi site có object cache |
| `object` | `group`, `lock_group` | `{base}_cache`, `{base}_cache_locks` | Group trong `wp_cache_*` |
| `file` | `path` | `wp-content/cache` | Thư mục gốc; dữ liệu nằm ở `{path}/{base}/data` |
| `file` | `lock_path`, `permission` | như `path`, `null` | Thư mục lock (`{lock_path}/{base}/locks`), quyền file (ví dụ `0644`) |
| `phpfastcache` | `phpfastcache_driver` | `Files` | `Files`, `Sqlite`, `Redis`, `Predis`, `Memcached`, `Apcu`... |
| `phpfastcache` | `options` | `[]` | Config của driver phpfastcache (`host`, `port`, `database`, `path`...) |
| `phpfastcache` | `pool`, `instance_id`, `purge_on_flush` | `null`, `null`, `true` | Pool tự tạo, id instance, xoá key cũ khi flush |
| `array` | `serialize` | `false` | Serialize giá trị như store thật |
| `failover` | `stores` | bắt buộc | Danh sách store thử lần lượt |
| mọi store | `ttl` | `3600` | TTL mặc định cho `$cache['key'] = ...` và `tags()` |
| mọi store | `finish_request` | `false` | `flexible()` gửi response trước khi làm mới (PHP-FPM) |
| mọi store | `prefix` | prefix chung | Ghi đè prefix cho riêng store này |
| mọi store | `events` | `true` | `false` = store này không phát event |

`{base}` là prefix bỏ dấu `_` cuối: `plugin_a_` → `plugin_a`.

## 3. Cách gọi Cache

Cách thường dùng là facade của plugin; mọi method của `CacheManager` và `Repository` đều gọi static được qua nó.

```php
use PLUGINA\App\Widen\Support\Facades\Cache;

Cache::put('key', 'value', 600);        // store mặc định ('default' trong config)
Cache::store('file')->put('key', 1, 60); // một store cụ thể
Cache::driver('file');                   // giống store()
Cache::memo()->get('key');               // store mặc định, có nhớ trong request
Cache::memo('file')->get('key');
```

`Cache::store($name)` trả về một `Repository`; mọi ví dụ dạng `Cache::xxx()` trong tài liệu này đều dùng được trên `Cache::store('...')->xxx()` và `Cache::tags(...)->xxx()`.

**Lấy từ container (Application của plugin):**

```php
$cache = $app->make('cache');                                   // CacheManager của plugin
$cache = $app->make(\WPSPCORELITE\App\Cache\CacheManager::class); // cùng object (alias)
$store = $app->make('cache.store');                             // Repository của store mặc định
$store = $app->make(\WPSPCORELITE\App\Cache\Repository::class);   // cùng object (alias)
```

Có thể type-hint `CacheManager`, `Contracts\Factory`, `Repository` hoặc `Contracts\Repository` trong constructor của service hay controller để container tự inject.

**Lấy object để truyền đi hoặc dùng không qua facade:**

```php
$cache = Cache::getFacadeRoot();                  // PLUGINA\App\Widen\Cache\Cache (CacheManager)
$repo  = Cache::store();                          // Repository của store mặc định

$cache = new \PLUGINA\App\Widen\Cache\Cache(
	\PLUGINA\Funcs::instance()->_getMainPath(), 'PLUGINA', 'PLUGINA_', []
);

$cache = \WPSPCORELITE\App\Cache\Cache::make([   // không cần file config
	'default' => 'array',
	'prefix'  => 'tmp_',
]);
```

**`Cache::memo()`** giữ mọi giá trị đã đọc trong bộ nhớ đến hết request: gọi `get()` cùng key 10 lần chỉ chạm DB hoặc Redis một lần. Ghi qua memo (`put`, `forget`, `increment`...) xoá giá trị đã nhớ của key đó. Memo không phát event.

### TTL

Mọi tham số `$ttl` nhận:

| Kiểu | Ví dụ | Ý nghĩa |
| --- | --- | --- |
| `int` | `600` | số giây |
| `DateInterval` | `new DateInterval('PT10M')` | khoảng thời gian |
| `DateTimeInterface` | `new DateTimeImmutable('tomorrow')` | hết hạn tại thời điểm đó |
| `null` | `Cache::put('k', $v)` | vĩnh viễn (như `forever`) |
| `0`, số âm, thời điểm đã qua | `Cache::put('k', $v, 0)` | xoá key, không lưu |

### Key

Key là chuỗi, hoặc enum: `BackedEnum` dùng `->value`, `UnitEnum` dùng `->name`.

```php
enum CacheKey: string {
	case Settings = 'settings';
	case Plans    = 'plans';
}

Cache::put(CacheKey::Settings, $data, 600);
Cache::get(CacheKey::Settings);   // giống Cache::get('settings')
```

Key dài hoặc có ký tự đặc biệt được tự hash khi cần, nên có thể dùng key dạng `'user:42:orders'` hay `'blc_backlinks_' . md5($domain)`.

## 4. Đọc dữ liệu

Quy tắc chung, giống Laravel: **`null` nghĩa là "không có"**. Mọi giá trị khác, kể cả `false`, `0`, `''` và `[]`, đều được lưu và đọc lại đúng kiểu.

### `get($key, $default = null)`

```php
$settings = Cache::get('settings');                    // null nếu không có
$settings = Cache::get('settings', []);                // giá trị mặc định
$settings = Cache::get('settings', fn() => loadDefaults()); // closure chỉ chạy khi miss
$values   = Cache::get(['a', 'b']);                    // mảng key => giống many()
```

Tham số `$default` dạng closure chỉ được gọi khi miss và **không** được lưu vào cache. Muốn vừa tính vừa lưu, dùng `remember()` (mục 6).

### `many(array $keys)`

Đọc nhiều key một lần, trả về mảng `key => value`. Key nào thiếu thì nhận `null`, hoặc giá trị mặc định nếu truyền dạng `key => default`.

```php
$data = Cache::many(['price', 'stock', 'rating' => 0]);
// ['price' => 120, 'stock' => null, 'rating' => 0]
```

Trên transient, `many()` gom việc nạp các option thành **một truy vấn** (`wp_prime_option_caches`), nhanh hơn gọi `get()` từng key. Trên object cache, nó dùng `wp_cache_get_multiple`; trên phpfastcache, `getItems`.

### `getMultiple(iterable $keys, $default = null)`

Phiên bản PSR-16: mọi key thiếu nhận chung một giá trị mặc định.

```php
Cache::getMultiple(['a', 'b'], 'n/a');   // ['a' => 1, 'b' => 'n/a']
```

### `has($key)` / `missing($key)`

```php
if (Cache::has('report_ready')) { ... }      // true nếu giá trị khác null
if (Cache::missing('report_ready')) { ... }  // ngược lại
```

`has()` đọc giá trị thật. Nếu sau đó còn cần giá trị, gọi thẳng `get()` để khỏi đọc hai lần.

### `pull($key, $default = null)`

Đọc rồi xoá ngay, hợp cho dữ liệu dùng một lần: thông báo flash, token xác nhận, kết quả import.

```php
$notice = Cache::pull('admin_notice_' . get_current_user_id());
```

### Đọc có kiểm tra kiểu (Laravel 12)

Sai kiểu sẽ ném `WPSPCORELITE\App\Cache\Exceptions\InvalidArgumentException`; dùng khi bạn chắc chắn về kiểu và muốn lỗi lộ ra sớm.

| Method | Chấp nhận | Ví dụ |
| --- | --- | --- |
| `string($key, $default)` | chỉ `string` | `Cache::string('api_token')` |
| `integer($key, $default)` | `int` hoặc chuỗi số nguyên (`'42'`) | `Cache::integer('api_calls', 0)` |
| `float($key, $default)` | `float`, `int`, chuỗi số | `Cache::float('rate', 1.0)` |
| `boolean($key, $default)` | chỉ `bool` | `Cache::boolean('maintenance', false)` |
| `array($key, $default)` | chỉ `array` | `Cache::array('settings', [])` |

### ArrayAccess

```php
$cache = Cache::store();
$cache['greeting'] = 'xin chào';   // put với TTL mặc định của store (3600s, đổi bằng 'ttl')
echo $cache['greeting'];
isset($cache['greeting']);
unset($cache['greeting']);
```

## 5. Ghi dữ liệu

Mọi method ghi trả về `bool` (riêng `increment`/`decrement` trả về số mới). Giá trị có thể là scalar, mảng hoặc object serialize được; **closure và resource thì không lưu được**.

### `put($key, $value, $ttl = null)` / `set(...)`

```php
Cache::put('settings', $settings, 600);                  // 10 phút
Cache::put('settings', $settings, new DateInterval('PT1H'));
Cache::put('daily_report', $html, new DateTimeImmutable('tomorrow'));
Cache::put('settings', $settings);                       // không có TTL = vĩnh viễn
Cache::put(['a' => 1, 'b' => 2], 300);                   // mảng = putMany()
Cache::set('settings', $settings, 600);                  // tên PSR-16, giống put()
```

### `putMany(array $values, $ttl = null)` / `setMultiple(...)`

```php
Cache::putMany([
	'product_12_price' => 120,
	'product_12_stock' => 7,
], 300);
```

### `add($key, $value, $ttl = null)`

Chỉ ghi khi key **chưa có**, trả `true` nếu ghi được. Có TTL thì thao tác là **atomic** trên `transient` (`INSERT IGNORE` hoặc `wp_cache_add`), `object` (`wp_cache_add`) và `file` (flock): giữa nhiều request đồng thời, chỉ đúng một request nhận `true`.

```php
if (Cache::add('welcome_mail_sent_' . $userId, 1, DAY_IN_SECONDS)) {
	wp_mail($email, 'Chào mừng', $body);   // chỉ gửi một lần dù hook chạy hai lần
}
```

Không truyền TTL thì `add()` đọc rồi ghi, nên **không** atomic. Trên phpfastcache, `add()` cũng không atomic; cần chắc chắn thì dùng `lock()` (mục 9).

### `forever($key, $value)`

Lưu không thời hạn. Trên transient khi site không có object cache, "vĩnh viễn" là 10 năm, để WordPress không autoload dòng đó ở mọi request. Trên phpfastcache cũng là 10 năm, thay vì TTL mặc định 900 giây của phpfastcache.

```php
Cache::forever('countries', $countries);
```

### `increment($key, $value = 1)` / `decrement($key, $value = 1)`

Trả về giá trị mới. Key chưa có thì được tạo vĩnh viễn với giá trị bằng bước tăng (hoặc âm bước giảm). Key có TTL thì **giữ nguyên hạn cũ**.

```php
Cache::increment('api_calls');           // 1
Cache::increment('api_calls', 5);        // 6
Cache::decrement('credits_' . $userId);  // giảm 1
```

| Store | Atomic giữa nhiều request? |
| --- | --- |
| `transient` | Có, chạy dưới lock ngắn (`atomic_increments` = `true`) |
| `object` | Có (`wp_cache_incr`). WordPress core không cho giảm dưới 0 |
| `file` | Có (flock) |
| `phpfastcache` | Không |

### `touch($key, $ttl = null)`

Gia hạn một key đang có mà không ghi lại giá trị. Không có TTL thì chuyển thành vĩnh viễn. Trả `false` nếu key không tồn tại.

```php
Cache::touch('session_cart_' . $cartId, 1800);   // còn 30 phút kể từ bây giờ
```

## 6. Remember: đọc, nếu thiếu thì tính và lưu

Đây là nhóm hàm dùng nhiều nhất. Closure chỉ chạy khi cache không có giá trị; kết quả được lưu và trả về.

### `remember($key, $ttl, Closure $callback)`

```php
$posts = Cache::remember('home_featured_posts', 600, function () {
	return get_posts(['numberposts' => 6, 'meta_key' => 'featured', 'meta_value' => 1]);
});
```

Nếu closure trả về `null`, giá trị đó coi như không có: lần gọi sau closure sẽ chạy lại. Đây là cách **không cache lỗi**:

```php
$rates = Cache::remember('exchange_rates', 3600, function () {
	$response = wp_remote_get('https://api.example.com/rates', ['timeout' => 5]);

	if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
		return null;   // không lưu, request sau sẽ thử lại
	}

	return json_decode(wp_remote_retrieve_body($response), true);
}) ?? [];
```

TTL có thể là closure nhận giá trị vừa tính, để chọn thời hạn theo kết quả:

```php
$data = Cache::remember('api_data', fn($value) => empty($value) ? 60 : 3600, fn() => fetchData());
```

### `rememberForever($key, Closure $callback)` / `sear(...)`

Giống `remember()` nhưng lưu vĩnh viễn. Dùng cho dữ liệu chỉ đổi khi bạn chủ động xoá, ví dụ danh sách tỉnh thành hoặc cấu hình build sẵn.

```php
$provinces = Cache::rememberForever('provinces', fn() => require __DIR__ . '/data/provinces.php');
```

### `flexible($key, [$fresh, $stale], $callback, $lock = null)`

Kiểu stale-while-revalidate (Laravel 11+). Người dùng gần như không bao giờ phải chờ việc tính lại:

| Tuổi của giá trị | Kết quả |
| --- | --- |
| nhỏ hơn `$fresh` | trả giá trị trong cache |
| từ `$fresh` đến `$stale` | trả **giá trị cũ ngay**, rồi tính lại sau khi trang đã chạy xong (hook `shutdown`) |
| lớn hơn `$stale`, hoặc chưa có | tính ngay, như `remember()` |

```php
// Tươi 5 phút; từ phút 5 đến phút 15 trả bản cũ và làm mới phía sau
$stats = Cache::flexible('dashboard_stats', [300, 900], fn() => buildDashboardStats());
```

Việc làm mới chạy trong lock, nên nhiều request cùng lúc chỉ có một request tính lại. Tham số `$lock` (`['seconds' => 30]`) đặt thời hạn cho lock này.

Mặc định, việc làm mới chạy ở `shutdown` **trước** khi response gửi xong. Đặt `'finish_request' => true` trong config của store (PHP-FPM) để gửi response cho trình duyệt trước, rồi mới tính lại.

## 7. Xoá

### `forget($key)` / `delete($key)`

Xoá một key. Trả `true` nếu thật sự đã xoá; với transient và object cache, key không tồn tại thì trả `false`.

```php
add_action('update_option_plugin_a_settings', function () {
	Cache::forget('settings');
});
```

### `deleteMultiple(iterable $keys)`

```php
Cache::deleteMultiple(['product_12_price', 'product_12_stock']);
```

### `flush()` / `clear()`

Xoá **toàn bộ cache của plugin này** trên store đang dùng. Plugin khác không bị ảnh hưởng:

| Store | `flush()` làm gì |
| --- | --- |
| `transient` (DB) | `DELETE` các dòng `_transient_{prefix}.%` của plugin |
| `transient` (có object cache), `object` | Đổi namespace version (hoặc `wp_cache_flush_group` nếu được hỗ trợ) |
| `file` | Xoá các thư mục con trong `{path}/{base}/data` |
| `phpfastcache` Files / Sqlite | `clear()` thư mục riêng `{path}/{base}/...` |
| `phpfastcache` Redis / Predis | Đổi version, rồi `SCAN` xoá đúng key cũ của plugin; **không bao giờ `FLUSHDB`** |
| `phpfastcache` Memcached / Apcu | Đổi version; key cũ không còn đọc được và tự hết hạn |

```php
Cache::flush();                     // store mặc định
Cache::store('file')->flush();      // một store cụ thể
Cache::tags('products')->flush();   // chỉ những item mang tag products (mục 8)
```

Nên đặt `flush()` ở nút "Xoá cache" trong trang cài đặt, khi plugin cập nhật phiên bản, hoặc khi deactivate:

```php
register_deactivation_hook(__FILE__, function () {
	Cache::flush();
});
```

## 8. Tags

Tags gom các item liên quan để xoá cùng lúc mà không cần nhớ từng key. Khác Laravel (chỉ `array`, `redis`, `memcached` có tags), ở đây **mọi store đều hỗ trợ tags**, nên đổi store không phải sửa code.

```php
// Ghi
Cache::tags(['products', 'category_' . $catId])->put('list_page_1', $html, 3600);
Cache::tags('products', 'category_' . $catId)->put('list_page_2', $html2, 3600); // variadic cũng được

// Đọc: phải dùng ĐÚNG tập tags và ĐÚNG thứ tự như khi ghi
$html = Cache::tags(['products', 'category_' . $catId])->get('list_page_1');

// Mọi method của Repository đều có trên tagged cache
Cache::tags('products')->remember('bestsellers', 600, fn() => getBestsellers());
Cache::tags('stats')->increment('views');

// Xoá mọi item có tag 'products' (kể cả item ghi với ['products', 'category_5'])
Cache::tags('products')->flush();

// Chỉ xoá trang của một danh mục
Cache::tags('category_' . $catId)->flush();
```

Cách hoạt động, giống `TagSet` của Laravel: mỗi tag có một id ngẫu nhiên, và key thật của item được tạo từ các id đó. `flush()` một tag chỉ đổi id của tag đó, nên mọi item mang tag ấy lập tức không còn tìm thấy và tự hết hạn theo TTL. Chi phí là thêm một lần đọc id cho mỗi tag mỗi lần gọi.

Lưu ý:

- `tags(['a', 'b'])` và `tags(['b', 'a'])` là **hai không gian khác nhau**. Hãy giữ thứ tự cố định, ví dụ đặt tag chung trước, tag riêng sau.
- Item ghi qua `tags()` không đọc được bằng `Cache::get()` thường, và ngược lại.
- Muốn xoá một item cụ thể trong tagged cache thì gọi `forget()` trên đúng tập tags đó: `Cache::tags(['products'])->forget('bestsellers')`.

## 9. Atomic locks

Lock bảo đảm một đoạn code chỉ chạy ở **một request tại một thời điểm**, ví dụ import, đồng bộ đơn hàng hay cron nặng, dù WP-Cron, admin và webhook có thể kích hoạt cùng lúc.

```php
use WPSPCORELITE\App\Cache\Exceptions\LockTimeoutException;

$lock = Cache::lock('import_products', 120);   // tên, số giây tối đa giữ lock
```

| Method | Tác dụng |
| --- | --- |
| `get()` | Thử lấy lock ngay; trả `true`/`false` |
| `get(fn() => ...)` | Lấy được thì chạy closure, **tự release** sau đó (kể cả khi closure ném lỗi) và trả kết quả closure; không lấy được thì trả `false` |
| `block($seconds)` | Chờ tối đa `$seconds` giây cho đến khi lấy được; quá hạn thì ném `LockTimeoutException` |
| `block($seconds, fn() => ...)` | Chờ, chạy closure, tự release |
| `release()` | Nhả lock, chỉ khi mình là chủ; trả `bool` |
| `forceRelease()` | Nhả bất kể chủ là ai |
| `owner()` | Chuỗi định danh chủ lock (16 ký tự ngẫu nhiên, hoặc giá trị bạn truyền) |
| `isOwnedByCurrentProcess()` | Lock còn thuộc về object này không |
| `betweenBlockedAttemptsSleepFor($ms)` | Khoảng nghỉ giữa các lần thử của `block()` (mặc định 250 ms) |
| `Cache::restoreLock($name, $owner)` | Dựng lại lock ở request khác để release |

**Cách 1 — bỏ qua nếu đang chạy:**

```php
$result = Cache::lock('sync_orders', 300)->get(function () {
	return syncOrdersFromApi();
});

if ($result === false) {
	// một request khác đang đồng bộ, bỏ qua lần này
}
```

**Cách 2 — chờ đến lượt:**

```php
try {
	Cache::lock('generate_invoice_' . $orderId, 30)->block(5, function () use ($orderId) {
		generateInvoice($orderId);
	});
} catch (LockTimeoutException $e) {
	wp_die('Hoá đơn đang được tạo, vui lòng thử lại.');
}
```

**Cách 3 — lấy lock ở request này, nhả ở request khác:**

```php
// Request 1: bắt đầu export, giao việc cho cron
$lock = Cache::lock('export_csv', 600);
if ($lock->get()) {
	wp_schedule_single_event(time(), 'plugin_a_export_csv', [$lock->owner()]);
}

// Request 2 (cron): xong việc thì nhả đúng lock đó
add_action('plugin_a_export_csv', function (string $owner) {
	runExport();
	Cache::restoreLock('export_csv', $owner)->release();
});
```

Độ tin cậy theo store:

| Store | Atomic giữa nhiều request | Lock tạo với 0 giây |
| --- | --- | --- |
| `transient` (DB) | Có (`INSERT IGNORE` vào `wp_options`) | hết hạn sau `lock_timeout` (86400s) |
| `transient` / `object` có Redis/Memcached drop-in | Có (`wp_cache_add`) | không hết hạn |
| `object` không có drop-in | Chỉ trong một request | — |
| `file` | Có (flock) | không hết hạn |
| `phpfastcache` | Không (đọc rồi ghi) | không hết hạn |
| `array` | Chỉ trong một request | — |

Luôn đặt số giây cho lock, lớn hơn thời gian chạy dự kiến, để một request chết giữa chừng không giữ lock mãi. Tên lock tự tách theo plugin: `lock('import')` của Plugin A và Plugin B là hai lock khác nhau.

## 10. Drivers và cách chọn

Plugin phát hành cho người khác cài nên để `'default' => 'transient'`: nó chạy trên mọi host, và tự dùng Redis/Memcached khi site có object-cache drop-in. Chỉ chọn phpfastcache khi plugin có `phpfastcache/phpfastcache` trong `composer.json` **của chính nó**.

| Driver | Lưu ở đâu | Cần gì | Hợp với |
| --- | --- | --- | --- |
| `transient` | `wp_options`, hoặc object cache nếu site có | không | Mặc định cho mọi plugin |
| `object` | `wp_cache_*` (Redis/Memcached drop-in) | persistent object cache | Site chắc chắn có Redis/Memcached drop-in |
| `file` | `wp-content/cache/{base}/data/*.php` | thư mục ghi được | Dữ liệu lớn (HTML, JSON vài trăm KB), không muốn làm phình `wp_options` |
| `phpfastcache` | Files, Sqlite, Redis, Memcached, Apcu... | `composer require phpfastcache/phpfastcache` | Muốn dùng Redis riêng mà không cần drop-in |
| `failover` | thử lần lượt các store | — | Redis chính, transient dự phòng |
| `array` | bộ nhớ của request | — | Test, hoặc cache trong một request |
| `null` | không lưu gì | — | Tắt cache tạm thời để debug |

### Khi KHÔNG có phpfastcache

```php
'default' => 'transient',
'stores'  => [
	'transient' => ['driver' => 'transient'],
	'object'    => ['driver' => 'object'],
	'file'      => ['driver' => 'file'],   // => wp-content/cache/plugin_a/data
	'array'     => ['driver' => 'array'],
	'null'      => ['driver' => 'null'],
],
```

Dùng song song nhiều store trong cùng plugin: store mặc định cho dữ liệu nhỏ, `file` cho dữ liệu lớn:

```php
Cache::remember('settings', 3600, fn() => loadSettings());                          // transient
Cache::store('file')->remember('sitemap_xml', 86400, fn() => buildSitemapXml());    // file
```

Multisite, cache dùng chung cho mọi site trong mạng:

```php
'network' => ['driver' => 'transient', 'network' => true],
// Cache::store('network')->put('license_status', $status, 43200);
```

### Khi CÓ phpfastcache

```bash
cd plugins/plugin-a
composer require phpfastcache/phpfastcache
```

```php
'default' => 'redis',
'stores'  => [
	'transient' => ['driver' => 'transient'],
	'files' => [
		'driver'              => 'phpfastcache',
		'phpfastcache_driver' => 'Files',
		// 'options' => ['path' => WP_CONTENT_DIR . '/cache'],   // => .../cache/plugin_a/files
	],
	'sqlite' => [
		'driver'              => 'phpfastcache',
		'phpfastcache_driver' => 'Sqlite',
	],
	'redis' => [
		'driver'              => 'phpfastcache',
		'phpfastcache_driver' => 'Redis',
		'options'             => [
			'host'     => '127.0.0.1',
			'port'     => 6379,
			'database' => 0,       // dùng chung với plugin khác thoải mái
			'password' => '',
			'timeout'  => 2,
		],
	],
	'failover' => [
		'driver' => 'failover',
		'stores' => ['redis', 'transient'],
	],
],
```

- **Chuyển đổi:** chỉ sửa `'default'` (`'transient'` ↔ `'redis'` ↔ `'files'`...). Code gọi `Cache::...` giữ nguyên.
- **Redis dùng chung:** nhiều plugin có thể cùng host và cùng `database`. `flush()` chỉ xoá key `{prefix}.{version}.*` của plugin mình; không cần, và không nên, đặt `optPrefix`.
- **Files, Sqlite:** thư mục luôn là `{path}/{base}/files` hoặc `.../sqlite`, và `securityKey` mặc định là `{base}`, nên request web và WP-CLI hay cron dùng chung một thư mục.
- **Truy cập object gốc của phpfastcache:** `Cache::store('redis')->getPool()` trả về pool để dùng các tính năng riêng.

### Failover

Store đầu tiên ném lỗi (Redis tắt, mất kết nối) thì tự chuyển sang store kế tiếp, và phát event `CacheFailedOver`.

```php
'default' => 'failover',
'failover' => ['driver' => 'failover', 'stores' => ['redis', 'transient']],
```

### `build()` — store tạm từ config

```php
$tmp = Cache::build(['driver' => 'file', 'path' => WP_CONTENT_DIR . '/uploads/plugin-a-tmp']);
$tmp->put('preview', $html, 600);
```

### `extend()` — driver tự viết

Store cần implement `WPSPCORELITE\App\Cache\Contracts\Store` (có thể kế thừa `Stores\TaggableStore` để có tags). Closure được bind vào CacheManager, nên gọi được `repository()` và `getPrefix()`.

```php
use WPSPCORELITE\App\Cache\Stores\TaggableStore;

Cache::extend('mydb', function (array $config) {
	return $this->repository(new MyDbStore($this->getPrefix($config)), $config);
});

// config/cache.php: 'mydb' => ['driver' => 'mydb'],
```

Đăng ký `extend()` khi plugin boot, trước lần đầu gọi `Cache::store('mydb')`.

## 11. Events, macros và test

### Events

Bật bằng `'events' => true` trong config (hoặc một chuỗi để tự đặt tên hook). Khi tắt, không có object event nào được tạo, nên không tốn chi phí.

| Event | Khi nào | Thuộc tính |
| --- | --- | --- |
| `RetrievingKey` / `RetrievingManyKeys` | trước khi đọc | `key` / `keys` |
| `CacheHit` | đọc thấy | `key`, `value` |
| `CacheMissed` | đọc không thấy | `key` |
| `WritingKey` / `WritingManyKeys` | trước khi ghi | `key`, `value`, `seconds` |
| `KeyWritten` / `KeyWriteFailed` | sau khi ghi | `key`, `value`, `seconds` |
| `ForgettingKey` / `KeyForgotten` / `KeyForgetFailed` | quanh `forget()` | `key` |
| `CacheFlushing` / `CacheFlushed` / `CacheFlushFailed` | quanh `flush()` | `storeName`, `tags` |
| `CacheFailedOver` | store của failover bị lỗi | `storeName`, `exception` |

Mọi event có `storeName`; event phát từ `tags()` có thêm `tags`. Namespace: `WPSPCORELITE\App\Cache\Events`.

Có hai cách nghe:

```php
use WPSPCORELITE\App\Cache\Events\CacheMissed;

// 1. WordPress hook: "{base}_cache" nhận mọi event, "{base}_cache/{tên_snake}" nhận một loại
add_action('plugin_a_cache/cache_missed', function (CacheMissed $e) {
	error_log("[cache miss] {$e->storeName}: {$e->key}");
});

// 2. Dispatcher của cache (không cần WordPress)
Cache::getEventDispatcher()->listen(CacheMissed::class, function (CacheMissed $e) {
	// ...
});
```

Ví dụ đo hit rate trong lúc tối ưu:

```php
add_action('plugin_a_cache/cache_hit',    fn() => Cache::store('array')->increment('hits'));
add_action('plugin_a_cache/cache_missed', fn() => Cache::store('array')->increment('misses'));
```

`increment()` và `decrement()` không phát event (giống Laravel), nên ví dụ trên không tự kích hoạt lại chính nó.

### Macros

Thêm method riêng cho mọi Repository, kể cả tagged cache. Trong closure, `$this` là Repository.

```php
Cache::macro('rememberJson', function (string $key, int $ttl, string $url) {
	return $this->remember($key, $ttl, function () use ($url) {
		$res = wp_remote_get($url, ['timeout' => 5]);
		return is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
	});
});

$data = Cache::rememberJson('remote_config', 3600, 'https://example.com/config.json');
Cache::hasMacro('rememberJson');
```

Macro là static và dùng chung giữa mọi plugin cùng nạp WPSPCORELITE, giống Laravel. Hãy đặt tên có tiền tố plugin, ví dụ `pluginA_rememberJson`.

### Test

Thay cache bằng store trong bộ nhớ, không chạm DB hay Redis:

```php
use WPSPCORELITE\App\Cache\Cache as CoreCache;

Cache::swap(CoreCache::make(['default' => 'array', 'prefix' => 'plugin_a_test_']));

// ... chạy code cần test ...

expect(Cache::get('settings'))->toBe([...]);
```

`Cache::getFacadeRoot()` trả về object hiện tại, để lưu lại rồi `swap()` trả về sau khi test xong.

## 12. Trường hợp thực tế

Mọi ví dụ giả định đã có `use PLUGINA\App\Widen\Support\Facades\Cache;`.

### 12.1 Gọi API bên ngoài

Key phải chứa mọi tham số làm kết quả khác nhau (ở đây là domain), và không cache khi API lỗi.

```php
function plugina_get_backlinks(string $domain): array {
	return Cache::remember('blc_backlinks_' . md5($domain), 300, function () use ($domain) {
		$response = wp_remote_get(
			'https://backlink.toolseo.click/wp-json/blc/v1/backlinks/' . rawurlencode($domain),
			['timeout' => 10]
		);

		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			return null;   // không lưu, lần sau thử lại
		}

		$json = json_decode(wp_remote_retrieve_body($response), true);
		return $json['data']['backlinks'] ?? [];
	}) ?? [];
}
```

### 12.2 Truy vấn nặng, tự xoá khi nội dung đổi (tags)

```php
function plugina_related_tours(int $destinationId): array {
	return Cache::tags(['tours', 'destination_' . $destinationId])
		->remember('related', 3600, fn() => get_posts([
			'post_type'   => 'tour',
			'numberposts' => 8,
			'meta_query'  => [['key' => 'destination_id', 'value' => $destinationId]],
		]));
}

add_action('save_post_tour', function (int $postId) {
	$destinationId = (int) get_post_meta($postId, 'destination_id', true);
	Cache::tags('destination_' . $destinationId)->flush();   // chỉ điểm đến bị ảnh hưởng
});

add_action('deleted_post', fn() => Cache::tags('tours')->flush());
```

### 12.3 Cài đặt của plugin

```php
function plugina_settings(): array {
	return Cache::rememberForever('settings', fn() => wp_parse_args(
		get_option('plugina_settings', []),
		['per_page' => 12, 'currency' => 'VND']
	));
}

add_action('update_option_plugina_settings', fn() => Cache::forget('settings'));
```

### 12.4 Cache một đoạn HTML lớn (file store)

HTML vài chục KB không nên nằm trong `wp_options`; dùng store `file`.

```php
add_shortcode('plugina_price_table', function ($atts) {
	$atts = shortcode_atts(['category' => 'all'], $atts);
	$key  = 'price_table_' . md5(serialize($atts) . get_locale());

	return Cache::store('file')->tags('price_tables')->remember($key, 6 * HOUR_IN_SECONDS, function () use ($atts) {
		ob_start();
		plugina_render_price_table($atts);
		return ob_get_clean();
	});
});

add_action('plugina_prices_updated', fn() => Cache::store('file')->tags('price_tables')->flush());
```

### 12.5 Dữ liệu theo từng user

```php
function plugina_user_bookings(int $userId): array {
	return Cache::tags('user_' . $userId)->remember('bookings', 900, fn() => plugina_query_bookings($userId));
}

add_action('plugina_booking_created', fn(int $userId) => Cache::tags('user_' . $userId)->flush());
```

### 12.6 Giới hạn tần suất (rate limit)

Tối đa 60 request mỗi phút cho mỗi IP với một REST endpoint. `add()` tạo bộ đếm với hạn 60 giây; `increment()` giữ nguyên hạn đó.

```php
function plugina_too_many_requests(string $action, int $max = 60): bool {
	$ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	$window = (int) floor(time() / 60);
	$key    = 'rl_' . $action . '_' . md5($ip) . '_' . $window;

	Cache::add($key, 0, 60);

	return Cache::increment($key) > $max;
}

register_rest_route('plugina/v1', '/search', [
	'methods'  => 'GET',
	'callback' => function (WP_REST_Request $request) {
		if (plugina_too_many_requests('search')) {
			return new WP_Error('too_many_requests', 'Quá nhiều yêu cầu', ['status' => 429]);
		}
		return plugina_search($request->get_param('q'));
	},
	'permission_callback' => '__return_true',
]);
```

### 12.7 Không cho cron chạy chồng nhau (lock)

```php
add_action('plugina_sync_inventory', function () {
	Cache::lock('sync_inventory', 15 * MINUTE_IN_SECONDS)->get(function () {
		plugina_pull_inventory_from_supplier();
	});
	// Lần chạy trước chưa xong thì lần này tự bỏ qua
});
```

### 12.8 Webhook hoặc form chỉ xử lý một lần (idempotency)

Cổng thanh toán thường gửi lại cùng một webhook nhiều lần. `add()` atomic bảo đảm chỉ một lần được xử lý.

```php
function plugina_handle_payment_webhook(array $payload): void {
	$eventId = sanitize_key($payload['event_id'] ?? '');

	if ($eventId === '' || !Cache::add('webhook_' . $eventId, 1, DAY_IN_SECONDS)) {
		return;   // đã xử lý, hoặc đang được request khác xử lý
	}

	plugina_mark_order_paid((int) $payload['order_id']);
}
```

### 12.9 Dashboard thống kê không bao giờ chậm (flexible)

```php
$stats = Cache::flexible('admin_dashboard_stats', [300, 3600], fn() => [
	'orders_today'  => plugina_count_orders_today(),
	'revenue_month' => plugina_revenue_this_month(),
	'top_tours'     => plugina_top_tours(5),
]);
```

Sau 5 phút, admin vẫn thấy số liệu ngay lập tức, và số liệu được tính lại phía sau; chỉ khi hơn 1 giờ không ai mở trang thì mới phải chờ tính.

### 12.10 Kiểm tra license: giữ kết quả tốt gần nhất khi server lỗi

```php
function plugina_license_status(): string {
	$status = Cache::remember('license_status', 12 * HOUR_IN_SECONDS, function () {
		$res = wp_remote_post('https://license.example.com/check', [
			'timeout' => 5,
			'body'    => ['key' => get_option('plugina_license_key'), 'site' => home_url()],
		]);

		if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
			return null;
		}

		$status = json_decode(wp_remote_retrieve_body($res), true)['status'] ?? 'invalid';
		Cache::forever('license_status_last_good', $status);
		return $status;
	});

	return $status ?? Cache::get('license_status_last_good', 'unknown');
}
```

### 12.11 Đếm lượt xem, ghi xuống DB theo đợt

Ghi `post_meta` mỗi lượt xem rất tốn; tăng bộ đếm trong cache rồi cron cộng dồn vào DB.

```php
add_action('wp', function () {
	if (is_singular('tour')) {
		$id = get_queried_object_id();
		Cache::increment('views_' . $id);
		Cache::lock('views_index', 5)->block(2, function () use ($id) {
			$ids = Cache::get('views_index', []);
			$ids[$id] = true;
			Cache::forever('views_index', $ids);
		});
	}
});

add_action('plugina_flush_views', function () {   // cron mỗi 5 phút
	Cache::lock('views_index', 30)->block(5, function () {
		foreach (array_keys(Cache::pull('views_index', [])) as $id) {
			$n = (int) Cache::get('views_' . $id, 0);
			if ($n > 0) {
				update_post_meta($id, 'views', (int) get_post_meta($id, 'views', true) + $n);
				Cache::decrement('views_' . $id, $n);   // giữ lượt xem phát sinh trong lúc chạy
			}
		}
	});
});
```

### 12.12 Cache phản hồi REST API

```php
add_filter('rest_pre_dispatch', function ($result, $server, WP_REST_Request $request) {
	if ($request->get_method() !== 'GET' || !str_starts_with($request->get_route(), '/plugina/v1/tours')) {
		return $result;
	}

	$key = 'rest_' . md5($request->get_route() . serialize($request->get_query_params()));
	$data = Cache::tags('rest_tours')->get($key);

	return $data !== null ? new WP_REST_Response($data, 200) : $result;
}, 10, 3);

add_filter('rest_post_dispatch', function (WP_REST_Response $response, $server, WP_REST_Request $request) {
	if ($request->get_method() === 'GET' && $response->get_status() === 200
		&& str_starts_with($request->get_route(), '/plugina/v1/tours')) {
		$key = 'rest_' . md5($request->get_route() . serialize($request->get_query_params()));
		Cache::tags('rest_tours')->put($key, $response->get_data(), 600);
	}
	return $response;
}, 10, 3);
```

### 12.13 Token dùng một lần

```php
$token = wp_generate_password(32, false);
Cache::put('download_token_' . $token, $fileId, 15 * MINUTE_IN_SECONDS);
// gửi link ?token=...

$fileId = Cache::pull('download_token_' . sanitize_text_field($_GET['token'] ?? ''));
if ($fileId === null) {
	wp_die('Link đã hết hạn hoặc đã được dùng.');
}
```

### 12.14 Xoá cache khi plugin cập nhật phiên bản

```php
add_action('plugins_loaded', function () {
	if (get_option('plugina_version') !== PLUGINA_VERSION) {
		Cache::flush();
		update_option('plugina_version', PLUGINA_VERSION);
	}
});
```

## 13. Lưu ý và tra cứu nhanh

### Lưu ý khi dùng

- **`null` không được cache.** `remember()` gặp `null` sẽ chạy lại closure lần sau. Muốn cache "không có kết quả", hãy trả `[]` hoặc `false`.
- **Key phải đủ cụ thể.** Đưa vào key mọi thứ làm kết quả khác nhau: tham số, user, ngôn ngữ (`get_locale()`), trang, blog id trên multisite.
- **Chỉ lưu dữ liệu serialize được.** Không lưu closure, resource, kết nối DB; object `WP_Post` thì được.
- **Giá trị lớn** (từ khoảng 100 KB) nên dùng store `file` hoặc Redis thay vì transient trong `wp_options`.
- **TTL `0` hoặc thời điểm đã qua** sẽ xoá key; không truyền TTL nghĩa là vĩnh viễn.
- **Đọc tags đúng thứ tự** như khi ghi; `tags(['a','b'])` khác `tags(['b','a'])`.
- **Atomic:** `add()` có TTL, `increment()` và `lock()` an toàn giữa nhiều request trên `transient`, `object` (có drop-in) và `file`. Trên `phpfastcache` thì không.
- **`object` không có drop-in** chỉ giữ dữ liệu trong một request; khi đó dùng `transient`.
- **Plugin không có phpfastcache** không được đặt store phpfastcache làm `default`, kể cả khi plugin khác trên site đã cài nó.
- **Prefix** đặt một lần và không đổi; đổi prefix tức là toàn bộ cache cũ bị bỏ qua (tự hết hạn).
- **Macro** dùng chung giữa các plugin; đặt tên có tiền tố plugin.

### Tra cứu nhanh

| Method | Trả về | Mục |
| --- | --- | --- |
| `get($key, $default = null)` | giá trị hoặc mặc định | 4 |
| `many(array $keys)` | mảng | 4 |
| `getMultiple($keys, $default)` | mảng | 4 |
| `has($key)` / `missing($key)` | bool | 4 |
| `pull($key, $default = null)` | giá trị, rồi xoá | 4 |
| `string` / `integer` / `float` / `boolean` / `array` | đúng kiểu, hoặc ném lỗi | 4 |
| `put($key, $value, $ttl = null)` / `set` | bool | 5 |
| `putMany(array, $ttl)` / `setMultiple` | bool | 5 |
| `add($key, $value, $ttl)` | bool (chỉ ghi khi chưa có) | 5 |
| `forever($key, $value)` | bool | 5 |
| `increment($key, $n = 1)` / `decrement` | số mới | 5 |
| `touch($key, $ttl = null)` | bool | 5 |
| `remember($key, $ttl, fn)` | giá trị | 6 |
| `rememberForever($key, fn)` / `sear` | giá trị | 6 |
| `flexible($key, [$fresh, $stale], fn)` | giá trị | 6 |
| `forget($key)` / `delete` | bool | 7 |
| `deleteMultiple($keys)` | bool | 7 |
| `flush()` / `clear()` | bool | 7 |
| `tags($names)` | TaggedCache | 8 |
| `lock($name, $seconds = 0, $owner = null)` | Lock | 9 |
| `restoreLock($name, $owner)` | Lock | 9 |
| `store($name)` / `driver($name)` | Repository | 3 |
| `memo($name = null)` | Repository có nhớ trong request | 3 |
| `build(array $config)` | Repository | 10 |
| `extend($driver, fn)` | CacheManager | 10 |
| `getDefaultDriver()` / `setDefaultDriver($name)` | string / void | 2 |
| `getConfig($key = null)` / `setConfig(array)` | mixed / CacheManager | 2 |
| `forgetDriver($name)` / `purge($name)` | bỏ store đã tạo, lần sau tạo lại | 10 |
| `getEventDispatcher()` | Dispatcher hoặc null | 11 |
| `macro($name, fn)` / `hasMacro($name)` | — | 11 |
| `getFacadeRoot()` / `swap($cache)` | facade | 11 |
| `getStore()` | Store bên dưới | 3 |
