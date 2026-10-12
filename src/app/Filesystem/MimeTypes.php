<?php

namespace WPSPCORELITE\App\Filesystem;

/**
 * Minimal mime <-> extension map, replacing Symfony\Component\Mime\MimeTypes
 * for Filesystem::guessExtension() and the mimeType() fallback.
 * Extend at runtime with MimeTypes::add('application/x-foo', ['foo']).
 */
class MimeTypes {

	/**
	 * mime => extensions (first is preferred)
	 *
	 * @var array<string, string[]>
	 */
	protected static $map = [
		// Text / code
		'text/plain'                    => ['txt', 'text', 'log', 'conf', 'ini'],
		'text/html'                     => ['html', 'htm'],
		'text/css'                      => ['css'],
		'text/csv'                      => ['csv'],
		'text/xml'                      => ['xml'],
		'application/xml'               => ['xml'],
		'text/markdown'                 => ['md', 'markdown'],
		'text/javascript'               => ['js', 'mjs'],
		'application/javascript'        => ['js'],
		'application/json'              => ['json'],
		'text/x-php'                    => ['php'],
		'application/x-httpd-php'       => ['php'],
		'image/svg+xml'                 => ['svg', 'svgz'],

		// Images
		'image/jpeg'                    => ['jpg', 'jpeg', 'jpe'],
		'image/png'                     => ['png'],
		'image/gif'                     => ['gif'],
		'image/webp'                    => ['webp'],
		'image/avif'                    => ['avif'],
		'image/bmp'                     => ['bmp'],
		'image/x-ms-bmp'                => ['bmp'],
		'image/tiff'                    => ['tiff', 'tif'],
		'image/x-icon'                  => ['ico'],
		'image/vnd.microsoft.icon'      => ['ico'],
		'image/heic'                    => ['heic'],

		// Audio / video
		'audio/mpeg'                    => ['mp3'],
		'audio/ogg'                     => ['ogg', 'oga'],
		'audio/wav'                     => ['wav'],
		'audio/x-wav'                   => ['wav'],
		'audio/aac'                     => ['aac'],
		'audio/flac'                    => ['flac'],
		'video/mp4'                     => ['mp4', 'm4v'],
		'video/webm'                    => ['webm'],
		'video/ogg'                     => ['ogv'],
		'video/quicktime'               => ['mov'],
		'video/x-msvideo'               => ['avi'],
		'video/x-matroska'              => ['mkv'],
		'application/vnd.apple.mpegurl' => ['m3u8'],
		'video/mp2t'                    => ['ts'],

		// Fonts
		'font/woff'                     => ['woff'],
		'font/woff2'                    => ['woff2'],
		'font/ttf'                      => ['ttf'],
		'font/otf'                      => ['otf'],

		// Documents
		'application/pdf'               => ['pdf'],
		'application/msword'            => ['doc'],
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => ['docx'],
		'application/vnd.ms-excel'      => ['xls'],
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => ['xlsx'],
		'application/vnd.ms-powerpoint' => ['ppt'],
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
		'application/rtf'               => ['rtf'],
		'application/epub+zip'          => ['epub'],

		// Archives
		'application/zip'               => ['zip'],
		'application/x-rar-compressed'  => ['rar'],
		'application/vnd.rar'           => ['rar'],
		'application/x-7z-compressed'   => ['7z'],
		'application/gzip'              => ['gz'],
		'application/x-gzip'            => ['gz'],
		'application/x-tar'             => ['tar'],
		'application/x-bzip2'           => ['bz2'],

		// Misc
		'application/octet-stream'      => ['bin'],
		'application/wasm'              => ['wasm'],
	];

	public static function getExtension($mimeType) {
		$mimeType = strtolower(trim(explode(';', $mimeType)[0]));

		return static::$map[$mimeType][0] ?? null;
	}

	public static function getExtensions($mimeType) {
		$mimeType = strtolower(trim(explode(';', $mimeType)[0]));

		return static::$map[$mimeType] ?? [];
	}

	public static function getMimeType($extension) {
		$extension = strtolower(ltrim($extension, '.'));

		foreach (static::$map as $mime => $extensions) {
			if (in_array($extension, $extensions, true)) {
				return $mime;
			}
		}

		return null;
	}

	public static function add($mimeType, array $extensions) {
		$mimeType = strtolower($mimeType);

		static::$map[$mimeType] = array_values(array_unique(array_merge(
			$extensions,
			static::$map[$mimeType] ?? []
		)));
	}

}
