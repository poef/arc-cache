<?php

/*
 * This file is part of the Ariadne Component Library.
 *
 * (c) Muze <info@muze.nl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace arc\cache;

/**
 * Class Proxy
 * @package arc\cache
 */
class Proxy
{
    use \arc\traits\Proxy {
        \arc\traits\Proxy::__construct as private ProxyConstruct;
    }

    protected $cacheStore   = null;
    protected $cacheControl = null;
    protected $targetObject = null;

    /**
     * Creates a new Caching Proxy object.
     * @param object $targetObject  The object to cache.
     * @param object $cacheStore    The cache store to use, e.g. \arc\cache\FileStore
     * @param mixed  $cacheControl  Either an int with the number of seconds to cache results, or a Closure that returns an int.
     *                              The Closure is called with a single array with the following entries:
     *                              - target      The cached object a method was called on.
     *                              - method      The method called
     *                              - arguments   The arguments to the method
     *                              - result      The result of the method
     */
    public function __construct($targetObject, $cacheStore, $cacheControl = 7200)
    {
        $this->ProxyConstruct( $targetObject );
        $this->targetObject = $targetObject;
        $this->cacheStore   = $cacheStore;
        $this->cacheControl = $cacheControl;
    }

    /**
     * Catches output and return values from a method call and returns them.
     * @param string $method
     * @param array $args
     * @return array with keys 'output' and 'result'
     */
    private function __callCatch($method, $args)
    {
        // catch all output and return value, return it
        ob_start();
        $result = call_user_func_array( array( $this->targetObject, $method ), $args );
        $output = ob_get_contents();
        ob_end_clean();

        return array(
            'output' => $output,
            'result' => $result
        );
    }

    /**
     * Checks if a fresh cache image for this method and these arguments is available
     * and returns those. If not, it lets the call through and caches its output and results.
     * @param string $method
     * @param array  $args
     * @param string $path
     * @return array
     */
    private function __callCached($method, $args, $path)
    {
        // check the cache, if fresh, use the cached version
        $cacheData = $this->cacheStore->getIfFresh( $path );
        if (!isset( $cacheData )) {
            if ($this->cacheStore->lock( $path )) {
                // try to get a lock to calculate the value
                $cacheData = $this->__callCatch( $method, $args );
                if (is_callable( $this->cacheControl )) {
                    $cacheTimeout = call_user_func(
                        $this->cacheControl,
                        array(
                            'target'    => $this->targetObject,
                            'method'    => $method,
                            'arguments' => $args,
                            'result'    => $cacheData
                        )
                    );
                } else {
                    $cacheTimeout = $this->cacheControl;
                }
                $this->cacheStore->set( $path, $cacheData, $cacheTimeout );
            } else if ($this->cacheStore->wait( $path )) {
                // couldn't get a lock, so there is another proces writing a cache, wait for that
                // stampede protection
                $cacheData = $this->cacheStore->get( $path );
            } else {
                // wait failed, so just do the work without caching
                $cacheData = $this->__callCatch( $method, $args );
            }
        }

        return $cacheData;
    }

    /**
     * Catches a call to the target object and caches it. If the result is an object, it creates a
     * cache proxy for that as well.
     * @param string $method
     * @param array  $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        // create a usable but unique filename based on the arguments and method name
        $path = $method . '(' . sha1( serialize($args) ) . ')';

        $cacheData = $this->__callCached( $method, $args, $path );
        echo $cacheData['output'];
        $result = $cacheData['result'];
        if (is_object( $result )) { // for fluent interface we want to cache the returned object as well
            $result = new static( $result, $this->cacheStore->cd( $path ), $this->cacheControl );
        }

        return $result;
    }

    /**
     * Catches any property access to the target object and caches it. If the property is an object
     * it creates a cache proxy for that as well.
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $result = $this->targetObject->{$name};
        if (is_object( $result )) {
            $result = new static( $result, $this->cacheStore->cd( $name ), $this->cacheControl );
        }

        return $result;
    }
}
