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

namespace ParadoxLabs\TokenBase\Model;

/**
 * Payment record storage
 */
class Card extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'tokenbase_card';

    /**
     * @var null|array
     */
    protected $address      = null;

    /**
     * @var null|array
     */
    protected $additional   = null;

    /**
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Payment\Helper\Data
     */
    protected $paymentHelper;
    
    /**
     * @var \ParadoxLabs\TokenBase\Model\AbstractMethod
     */
    protected $method;
    
    /**
     * @var \ParadoxLabs\Tokenbase\Model\Resource\Card\CollectionFactory
     */
    protected $cardCollectionFactory;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerFactory;
    
    /**
     * @var \Magento\Sales\Model\Resource\Order\CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Model\Resource\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \Magento\Payment\Helper\Data $paymentHelper
     * @param \ParadoxLabs\Tokenbase\Model\Resource\Card\CollectionFactory $cardCollectionFactory
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \Magento\Sales\Model\Resource\Order\CollectionFactory $orderCollectionFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\Resource\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        \ParadoxLabs\TokenBase\Helper\Data $helper,
        \Magento\Payment\Helper\Data $paymentHelper,
        \ParadoxLabs\Tokenbase\Model\Resource\Card\CollectionFactory $cardCollectionFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Sales\Model\Resource\Order\CollectionFactory $orderCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        
        $this->helper                   = $helper;
        $this->paymentHelper            = $paymentHelper;
        $this->customerFactory          = $customerFactory;
        $this->cardCollectionFactory    = $cardCollectionFactory;
        $this->orderCollectionFactory   = $orderCollectionFactory;
    }

    /**
     * Model construct that should be used for object initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('ParadoxLabs\Tokenbase\Model\Resource\Card');
    }

    /**
     * Set the method instance for this card. This is often necessary to route card data properly.
     *
     * @param AbstractMethod $method
     * @return $this
     */
    public function setMethodInstance( AbstractMethod $method )
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Get the arbitrary method instance.
     *
     * @return AbstractMethod Gateway-specific payment method
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMethodInstance()
    {
        if( is_null( $this->method ) ) {
            if( $this->hasData('method') ) {
                $this->paymentHelper->getMethodInstance( $this->getData('method') );
            }
            else {
                throw new \UnexpectedValueException('Payment method is unknown for the current card.');
            }
        }

        return $this->method;
    }

    /**
     * Get the arbitrary type instance for this card.
     * Response will extend ParadoxLabs_TokenBase_Model_Card.
     */
    public function getTypeInstance()
    {
        // TODO: how am I supposed to do this?!
//        if( is_null( $this->_instance ) ) {
//            if( $this->hasMethod() ) {
//                $this->_instance = Mage::getModel( $this->getMethod() . '/card' );
//                $this->_instance->setData( $this->getData() );
//            }
//            else {
//                return $this;
//            }
//        }
//
//        return $this->_instance;
    }

    /**
     * Set the customer account (if any) for the card.
     * 
     * @param \Magento\Customer\Model\Customer $customer
     * @param \Magento\Payment\Model\InfoInterface|null $payment
     * @return $this
     */
    public function setCustomer( \Magento\Customer\Model\Customer $customer, \Magento\Payment\Model\InfoInterface $payment=null )
    {
        /** @var \Magento\Payment\Model\Info $payment */
        if( $customer->getEmail() != '' ) {
            $this->setCustomerEmail( $customer->getEmail() );

            /**
             * Make an ID if we don't have one (and hope this doesn't break anything)
             */
            if( $customer->getId() < 1 ) {
                $customer->save();
            }

            $this->setCustomerId( $customer->getId() );

            parent::setData( 'customer', $customer );
        }
        elseif( !is_null( $payment ) ) {
            $model = null;

            /**
             * If we have no email, try to find it from current scope data.
             */
            if( $payment->hasData('quote') != null && $payment->getData('quote')->getBillingAddress() != null && $payment->getData('quote')->getBillingAddress()->getCustomerEmail() != '' ) {
                $model = $payment->getData('quote');
            }
            elseif( $payment->hasData('order') != null && ( $payment->getData('order')->getCustomerEmail() != '' || ( $payment->getData('order')->getBillingAddress() != null && $payment->getData('order')->getBillingAddress()->getCustomerEmail() != '' ) ) ) {
                $model = $payment->getData('order');
            }
            else {
                /**
                 * This will fall back to checkout/session if onepage has no quote loaded.
                 * Should work for all checkouts that use normal Magento processes.
                 */
                // TODO: this?
//                $model = Mage::getSingleton('checkout/type_onepage')->getQuote();
            }

            if( !is_null( $model ) ) {
                if( $model->getCustomerEmail() == '' && $model->getBillingAddress() instanceof \Magento\Framework\Object && $model->getBillingAddress()->getEmail() != '' ) {
                    $model->setCustomerEmail( $model->getBillingAddress()->getEmail() );
                }

                if( $model->hasEmail() ) {
                    $this->setCustomerEmail( $model->getEmail() );
                }
                elseif( $model->hasCustomerEmail() ) {
                    $this->setCustomerEmail( $model->getCustomerEmail() );
                }

                $this->setCustomerId( intval( $model->getCustomerId() ) );
            }
        }

        return $this;
    }

    /**
     * Get the customer object (if any) for the card.
     * 
     * @return \Magento\Customer\Model\Customer
     */
    public function getCustomer()
    {
        if( $this->hasData('customer') ) {
            return parent::getData('customer');
        }

        /** @var \Magento\Customer\Model\Customer $customer */
        $customer = $this->customerFactory->create();

        if( $this->getData('customer_id') > 0 ) {
            $customer->load( $this->getData('customer_id') );
        }
        else {
            $customer->setData( 'email', $this->getData('customer_email') );
        }

        parent::setData( 'customer', $customer );

        return $customer;
    }

    /**
     * Set card payment data from a quote or order payment instance.
     * 
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    public function importPaymentInfo( \Magento\Payment\Model\InfoInterface $payment )
    {
        if( $payment instanceof \Magento\Payment\Model\InfoInterface ) {
            /** @var \Magento\Payment\Model\Info $payment */
            if( $payment->getAdditionalInformation('save') === 0 ) {
                $this->setData( 'active', 0 );
            }

            if( $payment->getData('cc_type') != '' ) {
                $this->setAdditional( 'cc_type', $payment->getData('cc_type') );
            }

            if( $payment->getData('cc_last4') != '' ) {
                $this->setAdditional( 'cc_last4', $payment->getData('cc_last4') );
            }

            if( $payment->getData('cc_exp_year') > date('Y') || ( $payment->getData('cc_exp_year') == date('Y') && $payment->getData('cc_exp_month') >= date('n') ) ) {
                $this->setAdditional( 'cc_exp_year', $payment->getData('cc_exp_year') )
                    ->setAdditional( 'cc_exp_month', $payment->getData('cc_exp_month') )
                    ->setData( 'expires', sprintf( "%s-%s-%s 23:59:59", $payment->getData('cc_exp_year'), $payment->getData('cc_exp_month'), date( 't', strtotime( $payment->getData('cc_exp_year') . '-' . $payment->getData('cc_exp_month') ) ) ) );
            }

            $this->setData( 'info_instance', $payment );

            if( $this->getMethodInstance()->hasData('info_instance') !== true ) {
                $this->getMethodInstance()->setInfoInstance( $payment );
            }
        }

        return $this;
    }

    /**
     * Check whether customer has permission to use/modify this card. Guests, never.
     * 
     * @param int $customerId
     * @return bool
     */
    public function hasOwner( $customerId )
    {
        $customerId = intval( $customerId );

        if( $customerId < 1 ) {
            return false;
        }

        return ( $this->getData('customer_id') == $customerId ? true : false );
    }

    /**
     * Check if card is connected to any pending orders.
     * 
     * @return bool
     */
    public function isInUse()
    {
        $orders	= $this->orderCollectionFactory->create();
        $orders->addAttributeToSelect( '*' )
               ->addAttributeToFilter( 'customer_id', $this->getData('customer_id') )
               ->addAttributeToFilter( 'status', array( 'like' => 'pending%' ) );

        if( count( $orders ) > 0 ) {
            foreach( $orders as $order ) {
                /** @var \Magento\Sales\Model\Order $order */
                $payment = $order->getPayment();

                if( $payment->getMethod() == $this->getData('method') && $payment->getData('tokenbase_id') == $this->getId() ) {
                    // If we found an order with this card that is not complete, closed, or canceled,
                    // it is still active and the payment ID is important. No editey.
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Change last_use date to the current time.
     */
    public function updateLastUse()
    {
        // TODO: Fix date source... ugh
//        $this->setData( 'last_use', new \Magento\Framework\Stdlib\DateTime()->formatDate(true) );

        return $this;
    }

    /**
     * Delete this card, or hide and queue for deletion after the refund period.
     * 
     * @return $this
     */
    public function queueDeletion()
    {
        $this->setData( 'active', 0 );
        
        return $this;
    }

    /**
     * Load card by security hash.
     *
     * @param $hash
     * @return $this
     */
    public function loadByHash( $hash )
    {
        /** @var \ParadoxLabs\TokenBase\Model\Resource\Card $resource */
        $resource = $this->_getResource();
        $resource->loadByHash( $this, $hash );

        return $this;
    }

    /**
     * Get billing address or some part thereof.
     * 
     * @param string $key
     * @return mixed|null
     */
    public function getAddress( $key='' )
    {
        if( is_null( $this->address ) ) {
            $this->address = unserialize( parent::getData('address') );
        }

        if( $key !== '' ) {
            return ( isset( $this->address[ $key ] ) ? $this->address[ $key ] : null );
        }

        return $this->address;
    }

    /**
     * Get additional card data.
     * If $key is set, will return that value or null;
     * otherwise, will return an array of all additional date.
     * 
     * @param string|null $key
     * @return mixed|null
     */
    public function getAdditional( $key=null )
    {
        if( is_null( $this->additional ) ) {
            $this->additional = unserialize( parent::getData('additional') );
        }

        if( !is_null( $key ) ) {
            return ( isset( $this->additional[ $key ] ) ? $this->additional[ $key ] : null );
        }

        return $this->additional;
    }

    /**
     * Set additional card data.
     * Can pass in a key-value pair to set one value,
     * or a single parameter (associative array) to overwrite all data.
     * 
     * @param string $key
     * @param string|null $value
     * @return $this
     */
    public function setAdditional( $key, $value=null )
    {
        if( !is_null( $value ) ) {
            if( is_null( $this->additional ) ) {
                $this->additional = array();
            }

            $this->additional[ $key ] = $value;
        }
        elseif( is_array( $key ) ) {
            $this->additional = $key;
        }

        return parent::setData( 'additional', serialize( $this->additional ) );
    }

    /**
     * Set the billing address for the card.
     * 
     * @param \Magento\Customer\Model\Address\AbstractAddress $address
     * @return $this
     */
    public function setAddress( \Magento\Customer\Model\Address\AbstractAddress $address )
    {
        $addressData = $address->getData();

        $this->helper->cleanupArray( $addressData );

        $this->address = null;

        return parent::setData( 'address', serialize( $addressData ) );
    }

    /**
     * Get customer email
     * 
     * @return string
     */
    public function getCustomerEmail()
    {
        return $this->getData('customer_email');
    }

    /**
     * Set customer email
     * 
     * @param string $email
     * @return $this
     */
    public function setCustomerEmail( $email )
    {
        return $this->setData( 'customer_email', $email );
    }

    /**
     * Get customer id
     *
     * @return int
     */
    public function getCustomerId()
    {
        return $this->getData('customer_id');
    }

    /**
     * Set customer id
     *
     * @param int $id
     * @return $this
     */
    public function setCustomerId( $id )
    {
        return $this->setData( 'customer_id', $id );
    }

    /**
     * Get customer ip
     *
     * @return string
     */
    public function getCustomerIp()
    {
        return $this->getData('customer_ip');
    }

    /**
     * Set customer ip
     *
     * @param string $ip
     * @return $this
     */
    public function setCustomerIp( $ip )
    {
        return $this->setData( 'customer_ip', $ip );
    }

    /**
     * Get profile id
     *
     * @return string
     */
    public function getProfileId()
    {
        return $this->getData('profile_id');
    }

    /**
     * Set profile id
     *
     * @param string $profileId
     * @return $this
     */
    public function setProfileId( $profileId )
    {
        return $this->setData( 'profile_id', $profileId );
    }

    /**
     * Get payment id
     *
     * @return string
     */
    public function getPaymentId()
    {
        return $this->getData('payment_id');
    }

    /**
     * Set payment id
     *
     * @param string $paymentId
     * @return $this
     */
    public function setPaymentId( $paymentId )
    {
        return $this->setData( 'payment_id', $paymentId );
    }

    /**
     * Get method code
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->getData('method');
    }

    /**
     * Set method code
     *
     * @param string $method
     * @return $this
     */
    public function setMethod( $method )
    {
        return $this->setData( 'method', $method );
    }

    /**
     * Get hash, generate if necessary
     *
     * @return string
     */
    public function getHash()
    {
        $hash = $this->getData('hash');
        
        if( empty( $hash ) ) {
            $hash = sha1( 'tokenbase' . time() . $this->getData('customer_id') . $this->getData('customer_email') . $this->getData('method') . $this->getData('profile_id') . $this->getData('payment_id') );
            
            $this->setHash( $hash );
        }
        
        return $hash;
    }

    /**
     * Set hash
     *
     * @param string $hash
     * @return $this
     */
    public function setHash( $hash )
    {
        return $this->setData( 'hash', $hash );
    }

    /**
     * Get active
     *
     * @return string
     */
    public function getActive()
    {
        return $this->getData('active');
    }

    /**
     * Set active
     *
     * @param int|bool $active
     * @return $this
     */
    public function setActive( $active )
    {
        return $this->setData( 'active', $active ? 1 : 0 );
    }

    /**
     * Get created at date
     *
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->getData('created_at');
    }

    /**
     * Set created at date
     *
     * @param $createdAt
     * @return $this
     */
    public function setCreatedAt( $createdAt )
    {
        return $this->setData( 'created_at', $createdAt );
    }

    /**
     * Get updated at date
     *
     * @return string
     */
    public function getUpdatedAt()
    {
        return $this->getData('updated_at');
    }

    /**
     * Set updated at date
     *
     * @param $updatedAt
     * @return $this
     */
    public function setUpdatedAt( $updatedAt )
    {
        return $this->setData( 'updated_at', $updatedAt );
    }

    /**
     * Get last use date
     *
     * @return string
     */
    public function getLastUse()
    {
        return $this->getData('last_use');
    }
    
    /**
     * Get expires
     *
     * @return string
     */
    public function getExpires()
    {
        return $this->getData('expires');
    }

    /**
     * Set expires
     *
     * @param string $expires
     * @return $this
     */
    public function setExpires( $expires )
    {
        return $this->setData( 'expires', $expires );
    }

    /**
     * Set last use date
     *
     * @param $lastUse
     * @return $this
     */
    public function setLastUse( $lastUse )
    {
        return $this->setData( 'last_use', $lastUse );
    }

    /**
     * Get card label (formatted number).
     * 
     * @return \Magento\Framework\Phrase|string
     */
    public function getLabel()
    {
        if( $this->getAdditional('cc_last4') ) {
            return __( 'XXXX-%s', $this->getAdditional('cc_last4') );
        }
        
        return '';
    }

    /**
     * Finalize before saving. Instances should sync with the gateway here.
     *
     * Set $this->_dataSaveAllowed to false or throw exception to abort.
     * 
     * @return $this
     */
    protected function _beforeSave()
    {
        /**
         * If the payment ID has changed, look for any duplicate payment records that might be stored.
         */
        if( $this->getOrigData('payment_id') != $this->getData('payment_id') ) {
            /** @var \ParadoxLabs\TokenBase\Model\Resource\Card\Collection $collection */
            $collection = $this->cardCollectionFactory->create();
            $collection->addFieldToFilter( 'method', $this->getData('method') )
                       ->addFieldToFilter( 'profile_id', $this->getData('profile_id') )
                       ->addFieldToFilter( 'payment_id', $this->getData('payment_id') )
                       ->addFieldToFilter( 'id', array( 'neq' => $this->getId() ) );
            
            /** @var \ParadoxLabs\TokenBase\Model\Card $dupe */
            $dupe = $collection->getFirstItem();
            
            /**
             * If we find a duplicate, switch to that one, but retain the current customer and active state.
             */
            if( $dupe && $dupe->getId() > 0 && $dupe->getId() != $this->getId() ) {
                $this->helper->log( $this->getData('method'), __( 'Merging duplicate payment data into card %s', $dupe->getId() ) );

                $customerId		= $this->getData('customer_id');
                $customerEmail	= $this->getData('customer_email');
                $active			= !is_null( $this->getData('active') ) ? $this->getData('active') : 1;

                $this->setData( $dupe->getData() )
                    ->setData( 'customer_id', $customerId )
                    ->setData( 'customer_email', $customerEmail )
                    ->setData( 'active', intval( $active ) )
                    ->isObjectNew( false );
            }
        }

        /**
         * If we are not admin, record current IP.
         */
//        if( Mage::app()->getStore()->isAdmin() == false ) {
//            $this->setCustomerIp( Mage::helper('core/http')->getRemoteAddr() );
//        }

        /**
         * Create unique hash for security purposes.
         */
        $this->getHash();

        /**
         * Update dates.
         */
        if( $this->isObjectNew() ) {
//            $this->setData( 'created_at', now() );
        }

//        $this->setData( 'updated_at', now() );

        return $this;
    }
}
