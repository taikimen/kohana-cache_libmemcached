<?php defined('SYSPATH') or die('No direct script access.');
/**
 * [Kohana Cache](api/Kohana_Cache) libdemcached driver,
 * 
 * ### Supported cache engines
 * 
 * *  [Memcached](http://php.net/manual/ja/book.memcached.php)
 * 
 * ### Configuration example
 * 
 * Below is an example of a _memcache_ server configuration.
 * 
 *     return array(
 *          'default'   => array(                          // Default group
 *                  'driver'         => 'memcached',        // using Memcache driver
 *                  'servers'        => array(             // Available server definitions
 *                         // First memcache server server
 *                         array('localhost',11211,100),
 *                         // Second memcache server
 *                         array('localhost2',11211,100),
 *                  ),
 *                  //
 *                  'options' => array(
 *                          Memcached::OPT_COMPRESSION => FALSE,
 *                          Memcached::OPT_BINARY_PROTOCOL => TRUE,
 *                  )
 *           ),
 *     )
 * 
 * In cases where only one cache group is required, if the group is named `default` there is
 * no need to pass the group name when instantiating a cache instance.
 * 
 * #### General cache group configuration settings
 * 
 * Below are the settings available to all types of cache driver.
 * 
 * Name           | Required | Description
 * -------------- | -------- | ---------------------------------------------------------------
 * driver         | __YES__  | (_string_) The driver type to use
 * servers        | __YES__  | (_array_) Associative array of server details, must include a __host__ key. (see _Memcache server configuration_ below)
 * compression    | __NO__   | (_boolean_) Use data compression when caching
 * 
 * #### Memcache server configuration
 * 
 * The following settings should be used when defining each memcache server
 * 
 * key              | Required | Description
 * ---------------- | -------- | ---------------------------------------------------------------
 * 0                | __YES__  | (_string_) The host of the memcache server, i.e. __localhost__; or __127.0.0.1__; or __memcache.domain.tld__
 * 1                | __YES__   | (_integer_) Point to the port where memcached is listening for connections. Set this parameter to 0 when using UNIX domain sockets.  Default __11211__
 * 2                | __NO__   | (_integer_) Number of buckets to create for this server which in turn control its probability of it being selected. The probability is relative to the total weight of all servers. Default __1__

 * ### System requirements
 * 
 * *  Kohana 3.0.x
 * *  PHP 5.2.4 or greater
 * *  Memcached
 * *  Zlib
 * 
 * @package    Kohana
 * @category   Cache
 * @version    1.0
 * @author     Kohana Team,taikimen
 * @copyright  (c) 2009-2010 Kohana Team
 * @license    http://kohanaphp.com/license
 */
class Kohana_Cache_Memcached extends Cache {

	// Memcache has a maximum cache lifetime of 30 days
	const CACHE_CEILING = 2592000;

	/**
	 * Memcached resource
	 *
	 * @var Memcached
	 */
	protected $_memcached;

	/**
	 * Flags to use when storing values
	 *
	 * @var string
	 */
	protected $_flags;

	/**
	 * Constructs the memcached Kohana_Cache object
	 *
	 * @param   array     configuration
	 * @throws  Kohana_Cache_Exception
	 */
	protected function __construct(array $config)
	{
		// Check for the memcache extention
		if ( ! extension_loaded('memcached'))
		{
			throw new Kohana_Cache_Exception('Memcached PHP extention not loaded');
		}

		parent::__construct($config);

		// Setup Memcache
		$this->_memcached = new Memcached;

		// Load servers from configuration
		$servers = Arr::get($this->_config, 'servers', NULL);

		if ( ! $servers)
		{
			// Throw an exception if no server found
			throw new Kohana_Cache_Exception('No Memcached servers defined in configuration');
		}


		// Add the memcache servers to the pool
		$this->_memcached->addServers($servers);
		
		$options = Arr::get($this->_config, 'options', NULL);
		foreach($options as $key => $val){
			$this->_memcached->setOption($key,$val);
		}

		// Setup the flags
		$this->_flags = empty($options[Memcached::OPT_COMPRESSION]) ? TRUE : $options[Memcached::OPT_COMPRESSION];
	}

	/**
	 * Retrieve a cached value entry by id.
	 * 
	 *     // Retrieve cache entry from memcache group
	 *     $data = Cache::instance('memcache')->get('foo');
	 * 
	 *     // Retrieve cache entry from memcache group and return 'bar' if miss
	 *     $data = Cache::instance('memcache')->get('foo', 'bar');
	 *
	 * @param   string   id of cache to entry
	 * @param   string   default value to return if cache miss
	 * @return  mixed
	 * @throws  Kohana_Cache_Exception
	 */
	public function get($id, $default = NULL)
	{
		// Get the value from Memcache
		$value = $this->_memcached->get($this->_sanitize_id($id));

		// If the value wasn't found, normalise it
		if ($value === FALSE)
		{
			$value = (NULL === $default) ? NULL : $default;
		}

		// Return the value
		return $value;
	}
	
