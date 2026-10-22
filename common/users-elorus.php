<?php

// ---------------------------------------------------------
// Elorus API Helper Functions
// ---------------------------------------------------------

function get_or_create_elorus_contact($apiKey, $orgId, $email, $username, $billingDetails = [])
{
	$endpoint = 'contacts';
	// 1. Search Contact (GET)
	$url = "https://api.elorus.com/v1.2/" . $endpoint . '?email=' . urlencode($email);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		"Authorization: Token " . $apiKey,
		"X-Elorus-Organization: " . $orgId,
		"Content-Type: application/json",
		"Accept: application/json"
	]);

	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	$contactId = null;

	if ($httpCode >= 200 && $httpCode < 300) {
		$search = json_decode($response, true);
		if ($search && isset($search['results']) && count($search['results']) > 0) {
			return $search['results'][0]['id'];
		}
	}

	// 2. Create Contact (POST) if not found
	$data = [
		'email' => $email,
		'is_supplier' => false,
		'is_client' => true
	];

	// Populate data based on billing details (Invoice vs Receipt)
	if (!empty($billingDetails) && !empty($billingDetails['company'])) {
		// Company / Invoice
		$data['company'] = $billingDetails['company']; // Company Name
		$data['vat_number'] = $billingDetails['afm'] ?? '';
		$data['profession'] = $billingDetails['activity'] ?? '';
		// Tax Office is automatically retrieved by Elorus/AADE via VAT Number

		// Addresses
		if (!empty($billingDetails['address'])) {
			$data['addresses'] = [
				[
					'address' => $billingDetails['address'],
					'city' => $billingDetails['city'] ?? '',
					'zip' => $billingDetails['zip'] ?? '',
					'country' => 'GR' // Defaulting to GR for now, or fetch from billing
				]
			];
		}
	} else {
		// Simple Person / Receipt
		$data['first_name'] = $username;
	}

	$url = "https://api.elorus.com/v1.2/" . $endpoint;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		"Authorization: Token " . $apiKey,
		"X-Elorus-Organization: " . $orgId,
		"Content-Type: application/json",
		"Accept: application/json"
	]);

	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($httpCode >= 200 && $httpCode < 300) {
		$create = json_decode($response, true);
		if ($create && isset($create['id'])) {
			return $create['id'];
		}
	}

	return false;
}

function create_elorus_invoice($apiKey, $orgId, $docTypeId, $contactId, $description, $netPrice, $vatRate, $vatAmount, $totalPrice, $currency = 'EUR')
{
	// Prepare Lines
	$line = [
		'product_type' => 'service',
		'description' => $description,
		'quantity' => 1,
		'unit_value' => (float) $netPrice,
		'vat_rate' => (float) $vatRate,
		'vat_amount' => (float) $vatAmount,
		'total_value' => (float) $totalPrice
	];

	$data = [
		'customer' => $contactId,
		'document_type' => $docTypeId,
		'currency_code' => $currency,
		'date' => date('Y-m-d'),
		'draft' => false,
		'lines' => [$line]
	];

	// POST Invoice
	$url = "https://api.elorus.com/v1.2/invoices";
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		"Authorization: Token " . $apiKey,
		"X-Elorus-Organization: " . $orgId,
		"Content-Type: application/json",
		"Accept: application/json"
	]);

	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($httpCode >= 200 && $httpCode < 300) {
		$res = json_decode($response, true);
		if ($res && isset($res['id'])) {
			return $res['id'];
		}
	}

	return false;
}

