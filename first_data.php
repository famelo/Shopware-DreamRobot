<?php
/*
 * @author "FO"
 * Version 2012
 */
if (file_exists('./schnittstelle.inc.php'))
    die();

if(trim($_POST["username"]) != "" || trim($_POST["password"]) != "")
{
    $user	= mysql_escape_string(trim($_POST["username"]));
    $password	= mysql_escape_string(trim($_POST["password"]));
    create_php_file($user,$password);
    echo '</div><!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
	<html>
	    <head>
		<meta http-equiv="content-type" content="application/xhtml+xml;charset=utf-8" />
		<title>erweiterte Shopschnittstelle Shopware - Admin</title>

		<link href="./styles.css" rel="stylesheet" type="text/css">
	    </head>
	    <body>
		<center><h3><br/><br/>Daten wurden gespeichert</h3></center></body></html>';
    die();
}    
     
function create_php_file($user, $password)
{
    $phpText = '<?php
        // Bitte hier die Schnittstellendaten für Shopware eintragen (nicht die DreamRobot Zugangsdaten)
	$dr_username = "'.$user.'";
	$dr_password = "'.$password.'";
?>';
    $filename = "./schnittstelle.inc.php";
    $string = $phpText;
    $fd = fopen($filename, "w");
    fwrite($fd, $string);
    fclose($fd);
}
?>
</div><!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
    <head>
	<title>erweiterte Shopschnittstelle Shopware - Admin</title>
	<meta http-equiv="content-type" content="application/xhtml+xml;charset=utf-8" />
	<link href="./styles.css" rel="stylesheet" type="text/css">
    </head>
    <body>
	<table width="100%" cellpadding="0" cellspacing="0">
	    <tr>
		<td height="200">&nbsp;</td>
	    </tr>

	    <tr>
		<td valign="middle">
		    <table id="change-log" width="100%" cellspacing="0" cellpadding="0">
			<tr>
			    <td class="top-left"></td>
			    <td class="top-center"></td>
			    <td class="top-right"></td>
			</tr>
			<tr>

			    <td rowspan="2" class="border-left"></td>
			    <td>
				<h1>Schnittstellendaten</h1>
			    </td>
			    <td rowspan="2" class="border-right"></td>
			</tr>

			<tr>
			    <td class="middle"><div id="login" class="text-center">

				    <form action="first_data.php" method="post" >
					<h3>Bitte tragen Sie hier die Daten für die Schnittstelle ein <br/>(Admin->Portalaccount->Portal->weiterte Schnittstellen) </h3>
					<br>
					<ul>
					    <li>Username<br></li>
					    <li><input type="text" id="username" name="username"  /></li>
					    <li>Passwort:</li>

					    <li><input type="password" id="password" name="password" /></li>
					    <li>&nbsp;<li>
					    <li><input style="margin-top:5px;" type="submit" name="setValue" value="Speichern" /></li>
					</ul>			
				    </form>
			    </td>
			</tr>
			<tr>
			    <td class="bottom-left"></td>
			    <td class="bottom-center"></td>

			    <td class="bottom-right"></td>
			</tr></table>
		</td>
	    </tr>
	    <tr>
		<td>&nbsp;</td>
	    </tr>
	</table>
    </body>
</html>