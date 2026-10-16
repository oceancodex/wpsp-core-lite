<?php

namespace WPSPCORELITE\App\Http;

use ArrayAccess;
use BadMethodCallException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Standalone HTTP Request mirroring the public API of Illuminate\Http\Request
 * (including the Symfony HttpFoundation members Laravel relies on). No Illuminate / Symfony dependency.
 *
 * Bags (public, like Symfony):
 *   $request->query, ->request, ->attributes, ->cookies, ->server  => ParameterBag
 *   $request->headers                                               => HeaderBag
 *   $request->files                                                 => FileBag
 *
 * Requires PHP 8.1+. Optional bridges (used only when the class exists):
 *  - Illuminate\Support\{Collection, Stringable}, Carbon\Carbon   (collect(), string(), date())
 *  - a session store exposing flashInput() / getOldInput()
 *
 * WordPress: superglobals are slashed by wp_magic_quotes(); createFromGlobals() unslashes them
 * automatically (configurable via Request::unslashGlobals()).
 */
class Request implements ArrayAccess, JsonSerializable, Stringable {

	public const HEADER_FORWARDED           = 0b000001;
	public const HEADER_X_FORWARDED_FOR     = 0b000010;
	public const HEADER_X_FORWARDED_HOST    = 0b000100;
	public const HEADER_X_FORWARDED_PROTO   = 0b001000;
	public const HEADER_X_FORWARDED_PORT    = 0b010000;
	public const HEADER_X_FORWARDED_PREFIX  = 0b100000;
	public const HEADER_X_FORWARDED_AWS_ELB = 0b0011010;
	public const HEADER_X_FORWARDED_TRAEFIK = 0b0111110;

	public const METHOD_HEAD    = 'HEAD';
	public const METHOD_GET     = 'GET';
	public const METHOD_POST    = 'POST';
	public const METHOD_PUT     = 'PUT';
	public const METHOD_PATCH   = 'PATCH';
	public const METHOD_DELETE  = 'DELETE';
	public const METHOD_PURGE   = 'PURGE';
	public const METHOD_OPTIONS = 'OPTIONS';
	public const METHOD_TRACE   = 'TRACE';
	public const METHOD_CONNECT = 'CONNECT';

	protected const TRUSTED_HEADERS = [
		self::HEADER_FORWARDED          => 'forwarded',
		self::HEADER_X_FORWARDED_FOR    => 'x-forwarded-for',
		self::HEADER_X_FORWARDED_HOST   => 'x-forwarded-host',
		self::HEADER_X_FORWARDED_PROTO  => 'x-forwarded-proto',
		self::HEADER_X_FORWARDED_PORT   => 'x-forwarded-port',
		self::HEADER_X_FORWARDED_PREFIX => 'x-forwarded-prefix',
	];

	protected const FORWARDED_PARAMS = [
		self::HEADER_X_FORWARDED_FOR   => 'for',
		self::HEADER_X_FORWARDED_HOST  => 'host',
		self::HEADER_X_FORWARDED_PROTO => 'proto',
		self::HEADER_X_FORWARDED_PORT  => 'host',
	];

	/** Ghi đè POST => các method này (không cho GET/HEAD/CONNECT/TRACE để tránh né CSRF). */
	protected const OVERRIDABLE_METHODS = ['PUT', 'PATCH', 'DELETE', 'PURGE', 'OPTIONS', 'POST'];

	public ParameterBag $query;
	public ParameterBag $request;
	public ParameterBag $attributes;
	public ParameterBag $cookies;
	public FileBag      $files;
	public ParameterBag $server;
	public HeaderBag    $headers;

	/** @var string|resource|null */
	protected mixed         $content = null;
	protected ?ParameterBag $json    = null;

	protected ?string $baseUrl                = null;
	protected ?string $pathInfo               = null;
	protected ?array  $acceptableContentTypes = null;
	protected ?array  $languages              = null;
	protected ?string $format                 = null;
	protected ?string $locale                 = null;
	protected string  $defaultLocale          = 'en';
	protected bool    $isHostValid            = true;

	protected          $session       = null;
	protected ?Closure $userResolver  = null;
	protected ?Closure $routeResolver = null;

	protected static array $trustedProxies              = [];
	protected static int   $trustedHeaderSet            = -1;
	protected static array $trustedHostPatterns         = [];
	protected static array $trustedHosts                = [];
	protected static array $macros                      = [];
	protected static bool  $httpMethodParameterOverride = false;
	protected static ?bool $unslashGlobals              = null;

	protected static array $formats = [
		'html'   => ['text/html', 'application/xhtml+xml'],
		'txt'    => ['text/plain'],
		'js'     => ['application/javascript', 'application/x-javascript', 'text/javascript'],
		'css'    => ['text/css'],
		'json'   => ['application/json', 'application/x-json'],
		'jsonld' => ['application/ld+json'],
		'xml'    => ['text/xml', 'application/xml', 'application/x-xml'],
		'rdf'    => ['application/rdf+xml'],
		'atom'   => ['application/atom+xml'],
		'rss'    => ['application/rss+xml'],
		'form'   => ['application/x-www-form-urlencoded', 'multipart/form-data'],
		'csv'    => ['text/csv'],
		'pdf'    => ['application/pdf'],
	];

	public function __construct(
		array $query = [], array $request = [], array $attributes = [],
		array $cookies = [], array $files = [], array $server = [], $content = null
	) {
		$this->initialize($query, $request, $attributes, $cookies, $files, $server, $content);
	}

	public function initialize(
		array $query = [], array $request = [], array $attributes = [],
		array $cookies = [], array $files = [], array $server = [], $content = null
	): void {
		$this->query      = new ParameterBag($query);
		$this->request    = new ParameterBag($request);
		$this->attributes = new ParameterBag($attributes);
		$this->cookies    = new ParameterBag($cookies);
		$this->files      = new FileBag($files);
		$this->server     = new ParameterBag($server);
		$this->headers    = new HeaderBag($this->extractHeaders($server));
		$this->content    = $content;
		$this->json       = null;
		$this->locale     = null;
		$this->isHostValid = true;
		$this->resetCache();
	}

	/** Xoá các giá trị đã cache (gọi sau khi sửa server/headers thủ công). */
	public function resetCache(): static {
		$this->baseUrl                = $this->pathInfo = $this->format = null;
		$this->acceptableContentTypes = $this->languages = null;
		return $this;
	}

	public function __clone() {
		$sharesJson = $this->json !== null && $this->json === $this->request;

		$this->query      = clone $this->query;
		$this->request    = clone $this->request;
		$this->attributes = clone $this->attributes;
		$this->cookies    = clone $this->cookies;
		$this->files      = clone $this->files;
		$this->server     = clone $this->server;
		$this->headers    = clone $this->headers;
		$this->json       = $sharesJson ? $this->request : ($this->json ? clone $this->json : null);
	}

	/* ================================================================== *
	 | Construction
	 * ================================================================== */

	/**
	 * Symfony: Request::createFromGlobals().
	 * - Gỡ slash do wp_magic_quotes() (WordPress).
	 * - Parse body x-www-form-urlencoded cho PUT/PATCH/DELETE (và multipart với PHP 8.4+).
	 * - Body JSON được gắn vào $request->request.
	 */
	public static function createFromGlobals(): static {
		$get    = $_GET;
		$post   = $_POST;
		$cookie = $_COOKIE;
		$server = $_SERVER;

		if (static::shouldUnslashGlobals()) {
			$get    = wp_unslash($get);
			$post   = wp_unslash($post);
			$cookie = wp_unslash($cookie);
			$server = wp_unslash($server);
		}

		$request = new static($get, $post, [], $cookie, $_FILES, $server);
		$request->parseBodyForNonPostMethods();
		$request->bindJsonToRequest();
		return $request;
	}

	public static function capture(): static {
		static::enableHttpMethodParameterOverride();
		return static::createFromGlobals();
	}