	/**
	 * Retrieve a cached value entry by id_array.
	 * 
	 *     // Retrieve cache entry from memcached group
	 *     $data = Cache::instance('memcached')->get_multi(array('key1','key2'));
	 * 
	 * @param   array    array of cache_id to entry
	 * @param   string   default value to return if cache miss
	 * @return  mixed
	 * @throws  Kohana_Cache_Exception
	 */
	public function get_multi($id_array, $default = NULL)
	{
		
		$sanitize_ids = array();
		// Get the value from Memcache
		foreach($id_array as &$id){
			$id = $this->_sanitize_id($id);
		}
		$value = $this->_memcached->getMulti($sanitize_ids);

		// If the value wasn't found, normalise it
		if ($value === FALSE)
		{
			$value = (NULL === $default) ? NULL : $default;
		}

		// Return the value
		return $value;
	}

	/**
	 * Set a value to cache with id and lifetime
	 * 
	 *     $data = 'bar';
	 * 
	 *     // Set 'bar' to 'foo' in memcache group for 10 minutes
	 *     if (Cache::instance('memcache')->set('foo', $data, 600))
	 *     {
	 *          // Cache was set successfully
	 *          return
	 *     }
	 *
	 * @param   string   id of cache entry
	 * @param   mixed    data to set to cache
	 * @param   integer  lifetime in seconds, maximum value 2592000
	 * @return  boolean
	 */
	public function set($id, $data, $lifetime = 3600)
	{
		// If the lifetime is greater than the ceiling
		if ($lifetime > Cache_Memcached::CACHE_CEILING)
		{
			// Set the lifetime to maximum cache time
			$lifetime = Cache_Memcached::CACHE_CEILING + time();
		}
		// Else if the lifetime is greater than zero
		elseif ($lifetime > 0)
		{
			$lifetime += time();
		}
		// Else
		else
		{
			// Normalise the lifetime
			$lifetime = 0;
		}

		// Set the data to memcache
		return $this->_memcached->set($this->_sanitize_id($id), $data, $lifetime);
	}
	
	/**
	 * Set a value to cache with array(id => value) and lifetime
	 * 
	 *     $data = 'bar';
	 * 
	 *     // Set 'bar' to 'foo' in memcache group for 10 minutes
	 *     if (Cache::instance('memcache')->set(array('foo' => 'value'),  600))
	 *     {
	 *          // Cache was set successfully
	 *          return
	 *     }
	 *
	 * @param   array    array('id' => 'value') of cache entry
	 * @param   integer  lifetime in seconds, maximum value 2592000
	 * @return  boolean
	 */
	public function set_multi($data, $lifetime = 3600)
	{
		// If the lifetime is greater than the ceiling
		if ($lifetime > Cache_Memcached::CACHE_CEILING)
		{
			// Set the lifetime to maximum cache time
			$lifetime = Cache_Memcached::CACHE_CEILING + time();
		}
		// Else if the lifetime is greater than zero
		elseif ($lifetime > 0)
		{
			$lifetime += time();
		}
		// Else
		else
		{
			// Normalise the lifetime
			$lifetime = 0;
		}
		
		$store = array();
		foreach($data as $id => $val){
			$store[$this->_sanitize_id($id)] = $val;
		}

		// Set the data to memcache
		return $this->_memcached->setMulti($stopre, $lifetime);
	}

	/**
	 * Delete a cache entry based on id
	 * 
	 *     // Delete the 'foo' cache entry immediately
	 *     Cache::instance('memcache')->delete('foo');
	 * 
	 *     // Delete the 'bar' cache entry after 30 seconds
	 *     Cache::instance('memcache')->delete('bar', 30);
	 *
	 * @param   string   id of entry to delete
	 * @param   integer  timeout of entry, if zero item is deleted immediately, otherwise the item will delete after the specified value in seconds
	 * @return  boolean
	 */
	public function delete($id, $timeout = 0)
	{
		// Delete the id
		return $this->_memcached->delete($this->_sanitize_id($id), $timeout);
	}

	/**
	 * Delete all cache entries.
	 * 
	 * Beware of using this method when
	 * using shared memory cache systems, as it will wipe every
	 * entry within the system for all clients.
	 * 
	 *     // Delete all cache entries in the default group
	 *     Cache::instance('memcache')->delete_all();
	 *
	 * @return  boolean
	 */
	public function delete_all()
	{
		$result = $this->_memcached->flush();

		// We must sleep after flushing, or overwriting will not work!
		// @see http://php.net/manual/en/function.memcache-flush.php#81420
		sleep(1);

		return $result;
	}
}