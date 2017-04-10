<?php

class SimulatorAccount
{
	use UniqueInstance;

	const PROVIDER_CACHE = 'YahooCacheStock';
	const BROKER_FEE = '0.7%';
	
	protected 	$cash = 0,
				$portefeuille = array(/* Isin => [qte, cours_d'achat, cours_actuel] */),
				$ordres = array(/* Rref => array('sens' => 'achat', 'isin', 'qte', 'cours')*/);
	private 	$slicestart = -600, $slicelength = 100;
	private		$stockCache = array();
	public function __construct()
	{
		return $this;
	}
	
	private function getStock($isin)
	{
		if(!isset($this->stockCache[$this->slicelength][$isin]))
		{
// 			$mn = StockInd::getInstance()->searchMnemo($isin);
// 			if($mn == 'SOLB')	$mn .= '.BR';
// 			elseif($mn == 'APAM')	$mn .= '.AS';
// 			else	$mn .= '.PA';
			$stock = new StockQuote(new Stock($isin), StockProvider::PERIOD_DAILY, self::PROVIDER_CACHE);
			return $this->stockCache[$this->slicelength][$isin] = $stock->Slice($this->slicestart, $this->slicelength);
		}
		else
			return $this->stockCache[$this->slicelength][$isin];
	}
	private function clearStockCache()
	{
		unset($this->stockCache[$this->slicelength]);
		return $this;
	}
	public function Start($start)
	{
		$this->slicestart = -1*($start+100);
		return $this;
	}
	public function NewDay($today=1)
	{
		$this->clearStockCache();
		$this->slicelength += $today;
// 		print_r($this->ordres);
// 		print $this->slicelength;
		if(!empty($this->portefeuille))
			print_r($this->portefeuille);
		$this
			->checkOrders()
			->reloadPortfolio();
// 		print $this->cash;
		return $this;
	}
	private function reloadPortfolio()
	{
		foreach($this->portefeuille as $isin => $d)
		{
			$s = $this->getStock($isin)->getLast();
			$this->portefeuille[$isin]['DernierCours'] = $s;
			$this->portefeuille[$isin]['PlusvaluePCT'] = round(($s-$this->portefeuille[$isin]['c_achat'])/$this->portefeuille[$isin]['c_achat'], 4)*100 .'%';
			$this->portefeuille[$isin]['UnitCostPrice'] = round($this->portefeuille[$isin]['c_achat']*(100+(float)self::BROKER_FEE)/100, 3);
			$this->portefeuille[$isin]['ValueInEur'] = $s * $this->portefeuille[$isin]['qte'];
		}
		return $this;
	}
	private function checkOrders()
	{
		foreach($this->ordres as $ref => $d)
		{
			// remove expired orders
			if($d['expire'] < $this->slicelength)
			{
				$this->removeOrder($ref);
				continue;
			}
			
			//last quote
			$s = $this->getStock($d['isin'])->getLast('Close');
// 			print 'Cours actuel : '.$s.' plus bas :'.$this->getStock($d['isin'])->getLast('Low');
			
			// ordre d'achat ou de rachat
			if($d['sens'] >0) // Achat
			{
				if(isset($d['cours']))
					if($d['cours'] <= $this->getStock($d['isin'])->getLast('Low')) // Cours limité, le cours est supérieur
						continue;
					else
						$s = $d['cours'];
				if(isset($d['seuil']))
					if($d['seuil'] > $this->getStock($d['isin'])->getLast('High'))
						continue;
					else
						$s = $d['seuil'];
				//TODO Vente à decouvert, rachat
				$somme = round($d['qte']*$s*(1+(float)self::BROKER_FEE/100), 2);
// 				print 'pour une somme = '.$somme;
// 				print $this->cash - $somme;
				if($this->cash < $somme)
				{
// 					print (float)$this->cash . '<' .$somme;
					print 'Pas assez de cash.';
					$this->removeOrder($ref);
					continue;
				}
				$this->Withdraw($somme);
				
				if(!isset($this->portefeuille[$d['isin']]))
					$this->portefeuille[$d['isin']] = array('qte'=>0,'c_achat'=>$s);
				
				$this->portefeuille[$d['isin']]['sens'] = 1;
				
				$this->portefeuille[$d['isin']]['c_achat'] = 
					($this->portefeuille[$d['isin']]['c_achat']
						*$this->portefeuille[$d['isin']]['qte']
						+$s
						*$d['qte'])
					/($d['qte']+$this->portefeuille[$d['isin']]['qte']);
					
				$this->portefeuille[$d['isin']]['qte'] += $d['qte'];
				
				$this->portefeuille[$d['isin']]['stock'] = StockInd::getInstance()->searchLabel($d['isin']);
				
				$this->portefeuille[$d['isin']]['isin'] = $d['isin'];
				
				print "\n".' => Ordre Achat passé sur ['.$this->portefeuille[$d['isin']]['stock'].'] x '.$d['qte'].' pour un total de = '.$somme.'€'."\n";
				
				$this->removeOrder($ref);
			}
			else
			{ //vente
				if(isset($d['cours']))
					if($d['cours'] > $this->getStock($d['isin'])->getLast('High')) // cours limité non requis
						continue;
					else
						$s = $d['cours'];
				if(isset($d['seuil']))
					if($d['seuil'] < $this->getStock($d['isin'])->getLast('Low')) // le seuil n'est pas passé.
						continue;
					else
						$s = $d['seuil'];
				//TODO : vente à decouvert
				$somme = $d['qte'] * $s * (1-(float)self::BROKER_FEE/100);
				
				$this->Deposit($somme);
				
				$this->portefeuille[$d['isin']]['qte'] -= $d['qte'];
				
				print "\n".' => Ordre de Vente passé sur ['.$this->portefeuille[$d['isin']]['stock'].'] x '.$d['qte'].' à '.$s.'€ pour un total de = '.$somme.'€. '.(isset($d['cours']) ? 'Limite à '.$d['cours']*$d['qte'].'€' : 'Au marché') ."\n";
				
				if($this->portefeuille[$d['isin']]['qte'] <= 0)
					unset($this->portefeuille[$d['isin']]);
					
				$this->removeOrder($ref);
			}
		}
		return $this;
	}
	public function removeOrder($ref)
	{
		unset($this->ordres[$ref]);
		return $this;
	}
	public function addOrder($data)
	{
// 		print 'Receiving order ... '.print_r($data, true);
		$lim = $seuil = null;
		extract($data);
		$ref = uniqid();
		$this->ordres[$ref] = array(
			'isin' => $isin,
			'sens' => (int)$sens,
			'qte' => (int)$qte,
			'expire' => (int)$expire+$this->slicelength);
		if($lim != null)
			$this->ordres[$ref]['cours'] = (float)$lim;
		if($seuil != null)
			$this->ordres[$ref]['seuil'] = (float)$seuil;
		return $ref;
	}
	
