<?php
/**
 * Plugin Datei für Shopware um die Bestellungen an Dreamrobot zu übertragen
 * @author MD
 * @version 1.2.2012
 * @copyright 2012 by CDN-GmbH
 */
class Shopware_Plugins_Backend_dr_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
	/**
	 * function die das plugin installiert und Shopware mitteilt, wann und wo unsere Funktion ansetzen soll.
	 * @version 1.2.2012
	 * @author MD
	 * @param none
	 * @return none
	 */
	public function install() {
		$event = $this->createHook(
			'sOrder',								// Objekt welches verändert werden soll
			'sSaveOrder',							// Die Funktion die verändert werden soll
			'onOrder',								// Name der Funktion die aufgerufen werden soll
			Enlight_Hook_HookHandler::TypeAfter,	// Wie soll die Funktion integriert werden
			10										// An welcher Position soll die funktion erweitert werden?
		);

		$this->subscribeHook($event);

		$this->createCronJob("DreamRobot", "DreamRobot", 10, true);
		$this->subscribeEvent('Shopware_CronJob_DreamRobot', 'onRunCronJob');

	    $sql= "
			CREATE TABLE IF NOT EXISTS `dreamrobot_process_log` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`orderId` text COLLATE utf8_unicode_ci NOT NULL,
				`date` text COLLATE utf8_unicode_ci NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;
	    ";
		Shopware()->Db()->query($sql);

		return true;
	}

	/**
	 * function die nach der Shopware funktion zum Speichern der Bestellungen aufgerufen wird
	 * @version 1.2.2012
	 * @author MD
	 * @param Enlight_Event_EventArgs $args
	 * @return none
	 */
	public static function onOrder(Enlight_Event_EventArgs $args) {
		// Bestellnummer
		$ordernumber = $args->getReturn();

		$export	= Shopware()->Api()->Export();
		$shop_settings = $export->sSettings();

		// id der Bestellung in der DB benötigt um mit Export an die daten zu kommen
		$orderId = Shopware()->Db()->fetchOne("SELECT id FROM s_order WHERE ordernumber=?", array($ordernumber));

		// Laden der Daten aus der DB
		$order_data	= current($export->sGetOrders(array('orderID' => $orderId)));

		$shopware_payment = $shop_settings['payment_means'][$order_data['paymentID']]['name'];

		$orderModel = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->findOneBy(array('id' => $orderId));

		if ($shopware_payment === 'Amazoncba'
			|| $shopware_payment === 'paypal'
			|| trim($orderModel->getInternalComment()) == 'CouchCommerce') {
			// Amazon need some time to clear and is processed later through the cronjob
			return;
		}

		self::sendOrder($order_data);
	}

	/**
	 * check if any recent orders with the amazon gateway were finalized
	 * @static
	 * @param Shopware_Components_Cron_CronJob $job
	 * @return void
	 */
	public static function onRunCronJob(Shopware_Components_Cron_CronJob $job) {
		$export	= Shopware()->Api()->Export();
		$orders	= Shopware()->Db()->fetchAll("SELECT id FROM s_order ORDER BY id DESC LIMIT 100");
		$shop_settings = $export->sSettings();

		echo "Sending finalized orders to dreamrobot\n";
		foreach ($orders as $order) {
			$order_data	= current($export->sGetOrders(array('orderID' => $order['id'])));
			$shopware_payment = $shop_settings['payment_means'][$order_data['paymentID']]['name'];
			$customer_data = $export->sOrderCustomers(array('orderID' => $order['id']));
			$orderModel = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->findOneBy(array('id' => $order['id']));

			if (self::isOrderAlreadyProcessed($order['id'])) {
				continue;
			}

			if (trim($orderModel->getInternalComment()) == 'CouchCommerce') {
				echo 'Sent CouchCommerce Order: ' . $order_data['ordernumber'] . chr(10);
				self::sendOrder($order_data);
				self::logOrderProcessed($order['id']);
			} else if ($shopware_payment === 'Amazoncba' || $shopware_payment === 'paypal') {
				if ($order_data['clearedID'] == 12) {
					echo 'Sent Order: ' . $order_data['ordernumber'] . chr(10);
					self::sendOrder($order_data);
					self::logOrderProcessed($order['id']);
				} else {
					echo 'Not cleared yet: ' . $order_data['ordernumber'] . chr(10);
				}
			}
		}
	}

	public static function sendOrder($order_data, $debug = FALSE) {
		include(dirname(__FILE__) . '/schnittstelle.inc.php');

		if(!empty($dr_username) && !empty($dr_password)) {
			// object um die Daten von Shopware zu laden
			$export = Shopware()->Api()->Export();

			$orderId = $order_data['orderID'];

			// Bestellte Artikel
			$order_positions = $export->sOrderDetails(array('orderID' => $order_data['orderID']));
			$count_article_positions = count($order_positions);
			// Kundendaten
			$customer_data = $export->sOrderCustomers(array('orderID' => $order_data['orderID']));
			// Shop Settings für die Namen der Zahlarten
			$shop_settings = $export->sSettings();

			// Zahlarten Zuweisung von Shopware zu Dreamrobot bei den Zahlarten wo die Bezeichnungen von Dreamrobot abweichen
			$dr_payments = array(
				'billsafe_invoice' => 'billsafe',
				'moneybookers' => 'Moneybookers',
				'prepayment' => 'Vorkasse',
				'paypalexpress' => 'PayPal',
				'paypal' => 'PayPal',
				'Amazoncba' => 'cba_amazon',
				'cash' => 'COD'
			);
			// Setzen der Zahlart über paymentID da payment_description auch einen anderen namen enthalten kann
			$shopware_payment = $shop_settings['payment_means'][$order_data['paymentID']]['name'];
			// versuch eine übersetzung f�r die shopware Zahlart zu holen
			$payment = $dr_payments[$shopware_payment];

			// Wenn $payment leer ist, ist der Name der Zahlart von Shopware mit Dreamrobot identisch oder die Zahlart wird nicht unterst�zt.
			if (empty($payment)) {
				$payment = $shopware_payment;
			}

			// Daten von Shopware in ein passendes Array für Dreamrobot schreiben
			$sendData						= array();
			$sendData['User']				= $dr_username;
			$sendData['Pass']				= $dr_password;
			$sendData['PosAnz']				= $count_article_positions;
			$sendData['Lieferanschrift']	= 1;
			$sendData['OrderId']			= $orderId;
			$sendData['Zahlart']			= $payment;

			$sendData['Versandart']			= $order_data['dispatch_description'];
			$sendData['Versandkosten']		= $order_data['invoice_shipping'];
			$sendData['KKommentar']			= utf8_decode($order_data['customercomment']) . "\n\n" . "Bestellnummer:" . $order_data['ordernumber'];

			$sendData['Kbenutzername']		= $customer_data[$orderId]['email'];
			$sendData['KFirma']				= utf8_decode($customer_data[$orderId]['billing_company']);
			$sendData['KVorname']			= utf8_decode($customer_data[$orderId]['billing_firstname']);
			$sendData['KNachname']			= utf8_decode($customer_data[$orderId]['billing_lastname']);
			$sendData['KStrasse']			= utf8_decode($customer_data[$orderId]['billing_street']) . ' ' . $customer_data[$orderId]['billing_streetnumber'];
			$sendData['KPLZ']				= $customer_data[$orderId]['billing_zipcode'];
			$sendData['KOrt']				= utf8_decode($customer_data[$orderId]['billing_city']);
			$sendData['KTelefon']			= $customer_data[$orderId]['billing_phone'];
			$sendData['Kemail']				= $customer_data[$orderId]['email'];
			$sendData['KLand']				= utf8_decode($customer_data[$orderId]['billing_countryiso']);
			$sendData['KLFirma']			= utf8_decode($customer_data[$orderId]['shipping_company']);
			$sendData['KLVorname']			= utf8_decode($customer_data[$orderId]['shipping_firstname']);
			$sendData['KLNachname']			= utf8_decode($customer_data[$orderId]['shipping_lastname']);
			$sendData['KLStrasse']			= utf8_decode($customer_data[$orderId]['shipping_street']) . ' ' . $customer_data[$orderId]['shipping_streetnumber'];
			$sendData['KLPLZ']				= $customer_data[$orderId]['shipping_zipcode'];
			$sendData['KLOrt']				= utf8_decode($customer_data[$orderId]['shipping_city']);
			$sendData['KLLand']				= utf8_decode($customer_data[$orderId]['shipping_countryiso']);
			$sendData['Ustid']				= $customer_data[$orderId]['ustid'];

			// Hier werden die einzelnen Artikel hinzugef�gt
			$counter = 1;

			foreach($order_positions as $id => $position_data) {
				//Setzen der MwSt auf 0 wenn das MwSt Feld leer ist passiert bei Steuerfreien Gutscheinen und den Prämien
				if(empty($position_data['tax'])) {
					$position_data['tax'] = 0;
				}

				if ($position_data['articleordernumber'] === 'sw-discount') {
					$position_data['tax'] = 19;
				}

				if ($position_data['articleordernumber'] === 'sw-payment') {
					$position_data['tax'] = 19;
				}


				$sendData['Artikelnr_' . $counter]		= $position_data['articleordernumber'];
				$sendData['ArtikelEpreis_' . $counter]	= $position_data['price'];
				$sendData['ArtikelMwSt_' . $counter]	= $position_data['tax'];
				$sendData['Artikelname_' . $counter]	= utf8_decode($position_data['name']);
				$sendData['ZNummer_' . $counter]		= $position_data['articleID'];
				$sendData['ArtikelMenge_' . $counter]	= $position_data['quantity'];

				$counter++;
			}

			if ($payment == 'billsafe') {
				// Fetch the PaymentInstructions
				$client = Shopware()->BillsafeClient();
				$responseInstruction = $client->getPaymentInstruction(array('transactionId' => $order_data['transactionID']));

				$sendData['bs_recipient'] = $responseInstruction->instruction->recipient;
				$sendData['bs_bankCode'] = $responseInstruction->instruction->bankCode;
				$sendData['bs_accountNumber'] = $responseInstruction->instruction->accountNumber;
				$sendData['bs_bankName'] = $responseInstruction->instruction->bankName;
				$sendData['bs_bic'] = $responseInstruction->instruction->bic;
				$sendData['bs_iban'] = $responseInstruction->instruction->iban;
				$sendData['bs_reference'] = $responseInstruction->instruction->reference;
				$sendData['bs_amount'] = $responseInstruction->instruction->amount;
				$sendData['bs_currencyCode'] = $responseInstruction->instruction->currencyCode;
				$sendData['bs_note'] = $responseInstruction->instruction->note;
				$sendData['bs_transaction_id'] = $order_data['transactionID'];
				$sendData['bs_order_id'] = $order_data['orderID'];

				if ($order_data['transactionID'] != "") {
					$sendData['set_paid'] = 1;
				}
			}

			if ($payment == 'cba_amazon' || $payment === 'PayPal') {
				$sendData['set_paid'] = 1;
			}

			if ($debug === TRUE) {
				var_dump($sendData);
				return;
			}

			// Dreamrobot Adresse
			//Unter Umst�nden kann es zu Problemen mit dem https-Link kommen. Wenn dies der Fall ist, m�ssen Sie den auskommentierten Link nutzen.
			//Sie sollten danach den https-Link auskommentieren
			$drUrl = "http://www.dreamrobot.de/schnittstelle_automatic.php";
			//$drUrl = "https://www.dreamrobot.de/schnittstelle_automatic.php";

			// Daten per Curl verschicken
			$curl = curl_init();

			curl_setopt($curl, CURLOPT_URL, $drUrl);
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $sendData);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

			$dr_result = curl_exec($curl);

			curl_close($curl);
		}
	}

	public static function logOrderProcessed($orderId) {
		Shopware()->Db()->query('INSERT INTO `dreamrobot_process_log` (`orderId`, `date`) VALUES ("' . $orderId . '", "' . date("Y-m-d H:i:s") . '");');
	}

	public static function isOrderAlreadyProcessed($orderId) {
		$order = Shopware()->Db()->fetchOne("SELECT * FROM dreamrobot_process_log WHERE orderId = " . $orderId);
		return $order !== FALSE;
	}

	public static function clearProcessedOrders() {
		Shopware()->Db()->query('DELETE FROM `dreamrobot_process_log` WHERE 1=1;');
	}

	/**
	 * Load plugin meta information
	 * @return
	 */
	public function getInfo()
	{
		return include(dirname(__FILE__).'/Meta.php');
	}
}
?>