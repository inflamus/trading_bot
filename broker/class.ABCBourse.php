<?php 

class ABCBourseBroker extends ABCBourse implements Broker
{
	CONST PORTIF = 220665; //PORTEFEUILLLE ID in ABCBOURSE GAME

	public function __construct($portifID = self::PORTIF)
	{
		return $this;
	}
	
// 	public function isISIN(&$i)
// 	{
// 		if(!class_exists('StockInd'))
// 			if(is_readable('class.StockInd.php'))
// 				require_once('class.StockInd.php');
// 			else
// 				return false; // couldn't search for indice in DB
// 		return StockInd::isISIN($i);
// 	}
	
	public function Ordre(Stock $stock)
	{
		return new OrdreABCBourse($stock);
	}
	
	public function Valorisation($getraw = false)
	{
		preg_match_all('/'.
				'<tr name="([0-9]+)(-([A-Z]+)[a-z])+-[A-Z]">.+'. //1 = idLign, 3= mnemo
				'<td class="alct">.+'.
					'<span class="ve1_2">(vendre|racheter)<\/span>.+'. //4 = vendre / racheter
				'<\/td>.+'.
				'<td class="allf">.+<\/td>.+'.
				'<td>([0-9]+)<\/td>.+'. //5 = qty
				'<td>([0-9\.]+)<\/td>.+'. //6 prix revient
				'<td><b>([0-9\.]+)<\/b><\/td>.+'. //7 dernier quote
				'<td class=".+">\+?([0-9\.-]+)\%<\/td>.+'. //8 var jour
				'<td>([0-9\.]+)<\/td>.+'. //9 capital
				'<td class=".+">\+?([0-9\.-]+)<\/td>.+'. //10gain  eur
				'<td class=".+">\+?([0-9\.-]+)\%<\/td>.+'. //11gainpct
				'<\/tr>'.
				'/siU', 
		file_get_contents(self::VALO_URL, null,
			stream_context_create(
				array(
					'http' => array(
						'method' => 'GET', 
						'header' => 'Cookie: '.self::ABCUSER_COOKIE.';'."\n".
									'Referer: https://www.abcbourse.com/game/displayp.aspx'."\n"
									
						)
					)
			)
		), $matches);
		
		foreach($matches[1] as $k => $idLign)
		{
			$Pos = new PositionABCBourse($idLign, $matches[3][$k], $matches[5][$k]);
			$Pos->set('QTY', $matches[5][$k])
				->set('SENS', $matches[4][$k]=='vendre' ? 1 : 0)
				->set('PRIXREVIENT', $matches[6][$k])
				->set('LASTQUOTE', $matches[7][$k])
				->set('DAYVAR', $matches[8][$k])
				->set('GAINEUR', $matches[10][$k])
				->set('GAINPCT', $matches[11][$k])
				->set('CAPITAL', $matches[9][$k]);
			yield $Pos;
		}

	}

	public function PendingOrders()
	{
		preg_match_all('/'.
				'<tr class="alct">.+'. 
				'<td>.+'.
					'<span class="ac1_3">(A|V)<\/span>.+'. //1 = vendre / racheter
				'<\/td>.+'.
				'.+'.
				'<td class="allf"><b><a href="display\.aspx\?s=([A-Z0-9]{12})[a-z]+">.+<\/a><\/b><\/td>.+'. //2 = ISIN
				'<td>([0-9]+)<\/td>.+'. //3 quantite
				'<td data-sort-value="[0-9]+" class="mobno">(.+)<\/td>.+'. //4 date expire
				'<td>(Limité|Stop) : ([0-9,]+) €<\/td>.+'. //5 Cours limité
				'.+'. 
				'<\/tr>'.
				'/siU', 
		file_get_contents(self::PEND_URL, null,
			stream_context_create(
				array(
					'http' => array(
						'method' => 'GET', 
						'header' => 'Cookie: '.self::ABCUSER_COOKIE.';'."\n".
									'Referer: https://www.abcbourse.com/game/displayp.aspx'."\n"
									
						)
					)
			)
		), $matches);
		
		foreach($matches[1] as $k => $idLign)
		{
			$Pos = new ABCBoursePendingOrder($matches[2][$k]);
			$Pos->set('QTY', $matches[3][$k])
				->set('SENS', $matches[1][$k]=='A' ? 1 : 0);
				//TODO : add isin, others params
			yield $Pos;
		}
	}
}

