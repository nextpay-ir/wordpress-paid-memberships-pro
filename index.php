<?php
/**
 * Plugin Name: Nextpay Paid Memberships Pro
 * Description: This plugin lets you use NextPay gateway in Memberships Pro wp plugin.
 * author: Nextpay Company
 * Version: 1.1
 * License: GPL v2.0.
 * Website: NextPay.ir
 */
//load classes init method
add_action('plugins_loaded', 'load_nextpay_pmpro_class', 11);
add_action('plugins_loaded', ['PMProGateway_Nextpay', 'init'], 12);

function load_nextpay_pmpro_class()
{
    if (class_exists('PMProGateway')) {
        class PMProGateway_Nextpay extends PMProGateway
        {
            public function PMProGateway_Nextpay($gateway = null)
            {
                $this->gateway = $gateway;
                $this->gateway_environment = pmpro_getOption('gateway_environment');

                return $this->gateway;
            }

            public static function init()
            {
                //make sure Nextpay is a gateway option
                add_filter('pmpro_gateways', ['PMProGateway_Nextpay', 'pmpro_gateways']);

                //add fields to payment settings
                add_filter('pmpro_payment_options', ['PMProGateway_Nextpay', 'pmpro_payment_options']);
                add_filter('pmpro_payment_option_fields', ['PMProGateway_Nextpay', 'pmpro_payment_option_fields'], 10, 2);
                $gateway = pmpro_getOption('gateway');

                if ($gateway == 'nextpay') {
                    add_filter('pmpro_checkout_before_change_membership_level', ['PMProGateway_Nextpay', 'pmpro_checkout_before_change_membership_level'], 10, 2);
                    add_filter('pmpro_include_billing_address_fields', '__return_false');
                    add_filter('pmpro_include_payment_information_fields', '__return_false');
                    add_filter('pmpro_required_billing_fields', ['PMProGateway_Nextpay', 'pmpro_required_billing_fields']);
                }

                add_action('wp_ajax_nopriv_nextpay-ins', ['PMProGateway_Nextpay', 'pmpro_wp_ajax_nextpay_ins']);
                add_action('wp_ajax_nextpay-ins', ['PMProGateway_Nextpay', 'pmpro_wp_ajax_nextpay_ins']);
            }

            /**
             * Make sure Nextpay is in the gateways list.
             *
             * @since 1.0
             */
            public static function pmpro_gateways($gateways)
            {
                if (empty($gateways['nextpay'])) {
                    $gateways['nextpay'] = 'نکست پی';
                }

                return $gateways;
            }

            /**
             * Get a list of payment options that the Nextpay gateway needs/supports.
             *
             * @since 1.0
             */
            public static function getGatewayOptions()
            {
                $options = [
                    'nextpay_api_key',
                    'currency',
                    'tax_rate',
                ];

                return $options;
            }

            /**
             * Set payment options for payment settings page.
             *
             * @since 1.0
             */
            public static function pmpro_payment_options($options)
            {
                //get nextpay options
                $nextpay_options = PMProGateway_Nextpay::getGatewayOptions();

                //merge with others.
                $options = array_merge($nextpay_options, $options);

                return $options;
            }

            /**
             * Remove required billing fields.
             *
             * @since 1.8
             */
            public static function pmpro_required_billing_fields($fields)
            {
                unset($fields['bfirstname']);
                unset($fields['blastname']);
                unset($fields['baddress1']);
                unset($fields['bcity']);
                unset($fields['bstate']);
                unset($fields['bzipcode']);
                unset($fields['bphone']);
                unset($fields['bemail']);
                unset($fields['bcountry']);
                unset($fields['CardType']);
                unset($fields['AccountNumber']);
                unset($fields['ExpirationMonth']);
                unset($fields['ExpirationYear']);
                unset($fields['CVV']);

                return $fields;
            }

            /**
             * Display fields for Nextpay options.
             *
             * @since 1.0
             */
            public static function pmpro_payment_option_fields($values, $gateway)
            {

                ?>
                <tr class="pmpro_settings_divider gateway gateway_nextpay" <?php if ($gateway != 'nextpay') {
                ?>style="display: none;"<?php
                }
                ?>>
                    <td colspan="2">
                        <?php echo 'تنظیمات نکست پی';
                        ?>
                    </td>
                </tr>
                <tr class="gateway gateway_nextpay" <?php if ($gateway != 'nextpay') {
                ?>style="display: none;"<?php
                }
                ?>>
                    <th scope="row" valign="top">
                        <label for="nextpay_api_key">کلید مجوزدهی درگاه نکست پی:</label>
                    </th>
                    <td>
                        <input type="text" id="nextpay_api_key" name="nextpay_api_key" size="60" value="<?php echo esc_attr($values['nextpay_api_key']);
                        ?>" />
                    </td>
                </tr>

                <?php

            }

            /**
             * Instead of change membership levels, send users to Nextpay to pay.
             *
             * @since 1.8
             */
            public static function pmpro_checkout_before_change_membership_level($user_id, $morder)
            {
                include_once dirname(__FILE__).'/nextpay_payment.php';
                global $wpdb, $discount_code_id;

                //if no order, no need to pay
                if (empty($morder)) {
                    return;
                }

                $morder->user_id = $user_id;
                $morder->saveOrder();

                //save discount code use
                if (!empty($discount_code_id)) {
                    $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('".$discount_code_id."', '".$user_id."', '".$morder->id."', now())");
                }



                $order_id = $morder->code;
                $redirect = admin_url('admin-ajax.php')."?action=nextpay-ins&oid=$order_id";


                global $pmpro_currency;

                $amount = intval($morder->subtotal);
                if ($pmpro_currency == 'IRR') {
                    $amount /= 10;
                }

                $api_key = pmpro_getOption('nextpay_api_key');

                $parameters = array
                (
                    "api_key"=>$api_key,
                    "order_id"=> $order_id,
                    "amount"=>$amount,
                    "callback_uri"=>$redirect
                );

                $nextpay = new Nextpay_Payment($parameters);
                $result = $nextpay->token();

                if(intval($result->code) == -1){
                    $go = 'http://api.nextpay.org/gateway/payment/'.$result->trans_id;
                    header("Location: {$go}");
                    die();
                } else {
                    $Err = 'خطا در ارسال اطلاعات به نکست پی کد خطا :  '.$result->code;
                    $morder->status = 'cancelled';
                    $morder->notes = $Err;
                    $morder->saveOrder();
                    die($Err);
                }
            }

            public static function pmpro_wp_ajax_nextpay_ins()
            {
                include_once dirname(__FILE__).'/nextpay_payment.php';
                global $gateway_environment;
                if (!isset($_GET['oid']) || is_null($_GET['oid'])) {
                    die('order_id is not sent!');
                }

                $oid = $_GET['oid'];

                $morder = null;
                try {
                    $morder = new MemberOrder($oid);
                    $morder->getMembershipLevel();
                    $morder->getUser();
                } catch (Exception $exception) {
                    die('order_id is not valid');
                }

                $current_user_id = get_current_user_id();

                if ($current_user_id !== intval($morder->user_id)) {
                    die('this order is not belong to you!');
                }

                global $pmpro_currency;

                $Trans_Id = $_POST['trans_id'];
                $Amount = intval($morder->subtotal);
                if ($pmpro_currency == 'IRR') {
                    $Amount /= 10;
                }
                $Api_Key = pmpro_getOption('nextpay_api_key');


                $parameters = array
                (
                    'api_key'	=> $Api_Key,
                    'order_id'	=> $oid,
                    'trans_id' 	=> $Trans_Id,
                    'amount'	=> $Amount,
                );

                $nextpay = new Nextpay_Payment();
                $result = $nextpay->verify_request($parameters);

                if (intval($result) == 0) {
                    if (self::do_level_up($morder, $Trans_Id)) {
                        header('Location:'.pmpro_url('confirmation', '?level='.$morder->membership_level->id));
                    }
                } else {
                    $Err = 'خطا در ارسال اطلاعات به زرین پال کد خطا :  '.$result;
                    $morder->status = 'cancelled';
                    $morder->notes = $Err;
                    $morder->saveOrder();
                    header('Location: '.pmpro_url());
                    die($Err);
                }
            }

            public static function do_level_up(&$morder, $txn_id)
            {
                global $wpdb;
                //filter for level
                $morder->membership_level = apply_filters('pmpro_inshandler_level', $morder->membership_level, $morder->user_id);

                //fix expiration date
                if (!empty($morder->membership_level->expiration_number)) {
                    $enddate = "'".date('Y-m-d', strtotime('+ '.$morder->membership_level->expiration_number.' '.$morder->membership_level->expiration_period, current_time('timestamp')))."'";
                } else {
                    $enddate = 'NULL';
                }

                //get discount code
                $morder->getDiscountCode();
                if (!empty($morder->discount_code)) {
                    //update membership level
                    $morder->getMembershipLevel(true);
                    $discount_code_id = $morder->discount_code->id;
                } else {
                    $discount_code_id = '';
                }

                //set the start date to current_time('mysql') but allow filters
                $startdate = apply_filters('pmpro_checkout_start_date', "'".current_time('mysql')."'", $morder->user_id, $morder->membership_level);

                //custom level to change user to
                $custom_level = [
                    'user_id'         => $morder->user_id,
                    'membership_id'   => $morder->membership_level->id,
                    'code_id'         => $discount_code_id,
                    'initial_payment' => $morder->membership_level->initial_payment,
                    'billing_amount'  => $morder->membership_level->billing_amount,
                    'cycle_number'    => $morder->membership_level->cycle_number,
                    'cycle_period'    => $morder->membership_level->cycle_period,
                    'billing_limit'   => $morder->membership_level->billing_limit,
                    'trial_amount'    => $morder->membership_level->trial_amount,
                    'trial_limit'     => $morder->membership_level->trial_limit,
                    'startdate'       => $startdate,
                    'enddate'         => $enddate, ];

                global $pmpro_error;
                if (!empty($pmpro_error)) {
                    echo $pmpro_error;
                    inslog($pmpro_error);
                }

                if (pmpro_changeMembershipLevel($custom_level, $morder->user_id) !== false) {
                    //update order status and transaction ids
                    $morder->status = 'success';
                    $morder->payment_transaction_id = $txn_id;
                    //if( $recurring )
                    //    $morder->subscription_transaction_id = $txn_id;
                    //else
                    $morder->subscription_transaction_id = '';
                    $morder->saveOrder();

                    //add discount code use
                    if (!empty($discount_code) && !empty($use_discount_code)) {
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('".$discount_code_id."', '".$morder->user_id."', '".$morder->id."', '".current_time('mysql')."')");
                    }

                    //save first and last name fields
                    if (!empty($_POST['first_name'])) {
                        $old_firstname = get_user_meta($morder->user_id, 'first_name', true);
                        if (!empty($old_firstname)) {
                            update_user_meta($morder->user_id, 'first_name', $_POST['first_name']);
                        }
                    }
                    if (!empty($_POST['last_name'])) {
                        $old_lastname = get_user_meta($morder->user_id, 'last_name', true);
                        if (!empty($old_lastname)) {
                            update_user_meta($morder->user_id, 'last_name', $_POST['last_name']);
                        }
                    }

                    //hook
                    do_action('pmpro_after_checkout', $morder->user_id);

                    //setup some values for the emails
                    if (!empty($morder)) {
                        $invoice = new MemberOrder($morder->id);
                    } else {
                        $invoice = null;
                    }

                    //inslog("CHANGEMEMBERSHIPLEVEL: ORDER: " . var_export($morder, true) . "\n---\n");

                    $user = get_userdata(intval($morder->user_id));
                    if (empty($user)) {
                        return false;
                    }

                    $user->membership_level = $morder->membership_level;  //make sure they have the right level info
                    //send email to member
                    $pmproemail = new PMProEmail();
                    $pmproemail->sendCheckoutEmail($user, $invoice);

                    //send email to admin
                    $pmproemail = new PMProEmail();
                    $pmproemail->sendCheckoutAdminEmail($user, $invoice);

                    return true;
                } else {
                    return false;
                }
            }
        }
    }
}
