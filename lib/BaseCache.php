<?php

if (!class_exists('EMOCBaseCache', false)) :

class EMOCBaseCache
{
	protected $cache = array();

	protected $enabled = true;
	protected $persist = true;
	protected $maxttl  = 3600;

	protected $np_groups     = array();
	protected $global_groups = array();

	private $blog_prefix;
	private $multisite;

	private static $serialize   = 'serialize';
	private static $unserialize = 'unserialize';

	/**
	 * @desc To stay compatible with SimpleTags
	 */
	protected $cache_enabled = true;

	public static function instance(array $data, $enabled = true, $persist = true, $maxttl = 3600)
	{
		static $self = false;

		if (!$self) {
			$self = new EMOCBaseCache($data, $enabled, $persist, $maxttl);
		}

		return $self;
	}

	public function __get($key)
	{
		static $keys = array('global_groups' => true, 'cache_enabled' => true, 'enabled' => true);
		return isset($keys[$key]) ? $this->$key : null;
	}

	public function __set($key, $val)
	{
		if ('enabled' == $key) {
			if (!$val) {
				$this->close();
				$this->persist = false;
			}

			$this->enabled = $val;
		}
	}

	protected function __construct(array $data, $enabled = true, $persist = true, $maxttl = 3600)
	{
		$this->enabled = $enabled;
		$this->persist = $persist && $enabled;
		$this->maxttl  = $maxttl;

		$this->multisite   = function_exists('is_multisite') && is_multisite();
		$this->blog_prefix = $this->multisite ? ($GLOBALS['blog_id'] . ':') : '';

		if (function_exists('igbinary_serialize')) {
			self::$serialize   = 'igbinary_serialize';
			self::$unserialize = 'igbinary_unserialize';
		}

		if ('basecache' == $data['engine']) {
			$this->persist = false;
		}

		if (!$this->persist) {
			$GLOBALS['_wp_using_ext_object_cache'] = false;
			$this->cache_enabled = false;
		}
	}

	public function add($key, $data, $group = 'default', $ttl = 0)
	{
		if (!$this->enabled) {
			return false;
		}

		$found = null;
		$this->resolveKey($group, $key);
		$this->get_resolved($key, $group, false, $found, $ttl);
		if (!$found) {
			return $this->set_resolved($key, $data, $group, $ttl);
		}

		return false;
	}

	public function close()
	{
	}

	public function decr($key, $offset = 1, $group = 'default')
	{
		if (!$this->enabled) {
			return false;
		}

		$found = null;
		$this->resolveKey($group, $key);

		$val = $this->get_resolved($key, $group, false, $found);

		if ($found) {
			if (!is_numeric($val)) {
				$val = 0;
			}

			$val -= $offset;
			if ($val < 0) {
				$val = 0;
			}

			$this->fast_set($key, $val, $group, $this->maxttl);
			return $val;
		}

		return false;
	}

	public function delete($key, $group = 'default')
	{
		$this->resolveKey($group, $key);
		unset($this->cache[$group][$key]);
		return $this->do_delete($key, $group);
	}

	public function flush()
	{
		$this->cache = array();
		$this->do_flush();
	}

	public function get($key, $group = 'default', $force = false, &$found = null, $ttl = 3600)
	{
		$found = false;
		if (!$this->enabled) {
			return false;
		}

		$this->resolveKey($group, $key);
		return $this->get_resolved($key, $group, $force, $found, $ttl);
	}

	private function get_resolved($key, $group, $force, &$found, $ttl)
	{
		$found = false;
		if (!$force || !$this->persist) {
			$result = $this->fast_get($key, $group, $found);
			if ($found) {
				return $result;
			}
		}

		if ($this->persist && !isset($this->np_groups[$group])) {
			$result = $this->do_get($group, $key, $found, $ttl);

			if ($found) {
				$func   = self::$unserialize;
				$result = $func($result);
				$this->cache[$group][$key] = $result;
				return $result;
			}
		}

		return false;
	}

