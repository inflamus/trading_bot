<?php

class StockInterpreter
{
	use InlineConstructor;
	
	private $stock = null;
	private $today = false;
	
	public function __construct(StockQuote $stock, $today = false)
	{
		$this->stock = $stock;
		$this->today = $today;
		return $this;
	}
	
	// test : function($currvalue, $compvalue, $prevvalue) : boolean
	// callback : function($date-key, $value from arr, $stock-pointer){ no return, must yield to return values };
	private function _Test($arr, $trigger, callable $test, $callback = null)
	{
		if($this->today)
			$arr = array_slice($arr, -2, null, true);
		$prev = null;
		foreach($arr as $date => $val)
		{
			if($prev == null)
			{
				$prev = $val;
				continue;
			}
			$trig = null;
			if(is_array($trigger))
				if(array_key_exists($date, $trigger))
					$trig = $trigger[$date];
				else
					continue;
			else
				$trig = $trigger;
			
			if($test($val, $trig, $prev))
			{
				if(!is_null($callback))
					yield ($callback($date, $val, $this->stock));
				else
					yield $date => $val;
// 			print "on $date : $val <=> $trig && previous = $prev \n";
			}
			$prev = $val;
		}
	}
	
	private function Cross($sens, $arr, $trigger, $callback = null)
	{
		return $this->_Test($arr, $trigger, function($val, $trig, $prev) use ($sens) {
			return (
				($sens && ($val >= $trig && $prev < $trig)) || 
				(!$sens && ($val <= $trig && $prev > $trig))
			);
		}, $callback);
	}
	public function CrossUp($arr, $trigger, $callback = null)
	{
		return $this->Cross(true, $arr, $trigger, $callback);
	}
	public function CrossDown($arr, $trigger, $callback = null)
	{
		return $this->Cross(false, $arr, $trigger, $callback);
	}
	
	public function Equals($arr, $trigger, $callback = null)
	{
		return $this->_Test($arr, $trigger, function($val, $trig){
			return $val == $trig;
		}, $callback);
	}
	
	public function Sup($arr, $trigger, $callback = null)
	{
		return $this->_Test($arr, $trigger, function($val, $trig){
			return $val > $trig;
		}, $callback);
	}
	
	public function Inf($arr, $trigger, $callback = null)
	{
		return $this->_Test($arr, $trigger, function($val, $trig){
			return $val < $trig;
		}, $callback);
	}
	
	public function NewHigh($arr = 30, $callback = null)
	{
		if(is_int($arr))
			$arr = $this->stock->Analysis()->NewHigh($arr);
		if(is_array($arr))
			return $this->Sup($arr, 0, $callback);
		else
			return false;
	}
	
	public function NewLow($arr = 30, $callback = null)
	{
		if(is_int($arr))
			$arr = $this->stock->Analysis()->NewLow($arr);
		if(is_array($arr))
			return $this->Sup($arr, 0, $callback);
		else
			return false;
	}
	
	public function Gaps($type, $arr = null, $callback = null)
	{
		if(is_null($arr))
			$arr = $this->stock->Analysis()->Gaps();
		return $type ? $this->Sup($arr, 0, $callback) : $this->Inf($arr, 0, $callback);
	}
	
	public static function fromScript($file)
	{
	// TODO : create from script;
	}

}
