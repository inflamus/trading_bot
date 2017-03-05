<?php

interface StockProvider
{
	const PERIOD_DAY = 'd';
	const PERIOD_WEEK = 'w';
	const PERIOD_MONTH = 'm';
	const PERIOD_5MIN = '5mn';
	
// 	const LENGTH
	
	
	public function isCachable() /*: bool PHP7*/; // Must return true if cachable, of false if not. Very Provider-dependant.
	public function Period($p); // $p = 'd'ay, 'w'eek, 'm'onth
	public function isStock() /*: bool PHP7*/; // Return true if Stock data is available, or false otherwise.
	public function __construct(Stock $stock); // Construct with the Stock ID;
// 	public function From($year, $month = null, $day = null); // Set the beginning date.
		// $year may be a int(4), and then requires $month and $day to be not null,
		// or an array($y, $m, $d) or a string of english formated date
// 	public function To($year, $month=1, $day = 1); //Idem
	public function getData(); // Get the data array with the constant format as Stock::$data[]
}

?>
