<?php

namespace CarlosFerrari\SimpleCrawler;
 
/**
 * Class that store one single request, their headers and all the info about the response
 *
 * PHP version 5.3
 *
 * @author    Carlos André Ferrari <carlos@ferrari.eti.br>
 * @category  CarlosFerrari
 * @package   SimpleCrawler
 * @copyright © Carlos André Ferrari
 * @license   http://ferrari.eti.br/license/newbsd/ New BSD License
 * @version   1
 */
class Request
{

	/**
	 * @var sent request storage
	 */
	public $request;
	
	/**
	 * @var response request storage
	 */
	public $response = false;
	
	/**
	 * @var callbacks closures storage
	 */
	public $callbacks = array();
	
	/**
	 * Request Constructor
	 *
	 * @param string $url 		url to be fetched
	 * @param string $method    HTTP method to be used
	 * @param string $headers	HTTP custom headers to be sent
	 *
	 * @return void
	 */
	public function __construct($url, $method='GET', $headers='') {
		// parse the request url into a object
		$this->request = (object)parse_url($url);
		
		// store the full uri
		$this->request->full = $url;
		
		// if the port is the default, set it to exist in the request object
		if (!isset($this->request->port)) $this->request->port = 80;
		
		// If the path is the index, set it here
		if (!isset($this->request->path)) $this->request->path = '/';

		// Define the request properties
		$this->sent = false;
		$this->request->headers = "$method {$this->request->full} HTTP/1.1\r\nHost: {$this->request->host}";
		$this->request->headers .= $headers;
		$this->request->uri 	= $url;
		$this->request->method 	= $method;
		
		// Set the default callbacks to empty functions
		$callbacks = array('onConnectionOpen', 'onDataReceived', 'onHeadersSent', 'onHeadersReceived', 
							'onHeadersReceived', 'onHttpError', 'onComplete');
		foreach ($callbacks as $c)
			$this->callbacks[$c] = function($response, $request, $self){ return; };
	}

	/**
	 * set one callback, if the callback name is not valid it'll throw a exception
	 *
	 * @param string 	$name 		name of the event
	 * @param function 	$function 	Function to be executed when the event occur
	 *
	 * @return void
	 */
	public function setCallback($name, $function){
		if (!isset($this->callbacks[$name])) throw new \Exception("Invalid callback!");
		$this->callbacks[$name] = $function;
	}

	/**
	 * Execute one callback
	 *
	 * @param string 	$name 		name of the event
	 * @param function 	$data 		Optional data to be passed
	 *
	 * @return void
	 */
	public function execCallback($name, $data=''){
		if (isset($this->callbacks[$name])) $this->callbacks[$name]($this->response, $this->request, $this);
	}

}
