<?php

function is_stripe_subscription_cancellation_pending($username)
{
	$stripe_subscription_id = call_user_data($username, 'billing', false, 'stripe-subscription-id');
	if ($stripe_subscription_id == null) {
		return false;
	}

	if (!class_exists('\Stripe\Stripe') || !getenv('STRIPE_SECRET_KEY')) {
		return false;
	}

	try {
		\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));
		$subscription = \Stripe\Subscription::retrieve($stripe_subscription_id);
		return $subscription && $subscription->cancel_at_period_end === true;
	} catch (\Exception $e) {
		return false;
	}
}

/**
 * Helper to determine Stripe recurring interval from days
 */
function get_stripe_recurring_interval($days_expires)
{
	if ($days_expires <= 0) {
		return null;
	}

	if ($days_expires <= 1) {
		return ['interval' => 'day'];
	} elseif ($days_expires <= 7) {
		return ['interval' => 'week'];
	} elseif ($days_expires <= 31) {
		return ['interval' => 'month'];
	} elseif ($days_expires >= 80 && $days_expires <= 100) {
		return ['interval' => 'month', 'interval_count' => 3];
	} elseif ($days_expires >= 170 && $days_expires <= 190) {
		return ['interval' => 'month', 'interval_count' => 6];
	} elseif ($days_expires >= 350) {
		return ['interval' => 'year'];
	} else {
		// Default fallback
		return ['interval' => 'month'];
	}
}

function start_stripe_subscription_by_plan_key($plan_key, $return_url)
{

	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}
	$_SESSION['pending_stripe_plan_key'] = $plan_key;

	// Load products.json to get Price and details directly
	$json_path = $_SERVER['DOCUMENT_ROOT'] . "/products.json";

	if (!file_exists($json_path)) {
		trigger_error("Products.json not found");
		return;
	}

	$products_data = json_decode(file_get_contents($json_path), true);

	if (!$products_data || !isset($products_data['products'])) {
		trigger_error("Invalid products.json format");
		return;
	}

	// Get product details directly from JSON
	if (!isset($products_data['products'][$plan_key])) {
		trigger_error("Plan not found.");
		return;
	}

	$product = $products_data['products'][$plan_key];
	$product_name = $product['name'];
	$price_amount = isset($product['price']) ? (float) $product['price'] : 0;

	if ($price_amount <= 0) {
		trigger_error("Product price is invalid.");
		return;
	}

	// Check if this is a recurring or one-time payment based on days-expires
	$days_expires = isset($product['days-expires']) ? (int) $product['days-expires'] : 0;

	// Determine checkout mode: subscription or payment (one-time)
	$checkout_mode = ($days_expires > 0) ? 'subscription' : 'payment';

	// Verify environment variables
	$secret_key = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');

	if (!$secret_key) {
		trigger_error("Stripe secret key is not configured correctly.");
		return;
	}

	if (!class_exists('\Stripe\Stripe')) {
		trigger_error("Stripe class is not loaded.");
		return;
	}

	\Stripe\Stripe::setApiKey($secret_key);

	try {
		// Get User Info for pre-filling email (optional convenience)
		$user_email = call_user_email(USERNAME);

		// Find existing stripe customer if any (for linking) but do not update address
		$stripe_customer_id = call_user_data(USERNAME, 'billing', false, 'stripe-customer-id');

		// Prepare Line Item with dynamic price_data
		$currency = isset($product['currency']) ? strtolower($product['currency']) : 'eur';
		$price_data = [
			'currency' => $currency,
			'unit_amount' => (int) ($price_amount * 100), // Convert to cents
		];

		// Check if we have an existing Product ID to link to (Prevents duplicate products)
		if (isset($product['stripe-product-id']) && !empty($product['stripe-product-id'])) {
			$price_data['product'] = $product['stripe-product-id'];
		} else {
			// No Product ID found? Create one on the fly (Fallback)
			$price_data['product_data'] = [
				'name' => $product_name,
				'metadata' => ['plan_key' => $plan_key],
			];
		}

		// Add recurring if subscription
		if ($checkout_mode === 'subscription') {
			$recurring = get_stripe_recurring_interval($days_expires);
			if ($recurring) {
				$price_data['recurring'] = $recurring;
			}
		}

		$line_item = [
			'price_data' => $price_data,
			'quantity' => 1,
		];

		// Prepare Session Data
		$session_data = [
			'line_items' => [$line_item],
			'mode' => $checkout_mode,
			'success_url' => strtok($return_url, '?') . '?thx=yes',
			'cancel_url' => $return_url,
			'client_reference_id' => USERNAME, // Pass username for webhooks
		];

		// If we know the customer ID, pass it
		if ($stripe_customer_id != null) {
			$session_data['customer'] = $stripe_customer_id;
		} else {
			// If no customer object, at least pre-fill email if available
			$session_data['customer_email'] = $user_email;
		}

		// Create Checkout Session
		/** @var \Stripe\Checkout\Session $session */
		$session = \Stripe\Checkout\Session::create($session_data);

		// === REDIRECT LOGIC ===
		// Redirect to Stripe Checkout
		header("Location: " . $session->url);
		echo '<meta http-equiv="refresh" content="0;url=' . $session->url . '">';
		exit;
	} catch (\Exception $e) {
		trigger_error($e->getMessage());
	}
}

