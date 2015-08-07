<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author        Ryan Hoerr <magento@paradoxlabs.com>
 * @license        http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\TokenBase\Helper;

/**
 * Class Data
 */
class Data extends \Magento\Payment\Helper\Data
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Card
     */
    protected $card;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Resource\Card\Collection[]
     */
    protected $cards;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $appState;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Logger\Logger
     */
    protected $tokenbaseLogger;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var \Magento\Store\Model\WebsiteFactory
     */
    protected $websiteFactory;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Model\CardFactory
     */
    protected $cardFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Resource\Card\CollectionFactory
     */
    protected $cardCollectionFactory;

    /**
     * @var \Magento\Customer\Model\Customer
     */
    protected $currentCustomer;

    /**
     * Construct
     *
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\View\LayoutFactory $layoutFactory
     * @param \Magento\Payment\Model\Method\Factory $paymentMethodFactory
     * @param \Magento\Store\Model\App\Emulation $appEmulation
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param \Magento\Framework\App\Config\Initial $initialConfig
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Store\Model\WebsiteFactory $websiteFactory
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory
     * @param \ParadoxLabs\TokenBase\Model\Resource\Card\CollectionFactory $cardCollectionFactory
     * @param \ParadoxLabs\TokenBase\Model\Logger\Logger $tokenbaseLogger
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\View\LayoutFactory $layoutFactory,
        \Magento\Payment\Model\Method\Factory $paymentMethodFactory,
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\App\Config\Initial $initialConfig,
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Store\Model\WebsiteFactory $websiteFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory,
        \ParadoxLabs\TokenBase\Model\Resource\Card\CollectionFactory $cardCollectionFactory,
        \ParadoxLabs\TokenBase\Model\Logger\Logger $tokenbaseLogger
    ) {
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->registry = $registry;
        $this->objectManager = $objectManager;
        $this->websiteFactory = $websiteFactory;
        $this->customerFactory = $customerFactory;
        $this->cardFactory = $cardFactory;
        $this->cardCollectionFactory = $cardCollectionFactory;
        $this->tokenbaseLogger = $tokenbaseLogger;

        parent::__construct(
            $context,
            $layoutFactory,
            $paymentMethodFactory,
            $appEmulation,
            $paymentConfig,
            $initialConfig
        );
    }

    /**
     * Return active payment methods (if any) implementing tokenbase.
     *
     * @return array
     */
    public function getActiveMethods()
    {
        $methods = [];

        foreach ($this->getPaymentMethods() as $code => $data) {
            if (isset($data['group']) && $data['group'] == 'tokenbase'
                && isset($data['active']) && $data['active'] == 1) {
                $methods[] = $code;
            }
        }

        return $methods;
    }

    /**
     * Return all tokenbase-derived payment methods, without an active check.
     *
     * @return array
     */
    public function getAllMethods()
    {
        $methods = [];

        foreach ($this->getPaymentMethods() as $code => $data) {
            if (isset($data['group']) && $data['group'] == 'tokenbase') {
                $methods[] = $code;
            }
        }

        return $methods;
    }

    /**
     * Return store scope based on the available info... the admin panel makes this complicated.
     *
     * @return int
     */
    public function getCurrentStoreId()
    {
        if (!$this->getIsFrontend()) {
            if ($this->registry->registry('current_order') != null) {
                return $this->registry->registry('current_order')->getStoreId();
            } elseif ($this->registry->registry('current_customer') != null) {
                $storeId = $this->registry->registry('current_customer')->getStoreId();

                // Customers registered through the admin will have store_id=0 with a valid website_id. Try to use that.
                if ($storeId < 1) {
                    $websiteId  = $this->registry->registry('current_customer')->getWebsiteId();
                    $website    = $this->websiteFactory->create();
                    $store      = $website->load($websiteId)->getDefaultStore();

                    if ($store instanceof \Magento\Store\Model\Store) {
                        $storeId = $store->getId();
                    }
                }

                return $storeId;
            } elseif ($this->registry->registry('current_invoice') != null) {
                return $this->registry->registry('current_invoice')->getStoreId();
            } elseif ($this->registry->registry('current_creditmemo') != null) {
                return $this->registry->registry('current_creditmemo')->getStoreId();
            } else {
                // Don't like to use the object manager directly but this is how the core does it.
                // @see \Magento\Sales\Controller\Adminhtml\Order\Create::_getSession()

                /** @var \Magento\Backend\Model\Session\Quote $backendSession */
                $backendSession = $this->objectManager->get('Magento\Backend\Model\Session\Quote');

                if ($backendSession->getStoreId() > 0) {
                    return $backendSession->getStoreId();
                } else {
                    return 0;
                }
            }
        }

        return $this->storeManager->getStore()->getId();
    }

    /**
     * Return current customer based on the available info.
     *
     * @return \Magento\Customer\Model\Customer|null
     */
    public function getCurrentCustomer()
    {
        if (!is_null($this->currentCustomer)) {
            return $this->currentCustomer;
        }
        
        $customer = $this->customerFactory->create();

        if (!$this->getIsFrontend()) {
            if ($this->registry->registry('current_order') != null) {
                $customer->load($this->registry->registry('current_order')->getCustomerId());
            } elseif ($this->registry->registry('current_customer') != null) {
                $customer = $this->registry->registry('current_customer');
            } elseif ($this->registry->registry('current_invoice') != null) {
                $customer->load($this->registry->registry('current_invoice')->getCustomerId());
            } elseif ($this->registry->registry('current_creditmemo') != null) {
                $customer->load($this->registry->registry('current_creditmemo')->getCustomerId());
            } else {
                // Don't like to use the object manager directly but this is how the core does it.
                // We don't necessarily want to inject it since that would initialize the session every time.
                // @see \Magento\Sales\Controller\Adminhtml\Order\Create::_getSession()

                /** @var \Magento\Backend\Model\Session\Quote $backendSession */
                $backendSession = $this->objectManager->get('Magento\Backend\Model\Session\Quote');

                if ($backendSession->hasQuoteId()) {
                    if ($backendSession->getQuote()->getCustomerId() > 0) {
                        $customer->load($backendSession->getQuote()->getCustomerId());
                    } elseif ($backendSession->getQuote()->getCustomerEmail() != '') {
                        $customer->setData('email', $backendSession->getQuote()->getCustomerEmail());
                    }
                }
            }
        } elseif ($this->registry->registry('current_customer') != null) {
            $customer = $this->registry->registry('current_customer');
        } else {
            // We don't necessarily want to inject this since that would initialize the session every time.
            $customerSession = $this->objectManager->get('Magento\Customer\Model\Session');
            if ($customerSession->getCustomerId() > 0) {
                $customer->load($customerSession->getCustomerId());
            } else {
                $customer = null;
            }
        }

        $this->currentCustomer = $customer;

        return $this->currentCustomer;
    }

    /**
     * Return active card model for edit (if any).
     *
     * @param string|null $method
     * @return \ParadoxLabs\TokenBase\Model\Card
     */
    public function getActiveCard($method = null)
    {
        $method = is_null($method) ? $this->registry->registry('tokenbase_method') : $method;

        if (is_null($this->card)) {
            if ($this->registry->registry('active_card')) {
                $this->card = $this->registry->registry('active_card');
            } else {
                $this->card = $this->cardFactory->create();
                $this->card->setMethod($method);

                /**
                 * Make sure we have the derivative card object for purposes of gateway syncing, etc.
                 */
                $this->card = $this->card->getTypeInstance();
            }

            /**
             * Import prior form data from the session, if possible.
             */
            if ($this->getIsFrontend()) {
                $session = $this->objectManager->get('Magento\Customer\Model\Session');
                if ($session->hasTokenbaseFormData()) {
                    $data = $session->getTokenbaseFormData(true);

                    // TODO: this bit here
                }
            }
        }

        return $this->card;
    }

    /**
     * Get stored cards for the currently-active method.
     *
     * @param string|null $method
     * @return \ParadoxLabs\TokenBase\Model\Resource\Card\Collection|array
     */
    public function getActiveCustomerCardsByMethod($method = null)
    {
        $method = is_null($method) ? $this->registry->registry('tokenbase_method') : $method;

        if (!is_array($this->cards) || !isset($this->cards[ $method ])) {
            $this->_eventManager->dispatch(
                'tokenbase_before_load_active_cards',
                [
                    'method'    => $method,
                    'customer'  => $this->getCurrentCustomer(),
                ]
            );

            $this->cards[ $method ] = $this->cardCollectionFactory->create();

            if (!$this->getIsFrontend()) {
                /** @var \Magento\Backend\Model\Session\Quote $backendSession */
                $backendSession = $this->objectManager->get('Magento\Backend\Model\Session\Quote');

                if ($backendSession->hasQuoteId()
                    && $backendSession->getQuote()->getPayment()->getData('tokenbase_id') > 0
                    && !($this->registry->registry('current_customer') instanceof \Magento\Customer\Model\Customer)) {
                    $tokenbaseId = $backendSession->getQuote()->getPayment()->getData('tokenbase_id');

                    if ($this->getCurrentCustomer()->getId() > 0) {
                        // Manual select -- only because collections don't let us do the complex condition. (soz.)
                        $this->cards[$method]->getSelect()->where(
                            sprintf(
                                "(id='%s' and customer_id='%s') or (active=1 and customer_id='%s')",
                                $tokenbaseId,
                                $this->getCurrentCustomer()->getId(),
                                $this->getCurrentCustomer()->getId()
                            )
                        );
                    } else {
                        $this->cards[$method]->addFieldToFilter('id', $tokenbaseId);
                    }
                } else {
                    return [];
                }
            } elseif ($this->getCurrentCustomer()->getId() > 0) {
                $this->cards[ $method ]->addFieldToFilter('active', 1)
                                       ->addFieldToFilter('customer_id', $this->getCurrentCustomer()->getId());
            } else {
                return [];
            }

            if (!is_null($method)) {
                $this->cards[ $method ]->addFieldToFilter('method', $method);
                $this->cards[ $method ]->addFieldToFilter('payment_id', ['notnull' => true]);
                $this->cards[ $method ]->addFieldToFilter('payment_id', ['neq' => '']);
            }

            $this->_eventManager->dispatch(
                'tokenbase_after_load_active_cards',
                [
                    'method'    => $method,
                    'customer'  => $this->getCurrentCustomer(),
                    'cards'     => $this->cards[ $method ],
                ]
            );
        }

        return $this->cards[ $method ];
    }

    /**
     * Check whether we are in the frontend area.
     *
     * @return bool
     */
    public function getIsFrontend()
    {
        if ($this->appState->getAreaCode() == \Magento\Framework\App\Area::AREA_FRONTEND) {
            return true;
        }

        return false;
    }

    /**
     * Recursively cleanup array from objects
     *
     * @param $array
     * @return void
     */
    public function cleanupArray(&$array)
    {
        if (!$array) {
            return;
        }

        foreach ($array as $key => $value) {
            if (is_object($value)) {
                unset($array[ $key ]);
            } elseif (is_array($value)) {
                $this->cleanupArray($array[ $key ]);
            }
        }
    }

    /**
     * Write a message to the logs, nice and abstractly.
     *
     * @param string $code
     * @param mixed $message
     * @return $this
     */
    public function log($code, $message)
    {
        if (is_object($message)) {
            if ($message instanceof \Magento\Framework\Object) {
                $message = $message->getData();
                
                $this->cleanupArray($message);
            } else {
                $message = (array)$message;
            }
        }
        
        if (is_array($message)) {
            $message = print_r($message, 1);
        }

        $this->tokenbaseLogger->info(sprintf('[%s] %s', $code, $message));

        return $this;
    }
}
