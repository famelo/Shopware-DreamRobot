<?php
/**
 * File to Update different data in an shopware shop
 * @author MD
 * @version 1.2.2012
 * @copyright 2012 by CDN-GmbH
 */

$Logs_einschalten = false;

define("PREIS_1", 1);
define("MENGE_2", 2);
define("VERSAND_3", 3);
define("KAT_TAX_4", 4);
define("LISTUNG_6", 6);

include('./schnittstelle.inc.php');
$importDaten = $_POST;

if(!empty($_GET) && empty($_POST))
{
	$importDaten = $_GET;
}

if(!array_key_exists("products_id", $importDaten))
{
	//DR sendet bei diesem Call immer amp; mit die müssen entfernt werden
	foreach ($importDaten as $key => $value)
	{
		$key					= str_replace('amp;', "", $key);
		$importDaten_temp[$key]	= $value;
	}
	
	$importDaten = $importDaten_temp;
	
	unset($importDaten_temp);
}

if($Logs_einschalten === true)
{
	write_log("gesendete Daten:".var_export($importDaten, true));
}


if(array_key_exists("Warennummer", $importDaten))
{
	if(array_key_exists("price", $importDaten))
	{
		$format = PREIS_1;
	}
	else
	{
		$format = MENGE_2;
	}

	$importDaten['dr_username_n'] = $importDaten["User"];
	$importDaten['dr_password_n'] = $importDaten["Pass"];
}
else if(array_key_exists("shippingStatus", $importDaten))
{
	$format = VERSAND_3;
	
	$importDaten['dr_username_n'] = $importDaten["User"];
	$importDaten['dr_password_n'] = $importDaten["Pass"];
}
else if ($importDaten["typ"] == "cat_einlesen")
{
	$format = KAT_TAX_4;
}
else if(array_key_exists("products_id", $importDaten))
{
	$format = LISTUNG_6;
}

if($dr_username != $importDaten['dr_username_n'] || $dr_password != $importDaten['dr_password_n'] || $dr_username == "" || $dr_password == "" || empty($importDaten))
{
	if($Logs_einschalten === true)
	{
		write_log("keine User-Daten:".var_export($importDaten, true));
	}

	exit();
}

//lade die Shopware api
try{
	require_once('../../../../../connectors/api/api.php');
}
catch(Exception $e)
{
	write_log('Shopware API nicht gefunden.');
	echo '>>Failure<<';
	die();
}

$api	= new sAPI();
$import	= &$api->import->shopware;
$export = &$api->export->shopware;
$state	= false;

switch($format)
{
	case PREIS_1:
		$state = updatePriceStock($import,$importDaten);
		
		if($state)
		{
			echo ">>Success<<";
		}
		else
		{
			echo ">>Failure<<";
		}
	break;
	case MENGE_2:
		$state = updateStock($import,$importDaten);
		
		if($state)
		{
			echo ">>Success<<";
		}
		else
		{
			echo ">>Failure<<";
		}
	break;
	case VERSAND_3:
		$state = updateDeliveryState($export,$importDaten);
	break;
	case KAT_TAX_4:
		$state = exportCategories($export);
	break;
	case LISTUNG_6:
		$state = doArticleUpdate($import,$export,$importDaten);
	break;
}

/**
 * Funktion zum schreiben der Fehler Logs
 * @param string $text 
 */
function write_log($text)
{
	$filename	= "./logfile_".date("Y-m-d")."";
	$string		= date("H:i:s")." | ".$text."\n";
	$fd			= fopen($filename.".txt", "a");
	
	fwrite($fd, $string);
	fclose($fd);
}

/**
 * Funktion zum updaten der Menge
 * @version 2.2.2012
 * @author MD
 * @param object $import
 * @param array $importDaten
 * @return boolean $state
 */
function updateStock($import, $importDaten)
{
	$state = $import->sArticleStock(array('ordernumber' => $importDaten['Artikelnummer'], 'instock' => $importDaten['Quantity']));
	
	return $state;
}

/**
 * Funktion zum Updaten des Preises und der Menge 
 * @version 2.2.2012
 * @author MD
 * @param object $import
 * @param array $importDaten
 * @return boolean $state
 */
function updatePriceStock($import, $importDaten)
{
	if(updateStock($import, $importDaten))
	{
		$state = $import->sArticlePrice(array('ordernumber' => $importDaten['Artikelnummer'], 'price' => $importDaten['price']));
		
		if($state > 0 && $state !== false)
		{
			return true;
		}
	}
	return false;
}

/**
 * Funktion zum aktualisieren des Versandstatus einer Bestellung
 * @author MD
 * @version 3.2.2012
 * @param object $export
 * @param array $importDaten
 * @return boolean $state
 */
function updateDeliveryState($export, $importDaten)
{
	$state = $export->sUpdateOrderStatus(array('orderID' => $importDaten['orderId'], 'status' => 7));
	
	return $state;
}

/**
 * Funktion zum anlegen oder aktualisieren eines Artikels
 * @author MD
 * @version 3.2.2012
 * @param object $import
 * @param object $export
 * @param array $importDaten
 * @return boolean
 */
