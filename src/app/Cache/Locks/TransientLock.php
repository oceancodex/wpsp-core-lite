<?php

namespace WPSPCORELITE\App\Cache\Locks;

/**
 * Database lock on the options table, mirror of Illuminate\Cache\DatabaseLock.
 *
 * - Row: option_name = "{prefix}lock_{name}", option_value = "{owner}|{expiresAt}", autoload = no.
 * - Acquire: "INSERT IGNORE" (option_name is a UNIQUE key), so only one process can create the row.
 *   add_option() is NOT used because it runs "INSERT ... ON DUPLICATE KEY UPDATE", which is not exclusive.
 * - An expired row is taken over with a compare-and-delete.
 * - Like Laravel, seconds = 0 uses a default timeout (86400s) so a crashed process can't hold it forever,
 *   and a lottery occasionally prunes expired lock rows.
 */
class TransientLock extends Lock {

	protected string $table;
	protected string $keyPrefix;
	protected array  $lottery;
	protected int    $defaultTimeoutInSeconds;

	public function __construct(
		string $table,
		string $keyPrefix,
		$name,
		$seconds,
		$owner = null,
		array $lottery = [2, 100],
		int $defaultTimeoutInSeconds = 86400
	) {
		parent::__construct($name, $seconds, $owner);

		$this->table                   = $table;
		$this->keyPrefix               = $keyPrefix;
		$this->lottery                 = $lottery;
		$this->defaultTimeoutInSeconds = $defaultTimeoutInSeconds;
	}

	public function acquire(): bool {
		$acquired = $this->insert();

		if (!$acquired) {
			$current = $this->readRaw();

			if ($current !== null) {
				[, $expiresAt] = $this->parse($current);

				if ($expiresAt <= time()) {
					global $wpdb;

					$wpdb->query($wpdb->prepare(
						"DELETE FROM {$this->table} WHERE option_name = %s AND option_value = %s",
						$this->key(),
						$current
					));

					$acquired = $this->insert();
				}
			}
		}

		if (count($this->lottery) === 2 && random_int(1, (int) $this->lottery[1]) <= (int) $this->lottery[0]) {
			$this->pruneExpiredLocks();
		}

		return $acquired;
	}

	public function release(): bool {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$this->table} WHERE option_name = %s AND option_value LIKE %s",
			$this->key(),
			$wpdb->esc_like($this->owner . '|') . '%'
		));

		return (int) $wpdb->rows_affected > 0;
	}

	public function forceRelease(): void {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$this->table} WHERE option_name = %s",
			$this->key()
		));
	}

	protected function getCurrentOwner(): ?string {
		$current = $this->readRaw();

		if ($current === null) {
			return null;
		}

		[$owner, $expiresAt] = $this->parse($current);

		return $expiresAt <= time() ? null : $owner;
	}

	/**
	 * Delete every expired lock row of this prefix.
	 */
	public function pruneExpiredLocks(): void {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$this->table} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) BETWEEN 1 AND %d",
			$wpdb->esc_like($this->keyPrefix) . '%',
			time()
		));
	}

	/*
	 *
	 */

	protected function insert(): bool {
		global $wpdb;

		$expiresAt = time() + ($this->seconds > 0 ? $this->seconds : $this->defaultTimeoutInSeconds);

		$wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$this->table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$this->key(),
			$this->owner . '|' . $expiresAt
		));

		return (int) $wpdb->rows_affected === 1;
	}

	protected function readRaw(): ?string {
		global $wpdb;

		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$this->table} WHERE option_name = %s LIMIT 1",
			$this->key()
		));

		return $value === null ? null : (string) $value;
	}

	protected function parse(string $raw): array {
		$pos = strrpos($raw, '|');

		if ($pos === false) {
			return [$raw, 0];
		}

		return [substr($raw, 0, $pos), (int) substr($raw, $pos + 1)];
	}

	protected function key(): string {
		$key = $this->keyPrefix . $this->name;

		// option_name is VARCHAR(191).
		return strlen($key) > 191 ? $this->keyPrefix . sha1($this->name) : $key;
	}

}