	public function incr($key, $offset = 1, $group = 'default')
	{
		if (!$this->enabled) {
			return false;
		}

		$found = null;
		$this->resolveKey($group, $key);

		$val = $this->get_resolved($key, $group, false, $found);

		if ($found) {
			if (!is_numeric($val)) {
				$val = $offset;
			}
			else {
				$val += $offset;
			}

			if ($val < 0) {
				$val = 0;
			}

			$this->resolveKey($group, $key);
			$this->fast_set($key, $val, $group, $this->maxttl);
			return $val;
		}

		return false;
	}

	public function replace($key, $data, $group, $ttl = 0)
	{
		if (!$this->enabled) {
			return false;
		}

		$found = null;
		$this->resolveKey($group, $key);

		$this->get_resolved($key, $group, false, $found, $ttl);
		if ($found) {
			return $this->set_resolved($key, $data, $group, $ttl);
		}

		return false;
	}

	public function reset()
	{
		$this->close();
		if ($this->cache) {
			foreach ($this->cache as $group => &$x) {
				if (!isset($this->global_groups[$group])) {
					unset($this->cache[$group]);
				}
			}

			unset($x);
		}

		$this->blog_prefix = $this->multisite ? ($GLOBALS['blog_id'] . ':') : '';
	}

	public function set($key, $data, $group = 'default', $ttl = 0)
	{
		if (!$this->enabled) {
			return false;
		}

		$this->resolveKey($group, $key);
		return $this->set_resolved($key, $data, $group, $ttl);
	}

	private function set_resolved($key, $data, $group, $ttl)
	{
		if (!$ttl && $this->maxttl) {
			$ttl = $this->maxttl;
		}

		if (is_object($data)) {
			$data = clone($data);
		}

		if (!$this->persist) {
			$this->cache[$group][$key] = $data;
			return true;
		}

		return $this->fast_set($key, $data, $group, $ttl);
	}

	public function switch_to_blog($blog_id)
	{
		// Work around a weird bug when $_wp_using_ext_object_cache somehow resets to true
		if (!$this->persist) {
			$GLOBALS['_wp_using_ext_object_cache'] = false;
		}

		$this->blog_prefix = $this->multisite ? ($blog_id . ':') : '';
	}

	protected function do_delete($key, $group)
	{
		return true;
	}

	protected function do_get($group, $key, &$found, $ttl)
	{
		$found = false;
		return false;
	}

	protected function do_flush()
	{
	}

	protected function do_set($key, $data, $group, $ttl)
	{
		return true;
	}

	private function fast_get($key, $group, &$found = null)
	{
		if (isset($this->cache[$group][$key])) {
			$found  = true;
			$result = $this->cache[$group][$key];
			return is_object($result) ? clone($result) : $result;
		}

		$found = false;
		return false;
	}

	private function fast_set($key, $data, $group, $ttl)
	{
		$this->cache[$group][$key] = $data;
		$func = self::$serialize;
		$data = $func($data);
		return $this->do_set($key, $data, $group, $ttl);
	}

	protected function has_group($group)
	{
		return isset($this->cache[$group]);
	}

	public function add_non_persistent_groups(array $groups)
	{
		$groups = array_fill_keys($groups, true);
		$this->np_groups = array_merge($this->np_groups, $groups);
	}

	public function add_global_groups(array $groups)
	{
		$groups = array_fill_keys($groups, true);
		$this->global_groups = array_merge($this->global_groups, $groups);
	}

	public function clear_global_groups()
	{
		$this->global_groups = array();
	}

	public function clear_non_persistent_groups()
	{
		$this->np_groups = array();
	}

	protected function resolveKey($group, &$key)
	{
		if ($this->multisite && !isset($this->global_groups[$group])) {
			$key = $this->blog_prefix . $key;
		}
	}
}

endif;
