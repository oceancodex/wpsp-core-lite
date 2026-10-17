<?php

namespace WPSPCORELITE\App\Cache\Concerns;

/**
 * Mirror of Illuminate\Cache\RetrievesMultipleKeys.
 */
trait RetrievesMultipleKeys {

	public function many(array $keys): array {
		$return = [];

		foreach ($keys as $key) {
			$return[$key] = $this->get($key);
		}

		return $return;
	}

	public function putMany(array $values, $seconds): bool {
		$manyResult = null;

		foreach ($values as $key => $value) {
			$result     = $this->put($key, $value, $seconds);
			$manyResult = is_null($manyResult) ? $result : $result && $manyResult;
		}

		return $manyResult ?: false;
	}

}
