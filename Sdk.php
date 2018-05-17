<?php
/**
 * Leo Innovation Platform API Connection
 * Requires aws sdk version 3 which is installed into
 * a separate directory called aws3.
 */
namespace Leo;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;

use Aws\Firehose\FirehoseClient;
use Aws\Kinesis\KinesisClient;
use Aws\S3\S3Client;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

use Aws\Firehose\Exception\FirehoseException;

class Sdk {
	private $config;
	private $id;

	public function __construct($id, $opts) {
		$this->id = $id;
		$this->config = array_merge([
			"region"=>null,
			"firehose"=>null,
			"kinesis"=>null,
			"s3"=>null,
			"enableLogging"=>false,
			"uploader"=>"kinesis",
			"version"=>"latest",
			"server"=>gethostname(),
			"debug"=>true
		], $opts);
		if($this->config['enableLogging']) {
			$this->enableLogging();
		}
	}

	/**
	*  @return Loader
	**/
	public function createLoader($checkpointer, $opts=[]) {
		if(empty($opts['config'])) {
			$opts['config'] = [];
		}
		$opts['config'] = array_merge($opts['config'], $this->config);
		if(!$this->id) {
			throw new \Exception("You must specify a bot id");
		}

		switch($opts['config']['uploader']) {
			case "firehose":
				$uploader = new lib\Firehose($this->id, $this->config['firehose'], $this->config['region']);
				$massuploader = new lib\Mass($this->id, $this->config['s3'],$this->config['region'], $uploader);
				break;
			case "kinesis":
				$uploader = new lib\Kinesis($this->id, $this->config['kinesis'],$this->config['region']);
				$massuploader = new lib\Mass($this->id, $this->config['s3'],$this->config['region'], $uploader);
				break;
			case "mass":
				$kinesis = new lib\Kinesis($this->id, $this->config['kinesis'],$this->config['region']);
				$uploader = new lib\Mass($this->id, $this->config['s3'],$this->config['region'], $kinesis);
				break;
		}
		return new lib\Combiner($this->id, $opts, $uploader, $massuploader,$checkpointer);
	}

	public function createOffloader($queue, $opts=[]) {
		$opts = array_merge([
			"buffer"=>1000,
			"loops"=>100,
			"debug"=>false,
			"run_time"=>new \DateInterval('P4M')
		],$opts);

		if($opts['run_time'] instanceof \DateInterval) {
			$opts['end_time'] = (new \DateTime())->add($opts['run_time']);
		} else if(!empty($opts['run_time'])) {
			$opts['end_time'] = new \DateTime("+ " . $opts['run_time']);
		}

		$events = new lib\Events($this->config);
		return $events->getEventReader($this->id, $queue, $events->getEventRange($this->id, $queue,$opts),$opts);
	}


	public function createEnrichment($queue, $transform, $toQueue, $opts=[]) {
		$reader = $this->createOffloader($queue,$opts);
		$stream = $this->createLoader(function ($checkpoint) use ($reader){
			lib\Utils::log($checkpoint);
			$reader->checkpoint($checkpoint);
		});
		$lastEvent = null;
		foreach($reader->events as $i=>$event) {
			$newEvent = $transform($event['payload'], $event);
			if($newEvent) {
				$stream->write($toQueue,$newEvent, ["source"=>$queue, "start"=>$event['eid']]);
			}
		}

		$stream->end();
	}


	public function enableLogging() {
		if(!$this->id) {
			throw new \Exception("You must specify a bot id");
		}
		return new lib\Logger($this->id, $this->config);
	}
}
