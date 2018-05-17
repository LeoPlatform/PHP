<?php
namespace Leo\lib;
use Aws\Firehose\FirehoseClient;

class Firehose extends Uploader{
	private $client;
	private $stream;

	public $combine = true;
	// public $batch_size = 1024 * 1024 * 3;
	public $batch_size = 3145728;
	// public $record_size = 1024 * 1024 * .7;
	public $record_size = 734003;
	public $max_records = 500;
	public $duration = 100;

	public $bytesPerSecond =1024*1024*2;

	private $opts;

	public function __construct($id, $stream,$region, $opts=[]) {
		$this->opts = array_merge([
			"maxRetries"=>4
		], $opts);
		$this->stream = $stream;
		$this->client = new FirehoseClient(array(
			"region"=> $region,
			"version"=>"2015-08-04",
			 'http'    => [
		        'verify' => false
		    ]
		));
	}

	public function sendRecords($batch) {
		$retries = 0;

		$correlation = null;
		do {
			$time_start = microtime(true);
			$cnt = 0;
			$len =0;
			$r =0;
			foreach($batch['records'] as $record) {
				$cnt += $record['cnt'];
				$len += $record['length'];
				$r++;
			}
			Utils::log($batch);

			$result = $this->client->putRecordBatch([
				'DeliveryStreamName' => $this->stream,
				'Records' => array_map(function($record) {
					return ["Data"=>$record['data']];
				},$batch['records'])
			]);

			if($retries > 0) {
                // @todo - This should output based on cli vs apache usage
				print "\tRetrying(#{$retries}) {$cnt} records of size ({$len}) in " . (microtime(true) - $time_start) . " seconds\n";
			} else {
                // @todo - This should output based on cli vs apache usage
				print "\tSent {$cnt} records of size ({$len}) in " . (microtime(true) - $time_start) . " seconds\n";
			}
			$hasErrors = $result->get('FailedPutCount') == 0;
			if(!$hasErrors) {
				$batch['records'] = [];
			} else { //we need to prune the ones that went through
				$responses = $result->get("RequestResponses");
				var_dump($responses); // @todo - This should output based on cli vs apache usage
				$maxCompleted = -1;
				foreach($responses as $i=>$response) {
					if(isset($response['RecordId'])) {
						if($maxCompleted == $i -1) { //Was the last one completed, then this one can be moved
							$maxCompleted = $i;
							$correlation = $batch['records'][$maxCompleted]['correlation'];
						}
						unset($batch['records'][$i]);
					}
				}
			}
			$retries++;
		} while (\count($batch['records']) > 0 && $retries < $this->opts['maxRetries']);

		if(\count($batch['records']) > 0) {
			return [
				"success"=>false,
				'errorMessage' => 'Failed to write ' . count($batch['records']) . ' events to the stream',
			];
		} else {
			if(!empty($correlation['end'])) {
				$checkpoint = $correlation['end'];
			} else {
				$checkpoint = $correlation['start'];
			}
			return [
				"success"=>true,
				"eid"=>$checkpoint,
				"records"=>$cnt
			];
		}
	}

	public function end() {

	}
}
