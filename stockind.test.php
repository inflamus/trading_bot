<?php
require('trait.common.php');
require('class.StockInd.php');
require('stock_provider/interface.StockProvider.php');
require('stock_provider/trait.CacheStock.php');
require('class.ABCBourse.php');
require('stock_provider/class.ABCBourse.php');
require('class.Stock.php');
require('stock_provider/class.YahooFinance.php');

$Sto = new StockQuote(new Stock('valeo'), StockProvider::PERIOD_DAILY, StockQuote::PROVIDER_YAHOOCACHE);
// count($Sto);
// print date('d/m/Y', strtotime('today -2 year'));
foreach($Sto as $k=> $v)
	print($k.':'.$v.' ');
