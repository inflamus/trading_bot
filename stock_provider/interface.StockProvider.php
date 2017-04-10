<?php

interface StockProvider
{
	const PERIOD_DAILY = 1;
	const PERIOD_WEEKLY = 2;
	const PERIOD_MONTHLY = 3;
	const PERIOD_10MIN = 4;
	const PERIOD_5MIN = 5;
	const PERIOD_2MIN = 6;
	
// 	const LENGTH
	
	
	public function isCachable() /*: bool PHP7*/; // Must return true if cachable, of false if not. Very Provider-dependant.
	public function SmartPeriod($p); // $p is one of these constants : period_daily, period_week...
	public function Length($l); // $l = string like "5years" or integer
	public function Period($p);
	public function isStock() /*: bool PHP7*/; // Return true if Stock data is available, or false otherwise.
	public function __construct(Stock $stock); // Construct with the Stock ID;
// 	public function From($year, $month = null, $day = null); // Set the beginning date.
		// $year may be a int(4), and then requires $month and $day to be not null,
		// or an array($y, $m, $d) or a string of english formated date
// 	public function To($year, $month=1, $day = 1); //Idem
	public function getData() /*: Generator pHP7*/; // Get the data array with the constant format as Stock::$data[]
	// Autoconf : if return false, thats mean it is unsupported by the stockprovider
	public function Daily();
	public function Weekly();
	public function Monthly();
	public function IntraDay($period); // in minutes
}

?>
