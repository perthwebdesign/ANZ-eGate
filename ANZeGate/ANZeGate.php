<?php
/**
 * ANZeGate
 *
 * @author Matt Boddy
 * @version 0.1
 * @copyright Perth Web Design, 2011
 * @package Shopp
 * @since 1.1
 * @subpackage ANZeGate
 * 
 * $Id: ANZeGate.php
 **/

require_once(SHOPP_PATH."/core/model/XML.php");

class ANZeGate extends GatewayFramework implements GatewayModule {
	
	//.. Whether SSL is required.
	var $secure = true;

	//.. Type of cards available
	var $cards = array("visa","mc","amex","dc");

	var $liveurl = "https://migs.mastercard.com.au/vpcdps";
	
	function __construct () {
		parent::__construct();
		$this->setup('customerid');
	}
	
	
	//.. return a money value in the correct number format.
	// ANZ DOCs
	// The amount of the transaction in the smallest currency unit expressed as an integer. For example, if
	// vpc_Amount the transaction amount is $49.95 then the amount in cents is 4995.
	private function _parseMoneyValue( $Number ) {
		$Number = str_replace( ".", "", number_format( $Number, 2 ) );
		return $Number; 
	}
	
	//.. function for decoding ANZ eGate response codes.
	private function _humanizeResponseCode( $Code ) {
		
		switch ( $Code ) {
	        case "0" : $result = "Transaction Successful"; break;
	        case "?" : $result = "Transaction status is unknown"; break;
	        case "1" : $result = "Unknown Error"; break;
	        case "2" : $result = "Bank Declined Transaction"; break;
	        case "3" : $result = "No Reply from Bank"; break;
	        case "4" : $result = "Expired Card"; break;
	        case "5" : $result = "Insufficient funds"; break;
	        case "6" : $result = "Error Communicating with Bank"; break;
	        case "7" : $result = "Payment Server System Error"; break;
	        case "8" : $result = "Transaction Type Not Supported"; break;
	        case "9" : $result = "Bank declined transaction (Do not contact Bank)"; break;
	        case "A" : $result = "Transaction Aborted"; break;
	        case "C" : $result = "Transaction Cancelled"; break;
	        case "D" : $result = "Deferred transaction has been received and is awaiting processing"; break;
	        case "F" : $result = "3D Secure Authentication failed"; break;
	        case "I" : $result = "Card Security Code verification failed"; break;
	        case "L" : $result = "Shopping Transaction Locked (Please try the transaction again later)"; break;
	        case "N" : $result = "Cardholder is not enrolled in Authentication scheme"; break;
	        case "P" : $result = "Transaction has been received by the Payment Adaptor and is being processed"; break;
	        case "R" : $result = "Transaction was not processed - Reached limit of retry attempts allowed"; break;
	        case "S" : $result = "Duplicate SessionID (OrderInfo)"; break;
	        case "T" : $result = "Address Verification Failed"; break;
	        case "U" : $result = "Card Security Code Failed"; break;
	        case "V" : $result = "Address Verification and Card Security Code Failed"; break;
	        default  : $result = "Unable to be determined"; 
	    }
	    
    	return $result;
	}

	function actions () {
		add_action('shopp_process_order',array(&$this,'process'));
	}
	
	function process () {
		
		$Response = $this->send($this->build());
		parse_str($Response, $ResponseArray);
		
		$HumanTransactionResponse = $this->_humanizeResponseCode( $ResponseArray['vpc_TxnResponseCode'] );
		
		if ( $HumanTransactionResponse === "Transaction Successful" ) {
			//.. Set the order Status to CHARGED on success
			$this->Order->transaction($this->session,'CHARGED');
		} else {
			//.. Set the order Status to ~ERROR
			new ShoppError($HumanTransactionResponse , $ResponseArray['vpc_TxnResponseCode'], SHOPP_TRXN_ERR);
		}
	}
	
	//.. Assembles the user submitted data into a usable ANZ eGate query string.
	function build () {
		$Order = $this->Order;
		$Customer = $Order->Customer;
		
		//.. Create an invoice description
		$InvoiceDescription = array();
		foreach($Order->Cart->contents as $Item)
			$InvoiceDescription[] = $Item->quantity.' x '.$Item->name.' '.((sizeof($Item->options) > 1)?' ('.$Item->optionlabel.')':'');
		$InvoiceDescription = implode(" ", $InvoiceDescription);
		
		//.. Build an array of transaction details, ready to send to ANZ
		$TransactionArray = array(
			//.. ANZ eGate method Details.
			'vpc_Version'			=> "1",
			'vpc_Command'			=> "pay",

			//.. Merchant Details.
			'vpc_AccessCode'		=> $this->settings['merchant_access_code'],
			'vpc_MerchTxnRef'		=> $this->session,
			'vpc_Merchant'			=> $this->settings['merchant_id'],

			//.. Order Details.
			'vpc_OrderInfo'			=> $InvoiceDescription,
			'vpc_Amount'			=> $this->_parseMoneyValue($Order->Cart->Totals->total),

			//.. Card Details.
			'vpc_CardNum'			=> $Order->Billing->card,
			'vpc_CardExp'			=> date("y",$Order->Billing->cardexpires) . date("m",$Order->Billing->cardexpires),
			'vpc_CardSecurityCode'	=> $Order->Billing->cvv
		);
		
		//.. Return a query string, required by ANZ eGate.
		return http_build_query($TransactionArray);
	}
	
	function send ( $data ) {
		// Get a HTTPS connection to VPC Gateway and do transaction
		// turn on output buffering to stop response going to browser
		ob_start();
		
		// initialise Client URL object
		$ch = curl_init();
		
		// set the URL of the VPC
		curl_setopt ($ch, CURLOPT_URL, $this->liveurl);
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);
		
		$response = curl_exec ($ch);
		
		//.. Return the response.
		return $response;
	}
	
	
	function settings () {
		//.. Added the card menu checkbox
		$this->ui->cardmenu(0,array(
			'name' => 'cards',
			'selected' => $this->settings['cards']
		),$this->cards);

		//.. Merchant ID text field
		$this->ui->text(1,array(
			'name' => 'merchant_id',
			'value' => $this->settings['merchant_id'],
			'size' => '16',
			'label' => __('Merchant ID.','Shopp')
		));
		
		//.. Secret HASH text field. (This is only needed for 3rd party)
		// $this->ui->text(1,array(
			// 'name' => 'secret_hash',
			// 'value' => $this->settings['secret_hash'],
			// 'size' => '16',
			// 'label' => __('Secret Hash (Only requred for 3rd party mode).','Shopp')
		// ));
		
		//.. Merchant Access Code text field
		$this->ui->text(1,array(
			'name' => 'merchant_access_code',
			'value' => $this->settings['merchant_access_code'],
			'size' => '16',
			'label' => __('Merchant Access Code.','Shopp')
		));
	}

} // END class ANZeGate

?>