<?php
namespace Leo;
class Loader {
	private $id;
	private $opts;

	public function __construct($id=null, $opts=[]) {
		$this->id = $id;
		$this->opts = array_merge([
			"uploader"=>"firehose"
		],$opts);
	}

	public function checkpoint() {
		
	}


	public function createTransformStream($queue, $toQueue, $opts, $transformFunc) {
		$opts = array_merge([
			"buffer"=>1000,
			"loops"=>100,
			"start"=>null,
			"limit"=>null,
			"size"=>null,
			"debug"=>false,
			"run_time"=>new \DateInterval('P4M')
		],$opts);

		if($opts['run_time'] instanceof \DateInterval) {
			$opts['end_time'] = (new \DateTime())->add($opts['run_time']);
		} else if(!empty($opts['run_time'])) {
			$opts['end_time'] = new \DateTime("+ " . $opts['run_time']);
		}

		$events = new Lib\Events($this->config);
		$reader = $events->getEventReader($queue, $events->getEventRange($this->id, $queue,$opts),$opts);

		$stream = $this->createBufferedWriteStream($opts, function ($checkpointData,$leoCheckpoint) {
            var_dump($checkpointData); // @todo - This should output based on cli vs apache usage
		});
		foreach($reader->events as $count=>$event) {
			$result = $transformFunc($event, null);
			if(empty($result)) {
				continue;
			} else if(is_array($result) && isset($result[0])) {
				foreach($result as $r) {
					$stream->write($toQueue,$r, ["source"=>$queue, "start"=>$event['eid']],[
						"timestamp"=>$event['event_source_timestamp']
					]);
				}
			} else {
				$stream->write($toQueue,$result, ["source"=>$queue, "start"=>$event['eid']],[
					"timestamp"=>$event['event_source_timestamp']
				]);
			}
		}
		$stream->end();
	}
}
