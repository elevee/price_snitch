<?php
date_default_timezone_set('America/Los_Angeles');
header('Content-type:application/json;charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/price_config.php';

// Import PHPMailer classes into the global namespace
// These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Sunra\PhpSimple\HtmlDomParser;

define('APPLICATION_NAME', 'Price Snitch');
// define('CREDENTIALS_PATH', __DIR__ . '/credentials.json');
// define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
// If modifying these scopes, delete your previously saved credentials
// at ~/.credentials/sheets.googleapis.com-php-quickstart.json
// define('SCOPES', implode(' ', array(
//   Google_Service_Sheets::SPREADSHEETS)
// ));

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient(){
    $client = new Google_Client();
    $client->setApplicationName('Price Snitch');
    $client->setScopes(Google_Service_Sheets::SPREADSHEETS);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}
// ------------------------------------------------

// parse_str(implode('&', array_slice($argv, 1)), $_GET);
// echo("GET VARS:\n");
// print_r($_GET);

// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Sheets($client);

$spreadsheetId = $SPREADSHEET_ID["production"];
$range = 'A2:H';
$response = $service->spreadsheets_values->get($spreadsheetId, $range);
$values = $response->getValues();
// print_r($values);

function addPriceToHistory($oldPrice, $newPrice){
	return $oldPrice .= trim($oldPrice) === "" ? $newPrice : (", ".$newPrice);
}

foreach ($values as $key => $value) {
	// if($value[1] === "katespade") continue;
	if(isset($value[0]) && is_string($value[0]) && isset($value[1]) && is_string($value[1])){ //if url and site present
		$seconds = rand(1000000,10000000);
		// echo(sprintf("Pausing %d seconds", $seconds/1000000));
		usleep($seconds);

		$item 				= parse($value[0], $value[1]);
		$price_history 		= isset($value[4]) ? $value[4] : null;
		$sold_out			= false;
		$previously_avail 	= isset($value[6]) ? trim($value[6]) === "Yep" : false;
		$not_avail			= (!isset($item["price"]) || !isset($item["name"]));
		$recorded_price		= isset($value[3]) && strlen($value[3]) > 0 ? $value[3] : null;
		$price_beat 		= $recorded_price && isset($item["price"]) && floatval($recorded_price) > floatval($item["price"]);
		$price_changed 		= $recorded_price && isset($item["price"]) && floatval($recorded_price) !== floatval($item["price"]);
		$notes				= null;
		$email_nec			= false;
		$email_reason   	= null;
		$r 					= $key+2; //true row number (accounting for header row)
		$writeRange 		= "Products!C".$r.":I".$r;


		if($not_avail && $previously_avail){ //if this is the first time this item is not available
			echo("Product not available anymore!\n");
			$email_nec = true;
			$email_reason = "not_avail";
			$item["name"] = $value[2]; //fill out name manually if info is on the spreadsheet
		} else if ($price_beat) {
			$email_nec = true; //email a sista
			echo("aw snap, price beat!\n");
			$email_reason = "price_drop";
			$price_history = addPriceToHistory($price_history, $recorded_price);
		}

		if(!$price_beat && $price_changed){ //if the price wasn't beat, but it did in fact change
			$price_history = addPriceToHistory($price_history, $recorded_price);
		}

		if($email_nec){
			$email_fetch = $service->spreadsheets_values->get($spreadsheetId, "Config!B1");
			$e = $email_fetch->getValues();
			sendEmail($item, $e[0][0], $email_reason);
		}
		//if last checked price is greater than current price, send email and update price

		//Updating the spreadsheet
		$vals = null;
		$vals = [[
			isset($value[2]) && strlen($value[2]) > 0 ? Google_Model::NULL_VALUE : $item["name"], //if no name, populate
			isset($item["price"]) ? $item["price"] : "N/A",
			isset($price_history) ? $price_history : "",
			$sold_out ? "Yep" : "Nope",
			$not_avail ? "Nope" : "Yep",
			date('Y-m-d H:i:s'),
			isset($notes) ? $notes : Google_Model::NULL_VALUE
		]];
		$body = new Google_Service_Sheets_ValueRange([
		  'values' => $vals
		]);
		$params = [
		  'valueInputOption' => "USER_ENTERED"
		];
		try {
			$result = $service->spreadsheets_values->update($spreadsheetId, $writeRange, $body, $params);	
		} catch (Exception $e) {
			echo 'Values not updated. Error: ', $e;
		}
		unset($item, $price_history, $sold_out, $not_avail, $price_beat, $email_nec, $email_reason, $r, $writeRange);
	}
}

function parse($url, $site){
	$item = array();
	
	$opts = array(
	  'http'=>array(
	    'header'=>"User-Agent:Mozilla/5.0 (iPhone; CPU iPhone OS 7_0 like Mac OS X; en-us) AppleWebKit/537.51.1 (KHTML, like Gecko) Version/7.0 Mobile/11A465 Safari/9537.53\r\n"
	  )
	);
	 
	$context = stream_context_create($opts);
	$dom = HtmlDomParser::file_get_html($url, false, $context);
	$item["url"]   = $url;

	switch($site){
		case "katespade":
			foreach ($dom->find("#product-content") as $product) {
				// print_r($product);
				$item["name"]  = trim($product->find(".product-name", 0)->plaintext);
				$item["price"] = str_replace("$", "", trim($product->find(".price-sales", 0)->plaintext));
			}
			print_r($item);
			break;
		case "coach":
			// foreach ($dom->find("#product-content") as $product) {
				// print_r($product);
			$item["name"]  = trim($dom->find(".product-name-desc", 0)->plaintext);
			$item["price"] = str_replace("$", "", trim($dom->find(".price", 0)->plaintext));
			// }
			print_r($item);
			break;
	}
	return $item;
}

function sendEmail($item, $sendTo, $type){
	global $EMAIL_USER, $EMAIL_PW, $EMAIL_SMTP;
	// if(!isset($item["name"]) || !is_string($item["name"]) || strlen($item["price"]) <= 0){
	// 	echo("No item name or price. Not sending email.");
	// 	return false;
	// }
	if(!isset($sendTo) || strlen($sendTo) <= 0){ //reasons not to send email
		echo("No email provided. Can't send!");
		return false;
	}

	$subject = "";
	$body = "";
	//determine if an email should be sent
	switch($type){
		case "price_drop":
			$subject .= 'Price Alert - $'.$item["price"]." on ".$item["name"];
			$body .=  "There's been a price drop on one of the products you're monitoring:<br/><br/>";
		    $body .= sprintf("<table><tr><td><a href='%s'>%s</a></td><td>$%s</td></tr></table>", $item["url"], $item["name"], $item["price"]);
	    	$body .= "<br/>Go buy it. Or don't. :P <br/>";
		    $body .= "Love,";
		    $body .= "<br/><br/>Price Snitch";
			break;
		case "not_avail":
			$subject .= $item["name"]." No Longer Available!";
			$body .=  "It appears that a product you're tracking is no longer available:<br/><br/>";
		    $body .= sprintf("<table><tr><td><a href='%s'>%s</a></td></tr></table>", $item["url"], $item["name"]);
	    	$body .= "<br/>To stop tracking, simply delete the spreadsheet row.<br/>";
		    $body .= "Love,";
		    $body .= "<br/><br/>Price Snitch";
			break;
	}

	// echo("This is the point where an email would be sent\n");
	// echo("Subject: ". $subject);
	// echo("Body: ". $body);
	try {
	    $mail = new PHPMailer(true);                              // Passing `true` enables exceptions
	    //Server settings
	    // $mail->SMTPDebug = 2;                                 // Enable verbose debug output
	    $mail->isSMTP();                                      // Set mailer to use SMTP
	    $mail->Host = $EMAIL_SMTP;                            // Specify main and backup SMTP servers
	    $mail->SMTPAuth = true;                               // Enable SMTP authentication
	    $mail->Username = $EMAIL_USER;                        // SMTP username
	    $mail->Password = $EMAIL_PW;                           // SMTP password
	    $mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
	    $mail->Port = 587;                                    // TCP port to connect to

	    //Recipients
	    $mail->setFrom($EMAIL_USER, 'Levine HQ');
	    // $mail->addAddress('joe@example.net', 'Joe User');     // Add a recipient
	    
	    $mail->addAddress($sendTo /*, name*/);               // Name is optional
	    $mail->addReplyTo('noreply@pricesnitch.com', 'No Reply');
		// $mail->addCC('cc@example.com');

	    //Attachments
	    // $mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
	    // $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name

	    //Content
	    $mail->isHTML(true);                                  // Set email format to HTML
	    $mail->Subject = $subject;
	    
	    $mail->Body = $body;
	    // $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

	    $mail->send();
	    unset($subject, $body);
	    echo 'Message has been sent';
	} catch (Exception $e) {
	    echo 'Message could not be sent. Mailer Error: ', $mail->ErrorInfo;
	}
}


?>