	/**
	 * true/false: bật/tắt gỡ slash; null: tự phát hiện (WordPress đã chạy wp_magic_quotes()).
	 * Tắt đi nếu bạn đã tự wp_unslash() superglobals trước khi capture().
	 */
	public static function unslashGlobals(?bool $enabled): void {
		static::$unslashGlobals = $enabled;
	}

	protected static function shouldUnslashGlobals(): bool {
		if (!function_exists('wp_unslash')) return false;
		if (static::$unslashGlobals !== null) return static::$unslashGlobals;

		// wp_magic_quotes() chạy ngay sau "muplugins_loaded".
		return function_exists('did_action') && did_action('muplugins_loaded') > 0
			&& !(function_exists('doing_action') && doing_action('muplugins_loaded'));
	}

	protected function parseBodyForNonPostMethods(): void {
		if (!in_array($this->getRealMethod(), ['PUT', 'PATCH', 'DELETE'], true) || $this->request->count() > 0) return;

		$type = strtolower((string)$this->headers->get('Content-Type', ''));
		if (str_starts_with($type, 'application/x-www-form-urlencoded')) {
			parse_str($this->getContent(), $data);
			$this->request->replace($data);
		}
		elseif (str_starts_with($type, 'multipart/form-data') && function_exists('request_parse_body')) {
			try {
				[$data, $files] = request_parse_body();
				$this->request->replace($data);
				$this->files->replace($files);
			}
			catch (Throwable) {
				// Body không hợp lệ: giữ nguyên bag rỗng.
			}
		}
	}

	/** For JSON requests, expose the decoded body as $request->request (like Laravel). */
	protected function bindJsonToRequest(): void {
		if ($this->isJson()) {
			$this->request->replace($this->json()->all());
			$this->setJson($this->request);
		}
	}

	/**
	 * Symfony/Laravel: Request::create($uri, $method, $parameters, $cookies, $files, $server, $content).
	 */
	public static function create(
		string|Stringable $uri, string $method = 'GET', array $parameters = [],
		array $cookies = [], array $files = [], array $server = [], $content = null
	): static {
		$server = array_replace([
			'SERVER_NAME'          => 'localhost',
			'SERVER_PORT'          => 80,
			'HTTP_HOST'            => 'localhost',
			'HTTP_USER_AGENT'      => 'WPSPCORE',
			'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'HTTP_ACCEPT_LANGUAGE' => 'en-us,en;q=0.5',
			'HTTP_ACCEPT_CHARSET'  => 'ISO-8859-1,utf-8;q=0.7,*;q=0.7',
			'REMOTE_ADDR'          => '127.0.0.1',
			'SCRIPT_NAME'          => '',
			'SCRIPT_FILENAME'      => '',
			'SERVER_PROTOCOL'      => 'HTTP/1.1',
			'REQUEST_TIME'         => time(),
			'REQUEST_TIME_FLOAT'   => microtime(true),
		], $server);

		$server['PATH_INFO']      = '';
		$server['REQUEST_METHOD'] = strtoupper($method);

		$components = parse_url((string)$uri);
		if ($components === false) throw new \InvalidArgumentException(sprintf('Malformed URI "%s".', $uri));

		if (isset($components['host'])) {
			$server['SERVER_NAME'] = $components['host'];
			$server['HTTP_HOST']   = $components['host'];
		}
		if (isset($components['scheme'])) {
			if ($components['scheme'] === 'https') {
				$server['HTTPS']       = 'on';
				$server['SERVER_PORT'] = 443;
			}
			else {
				unset($server['HTTPS']);
				$server['SERVER_PORT'] = 80;
			}
		}
		if (isset($components['port'])) {
			$server['SERVER_PORT'] = $components['port'];
			$server['HTTP_HOST']   .= ':' . $components['port'];
		}
		if (isset($components['user'])) $server['PHP_AUTH_USER'] = $components['user'];
		if (isset($components['pass'])) $server['PHP_AUTH_PW'] = $components['pass'];
		$components['path'] ??= '/';

		switch ($server['REQUEST_METHOD']) {
			case 'POST':
			case 'PUT':
			case 'DELETE':
				$server['CONTENT_TYPE'] ??= 'application/x-www-form-urlencoded';
			// no break
			case 'PATCH':
				$request = $parameters;
				$query   = [];
				break;
			default:
				$request = [];
				$query   = $parameters;
		}

		$queryString = '';
		if (isset($components['query'])) {
			$qs = Arr::parseQuery(html_entity_decode($components['query']));
			if ($query) {
				$query       = array_replace($qs, $query);
				$queryString = Arr::query($query);
			}
			else {
				$query       = $qs;
				$queryString = $components['query'];
			}
		}
		elseif ($query) {
			$queryString = Arr::query($query);
		}

		$server['REQUEST_URI']  = $components['path'] . ($queryString !== '' ? '?' . $queryString : '');
		$server['QUERY_STRING'] = $queryString;

		return new static($query, $request, [], $cookies, $files, $server, $content);
	}

	/** Build from a Symfony Request (or any object exposing the same bags). */
	public static function createFromBase(object $base): static {
		$new = new static(
			$base->query->all(), $base->request->all(), $base->attributes->all(),
			$base->cookies->all(), $base->files->all(), $base->server->all(), $base->getContent()
		);

		$new->headers->replace($base->headers->all());
		$new->bindJsonToRequest();
		return $new;
	}

	public static function createFrom(self $from, ?self $to = null): static {
		$request = $to ?: new static;

		$request->initialize(
			$from->query->all(), $from->request->all(), $from->attributes->all(), $from->cookies->all(),
			array_filter($from->files->all()), $from->server->all(), $from->getContent()
		);

		$request->headers->replace($from->headers->all());
		$request->setRequestLocale($from->getLocale());
		$request->setDefaultRequestLocale($from->getDefaultLocale());
		$request->setJson($from->json());

		if ($from->hasSession()) $request->setLaravelSession($from->session());

		$request->setUserResolver($from->getUserResolver());
		$request->setRouteResolver($from->getRouteResolver());

		return $request;
	}

	public function duplicate(?array $query = null, ?array $request = null, ?array $attributes = null, ?array $cookies = null, ?array $files = null, ?array $server = null): static {
		$dup = clone $this;

		if ($query !== null) $dup->query->replace($query);
		if ($request !== null) $dup->request->replace($request);
		if ($attributes !== null) $dup->attributes->replace($attributes);
		if ($cookies !== null) $dup->cookies->replace($cookies);
		if ($files !== null) $dup->files->replace($this->filterFiles($files) ?? []);
		if ($server !== null) {
			$dup->server->replace($server);
			$dup->headers->replace($dup->extractHeaders($server));
		}
		if ($query !== null || $request !== null || $server !== null) $dup->json = null;

		$dup->isHostValid = true;
		return $dup->resetCache();
	}

	public function instance(): static {
		return $this;
	}

