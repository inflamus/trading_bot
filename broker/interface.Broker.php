<?php

// namespace Broker;

interface Broker
{
	public function isISIN(&$i);
	public function Valorisation($getraw = false) : Position;
	public function Ordre($isin = null) : Ordre;
}

interface Position extends Ordre
{
// 	public function Gain(); // get gain
// 	public function GainPCT(); // get gain GainPCT
// 	public function Qty(); // quantité en position
// 	public function Capital(); // must return capital
// 	public function PrixRevient(); // must return le prix de revient de la position
	public function get($key); // return one of the features of the position scheme
	protected function set($key, $val); // useful function
	public function Qte($qte = 0); // set the quantity of stocks for the order
}
trait PositionScheme
{
	private $Features = array(
		'SENS' => -1, // 1 => achat, 0 => vendre
		'QTY' => 0,
		'PRIXREVIENT' => 0.0,
		'LASTQUOTE' => 0.0,
		'DAYVAR' => 0.0,
		'GAINEUR' => 0.0,
		'GAINPCT' => 0.0,
		'CAPITAL'=> 0.0,
		'SRD' => false,
	);
	
	public function get($key)
	{
		if(!isset($this->Features[$key]))	throw new Exception ('Unknown key ['.$key.'] for position.');
		return $this->Features[$key];
	}
	
	protected function set($key, $val)
	{
		if(isset($this->Features[$key]))
			$this->Features[$key] = settype($val, gettype($this->Features[$key]));
		else
			throw new Exception('Unknown key setting ['.$key.'] with val '.$val);
		return $this;
	}
}

interface Ordre
{
// 	public function Buy ($qte);
// 	public function Sell ($qte);
// 	public function Stop ($stop, $lim = null);
// 	public function Limit ($lim);
// 	public function Market	();
// 	public function Expire (DateTime $date);
	public function Achat($qte);
	public function Vendre($qte);
	public function ASeuil($seuil);
	public function APlage($seuil, $lim);
	public function ACoursLimite($lim);
	public function AuMarche();
	public function Expire($date);
	public function Exec() : PendingOrder;
}

interface PendingOrder
{
	public function Delete();
}

interface Action
{
	public function ISIn();
}

?>