class OrdreABCBourse extends ABCBourseBroker implements Ordre
{
	const SEND_ORDER = "https://www.abcbourse.com/game/tab_market.aspx/sendOrder";
	protected $remoteURL = self::SEND_ORDER;
	private $isin = null, $p = null;
	protected $data = array(
		'portifID' => parent::PORTIF,
		'valuePortif' => '100000',
		'stopQuote' => '',
		'limitQuote' => '',
		'expiration' => '');
	
	public function __construct(Stock $stock)
	{
		$isin = $stock->ISIN();
		$this->tickerID = $isin;
		$this->isin = $isin;
		$this->dftexp();
	}
	protected function dftexp()
	{
		$this->expiration = date('d/m/Y', strtotime('3 weeks Friday'));
		return $this;
	}
	protected function idLign($idlign)
	{
		$this->idLigh = $idlign;
		return $this;
	}
	
	public function __get($n)
	{
		return $this->data[$n];
	}
	public function __set($n, $v)
	{
		$this->data[$n] = $v;
		return true;
	}
// 	public function __call($n,$v)
// 	{
// 		return $this;
// 	}
	public function Achat($qte)
	{
		$this->sens = 'buy';
		$this->qty = (string)(int)$qte;
		return $this;
	}
	public function Vendre($qte)
	{
		$this->sens = 'sell';
		$this->qty = (string)(int)$qte;
		return $this;
	}
	public function ASeuil($seuil)
	{
		$this->stopQuote = (float) $seuil;
		$this->orderType = 'stp';
		return $this;
	}
	public function APlage($seuil, $lim)
	{
		$this->stopQuote = (string)(float)$seuil;
		$this->limitQuote = (string)(float)$lim;
		$this->orderType = 'stp';
		return $this;
	}
	public function ACoursLimite($lim)
	{
		$this->limitQuote = (float)$lim;
		$this->orderType = 'lmt';
		return $this;
	}
	public function AuDernierCours()
	{
		return $this->AuMarche();
	}
	public function AuMarche()
	{
		$this->orderType = 'mkt';
		return $this;
	}
	public function Exec()
	{
		$re = $this->post($this->remoteURL, $this->data);
			if(!isset($re->d) || $re->d->Code != "2")
				throw new Exception($re->d->Message . ' from this order : '.print_r($this->data, true));
		return new ABCBoursePendingOrder($this->isin);
// 		return $this;
	}
	public function Expire($exp)
	{
		$this->expiration = is_int($exp) ? date('d/m/Y', $exp) : $exp;
		return $this;
	}

	public function __destruct()
	{
		unset($this->data);
	}
	
}

class PositionABCBourse extends OrdreABCBourse implements Position 
{
	use PositionScheme;
	
	private $idLign = -1;
	private $posQty = 0;
	public function __construct($idLign, $mnemo, $qte)
	{
		$this->dftexp()->idLign($idLign);
		$this->remoteURL = "https://www.abcbourse.com/game/displayp.aspx/sendOrder";
		$this->idLign = (int) $idLign;
		$this->posQty = (int) $qte;
		$this->Stock = new Stock($mnemo);
		$this->Qte();
		
		return $this;
	}
	
	public function Qte($qte = 0)
	{
		if($qte > $this->posQty)
			throw new Exception('Wrong quantity, more than available.');
		$this->qty = (string) $qte <= 0 ? $this->posQty : $qte;
		return $this;
	}
	
	public function __toString()
	{
		return $this->Stock->Label;
	}
	
}

class ABCBoursePendingOrder implements PendingOrder
{
	use PendingOrderScheme;
	
	public function __construct($isin)
	{
		$this->Stock = new Stock($isin);
	}
	
	public function Delete()
	{
		return true;
	}
}


?>
