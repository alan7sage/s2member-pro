<?php
// @codingStandardsIgnoreFile
/**
 * Stripe Checkout Form handler (inner processing routines).
 *
 * Copyright: © 2009-2011
 * {@link http://websharks-inc.com/ WebSharks, Inc.}
 * (coded in the USA)
 *
 * This WordPress plugin (s2Member Pro) is comprised of two parts:
 *
 * o (1) Its PHP code is licensed under the GPL license, as is WordPress.
 *   You should have received a copy of the GNU General Public License,
 *   along with this software. In the main directory, see: /licensing/
 *   If not, see: {@link http://www.gnu.org/licenses/}.
 *
 * o (2) All other parts of (s2Member Pro); including, but not limited to:
 *   the CSS code, some JavaScript code, images, and design;
 *   are licensed according to the license purchased.
 *   See: {@link http://s2member.com/prices/}
 *
 * Unless you have our prior written consent, you must NOT directly or indirectly license,
 * sub-license, sell, resell, or provide for free; part (2) of the s2Member Pro Add-on;
 * or make an offer to do any of these things. All of these things are strictly
 * prohibited with part (2) of the s2Member Pro Add-on.
 *
 * Your purchase of s2Member Pro includes free lifetime upgrades via s2Member.com
 * (i.e., new features, bug fixes, updates, improvements); along with full access
 * to our video tutorial library: {@link http://s2member.com/videos/}
 *
 * @package s2Member\Stripe
 * @since 140617
 */
if(!defined('WPINC')) // MUST have WordPress.
	exit('Do not access this file directly.');

