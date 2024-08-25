<?php

namespace App\Library\SslCommerz;

class SslCommerzNotification extends AbstractSslCommerz
{
    protected $data = [];
    protected $config = [];

    private $successUrl;
    private $cancelUrl;
    private $failedUrl;
    private $ipnUrl;
    private $error;

    /**
     * SslCommerzNotification constructor.
     */
    public function __construct()
    {
        $this->config = config('sslcommerz');

        $this->setStoreId($this->config['apiCredentials']['store_id']);
        $this->setStorePassword($this->config['apiCredentials']['store_password']);
    }

    public function orderValidate($post_data, $trx_id = '', $amount = 0, $currency = "BDT")
    {
        if (empty($post_data) && empty($trx_id) && !is_array($post_data)) {
            $this->error = "Please provide valid transaction ID and post request data";
            return $this->error;
        }

        return $this->validate($trx_id, $amount, $currency, $post_data);
    }


    # VALIDATE SSLCOMMERZ TRANSACTION
    protected function validate($merchant_trans_id, $merchant_trans_amount, $merchant_trans_currency, $post_data)
    {
        # MERCHANT SYSTEM INFO
        if (!empty($merchant_trans_id) && !empty($merchant_trans_amount)) {

            # CALL THE FUNCTION TO CHECK THE RESULT
            $post_data['store_id'] = $this->getStoreId();
            $post_data['store_pass'] = $this->getStorePassword();

            $val_id = urlencode($post_data['val_id']);
            $store_id = urlencode($this->getStoreId());
            $store_passwd = urlencode($this->getStorePassword());
            $requested_url = ($this->config['apiDomain'] . $this->config['apiUrl']['order_validate'] . "?val_id=" . $val_id . "&store_id=" . $store_id . "&store_passwd=" . $store_passwd . "&v=1&format=json");

            $handle = curl_init();
            curl_setopt_array($handle, [
                CURLOPT_URL => $requested_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => $this->config['connect_from_localhost'] ? 0 : 2,
                CURLOPT_SSL_VERIFYPEER => $this->config['connect_from_localhost'] ? 0 : 2,
            ]);

            $result = curl_exec($handle);
            $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);

            if ($code == 200 && !(curl_errno($handle))) {

                $result = json_decode($result);
                $this->sslc_data = $result;

                # TRANSACTION INFO
                $status = $result->status;
                $tran_date = $result->tran_date;
                $tran_id = $result->tran_id;
                $val_id = $result->val_id;
                $amount = $result->amount;
                $store_amount = $result->store_amount;
                $bank_tran_id = $result->bank_tran_id;
                $card_type = $result->card_type;
                $currency_type = $result->currency_type;
                $currency_amount = $result->currency_amount;

                # ISSUER INFO
                $card_no = $result->card_no;
                $card_issuer = $result->card_issuer;
                $card_brand = $result->card_brand;
                $card_issuer_country = $result->card_issuer_country;
                $card_issuer_country_code = $result->card_issuer_country_code;

                # API AUTHENTICATION
                $APIConnect = $result->APIConnect;
                $validated_on = $result->validated_on;
                $gw_version = $result->gw_version;

                # GIVE SERVICE
                if (in_array($status, ["VALID", "VALIDATED"])) {
                    if ($merchant_trans_currency == "BDT") {
                        if (trim($merchant_trans_id) == trim($tran_id) && (abs($merchant_trans_amount - $amount) < 1) && trim($merchant_trans_currency) == trim('BDT')) {
                            return true;
                        } else {
                            # DATA TEMPERED
                            $this->error = "Data has been tampered";
                            return false;
                        }
                    } else {
                        if (trim($merchant_trans_id) == trim($tran_id) && (abs($merchant_trans_amount - $currency_amount) < 1) && trim($merchant_trans_currency) == trim($currency_type)) {
                            return true;
                        } else {
                            # DATA TEMPERED
                            $this->error = "Data has been tampered";
                            return false;
                        }
                    }
                } else {
                    # FAILED TRANSACTION
                    $this->error = "Failed Transaction";
                    return false;
                }
            } else {
                # Failed to connect with SSLCOMMERZ
                $this->error = "Failed to connect with SSLCOMMERZ";
                return false;
            }
        } else {
            # INVALID DATA
            $this->error = "Invalid data";
            return false;
        }
    }

    # FUNCTION TO CHECK HASH VALUE
    protected function SSLCOMMERZ_hash_verify($post_data, $store_passwd = "")
    {
        if (isset($post_data['verify_sign'], $post_data['verify_key'])) {
            # NEW ARRAY DECLARED TO TAKE VALUE OF ALL POST
            $pre_define_key = explode(',', $post_data['verify_key']);

            $new_data = array_filter($pre_define_key, function ($value) use ($post_data) {
                return isset($post_data[$value]);
            });

            # ADD MD5 OF STORE PASSWORD
            $new_data['store_passwd'] = md5($store_passwd);

            # SORT THE KEY AS BEFORE
            ksort($new_data);

            $hash_string = http_build_query($new_data, '', '&');

            if (md5($hash_string) == $post_data['verify_sign']) {
                return true;
            } else {
                $this->error = "Verification signature not matched";
                return false;
            }
        } else {
            $this->error = 'Required data missing. ex: verify_key, verify_sign';
            return false;
        }
    }

    /**
     * @param array $requestData
     * @param string $type
     * @param string $pattern
     * @return false|mixed|string
     */
    public function makePayment(array $requestData, $type = 'checkout', $pattern = 'json')
    {
        if (empty($requestData)) {
            return "Please provide valid information about the transaction with transaction id, amount, success url, fail url, cancel url, store id, and password at least";
        }

        $header = [];

        $this->setApiUrl($this->config['apiDomain'] . $this->config['apiUrl']['make_payment']);

        // Set the required/additional params
        $this->setParams($requestData);

        // Set the authentication information
        $this->setAuthenticationInfo();

        // Now, call the Gateway API
        $response = $this->callToApi($this->data, $header, $this->config['connect_from_localhost']);

        $formattedResponse = $this->formatResponse($response, $type, $pattern); // Here we will define the response pattern

        if ($type == 'hosted') {
            if (!empty($formattedResponse['GatewayPageURL'])) {
                $this->redirect($formattedResponse['GatewayPageURL']);
            } else {
                if (strpos($formattedResponse['failedreason'], 'Store Credential') === false) {
                    $message = $formattedResponse['failedreason'];
                } else {
                    $message = "Check the SSLCZ_TESTMODE and SSLCZ_STORE_PASSWORD value in your .env; DO NOT USE MERCHANT PANEL PASSWORD HERE.";
                }

                return $message;
            }
        } else {
            return $formattedResponse;
        }
    }

    protected function setSuccessUrl()
    {
        $this->successUrl = rtrim(env('APP_URL'), '/') . $this->config['success_url'];
    }

    protected function getSuccessUrl()
    {
        return $this->successUrl;
    }

    protected function setFailedUrl()
    {
        $this->failedUrl = rtrim(env('APP_URL'), '/') . $this->config['failed_url'];
    }

    protected function getFailedUrl()
    {
        return $this->failedUrl;
    }

    protected function setCancelUrl()
    {
        $this->cancelUrl = rtrim(env('APP_URL'), '/') . $this->config['cancel_url'];
    }

    protected function getCancelUrl()
    {
        return $this->cancelUrl;
    }

    protected function setIPNUrl()
    {
        $this->ipnUrl = rtrim(env('APP_URL'), '/') . $this->config['ipn_url'];
    }

    protected function getIPNUrl()
    {
        return $this->ipnUrl;
    }

    public function setParams($requestData)
    {
        ##  Integration Required Parameters
        $this->setRequiredInfo($requestData);

        ##  Customer Information
        $this->setCustomerInfo($requestData);

        ##  Shipment Information
        $this->setShipmentInfo($requestData);

        ##  Product Information
        $this->setProductInfo($requestData);

        ##  Customized or Additional Parameters
        $this->setAdditionalInfo($requestData);
    }

    public function setAuthenticationInfo()
    {
        $this->data['store_id'] = $this->getStoreId();
        $this->data['store_passwd'] = $this->getStorePassword();

        return $this->data;
    }

    public function setRequiredInfo(array $info)
    {
        $requiredParams = [
            'total_amount',
            'currency',
            'tran_id',
            'product_category',
        ];

        foreach ($requiredParams as $param) {
            $this->data[$param] = $info[$param] ?? null;
        }

        // Set the SUCCESS, FAIL, CANCEL Redirect URL before setting the other parameters
        $this->setSuccessUrl();
        $this->setFailedUrl();
        $this->setCancelUrl();
        $this->setIPNUrl();

        $this->data['success_url'] = $this->getSuccessUrl();
        $this->data['fail_url'] = $this->getFailedUrl();
        $this->data['cancel_url'] = $this->getCancelUrl();
        $this->data['ipn_url'] = $this->getIPNUrl();

        $this->data['multi_card_name'] = $info['multi_card_name'] ?? null;
        $this->data['allowed_bin'] = $info['allowed_bin'] ?? null;

        ##   Parameters to Handle EMI Transaction ##
        $this->data['emi_option'] = $info['emi_option'] ?? null;
        $this->data['emi_max_inst_option'] = $info['emi_max_inst_option'] ?? null;
        $this->data['emi_selected_inst'] = $info['emi_selected_inst'] ?? null;
        $this->data['emi_allow_only'] = $info['emi_allow_only'] ?? 0;

        return $this->data;
    }

    public function setCustomerInfo(array $info)
    {
        $customerParams = [
            'cus_name',
            'cus_email',
            'cus_add1',
            'cus_add2',
            'cus_city',
            'cus_state',
            'cus_postcode',
            'cus_country',
            'cus_phone',
            'cus_fax',
        ];

        foreach ($customerParams as $param) {
            $this->data[$param] = $info[$param] ?? null;
        }

        return $this->data;
    }

    public function setShipmentInfo(array $info)
    {
        $shipmentParams = [
            'shipping_method',
            'num_of_item',
            'ship_name',
            'ship_add1',
            'ship_add2',
            'ship_city',
            'ship_state',
            'ship_postcode',
            'ship_country',
        ];

        foreach ($shipmentParams as $param) {
            $this->data[$param] = $info[$param] ?? null;
        }

        return $this->data;
    }

    public function setProductInfo(array $info)
    {
        $productParams = [
            'product_name',
            'product_category',
            'product_profile',
            'hours_till_departure',
            'flight_type',
            'pnr',
            'journey_from_to',
            'third_party_booking',
            'hotel_name',
            'length_of_stay',
            'check_in_time',
            'hotel_city',
            'product_type',
            'topup_number',
            'country_topup',
            'cart',
            'product_amount',
            'vat',
            'discount_amount',
            'convenience_fee',
        ];

        foreach ($productParams as $param) {
            $this->data[$param] = $info[$param] ?? null;
        }

        return $this->data;
    }

    public function setAdditionalInfo(array $info)
    {
        $additionalParams = [
            'value_a',
            'value_b',
            'value_c',
            'value_d',
        ];

        foreach ($additionalParams as $param) {
            $this->data[$param] = $info[$param] ?? null;
        }

        return $this->data;
    }
}