function check_and_issue_pending_elorus_invoices()
{
	// Use file-locked database functions to prevent race conditions
	$db = loadJsonDb();

	// If users key missing or not an array, nothing to do
	if (!isset($db['users']) || !is_array($db['users'])) {
		return;
	}

	// Collect usernames from the DB keys
	$usernames = array_keys($db['users']);
	$updated = false;
	foreach ($usernames as $username) {
		// Skip non-string keys
		if (!is_string($username) || $username === '') {
			continue;
		}

		// Check if user has a pending invoice
		$userData = $db['users'][$username];

		if (isset($userData['billing']) && is_array($userData['billing'])) {
			foreach ($userData['billing'] as $timestamp => $billingInfo) {
				// Check if this record is pending invoice
				if (isset($billingInfo['pending-invoice']) && $billingInfo['pending-invoice'] === 'true') {

					// --- START ELORUS PROCESS ---
					$apiKey = $_ENV['ELORUS_API_KEY'] ?? getenv('ELORUS_API_KEY');
					$orgId = $_ENV['ELORUS_ORG_ID'] ?? getenv('ELORUS_ORG_ID');

					// Determine Document Type (Invoice vs Receipt)
					$invoiceRequested = call_user_data($username, 'billing', false, 'invoice-requested');
					$docTypeId = $_ENV['ELORUS_RECEIPT_TYPE_ID'] ?? getenv('ELORUS_RECEIPT_TYPE_ID'); // Default Receipt

					$billingDetails = [];

					if ($invoiceRequested === 'yes') {
						$invoiceTypeId = $_ENV['ELORUS_INVOICE_TYPE_ID'] ?? getenv('ELORUS_INVOICE_TYPE_ID');
						if ($invoiceTypeId) {
							$docTypeId = $invoiceTypeId;
						}

						// Collect Billing Details for Invoice
						$billingDetails = [
							'company' => call_user_data($username, 'billing', false, 'company'),
							'afm' => call_user_data($username, 'billing', false, 'afm'),
							'activity' => call_user_data($username, 'billing', false, 'activity'),
							'address' => call_user_data($username, 'billing', false, 'address'),
							'city' => call_user_data($username, 'billing', false, 'city'),
							'zip' => call_user_data($username, 'billing', false, 'zip')
						];
					}

					if (!$apiKey || !$orgId) {
						// Log error: Elorus not configured
						break;
					}

					// 1. Get/Create Contact
					$email = call_user_email($username);
					if (empty($email)) {
						$email = call_user_data($username, 'info', false, 'email-for-review');
					}

					$contactId = get_or_create_elorus_contact($apiKey, $orgId, $email, $username, $billingDetails);

					if (!$contactId) {
						// Failed to get contact
						continue;
					}

					// 2. Prepare Invoice Data
					$netPrice = $billingInfo['pending-invoice-net-price'] ?? 0;
					$vatAmount = $billingInfo['pending-invoice-vat-amount'] ?? 0;
					$vatRate = $billingInfo['pending-invoice-vat-rate'] ?? 24;
					$totalPrice = $billingInfo['pending-invoice-total-price'] ?? 0;
					$totalPrice = $billingInfo['pending-invoice-total-price'] ?? 0;
					$currency = $billingInfo['pending-invoice-currency'] ?? 'EUR';

					// Get Plan Name (Key)
					$planKey = call_user_data($username, 'info', false, 'plan-name') ?? 'Subscription';
					$readablePlanName = $planKey;

					// Lookup real name from products.json (Name can be fresh, but Currency must be historic)
					$productsFile = $_SERVER['DOCUMENT_ROOT'] . '/products.json';
					if (file_exists($productsFile)) {
						$pData = json_decode(file_get_contents($productsFile), true);
						if (isset($pData['products'][$planKey])) {
							if (isset($pData['products'][$planKey]['name'])) {
								$readablePlanName = $pData['products'][$planKey]['name'];
							}
						}
					}

					// 3. Create Invoice
					$invoiceId = create_elorus_invoice($apiKey, $orgId, $docTypeId, $contactId, $readablePlanName, $netPrice, $vatRate, $vatAmount, $totalPrice, $currency);

					if ($invoiceId) {
						// 4. Update DB (Clean up pending flags and save invoice info)
						$billingRef = &$db['users'][$username]['billing'][$timestamp];

						unset($billingRef['pending-invoice']);
						unset($billingRef['pending-invoice-total-price']);
						unset($billingRef['pending-invoice-net-price']);
						unset($billingRef['pending-invoice-vat-amount']);
						unset($billingRef['pending-invoice-vat-rate']);
						unset($billingRef['pending-invoice-currency']);

						$billingRef['invoice-number'] = $invoiceId; // Store Elorus ID for reference
						$billingRef['invoice-date'] = date('Y-m-d H:i:s');

						$updated = true;
					}
					// --- END ELORUS PROCESS ---
				}
			}
		}
	}

	// Save the updated database back to storage ONLY if changed
	if ($updated) {
		saveJsonDb($db);
	}
}

// Here clean all anonymous/invalid users once every 24 hours
if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/users-elorus.txt') || (time() - (int) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/users-elorus.txt')) >= 86400) {
	file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/users-elorus.txt', time());

	if (function_exists('check_and_issue_pending_elorus_invoices')) {
		check_and_issue_pending_elorus_invoices();
	}
}