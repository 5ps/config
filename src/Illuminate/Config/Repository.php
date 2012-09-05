<?php namespace Illuminate\Config;

use ArrayAccess;

class Repository implements ArrayAccess {

	/**
	 * The loader implementation.
	 *
	 * @var Illuminate\Config\LoaderInterface 
	 */
	protected $loader;

	/**
	 * The current environment.
	 *
	 * @var string
	 */
	protected $environment;

	/**
	 * All of the configuration items.
	 *
	 * @var array
	 */
	protected $items = array();

	/**
	 * An array of parsed key values.
	 *
	 * @var array
	 */
	protected $parsed = array();

	/**
	 * Create a new configuration repository.
	 *
	 * @param  Illuminate\Config\LoaderInterface  $loader
	 * @param  string  $environment
	 * @return void
	 */
	public function __construct(LoaderInterface $loader, $environment)
	{
		$this->loader = $loader;
		$this->environment = $environment;
	}

	/**
	 * Determine if the given configuration value exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function has($key)
	{
		$default = microtime(true);

		return $this->get($key, $default) != $default;
	}

	/**
	 * Get the specified configuration value.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		list($namespace, $group, $item) = $this->parse($key);

		// Configuration items are actually keyed by "collection", which is simply a
		// combination of each namespace and groups, which allows a unique way to
		// identify the arrays of configuration items for the particular files.
		$collection = $this->getCollection($group, $namespace);

		$this->load($group, $namespace, $collection);

		return array_get($this->items[$collection], $item, $default);
	}

	/**
	 * Set a given configuration value.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function set($key, $value)
	{
		list($namespace, $group, $item) = $this->parse($key);

		$collection = $this->getCollection($group, $namespace);

		// We'll need to go ahead and lazy load each configuration groups even when
		// we're just setting a configuration item so that the set item does not
		// get overwritten if a different item in the group is requested later.
		$this->load($group, $namespace, $collection);

		if (is_null($item))
		{
			$this->items[$collection] = $value;
		}
		else
		{
			array_set($this->items[$collection], $item, $value);
		}
	}

	/**
	 * Load the configuration group for the key.
	 *
	 * @param  string  $key
	 * @param  string  $namespace
	 * @param  string  $collection
	 * @return void
	 */
	protected function load($group, $namespace, $collection)
	{
		// If we've already loaded this collection, we will just bail out since we do
		// not want to load it again. Once items are loaded a first time they will
		// stay kept in memory within this class and not loaded from disk again.
		if (isset($this->items[$collection]))
		{
			return;
		}

		$items = $this->loader->load($this->environment, $group, $namespace);

		$this->items[$collection] = $items;
	}

	/**
	 * Parse a key into namespace, group, and item.
	 *
	 * @param  string  $key
	 * @return array
	 */
	protected function parse($key)
	{
		// If we've already parsed the given key, we'll return the cached version we
		// already have, as this will save us some processing. We cache off every
		// key we parse so we can quickly return it on all subsequent requests.
		if (isset($this->parsed[$key]))
		{
			return $this->parsed[$key];
		}

		$segments = explode('.', $key);

		// If the key does not contain a double colon, it means the key is not in a
		// namespace, and is just a regular configuration item. Namespaces are a
		// tool for organizing configuration items for things such as modules.
		if ( ! str_contains($key, '::'))
		{
			$parsed = $this->parseBasicSegments($segments);
		}
		else
		{
			$parsed = $this->parseNamespacedSegments($segments);
		}

		// Once we have the parsed array of this key's elements, such as its groups
		// and namespace, we will cache each array inside a simple list that has
		// the key and the parsed array for quick look-ups for later requests.
		return $this->parsed[$key] = $parsed;
	}

	/**
	 * Parse an array of basic segments.
	 *
	 * @param  array  $segments
	 * @return array
	 */
	protected function parseBasicSegments(array $segments)
	{
		// The first segment in a basic array will always be the group, so we can go
		// ahead and grab that segment. If there is only one total segment we are
		// just pulling an entire group out of the array and not a single item.
		$group = $segments[0];

		if (count($segments) == 1)
		{
			return array(null, $group, null);
		}

		// If there is more than one segment in the group, ite means we are pulling
		// a specific item out of a groups and will need to return the item name
		// as well as the group so we know which item to pull from the arrays.
		else
		{
			$item = implode('.', array_slice($segments, 1));

			return array(null, $group, $item);
		}
	}

	/**
	 * Parse an array of namespaced segments.
	 *
	 * @param  array  $segments
	 * @return array
	 */
	protected function parseNamespacedSegments(array $segments)
	{
		list($namespace, $group) = explode('::', $segments[0]);

		// If the group doesn't exist for the namespace, we'll assume it is the config
		// group so that any namespaces with just a single configuration file don't
		// have an awkward extra "config" identifier in each of their items keys.
		$item = null;

		if ($this->assumingGroup($segments, $group, $namespace))
		{
			list($item, $group) = array($group, 'config');
		}

		// If there is more than one segment, this means we have all three segments in
		// the key so we will concatenate all of the segments after the first which
		// has the group and the namespace, giving the configuration item's name.
		elseif (count($segments) > 1)
		{
			$item = implode('.', array_slice($segments, 1));
		}

		return array($namespace, $group, $item);
	}

	/**
	 * Determine if we should be assuming the configuration group.
	 *
	 * @param  array   $segments
	 * @param  string  $group
	 * @param  string  $namespace
	 * @return bool
	 */
	protected function assumingGroup($segments, $group, $namespace)
	{
		return count($segments) == 1 and ! $this->loader->exists($group, $namespace);
	}

	/**
	 * Get the collection identifier.
	 *
	 * @param  string  $group
	 * @param  string  $namespace
	 * @return string
	 */
	protected function getCollection($group, $namespace = null)
	{
		return $namespace ?: '*'.'::'.$group;
	}

	/**
	 * Add a new namespace to the loader.
	 *
	 * @param  string  $namespace
	 * @param  string  $hint
	 * @return void
	 */
	public function addNamespace($namespace, $hint)
	{
		return $this->loader->addNamespace($namespace, $hint);
	}

	/**
	 * Determine if the given configuration option exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetExists($key)
	{
		return $this->has($key);
	}

	/**
	 * Get a configuration option.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetGet($key)
	{
		return $this->get($key);
	}

	/**
	 * Set a configuration option.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetSet($key, $value)
	{
		$this->set($key, $value);
	}

	/**
	 * Unset a configuration option.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetUnset($key)
	{
		$this->set($key, null);
	}

	/**
	 * Get the loader implementation.
	 *
	 * @return Illuminate\Config\LoaderInterface
	 */
	public function getLoader()
	{
		return $this->loader;
	}

	/**
	 * Get all of the configuration items.
	 *
	 * @return array
	 */
	public function getItems()
	{
		return $this->items;
	}

}