<?php
error_reporting(E_ALL);
require('trait.common.php');
require('class.StockInd.php');
require('stock_provider/interface.StockProvider.php');
require('stock_provider/trait.CacheStock.php');
require('class.ABCBourse.php');
require('stock_provider/class.ABCBourse.php');
require('class.Stock.php');
require('stock_provider/class.YahooFinance.php');
require('class.StockData.php');
require('class.StockAnalysis.php');

// exit(date('r',1495922400));
$Sto = new StockQuote(new Stock('orange'), StockProvider::PERIOD_DAILY, StockQuote::PROVIDER_ABCBOURSE);
// count($Sto);
// print date('d/m/Y', strtotime('today -2 year'));
// print_r($Sto->Close(true));
// exit();
// print_r($Sto->Analysis()->RSI());
// $an = $Sto->Analysis()->Supertrend(10, 3);
// // print_r($an);
// print "date,close,supertrend\n";
// foreach($Sto->Close(true) as $k=>$c)
// 	print "$k,$c,".$an[$k]."\n";
// $vn = $Sto->Analysis()->VolumeOscillator();
// $vn = $Sto->Analysis()->Stochastic();
// $vn = $Sto->Analysis()->CCI();
// $vn = $Sto->Analysis()->Supertrend();
// $vn = $Sto->Analysis()->OBV();
// $vn = $Sto->Analysis()->DMIMinus();
$vn = $Sto->Analysis()->DMI('+');
// $vn = $Sto->Analysis()->ROC();


print_r(array_slice($vn, -10, null, true));