function get_stripe_billing_url_by_the_payment_method($username)
{

	// First check if is stripe customer
	$stripe_customer_id = call_user_data($username, 'billing', false, 'stripe-customer-id');
	if ($stripe_customer_id) {
		$secret_key = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
		if ($secret_key && class_exists('\Stripe\Stripe')) {
			\Stripe\Stripe::setApiKey($secret_key);
			try {
				$session = \Stripe\BillingPortal\Session::create([
					'customer' => $stripe_customer_id,
					'return_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/web/account.php',
				]);
				return $session->url;
			} catch (\Exception $e) {
				trigger_error($e->getMessage());
			}
		} else {
			trigger_error("Stripe configuration error.");
		}
	} else {
		return false;
	}
}

/**
 * Checks if the user has a currently running (active) subscription in Stripe.
 * This performs a real-time check against the Stripe API.
 * 
 * Ξ”ΞµΞ½ ΞΌΞΏΟ… Ξ±ΟΞ­ΟƒΞµΞΉ Ο€ΞΏΞ»Ο Ξ³ΞΉΞ±Ο„ΞΉ ΟΞ¬Ο‡Ξ½ΞµΞΉ ΟƒΟ„ΞΏ Stripe Ξ³ΞΉΞ± Ξ½Ξ± Ξ²ΟΞµΞΉ Ο„Ξ·Ξ½ ΟƒΟ…Ξ½Ξ΄ΟΞΏΞΌΞ® Ο„ΞΏΟ… ΞµΞ½Ο ΞµΞ³Ο ΞΈΞ­Ξ»Ο‰ ΞΌΟΞ½ΞΏ ΟƒΟ„Ξ· Ξ²Ξ¬ΟƒΞ· Ξ³ΞΉΞ±Ο„ΞΉ ΞΌΟ€ΞΏΟΞµΞΉ Ξ½Ξ± ΞΊΞ¬Ξ½ΞΏΟ…ΞΌΞµ Ξ±Ξ»Ξ»Ξ±Ξ³Ξ­Ο‚ Ξ· ΟƒΟ…Ξ½Ξ΄ΟΞΏΞΌΞ® ΞΌΞµ Ο„ΞΏ Ο‡Ξ­ΟΞΉ.
 */
function is_stripe_subscription_running($username)
{
	// Check if Stripe is available
	if (!class_exists('\Stripe\Stripe')) {
		return false;
	}

	$stripe_key = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
	if (!$stripe_key) {
		return false;
	}

	// Get customer ID
	$customer_id = call_user_data($username, 'billing', false, 'stripe-customer-id');
	if (!$customer_id) {
		return false;
	}

	try {
		\Stripe\Stripe::setApiKey($stripe_key);

		$subscriptions = \Stripe\Subscription::all([
			'customer' => $customer_id,
			'status' => 'active',
			'limit' => 1
		]);

		return count($subscriptions->data) > 0;

	} catch (\Exception $e) {
		return false;
	}
}

/**
 * Ξ‘ΞΊΟΟΟ‰ΟƒΞ· ΟƒΟ…Ξ½Ξ΄ΟΞΏΞΌΞ®Ο‚ Ο‡ΟΞ®ΟƒΟ„Ξ· ΟƒΟ„ΞΏ Stripe (Ο„ΞΏΟ€ΞΏΞΈΞµΟ„ΞµΞ― cancel_at_period_end=true).
 */
