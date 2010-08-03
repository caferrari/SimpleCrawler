<?php

header ('Content-type: text/plain; charset=utf-8'); 

error_reporting(E_ALL);
ini_set('display_errors', true);
set_time_limit(0);

require_once '../src/CarlosFerrari/SimpleCrawler/Request.php';
require_once '../src/CarlosFerrari/SimpleCrawler/Crawler.php';

use CarlosFerrari\SimpleCrawler\Crawler as Crawler;
use CarlosFerrari\SimpleCrawler\Request as Request;

// onComplete callback
$complete = function($response){
	
};

// onHttpError callback
$error = function($response, $request, $self){
	echo $request->uri . " erro: {$response->code}\n";
	// If it's a redirect.. create a new request and put into the work
	if ($response->code==302 || $response->code==301){
		$r = new Request($response->headers['Location'], $request->method);
		$r->callbacks = $self->callbacks;
		echo "Redirecting to: {$r->request->uri}\n";
		Crawler::getInstance()->addRequest($r)->work();
	}else{
		// if it's another kind of error
		var_dump($response);
	}
};

// onConnectionOpen callback, do nothing
$open = function($response){ };


$c = Crawler::getInstance();
for ($x=0; $x<1000; $x++){
	$r = new Request('http://to.gov.br/');
	$r->setCallback('onComplete', $complete);
	$r->setCallback('onHttpError', $error);
	$r->setCallback('onConnectionOpen', $open);
	$c->addRequest($r);
}

// debug level 5... everything
$c->debug = 5;

// setup the crawler with a Teorical link of 2 megabytes and put it to work
$stat = $c->setBandwidth(2)->work();

// print some status
print_r($stat);
