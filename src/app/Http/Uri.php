<?php

namespace WPSPCORELITE\App\Http;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use Stringable;

/**
 * URI bất biến (immutable) - API tương thích Illuminate\Support\Uri, không phụ thuộc league/uri hay Illuminate.
 *
 *   Uri::of('https://example.com/shop?page=2')
 *       ->withPath('blog')->withQuery(['tag' => 'php'])->withFragment('top');
 *
 * Mở rộng thêm: resolve() (RFC 3986), equals(), signed() / hasValidSignature().
 */
class Uri implements Stringable, JsonSerializable {

	protected const DEFAULT_PORTS = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];

	protected ?string $scheme   = null;
	protected ?string $user     = null;   // đã percent-encode
	protected ?string $pass     = null;   // đã percent-encode
	protected ?string $host     = null;
	protected ?int    $port     = null;
	protected string  $path     = '';     // đã percent-encode
	protected ?string $query    = null;   // chuỗi thô, không có "?"
	protected ?string $fragment = null;   // chuỗi thô, không có "#"

	protected static ?Closure                   $urlResolver = null;
	protected static Closure|array|string|null $signingKey  = null;

	public function __construct(Stringable|string $uri = '') {
		$uri = (string)$uri;
		if ($uri === '') return;

		$parts = parse_url($uri);
		if ($parts === false) {
			throw new InvalidArgumentException(sprintf('Unable to parse URI "%s".', $uri));
		}

		$this->scheme   = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
		$this->user     = isset($parts['user']) ? static::encode($parts['user'], '') : null;
		$this->pass     = isset($parts['pass']) ? static::encode($parts['pass'], ':') : null;
		$this->host     = isset($parts['host']) ? strtolower($parts['host']) : null;
		$this->port     = $parts['port'] ?? null;
		$this->path     = static::encode($parts['path'] ?? '', ':@/');
		$this->query    = isset($parts['query']) ? static::encode($parts['query'], ':@/?[]') : null;
		$this->fragment = isset($parts['fragment']) ? static::encode($parts['fragment'], ':@/?') : null;
	}

	public static function of(Stringable|string $uri = ''): static {
		return new static($uri);
	}

	/** URL tuyệt đối cho một path của site (dùng resolver tuỳ chỉnh, hoặc home_url() trên WordPress). */
	public static function to(string $path = '/'): static {
		if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $path) || str_starts_with($path, '//')) return new static($path);
		if (static::$urlResolver) return new static((static::$urlResolver)($path));
		if (function_exists('home_url')) return new static(home_url($path));
		throw new RuntimeException('No URL resolver configured. Call Uri::resolveUrlsUsing().');
	}

	public static function resolveUrlsUsing(?Closure $resolver): void {
		static::$urlResolver = $resolver;
	}

	/* ================================================================== *
	 | Components
	 * ================================================================== */

	public function scheme(): ?string {
		return $this->scheme;
	}

	/** Username (đã decode); $withPassword = true => "user:pass". */
	public function user(bool $withPassword = false): ?string {
		if ($this->user === null) return null;
		$user = rawurldecode($this->user);
		return $withPassword && $this->pass !== null ? $user . ':' . rawurldecode($this->pass) : $user;
	}

	public function password(): ?string {
		return $this->pass === null ? null : rawurldecode($this->pass);
	}

	public function host(): ?string {
		return $this->host;
	}

	/** Port ghi rõ trong URI (null nếu không có). */
	public function port(): ?int {
		return $this->port;
	}

	/** Port thực tế: port ghi rõ, hoặc port mặc định của scheme. */
	public function effectivePort(): ?int {
		return $this->port ?? (static::DEFAULT_PORTS[$this->scheme ?? ''] ?? null);
	}

	public function authority(): ?string {
		if ($this->host === null) return null;

		$authority = '';
		if ($this->user !== null) $authority .= $this->user . ($this->pass !== null ? ':' . $this->pass : '') . '@';
		$authority .= $this->host;
		if ($this->port !== null) $authority .= ':' . $this->port;
		return $authority;
	}

	/** Như Laravel: bỏ "/" hai đầu, trả "/" cho root. */
	public function path(): string {
		$path = trim($this->path, '/');
		return $path === '' ? '/' : $path;
	}

	/** Path nguyên bản (giữ "/" đầu/cuối, chưa decode). */
	public function rawPath(): string {
		return $this->path;
	}

	/** Các đoạn path đã decode. */
	public function pathSegments(): array {
		$path = trim($this->path, '/');
		return $path === '' ? [] : array_map('rawurldecode', explode('/', $path));
	}

	public function query(): UriQueryString {
		return new UriQueryString($this);
	}

	public function rawQuery(): ?string {
		return $this->query;
	}

	public function fragment(): ?string {
		return $this->fragment;
	}

	public function isAbsolute(): bool {
		return $this->scheme !== null;
	}

	public function isRelative(): bool {
		return $this->scheme === null;
	}

	public function isSecure(): bool {
		return in_array($this->scheme, ['https', 'wss'], true);
	}

	public function isEmpty(): bool {
		return $this->value() === '';
	}

	/* ================================================================== *
	 | Modifiers (luôn trả về instance mới)
	 * ================================================================== */

	public function withScheme(Stringable|string|null $scheme): static {
		$new         = clone $this;
		$new->scheme = $scheme === null || (string)$scheme === '' ? null : strtolower(rtrim((string)$scheme, ':/'));
		return $new;
	}

	public function withUser(Stringable|string|null $user, Stringable|string|null $password = null): static {
		$new       = clone $this;
		$new->user = $user === null || (string)$user === '' ? null : static::encode((string)$user, '');
		$new->pass = $new->user === null || $password === null ? null : static::encode((string)$password, ':');
		return $new;
	}

	public function withHost(Stringable|string|null $host): static {
		$host = $host === null ? '' : strtolower(trim((string)$host));
		if ($host !== '' && str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';   // IPv6

		$new       = clone $this;
		$new->host = $host === '' ? null : $host;
		return $new;
	}

	public function withPort(?int $port): static {
		if ($port !== null && ($port < 0 || $port > 65535)) {
			throw new InvalidArgumentException(sprintf('Invalid port "%d".', $port));
		}
		$new       = clone $this;
		$new->port = $port;
		return $new;
	}

	/** Như Laravel: path luôn bắt đầu bằng "/". */
	public function withPath(Stringable|string $path): static {
		$new       = clone $this;
		$new->path = '/' . ltrim(static::encode((string)$path, ':@/'), '/');
		return $new;
	}

	/**
	 * Gộp (mặc định) hoặc thay thế query. Hỗ trợ dot-notation: ['filter.status' => 'open'].
	 * Truyền chuỗi để đặt query thô.
	 */
	public function withQuery(array|string $query, bool $merge = true): static {
		if (is_string($query)) {
			$query     = ltrim($query, '?');
			$new       = clone $this;
			$new->query = $query === '' ? null : static::encode($query, ':@/?[]');
			return $new;
		}

		$data = $merge ? $this->query()->all() : [];
		foreach ($query as $key => $value) {
			Arr::set($data, $key, static::normalizeQueryValue($value));
		}
		return $this->withRawQuery(Arr::query($data));
	}

	/** Chỉ thêm các key chưa có. */
	public function withQueryIfMissing(array $query): static {
		$current = $this->query();
		foreach (array_keys($query) as $key) {
			if (!$current->missing((string)$key)) unset($query[$key]);
		}
		return $this->withQuery($query);
	}

	/** Đẩy thêm giá trị vào một key dạng mảng: ?tags[]=a => pushOntoQuery('tags', 'b'). */
	public function pushOntoQuery(string $key, mixed $value): static {
		$current = $this->query()->get($key);
		$values  = Arr::wrap($value);

		return $this->withQuery([$key => match (true) {
			is_array($current) && array_is_list($current) => array_values(array_unique([...$current, ...$values], SORT_REGULAR)),
			is_array($current)                            => [...$current, ...$values],
			$current !== null                             => [$current, ...$values],
			default                                       => $values,
		}]);
	}

	public function withoutQuery(array|string $keys): static {
		return $this->replaceQuery(Arr::except($this->query()->all(), $keys));
	}

	public function replaceQuery(array $query): static {
		return $this->withQuery($query, false);
	}

	public function withFragment(Stringable|string|null $fragment): static {
		$fragment      = $fragment === null ? '' : ltrim((string)$fragment, '#');
		$new           = clone $this;
		$new->fragment = $fragment === '' ? null : static::encode($fragment, ':@/?');
		return $new;
	}

	public function withoutFragment(): static {
		return $this->withFragment(null);
	}

	protected function withRawQuery(string $query): static {
		$new        = clone $this;
		$new->query = $query === '' ? null : $query;
		return $new;
	}

	protected static function normalizeQueryValue(mixed $value): mixed {
		if (is_array($value)) return array_map([static::class, 'normalizeQueryValue'], $value);
		if ($value instanceof \BackedEnum) return $value->value;
		if ($value instanceof \UnitEnum) return $value->name;
		if ($value instanceof DateTimeInterface) return $value->format(DateTimeInterface::ATOM);
		if ($value instanceof Stringable) return (string)$value;
		if (is_object($value) && method_exists($value, 'getRouteKey')) return $value->getRouteKey();
		return $value;
	}

	/* ================================================================== *
	 | Resolution & comparison
	 * ================================================================== */

	/**
	 * Resolve một reference tương đối theo URI hiện tại (RFC 3986 §5.2).
	 *   Uri::of('https://a.com/blog/post/1')->resolve('../2?x=1')  => https://a.com/blog/2?x=1
	 */
	public function resolve(Stringable|string $reference): static {
		$ref = $reference instanceof self ? $reference : new static((string)$reference);

		if ($ref->scheme !== null) {
			$target       = clone $ref;
			$target->path = static::removeDotSegments($ref->path);
			return $target;
		}

		$target           = clone $this;
		$target->fragment = $ref->fragment;

		if ($ref->host !== null) {
			$target->user  = $ref->user;
			$target->pass  = $ref->pass;
			$target->host  = $ref->host;
			$target->port  = $ref->port;
			$target->path  = static::removeDotSegments($ref->path);
			$target->query = $ref->query;
			return $target;
		}

		if ($ref->path === '') {
			$target->query = $ref->query ?? $this->query;
			return $target;
		}

		if ($ref->path[0] === '/') {
			$target->path = static::removeDotSegments($ref->path);
		}
		else {
			if ($this->host !== null && $this->path === '') {
				$merged = '/' . $ref->path;
			}
			else {
				$slash  = strrpos($this->path, '/');
				$merged = ($slash === false ? '' : substr($this->path, 0, $slash + 1)) . $ref->path;
			}
			$target->path = static::removeDotSegments($merged);
		}
		$target->query = $ref->query;
		return $target;
	}

	/** So sánh sau khi chuẩn hoá (scheme/host chữ thường, bỏ port mặc định, path rỗng => "/"). */
	public function equals(Stringable|string $other, bool $ignoreFragment = false): bool {
		$other = $other instanceof self ? $other : new static((string)$other);
		return $this->normalized($ignoreFragment) === $other->normalized($ignoreFragment);
	}

	protected function normalized(bool $ignoreFragment): string {
		$uri = clone $this;
		if ($uri->port !== null && $uri->port === (static::DEFAULT_PORTS[$uri->scheme ?? ''] ?? null)) $uri->port = null;
		if ($uri->host !== null && $uri->path === '') $uri->path = '/';
		$uri->path = static::removeDotSegments($uri->path);
		if ($ignoreFragment) $uri->fragment = null;
		return $uri->value();
	}

	protected static function removeDotSegments(string $path): string {
		if ($path === '' || !str_contains($path, '.')) return $path;

		$input  = $path;
		$output = '';
		while ($input !== '') {
			if (str_starts_with($input, '../')) {
				$input = substr($input, 3);
			}
			elseif (str_starts_with($input, './') || str_starts_with($input, '/./')) {
				$input = substr($input, 2);
			}
			elseif ($input === '/.') {
				$input = '/';
			}
			elseif (str_starts_with($input, '/../') || $input === '/..') {
				$input  = $input === '/..' ? '/' : substr($input, 3);
				$output = substr($output, 0, (int)strrpos($output, '/'));
			}
			elseif ($input === '.' || $input === '..') {
				$input = '';
			}
			else {
				$next   = strpos($input, '/', 1);
				$next   = $next === false ? strlen($input) : $next;
				$output .= substr($input, 0, $next);
				$input  = substr($input, $next);
			}
		}
		return $output;
	}

	/* ================================================================== *
	 | Signed URLs (cùng thuật toán với Laravel URL::signedRoute)
	 * ================================================================== */

	/**
	 * Khoá ký: string, mảng (xoay vòng khoá - khoá đầu dùng để ký, tất cả dùng để xác thực) hoặc Closure.
	 * Mặc định trên WordPress: wp_salt('auth').
	 */
	public static function signUsing(Closure|array|string|null $key): void {
		static::$signingKey = $key;
	}

	public static function signingKeys(): array {
		$key = static::$signingKey instanceof Closure ? (static::$signingKey)() : static::$signingKey;
		if ($key === null && function_exists('wp_salt')) $key = wp_salt('auth');

		$keys = array_values(array_filter(Arr::wrap($key), fn($k) => is_string($k) && $k !== ''));
		if (!$keys) throw new RuntimeException('No URL signing key configured. Call Uri::signUsing($key).');
		return $keys;
	}

	/**
	 * Ký URL. $expiration: số giây kể từ bây giờ, DateInterval, hoặc DateTimeInterface.
	 *   Uri::of('https://site.com/download/5')->signed(3600)
	 */
	public function signed(DateTimeInterface|DateInterval|int|null $expiration = null, bool $absolute = true): static {
		if ($absolute && $this->host === null) {
			throw new InvalidArgumentException('An absolute signature requires a URI with a host.');
		}

		$uri = $this->query()->has('signature') ? $this->withoutQuery('signature') : $this;
		if ($expiration !== null) $uri = $uri->withQuery(['expires' => static::expirationTimestamp($expiration)]);

		$payload = static::signaturePayload($uri->signatureBase($absolute), $uri->query);
		return $uri->withQuery(['signature' => hash_hmac('sha256', $payload, static::signingKeys()[0])]);
	}

	public function temporarySigned(DateTimeInterface|DateInterval|int $expiration, bool $absolute = true): static {
		return $this->signed($expiration, $absolute);
	}

	public function hasValidSignature(bool $absolute = true, array $ignoreQuery = []): bool {
		return $this->hasCorrectSignature($absolute, $ignoreQuery) && $this->signatureHasNotExpired();
	}

	public function hasCorrectSignature(bool $absolute = true, array $ignoreQuery = []): bool {
		$signature = $this->query()->get('signature');
		if (!is_string($signature) || $signature === '') return false;

		$payload = static::signaturePayload($this->signatureBase($absolute), $this->query, $ignoreQuery);
		foreach (static::signingKeys() as $key) {
			if (hash_equals(hash_hmac('sha256', $payload, $key), $signature)) return true;
		}
		return false;
	}

	public function signatureHasNotExpired(): bool {
		$expires = $this->query()->get('expires');
		return !($expires && time() > (int)$expires);
	}

	/** Chuỗi được ký: "<base>?<query thô, bỏ signature + các key bị ignore>". */
	public static function signaturePayload(string $base, ?string $rawQuery, array $ignoreQuery = []): string {
		$ignoreQuery[] = 'signature';
		$pairs         = array_filter(
			explode('&', (string)$rawQuery),
			fn($pair) => $pair !== '' && !in_array(explode('=', $pair, 2)[0], $ignoreQuery, true)
		);
		return rtrim($base . '?' . implode('&', $pairs), '?');
	}

	/** Khớp với Request::url() (absolute) hoặc "/path" (relative). */
	protected function signatureBase(bool $absolute): string {
		$path = '/' . trim($this->path, '/');
		if (!$absolute) return $path;

		$scheme = $this->scheme ?? 'http';
		$port   = $this->port !== null && $this->port !== (static::DEFAULT_PORTS[$scheme] ?? null) ? ':' . $this->port : '';
		return rtrim($scheme . '://' . $this->host . $port . $path, '/');
	}

	protected static function expirationTimestamp(DateTimeInterface|DateInterval|int $expiration): int {
		if ($expiration instanceof DateTimeInterface) return $expiration->getTimestamp();
		if ($expiration instanceof DateInterval) return (new DateTimeImmutable())->add($expiration)->getTimestamp();
		return time() + $expiration;
	}

	/* ================================================================== *
	 | Output
	 * ================================================================== */

	public function value(): string {
		$uri = $this->scheme !== null ? $this->scheme . ':' : '';

		$authority = $this->authority();
		$path      = $this->path;
		if ($authority !== null) {
			$uri .= '//' . $authority;
			if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
		}
		elseif (str_starts_with($path, '//')) {
			$path = '/.' . $path;   // tránh bị hiểu nhầm thành authority
		}

		$uri .= $path;
		if ($this->query !== null) $uri .= '?' . $this->query;
		if ($this->fragment !== null) $uri .= '#' . $this->fragment;
		return $uri;
	}

	/** URI với phần query đã decode (dễ đọc, không dùng để gửi đi). */
	public function decode(): string {
		if ($this->query === null || $this->query === '') return $this->value();
		$new        = clone $this;
		$new->query = rawurldecode($this->query);
		return $new->value();
	}

	public function toArray(): array {
		return [
			'scheme'   => $this->scheme,
			'user'     => $this->user(),
			'password' => $this->password(),
			'host'     => $this->host,
			'port'     => $this->port,
			'path'     => $this->path,
			'query'    => $this->query()->all(),
			'fragment' => $this->fragment,
		];
	}

	public function when(mixed $value, callable $callback, ?callable $default = null): static {
		$value = $value instanceof Closure ? $value($this) : $value;
		if ($value) return $callback($this, $value) ?? $this;
		return $default ? ($default($this, $value) ?? $this) : $this;
	}

	public function unless(mixed $value, callable $callback, ?callable $default = null): static {
		$value = $value instanceof Closure ? $value($this) : $value;
		if (!$value) return $callback($this, $value) ?? $this;
		return $default ? ($default($this, $value) ?? $this) : $this;
	}

	public function dump(): static {
		function_exists('dump') ? dump($this->value()) : var_dump($this->value());
		return $this;
	}

	public function jsonSerialize(): string {
		return $this->value();
	}

	public function __toString(): string {
		return $this->value();
	}

	/**
	 * Percent-encode ký tự không hợp lệ, giữ nguyên %XX đã có và các ký tự cho phép.
	 * $extra: ký tự được phép thêm cho từng thành phần.
	 */
	protected static function encode(string $value, string $extra): string {
		if ($value === '') return '';
		$extra = preg_quote($extra, '#');
		return (string)preg_replace_callback(
			'#(?:[^A-Za-z0-9\-._~!$&\'()*+,;=%' . $extra . ']++|%(?![A-Fa-f0-9]{2}))#',
			fn($m) => rawurlencode($m[0]),
			$value
		);
	}

}