function cancel_stripe_subscription($username)
{

	//first try stripe
	$stripe_subscription_id = call_user_data($username, 'billing', false, 'stripe-subscription-id');
	if ($stripe_subscription_id != null) {

		// Check if Stripe is available silently
		if (!class_exists('\Stripe\Stripe') || !getenv('STRIPE_SECRET_KEY')) {
			return false;
		}

		try {
			\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

			// Cancel the subscription at period end via api only
			$subscription = \Stripe\Subscription::update($stripe_subscription_id, [
				'cancel_at_period_end' => true
			]);

			// Verify that the subscription was actually set to cancel at period end
			if ($subscription && $subscription->cancel_at_period_end === true) {

				// Update user data
				write_user_data($username, 'billing', false, 'canceled-date', date('Y-m-d H:i:s'));
				return true;
			} else {
				trigger_error('Failed to confirm subscription cancellation. Please try again or contact support.');
				return false;
			}

		} catch (\Stripe\Exception\ApiErrorException $e) {
			trigger_error('Error canceling subscription: ' . $e->getMessage());
			return false;
		} catch (Exception $e) {
			trigger_error('Error: ' . $e->getMessage());
			return false;
		}
	} else {

		// add more at the feature
		return false;
	}
}

/**
 * Ξ•Ο€Ξ±Ξ½ΞµΞ½ΞµΟΞ³ΞΏΟ€ΞΏΞ―Ξ·ΟƒΞ· Ξ±ΞΊΟ…ΟΟ‰ΞΌΞ­Ξ½Ξ·Ο‚ ΟƒΟ…Ξ½Ξ΄ΟΞΏΞΌΞ®Ο‚ (Ξ±Ο†Ξ±ΞΉΟΞµΞ― cancel_at_period_end flag).
 */
function reactivate_stripe_subscription($username)
{
	$stripe_subscription_id = call_user_data($username, 'billing', false, 'stripe-subscription-id');
	if ($stripe_subscription_id != null) {

		// Check if Stripe is available silently
		if (!class_exists('\Stripe\Stripe') || !getenv('STRIPE_SECRET_KEY')) {
			return false;
		}

		try {
			\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

			// Remove the cancel_at_period_end flag
			$subscription = \Stripe\Subscription::update($stripe_subscription_id, [
				'cancel_at_period_end' => false
			]);

			// Verify that the subscription was actually reactivated before return true
			if ($subscription && $subscription->cancel_at_period_end === false) {
				// Update user data
				return true;
			} else {
				trigger_error('Failed to confirm subscription reactivation. Please try again or contact support.');
				return false;
			}
		} catch (\Stripe\Exception\ApiErrorException $e) {
			trigger_error('Error reactivating subscription: ' . $e->getMessage());
			return false;
		} catch (Exception $e) {
			trigger_error('Error: ' . $e->getMessage());
			return false;
		}
	} else {

		return false;
	}
}

/**
 * Ξ•Ξ»Ξ­Ξ³Ο‡ΞµΞΉ Ξ±Ξ½ Ξ· ΟƒΟ…Ξ½Ξ΄ΟΞΏΞΌΞ® Ο„ΞΏΟ… Ο‡ΟΞ®ΟƒΟ„Ξ· Ξ»Ξ®Ξ³ΞµΞΉ ΟƒΟΞ½Ο„ΞΏΞΌΞ± (ΞΌΞ­ΟƒΞ± ΟƒΟ„ΞΉΟ‚ ΞµΟ€ΟΞΌΞµΞ½ΞµΟ‚ 7 Ξ·ΞΌΞ­ΟΞµΟ‚).
 *
 * Ξ§ΟΞ®ΟƒΞ·:
 * - Ξ§ΟΞ®ΟƒΞΉΞΌΞΏ Ξ³ΞΉΞ± Ξ½Ξ± ΞµΞΌΟ†Ξ±Ξ½Ξ―ΟƒΞΏΟ…ΞΌΞµ Ο€ΟΞΏΞµΞΉΞ΄ΞΏΟ€ΞΏΞ―Ξ·ΟƒΞ· Ξ® Ξ½Ξ± ΟƒΟ„ΞµΞ―Ξ»ΞΏΟ…ΞΌΞµ reminder email.
 */
function is_subscription_expiring_soon($username)
{

	$expires = call_user_data($username, 'info', false, 'plan-expires');
	// $expires is a date string, e.g. "18-Jun-2025"
	$expiry_ts = strtotime($expires);
	$days_until_expiry = ($expiry_ts - time()) / (24 * 60 * 60);
	if ($days_until_expiry <= 7 && $days_until_expiry >= 0) {
		return ceil($days_until_expiry);
	}
	return false;
}

/**
 * Notify admin before issuing Elorus invoice
 */
