<?php
//UNUSED
class StockData
{
	use InlineConstructor;

	private $stock = null;
	
	/*
	 * **** ORDERED BY DATE FROM OLDEST TO NEWEST *****
	 * [date] => array( //date(string) as yyyy-mm-dd
	 * 		[Open],		//(float)
	 * 		[High],		//(float)
	 * 		[Low],		//(float)
	 * 		[Close],	//(float)
	 * 		[Volume],	//(int)
	 * 		[AdjustedClose] // ajusté par le dividende et les divisions si il y a
	 * 	),
	 */
	public $date, $open, $high, $low, $close, $volume, $adjclose;
	public function __construct(Stock $stock)
	{
		return $this;
	}
	
	public function date($input = null)
	{
		if(is_null($input))	return $this->date->format('Y-m-d H:i:s');
		//TODO date universelle
		if($input instanceof DateTime)
			$this->date = $input;
		if(is_int($input))
			$this->date = date_create_from_format($input, 'U');
		if(is_string($input))
			$this->date = new DateTime($input);
		return $this;
	}
	
	public function __set($n, $v)
	{
		if(isset($this->$n)) && empty($this->$n)
			$this->$n = (float)$v;
		else
			throw new Exception('Error, '.$n.' already set as '.$this->$n.' : $v.');
		return $this;
	}
	
	public function __get($n)
	{
		if(isset($this->$n))
			return $this->$n;
		throw new Exception('Error, '.$n.' not def.');
	}
	
	public function __call($n, $arg)
	{
		if(!empty($arg))
			$this->$n = $arg[0];
		else
			return $this->$n;
		return $this;
	}

}
