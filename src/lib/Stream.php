<?php
namespace LEO;

class Stream {
	private $id;
	private $opts;
	private $config;

	public function __construct($id=null, $opts=[]) {
		$this->id = $id;
		$this->opts = array_merge([
			"enableLogging"=>false,
			"version"=>"latest",
			"server"=>gethostname(),
			"uploader"=>"firehose"
		],$opts);

		$this->config = array_merge([
			"region"=>null,
			"firehose"=>null,
			"kinesis"=>null,
			"s3"=>null
		], $opts['config']);
	}
	public function createBufferedWriteStream($opts=[], $checkpointer) {
		if(!$this->id) {
			throw new \Exception("You must specify a bot id");
		}

		switch($this->opts['uploader']) {
			case "firehose":
				$uploader = new Firehose($this->id, $this->config['firehose'], $this->config['region']);
				break;
			case "kinesis":
				$uploader = new Kinesis($this->id, $this->config['kinesis'], $this->config['region']);
				break;
			case "mass":
				$uploader = new Mass($this->id, $this->config['s3'], $this->config['region']);
				break;
			case "ugradeable":
				$uploader = new Upgradeable($this->id, $this->config['firehose'], $this->config['region']);
				break;

		}
		return new Combiner($this->id, $opts, $uploader,$checkpointer);
	}

	public function checkpoint() {
		
	}
	public function createTransformStream($queue, $toQueue, $opts, $transformFunc) {
		
	}
}
