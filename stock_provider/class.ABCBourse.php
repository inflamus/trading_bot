<?php
require('../class.ABCBourse.php');
require('./interface.StockProvider.php');
require('../class.StockInd.php');

class ABCBourseStock extends ABCBourse implements StockProvider
{
	const GRAPH_URL = "https://www.abcbourse.com/ws/charts.asmx/GetTicksEOD";
	
	protected $data = array(
		'tickerID' => 'xx00000000',
		'length' => '365',
		'XPeriod' => '0',
		'guid' => 'pJOc3m+WOt07IOkHEpmxGlKQkH39q5Omx2UcHSx01G8%3D'
	);
	protected $XPeriod, $Name;
	public function __construct(Stock $stock)
	{
		$this->data['tickerID'] = $stock->ISIN();
		
		return $this;
	}
	
	public function getData()
	{
		$re = $this->post(self::GRAPH_URL, $this->data);
		print_r($re);
		$this->XPeriod = $re->d->XPeriod;
		$this->Name = $re->d->Name;
		foreach($re->d->QuoteTab as $v)
		{
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
			$date = str_split($v->d, 2);
			$date = '20'.$date[0].'-'.$date[1].'-'.$date[2];
			yield $date => array($v->o, $v->h, $v->l, $v->c, $v->v, $v->c); // adjustedclose is close 
		}
	}
	
	public function isCachable() // Must return true if cachable, of false if not. Very Provider-dependant.
	{
		return false;
	}
	
	public function Length($l)
	{
	//TODO : Length : 1, 2, 5, 10, 90, 180, 365, 730, 1825, 3650, 5475, 7300, 9125
		switch($l)
		{
			default:
			case 'year': case 'y': case 365: case '1an':
				$this->data['length'] = '365';
			break;
			case '2years': case '2y': case 730: case '2ans':
				$this->data['length'] = '730';
			break;
			case '5years': case '5y': case 1825: case '5ans':
				$this->data['length'] = '1825';
			break;
			case '10years': case '10y': case 3650: case '10ans':
				$this->data['length'] = '3650';
			break;
			case '20years': case '20y': case 5475: case '20ans':
				$this->data['length'] = '5475';
			break;
			case '6months': case '6m': case 180: case '1/2y': case '6mois':
				$this->data['length'] = '180';
			break;
			case '3months': case '3m': case 90: case '1/4y': case '3mois':
				$this->data['length'] = '90';
			break;
			case '10days': case '10d': case 10: case '10d':  case '10jours': case '10j':
				$this->data['length'] = '10';
			break;
			case '5days': case '5d': case 5: case '5d': case '5jours': case '5j':
				$this->data['length'] = '5';
			break;
			case '2days': case '2d': case 2: case '2d': case '2jours': case '2j':
				$this->data['length'] = '2';
			break;
			case '1day': case '1d': case 1: case '1d': case '1jour': case '1j':
				$this->data['length'] = '1';
			break;
		}
		return $this;
	}
	public function Period($p) // $p = 'd'ay, 'w'eek, 'm'onth
	{
		switch($p)
		{
			default:
			case 'day':	case 'd':	case 0:	case 'jour':
				$this->data['XPeriod'] = "0";
			break;
			case 'week':	case 'w': case 7:	case 'semaine':
				$this->data['XPeriod'] = "7";
			break;
			case 'month':	case 'm':	case 30:	case 'mois':
				$this->data['XPeriod'] = '30';
			break;
			case -1: case '1mn': case '1min':
				$this->data['XPeriod'] = "-1";
			break;
			case -2: case '2mn': case '2min': case '1/30h':
				$this->data['XPeriod'] = "-2";
			break;
			case -5: case '5mn': case '5min': case '1/12h':
				$this->data['XPeriod'] = "-5";
			break;
			case -10: case '10mn': case '10min': case '1/6h':
				$this->data['XPeriod'] = "-10";
			break;
			case -15: case '15mn': case '15min': case '1/4h':
				$this->data['XPeriod'] = "-15";
			break;
			case -30: case '30mn': case '30min': case '1/2h':
				$this->data['XPeriod'] = "-30";
			break;
			case -60: case '60mn': case '60min': case '1h': case '1hour': case 'heure':
				$this->data['XPeriod'] = "-60";
			break;
			case -120: case '120mn': case '120min': case '2h': case '2hours':
				$this->data['XPeriod'] = "-120";
			break;
			case -240: case '240mn': case '240min': case '4h': case '4hours':
				$this->data['XPeriod'] = "-240";
			break;
		}
		return $this;
	}
	public function isStock() // Return true if Stock data is available, or false otherwise.
	{
		
		//TODO : mini request for is this stock available ?
	}
// 	public function From($year, $month = null, $day = null); // Set the beginning date.
		// $year may be a int(4), and then requires $month and $day to be not null,
		// or an array($y, $m, $d) or a string of english formated date
// 	public function To($year, $month=1, $day = 1); //Idem
	
}

$ABC = new ABCBourseStock(new Stock("FI0009000681"));
print_r($ABC->getData());
?>