	public function Valorisation()
	{
		foreach($this->portefeuille as $isin => $v)
		{
			$Pos = new PositionSimulator($isin, (int)$v['qte']);
			$Pos->set('QTY', $v['qte'])
				->set('SENS', $v['sens'])
				->set('PRIXREVIENT', $v['UnitCostPrice'])
				->set('LASTQUOTE', $v['DernierCours'])
// 				->set('DAYVAR', $matches[8][$k])
// 				->set('GAINEUR', $matches[10][$k])
				->set('GAINPCT', $v['PlusvaluePCT'])
				->set('CAPITAL', $v['ValueInEur']);
			yield $Pos;		
		}
	}
	
	public function Withdraw($cash)
	{
		$this->cash -= (int)$cash;
		return $this;
	}
	public function Deposit($cash)
	{
		// Sharpe Ratio calculator :
		static $i = false;
		if(!$i) // Set the initial deposit cash
		{
			$this->initialcash = (int)$cash;
			$i = true;
		}
		else
			$this->sellhistory[] = (int)$cash;
		// Concrete cash handling
		$this->cash += (int)$cash;
		return $this;
	}

	// Utils
	private $initialcash = 0, $sellhistory = array();
	public function SharpeRatio($rendementsansrisque = 0.03) // 3% du cac40
	{
		$sellhistory =  $this->sellhistory;
		if(!empty($this->portefeuille)) // Add in history of sell prices the current portfolio
			$sellhistory += array_map(function($v){return $v['ValueInEur'];}, $this->portefeuille);
		$n = count($sellhistory);
		$mean = array_sum($sellhistory) / $n;
		$carry = 0.0;
		foreach ($sellhistory as $val) {
			$d = ((double) $val) - $mean;
			$carry += $d * $d;
		};
		
		//Sharpe Ratio = RendementRisqué - RendementSansRisque / EcartTypeRisqué
		return (array_sum($sellhistory) - $rendementsansrisque*$this->initialcash)
			/ sqrt($carry / $n);
	}
	
