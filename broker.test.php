<?php

require('trait.common.php');
require('class.ABCBourse.php');
require('class.StockInd.php');
require('stock_provider/interface.StockProvider.php');
require('class.Stock.php');
require('stock_provider/trait.CacheStock.php');
require('stock_provider/class.YahooFinance.php');
require('broker/interface.Broker.php');
require('broker/class.ABCBourse.php');
require('broker/class.Simulator.php');

// print (date('d/m/Y', strtotime('3 weeks Friday')));
// 
// exit();

// $ABC = new ABCBourseBroker();
// var_dump($ABC->Ordre(new Stock("nokia"))->Achat(10)->AuMarche()->Exec());
// $a = $ABC->Valorisation();
// foreach($a as $v)
// 	print_r($v);
// 	$v->AuMarche()->Exec();

// exit();


$Simulator = new Simulator($Account = SimulatorAccount::getInstance()->Deposit(10000));

$ref = $Simulator->Ordre(new Stock('valeo'))->Achat(10)->AuMarche()->Exec();
$Account->NewDay();
// $Simulator->Ordre(new Stock('valeo'))->Vendre(10)->AuMarche()->Exec();
$Account->NewDay();

foreach($Account->Valorisation() as $v)
	{
		var_dump($v);
	}
// print $Account;
// $ref->Delete();
// var_dump($Simulator);
