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
$range = 'A2:E';
$response = $service->spreadsheets_values->get($spreadsheetId, $range);
$values = $response->getValues();
// print_r($values);

foreach ($values as $key => $value) {
	if(isset($value[0]) && is_string($value[0]) && isset($value[1]) && is_string($value[1])){ //if url and site present
		$seconds = rand(1000000,10000000);
		// echo(sprintf("Pausing %d seconds", $seconds/1000000));
		usleep($seconds);

		$item = parse($value[0], $value[1]);

		$price_beat = isset($value[3]) && strlen($value[3]) > 0 && floatval($value[3]) > floatval($item["price"]);
		if($price_beat){ 
			//email a sista
			echo("aw snap, price beat!\n");
			$email_response = $service->spreadsheets_values->get($spreadsheetId, "Config!B1");
			$e = $email_response->getValues();
			sendEmail($item, $e[0][0]);
		}
		//if last checked price is greater than current price, send email and update price

		$r = $key+2; //true row number (accounting for header row)
		$writeRange = "Products!C".$r.":E".$r;
		// echo("What's the write range?  ". $writeRange);
		
		
		$vals = null;
		$vals = [[
			isset($value[2]) && strlen($value[2]) > 0 ? Google_Model::NULL_VALUE : $item["name"], //if no name, populate
			$item["price"],
			date('Y-m-d H:i:s')
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
	}
}

function parse($url, $site){
	$item = array();
	$dom = HtmlDomParser::file_get_html($url);
	
	switch($site){
		case "katespade":
			foreach ($dom->find("#product-content") as $product) {
				// print_r($product);
				$item["url"]   = $url;
				$item["name"]  = trim($product->find(".product-name", 0)->plaintext);
				$item["price"] = str_replace("$", "", trim($product->find(".price-sales", 0)->plaintext));
			}
			print_r($item);
			break;
	}
	return $item;
}

function sendEmail($item, $sendTo){
	global $EMAIL_USER, $EMAIL_PW, $EMAIL_SMTP;
	if(isset($item["name"]) && is_string($item["name"]) && strlen($item["price"]) > 0 && isset($sendTo) && strlen($sendTo) > 0){
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
		    $mail->Subject = 'Price Alert - $'.$item["price"]." on ".$item["name"];
		    
		    $body =  "There's been a price drop on one of the products you're monitoring:<br/><br/>";
		    $body .= sprintf("<table><tr><td><a href='%s'>%s</a></td><td>$%s</td></tr></table>", $item["url"], $item["name"], $item["price"]);
	    	$body .= "<br/>Go buy it. Or don't. :P <br/>";
		    $body .= ("Love,");
		    $body .= "<br/><br/>Price Snitch";
		    $mail->Body = $body;
		    // $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

		    $mail->send();
		    echo 'Message has been sent';
		} catch (Exception $e) {
		    echo 'Message could not be sent. Mailer Error: ', $mail->ErrorInfo;
		}
	}
}


?>