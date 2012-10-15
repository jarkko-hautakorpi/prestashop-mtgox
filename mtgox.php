<?php

include_once(__DIR__.'/lib/MtgoxApi.php');

if (!defined('_PS_VERSION_'))
    exit;

class Mtgox extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'mtgox';
        $this->tab = 'payments_gateways';
        $this->version = 1.0;
        $this->author = 'MtGox';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('MtGox Payment Gateway');
        $this->description = $this->l('MtGox allow you to accept bitcoin payments through its platform.');
    }

    /**
     * Prestashop install
     */
    public function install()
    {
        $pendingStatus = Configuration::get('MTGOX_PENDING_STATE_ID');

        if ($pendingStatus === false) {
            $orderState = new OrderState();
            $langs = Language::getLanguages();
            foreach ($langs AS $lang) {
                $orderState->name[$lang['id_lang']] = pSQL('MtGox payment pending');
            }

            $orderState->invoice = false;
            $orderState->send_email = false;
            $orderState->logable = true;
            $orderState->color = '#FFDD99';
            $orderState->save();

            Configuration::updateValue('MTGOX_PENDING_STATE_ID', $orderState->id);
        }

        if (parent::install() == false OR
                !$this->registerHook('payment') OR
                !Configuration::updateValue('MTGOX_MERCHANT_ID', '0') OR
                !Configuration::updateValue('MTGOX_API_KEY', '0') OR
                !Configuration::updateValue('MTGOX_API_SECRET_KEY', '0') OR
                !Configuration::updateValue('MTGOX_PAYMENT_DESCRIPTION', 'MtGox Payment Gateway') OR
                !Configuration::updateValue('MTGOX_EMAIL_ON_SUCCESS', '1') OR
                !Configuration::updateValue('MTGOX_AUTOSELL', '1') OR
                !Configuration::updateValue('MTGOX_INSTANT_ONLY', '0'))
            return false;
        return true;
    }

    /**
     * Prestashop uninstall
     */
    public function uninstall()
    {
        return (parent::uninstall() AND
            Configuration::deleteByName('MTGOX_MERCHANT_ID') AND
            Configuration::deleteByName('MTGOX_API_KEY') AND
            Configuration::deleteByName('MTGOX_API_SECRET_KEY') AND
            Configuration::deleteByName('MTGOX_PAYMENT_DESCRIPTION') AND
            Configuration::deleteByName('MTGOX_EMAIL_ON_SUCCESS') AND
            Configuration::deleteByName('MTGOX_AUTOSELL') AND
            Configuration::deleteByName('MTGOX_INSTANT_ONLY'));
    }

    /**
     * Prestashop config page
     */
    public function getContent()
    {
        global $smarty;
        $errors = array();

        if (Tools::isSubmit('submitMtgox')) {
            foreach ($this->getConfigFields() as $field) {
                $field_val = Tools::getValue(strtolower($field['config_name']));
                if (isset($field['empty']) AND $field['empty'] == false) {
                    if ($field_val != '0' AND empty($field_val)) {
                        $errors[] = $this->l($field['display_name'].' field cannot be empty');
                        continue;
                    }
                }

                if (isset($field['boolean']) AND $field['boolean'] == true) {
                    if (!in_array($field_val, array('0', '1'))) {
                        $errors[] = $this->l($field['display_name'].' field must be a string containing 0 or 1');
                        continue;
                    }
                }

                Configuration::updateValue($field['config_name'], $field_val);
            }

            if (!$errors)
            {
                // Retro 1.4
                global $currentIndex;

                $curr_index = Tools::property_exists('AdminController', 'currentIndex') ?
                    AdminController::$currentIndex : $currentIndex;
                Tools::redirectAdmin($curr_index.'&configure=mtgox&token='.Tools::safeOutput(Tools::getValue('token')).'&conf=4');
            }
        }

        $smarty->assign(array(
            'displayName'        => $this->displayName,
            'requestUrl'         => Tools::safeOutput($_SERVER['REQUEST_URI']),
            'merchantId'         => Tools::safeOutput(Tools::getValue('mtgox_merchant_id', Configuration::get('MTGOX_MERCHANT_ID'))),
            'apiKey'             => Tools::safeOutput(Tools::getValue('mtgox_api_key', Configuration::get('MTGOX_API_KEY'))),
            'apiSecretKey'       => Tools::safeOutput(Tools::getValue('mtgox_api_secret_key', Configuration::get('MTGOX_API_SECRET_KEY'))),
            'paymentDescription' => Tools::safeOutput(Tools::getValue('mtgox_payment_description', Configuration::get('MTGOX_PAYMENT_DESCRIPTION'))),
            'autosell'           => Tools::safeOutput(Tools::getValue('mtgox_autosell', Configuration::get('MTGOX_AUTOSELL'))),
            'email'              => Tools::safeOutput(Tools::getValue('mtgox_email_on_success', Configuration::get('MTGOX_EMAIL_ON_SUCCESS'))),
            'instantonly'        => Tools::safeOutput(Tools::getValue('mtgox_instant_only', Configuration::get('MTGOX_INSTANT_ONLY'))),
            'submit'             => Tools::isSubmit('submitMtgox'),
            'errors'             => $errors
        ));

        return $this->display(__FILE__, 'views/configure.tpl');
    }

    /**
     * Prestashop "hook" payment option
     */
    public function hookPayment()
    {
        global $smarty;

        return $this->display(__FILE__, 'views/mtgox.tpl');
    }

    /**
     * Prepare for payment and assign the total to the template
     *
     * @param Cart $cart    Prestashop cart
     * @return void
     */
    public function preparePayment($cart)
    {
        global $smarty;

        $smarty->assign(array(
            'total' => $cart->getOrderTotal(),
        ));
    }

    /**
     * Try to checkout the payment
     *
     * @param float $total          Total amount to pay
     * @param integer $id           Cart id
     * @param string $currency      ISO Currency code
     * @param string $base_dir_ssl  The base url of the shop
     * @return array
     * @throws Exception
     */
    public function checkout($total, $id, $currency, $base_dir_ssl) {
        if ($this->validateOrder($id, (int)Configuration::get('MTGOX_PENDING_STATE_ID'), 0, 'MtGox') === true) {
            // prestashop validateOrder is a pain
            // we're stuck to set AGAIN the correct status as it won't take ours
            $order = new Order((int)$this->currentOrder);
            $order->setCurrentState(Configuration::get('MTGOX_PENDING_STATE_ID'));
            $order->save();

            $request = array(
                'amount'         => $total,
                'currency'       => $currency,
                'description'    => Tools::safeOutput(Configuration::get('MTGOX_PAYMENT_DESCRIPTION')).' Order #'.(int)$this->currentOrder,
                'data'           => $this->currentOrder,
                'return_success' => $base_dir_ssl.'modules/mtgox/payment.php?step=success',
                'return_failure' => $base_dir_ssl.'modules/mtgox/payment.php?step=failure&order='.(int)$this->currentOrder,
                'ipn'            => $base_dir_ssl.'modules/mtgox/payment.php?step=callback'
            );

            $request['autosell'] = (bool)Configuration::get('MTGOX_AUTOSELL');

            $request['email'] = (bool)Configuration::get('MTGOX_EMAIL_ON_SUCCESS');

            $request['instant_only'] = (bool)Configuration::get('MTGOX_INSTANT_ONLY');

            return MtgoxApi::mtgoxQuery(MtgoxApi::API_ORDER_CREATE, Configuration::get('MTGOX_API_KEY'), Configuration::get('MTGOX_API_SECRET_KEY'), $request);
        } else {
            throw new \Exception('Could not place the order. Please try again or contact the store owner.');
        }
    }

    /**
     * Cancel order given its id
     *
     * @param integer $id   Order id
     * @return boolean
     */
    public function cancelOrder($id)
    {
        $order = new Order($id);
        $order->setCurrentState(6);
        $order->save();

        return true;
    }

    /**
     * Configuration fields
     *
     * @return array
     */
    private function getConfigFields()
    {
        return array(
            array('config_name' => 'MTGOX_MERCHANT_ID',
                  'display_name' => 'Merchant ID',
                  'empty' => false),
            array('config_name' => 'MTGOX_API_KEY',
                  'display_name' => 'API Key',
                  'empty' => false),
            array('config_name' => 'MTGOX_API_SECRET_KEY',
                  'display_name' => 'API Secret Key',
                  'empty' => false),
            array('config_name' => 'MTGOX_PAYMENT_DESCRIPTION',
                  'display_name' => 'Payment Description',
                  'empty' => false),
            array('config_name' => 'MTGOX_AUTOSELL',
                  'display_name' => 'Autosell',
                  'empty' => false,
                  'boolean' => true),
            array('config_name' => 'MTGOX_EMAIL_ON_SUCCESS',
                  'display_name' => 'Email on Success',
                  'empty' => false,
                  'boolean' => true),
            array('config_name' => 'MTGOX_INSTANT_ONLY',
                  'display_name' => 'Instant Only',
                  'empty' => false,
                  'boolean' => true),
        );
    }
}