	protected function extractHeaders(array $server): array {
		$headers = [];
		foreach ($server as $key => $value) {
			$key = (string)$key;
			if (str_starts_with($key, 'HTTP_')) {
				$headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
			}
			elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && $value !== '') {
				$headers[strtolower(str_replace('_', '-', $key))] = $value;
			}
		}
		if (!isset($headers['authorization'])) {
			if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
				$headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
			}
			elseif (isset($server['PHP_AUTH_USER'])) {
				$headers['authorization'] = 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . ($server['PHP_AUTH_PW'] ?? ''));
			}
			elseif (isset($server['PHP_AUTH_DIGEST'])) {
				$headers['authorization'] = $server['PHP_AUTH_DIGEST'];
			}
		}
		return $headers;
	}

	/** Ghi request hiện tại ngược vào $_GET, $_POST, $_SERVER, $_COOKIE, $_REQUEST (như Symfony). */
	public function overrideGlobals(): void {
		$this->server->set('QUERY_STRING', static::normalizeQueryString(Arr::query($this->query->all())));

		$server = $this->server->all();
		foreach ($this->headers->all() as $key => $value) {
			$key = strtoupper(str_replace('-', '_', $key));
			$val = implode(', ', array_map('strval', $value));
			if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) $server[$key] = $val;
			else $server['HTTP_' . $key] = $val;
		}

		$get    = $this->query->all();
		$post   = $this->request->all();
		$cookie = $this->cookies->all();

		// WordPress mong đợi superglobals ở dạng đã slash.
		if (static::shouldUnslashGlobals() && function_exists('wp_slash')) {
			[$get, $post, $cookie, $server] = [wp_slash($get), wp_slash($post), wp_slash($cookie), wp_slash($server)];
		}

		$_GET     = $get;
		$_POST    = $post;
		$_COOKIE  = $cookie;
		$_SERVER  = $server;
		$_REQUEST = array_merge($get, $post);
	}

	/* ================================================================== *
	 | HTTP method
	 * ================================================================== */

	public static function enableHttpMethodParameterOverride(): void {
		static::$httpMethodParameterOverride = true;
	}

	public static function getHttpMethodParameterOverride(): bool {
		return static::$httpMethodParameterOverride;
	}

	public function getRealMethod(): string {
		return strtoupper((string)$this->server->get('REQUEST_METHOD', 'GET'));
	}

	/**
	 * Method thực tế, có xét override cho POST:
	 * header X-HTTP-Method-Override (luôn), hoặc tham số _method (khi bật parameter override).
	 */
	public function getMethod(): string {
		$method = $this->getRealMethod();
		if ($method !== 'POST') return $method;

		$override = $this->headers->get('X-HTTP-METHOD-OVERRIDE');
		if (!$override && static::$httpMethodParameterOverride) {
			$override = $this->request->get('_method', $this->query->get('_method'));
		}
		if (!is_string($override) || $override === '') return $method;

		$override = strtoupper($override);
		return in_array($override, static::OVERRIDABLE_METHODS, true) ? $override : $method;
	}

	public function method(): string {
		return $this->getMethod();
	}

	public function setMethod(string $method): void {
		$this->server->set('REQUEST_METHOD', strtoupper($method));
	}

	public function isMethod(string $method): bool {
		return $this->getMethod() === strtoupper($method);
	}

	public function isMethodSafe(): bool {
		return in_array($this->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
	}

	public function isMethodIdempotent(): bool {
		return in_array($this->getMethod(), ['HEAD', 'GET', 'PUT', 'DELETE', 'TRACE', 'OPTIONS', 'PURGE'], true);
	}

	public function isMethodCacheable(): bool {
		return in_array($this->getMethod(), ['GET', 'HEAD'], true);
	}

	/* ================================================================== *
	 | URL / path
	 * ================================================================== */

	public function setBaseUrl(string $baseUrl): static {
		$this->baseUrl  = rtrim($baseUrl, '/');
		$this->pathInfo = null;
		return $this;
	}

	/** Thư mục cài app ("" khi ở root), có cộng X-Forwarded-Prefix từ proxy tin cậy. */
	public function getBaseUrl(): string {
		return $this->getTrustedPrefix() . $this->getBaseUrlReal();
	}

	public function getBasePath(): string {
		return $this->getBaseUrl();
	}

	protected function getBaseUrlReal(): string {
		return $this->baseUrl ??= $this->prepareBaseUrl();
	}

	protected function getTrustedPrefix(): string {
		if (!$this->isFromTrustedProxy()) return '';
		return rtrim($this->getTrustedValues(self::HEADER_X_FORWARDED_PREFIX)[0] ?? '', '/');
	}

	public function getScriptName(): string {
		return (string)$this->server->get('SCRIPT_NAME', $this->server->get('ORIG_SCRIPT_NAME', ''));
	}

	protected function prepareBaseUrl(): string {
		$uri = $this->requestUriPath();

		// WordPress: dùng đường dẫn của site (option "home", không qua filter để tránh prefix ngôn ngữ...).
		if (function_exists('get_option') || function_exists('home_url')) {
			$home = function_exists('get_option') ? (string)get_option('home') : (string)home_url('/');
			$dir  = rtrim((string)parse_url($home, PHP_URL_PATH), '/');
			return $dir !== '' && static::pathStartsWith($uri, $dir) ? $dir : '';
		}

		$script = str_replace('\\', '/', $this->getScriptName());
		if ($script === '') return '';
		if (static::pathStartsWith($uri, $script)) return $script;   // /index.php/foo

		$dir = dirname($script);
		$dir = in_array($dir, ['.', '/', '\\'], true) ? '' : rtrim($dir, '/');
		return $dir !== '' && static::pathStartsWith($uri, $dir) ? $dir : '';
	}

	protected static function pathStartsWith(string $path, string $prefix): bool {
		return $path === $prefix || str_starts_with($path, $prefix . '/');
	}

	public function getRequestUri(): string {
		return (string)$this->server->get('REQUEST_URI', '/');
	}

	protected function requestUriPath(): string {
		$uri = $this->getRequestUri();
		if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $uri)) $uri = (string)parse_url($uri, PHP_URL_PATH);   // absolute-form
		$path = strstr($uri, '?', true);
		$path = $path === false ? $uri : $path;
		return $path === '' ? '/' : $path;
	}

	/** Raw path after the base URL, with leading slash ("/" for root). */
	public function getPathInfo(): string {
		if ($this->pathInfo !== null) return $this->pathInfo;

		$path = $this->requestUriPath();
		$base = $this->getBaseUrlReal();
		if ($base !== '' && static::pathStartsWith($path, $base)) $path = substr($path, strlen($base));
		return $this->pathInfo = ($path === '' ? '/' : $path);
	}

	public function path(): string {
		$pattern = trim($this->getPathInfo(), '/');
		return $pattern === '' ? '/' : $pattern;
	}

	public function decodedPath(): string {
		return rawurldecode($this->path());
	}

	public function segments(): array {
		return array_values(array_filter(explode('/', $this->decodedPath()), fn($v) => $v !== ''));
	}

	public function segment(int $index, $default = null) {
		return $this->segments()[$index - 1] ?? $default;
	}

	/** Query string đã chuẩn hoá (sắp xếp key, RFC 3986) hoặc null nếu rỗng - như Symfony. */
	public function getQueryString(): ?string {
		$qs = $this->server->has('QUERY_STRING')
			? (string)$this->server->get('QUERY_STRING')
			: Arr::query($this->query->all());

		$qs = static::normalizeQueryString($qs);
		return $qs === '' ? null : $qs;
	}

	public static function normalizeQueryString(?string $qs): string {
		if ($qs === null || $qs === '') return '';
		$query = Arr::parseQuery($qs);
		ksort($query);
		return Arr::query($query);
	}

	public function getScheme(): string {
		return $this->isSecure() ? 'https' : 'http';
	}

	/** Host đã kiểm tra hợp lệ (+ trusted hosts nếu có cấu hình), không kèm port. */
	public function getHost(): string {
		if ($this->isFromTrustedProxy() && ($forwarded = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST))) {
			$host = $forwarded[0];
		}
		else {
			$host = $this->headers->get('Host')
				?: ($this->server->get('SERVER_NAME') ?: $this->server->get('SERVER_ADDR', ''));
		}

		$host = strtolower((string)preg_replace('/:\d+$/', '', trim((string)$host)));

		// Chỉ cho phép ký tự hợp lệ (chặn Host header injection).
		if ($host !== '' && preg_replace('/(?:^\[)?[a-zA-Z0-9-:\]_]+\.?/', '', $host) !== '') {
			return $this->rejectHost(sprintf('Invalid Host "%s".', $host));
		}

		if (static::$trustedHostPatterns) {
			if (in_array($host, static::$trustedHosts, true)) return $host;
			foreach (static::$trustedHostPatterns as $pattern) {
				if (preg_match($pattern, $host)) {
					static::$trustedHosts[] = $host;
					return $host;
				}
			}
			return $this->rejectHost(sprintf('Untrusted Host "%s".', $host));
		}

		return $host;
	}

	protected function rejectHost(string $message): string {
		if (!$this->isHostValid) return '';
		$this->isHostValid = false;
		throw new SuspiciousOperationException($message);
	}

	public function getPort(): int|string|null {
		$fromProxy = $this->isFromTrustedProxy();

		if ($fromProxy && ($port = $this->getTrustedValues(self::HEADER_X_FORWARDED_PORT))) {
			$port = $port[0];
			if (ctype_digit($port)) return (int)$port;
			$host = $port;
		}
		elseif ($fromProxy && ($forwarded = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST))) {
			$host = $forwarded[0];
		}
		elseif (!($host = $this->headers->get('Host'))) {
			return $this->server->get('SERVER_PORT');
		}

		$pos = $host[0] === '[' ? strpos($host, ':', (int)strrpos($host, ']')) : strrpos($host, ':');
		if ($pos !== false && ($port = substr($host, $pos + 1)) !== '' && ctype_digit($port)) return (int)$port;

		return $this->getScheme() === 'https' ? 443 : 80;
	}

	/** Host kèm port nếu không phải port mặc định. */
	public function getHttpHost(): string {
		$scheme = $this->getScheme();
		$port   = (int)$this->getPort();
		if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) || $port === 0) {
			return $this->getHost();
		}
		return $this->getHost() . ':' . $port;
	}

	public function getSchemeAndHttpHost(): string {
		return $this->getScheme() . '://' . $this->getHttpHost();
	}

	public function getUri(): string {
		$qs = $this->getQueryString();
		return $this->getUriForPath($this->getPathInfo()) . ($qs !== null ? '?' . $qs : '');
	}

	public function getUriForPath(string $path): string {
		return $this->getSchemeAndHttpHost() . $this->getBaseUrl() . $path;
	}

	/** Đường dẫn tương đối từ path hiện tại tới $path (Symfony). */
	public function getRelativeUriForPath(string $path): string {
		if ($path === '' || $path[0] !== '/') return $path;

		$basePath = $this->getPathInfo();
		if ($path === $basePath) return '';

		$sourceDirs = explode('/', ltrim($basePath, '/'));
		$targetDirs = explode('/', substr($path, 1));
		array_pop($sourceDirs);
		$targetFile = array_pop($targetDirs);

		foreach ($sourceDirs as $i => $dir) {
			if (isset($targetDirs[$i]) && $dir === $targetDirs[$i]) {
				unset($sourceDirs[$i], $targetDirs[$i]);
			}
			else {
				break;
			}
		}

		$targetDirs[] = $targetFile;
		$path         = str_repeat('../', count($sourceDirs)) . implode('/', $targetDirs);

		$colon = strpos($path, ':');
		$slash = strpos($path, '/');
		return $path === '' || $path[0] === '/' || ($colon !== false && ($slash === false || $colon < $slash)) ? './' . $path : $path;
	}

	public function getUser(): ?string {
		return $this->server->get('PHP_AUTH_USER') ?? $this->basicCredentials()[0];
	}

	public function getPassword(): ?string {
		return $this->server->get('PHP_AUTH_PW') ?? $this->basicCredentials()[1];
	}

	public function getUserInfo(): ?string {
		$user = $this->getUser();
		$pass = $this->getPassword();
		return $pass !== null && $pass !== '' ? $user . ':' . $pass : $user;
	}

	/** Đọc user/pass từ header "Authorization: Basic ..." khi PHP không tự điền PHP_AUTH_* (FastCGI). */
	protected function basicCredentials(): array {
		$header = (string)$this->headers->get('Authorization', '');
		if (stripos($header, 'basic ') !== 0) return [null, null];

		$decoded = base64_decode(substr($header, 6), true);
		if ($decoded === false || !str_contains($decoded, ':')) return [null, null];
		return explode(':', $decoded, 2);
	}

	public function getProtocolVersion(): ?string {
		return $this->server->get('SERVER_PROTOCOL');
	}

	public function root(): string {
		return rtrim($this->getSchemeAndHttpHost() . $this->getBaseUrl(), '/');
	}

	public function url(): string {
		return rtrim((string)preg_replace('/\?.*/', '', $this->getUri()), '/');
	}

	protected function queryQuestionMark(): string {
		return $this->getBaseUrl() . $this->getPathInfo() === '/' ? '/?' : '?';
	}

	public function fullUrl(): string {
		$query = $this->getQueryString();
		return $query !== null ? $this->url() . $this->queryQuestionMark() . $query : $this->url();
	}

	public function fullUrlWithQuery(array $query): string {
		return $this->query->count() > 0
			? $this->url() . $this->queryQuestionMark() . Arr::query(array_merge($this->query->all(), $query))
			: $this->fullUrl() . $this->queryQuestionMark() . Arr::query($query);
	}

	public function fullUrlWithoutQuery(array|string $keys): string {
		$query = Arr::except($this->query->all(), $keys);
		return count($query) > 0
			? $this->url() . $this->queryQuestionMark() . Arr::query($query)
			: $this->url();
	}

	/** URI đầy đủ dưới dạng đối tượng Uri (thay cho Illuminate\Support\Uri). */
	public function uri(): Uri {
		return Uri::of($this->fullUrl());
	}

	/** $request->is('admin/*', 'api/*') - "/" ở đầu/cuối pattern được bỏ qua. */
	public function is(string ...$patterns): bool {
		$path = $this->decodedPath();
		foreach ($patterns as $pattern) {
			if (static::matchesPattern($pattern, $path)) return true;
		}
		return false;
	}

	public function fullUrlIs(string ...$patterns): bool {
		$url = $this->fullUrl();
		foreach ($patterns as $pattern) {
			if (static::matchesPattern($pattern, $url)) return true;
		}
		return false;
	}

	public function routeIs(string ...$patterns): bool {
		$route = $this->route();
		return is_object($route) && method_exists($route, 'named') && $route->named(...$patterns);
	}

	protected static function matchesPattern(string $pattern, string $value): bool {
		if ($pattern === $value) return true;
		if ($pattern !== '/' && !str_contains($pattern, '://')) {
			$pattern = trim($pattern, '/') ?: '/';
		}
		$regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '\z#u';
		return (bool)preg_match($regex, $value);
	}

	public function host(): string {
		return $this->getHost();
	}

	public function httpHost(): string {
		return $this->getHttpHost();
	}

	public function schemeAndHttpHost(): string {
		return $this->getSchemeAndHttpHost();
	}

	/* ================================================================== *
	 | Signed URLs (Laravel: hasValidSignature...) - ký bằng Uri::signed()
	 * ================================================================== */

	public function hasValidSignature(bool $absolute = true, array $ignoreQuery = []): bool {
		return $this->hasCorrectSignature($absolute, $ignoreQuery) && $this->signatureHasNotExpired();
	}

	public function hasValidRelativeSignature(): bool {
		return $this->hasValidSignature(false);
	}

	public function hasValidSignatureWhileIgnoring(array $ignoreQuery = [], bool $absolute = true): bool {
		return $this->hasValidSignature($absolute, $ignoreQuery);
	}

	public function hasValidRelativeSignatureWhileIgnoring(array $ignoreQuery = []): bool {
		return $this->hasValidSignature(false, $ignoreQuery);
	}

	public function hasCorrectSignature(bool $absolute = true, array $ignoreQuery = []): bool {
		$signature = $this->query->get('signature');
		if (!is_string($signature) || $signature === '') return false;

		$base    = $absolute ? $this->url() : '/' . trim($this->getBaseUrl() . $this->getPathInfo(), '/');
		$payload = Uri::signaturePayload($base, (string)$this->server->get('QUERY_STRING', ''), $ignoreQuery);

		foreach (Uri::signingKeys() as $key) {
			if (hash_equals(hash_hmac('sha256', $payload, $key), $signature)) return true;
		}
		return false;
	}

	public function signatureHasNotExpired(): bool {
		$expires = $this->query->get('expires');
		return !($expires && time() > (int)$expires);
	}

	/* ================================================================== *
	 | Client, proxies, security
	 * ================================================================== */

	/**
	 * $proxies: IP / CIDR, hoặc 'REMOTE_ADDR' / '*' (tin mọi kết nối trực tiếp), 'PRIVATE_SUBNETS'.
	 * $trustedHeaderSet: tổ hợp HEADER_* (mặc định: tất cả).
	 */
	public static function setTrustedProxies(array $proxies, int $trustedHeaderSet = -1): void {
		static::$trustedProxies   = array_values($proxies);
		static::$trustedHeaderSet = $trustedHeaderSet;
	}

	public static function getTrustedProxies(): array {
		return static::$trustedProxies;
	}

	public static function getTrustedHeaderSet(): int {
		return static::$trustedHeaderSet;
	}

	/** Regex host hợp lệ, vd. ['^(.+\.)?example\.com$']. Host khác => SuspiciousOperationException. */
	public static function setTrustedHosts(array $hostPatterns): void {
		static::$trustedHostPatterns = array_map(fn($p) => sprintf('{%s}i', $p), $hostPatterns);
		static::$trustedHosts        = [];
	}

	public static function getTrustedHosts(): array {
		return static::$trustedHostPatterns;
	}

	protected function trustedProxyList(): array {
		$list = [];
		foreach (static::$trustedProxies as $proxy) {
			if ($proxy === 'REMOTE_ADDR' || $proxy === '*') {
				if ($remote = $this->server->get('REMOTE_ADDR')) $list[] = (string)$remote;
			}
			elseif ($proxy === 'PRIVATE_SUBNETS') {
				array_push($list, ...IpUtils::PRIVATE_SUBNETS);
			}
			else {
				$list[] = (string)$proxy;
			}
		}
		return $list;
	}

	public function isFromTrustedProxy(): bool {
		$remote = (string)$this->server->get('REMOTE_ADDR', '');
		return $remote !== '' && static::$trustedProxies && IpUtils::checkIp($remote, $this->trustedProxyList());
	}

	/** Giá trị header proxy (X-Forwarded-* hoặc RFC 7239 Forwarded), KHÔNG kiểm tra proxy tin cậy. */
	protected function getTrustedValues(int $type): array {
		$values = [];

		if ((static::$trustedHeaderSet & $type) && ($header = $this->headers->get(self::TRUSTED_HEADERS[$type])) !== null) {
			foreach (explode(',', $header) as $value) {
				$value = trim($value);
				if ($value !== '') $values[] = $value;
			}
		}

		if (!$values && (static::$trustedHeaderSet & self::HEADER_FORWARDED) && isset(self::FORWARDED_PARAMS[$type])
			&& ($forwarded = $this->headers->get('Forwarded'))) {
			$param = self::FORWARDED_PARAMS[$type];
			foreach (explode(',', $forwarded) as $element) {
				foreach (explode(';', $element) as $pair) {
					$kv = explode('=', $pair, 2);
					if (count($kv) !== 2 || strtolower(trim($kv[0])) !== $param) continue;

					$value = trim(trim($kv[1]), '"');
					if ($type === self::HEADER_X_FORWARDED_PORT) {
						$pos   = strrpos($value, ':');
						$value = str_ends_with($value, ']') || $pos === false ? ($this->isSecure() ? '443' : '80') : substr($value, $pos + 1);
					}
					if ($value !== '') $values[] = $value;
				}
			}
		}

		return $values;
	}

	public function isSecure(): bool {
		if ($this->isFromTrustedProxy() && ($proto = $this->getTrustedValues(self::HEADER_X_FORWARDED_PROTO))) {
			return in_array(strtolower($proto[0]), ['https', 'on', 'ssl', '1'], true);
		}
		$https = $this->server->get('HTTPS');
		return $https !== null && $https !== '' && strtolower((string)$https) !== 'off';
	}

	public function secure(): bool {
		return $this->isSecure();
	}

	/** Gần nhất trước, IP client gốc (kém tin cậy nhất) ở cuối; chỉ gồm các IP không thuộc proxy tin cậy. */
	public function getClientIps(): array {
		$remote = (string)$this->server->get('REMOTE_ADDR', '');
		if ($remote === '') return [];
		if (!$this->isFromTrustedProxy()) return [$remote];

		$chain = [];
		foreach ($this->getTrustedValues(self::HEADER_X_FORWARDED_FOR) as $ip) {
			if (($ip = static::normalizeIp($ip)) !== null) $chain[] = $ip;
		}
		if (!$chain) return [$remote];
		$chain[] = $remote;

		$proxies   = $this->trustedProxyList();
		$untrusted = [];
		foreach (array_reverse($chain) as $ip) {
			if (!IpUtils::checkIp($ip, $proxies)) $untrusted[] = $ip;
		}

		// Toàn bộ chuỗi đều là proxy tin cậy => client là IP xa nhất.
		return $untrusted ?: [$chain[0]];
	}

	/** Bỏ port và dấu [] của IP trong header ("[2001:db8::1]:443", "1.2.3.4:80"). */
	protected static function normalizeIp(string $ip): ?string {
		$ip = trim($ip);
		if ($ip === '') return null;
		if ($ip[0] === '[') {
			$end = strpos($ip, ']');
			$ip  = $end === false ? $ip : substr($ip, 1, $end - 1);
		}
		elseif (substr_count($ip, ':') === 1) {
			$ip = explode(':', $ip, 2)[0];
		}
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
	}

	public function getClientIp(): ?string {
		return $this->getClientIps()[0] ?? null;
	}

	public function ips(): array {
		return $this->getClientIps();
	}

	public function ip(): ?string {
		return $this->getClientIp();
	}

	public function userAgent(): ?string {
		return $this->headers->get('User-Agent');
	}

	public function ajax(): bool {
		return $this->isXmlHttpRequest();
	}

	public function isXmlHttpRequest(): bool {
		return strtolower((string)$this->headers->get('X-Requested-With', '')) === 'xmlhttprequest';
	}

	public function pjax(): bool {
		return $this->headers->get('X-PJAX') == true;
	}

	public function prefetch(): bool {
		return strcasecmp((string)$this->server->get('HTTP_X_MOZ', ''), 'prefetch') === 0
			|| strcasecmp((string)$this->headers->get('Purpose', ''), 'prefetch') === 0
			|| strcasecmp((string)$this->headers->get('Sec-Purpose', ''), 'prefetch') === 0;
	}

	/* ================================================================== *
	 | Headers
	 * ================================================================== */

	/** $request->header() => all headers; $request->header('X-Foo') => first value. */
	public function header(?string $key = null, $default = null) {
		if ($key === null) return $this->headers->all();
		return $this->headers->has($key) ? $this->headers->get($key) : $default;
	}

	public function hasHeader(string $key): bool {
		return $this->headers->has($key);
	}

	public function bearerToken(): ?string {
		$header   = (string)$this->header('Authorization', '');
		$position = strripos($header, 'Bearer ');
		if ($position === false) return null;

		$header = substr($header, $position + 7);
		return str_contains($header, ',') ? strstr($header, ',', true) : $header;
	}

	/* ================================================================== *
	 | Content negotiation (InteractsWithContentTypes)
	 * ================================================================== */

	public function getContentType(): ?string {
		$type = $this->headers->get('Content-Type');
		return $type === null ? null : strtolower(trim(explode(';', $type)[0]));
	}

	public function getContentTypeFormat(): ?string {
		return $this->getFormat($this->getContentType());
	}

	public function getMimeType(string $format): ?string {
		return static::$formats[$format][0] ?? null;
	}

	public static function getMimeTypes(string $format): array {
		return static::$formats[$format] ?? [];
	}

	public function getFormat(?string $mimeType): ?string {
		if ($mimeType === null) return null;
		$canonical = strtolower(trim(explode(';', $mimeType)[0]));
		foreach (static::$formats as $format => $mimeTypes) {
			if (in_array($canonical, $mimeTypes, true)) return $format;
		}
		return null;
	}

	/** Thêm/ghi đè định dạng: Request::setFormat('csv', 'text/csv'). */
	public static function setFormat(string $format, string|array $mimeTypes): void {
		static::$formats[$format] = (array)$mimeTypes;
	}

	public function getRequestFormat(?string $default = 'html'): ?string {
		$this->format ??= $this->attributes->get('_format');
		return $this->format ?? $default;
	}

	public function setRequestFormat(?string $format): void {
		$this->format = $format;
	}

	/** Định dạng ưu tiên: _format attribute > Accept > $default. */
	public function getPreferredFormat(?string $default = 'html'): ?string {
		if ($format = $this->getRequestFormat(null)) return $format;
		foreach ($this->getAcceptableContentTypes() as $type) {
			if ($format = $this->getFormat($type)) return $format;
		}
		return $default;
	}

	public function isJson(): bool {
		$type = strtolower((string)$this->headers->get('Content-Type', ''));
		return str_contains($type, '/json') || str_contains($type, '+json');
	}

	public function getAcceptableContentTypes(): array {
		return $this->acceptableContentTypes ??= static::parseAcceptHeader((string)$this->headers->get('Accept', ''));
	}

	public function getCharsets(): array {
		return static::parseAcceptHeader((string)$this->headers->get('Accept-Charset', ''));
	}

	public function getEncodings(): array {
		return static::parseAcceptHeader((string)$this->headers->get('Accept-Encoding', ''));
	}

	public function getETags(): array {
		return preg_split('/\s*,\s*/', (string)$this->headers->get('If-None-Match', ''), -1, PREG_SPLIT_NO_EMPTY);
	}

	public function isNoCache(): bool {
		return $this->headers->hasCacheControlDirective('no-cache') || $this->headers->get('Pragma') === 'no-cache';
	}

	public function preferSafeContent(): bool {
		return $this->isSecure() && str_contains(strtolower((string)$this->headers->get('Prefer', '')), 'safe');
	}

	/** Danh sách giá trị theo q giảm dần (giữ thứ tự gốc khi bằng nhau), bỏ các mục q=0. */
	protected static function parseAcceptHeader(string $header): array {
		if (trim($header) === '') return [];

		$items = [];
		foreach (explode(',', $header) as $part) {
			$bits = explode(';', $part);
			$name = strtolower(trim(array_shift($bits)));
			if ($name === '') continue;

			$q = 1.0;
			foreach ($bits as $param) {
				if (preg_match('/^\s*q\s*=\s*"?([\d.]+)"?\s*$/i', $param, $m)) $q = (float)$m[1];
			}
			if ($q <= 0) continue;
			if (!isset($items[$name]) || $items[$name] < $q) $items[$name] = $q;
		}

		arsort($items);   // PHP 8 sort ổn định
		return array_map('strval', array_keys($items));
	}

	public function expectsJson(): bool {
		return ($this->ajax() && !$this->pjax() && $this->acceptsAnyContentType()) || $this->wantsJson();
	}

	public function wantsJson(): bool {
		$acceptable = $this->getAcceptableContentTypes();
		if (!isset($acceptable[0])) return false;
		$first = strtolower($acceptable[0]);
		return str_contains($first, '/json') || str_contains($first, '+json');
	}

	public function accepts(string|array $contentTypes): bool {
		$accepts = $this->getAcceptableContentTypes();
		if (count($accepts) === 0) return true;

		$types = array_map('strtolower', (array)$contentTypes);
		foreach ($accepts as $accept) {
			if ($accept === '*/*' || $accept === '*') return true;
			foreach ($types as $type) {
				if (static::matchesType($accept, $type) || $accept === strtok($type, '/') . '/*') return true;
			}
		}
		return false;
	}

	public function prefers(string|array $contentTypes): ?string {
		$contentTypes = (array)$contentTypes;

		foreach ($this->getAcceptableContentTypes() as $accept) {
			if (in_array($accept, ['*/*', '*'], true)) return $contentTypes[0] ?? null;
			foreach ($contentTypes as $contentType) {
				$type = strtolower($this->getMimeType($contentType) ?? $contentType);
				if (static::matchesType($type, $accept) || $accept === strtok($type, '/') . '/*') return $contentType;
			}
		}
		return null;
	}

	public function acceptsAnyContentType(): bool {
		$acceptable = $this->getAcceptableContentTypes();
		return count($acceptable) === 0 || $acceptable[0] === '*/*' || $acceptable[0] === '*';
	}

	public function acceptsJson(): bool {
		return $this->accepts('application/json');
	}

	public function acceptsHtml(): bool {
		return $this->accepts('text/html');
	}

	public static function matchesType(string $actual, string $type): bool {
		if ($actual === $type) return true;
		$split = explode('/', $actual);
		return isset($split[1]) && (bool)preg_match('#' . preg_quote($split[0], '#') . '/.+\+' . preg_quote($split[1], '#') . '#', $type);
	}

	public function format(string $default = 'html'): string {
		foreach ($this->getAcceptableContentTypes() as $type) {
			if ($format = $this->getFormat($type)) return $format;
		}
		return $default;
	}

	/* ------------------------------------------------------------------ *
	 | Languages / locale
	 * ------------------------------------------------------------------ */

	/** Accept-Language đã chuẩn hoá: "en-us" => "en_US", "zh-hant-tw" => "zh_Hant_TW". */
	public function getLanguages(): array {
		if ($this->languages !== null) return $this->languages;

		$languages = [];
		foreach (static::parseAcceptHeader((string)$this->headers->get('Accept-Language', '')) as $code) {
			if ($code === '*') continue;
			$parts = explode('-', $code);
			if ($parts[0] === 'i' && isset($parts[1])) {
				$lang = $parts[1];   // i-klingon
			}
			else {
				$lang = $parts[0];
				foreach (array_slice($parts, 1) as $part) {
					$lang .= '_' . (strlen($part) === 2 ? strtoupper($part) : ucfirst($part));
				}
			}
			$languages[] = $lang;
		}
		return $this->languages = array_values(array_unique($languages));
	}

	public function getPreferredLanguage(?array $locales = null): ?string {
		$preferred = $this->getLanguages();
		if (!$locales) return $preferred[0] ?? null;
		if (!$preferred) return $locales[0];

		// Như Symfony: sau mỗi "vi_VN" thử luôn "vi" (nếu "vi" không có sẵn trong danh sách).
		$norm     = fn($l) => strtolower(str_replace('-', '_', $l));
		$extended = [];
		foreach ($preferred as $p) {
			$extended[] = $norm($p);
			$primary    = strstr($norm($p), '_', true);
			if ($primary !== false && !in_array($primary, array_map($norm, $preferred), true)) $extended[] = $primary;
		}

		foreach (array_unique($extended) as $wanted) {
			foreach ($locales as $l) {
				if ($norm($l) === $wanted) return $l;
			}
		}
		return $locales[0];
	}

	public function getLocale(): string {
		return $this->locale ?? $this->defaultLocale;
	}

	public function setLocale(string $locale): void {
		$this->locale = $locale;
	}

	public function getDefaultLocale(): string {
		return $this->defaultLocale;
	}

	public function setDefaultLocale(string $locale): void {
		$this->defaultLocale = $locale;
	}

	public function setRequestLocale(string $locale): void {
		$this->locale = $locale;
	}

	public function setDefaultRequestLocale(string $locale): void {
		$this->defaultLocale = $locale;
	}

	/* ================================================================== *
	 | Body / JSON
	 * ================================================================== */

	/**
	 * Raw body. $asResource = true => stream (không nạp cả body vào bộ nhớ).
	 * @return string|resource
	 */
	public function getContent(bool $asResource = false) {
		if ($asResource) {
			if (is_resource($this->content)) {
				rewind($this->content);
				return $this->content;
			}
			if (is_string($this->content)) {
				$stream = fopen('php://temp', 'r+');
				fwrite($stream, $this->content);
				rewind($stream);
				return $stream;
			}
			return fopen('php://input', 'rb');
		}

		if (is_resource($this->content)) {
			rewind($this->content);
			return (string)stream_get_contents($this->content);
		}
		return $this->content ??= (string)file_get_contents('php://input');
	}

	/** $request->json() => ParameterBag; $request->json('a.b') => value. */
	public function json(?string $key = null, $default = null) {
		if ($this->json === null) {
			$content    = trim($this->getContent());
			$decoded    = $content === '' ? [] : json_decode($content, true);
			$this->json = new ParameterBag(is_array($decoded) ? $decoded : []);
		}
		if ($key === null) return $this->json;
		return Arr::get($this->json->all(), $key, $default);
	}

	public function setJson(ParameterBag $json): static {
		$this->json = $json;
		return $this;
	}

	/** Symfony getPayload(): body JSON hoặc form, tuỳ Content-Type. */
	public function getPayload(): ParameterBag {
		return $this->isJson() ? $this->json() : $this->request;
	}

	/* ================================================================== *
	 | Input (InteractsWithInput / InteractsWithData)
	 * ================================================================== */

	protected function getInputSource(): ParameterBag {
		if ($this->isJson()) return $this->json();
		return in_array($this->getRealMethod(), ['GET', 'HEAD'], true) ? $this->query : $this->request;
	}

	public function server(?string $key = null, $default = null) {
		return $key === null ? $this->server->all() : $this->server->get($key, $default);
	}

	public function all($keys = null): array {
		$input = array_replace_recursive($this->input(), $this->allFiles());
		if ($keys === null) return $input;

		$results = [];
		foreach (is_array($keys) ? $keys : func_get_args() as $key) {
			Arr::set($results, $key, Arr::get($input, $key));
		}
		return $results;
	}

	public function input(?string $key = null, $default = null) {
		$data = $this->getInputSource()->all() + $this->query->all();
		return $key === null ? $data : Arr::get($data, $key, $default);
	}

	public function query(?string $key = null, $default = null) {
		return $key === null ? $this->query->all() : Arr::get($this->query->all(), $key, $default);
	}

	public function post(?string $key = null, $default = null) {
		return $key === null ? $this->request->all() : Arr::get($this->request->all(), $key, $default);
	}

	public function keys(): array {
		return array_merge(array_keys($this->input()), $this->files->keys());
	}

	protected function keysFrom(array $args): array {
		return is_array($args[0] ?? null) ? $args[0] : $args;
	}

	public function exists(...$keys): bool {
		return $this->has(...$keys);
	}

	public function has(...$keys): bool {
		$input = $this->all();
		foreach ($this->keysFrom($keys) as $key) {
			if (!Arr::has($input, $key)) return false;
		}
		return true;
	}

	public function hasAny(...$keys): bool {
		$input = $this->all();
		foreach ($this->keysFrom($keys) as $key) {
			if (Arr::has($input, $key)) return true;
		}
		return false;
	}

	/** True when at least one key is absent (mirrors Laravel: !has($keys)). */
	public function missing(...$keys): bool {
		return !$this->has($this->keysFrom($keys));
	}

	public function filled(...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if ($this->isEmptyString((string)$key)) return false;
		}
		return true;
	}

	public function isNotFilled(...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if (!$this->isEmptyString((string)$key)) return false;
		}
		return true;
	}

	public function anyFilled(...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if ($this->filled($key)) return true;
		}
		return false;
	}

	protected function isEmptyString(string $key): bool {
		$value = $this->input($key);
		return !is_bool($value) && !is_array($value) && !is_object($value) && trim((string)$value) === '';
	}

	public function whenHas(string $key, callable $callback, ?callable $default = null) {
		if ($this->has($key)) return $callback(Arr::get($this->all(), $key)) ?: $this;
		return $default ? $default() : $this;
	}

	public function whenFilled(string $key, callable $callback, ?callable $default = null) {
		if ($this->filled($key)) return $callback(Arr::get($this->all(), $key)) ?: $this;
		return $default ? $default() : $this;
	}

	public function whenMissing(string $key, callable $callback, ?callable $default = null) {
		if ($this->missing($key)) return $callback(Arr::get($this->all(), $key)) ?: $this;
		return $default ? $default() : $this;
	}

	public function str(string $key, $default = null) {
		return $this->string($key, $default);
	}

	/** Illuminate\Support\Stringable nếu có, ngược lại string thuần. */
	public function string(string $key, $default = null) {
		$value = $this->input($key, $default);
		$value = is_scalar($value) || $value instanceof Stringable ? (string)$value : '';
		return class_exists('\Illuminate\Support\Stringable') ? new \Illuminate\Support\Stringable($value) : $value;
	}

	public function boolean(?string $key = null, bool $default = false): bool {
		$value = $this->input($key, $default);
		return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	public function integer(string $key, int $default = 0): int {
		$value = $this->input($key, $default);
		return is_scalar($value) ? intval($value) : $default;
	}

	public function float(string $key, float $default = 0.0): float {
		$value = $this->input($key, $default);
		return is_scalar($value) ? floatval($value) : $default;
	}

	/** Giá trị dạng mảng (giá trị đơn được bọc thành mảng). */
	public function array(?string $key = null): array {
		return Arr::wrap($this->input($key));
	}

	/** Carbon nếu có, ngược lại DateTimeImmutable; null nếu rỗng hoặc sai định dạng. */
	public function date(string $key, ?string $format = null, ?string $tz = null) {
		if ($this->isNotFilled($key)) return null;
		$value = (string)$this->input($key);

		if (class_exists('\Carbon\Carbon')) {
			return $format === null
				? \Carbon\Carbon::parse($value, $tz)
				: \Carbon\Carbon::createFromFormat($format, $value, $tz);
		}

		$zone = $tz ? new DateTimeZone($tz) : null;
		try {
			return $format === null
				? new DateTimeImmutable($value, $zone)
				: (DateTimeImmutable::createFromFormat($format, $value, $zone) ?: null);
		}
		catch (Throwable) {
			return null;
		}
	}

	public function enum(string $key, string $enumClass, $default = null) {
		if ($this->isNotFilled($key) || !is_subclass_of($enumClass, \BackedEnum::class)) return Arr::value($default);

		try {
			return $enumClass::tryFrom($this->input($key)) ?? Arr::value($default);
		}
		catch (\TypeError|\ValueError) {
			return Arr::value($default);
		}
	}

	public function enums(string $key, string $enumClass): array {
		if ($this->isNotFilled($key) || !is_subclass_of($enumClass, \BackedEnum::class)) return [];

		$result = [];
		foreach ((array)$this->input($key) as $value) {
			try {
				if (($case = $enumClass::tryFrom($value)) !== null) $result[] = $case;
			}
			catch (\TypeError|\ValueError) {
				// bỏ qua giá trị sai kiểu
			}
		}
		return $result;
	}

	/** Illuminate\Support\Collection nếu có, ngược lại ArrayObject. */
	public function collect($key = null) {
		$data = is_array($key) ? $this->only($key) : $this->input($key);
		$data = Arr::wrap($data);
		return class_exists('\Illuminate\Support\Collection') ? new \Illuminate\Support\Collection($data) : new \ArrayObject($data);
	}

	public function only(...$keys): array {
		return Arr::only($this->all(), $this->keysFrom($keys));
	}

	public function except(...$keys): array {
		return Arr::except($this->all(), $this->keysFrom($keys));
	}

	public function merge(array $input): static {
		$source = $this->getInputSource();
		$all    = $source->all();
		foreach ($input as $key => $value) Arr::set($all, $key, $value);
		$source->replace($all);
		return $this;
	}

	public function mergeIfMissing(array $input): static {
		return $this->merge(array_filter($input, fn($v, $k) => $this->missing((string)$k), ARRAY_FILTER_USE_BOTH));
	}

	public function replace(array $input): static {
		$this->getInputSource()->replace($input);
		return $this;
	}

	/** Symfony-style getter: attributes > query > request. */
	public function get(string $key, mixed $default = null): mixed {
		if ($this->attributes->has($key)) return $this->attributes->get($key);
		if ($this->query->has($key)) return $this->query->get($key);
		if ($this->request->has($key)) return $this->request->get($key);
		return $default;
	}

	public function dump(...$keys): static {
		$keys = $this->keysFrom($keys);
		$data = count($keys) > 0 ? $this->only($keys) : $this->all();
		function_exists('dump') ? dump($data) : var_dump($data);
		return $this;
	}

	public function dd(...$keys): never {
		$this->dump(...$keys);
		exit(1);
	}

	/* ================================================================== *
	 | Cookies, files
	 * ================================================================== */

	public function hasCookie(string $key): bool {
		return $this->cookies->has($key);
	}

	public function cookie(?string $key = null, $default = null) {
		return $key === null ? $this->cookies->all() : $this->cookies->get($key, $default);
	}

	public function allFiles(): array {
		return $this->files->all();
	}

	/**
	 * @return UploadedFile|UploadedFile[]|array|null
	 */
	public function file(?string $key = null, $default = null) {
		return $key === null ? $this->allFiles() : Arr::get($this->allFiles(), $key, $default);
	}

	public function hasFile(string $key): bool {
		$files = $this->file($key);
		foreach (is_array($files) ? $files : [$files] as $file) {
			if ($this->isValidFile($file)) return true;
		}
		return false;
	}

	protected function isValidFile($file): bool {
		return $file instanceof \SplFileInfo && $file->getPath() !== '';
	}

	/** Remove empty entries (e.g. file inputs with no upload) recursively. */
	protected function filterFiles($files) {
		if (!$files) return null;

		foreach ($files as $key => $file) {
			if (is_array($file)) $files[$key] = $this->filterFiles($files[$key]);
			if (empty($files[$key])) unset($files[$key]);
		}
		return $files;
	}

	/* ================================================================== *
	 | Session & flash data (InteractsWithFlashData)
	 * ================================================================== */

	public function hasSession(bool $skipIfUninitialized = false): bool {
		return $this->session !== null;
	}

	public function hasPreviousSession(): bool {
		return $this->hasSession() && method_exists($this->session, 'getName') && $this->cookies->has($this->session->getName());
	}

	public function getSession() {
		if (!$this->hasSession()) throw new RuntimeException('Session not found on request.');
		return $this->session;
	}

	public function session() {
		if (!$this->hasSession()) throw new RuntimeException('Session store not set on request.');
		return $this->session;
	}

	public function setLaravelSession($session): void {
		$this->session = $session;
	}

	public function setSession($session): void {
		$this->session = $session;
	}

	public function old(?string $key = null, $default = null) {
		return $this->hasSession() ? $this->session()->getOldInput($key, $default) : Arr::value($default);
	}

	public function flash(): void {
		$this->session()->flashInput($this->input());
	}

	public function flashOnly(...$keys): void {
		$this->session()->flashInput($this->only(...$keys));
	}

	public function flashExcept(...$keys): void {
		$this->session()->flashInput($this->except(...$keys));
	}

	public function flush(): void {
		$this->session()->flashInput([]);
	}

	/* ================================================================== *
	 | Precognition (CanBePrecognitive)
	 * ================================================================== */

	public function isAttemptingPrecognition(): bool {
		return $this->header('Precognition') === 'true';
	}

	public function isPrecognitive(): bool {
		return (bool)$this->attributes->get('precognitive', false);
	}

	/* ================================================================== *
	 | Route & user
	 * ================================================================== */

	/**
	 * @return \WP_User|mixed|null
	 */
	public function user($guard = null) {
		return call_user_func($this->getUserResolver(), $guard);
	}

	public function getUserResolver(): Closure {
		return $this->userResolver ?: static function($guard = null) {
			if (!function_exists('wp_get_current_user')) return null;
			$user = wp_get_current_user();
			return $user->exists() ? $user : null;
		};
	}

	public function setUserResolver(Closure $callback): static {
		$this->userResolver = $callback;
		return $this;
	}

	/** Resolver returns a route object (with parameter()/parameters()) or an array of parameters. */
	public function route(?string $param = null, $default = null) {
		$route = call_user_func($this->getRouteResolver());
		if ($route === null || $param === null) return $route;

		if (is_array($route)) return Arr::get($route, $param, $default);
		if (is_object($route) && method_exists($route, 'parameter')) return $route->parameter($param, $default);
		return Arr::value($default);
	}

	public function getRouteResolver(): Closure {
		return $this->routeResolver ?: static fn() => null;
	}

	public function setRouteResolver(Closure $callback): static {
		$this->routeResolver = $callback;
		return $this;
	}

	/** Unique hash for route + IP. */
	public function fingerprint(): string {
		$route = $this->route();
		if (!is_object($route)) {
			throw new RuntimeException('Unable to generate fingerprint. Route unavailable.');
		}
		return sha1(implode('|', array_merge(
			method_exists($route, 'methods') ? $route->methods() : [$this->getMethod()],
			[
				method_exists($route, 'getDomain') ? $route->getDomain() : $this->getHost(),
				method_exists($route, 'uri') ? $route->uri() : $this->path(),
				$this->ip(),
			]
		)));
	}

	protected function routeParameters(): array {
		$route = $this->route();
		if (is_array($route)) return $route;
		return is_object($route) && method_exists($route, 'parameters') ? (array)$route->parameters() : [];
	}

	/* ================================================================== *
	 | Conditionable & Macroable
	 * ================================================================== */

	public function when($value = null, ?callable $callback = null, ?callable $default = null) {
		$value = $value instanceof Closure ? $value($this) : $value;
		if ($value) return $callback ? ($callback($this, $value) ?? $this) : $this;
		if ($default) return $default($this, $value) ?? $this;
		return $this;
	}

	public function unless($value = null, ?callable $callback = null, ?callable $default = null) {
		$value = $value instanceof Closure ? $value($this) : $value;
		if (!$value) return $callback ? ($callback($this, $value) ?? $this) : $this;
		if ($default) return $default($this, $value) ?? $this;
		return $this;
	}

	public static function macro(string $name, callable $macro): void {
		static::$macros[$name] = $macro;
	}

	public static function mixin(object $mixin, bool $replace = true): void {
		$methods = (new ReflectionClass($mixin))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);
		foreach ($methods as $method) {
			if ($replace || !static::hasMacro($method->name)) {
				static::macro($method->name, $method->invoke($mixin));
			}
		}
	}

	public static function hasMacro(string $name): bool {
		return isset(static::$macros[$name]);
	}

	public static function flushMacros(): void {
		static::$macros = [];
	}

	public static function __callStatic(string $method, array $args) {
		if (!static::hasMacro($method)) {
			throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
		}
		$macro = static::$macros[$method];
		if ($macro instanceof Closure) $macro = Closure::bind($macro, null, static::class);
		return $macro(...$args);
	}

	public function __call(string $method, array $args) {
		if (!static::hasMacro($method)) {
			throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
		}
		$macro = static::$macros[$method];
		if ($macro instanceof Closure) $macro = $macro->bindTo($this, static::class);
		return $macro(...$args);
	}

	/* ================================================================== *
	 | Arrayable / ArrayAccess / magic
	 * ================================================================== */

	public function toArray(): array {
		return $this->all();
	}

	/** Raw HTTP message (request line + headers + body), như Symfony. */
	public function __toString(): string {
		$headers = '';
		foreach ($this->headers->all() as $name => $values) {
			$name = ucwords($name, '-');
			foreach ($values as $value) $headers .= $name . ': ' . $value . "\r\n";
		}
		return sprintf('%s %s %s', $this->getMethod(), $this->getRequestUri(), $this->getProtocolVersion() ?? 'HTTP/1.1') . "\r\n"
			. $headers . "\r\n" . $this->getContent();
	}

	public function jsonSerialize(): mixed {
		return $this->all();
	}

	public function offsetExists(mixed $offset): bool {
		return Arr::has($this->all() + $this->routeParameters(), (string)$offset);
	}

	public function offsetGet(mixed $offset): mixed {
		return $this->__get((string)$offset);
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		$this->getInputSource()->set((string)$offset, $value);
	}

	public function offsetUnset(mixed $offset): void {
		$this->getInputSource()->remove((string)$offset);
	}

	public function __isset(string $key): bool {
		return $this->__get($key) !== null;
	}

	public function __get(string $key) {
		return Arr::get($this->all(), $key, fn() => $this->route($key));
	}

}