function notify_admin_generic_invoice($username, $amount_cents = null, $currency = null, $context = null)
{
	$admin_email = call_user_data('global', 'info', false, 'incomming-maillo-stripe');
	if (!$admin_email) {
		$admin_email = call_user_data('global', 'info', false, 'incoming-mailto');
		if (!$admin_email) {
			return false;
		}
	}

	$subject = 'Generic Invoice / Payment Notification'; // Generic subject
	$amount_text = 'N/A';
	if ($amount_cents !== null && $currency) {
		$amount_text = number_format(((int) $amount_cents) / 100, 2, '.', '') . ' ' . strtoupper($currency);
	}

	$lines = [
		'An invoice/payment event has occurred.', // Generic message
		'User: ' . ($username ?: 'unknown'),
		'Amount: ' . $amount_text,
		'Source: ' . ($context ?: 'unknown'),
		'Date: ' . date('Y-m-d H:i:s'),
	];
	$message = implode("\n", $lines);

	return mail_with_php_mailler($admin_email, $subject, $message);
}

function send_customer_payment_email($username)
{
	if (!$username) {
		return false;
	}

	$to = call_user_email($username);
	if (!$to) {
		return false;
	}

	// Read email templates from users.json
	$template_title = call_user_data('global', 'info', false, 'template-payment-notification-title');
	$template_body = call_user_data('global', 'info', false, 'template-payment-notification-body');

	// Append only payment templates (English) if missing
	if (!$template_title) {
		$template_title = 'Payment Confirmation β€“ [[domain]]';
		write_user_data('global', 'info', false, 'template-payment-notification-title', $template_title);
	}

	if (!$template_body) {
		$template_body = '<h2>Payment Confirmation β€“ [[domain]]</h2><p>Your payment has been successfully processed.</p><p><strong>Summary:</strong></p><ul><li>Service: [[plan-name]]</li><li>Status: Active</li></ul><p>Thank you for your business.</p><p>If you have any questions, please contact our support team.</p>';
		write_user_data('global', 'info', false, 'template-payment-notification-body', $template_body);
	}

	$plan_key = call_user_data($username, 'info', false, 'plan-name');
	$plan_name = $plan_key ? get_product_name_by_plan_key($plan_key) : null;
	if (!$plan_name && $plan_key) {
		$plan_name = $plan_key;
	}

	$domain = $_SERVER['HTTP_HOST'] ?? 'Era-On';
	$safe_plan_name = $plan_name ?: 'Subscription / Package';

	// Replace placeholders in both title and body (explicit mapping)
	$subject = $template_title;
	$subject = str_replace('[[plan-name]]', $safe_plan_name, $subject);
	$subject = str_replace('[[domain]]', $domain, $subject);

	$body = $template_body;
	$body = str_replace('[[plan-name]]', $safe_plan_name, $body);
	$body = str_replace('[[domain]]', $domain, $body);

	return mail_with_php_mailler($to, $subject, $body);
}

// Cli run patch
if (php_sapi_name() === 'cli') {
	$_SERVER['DOCUMENT_ROOT'] = __DIR__ . "/..";
}

// Program starts here
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/common/functions.php";

// Dont run this is is not cli because it not supported
require_once $_SERVER['DOCUMENT_ROOT'] . "/common/users.php";

// Set default timezone to UTC for crawler
date_default_timezone_set('UTC');

// Load .env file (fallback to project root when DOCUMENT_ROOT differs)
$env_loaded = load_enviroment_variables($_SERVER['DOCUMENT_ROOT'] . '/.env');
if (!$env_loaded) {
	load_enviroment_variables(dirname(__DIR__) . '/.env');
}

// ---------------------------------------------------------
// Webhook Functions
// ---------------------------------------------------------

/**
 * Check if a Stripe event has already been processed
 * This prevents duplicate processing from webhook retries
 */
function is_stripe_event_processed($event_id)
{
	$log_file = $_SERVER['DOCUMENT_ROOT'] . '/stripe-events.json';

	if (!file_exists($log_file)) {
		return false;
	}

	$fp = fopen($log_file, 'r');
	if (!$fp) {
		trigger_error("Failed to open stripe-events.json for reading");
		return false;
	}

	flock($fp, LOCK_SH); // Shared read lock
	$content = fread($fp, filesize($log_file));
	flock($fp, LOCK_UN);
	fclose($fp);

	$events = json_decode($content, true);
	if (json_last_error() !== JSON_ERROR_NONE) {
		trigger_error(json_last_error_msg());
		return false;
	}

	return isset($events[$event_id]);
}

/**
 * Mark a Stripe event as processed
 * Automatically cleans up events older than 30 days
 */
