<?php

namespace Leo\lib;

/**
 * Class Backoff
 * @package Leo\lib
 */
class Backoff
{
	/**
	 * @var int
	 */
	private $initialDelay;
	/**
	 * @var int
	 */
	private $maxDelay;
	/**
	 * @var int
	 */
	private $currentDelay;
	/**
	 * @var int
	 */
	private $currentDelayIncrement = 0;

	/**
	 * Backoff constructor.
	 * @param int $initialDelay
	 * @param int $maxDelay
	 */
	public function __construct($initialDelay = 1, $maxDelay = 7200)
	{
		$this->initialDelay = $initialDelay;
		$this->currentDelay = $this->initialDelay;
		$this->maxDelay = $maxDelay;
	}

	/**
	 * Perform the backoff. Sleep the number of seconds for the current delay.
	 */
	public function backoff()
	{
		// increment the current delay
		$this->currentDelayIncrement++;
		// get the current delay seconds
		$this->currentDelay = $this->fibanocci($this->currentDelayIncrement);

		if ($this->currentDelay > $this->maxDelay) {
			$this->currentDelay = $this->maxDelay;
		}

		Utils::debug(['operation' => 'backoff', 'seconds' => $this->currentDelay]);//"Backing off for {$this->currentDelay} seconds before retrying.");
		sleep($this->currentDelay);
	}

	/**
	 * Run Fibanocci from our initial delay
	 * @param int $n
	 * @param int $a
	 * @param int $b
	 * @return int
	 */
	private function fibanocci($n, $a = null, $b = null)
	{
		// use the initial delay if no value passed in
		$a = ($a !== null) ? $a : $this->initialDelay;
		$b = ($b !== null) ? $b : $this->initialDelay;

		if ($n) {
			return $this->fibanocci($n - 1, $b, $a + $b);
		} else {
			return $a;
		}
	}

	/**
	 * Reset the current delay seconds
	 */
	public function reset()
	{
		$this->currentDelay = $this->initialDelay;
		$this->currentDelayIncrement = 0;
	}

	/**
	 * Return the current delay seconds
	 * @return int
	 */
	public function getCurrentDelay()
	{
		return $this->currentDelay;
	}
}
