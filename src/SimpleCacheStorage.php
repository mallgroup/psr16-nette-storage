<?php
declare(strict_types=1);

namespace Mallgroup\Psr16Storage;

use Nette;
use Nette\Caching\BulkReader;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use function array_combine;
use function array_map;

class SimpleCacheStorage implements Storage, BulkReader
{
	use Nette\SmartObject;

	/** @internal cache structure */
	private const
		MetaCallbacks = 'callbacks',
		MetaData = 'data',
		MetaDelta = 'delta';

	public function __construct(
		private readonly CacheInterface $cache
	) {
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function read(string $key): mixed
	{
		/** @var array{data:string,delta:int,callbacks:array}|null $meta */
		$meta = $this->cache->get($key);
		if (!$meta) {
			return null;
		}

		if (!empty($meta[self::MetaCallbacks]) && !Cache::checkCallbacks($meta[self::MetaCallbacks])) {
			$this->cache->delete($key);
			return null;
		}

		if (!empty($meta[self::MetaDelta])) {
			$this->cache->set($key, $meta, $meta[self::MetaDelta] + time());
		}

		return $meta[self::MetaData];

	}

	public function lock(string $key): void
	{
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function write(string $key, mixed $data, array $dependencies): void
	{
		if (isset($dependencies[Cache::Items])) {
			throw new Nette\NotSupportedException('Dependent items are not supported by RoadRunnerStorage.');
		}

		$meta = [
			self::MetaData => $data,
		];

		$expire = null;
		if (isset($dependencies[Cache::Expire])) {
			$expire = (int)$dependencies[Cache::Expire];
			if (!empty($dependencies[Cache::Sliding])) {
				$meta[self::MetaDelta] = $expire; // sliding time
			}
		}

		if (isset($dependencies[Cache::Callbacks])) {
			$meta[self::MetaCallbacks] = $dependencies[Cache::Callbacks];
		}

		if (isset($dependencies[Cache::Tags]) || isset($dependencies[Cache::Priority])) {
			throw new Nette\InvalidStateException('RoadRunner cache does not support Journal.');
		}

		$this->cache->set($key, $meta, $expire);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function remove(string $key): void
	{
		$this->cache->delete($key);
	}

	public function clean(array $conditions): void
	{
		if (!empty($conditions[Cache::All])) {
			$this->cache->clear();
		}
	}

	/**
	 * @param array<array-key, mixed> $keys
	 * @throws InvalidArgumentException
	 */
	public function bulkRead(array $keys): array
	{
		/** @phpstan-ignore-next-line */
		$strKeys = array_map(static fn($key): string => (string) $key, $keys);
		$keys = array_combine($strKeys, $keys);

		$metas = $this->cache->getMultiple($strKeys);
		$result = [];
		$deleteKeys = [];
		foreach ($metas as $strKey => $meta) {
			/** @var array{data:string,delta:int,callbacks:array}|null $meta */
			if (!empty($meta[self::MetaCallbacks]) && !Cache::checkCallbacks($meta[self::MetaCallbacks])) {
				$deleteKeys[] = $strKey;
			} else {
				$result[$keys[$strKey]] = $meta[self::MetaData] ?? null;
			}

			if (!empty($meta[self::MetaDelta])) {
				$this->cache->set($strKey, $meta, $meta[self::MetaDelta] + time());
			}
		}

		if (!empty($deleteKeys)) {
			$this->cache->deleteMultiple($deleteKeys);
		}

		return $result;
	}
}
