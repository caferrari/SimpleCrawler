<?php

namespace CarlosFerrari\SimpleCrawler;

/**
 * Singleton Class responsable to all http transactions
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
class Crawler
{

	/**
	 * Debug level
	 * @var boolean
	 */
	public $debug = 0;
	
	/**
	 * 
	 * @staticvar   CarlosFerrari\SimpleCrawler\Crawler
	 * @access		protected
	 */
	protected static $instance = null;
	
	/**
	 * Resolved hosts IPs
	 * @staticvar   array
	 * @access		protected
	 */
	protected static $hosts = array();
	
	/**
	 * Requisitions IDs
	 * @staticvar   integer
	 * @access		protected
	 */
	static $ids = -1;
	
	/**
	 * Requests in execution
	 * @var   		array;
	 * @access		protected
	 */
	protected $requests = array();
	
	/**
	 * Responses in execution
	 * @var   		array;
	 * @access		protected
	 */
	protected $responses = array();
	
	/**
	 * Steam resources storage
	 * @var   		array;
	 * @access		protected
	 */
	protected $reqs = array();
	
	/**
	 * Flag to control if the Crawler is working
	 * @var   		array;
	 * @access		protected
	 */
	protected $isWorking = false;
	
	/**
	 * Requisitions quewe
	 * @var   		array;
	 * @access		protected
	 */
	protected $quewe = array();
	
	/**
	 * Open connections pool
	 * @var   		array;
	 * @access		protected
	 */
	protected $connectionPool = array();
	
	/**
	 * Open connections timeout
	 * @var   		int;
	 * @access		protected
	 */
	protected $connectionTimeout = 2;
	
	/**
	 * Max number of connections
	 * @var   		int;
	 * @access		protected
	 */
	protected $workers = 10;
	
	/**
	 * Max bandwidth in kb/s
	 * @var   		int;
	 * @access		protected
	 */
	protected $bandwidth = 50;
	
	/**
	 * Status
	 * @var   		array;
	 * @access		protected
	 */
	protected $status = array(
		'startTime' => 0,
		'endTime' => 0,
		'tickTime' => 0,
		'tickBytes' => 0,
		'requests' => 0,
		'bytesReceived' => 0
	);
	
	/**
	 * protected Constructor to force singleton mode only
	 *
	 * @access		protected
	 *
	 * @return void
	 */
	protected function __construct(){
		$this->workers = $this->bandwidth;
		// singleton
	}
	
	/**
	 * print debug messages
	 *
	 * @param string $msg 		Message to be printed
	 * @access		protected
	 *
	 * @return void
	 */
	protected function debug($msg, $level = 5){
		if ($this->debug >= $level) echo $msg . "\n";
	}
	
	/**
	 * get the CarlosFerrari\SimpleCrawler\Crawler instance
	 *
	 * @access		protected
	 *
	 * @return CarlosFerrari\SimpleCrawler\Crawler
	 */
	public function getInstance(){
		if (self::$instance==null) self::$instance = new Crawler();
		return self::$instance;
	}
	
	/**
	 * Add one request to the quewe
	 *
	 * @access		public
	 * @param CarlosFerrari\SimpleCrawler\Request $request
	 *
	 * @return CarlosFerrari\SimpleCrawler\Crawler
	 */	
	public function addRequest(Request $request){
		if (count($this->reqs) < $this->workers){
			$this->execRequest($request);
			$this->debug("Request added: {$request->request->full}", 3);
		}else{
			$this->debug("Request added to quewe: {$request->request->full}", 3);
			$this->quewe[] = $request;
		}		
		return $this;
	}
	
	/**
	 * Set the max suported speed
	 *
	 * @access		public
	 * @param float $mb
	 *
	 * @return void
	 */
	public function setBandwidth($mb){
		$this->bandwidth = ceil($mb * 102.4);
		$this->workers = $this->bandwidth;
		return $this;
	}

	/**
	 * Veryfy the bandwidth usage and change the number of workers for a better use
	 *
	 * @access		private
	 *
	 * @return void
	 */	
	private function checkBandwidthUsage(){
		$now = microtime(true);
		$diffTime = $now - $this->status['tickTime'];
		if ($diffTime < 10) return;
		$diffbytes = $this->status['bytesReceived'] - $this->status['tickBytes'];
		$speed = $diffbytes / $diffTime / 1024;

		$ratio = ($this->bandwidth - $speed) / 100;
				
		if ($ratio <= 0) $ratio = 0.5;
		$this->workers = ceil($this->workers * $ratio);
		if ($this->workers > $this->bandwidth) $this->workers = $this->bandwidth;
		
		$this->debug("Changed the number of workers to {$this->workers}", 2);
		
		$this->status['tickTime'] = $now;
		$this->status['tickBytes'] = $this->status['bytesReceived'];
	}

	/**
	 * Exec one request
	 *
	 * @access		private
	 * @param CarlosFerrari\SimpleCrawler\Request $request
	 *
	 * @return void
	 */			
	private function execRequest(Request $request){
		$id = ++self::$ids;
		$this->status['requests']++;
		$this->requests[$id] = $request;
		$this->responses[$id] = '';
		$this->debug("$id: Connecting...", 5);
		$this->reqs[$id] = $this->checkPool($request->request->host, $request->request->port);
		$this->requests[$id]->execCallback('onConnectionOpen');
	}
	
	/**
	 * Find a previowsly open connection in the pool or create a new one
	 *
	 * @access		private
	 * @param string 	$host
	 * @param int 		$port
	 *
	 * @return stream
	 */	
	public function checkPool($host, $port=80){
	
		if (isset($this->connectionPool[$host]) 
			&& count($this->connectionPool[$host])){
			
			for ($x=0; $x < count($this->connectionPool[$host]); $x++){
				$c = array_shift($this->connectionPool[$host]);
				$idleTime = microtime(true) - $c['time'];
				if ($idleTime >= $this->connectionTimeout){
					fclose($c['resource']);
					$this->debug('Pool connection discarted due timeout', 5);
					continue;
				}
				$this->debug('Reutilizing a connection from the pool', 5);
				return $c['resource'];
			}
		}
	
		$this->debug('Opennning a new connection', 5);
		if (!isset(self::$hosts[$host]))
			self::$hosts[$host] = gethostbyname($host);
		$ip = self::$hosts[$host];
		return stream_socket_client("$ip:{$port}", $errno, $errstr, 
							 10, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
	}
	
	/**
	 * Close all opened connections in the pool
	 *
	 * @access		private
	 *
	 * @return void
	 */	
	private function closePool(){
		foreach ($this->connectionPool as $host){
			foreach ($host as $connection){
				fclose($connection['resource']);
			}
		}
		$this->connectionPool = array();
	}

	/**
	 * Check the quewe list and add requests if needed
	 *
	 * @access		private
	 *
	 * @return void
	 */		
	private function checkQuewe(){
		$this->checkBandwidthUsage();
		$size = count($this->quewe);
		if ($size==0){
			$this->debug('No more itens in quewe, clearing the pool', 5);
			$this->closePool();
			return;
		}
		$max = $this->workers - count($this->reqs);
		$this->debug("Loading more itens from quewe ($size available)", 4);
		for ($x=0; $x<$max; $x++){
			$request = array_shift($this->quewe);
			if (!$request) return false;
			$this->debug("Request added from quewe: {$request->request->full}", 5);
			$this->execRequest($request);
		}
		$this->work();
	}
	
	/**
	 * Tell the crawler to start the work
	 *
	 * @access		public
	 *
	 * @return void
	 */		
	public function work(){
		if ($this->isWorking) return;
		
		$this->status['startTime'] = microtime(true);
		$this->status['tickTime'] = $this->status['startTime'];
		
		$this->debug("Starting {$this->workers} workers", 2);
		$this->isWorking = true;
	
		while ($this->reqs){
			$read = $write = $this->reqs;
			$n = stream_select($read, $write, $e = null, 5);
			if ($n > 0){
				foreach ($read as $r){
					$id = array_search($r, $this->reqs);
					if (!$this->read($id)) break;
				}
				foreach ($write as $w){
					$id = array_search($w, $this->reqs);
					$this->write($id);
				}
			}
		}
		
		$this->isWorking = false;
		$this->debug('Closing worker', 2);
		
		return $this->calcStat();
	}
	
	/**
	 * calculate the final stats and print in the console
	 *
	 * @access		private
	 *
	 * @return void
	 */	
	private function calcStat(){
		$this->status['endTime'] = microtime(true);
		$this->status['totalTime'] = $this->status['endTime'] - $this->status['startTime'];
		$this->status['speed'] = floor($this->status['bytesReceived'] / $this->status['totalTime']);
		return $this->status;
	}

	/**
	 * Read data received from the stream
	 *
	 * @param int 	$id
	 * @access		private
	 *
	 * @return boolean
	 */		
	private function read($id){
		$this->debug("$id: reading data", 6);
		$buffer = fgets($this->reqs[$id], 8192);
		$this->status['bytesReceived'] = $this->status['bytesReceived'] + strlen($buffer);
		$this->responses[$id] .= $buffer;
		$request = &$this->requests[$id];
		if (!$request->response){
			if (strpos($this->responses[$id], "\r\n\r\n") !== false){
				$this->debug("$id: found http header", 5);
				$request->response = (object)$this->loadHeaders($this->responses[$id]);
				if (isset($request->response->headers['Content-Length']))
					$request->response->length = $request->response->headers['Content-Length'];
				else
					$request->response->length = 'auto';
				$this->debug("$id: response code: {$request->response->code}", 5);
				if ($request->response->code < 200 || $request->response->code >= 300){
					$request->response->contents = '';
					$request->execCallback('onHttpError');
					$this->close($id);
					return false;
				}
				$request->execCallback('onHeadersReceived');
				$this->responses[$id] = '';
			}
		}else{
			$request->execCallback('onDataReceived');
			$done = false;
			if ($request->response->length === 'auto'){
				$end = substr($this->responses[$id], -7) === "\r\n0\r\n\r\n";
				if ($end){
					$request->response->contents = trim(substr($this->responses[$id],0, -7));
					$done = true;		
				}
			}else{
				if (strlen(trim($this->responses[$id])) >= $request->response->length){
					$request->response->contents = $this->responses[$id];
					$done = true;
				}
			}
			if ($done){
				$this->close($id);
				$request->execCallback('onComplete');
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Write request headers into the stream
	 *
	 * @param int 	$id
	 * @access		private
	 *
	 * @return void
	 */
	private function write($id){
		if ($this->requests[$id]->sent) return;
		fwrite($this->reqs[$id], $this->requests[$id]->request->headers . "\r\n\r\n");
		$this->requests[$id]->sent = true;
		$this->requests[$id]->execCallback('onHeadersSent');
	}

	/**
	 * Load the received headers into an array
	 *
	 * @param string 	$content
	 * @access		private
	 *
	 * @return void
	 */	
	private function loadHeaders($content){
		$tmp = explode("\r\n\r\n", $content);
		$headers = $tmp[0];
		$arr = explode("\r\n", trim($headers));
		$status = array_shift($arr);
		preg_match("@([1-5]0[0-5])@", $status, $mat);
		$code = $mat[0];

		$response = array(
			'status' => $status,
			'code' => $code * 1,
			'headers' => array(),
			'contents' => ''
		);
	
		foreach ($arr as $h){
			list ($name, $val) = explode(':', $h, 2);
			$response['headers'][trim($name)] = trim($val);
		}
		//$response['headers']['plain'] = $headers;
		if (!isset($response['headers']['Connection']))
			$response['headers']['Connection'] = 'close';
		
		return $response;
	}
	
	/**
	 * Close the connection to the server
	 *
	 * @param string 	$content
	 * @access		private
	 *
	 * @return void
	 */		
	private function close($id){
		$this->debug("$id: Request finished", 5);

		if ($this->requests[$id]->response->headers['Connection']!='keep-alive')
			fclose($this->reqs[$id]);
		else{
			$host = $this->requests[$id]->request->host;
			if (!isset($this->connectionPool[$host]))
				$this->connectionPool[$host] = array();
			$this->connectionPool[$host][] = array('resource' => $this->reqs[$id], 'time' => microtime(true));
		}
		unset($this->responses[$id]);
		unset($this->reqs[$id]);
		unset($this->requests[$id]);
		$this->checkQuewe();
	}
}
