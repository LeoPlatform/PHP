<?php

namespace Leo\lib;

use Aws\Kinesis\KinesisClient;

class Kinesis extends Uploader
{
	private $client;
	private $stream;
	private $id;

	// public $batch_size = 1024 * 1024 * 1;
	public $batch_size = 1048576;
	// public $record_size = 1024 * 1024 * 1;
	public $record_size = 1048576;
	public $max_records = 500;
	public $duration = 10;

	public $bytesPerSecond = 1024 * 1024 * 1.5;

	public $combine = true;

	private $opts;

	private $backoff;

	public function __construct($id, $config, $opts = []) // opts is not passed in
	{
		// ready the backoff
		$this->backoff = new Backoff();

		$this->id = $id;
		$this->opts = array_merge([
			"maxRetries" => 10, // set default number of retries to 10 now that we have backoff
			'profile' => 'default',
		], $config);

		$this->stream = $config['leosdk']['kinesis'];
		$this->client = new KinesisClient(array(
			'profile' => $config['leoaws']['profile'],
			"region" => $config['leoaws']['region'],
			"version" => "2013-12-02",
			'http' => [
				'verify' => false
			]
		));
	}

	public function sendRecords($batch)
	{
		$retries = 0;
		$correlation = null;

		do {
			$time_start = microtime(true);
			$cnt = 0;
			$len = 0;
			foreach ($batch['records'] as $record) {
				$cnt += $record['cnt'];
				$len += $record['length'];
			}

			$results = [
				'operation' => 'Kinesis->sendRecords',
				'id' => $this->id,
				'success' => false, // set this to false by default. Change if successful.
				'records' => $cnt,
				'recordsFailed' => 0,
				'size' => $len,
				'retries' => $retries,
				'time' => 0,
			];

			try {
				$result = $this->client->putRecords([
					'StreamName' => $this->stream,
					'Records' => array_map(function ($record) {
						return [
							"Data" => gzencode($record['data']),
							"PartitionKey" => $this->id

						];
					}, $batch['records'])
				]);
				$hasErrors = $result->get('FailedRecordCount') > 0;
			} catch (\Exception $e) {

				if (empty($hasErrors)) {
					$hasErrors = $cnt;
				}

				if ($retries + 1 >= $this->opts['maxRetries']) {
					$results['recordsFailed'] = $hasErrors;
					$results['time'] = (microtime(true) - $time_start) . ' seconds';
					Utils::error($results);

					// throw an exception with remaining batch and record count
					$exception = new Exception($e->getMessage());
					$exception->setBatchCount(\count($batch['records']));
					$exception->setRecordCount($cnt);

					throw $exception;
				}
			}

			$results['time'] = (microtime(true) - $time_start) . ' seconds';

			if (!$hasErrors) {
				// Return the correlation eid on success
				$correlation = array_pop($batch['records'])['correlation'];
				$results['success'] = true;
				$results['recordsFailed'] = 0;
				$batch['records'] = [];
			} else if (!empty($result)) { //we need to prune the ones that went through
				$responses = $result->get("Records");
				$maxCompleted = -1;
				foreach ($responses as $i => $response) {
					if (isset($response['SequenceNumber'])) {
						if ($maxCompleted == $i - 1) { //Was the last one completed, then this one can be moved
							$maxCompleted = $i;
							$correlation = $batch['records'][$maxCompleted]['correlation'];
						}
						unset($batch['records'][$i]);
					}
				}
			}

			$recordsRemaining = \count($batch['records']);

			// if we have records remaining to submit, backoff and retry.
			if ($recordsRemaining) {
				$results['recordsFailed'] = $cnt;
				Utils::error($results);
				$this->backoff->backoff();
			} else {
				// reset the backoff delay because all was successful
				$this->backoff->reset();
			}

			$retries++;
		} while (\count($batch['records']) > 0 && $retries < $this->opts['maxRetries']);

		if (\count($batch['records']) > 0) {
			// throw an exception with remaining batch and record count
			$exception = new Exception('Failed to write events to the stream.');
			$exception->setRecordCount($cnt);
			$exception->setBatchCount($recordsRemaining);

			throw $exception;
		} else {
			if (!empty($correlation['end'])) {
				$checkpoint = $correlation['end'];
			} else {
				$checkpoint = $correlation['start'];
			}

			$results['eid'] = $checkpoint;

			return $results;
		}
	}

	public function end()
	{

	}
}