function doArticleUpdate($import,$export,$importDaten)
{
	$shop_data		= $export->sSettings();
	$taxes			= $shop_data['tax'];
	
	// prüfen ob eine Artikel schon vorhanden ist
	$update_check	= $import->sGetArticleID($importDaten['products_ean']);
	$doPictures		= (int) $importDaten["setProductImages"];
	$article_id		= false;
	
	// array mit den artikel daten
	$article_array = array(
		'name'				=> mysql_escape_string(html_entity_decode($importDaten['products_name'])),
		'ordernumber'		=> $importDaten['products_ean'],
		'supplierID'		=> $importDaten['manufacturers_id'],
		'instock'			=> $importDaten['products_quantity'],
		'weight'			=> $importDaten['products_weight'],
		'description_long'	=> mysql_escape_string(html_entity_decode($importDaten['products_description'])),
		'description'		=> mysql_escape_string(html_entity_decode($importDaten['products_short_description'])),
		'shippingtime'		=> $importDaten['products_shippingtime'],
		'added'				=> $importDaten['products_date_added'],
		'taxID'				=> $importDaten['products_tax_class_id']
	);
	
	// wenn der Artikel vorhanden ist häng die id an für ein update
	if($update_check !== false)
	{
		$message					= 'upgedatet';
		$article_array['articleID']	= $update_check;
		$article_id					= $update_check;
	}
	else
	{
		$message					= 'Neu angelegt';
	}
	
	$sArticleResponse = $import->sArticle($article_array);
	
	if(is_array($sArticleResponse))
	{
		// wenn der Artikel noch nicht existierte setzt die Artikel id
		if($update_check == false)
		{
			$article_id = $sArticleResponse['articleID'];
		}
		
		// berechnen des Netto Preises
		$tax_rate		= ($taxes[$importDaten['products_tax_class_id']]['tax']/100)+1;
		$netto_price	= $importDaten['products_price']/ $tax_rate;
		// setzen des Preises
		$price_state	= $import->sArticlePrice(array('articleID' => $article_id, 'price' => $netto_price));
		// setzen der Kategorie
		$category_state	= $import->sArticleCategory($article_id, $importDaten['products_categorie']);
		$image_state	= false;
		
		// aktualisiere das Produktbild
		if($doPictures == 1 && $article_id !== false)
		{
			if($import->sDeleteArticleImages(array('articleID' => $article_id)))
			{
				$image_state = $import->sArticleImage(array('articleID' => $article_id, 'image' => $importDaten['products_image'], 'main' => 1));
			}
		}
		else
		{
			$image_state = true;
		}
		
		if($image_state !== false && $price_state !== false && $category_state !== false)
		{
			echo '<ItemResponse>
				<Success>true</Success>
				<Price></Price>
				<AuctionID>'.$importDaten['products_ean'].'</AuctionID>
				<Fault></Fault>
				<Message>'.$message.'</Message>
				</ItemResponse>';
			
			return true;
		}
		else
		{
			echo '<ItemResponse>
				<Success>false</Success>
				<Price></Price>
				<AuctionID>'.$importDaten['products_ean'].'</AuctionID>
				<Fault></Fault>
				<Message></Message>
				</ItemResponse>';
			
			return false;
		}
	}
	else
	{
		echo '<ItemResponse>
				<Success>false</Success>
				<Price></Price>
				<AuctionID>'.$importDaten['products_ean'].'</AuctionID>
				<Fault></Fault>
				<Message></Message>
				</ItemResponse>';
		
		return false;
	}
}

/**
 * Funktion zum Exportieren der Kategorien und so weiter
 * @author MD
 * @version 7.2.2012
 * @param object $export 
 * @return string $export
 */
function exportCategories($export)
{
	$shop_data		= $export->sSettings();
	$category_tree	= $export->sCategoryTree();
	$categories		= $export->sCategories();
	
	$xmlString = '<?xml version="1.0" encoding="iso-8859-1"?>
		<categories>';
	
	//Hier werden die Kategorien für den Export verarbeitet
	foreach ($categories as $categorie)
	{
		//anpassen der parentID nur für die Hauptkategorien muss gemacht werden damit der baum in der auftaucht
		$categorie['parentID'] = (int) $categorie['parentID'];
		
		if($categorie['parentID'] == 1)
		{
			$categorie['parentID'] = 0;
		}
		
		$xmlString .='<categorie>'.
			'<categories_id>'.$categorie['categoryID'].'</categories_id>'.
			'<parent_id>'. $categorie['parentID'].'</parent_id>'.
			'<categories_status>'.$categorie['active'].'</categories_status>'.
			'<categories_name>'. $categorie['description'] .'</categories_name>' .
			'</categorie>';
	}
	$xmlString .= '</categories>
					<tax_classes>';
	
	//Hier werden die Steuerklassen verarbeitet
	foreach($shop_data['tax'] as $id => $values)
	{
		$xmlString .= '<tax_class>' .
						'<tax_rates_id>'. $id .'</tax_rates_id>' .
						'<tax_class_id>'. $id .'</tax_class_id>' .
						'<tax_rate>'. $values['tax'] .'</tax_rate>' .
						'<tax_description>'. $values['description'] .'</tax_description>' .
						'<tax_class_description>'. $values['description'] .'</tax_class_description>' .
						'<tax_class_title>'. $values['description'] .'</tax_class_title>' .
					'</tax_class>';
	}
	
	$xmlString .= "</tax_classes>
					<manufacturers>";

	foreach($shop_data['manufacturers'] as $id => $manufacturer)
	{
		$xmlString .= '<manufacturer>' .
						'<manufacturer_id>'. $id .'</manufacturer_id>'.
						'<manufacturer_name>'. $manufacturer .'</manufacturer_name>'.
					'</manufacturer>';
	}
	
	$xmlString .= "</manufacturers>";
	
	echo $xmlString;
	
	//variable um dem sript mitzuteilen das ex ein export ist
	$export = 'export';
	return $export;
}
?>