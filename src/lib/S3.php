<?php
namespace LEO\lib;
use Aws\DynamoDB\Marshaler;


class S3 {
	private $client;
	private $dynamoDBClient;

	public function __construct($client,$dynamoDBClient) {
		$this->client = $client;
		$this->dynamoDBClient = $dynamoDBClient;
	}

	public function getReader($queue, $range,$opts) : EventIterator {
		if(empty($range['version']) || $range['version'] == 1) {
			return new S3Reader($this->client, $this->dynamoDBClient, $queue, $range,$opts);
		} else {
			// return $this->getReaderV2($queue,$range);
		}
	}
}

class S3IteratorV1 implements \Iterator{
	private $client;

	private $queue;
	private $range;
	private $originalStart;
	private $position;

	private $cached;
	private $offset;

	private $last = null;

	private $dynamoDBClient;
	private $s3Indexes = [];
	private $s3Position = 0;

	public function __construct($client, $dynamoDBClient, $queue,$range,$opts) {
		$this->client = $client;
		$this->queue = $queue;
		$this->range = $range;
		$this->originalStart = $range['start'];
		$this->position = 0;
		$this->offset = 0;
		$this->cached = [];

		$this->opts = array_merge([
			"limit"=>1000000
		], $opts);
		
		$this->s3Indexes = [];
		$params = [
			"TableName"=>"Leo_s3_index",
			"KeyConditionExpression"=>"#queue = :queue and #end between :kinesis_number and :max_kinesis_number",
			"ExpressionAttributeNames"=> [
				"#queue"=> "queue",
				"#end"=> "end"
			],
			'Limit'=>100,
			"ExpressionAttributeValues"=>[
				":queue"=>['S'=>$this->queue],
				":max_kinesis_number"=>['S'=>$this->range['max']],
				":kinesis_number"=>['S'=>$this->range['start']."0"]
			],
			"ReturnConsumedCapacity"=>'TOTAL'
		];
		$result = $dynamoDBClient->Query($params);
		$marshaler = new Marshaler();
		foreach($result->get('Items') as $item) {
			$this->s3Indexes[] = $marshaler->unmarshalItem($item);
		}

		$this->load();
	}

	private function load() {
		$this->offset += count($this->cached);
		if($this->position < $this->opts['limit'] && (new \DateTime()) < $this->opts['end_time']) {
			$current = $this->s3Indexes[$this->s3Position];
			var_dump($current); // @todo - This should output based on cli vs apache usage
			$result = $this->client->getObject([
			    'Bucket' => 'leo-s3bus-1r0aubze8imm5',
			    'Key' => $current['key'],
			    'Range' => "bytes=" . $current['gzipOffset'] . "-" . ($current['gzipOffset'] + $current['gzipLength']-1),
			]);
			$this->cached = array_map(function ($a) {
				return json_decode($a, true);
			}, preg_split("/\r?\n/", gzdecode($result['Body']), -1, PREG_SPLIT_NO_EMPTY));
		} else {
			$this->cached = [];
		}
	}


	public function rewind() {
		if($this->range['start'] != $this->originalStart) {
			$this->range['start'] = $this->originalStart;	
			$this->position = 0;
			$this->offset = 0;
			$this->s3Position = 0;
			$this->load();
		} else {
			$this->position = 0;
		}
	}
	public function current() {
		$this->last = $this->cached[$this->position-$this->offset];
		$this->last['eid'] = $this->last['kinesis_number'];
		unset($this->last['kinesis_number']);
		return $this->last;
	}
	public function key() {
		return $this->position;
	}
	public function next() {
		++$this->position;
		if(count($this->cached) && ($this->position - $this->offset) >= count($this->cached)) {
			var_dump("need to load more"); // @todo - This should output based on cli vs apache usage
			++$this->s3Position;
			$this->load();
		}
	}
	public function valid() {
		return isset($this->cached[$this->position-$this->offset]) && $this->position < $this->opts['limit'] && (new \DateTime()) < $this->opts['end_time'];
	}
};

class S3Reader extends EventIterator {
	public $events;
	public function __construct($client, $dynamoDBClient, $params,$range,$opts) {
		$this->events = new S3IteratorV1($client, $dynamoDBClient, $params,$range,$opts);
	}
} 
?>
