#!/usr/bin/php
<?php

require('class.CM.php');
require('class.Stock.php');
require('class.TradingBot.php');
require('class.StockInd.php');

$somme_de_depart = $argc > 1 ? $argv[1] : '30000€'; 
$days = $argc > 2 ? $argv[2] : 260; // 1an, 260 jours
$list = $argc > 3 ? array_slice($argv, 3) : Stock::$YahooSBF120; //SBF120.

print "Watchlist : ".wordwrap(implode(' ', $list))."\n";
print "Somme de départ : $somme_de_depart\n";
print "Boucle sur $days jours.\n";
print "Départ...";

try{
	$SimAccount = SimulatorAccount::getInstance()->Deposit($somme_de_depart)->Start($days);
	
	// Starting Loop.
	for($i = 1; $i<= $days; $i++)
	{
		print "\n--------------------------------------------------\nJour #$i";
		$T = new TradingBot(
			new Simulator($SimAccount->NewDay())
			);

		$todestroy = array();
		foreach($list as $act)
		{
			$actt = new Stock($act, 'd', Stock::PROVIDER_CACHE);
			$actt->Slice(($days+100)*-1,100+$i);
// 			print $actt->getLast();
			$T->Watchlist($actt);
			$todestroy[] = $actt;
		}
		
		$T	
// 			->GlobalParams('BeneficeMinimal', '7.5%')
// 			->GlobalParams('SeuilPolicy', '2.5%')
			// Add other specific params here...
			// ->IsinParams('Michelin', 'BeneficeMinimal', '8%')
			;
			
		// Execute
		$T ->DailyCheckup(TradingBot::DAILYCHECKUP_BOTH); // Place les seuils automatiquement.
		unset($T);
		unset($todestroy);
	}
	print("\n".$SimAccount);
	print("\nRadio de Sharpe à 3%: ".$SimAccount->SharpeRatio(0.03));
}catch(Exception $e)
{
	fwrite(STDERR, $e->getMessage());
}
exit();