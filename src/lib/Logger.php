<?php
namespace Leo\lib;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

class Logger {
	private $id;
	private $client;
	private $opts;

	private $messages = [];
	private $sequenceNumber=null;


	private $config;
	private $configFile;

	private $requestId;
	private $startTime;


	private function getLogStream() {
		if($this->config) {
			return $this->config;
		}
		else if(file_exists($this->configFile)) {
			$config = json_decode(file_get_contents($this->configFile), JSON_OBJECT_AS_ARRAY );

			//check to see if the stream matches today's
			//if not, create a new one
			if(0) {
				$logStreamName = date("Y/m/d/") ."[{$this->opts['version']}]/{$this->opts['server']}/". Utils::milliseconds();
				$result = $this->client->createLogStream([
					"logGroupName" => $logGroupName,
					"logStreamName" => $logStreamName
				]);
				$config = [
					"logGroupName" => $logGroupName,
					"logStreamName" => $logStreamName,
					"sequenceNumber"=>null
				];
			}
		} else {
			$logGroupName = "/aws/lambda/{$this->id}";
			try {
				$result = $this->client->createLogGroup([
					"logGroupName" => $logGroupName
				]);
			} catch(\Aws\CloudWatchLogs\Exception\CloudWatchLogsException $e) {
				//don't care about this one
			}
			$logStreamName = date("Y/m/d/") ."[{$this->opts['version']}]/{$this->opts['server']}/". Utils::milliseconds();
			$result = $this->client->createLogStream([
				"logGroupName" => $logGroupName,
				"logStreamName" => $logStreamName
			]);
			$config = [
				"logGroupName" => $logGroupName,
				"logStreamName" => $logStreamName,
				"sequenceNumber"=>null
			];
		}
		return $this->config = $config;
	}

	private function updateConfig($result) {
		$this->config['sequenceNumber'] = $result->get("nextSequenceToken");
		file_put_contents($this->configFile, json_encode($this->config));
	}


	public function __construct($id,$opts) {
		$this->opts = $opts;
		$this->id = $id;
		$this->client = new CloudWatchLogsClient([
			"region"=>"us-west-2", // @todo - Should this be hard-coded or an opt?
			"version"=>"2014-03-28",
			'http'    => [
				'verify' => false
			]
		]);

		$this->configFile = sys_get_temp_dir() . "/leo_log.json";
		$this->requestId = uniqid();
		$this->startTime = Utils::milliseconds();

		$this->getLogStream();

		register_shutdown_function( array($this, "checkFatal") );
		set_error_handler( array($this, "logBuiltinError") );
		set_exception_handler( array($this, "logException") );
		ini_set( "display_errors", "on" );
		ini_set('error_reporting', -1);

		$this->addMessage("START RequestId: {$this->requestId} Version: {$this->opts['version']}");
	}

	private function addMessage($message) {
		$this->messages[] = [
			"timestamp"=>Utils::milliseconds(),
			"message"=>date('Y-m-d\TH:i:s\Z')."	{$this->requestId}	" . $message
		];

	}
	public function info() {

	}

	public function warn() {

	}

	public function error() {

	}

	public function debug($message, $file, $line) {

	}


	public function logException( $e) {
		$this->log(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
		$this->end();
		exit();
	}

	public function logBuiltinError($err, $message, $file, $line, $context=null) {
		switch ($err) {
			case 1: $type = 'ERROR'; break;
			case 2: $type = 'WARNING'; break;
			case 4: $type = 'PARSE'; break;
			case 8: $type = 'NOTICE'; break;
			case 16: $type = 'CORE_ERROR'; break;
			case 32: $type = 'CORE_WARNING'; break;
			case 64: $type = 'COMPILE_ERROR'; break;
			case 128: $type = 'COMPILE_WARNING'; break;
			case 256: $type = 'USER_ERROR'; break;
			case 512: $type = 'USER_WARNING'; break;
			case 1024: $type = 'USER_NOTICE'; break;
			case 2048: $type = 'STRICT'; break;
			case 4096: $type = 'RECOVERABLE_ERROR'; break;
			case 8192: $type = 'DEPRECATED'; break;
			case 16384: $type = 'USER_DEPRECATED'; break;
			case 30719: $type = 'ALL'; break;
			default: $type = 'UNKNOWN'; break;
		}

		return $this->log($type, $message, $file, $line);
	}
	public function log($type, $message, $file, $line) {
		$this->addMessage(strtoupper($type) . ": " . $message . "\n\n $file $line");
		return false;
	}
	public function __destruct() {
		$this->end();
	}

	private function sendEvents()  {
		if(count($this->messages)) {
			$params = [
			    'logGroupName' => $this->config['logGroupName'],
			    'logStreamName' => $this->config['logStreamName'],
			    'logEvents' => $this->messages
			];
			if($this->config['sequenceNumber']) {
				$params['sequenceToken'] = $this->config['sequenceNumber'];
			}
			$result = $this->client->putLogEvents($params);
			$this->updateConfig($result);
		}
		$this->messages = [];
	}

	public function end() {
		if($this->requestId) {
			$this->addMessage("END RequestId: {$this->requestId}");
			$duration = round(Utils::milliseconds() - $this->startTime, 2);
			$memory = round(memory_get_peak_usage() / 1024 / 1024);
			$memoryLimit = ini_get('memory_limit');
			$this->addMessage("REPORT RequestId: {$this->requestId}	Duration: $duration ms	Billed Duration: $duration ms Memory Size: $memoryLimit MB	Max Memory Used: $memory MB");


			
		}
		$this->sendEvents();
	}

	public function checkFatal() {
		$e = error_get_last();
		if($e['type'] == E_ERROR) {
			$this->log($e['type'], $e['message'], $e['file'], $e['line']);
		}
	}
}