if(!class_exists('c_ws_plugin__s2member_pro_stripe_checkout_in'))
{
	/**
	 * Stripe Checkout Form handler (inner processing routines).
	 *
	 * @package s2Member\Stripe
	 * @since 140617
	 */
	class c_ws_plugin__s2member_pro_stripe_checkout_in
	{
		/**
		 * Handles processing of Pro-Form checkouts.
		 *
		 * @package s2Member\Stripe
		 * @since 140617
		 *
		 * @attaches-to ``add_action('init');``
		 */
		public static function stripe_checkout()
		{
			if(!empty($_POST['s2member_pro_stripe_checkout']['nonce'])
			   && ($nonce = $_POST['s2member_pro_stripe_checkout']['nonce'])
			   && wp_verify_nonce($nonce, 's2member-pro-stripe-checkout')
			)
			{
				$GLOBALS['ws_plugin__s2member_pro_stripe_checkout_response'] = array(); // This holds the global response details.
				$global_response                                             = &$GLOBALS['ws_plugin__s2member_pro_stripe_checkout_response'];

				$post_vars         = c_ws_plugin__s2member_utils_strings::trim_deep(stripslashes_deep($_POST['s2member_pro_stripe_checkout']));
				$post_vars['attr'] = !empty($post_vars['attr']) ? (array)unserialize(c_ws_plugin__s2member_utils_encryption::decrypt($post_vars['attr'])) : array();
				$post_vars['attr'] = apply_filters('ws_plugin__s2member_pro_stripe_checkout_post_attr', $post_vars['attr'], get_defined_vars());

				// Stripe Payment Method and Intent IDs.
				$post_vars['pm_id']   = c_ws_plugin__s2member_utils_strings::trim_deep(stripslashes_deep($_POST['stripe_pm_id']));
				$post_vars['pi_id']   = c_ws_plugin__s2member_utils_strings::trim_deep(stripslashes_deep($_POST['stripe_pi_id']));
				$post_vars['seti_id'] = c_ws_plugin__s2member_utils_strings::trim_deep(stripslashes_deep($_POST['stripe_seti_id']));
				$post_vars['sub_id']  = c_ws_plugin__s2member_utils_strings::trim_deep(stripslashes_deep($_POST['stripe_sub_id']));

				$post_vars['name']     = trim($post_vars['first_name'].' '.$post_vars['last_name']);
				$post_vars['email']    = apply_filters('user_registration_email', sanitize_email(@$post_vars['email']), get_defined_vars());
				$post_vars['username'] = (is_multisite()) ? strtolower(@$post_vars['username']) : @$post_vars['username']; // Force lowercase.
				$post_vars['username'] = sanitize_user(($post_vars['_o_username'] = $post_vars['username']), is_multisite());

				$post_vars = c_ws_plugin__s2member_utils_captchas::recaptcha_post_vars($post_vars); // Collect reCAPTCHA™ post vars.

				if(!c_ws_plugin__s2member_pro_stripe_responses::stripe_form_attr_validation_errors($post_vars['attr']))
				{
					if(!($form_submission_validation_errors // Validate checkout input form fields.
						= c_ws_plugin__s2member_pro_stripe_responses::stripe_form_submission_validation_errors('checkout', $post_vars))
					) // If this fails the global response is set to the error(s) returned during form field validation.
					{
						unset($_POST['s2member_pro_stripe_checkout']['source_token']); // Good one-time only.
						unset($_POST['s2member_pro_stripe_checkout']['source_token_summary']); // Good one-time only.

						$is_bitcoin        = !empty($post_vars['source_token']) && stripos($post_vars['source_token'], 'btcrcv_') === 0;
						$cp_attr           = c_ws_plugin__s2member_pro_stripe_utilities::apply_coupon($post_vars['attr'], $post_vars['coupon'], 'attr', array('affiliates-silent-post'));
						$cost_calculations = c_ws_plugin__s2member_pro_stripe_utilities::cost($cp_attr['ta'], $cp_attr['ra'], $post_vars['state'], $post_vars['country'], $post_vars['zip'], $cp_attr['cc'], $cp_attr['desc'], $is_bitcoin);

						// So, if there's a trial cost or period (free trial period would not match here), but no regular cost...
						// then turn the trial into the regular amount, which being the first payment, will be charged directly.
						if($cost_calculations['total'] <= 0 && $post_vars['attr']['tp'] && $cost_calculations['trial_total'] > 0)
						{
							$post_vars['attr']['tp']              = '0'; // Ditch the trial period completely.
							$cost_calculations['sub_total']       = $cost_calculations['trial_sub_total']; // Use as regular sub-total (ditch trial sub-total).
							$cost_calculations['tax']             = $cost_calculations['trial_tax']; // Use as regular tax (ditch trial tax).
							$cost_calculations['tax_per']         = $cost_calculations['trial_tax_per']; // Use as regular tax (ditch trial tax).
							$cost_calculations['total']           = $cost_calculations['trial_total']; // Use as regular total (ditch trial).
							$cost_calculations['trial_sub_total'] = '0.00'; // Ditch the initial total (using as grand total).
							$cost_calculations['trial_tax']       = '0.00'; // Ditch this calculation now also.
							$cost_calculations['trial_tax_per']   = ''; // Ditch this calculation now also.
							$cost_calculations['trial_total']     = '0.00'; // Ditch this calculation now also.
						}
						$use_subscription          = ($post_vars['attr']['rr'] === 'BN' || (!$post_vars['attr']['tp'] && !$post_vars['attr']['rr'])) ? FALSE : TRUE;
						$is_independent_ccaps_sale = ($post_vars['attr']['level'] === '*') ? TRUE : FALSE; // Selling Independent Custom Capabilities?

						// If we'll use a subscription, there's no trial cost, and there is a total cost.
						if($use_subscription && $cost_calculations['trial_total'] <= 0 && $cost_calculations['total'] <= 0)
						{
							if(!$post_vars['attr']['rr'] && $post_vars['attr']['rt'] !== 'L')
							{
								if(substr_count($post_vars['attr']['level_ccaps_eotper'], ':') === 1)
									$post_vars['attr']['level_ccaps_eotper'] .= ':'.$post_vars['attr']['rp'].' '.$post_vars['attr']['rt'];

								else if(substr_count($post_vars['attr']['level_ccaps_eotper'], ':') === 0)
									$post_vars['attr']['level_ccaps_eotper'] .= '::'.$post_vars['attr']['rp'].' '.$post_vars['attr']['rt'];
							}
							else if($post_vars['attr']['rr'] && $post_vars['attr']['rrt'] && $post_vars['attr']['rt'] !== 'L')
							{
								if(substr_count($post_vars['attr']['level_ccaps_eotper'], ':') === 1)
									$post_vars['attr']['level_ccaps_eotper'] .= ':'.($post_vars['attr']['rp'] * $post_vars['attr']['rrt']).' '.$post_vars['attr']['rt'];

								else if(substr_count($post_vars['attr']['level_ccaps_eotper'], ':') === 0)
									$post_vars['attr']['level_ccaps_eotper'] .= '::'.($post_vars['attr']['rp'] * $post_vars['attr']['rrt']).' '.$post_vars['attr']['rt'];
							}
						}
						// Subscription, existing user.
						if($use_subscription && is_user_logged_in() && is_object($user = wp_get_current_user()) && ($user_id = $user->ID))
						{
							$plan_attr         = $cp_attr; // For the subscription plan.
							// Update amount attributes with taxes added.
							$plan_attr['ta']   = $cost_calculations['trial_total'];
							$plan_attr['ra']   = $cost_calculations['total'];
							$plan_attr['desc'] = $cost_calculations['desc'];

							update_user_meta($user_id, 'first_name', $post_vars['first_name']);
							update_user_meta($user_id, 'last_name', $post_vars['last_name']);

							$period1    = c_ws_plugin__s2member_paypal_utilities::paypal_pro_period1($post_vars['attr']['tp'].' '.$post_vars['attr']['tt']);
							$period3    = c_ws_plugin__s2member_paypal_utilities::paypal_pro_period3($post_vars['attr']['rp'].' '.$post_vars['attr']['rt']);
							$start_time = ($post_vars['attr']['tp']) ? // If there's an Initial/Trial Period; start when it's over.
								c_ws_plugin__s2member_pro_stripe_utilities::start_time($period1) : // After Trial is over.
								c_ws_plugin__s2member_pro_stripe_utilities::start_time($period3); // Or next billing cycle.

							if(!$global_response)
								if($cost_calculations['total'] > 0)
								{
									// The flow in this block is a bit complicated.

									// First we check if we come from authorizing a payment, or setting up an intent
									// If we have an payment intent ID, maybe its requirement was resolved.
									if(!empty($post_vars['pi_id'])) {
										// Check status.
										$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($post_vars['pi_id']);
										// If we get an object, then the charge succeeded!
										if(is_object($handle_intent_status))
											$stripe_intent_succeeded = $handle_intent_status;
									}
									// If we have a setup intent ID, maybe its requirement was resolved.
									if(!empty($post_vars['seti_id'])) {
										// Check status.
										$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($post_vars['seti_id']);
										// If we get an object, then the setup succeeded!
										if(is_object($handle_intent_status))
											$stripe_intent_succeeded = $handle_intent_status;
									}

									// If the above didn't succeed, and we have a payment method ID... 
									if(!isset($stripe_intent_succeeded) && !empty($post_vars['pm_id'])) {
										// Get the Customer object (create or retrieve).
										if(!is_object($stripe_customer = c_ws_plugin__s2member_pro_stripe_utilities::get_customer($user_id, $user->user_email, $post_vars['first_name'], $post_vars['last_name'], array(), $post_vars)))
											$global_response = array('response' => $stripe_customer, 'error' => TRUE);
										// Attach payment method to Customer if needed, or get existing one for this card.
										else if(!is_object($payment_method = c_ws_plugin__s2member_pro_stripe_utilities::attached_card_payment_method($stripe_customer->id, $post_vars['pm_id'])))
											$global_response = array('response' => $payment_method, 'error' => TRUE);

										// If we have a Payment Intent ID, let's try to update it and handle it again,
										if(!empty($post_vars['pi_id'])) {
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::update_payment_intent($post_vars['pi_id'], array('payment_method'=>$payment_method->id));
											$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($post_vars['pi_id']);
											// If we get an object, then the charge succeeded!
											if(is_object($handle_intent_status))
												$stripe_intent_succeeded = $handle_intent_status;
										}
										// Or if we have a Setup Intent ID, let's try to update it and handle it again.
										elseif(!empty($post_vars['seti_id'])) {
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::update_setup_intent($post_vars['seti_id'], array('payment_method'=>$payment_method->id));
											$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($post_vars['seti_id']);
											// If we get an object, then the setup succeeded!
											if(is_object($handle_intent_status))
												$stripe_intent_succeeded = $handle_intent_status;
										}

										// If we don't have successful intent object here, we create the subscription. 
										if((empty($stripe_intent_succeeded) || !is_object($stripe_intent_succeeded))) {
											// Get the plan for the subscription.
											if(!is_object($stripe_plan = c_ws_plugin__s2member_pro_stripe_utilities::get_plan($plan_attr)))
												$global_response = array('response' => $stripe_plan, 'error' => TRUE);
											// And now we create the subscription.
											elseif(!is_object($stripe_subscription = c_ws_plugin__s2member_pro_stripe_utilities::create_customer_subscription($stripe_customer->id, $stripe_plan->id, array(), $post_vars, $cost_calculations)))
												$global_response = array('response' => $stripe_subscription, 'error' => TRUE);
											else {
												// Save the subscription ID to have it later for the user profile.
												$GLOBALS['ws_plugin__s2member_pro_stripe']['sub_id'] = $stripe_subscription->id;

												// if I have a payment intent object, then handle it
												if(!empty($stripe_subscription->latest_invoice->payment_intent) && is_object($stripe_intent = $stripe_subscription->latest_invoice->payment_intent)) {
													$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($stripe_intent->id);
													// If we get an object, then the charge succeeded!
													if(is_object($handle_intent_status))
														$stripe_intent_succeeded = $handle_intent_status;
												}
												// else if I have a setup intent object, then handle it
												elseif(!empty($stripe_subscription->pending_setup_intent) && is_object($setup_intent = $stripe_subscription->pending_setup_intent)) {
													$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($setup_intent->id);
													// If we get an object, then the setup succeeded!
													if(is_object($handle_intent_status))
														$stripe_intent_succeeded = $handle_intent_status;
												}
												// So we just created the subscription, but didn't need an intent, probably trialing already.
												elseif($stripe_subscription->status == 'trialing' || $stripe_subscription->status == 'active')
													$subscription_good = true;
											}
										}
									}

									// If intent status didn't succeed, let's get the response with the status requirement.
									if(!isset($stripe_intent_succeeded) && !empty($handle_intent_status) && is_array($handle_intent_status))
										$global_response = $handle_intent_status;
									// If we got here, and we don't have a setup or payment intent, or method ID, ask for a card.
									else if(empty($post_vars['seti_id']) && empty($post_vars['pi_id']) && empty($post_vars['pm_id']))
										$global_response = array('response' => _x('Please submit a card.', 's2member-front', 's2member'), 'error' => TRUE);
									// Or we have a successful payment intent charge. Rejoice!
									else if((isset($stripe_intent_succeeded) && is_object($stripe_intent_succeeded) && $stripe_intent_succeeded->status == 'succeeded') 
										|| (isset($subscription_good) && $subscription_good))
									{
										// The Stripe Customer ID
										if (!empty($stripe_subscription->customer))
											$new__subscr_cid = $stripe_subscription->customer;
										elseif (!empty($stripe_intent_succeeded->customer))
											$new__subscr_cid = $stripe_intent_succeeded->customer;
										
										// Getting the subscription's ID may be a bit trickier.
										// If we just created the subscription, that's easy.
										if (!empty($stripe_subscription->id))
											$new__subscr_id = $stripe_subscription->id;
										// If this was after a payment intent's 3DS, we need to get it from here...
										elseif (!empty($stripe_intent_succeeded->charges->data['0']['invoice']->subscription))
											$new__subscr_id = $stripe_intent_succeeded->charges->data['0']['invoice']->subscription;
										// Setup intents objects don't have a way to find the subscription, so we'll try getting the one saved from the previous step.
										elseif (!empty($post_vars['sub_id']))
											$new__subscr_id = $post_vars['sub_id'];
										// Let's add this one as a last resort, in case we can use it in the future to identify the subscription... 
										elseif (!empty($stripe_intent_succeeded->id))
											$new__subscr_id = $stripe_intent_succeeded->id;
									}
								}

								if(!$global_response)
							{
								$old__subscr_cid      = get_user_option('s2member_subscr_cid');
								$old__subscr_id       = get_user_option('s2member_subscr_id');
								$old__subscr_or_wp_id = c_ws_plugin__s2member_utils_users::get_user_subscr_or_wp_id();

								if(empty($new__subscr_cid)) $new__subscr_cid = strtoupper('free-'.uniqid());
								if(empty($new__subscr_id)) $new__subscr_id = strtoupper('free-'.uniqid());

								$ipn['txn_type']   = 'subscr_signup';
								$ipn['subscr_cid'] = $new__subscr_cid;
								$ipn['subscr_id']  = $new__subscr_id;
								$ipn['custom']     = $post_vars['attr']['custom'];

								$ipn['txn_cid'] = !empty($new__txn_cid) ? $new__txn_cid : $new__subscr_cid;
								$ipn['txn_id']  = !empty($new__txn_id) ? $new__txn_id : $new__subscr_id;

								$ipn['period1'] = $period1;
								$ipn['period3'] = $period3;

								$ipn['mc_amount1'] = $cost_calculations['trial_total'];
								$ipn['mc_amount3'] = $cost_calculations['total'];

								$ipn['mc_gross'] = preg_match('/^[1-9]/', $ipn['period1']) ? $ipn['mc_amount1'] : $ipn['mc_amount3'];

								$ipn['mc_currency'] = $cost_calculations['cur'];
								$ipn['tax']         = $cost_calculations['tax'];

								$ipn['recurring'] = ($post_vars['attr']['rr']) ? '1' : '';

								$ipn['payer_email'] = $user->user_email;
								$ipn['first_name']  = $post_vars['first_name'];
								$ipn['last_name']   = $post_vars['last_name'];

								$ipn['option_name1']      = 'Referencing Customer ID';
								$ipn['option_selection1'] = $old__subscr_or_wp_id;

								$ipn['option_name2']      = 'Customer IP Address';
								$ipn['option_selection2'] = c_ws_plugin__s2member_utils_ip::current();

								$ipn['item_name']   = $cost_calculations['desc'];
								$ipn['item_number'] = $post_vars['attr']['level_ccaps_eotper'];

								$ipn['s2member_paypal_proxy']     = 'stripe';
								$ipn['s2member_paypal_proxy_use'] = 'pro-emails';
								$ipn['s2member_paypal_proxy_use'] .= ($ipn['mc_gross'] > 0) ? ',subscr-signup-as-subscr-payment' : '';
								$ipn['s2member_paypal_proxy_coupon']       = array('coupon_code' => $cp_attr['_coupon_code'], 'full_coupon_code' => $cp_attr['_full_coupon_code'], 'affiliate_id' => $cp_attr['_coupon_affiliate_id']);
								$ipn['s2member_paypal_proxy_verification'] = c_ws_plugin__s2member_paypal_utilities::paypal_proxy_key_gen();
								$ipn['s2member_paypal_proxy_return_url']   = $post_vars['attr']['success'];

								$ipn['s2member_stripe_proxy_return_url'] = trim(c_ws_plugin__s2member_utils_urls::remote(home_url('/?s2member_paypal_notify=1'), $ipn, array('timeout' => 20)));

								if(!empty($stripe_subscription_failed_charge_succeeded))
									update_user_option($user_id, 's2member_auto_eot_time', $start_time);

								if($old__subscr_cid && $old__subscr_id && apply_filters('s2member_pro_cancels_old_rp_before_new_rp', TRUE, get_defined_vars()))
									c_ws_plugin__s2member_pro_stripe_utilities::cancel_customer_subscription($old__subscr_cid, $old__subscr_id, FALSE);

								c_ws_plugin__s2member_list_servers::process_list_servers_against_current_user((boolean)@$post_vars['custom_fields']['opt_in'], TRUE, TRUE);

								setcookie('s2member_tracking', ($s2member_tracking = c_ws_plugin__s2member_utils_encryption::encrypt($new__subscr_id)), time() + 31556926, COOKIEPATH, COOKIE_DOMAIN).
								setcookie('s2member_tracking', $s2member_tracking, time() + 31556926, SITECOOKIEPATH, COOKIE_DOMAIN).
								($_COOKIE['s2member_tracking'] = $s2member_tracking);

								$global_response = array('response' => sprintf(_x('<strong>Thank you.</strong> Your account has been updated :-)', 's2member-front', 's2member'), esc_attr(wp_login_url())));

								if($post_vars['attr']['success'] && substr($ipn['s2member_stripe_proxy_return_url'], 0, 2) === substr($post_vars['attr']['success'], 0, 2) && ($custom_success_url = str_ireplace(array('%%s_response%%', '%%response%%'), array(urlencode(c_ws_plugin__s2member_utils_encryption::encrypt($global_response['response'])), urlencode($global_response['response'])), $ipn['s2member_stripe_proxy_return_url'])) && ($custom_success_url = trim(preg_replace('/%%(.+?)%%/i', '', $custom_success_url))))
									wp_redirect(c_ws_plugin__s2member_utils_urls::add_s2member_sig($custom_success_url, 's2p-v')).exit();
							}
						}
						// Subscription, new user.
						else if($use_subscription && !is_user_logged_in()) // Create a new account.
						{
							$plan_attr         = $cp_attr; // For the subscription plan.
							$plan_attr['ta']   = $cost_calculations['trial_total'];
							$plan_attr['ra']   = $cost_calculations['total'];
							$plan_attr['desc'] = $cost_calculations['desc'];

							$period1    = c_ws_plugin__s2member_paypal_utilities::paypal_pro_period1($post_vars['attr']['tp'].' '.$post_vars['attr']['tt']);
							$period3    = c_ws_plugin__s2member_paypal_utilities::paypal_pro_period3($post_vars['attr']['rp'].' '.$post_vars['attr']['rt']);
							$start_time = ($post_vars['attr']['tp']) ? // If there's an Initial/Trial Period; start when it's over.
								c_ws_plugin__s2member_pro_stripe_utilities::start_time($period1) : // After Trial is over.
								c_ws_plugin__s2member_pro_stripe_utilities::start_time($period3); // Or next billing cycle.

							if(!$global_response)
								if($cost_calculations['total'] > 0)
								{
									// The flow in this block is a bit complicated.

									// First we check if we come from authorizing a payment, or setting up an intent
									// If we have an payment intent ID, maybe its requirement was resolved.
									if(!empty($post_vars['pi_id'])) {
										// Check status.
										$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($post_vars['pi_id']);
										// If we get an object, then the charge succeeded!
										if(is_object($handle_intent_status))
											$stripe_intent_succeeded = $handle_intent_status;
									}
									// If we have a setup intent ID, maybe its requirement was resolved.
									if(!empty($post_vars['seti_id'])) {
										// Check status.
										$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($post_vars['seti_id']);
										// If we get an object, then the setup succeeded!
										if(is_object($handle_intent_status))
											$stripe_intent_succeeded = $handle_intent_status;
									}

									// If the above didn't succeed, and we have a payment method ID... 
									if(!isset($stripe_intent_succeeded) && !empty($post_vars['pm_id'])) {
										// Get the Customer object (create or retrieve).
										if(!is_object($stripe_customer = c_ws_plugin__s2member_pro_stripe_utilities::get_customer(0, $post_vars['email'], $post_vars['first_name'], $post_vars['last_name'], array(), $post_vars)))
											$global_response = array('response' => $stripe_customer, 'error' => TRUE);
										// Attach payment method to Customer if needed, or get existing one for this card.
										else if(!is_object($payment_method = c_ws_plugin__s2member_pro_stripe_utilities::attached_card_payment_method($stripe_customer->id, $post_vars['pm_id'])))
											$global_response = array('response' => $payment_method, 'error' => TRUE);

										// If we have a Payment Intent ID, let's try to update it and handle it again,
										if(!empty($post_vars['pi_id'])) {
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::update_payment_intent($post_vars['pi_id'], array('payment_method'=>$payment_method->id));
											$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($post_vars['pi_id']);
											// If we get an object, then the charge succeeded!
											if(is_object($handle_intent_status))
												$stripe_intent_succeeded = $handle_intent_status;
										}
										// Or if we have a Setup Intent ID, let's try to update it and handle it again.
										elseif(!empty($post_vars['seti_id'])) {
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::update_setup_intent($post_vars['seti_id'], array('payment_method'=>$payment_method->id));
											$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($post_vars['seti_id']);
											// If we get an object, then the setup succeeded!
											if(is_object($handle_intent_status))
												$stripe_intent_succeeded = $handle_intent_status;
										}

										// If we don't have successful intent object here, we create the subscription. 
										if((empty($stripe_intent_succeeded) || !is_object($stripe_intent_succeeded))) {
											// Get the plan for the subscription.
											if(!is_object($stripe_plan = c_ws_plugin__s2member_pro_stripe_utilities::get_plan($plan_attr)))
												$global_response = array('response' => $stripe_plan, 'error' => TRUE);
											// And now we create the subscription.
											elseif(!is_object($stripe_subscription = c_ws_plugin__s2member_pro_stripe_utilities::create_customer_subscription($stripe_customer->id, $stripe_plan->id, array(), $post_vars, $cost_calculations)))
												$global_response = array('response' => $stripe_subscription, 'error' => TRUE);
											else {
												// Save the subscription ID to have it later for the user profile.
												$GLOBALS['ws_plugin__s2member_pro_stripe']['sub_id'] = $stripe_subscription->id;

												// if I have a payment intent object, then handle it
												if(!empty($stripe_subscription->latest_invoice->payment_intent) && is_object($stripe_intent = $stripe_subscription->latest_invoice->payment_intent)) {
													$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($stripe_intent->id);
													// If we get an object, then the charge succeeded!
													if(is_object($handle_intent_status))
														$stripe_intent_succeeded = $handle_intent_status;
												}
												// else if I have a setup intent object, then handle it
												elseif(!empty($stripe_subscription->pending_setup_intent) && is_object($setup_intent = $stripe_subscription->pending_setup_intent)) {
													$handle_intent_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_setup_intent_status($setup_intent->id);
													// If we get an object, then the setup succeeded!
													if(is_object($handle_intent_status))
														$stripe_intent_succeeded = $handle_intent_status;
												}
												// So we just created the subscription, but didn't need an intent, probably trialing already.
												elseif($stripe_subscription->status == 'trialing' || $stripe_subscription->status == 'active')
													$subscription_good = true;
											}
										}
									}

									// If intent status didn't succeed, let's get the response with the status requirement.
									if(!isset($stripe_intent_succeeded) && !empty($handle_intent_status) && is_array($handle_intent_status))
										$global_response = $handle_intent_status;
									// If we got here, and we don't have a setup or payment intent, or method ID, ask for a card.
									else if(empty($post_vars['seti_id']) && empty($post_vars['pi_id']) && empty($post_vars['pm_id']))
										$global_response = array('response' => _x('Please submit a card.', 's2member-front', 's2member'), 'error' => TRUE);
									// Or we have a successful payment intent charge. Rejoice!
									else if((isset($stripe_intent_succeeded) && is_object($stripe_intent_succeeded) && $stripe_intent_succeeded->status == 'succeeded') 
										|| (isset($subscription_good) && $subscription_good))
									{
										// The Stripe Customer ID
										if (!empty($stripe_subscription->customer))
											$new__subscr_cid = $stripe_subscription->customer;
										elseif (!empty($stripe_intent_succeeded->customer))
											$new__subscr_cid = $stripe_intent_succeeded->customer;
										
										// Getting the subscription's ID may be a bit trickier.
										// If we just created the subscription, that's easy.
										if (!empty($stripe_subscription->id))
											$new__subscr_id = $stripe_subscription->id;
										// If this was after a payment intent's 3DS, we need to get it from here...
										elseif (!empty($stripe_intent_succeeded->charges->data['0']['invoice']->subscription))
											$new__subscr_id = $stripe_intent_succeeded->charges->data['0']['invoice']->subscription;
										// Setup intents objects don't have a way to find the subscription, so we'll try getting the one saved from the previous step.
										elseif (!empty($post_vars['sub_id']))
											$new__subscr_id = $post_vars['sub_id'];
										// Let's add this one as a last resort, in case we can use it in the future to identify the subscription... 
										elseif (!empty($stripe_intent_succeeded->id))
											$new__subscr_id = $stripe_intent_succeeded->id;
									}
								}

							if(!$global_response) // No errors thus far?
							{
								if(empty($new__subscr_cid)) $new__subscr_cid = strtoupper('free-'.uniqid());
								if(empty($new__subscr_id)) $new__subscr_id = strtoupper('free-'.uniqid());

								$ipn['txn_type']   = 'subscr_signup';
								$ipn['subscr_cid'] = $new__subscr_cid;
								$ipn['subscr_id']  = $new__subscr_id;
								$ipn['custom']     = $post_vars['attr']['custom'];

								$ipn['txn_cid'] = !empty($new__txn_cid) ? $new__txn_cid : $new__subscr_cid;
								$ipn['txn_id']  = !empty($new__txn_id) ? $new__txn_id : $new__subscr_id;

								$ipn['period1'] = $period1;
								$ipn['period3'] = $period3;

								$ipn['mc_amount1'] = $cost_calculations['trial_total'];
								$ipn['mc_amount3'] = $cost_calculations['total'];

								$ipn['mc_gross'] = preg_match('/^[1-9]/', $ipn['period1']) ? $ipn['mc_amount1'] : $ipn['mc_amount3'];

								$ipn['mc_currency'] = $cost_calculations['cur'];
								$ipn['tax']         = $cost_calculations['tax'];

								$ipn['recurring'] = ($post_vars['attr']['rr']) ? '1' : '';

								$ipn['payer_email'] = $post_vars['email'];
								$ipn['first_name']  = $post_vars['first_name'];
								$ipn['last_name']   = $post_vars['last_name'];

								$ipn['option_name1']      = 'Originating Domain';
								$ipn['option_selection1'] = $_SERVER['HTTP_HOST'];

								$ipn['option_name2']      = 'Customer IP Address';
								$ipn['option_selection2'] = c_ws_plugin__s2member_utils_ip::current();

								$ipn['item_name']   = $cost_calculations['desc'];
								$ipn['item_number'] = $post_vars['attr']['level_ccaps_eotper'];

								$ipn['s2member_paypal_proxy']     = 'stripe';
								$ipn['s2member_paypal_proxy_use'] = 'pro-emails';
								$ipn['s2member_paypal_proxy_use'] .= ($ipn['mc_gross'] > 0) ? ',subscr-signup-as-subscr-payment' : '';
								$ipn['s2member_paypal_proxy_coupon']       = array('coupon_code' => $cp_attr['_coupon_code'], 'full_coupon_code' => $cp_attr['_full_coupon_code'], 'affiliate_id' => $cp_attr['_coupon_affiliate_id']);
								$ipn['s2member_paypal_proxy_verification'] = c_ws_plugin__s2member_paypal_utilities::paypal_proxy_key_gen();
								$ipn['s2member_paypal_proxy_return_url']   = $post_vars['attr']['success'];

								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_user_pass1'] = @$post_vars['password1'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_first_name'] = $post_vars['first_name'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_last_name']  = $post_vars['last_name'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_opt_in']     = @$post_vars['custom_fields']['opt_in'];

								if($GLOBALS['WS_PLUGIN__']['s2member']['o']['custom_reg_fields'])
									foreach(json_decode($GLOBALS['WS_PLUGIN__']['s2member']['o']['custom_reg_fields'], TRUE) as $field)
									{
										$field_var      = preg_replace('/[^a-z0-9]/i', '_', strtolower($field['id']));
										$field_id_class = preg_replace('/_/', '-', $field_var);

										if(isset($post_vars['custom_fields'][$field_var]))
											$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_'.$field_var]
												= $post_vars['custom_fields'][$field_var];
									}
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_subscr_gateway'] = 'stripe';
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_subscr_cid']     = $new__subscr_cid;
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_subscr_id']      = $new__subscr_id;
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_level']          = $post_vars['attr']['level'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_ccaps']          = $post_vars['attr']['ccaps'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_custom']         = $post_vars['attr']['custom'];
								@list ($level, $ccaps, $eotper) = preg_split('/\:/', $post_vars['attr']['level_ccaps_eotper'], 3);
								if(!empty($eotper)) $GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_auto_eot_time']
									= date('Y-m-d H:i:s', c_ws_plugin__s2member_utils_time::auto_eot_time('', '', '', $eotper));

								$create_user['user_email'] = $post_vars['email']; // Copy this into a separate array for `wp_create_user()`.
								$create_user['user_login'] = $post_vars['username']; // Copy this into a separate array for `wp_create_user()`.
								$create_user['user_pass']  = c_ws_plugin__s2member_registrations::maybe_custom_pass($post_vars["password1"]);
								$has_custom_password       = !empty($post_vars['password1']) && $post_vars['password1'] === $create_user['user_pass'];

								if(((is_multisite() && ($new__user_id = c_ws_plugin__s2member_registrations::ms_create_existing_user($create_user['user_login'], $create_user['user_email'], $create_user['user_pass'])))
								    || ($new__user_id = wp_create_user($create_user['user_login'], $create_user['user_pass'], $create_user['user_email'])))
								   && !is_wp_error($new__user_id)
								)
								{
									update_user_option($new__user_id, 'default_password_nag', $has_custom_password ? FALSE : TRUE, TRUE);

									if (version_compare(get_bloginfo("version"), "4.3.1", ">="))
										wp_new_user_notification($new__user_id, null, $has_custom_password ? "admin" : "both", $create_user['user_pass']);
									else if (version_compare(get_bloginfo("version"), "4.3", ">="))
										wp_new_user_notification($new__user_id, $has_custom_password ? "admin" : "both", $create_user['user_pass']);
									else wp_new_user_notification($new__user_id, $create_user['user_pass']);

									// if(!empty($stripe_subscription_failed_charge_succeeded))
									// 	update_user_option($new__user_id, 's2member_auto_eot_time', $start_time);

									$ipn['s2member_stripe_proxy_return_url'] = trim(c_ws_plugin__s2member_utils_urls::remote(home_url('/?s2member_paypal_notify=1'), $ipn, array('timeout' => 20)));

									setcookie('s2member_tracking', ($s2member_tracking = c_ws_plugin__s2member_utils_encryption::encrypt($new__subscr_id)), time() + 31556926, COOKIEPATH, COOKIE_DOMAIN).
									setcookie('s2member_tracking', $s2member_tracking, time() + 31556926, SITECOOKIEPATH, COOKIE_DOMAIN).
									($_COOKIE['s2member_tracking'] = $s2member_tracking);

									if($has_custom_password)
										$global_response = array('response' => sprintf(_x('<strong>Thank you.</strong> Your account has been approved.<br />&mdash; Please <a href="%s" rel="nofollow">log in</a>.', 's2member-front', 's2member'), esc_attr(wp_login_url())));
									else $global_response = array('response' => _x('<strong>Thank you.</strong> Your account has been approved.<br />&mdash; You\'ll receive an email momentarily.', 's2member-front', 's2member'));

									if($post_vars['attr']['success'] && substr($ipn['s2member_stripe_proxy_return_url'], 0, 2) === substr($post_vars['attr']['success'], 0, 2)
									   && ($custom_success_url = str_ireplace(array('%%s_response%%', '%%response%%'), array(urlencode(c_ws_plugin__s2member_utils_encryption::encrypt($global_response['response'])), urlencode($global_response['response'])), $ipn['s2member_stripe_proxy_return_url']))
									   && ($custom_success_url = trim(preg_replace('/%%(.+?)%%/i', '', $custom_success_url)))
									) wp_redirect(c_ws_plugin__s2member_utils_urls::add_s2member_sig($custom_success_url, 's2p-v')).exit();
								}
								else // Else, an error reponse should be given.
								{
									c_ws_plugin__s2member_utils_urls::remote(home_url('/?s2member_paypal_notify=1'), $ipn, array('timeout' => 20));

									$global_response = array('response' => _x('<strong>Oops.</strong> A slight problem. Please contact Support for assistance.', 's2member-front', 's2member'), 'error' => TRUE);
								}
							}
						}
						// Buy-now, existing user.
						else if(!$use_subscription && is_user_logged_in() && is_object($user = wp_get_current_user()) && ($user_id = $user->ID))
						{
							update_user_meta($user_id, 'first_name', $post_vars['first_name']);
							update_user_meta($user_id, 'last_name', $post_vars['last_name']);

							if(!$global_response)
								if($cost_calculations['total'] > 0)
								{
									// The flow in this block is a bit complicated.

									// If we have an intent ID, the some intent requirement was solved.
									if(!empty($post_vars['pi_id'])) {
										// Check status.
										$handle_pi_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($post_vars['pi_id']);
										// If we get an object, then the charge succeeded!
										if(is_object($handle_pi_status))
											$stripe_intent_succeeded = $handle_pi_status;
									}

									// If the above didn't succeed, and we have a payment method ID.
									if(!isset($stripe_intent_succeeded) && !empty($post_vars['pm_id'])) {
										// Get the Customer object (create or retrieve).
										if(!is_object($stripe_customer = c_ws_plugin__s2member_pro_stripe_utilities::get_customer($user_id, $user->user_email, $post_vars['first_name'], $post_vars['last_name'], array(), $post_vars)))
											$global_response = array('response' => $stripe_customer, 'error' => TRUE);

										// Attach payment method to Customer if needed, or get existing one for this card.
										else if(!is_object($payment_method = c_ws_plugin__s2member_pro_stripe_utilities::attached_card_payment_method($stripe_customer->id, $post_vars['pm_id'])))
											$global_response = array('response' => $payment_method, 'error' => TRUE);

										// if we have a Payment Intent, let's try to update it, if not create one.
										if(!empty($post_vars['pi_id']))
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::update_payment_intent($post_vars['pi_id'], array('payment_method'=>$payment_method->id));
										if(empty($post_vars['pi_id']) || (!empty($stripe_intent) && !is_object($stripe_intent)))
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::create_payment_intent($stripe_customer->id, $payment_method->id, $cost_calculations['total'], $cost_calculations['cur'], $cost_calculations['desc'], array(), $post_vars, $cost_calculations);
										if(!is_object($stripe_intent))
											$global_response = array('response' => $stripe_intent, 'error' => TRUE);
										// Let's see now what the status for this intent is.
										// If we get an object, then the charge succeeded!
										else if(is_object($handle_pi_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($stripe_intent->id)))
											$stripe_intent_succeeded = $handle_pi_status;
									}

									// If status didn't succeed, let's get the response with the status requirement.
									if(!isset($stripe_intent_succeeded) && !empty($handle_pi_status) && is_array($handle_pi_status))
										$global_response = $handle_pi_status;

									// If we got here, maybe we don't have a payment intent or method ID, ask for card.
									else if(empty($post_vars['pi_id']) && empty($post_vars['pm_id']))
										$global_response = array('response' => _x('Please submit a card.', 's2member-front', 's2member'), 'error' => TRUE);

									// Rejoice! We have a successful payment intent charge.
									else if(isset($stripe_intent_succeeded) && is_object($stripe_intent_succeeded) && $stripe_intent_succeeded->status == 'succeeded')
									{
										$new__txn_cid = $stripe_intent_succeeded->customer;
										$new__txn_id  = $stripe_intent_succeeded->id;
									}
								}
							if(!$global_response)
							{
								$old__subscr_cid      = get_user_option('s2member_subscr_cid');
								$old__subscr_id       = get_user_option('s2member_subscr_id');
								$old__subscr_or_wp_id = c_ws_plugin__s2member_utils_users::get_user_subscr_or_wp_id();

								if(empty($new__txn_cid)) $new__txn_cid = strtoupper('free-'.uniqid());
								if(empty($new__txn_id)) $new__txn_id = strtoupper('free-'.uniqid());

								$ipn['txn_type'] = 'web_accept';
								$ipn['txn_cid']  = $new__txn_cid;
								$ipn['txn_id']   = $new__txn_id;
								$ipn['custom']   = $post_vars['attr']['custom'];

								$ipn['mc_gross']    = $cost_calculations['total'];
								$ipn['mc_currency'] = $cost_calculations['cur'];
								$ipn['tax']         = $cost_calculations['tax'];

								$ipn['payer_email'] = $user->user_email;
								$ipn['first_name']  = $post_vars['first_name'];
								$ipn['last_name']   = $post_vars['last_name'];

								$ipn['option_name1']      = 'Referencing Customer ID';
								$ipn['option_selection1'] = $old__subscr_or_wp_id;

								$ipn['option_name2']      = 'Customer IP Address';
								$ipn['option_selection2'] = c_ws_plugin__s2member_utils_ip::current();

								$ipn['item_name']   = $cost_calculations['desc'];
								$ipn['item_number'] = $post_vars['attr']['level_ccaps_eotper'];

								$ipn['s2member_paypal_proxy']              = 'stripe';
								$ipn['s2member_paypal_proxy_use']          = 'pro-emails';
								$ipn['s2member_paypal_proxy_coupon']       = array('coupon_code' => $cp_attr['_coupon_code'], 'full_coupon_code' => $cp_attr['_full_coupon_code'], 'affiliate_id' => $cp_attr['_coupon_affiliate_id']);
								$ipn['s2member_paypal_proxy_verification'] = c_ws_plugin__s2member_paypal_utilities::paypal_proxy_key_gen();
								$ipn['s2member_paypal_proxy_return_url']   = $post_vars['attr']['success'];

								$ipn['s2member_stripe_proxy_return_url'] = trim(c_ws_plugin__s2member_utils_urls::remote(home_url('/?s2member_paypal_notify=1'), $ipn, array('timeout' => 20)));

								if(!$is_independent_ccaps_sale) // Independent?
									if($old__subscr_cid && $old__subscr_id && apply_filters('s2member_pro_cancels_old_rp_before_new_rp', TRUE, get_defined_vars()))
										c_ws_plugin__s2member_pro_stripe_utilities::cancel_customer_subscription($old__subscr_cid, $old__subscr_id, FALSE);

								c_ws_plugin__s2member_list_servers::process_list_servers_against_current_user((boolean)@$post_vars['custom_fields']['opt_in'], TRUE, TRUE);

								setcookie('s2member_tracking', ($s2member_tracking = c_ws_plugin__s2member_utils_encryption::encrypt($new__txn_id)), time() + 31556926, COOKIEPATH, COOKIE_DOMAIN).
								setcookie('s2member_tracking', $s2member_tracking, time() + 31556926, SITECOOKIEPATH, COOKIE_DOMAIN).
								($_COOKIE['s2member_tracking'] = $s2member_tracking);

								$global_response = array('response' => sprintf(_x('<strong>Thank you.</strong> Your account has been updated :-)', 's2member-front', 's2member'), esc_attr(wp_login_url())));

								if($post_vars['attr']['success']
								   && substr($ipn['s2member_stripe_proxy_return_url'], 0, 2) === substr($post_vars['attr']['success'], 0, 2)
								   && ($custom_success_url = str_ireplace(array('%%s_response%%', '%%response%%'), array(urlencode(c_ws_plugin__s2member_utils_encryption::encrypt($global_response['response'])), urlencode($global_response['response'])), $ipn['s2member_stripe_proxy_return_url']))
								   && ($custom_success_url = trim(preg_replace('/%%(.+?)%%/i', '', $custom_success_url)))
								) wp_redirect(c_ws_plugin__s2member_utils_urls::add_s2member_sig($custom_success_url, 's2p-v')).exit();
							}
						}
						// Buy-now, new user.
						else if(!$use_subscription && !is_user_logged_in()) // Create a new account.
						{
							if(!$global_response)
								if($cost_calculations['total'] > 0)
								{
									// The flow in this block is a bit complicated.

									// If we have an intent ID, the some intent requirement was solved.
									if(!empty($post_vars['pi_id'])) {
										// Check status.
										$handle_pi_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($post_vars['pi_id']);
										// If we get an object, then the charge succeeded!
										if(is_object($handle_pi_status))
											$stripe_intent_succeeded = $handle_pi_status;
									}

									// If the above didn't succeed, and we have a payment method ID.
									if(!isset($stripe_intent_succeeded) && !empty($post_vars['pm_id'])) {
										// Get the Customer object (create or retrieve).
										if(!is_object($stripe_customer = c_ws_plugin__s2member_pro_stripe_utilities::get_customer(0, $post_vars['email'], $post_vars['first_name'], $post_vars['last_name'], array(), $post_vars)))
											$global_response = array('response' => $stripe_customer, 'error' => TRUE);

										// Attach payment method to Customer if needed, or get existing one for this card.
										else if(!is_object($payment_method = c_ws_plugin__s2member_pro_stripe_utilities::attached_card_payment_method($stripe_customer->id, $post_vars['pm_id'])))
											$global_response = array('response' => $payment_method, 'error' => TRUE);

										// if we have a Payment Intent, let's try to update it, if not create one.
										if(!empty($post_vars['pi_id']))
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::update_payment_intent($post_vars['pi_id'], array('payment_method'=>$payment_method->id));
										if(empty($post_vars['pi_id']) || (!empty($stripe_intent) && !is_object($stripe_intent)))
											$stripe_intent = c_ws_plugin__s2member_pro_stripe_utilities::create_payment_intent($stripe_customer->id, $payment_method->id, $cost_calculations['total'], $cost_calculations['cur'], $cost_calculations['desc'], array(), $post_vars, $cost_calculations);
										if(!is_object($stripe_intent))
											$global_response = array('response' => $stripe_intent, 'error' => TRUE);
										// Let's see now what the status for this intent is.
										// If we get an object, then the charge succeeded!
										else if(is_object($handle_pi_status = c_ws_plugin__s2member_pro_stripe_utilities::handle_payment_intent_status($stripe_intent->id)))
											$stripe_intent_succeeded = $handle_pi_status;
									}

									// If status didn't succeed, let's get the response with the status requirement.
									if(!isset($stripe_intent_succeeded) && !empty($handle_pi_status) && is_array($handle_pi_status))
										$global_response = $handle_pi_status;

									// If we got here, maybe we don't have a payment intent or method ID, ask for card.
									else if(empty($post_vars['pi_id']) && empty($post_vars['pm_id']))
										$global_response = array('response' => _x('Please submit a card.', 's2member-front', 's2member'), 'error' => TRUE);

									// Rejoice! We have a successful payment intent charge.
									else if(isset($stripe_intent_succeeded) && is_object($stripe_intent_succeeded) && $stripe_intent_succeeded->status == 'succeeded')
									{
										$new__txn_cid = $stripe_intent_succeeded->customer;
										$new__txn_id  = $stripe_intent_succeeded->id;
									}
								}
							if(!$global_response)
							{
								if(empty($new__txn_cid)) $new__txn_cid = strtoupper('free-'.uniqid());
								if(empty($new__txn_id)) $new__txn_id = strtoupper('free-'.uniqid());

								$ipn['txn_type'] = 'web_accept';
								$ipn['txn_cid']  = $new__txn_cid;
								$ipn['txn_id']   = $new__txn_id;
								$ipn['custom']   = $post_vars['attr']['custom'];

								$ipn['mc_gross']    = $cost_calculations['total'];
								$ipn['mc_currency'] = $cost_calculations['cur'];
								$ipn['tax']         = $cost_calculations['tax'];

								$ipn['payer_email'] = $post_vars['email'];
								$ipn['first_name']  = $post_vars['first_name'];
								$ipn['last_name']   = $post_vars['last_name'];

								$ipn['option_name1']      = 'Originating Domain';
								$ipn['option_selection1'] = $_SERVER['HTTP_HOST'];

								$ipn['option_name2']      = 'Customer IP Address';
								$ipn['option_selection2'] = c_ws_plugin__s2member_utils_ip::current();

								$ipn['item_name']   = $cost_calculations['desc'];
								$ipn['item_number'] = $post_vars['attr']['level_ccaps_eotper'];

								$ipn['s2member_paypal_proxy']              = 'stripe';
								$ipn['s2member_paypal_proxy_use']          = 'pro-emails';
								$ipn['s2member_paypal_proxy_coupon']       = array('coupon_code' => $cp_attr['_coupon_code'], 'full_coupon_code' => $cp_attr['_full_coupon_code'], 'affiliate_id' => $cp_attr['_coupon_affiliate_id']);
								$ipn['s2member_paypal_proxy_verification'] = c_ws_plugin__s2member_paypal_utilities::paypal_proxy_key_gen();
								$ipn['s2member_paypal_proxy_return_url']   = $post_vars['attr']['success'];

								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_user_pass1'] = @$post_vars['password1'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_first_name'] = $post_vars['first_name'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_last_name']  = $post_vars['last_name'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_opt_in']     = @$post_vars['custom_fields']['opt_in'];

								if($GLOBALS['WS_PLUGIN__']['s2member']['o']['custom_reg_fields'])
									foreach(json_decode($GLOBALS['WS_PLUGIN__']['s2member']['o']['custom_reg_fields'], TRUE) as $field)
									{
										$field_var      = preg_replace('/[^a-z0-9]/i', '_', strtolower($field['id']));
										$field_id_class = preg_replace('/_/', '-', $field_var);

										if(isset($post_vars['custom_fields'][$field_var]))
											$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_'.$field_var]
												= $post_vars['custom_fields'][$field_var];
									}
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_subscr_gateway'] = 'stripe';
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_subscr_cid']     = $new__txn_cid;
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_subscr_id']      = $new__txn_id;
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_level']          = $post_vars['attr']['level'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_ccaps']          = $post_vars['attr']['ccaps'];
								$GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_custom']         = $post_vars['attr']['custom'];
								@list ($level, $ccaps, $eotper) = preg_split('/\:/', $post_vars['attr']['level_ccaps_eotper'], 3);
								if(!empty($eotper)) $GLOBALS['ws_plugin__s2member_registration_vars']['ws_plugin__s2member_custom_reg_field_s2member_auto_eot_time']
									= date('Y-m-d H:i:s', c_ws_plugin__s2member_utils_time::auto_eot_time('', '', '', $eotper));

								$create_user['user_email'] = $post_vars['email']; // Copy this into a separate array for `wp_create_user()`.
								$create_user['user_login'] = $post_vars['username']; // Copy this into a separate array for `wp_create_user()`.
								$create_user['user_pass']  = c_ws_plugin__s2member_registrations::maybe_custom_pass($post_vars["password1"]);
								$has_custom_password       = !empty($post_vars['password1']) && $post_vars['password1'] === $create_user['user_pass'];

								if(((is_multisite() && ($new__user_id = c_ws_plugin__s2member_registrations::ms_create_existing_user($create_user['user_login'], $create_user['user_email'], $create_user['user_pass'])))
								    || ($new__user_id = wp_create_user($create_user['user_login'], $create_user['user_pass'], $create_user['user_email'])))
								   && !is_wp_error($new__user_id)
								)
								{
									update_user_option($new__user_id, 'default_password_nag', $has_custom_password ? FALSE : TRUE, TRUE);

									if (version_compare(get_bloginfo("version"), "4.3.1", ">="))
										wp_new_user_notification($new__user_id, null, $has_custom_password ? "admin" : "both", $create_user['user_pass']);
									else if (version_compare(get_bloginfo("version"), "4.3", ">="))
										wp_new_user_notification($new__user_id, $has_custom_password ? "admin" : "both", $create_user['user_pass']);
									else wp_new_user_notification($new__user_id, $create_user['user_pass']);

									$ipn['s2member_stripe_proxy_return_url'] = trim(c_ws_plugin__s2member_utils_urls::remote(home_url('/?s2member_paypal_notify=1'), $ipn, array('timeout' => 20)));

									setcookie('s2member_tracking', ($s2member_tracking = c_ws_plugin__s2member_utils_encryption::encrypt($new__txn_id)), time() + 31556926, COOKIEPATH, COOKIE_DOMAIN).
									setcookie('s2member_tracking', $s2member_tracking, time() + 31556926, SITECOOKIEPATH, COOKIE_DOMAIN).
									($_COOKIE['s2member_tracking'] = $s2member_tracking);

									if($has_custom_password)
										$global_response = array('response' => sprintf(_x('<strong>Thank you.</strong> Your account has been approved.<br />&mdash; Please <a href="%s" rel="nofollow">log in</a>.', 's2member-front', 's2member'), esc_attr(wp_login_url())));
									else $global_response = array('response' => _x('<strong>Thank you.</strong> Your account has been approved.<br />&mdash; You\'ll receive an email momentarily.', 's2member-front', 's2member'));

									if($post_vars['attr']['success'] && substr($ipn['s2member_stripe_proxy_return_url'], 0, 2) === substr($post_vars['attr']['success'], 0, 2)
									   && ($custom_success_url = str_ireplace(array('%%s_response%%', '%%response%%'), array(urlencode(c_ws_plugin__s2member_utils_encryption::encrypt($global_response['response'])), urlencode($global_response['response'])), $ipn['s2member_stripe_proxy_return_url']))
									   && ($custom_success_url = trim(preg_replace('/%%(.+?)%%/i', '', $custom_success_url)))
									) wp_redirect(c_ws_plugin__s2member_utils_urls::add_s2member_sig($custom_success_url, 's2p-v')).exit();
								}
								else // Else, an error reponse should be given.
								{
									c_ws_plugin__s2member_utils_urls::remote(home_url('/?s2member_paypal_notify=1'), $ipn, array('timeout' => 20));

									$global_response = array('response' => _x('<strong>Oops.</strong> A slight problem. Please contact Support for assistance.', 's2member-front', 's2member'), 'error' => TRUE);
								}
							}
						}
						else $global_response = array('response' => _x('<strong>Unknown error.</strong> Please contact Support for assistance.', 's2member-front', 's2member'), 'error' => TRUE);
					}
					else // Input form field validation errors.
						$global_response = $form_submission_validation_errors;
				}
			}
		}
	}
}
