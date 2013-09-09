<?php
/**
 * Curl custom exception
 */
class CurlException extends Exception {}

/**
 * Class that holds a rolling queue of curl requests.
 *
 * @throws CurlException
 */
class Curl 
{   
    /**
     * @var int
     *
     * Window size is the max number of simultaneous connections allowed.
     */
    protected $window_size = 10;

    /**
     * @var string|array
     *
     * Callback function to be applied to each result.
     */
    protected $callback;

    /**
     * @var array
     *
     * Set your base options that you want to be used with EVERY request.
     */
    protected $options = array(
        CURLOPT_USERAGENT       => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        CURLOPT_REFERER         => 'http://www.google.com',
        CURLOPT_AUTOREFERER     => true,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 10,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_MAXREDIRS       => 0
    );
       
    /**
     * @var null|array
     */
    protected $headers;
    
    /**
     * @var bool
     */
    protected $isAsync = true;

    /**
     * @var CurlRequest[]
     *
     * The request queue
     */
    protected $requests = array();

    /**
     * @param  $callback
     * Callback function to be applied to each result.
     *
     * Can be specified as 'my_callback_function'
     * or array($object, 'my_callback_method').
     *
     * Function should take two parameters: $response, $info.
     * $response is response body, $info is additional curl info.
     *
     * @return void
     */
    public function __construct($callback = null, $isAsync = true) 
    {
        $this->callback = $callback;
        $this->isAsync = $isAsync;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name) 
    {
        return (isset($this->{$name})) ? $this->{$name} : null;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public function __set($name, $value)
    {
        // append the base options & headers
        if ($name == "options" || $name == "headers") {
            $this->{$name} = $this->{$name} + $value;
        } else {
            $this->{$name} = $value;
        }
        return true;
    }
    
    /**
     * @param string $key
     * @param mixed $value
     */
    public function setOption($key, $value)
    {
        $this->options[$key] = $value;
    }

    /**
     * Add a request to the request queue
     *
     * @param CurlRequest $request
     * @return bool
     */
    public function add($request) 
    {
         $this->requests[] = $request;
         return true;
    }

    /**
     * Create new CurlRequest and add it to the request queue
     *
     * @param string $url
     * @param string $method
     * @param $post_data
     * @param $headers
     * @param $options
     * @return bool
     */
    public function request($url, $method = "GET", $post_data = null, $headers = null, $options = null) 
    {
         $this->requests[] = new CurlRequest($url, $method, $post_data, $headers, $options);
         return true;
    }

    /**
     * Perform GET request
     *
     * @param string $url
     * @param $headers
     * @param $options
     * @return bool
     */
    public function get($url, $headers = null, $options = null) 
    {
        return $this->request($url, "GET", null, $headers, $options);
    }

    /**
     * Perform POST request
     *
     * @param string $url
     * @param $post_data
     * @param $headers
     * @param $options
     * @return bool
     */
    public function post($url, $post_data = null, $headers = null, $options = null) 
    {
        return $this->request($url, "POST", $post_data, $headers, $options);
    }

    /**
     * Execute the curl
     *
     * @return string|bool
     */
    public function execute() 
    {
        if ($this->isAsync) {
            return $this->rolling_curl();
        } else {
            return $this->single_curl();            
        }
    }

    /**
     * Performs a single curl request
     *
     * @access private
     * @return string
     */
    private function single_curl() 
    {
        $ch = curl_init();              
        $options = $this->get_options(array_shift($this->requests));
        curl_setopt_array($ch,$options);
        $output = curl_exec($ch);
        $info = curl_getinfo($ch);

        // it's not neccesary to set a callback for one-off requests
        if ($this->callback) {
            if (is_object($this->callback) && method_exists($this->callback, 'callback')) {
                call_user_func(array($this->callback, 'callback'), $output, $info);
            } else if (is_callable($this->callback)){
                call_user_func($this->callback, $output, $info);
            }
        }
        return $output;
    }

    /**
     * Performs multiple curl requests
     *
     * @access private
     * @throws CurlException
     * @param int $window_size Max number of simultaneous connections
     * @return bool
     */
    private function rolling_curl() 
    {
        // make sure the rolling window isn't greater than the # of urls
        if (sizeof($this->requests) < $this->window_size) {
            $this->window_size = sizeof($this->requests);
        }
        
        $master = curl_multi_init();        

        // start the first batch of requests
        for ($i = 0; $i < $this->window_size; $i++) {
            $ch = curl_init();

            $options = $this->get_options($this->requests[$i]);

            curl_setopt_array($ch,$options);
            curl_multi_add_handle($master, $ch);
        }

        do {
            while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
            if($execrun != CURLM_OK) break;
            
            // a request was just completed -- find out which one
            while($done = curl_multi_info_read($master)) {

                // get the info and content returned on the request
                $info = curl_getinfo($done['handle']);
                $output = curl_multi_getcontent($done['handle']);

                // send the return values to the callback method/function.
        		if ($this->callback) {
            		if (is_object($this->callback) && method_exists($this->callback, 'asyncCallback')) {
                		call_user_func(array($this->callback, 'asyncCallback'), $output, $info);
            		} else if (is_callable($this->callback)){
                		call_user_func($this->callback, $output, $info);
            		}
            	}

                // start a new request (it's important to do this before removing the old one)
                if ($i < sizeof($this->requests) && isset($this->requests[$i]) && $i < count($this->requests)) {
                    $ch = curl_init();
                    $options = $this->get_options($this->requests[$i++]);
                    curl_setopt_array($ch,$options);
                    curl_multi_add_handle($master, $ch);
                }

                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);

            }
        } while ($running);
        curl_multi_close($master);
        return true;
    }


    /**
     * Helper function to set up a new request by setting the appropriate options
     *
     * @access private
     * @param CurlRequest $request
     * @return array
     */
    private function get_options($request) 
    {
        // options for this entire curl object
        $options = $this->__get('options');

        // append custom options for this specific request
        if ($request->options) {
            $options += $request->options;
        }

        // set the request URL
        $options[CURLOPT_URL] = $request->url;

        // posting data w/ this request?
        if ($request->post_data) {
            $options[CURLOPT_POST] = 1;
            $options[CURLOPT_POSTFIELDS] = $request->post_data;
        }

        return $options;
    }

    /**
     * @return void
     */
    public function __destruct() 
    {
        unset($this->window_size, $this->callback, $this->options, $this->headers, $this->requests);
    }
    
    /**
     * @return boolean
     */
    public function hasRequests()
    {
        return count($this->requests) > 0;
    }
}

/**
 * Class that represent a single curl request
 */
class CurlRequest 
{
    public $url = false;
    public $method = 'GET';
    public $post_data = null;
    public $headers = null;
    public $options = null;

    /**
     * @param string $url
     * @param string $method
     * @param $post_data
     * @param $headers
     * @param $options
     * @return void
     */
    public function __construct($url, $method = "GET", $post_data = null, $headers = null, $options = null) 
    {
        $this->url = $url;
        $this->method = $method;
        $this->post_data = $post_data;
        $this->headers = $headers;
        $this->options = $options;
    }
}