function mark_stripe_event_processed($event_id)
{
	$log_file = $_SERVER['DOCUMENT_ROOT'] . '/stripe-events.json';

	$fp = fopen($log_file, 'c+');
	if (!$fp) {
		trigger_error("Failed to open stripe-events.json for writing");
		return false;
	}

	flock($fp, LOCK_EX); // Exclusive write lock

	// Read existing events
	$size = filesize($log_file);
	$content = $size > 0 ? fread($fp, $size) : '{}';
	$events = json_decode($content, true);

	if (json_last_error() !== JSON_ERROR_NONE) {
		trigger_error(json_last_error_msg());
		$events = []; // Reset to empty if corrupted
	}

	// Add new event with timestamp
	$events[$event_id] = time();

	// Auto-cleanup: Remove events older than 30 days
	$cutoff = time() - (30 * 86400);
	$events = array_filter($events, function ($timestamp) use ($cutoff) {
		return $timestamp > $cutoff;
	});

	// Write back
	ftruncate($fp, 0);
	rewind($fp);
	$write_result = fwrite($fp, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

	if ($write_result === false) {
		trigger_error("Failed to write to stripe-events.json");
	}

	flock($fp, LOCK_UN);
	fclose($fp);

	return $write_result !== false;
}

function handle_checkout_session_completed($session)
{
	// A completed Checkout Session is not always a confirmed payment.
	// Delayed payment methods are activated only after async_payment_succeeded.
	if (($session->payment_status ?? '') !== 'paid') {
		return true;
	}

	// Retrieve the user from client_reference_id
	$username = $session->client_reference_id;

	if (!$username) {
		// Fallback: Try to find by customer ID if client_reference_id missed (rare)
		if ($session->customer) {
			$username = find_username_by_stripe_customer_id($session->customer);
		}

		if (!$username) {
			trigger_error("Cannot identify user");
			return false;
		}
	}

	// 1. Save Customer ID (Essential for future lookups)
	if ($session->customer) {
		write_user_data($username, 'billing', false, 'stripe-customer-id', $session->customer);
	} else {
		trigger_error("Warning: Checkout session completed but no customer ID created for user: $username");
	}

	// 2. Update Subscription ID & Expiry
	if ($session->subscription) {
		write_user_data($username, 'billing', false, 'stripe-subscription-id', $session->subscription);

		// Get expiry from subscription object
		try {
			$sub = \Stripe\Subscription::retrieve($session->subscription);
			$expiry_date = date('j-M-Y', $sub->current_period_end);

			// Update Plan Expiry
			write_user_data($username, 'info', false, 'plan-expires', $expiry_date);

			// SAVE START DATE (If not already set)
			// For new subscriptions, current_period_start is effectively the start date.
			$existing_start = call_user_data($username, 'info', false, 'plan-start');
			if (empty($existing_start)) {
				$start_date = date('j-M-Y', $sub->current_period_start);
				write_user_data($username, 'info', false, 'plan-start', $start_date);
			}

			// Try to find plan name from metadata or product
			if (isset($sub->plan->product)) {
				try {
					$prod = \Stripe\Product::retrieve($sub->plan->product);

					if (isset($prod->metadata['plan_key'])) {
						write_user_data($username, 'info', false, 'plan-name', $prod->metadata['plan_key']);
					} else {
						trigger_error("Critical: Stripe product {$sub->plan->product} missing metadata[plan_key] for user: $username. Plan name not set!");
					}
				} catch (\Exception $e) {
					trigger_error($e->getMessage());
					return false;
				}
			} else {
				trigger_error($username);
			}

		} catch (\Exception $e) {
			trigger_error($e->getMessage());
			return false;
		}
	}

	// 3. Invoice Pending Flags
	if (isset($session->amount_total) && $session->amount_total > 0) {

		// Calculate and store invoice amounts
		calculate_and_store_invoice_amounts($username, $session->amount_total, $session->currency);

		// Notify admin of generic invoice
		notify_admin_generic_invoice($username, $session->amount_total, $session->currency, 'checkout.session.completed');

		// Send customer payment email
		send_customer_payment_email($username);

		// Issue an Elorus invoice only when include=elorus is present in the endpoint URL
		if (($_GET['include'] ?? '') === 'elorus' && file_exists($_SERVER['DOCUMENT_ROOT'] . '/common/users-elorus.php')) {
			require_once $_SERVER['DOCUMENT_ROOT'] . '/common/users-elorus.php';
			if (function_exists('check_and_issue_pending_elorus_invoices')) {
				check_and_issue_pending_elorus_invoices();
			}
		}
	} else {
		trigger_error("Warning: Checkout session has no amount_total for user: $username");
	}

	return true;
}

function handle_invoice_payment_succeeded($invoice)
{
	// Avoid Race Condition with Checkout Session:
	// If this is the initial subscription creation, let handle_checkout_session_completed handle it.
	// Otherwise both might try to write to users.json simultaneously, causing data loss.
	if (isset($invoice->billing_reason) && $invoice->billing_reason == 'subscription_create') {
		return true;
	}

	// This happens on auto-renewals
	$customer_id = $invoice->customer;
	if (!$customer_id) {
		trigger_error("Invoice payment succeeded but no customer ID found");
		return false;
	}

	$username = find_username_by_stripe_customer_id($customer_id);
	if (!$username) {
		trigger_error("Cannot identify user from customer ID: $customer_id");
		return false;
	}

	// Validate Subscription (Update Expiry)
	if ($invoice->subscription) {
		try {
			$sub = \Stripe\Subscription::retrieve($invoice->subscription);
			$expiry_date = date('j-M-Y', $sub->current_period_end);

			// Update Expiry Date
			write_user_data($username, 'info', false, 'plan-expires', $expiry_date);

			// Check Plan (in case of upgrade/downgrade not handled elsewhere)
			if (isset($sub->plan->product)) {
				try {
					$prod = \Stripe\Product::retrieve($sub->plan->product);

					if (isset($prod->metadata['plan_key'])) {
						write_user_data($username, 'info', false, 'plan-name', $prod->metadata['plan_key']);
					} else {
						trigger_error("Critical: Stripe product {$sub->plan->product} missing metadata[plan_key] on renewal for user: $username");
					}
				} catch (\Exception $e) {
					trigger_error($e->getMessage());
					return false;
				}
			}

		} catch (\Exception $e) {
			trigger_error($e->getMessage());
			return false;
		}
	} else {
		trigger_error("Warning: Invoice payment has no subscription ID for user: $username");
	}

	// Flag for Invoice Generation (Recurring payments need invoices too!)
	if ($invoice->amount_paid > 0) {
		calculate_and_store_invoice_amounts($username, $invoice->amount_paid, $invoice->currency);
		notify_admin_generic_invoice($username, $invoice->amount_paid, $invoice->currency, 'invoice.payment_succeeded');
		send_customer_payment_email($username);

		// Issue an Elorus invoice only when include=elorus is present in the endpoint URL
		if (($_GET['include'] ?? '') === 'elorus' && file_exists($_SERVER['DOCUMENT_ROOT'] . '/common/users-elorus.php')) {
			require_once $_SERVER['DOCUMENT_ROOT'] . '/common/users-elorus.php';
			if (function_exists('check_and_issue_pending_elorus_invoices')) {
				check_and_issue_pending_elorus_invoices();
			}
		}
	}

	return true;
}

function find_username_by_stripe_customer_id($stripe_customer_id)
{
	if (empty($stripe_customer_id))
		return false;

	$db_file = $_SERVER['DOCUMENT_ROOT'] . '/users.json';
	if (!file_exists($db_file))
		return false;

	$db = json_decode(file_get_contents($db_file), true);
	if (!$db || !isset($db['users'])) {
		trigger_error("Failed to load users.json for customer ID lookup");
		return false;
	}

	foreach ($db['users'] as $username => $data) {
		// Iterate through billing variants to find the ID
		if (isset($data['billing'])) {

			foreach ($data['billing'] as $time_key => $variants) {
				if (is_array($variants)) {
					foreach ($variants as $key => $value) {
						if ($key === 'stripe-customer-id' && $value === $stripe_customer_id) {
							return $username;
						}
					}
				}
			}
		}
	}

	return false;
}

// Load database once at the top
if (isset($_POST['start-subscription-stripe'])) {

	// Define plan_id based on user type (Player or Scout)
	$user_type_temp = call_user_data(USERNAME, 'info', false, 'type');
	$plan_id = ($user_type_temp === 'scout') ? 'scout-plan' : 'player-plan';

	// Fix #2: Validate plan exists and has valid price BEFORE collecting billing info
	$plan_price = get_product_price_by_plan_key($plan_id);

	if (empty($plan_price) || !is_numeric($plan_price) || floatval($plan_price) <= 0) {
		trigger_error("Invalid subscription plan selected. Please contact support.");
		exit;
	}

	// Validate products.json exists
	$json_path = $_SERVER['DOCUMENT_ROOT'] . "/products.json";
	if (!file_exists($json_path)) {
		trigger_error("Subscription service temporarily unavailable. Please try again later.");
		exit;
	}

	// Validate plan exists in products.json
	$products_data = json_decode(file_get_contents($json_path), true);
	if (!isset($products_data['products'][$plan_id])) {
		trigger_error("Selected plan not found. Please contact support.");
		exit;
	}

	// Save Billing & Invoice Data to User Profile (for future renewals)
	write_user_data(USERNAME, 'billing', false, 'country', 'GR'); // Always Greece

	$invoice_requested = isset($_POST['want_invoice']) ? 'yes' : null;
	if ($invoice_requested === 'yes') {

		// Collect Billing Data
		$billing_name = !empty($_POST['billing_name']) ? $_POST['billing_name'] : null;
		$billing_address = !empty($_POST['billing_address']) ? $_POST['billing_address'] : null;
		$billing_city = !empty($_POST['billing_city']) ? $_POST['billing_city'] : null;
		$billing_zip = !empty($_POST['billing_zip']) ? $_POST['billing_zip'] : null;
		$billing_company = !empty($_POST['billing_company']) ? $_POST['billing_company'] : null;
		$billing_afm = !empty($_POST['billing_afm']) ? $_POST['billing_afm'] : null;

		$billing_activity = !empty($_POST['billing_activity']) ? $_POST['billing_activity'] : null;

		write_user_data(USERNAME, 'billing', false, 'name', $billing_name);
		write_user_data(USERNAME, 'billing', false, 'address', $billing_address);
		write_user_data(USERNAME, 'billing', false, 'city', $billing_city);
		write_user_data(USERNAME, 'billing', false, 'zip', $billing_zip);
		write_user_data(USERNAME, 'billing', false, 'invoice-requested', $invoice_requested);
		write_user_data(USERNAME, 'billing', false, 'company', $billing_company);
		write_user_data(USERNAME, 'billing', false, 'afm', $billing_afm);
		write_user_data(USERNAME, 'billing', false, 'activity', $billing_activity);
	}

	// Start Stripe Flow (validation already done above)
	start_stripe_subscription_by_plan_key($plan_id, get_current_url());
}

// Handle subscription cancellation
if (isset($_POST['cancel-subscription-stripe'])) {

	if (cancel_stripe_subscription(USERNAME)) {
		trigger_correct('Your subscription has been scheduled for cancellation at the end of the current period.');
	} else {
		trigger_error('Failed to cancel subscription. Please try again.');
	}
}

// Handle subscription reactivation
if (isset($_POST['reactivate-subscription-stripe'])) {

	if (reactivate_stripe_subscription(USERNAME)) {
		trigger_correct('Your subscription has been successfully reactivated!');
	} else {
		trigger_error('Failed to reactivate subscription. Please try again.');
	}
}

// Handle Manage Billing Request
if (isset($_POST['manage-billing-stripe'])) {

	$url = get_stripe_billing_url_by_the_payment_method(USERNAME);
	if ($url) {
		header("Location: " . $url);
		exit;
	} else {
		trigger_error("No subscription details found for management.");
	}
}

// Daily Stripe polling is disabled. The Stripe webhook will be the only source of truth.
// // Clean all anonymous or invalid users once every 24 hours
// if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/users-stripe.txt') || (time() - (int) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/users-stripe.txt')) >= 86400) {
// 	file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/users-stripe.txt', time());
//
// 	// Clean anonymous/invalid users using the loaded JSON DB instead of external username call
// 	$db = loadJsonDb();
//
// 	// If users key missing or not an array, nothing to do
// 	if (!isset($db['users']) || !is_array($db['users'])) {
// 	} else {
//
// 		// Collect usernames from the DB keys
// 		$usernames = array_keys($db['users']);
//
// 		foreach ($usernames as $username) {
//
// 			// Check Stripe Subscription Renewal
// 			$sub_id = call_user_data($username, 'billing', false, 'stripe-subscription-id');
//
// 			// Only if user has a stripe subscription
// 			if ($sub_id) {
//
// 				// Make sure we can use Stripe
// 				if (class_exists('\Stripe\Stripe') && getenv('STRIPE_SECRET_KEY')) {
//
// 					try {
// 						\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));
// 						$sub = \Stripe\Subscription::retrieve($sub_id);
//
// 						// Get dates
// 						$stripe_timestamp = $sub->current_period_end;
//
// 						// Get local date
// 						$local_date_str = call_user_data($username, 'info', false, 'plan-expires');
// 						$local_timestamp = $local_date_str ? strtotime($local_date_str) : 0;
//
// 						// Compare: if Stripe is newer, update my database (RENEWAL DETECTED)
// 						if ($stripe_timestamp > $local_timestamp) {
//
// 							$new_date_str = date('j-M-Y', $stripe_timestamp);
// 							write_user_data($username, 'info', false, 'plan-expires', $new_date_str);
//
// 							// Sync Plan Name
// 							if (isset($sub->plan->product)) {
// 								$prod_id = $sub->plan->product;
// 								// We need to fetch product to get metadata
// 								$prod = \Stripe\Product::retrieve($prod_id);
// 								if ($prod && isset($prod->metadata['plan_key'])) {
// 									write_user_data($username, 'info', false, 'plan-name', $prod->metadata['plan_key']);
// 								}
// 							}
//
// 							// Handle Billing & Invoice for Renewal
// 							// Get the latest invoice for this subscription
// 							if ($sub->latest_invoice) {
// 								try {
// 									$invoice = \Stripe\Invoice::retrieve($sub->latest_invoice);
//
// 									// Only process if invoice was paid
// 									if ($invoice->status === 'paid' && $invoice->amount_paid > 0) {
//
// 										// Calculate and store invoice amounts
// 										calculate_and_store_invoice_amounts($username, $invoice->amount_paid, $invoice->currency);
// 										notify_admin_generic_invoice($username, $invoice->amount_paid, $invoice->currency, 'daily-renewal-sync');
// 										send_customer_payment_email($username);
//
// 										// Trigger Elorus invoice generation
// 										if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/common/users-elorus.php')) {
// 											require_once $_SERVER['DOCUMENT_ROOT'] . '/common/users-elorus.php';
// 											if (function_exists('check_and_issue_pending_elorus_invoices')) {
// 												check_and_issue_pending_elorus_invoices();
// 											}
// 										}
// 									}
// 								} catch (Exception $invoice_error) {
// 									trigger_error("Failed to process renewal invoice for user $username: " . $invoice_error->getMessage());
// 								}
// 							}
// 						}
//
// 					} catch (Exception $e) {
// 						trigger_error($e->getMessage());
// 						// If error (e.g. no internet, wrong key), just ignore and try tomorrow
// 					}
// 				}
// 			}
// 		}
//
// 		// 4. Save Changes
// 		saveJsonDb($db);
// 	}
// }

// Stripe webhook endpoint for future use:
// https://era-on.gr/common/users-stripe.php?webhook=stripe&include=elorus
// Listen for Stripe Webhooks (Replaces hook.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['webhook'] ?? '') === 'stripe') {

	// Get Stripe Secrets
	$stripe_secret_key = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
	$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET');

	if (!$stripe_secret_key) {
		trigger_error("Critical: STRIPE_SECRET_KEY not configured");
		http_response_code(500);
		exit("Stripe Key missing");
	}

	if (!$endpoint_secret) {
		trigger_error("Critical: STRIPE_WEBHOOK_SECRET not configured");
		http_response_code(500);
		exit("Webhook secret missing");
	}

	\Stripe\Stripe::setApiKey($stripe_secret_key);

	// Payload reading
	$payload = @file_get_contents('php://input');
	$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
	$event = null;

	try {
		$event = \Stripe\Webhook::constructEvent(
			$payload,
			$sig_header,
			$endpoint_secret
		);
	} catch (\UnexpectedValueException $e) {
		// Invalid payload
		trigger_error($e->getMessage());
		http_response_code(400);
		exit();
	} catch (\Stripe\Exception\SignatureVerificationException $e) {
		// Invalid signature
		trigger_error($e->getMessage());
		http_response_code(400);
		exit();
	}

	// Check if this event was already processed (idempotency)
	if (is_stripe_event_processed($event->id)) {
		// Already processed - acknowledge and exit
		http_response_code(200);
		exit;
	}

	// Handle the event
	$event_processed = true;

	switch ($event->type) {
		case 'checkout.session.completed':
		case 'checkout.session.async_payment_succeeded':
			$session = $event->data->object;
			$event_processed = handle_checkout_session_completed($session);
			break;

		case 'invoice.payment_succeeded':
			$invoice = $event->data->object;
			$event_processed = handle_invoice_payment_succeeded($invoice);
			break;

		case 'checkout.session.async_payment_failed':
		case 'invoice.payment_failed':
			// Stripe remains the source of truth. Do not activate the account.
			break;

		default:
		// Unexpected event type
		// echo 'Received unknown event type ' . $event->type;
	}

	if (!$event_processed) {
		http_response_code(500);
		exit;
	}

	// Mark event as successfully processed
	$mark_result = mark_stripe_event_processed($event->id);
	if (!$mark_result) {
		trigger_error("Warning: Failed to mark event {$event->id} as processed");
		http_response_code(500);
		exit;
	}

	http_response_code(200);
	exit;
}