	public function __toString()
	{
		return $this->cash.'€'."\n Portefeuille : ".print_r($this->portefeuille, true). "\n".
			'Total = '.($this->cash + array_sum(array_map(function($a){ return $a['ValueInEur'];}, $this->portefeuille))).'€';
	}
}

class Simulator implements Broker
{
	private $s = null;
	public function __construct(SimulatorAccount $sim)
	{
		$this->s = $sim;
	}
	
	public function Valorisation()
	{
		return $this->s->Valorisation();
	}
	public function Ordre(Stock $sto)
	{
		return new OrdreSimulator($sto);
	}
}

class OrdreSimulator implements Ordre
{
	private $data = array();
	public function __construct(Stock $sto)
	{
		$this->isin = $sto->ISIN();
		$this->IsinCode = $this->isin;
		$this->expire = 5; // default 5 days
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
	public function __call($n,$v)
	{
		return $this;
	}
	public function Achat($qte)
	{
		$this->sens = 1;
		$this->qte = $qte;
		return $this;
	}
	public function Vendre($qte)
	{
		$this->sens = 0;
		$this->qte = $qte;
		return $this;
	}
	public function ASeuil($seuil)
	{
		$this->seuil = $seuil;
		return $this;
	}
	public function APlage($seuil, $lim)
	{
		$this->seuil = $seuil;
		$this->lim = $lim;
		return $this;
	}
	public function ACoursLimite($lim)
	{
		$this->lim = $lim;
		return $this;
	}
	public function AuMarche()
	{
		$this->lim = null;
		$this->seuil = null;
		return $this;
	}
	public function AuDernierCours($lim)
	{
		return $this->AcoursLimite($lim);
	}
	public function Expire($date)
	{
		$this->expire = abs((int)$date);
		return $this;
	}
	
	public function Exec()
	{
		return new PendingOrderSimulator(SimulatorAccount::getInstance()->addOrder($this->data));
	}
	
	public function __destruct()
	{
		unset($this->data);
	}
}

class PositionSimulator extends OrdreSimulator implements Position
{
	use PositionScheme;
	
	private $isin, $maxqte;
	public function __construct($isin, $qte)
	{
		$this->isin = $isin;
		$this->maxqte = (int)$qte;
		$this->Qte();
		return $this;
	}
	
	public function Qte($qte = 0)
	{
		if($qte > $this->maxqte)
			throw new Exception('Wrong quantity, more than available.');
		$this->qte = (string) $qte <= 0 ? $this->maxqte : $qte;
		return $this;
	}
	
}

class PendingOrderSimulator implements PendingOrder
{
	private $ref;
	public function __construct($ref)
	{
		$this->ref = $ref;
	}
	
	public function Delete()
	{
		return SimulatorAccount::getInstance()->removeOrder($this->ref);
	}
}
