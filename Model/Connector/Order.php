<?php

namespace Dotdigitalgroup\Email\Model\Connector;

use Dotdigitalgroup\Email\Helper\Config;
use Dotdigitalgroup\Email\Logger\Logger;
use Dotdigitalgroup\Email\Model\Product\AttributeFactory;

/**
 * Transactional data for orders, including mapped custom order attributes to sync.
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Order
{
    /**
     * Order Increment ID.
     *
     * @var string
     */
    public $id;

    /**
     * Email address.
     *
     * @var string
     */
    public $email;

    /**
     * @var int
     */
    public $quoteId;

    /**
     * @var string
     */
    public $storeName;

    /**
     * @var string
     */
    public $purchaseDate;

    /**
     * @var array
     */
    public $deliveryAddress = [];

    /**
     * @var array
     */
    public $billingAddress = [];

    /**
     * @var array
     */
    public $products = [];

    /**
     * @var float
     */
    public $orderSubtotal;

    /**
     * @var float
     */
    public $discountAmount;

    /**
     * @var float
     */
    public $orderTotal;

    /**
     * Payment name.
     *
     * @var string
     */
    public $payment;

    /**
     * @var string
     */
    public $deliveryMethod;

    /**
     * @var float
     */
    public $deliveryTotal;

    /**
     * @var string
     */
    public $currency;

    /**
     * @var object
     */
    public $couponCode;

    /**
     * @var array
     */
    public $custom = [];

    /**
     * @var string
     */
    public $orderStatus;

    /**
     * @var \Dotdigitalgroup\Email\Helper\Data
     */
    private $helper;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    private $customerFactory;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    private $productFactory;

    /**
     * @var KeyValidator
     */
    private $validator;

    /**
     * @var AttributeFactory $attributeHandler
     */
    private $attributeHandler;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * Order constructor.
     *
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \Dotdigitalgroup\Email\Helper\Data $helperData
     * @param KeyValidator $validator
     * @param AttributeFactory $attributeHandler
     * @param Logger $logger
     */
    public function __construct(
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Dotdigitalgroup\Email\Helper\Data $helperData,
        KeyValidator $validator,
        AttributeFactory $attributeHandler,
        Logger $logger
    ) {
        $this->productFactory = $productFactory;
        $this->customerFactory = $customerFactory;
        $this->helper = $helperData;
        $this->validator = $validator;
        $this->attributeHandler = $attributeHandler;
        $this->logger = $logger;
    }

    /**
     * Set the order data information.
     *
     * @param \Magento\Sales\Model\Order $orderData
     *
     * @return $this
     */
    public function setOrderData($orderData)
    {
        $this->id = $orderData->getIncrementId();
        $this->email = $orderData->getCustomerEmail();
        $this->quoteId = $orderData->getQuoteId();
        $this->storeName = $orderData->getStore()->getName();
        $this->purchaseDate = $orderData->getCreatedAt();
        $this->deliveryMethod = $orderData->getShippingDescription();
        $this->deliveryTotal = (float) number_format(
            (float) $orderData->getShippingAmount(),
            2,
            '.',
            ''
        );
        $this->currency = $orderData->getOrderCurrencyCode();

        /** @var \Magento\Sales\Api\Data\OrderPaymentInterface|\Magento\Payment\Model\InfoInterface $payment */
        $payment = $orderData->getPayment();
        if ($payment) {
            if ($payment->getMethod()) {
                $methodInstance = $payment->getMethodInstance();
                if ($methodInstance) {
                    $this->payment = $methodInstance->getTitle();
                }
            }
        }

        $this->couponCode = $orderData->getCouponCode();

        /*
         * custom order attributes
         */
        $customAttributes = $this->getConfigSelectedCustomOrderAttributes(
            $orderData->getStore()->getWebsiteId()
        );

        if ($customAttributes) {
            $fields = $this->helper->getOrderTableDescription();
            $this->custom = [];
            foreach ($customAttributes as $customAttribute) {
                if (isset($fields[$customAttribute])) {
                    $field = $fields[$customAttribute];
                    $value = $this->_getCustomAttributeValue(
                        $field,
                        $orderData
                    );
                    if ($value) {
                        $this->_assignCustom($field, $value);
                    }
                }
            }
        }

        /*
         * Billing address.
         */
        $this->processBillingAddress($orderData);

        /*
         * Shipping address.
         */
        $this->processShippingAddress($orderData);

        $syncCustomOption = $this->helper->getWebsiteConfig(
            Config::XML_PATH_CONNECTOR_SYNC_ORDER_PRODUCT_CUSTOM_OPTIONS,
            $orderData->getStore()->getWebsiteId()
        );

        /*
         * Order items.
         */
        try {
            $this->processOrderItems($orderData, $syncCustomOption);
        } catch (\InvalidArgumentException $e) {
            $this->logger->debug(
                'Error processing items for order ID: ' . $orderData->getId(),
                [(string) $e]
            );
            $this->products = [];
        }

        $this->orderSubtotal = (float) number_format(
            (float) $orderData->getData('subtotal'),
            2,
            '.',
            ''
        );
        $this->discountAmount = (float) number_format(
            (float) $orderData->getData('discount_amount'),
            2,
            '.',
            ''
        );
        $orderTotal = abs(
            $orderData->getData('grand_total') - $orderData->getTotalRefunded()
        );
        $this->orderTotal = (float) number_format($orderTotal, 2, '.', '');
        $this->orderStatus = $orderData->getStatus();

        return $this;
    }

    /**
     * Process order billing address.
     *
     * @param \Magento\Sales\Model\Order $orderData
     *
     * @return void
     */
    private function processBillingAddress($orderData)
    {
        if ($billingAddress = $orderData->getBillingAddress()) {
            /** @var \Magento\Framework\Model\AbstractExtensibleModel $billingAddress */
            $billingData = $billingAddress->getData();

            $this->billingAddress = [
                'billing_address_1' => $this->_getStreet(
                    $billingData['street'],
                    1
                ),
                'billing_address_2' => $this->_getStreet(
                    $billingData['street'],
                    2
                ),
                'billing_city' => $billingData['city'],
                'billing_region' => $billingData['region'],
                'billing_country' => $billingData['country_id'],
                'billing_postcode' => $billingData['postcode'],
            ];
        }
    }

    /**
     * Process order shipping address.
     *
     * @param \Magento\Sales\Model\Order $orderData
     *
     * @return void
     */
    private function processShippingAddress($orderData)
    {
        if ($shippingAddress = $orderData->getShippingAddress()) {
            /** @var \Magento\Framework\Model\AbstractExtensibleModel $shippingAddress */
            $shippingData = $shippingAddress->getData();

            $this->deliveryAddress = [
                'delivery_address_1' => $this->_getStreet(
                    $shippingData['street'],
                    1
                ),
                'delivery_address_2' => $this->_getStreet(
                    $shippingData['street'],
                    2
                ),
                'delivery_city' => $shippingData['city'],
                'delivery_region' => $shippingData['region'],
                'delivery_country' => $shippingData['country_id'],
                'delivery_postcode' => $shippingData['postcode'],
            ];
        }
    }

    /**
     * Process order items.
     *
     * @param \Magento\Sales\Model\Order $orderData
     * @param boolean $syncCustomOption
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function processOrderItems($orderData, $syncCustomOption)
    {
        foreach ($orderData->getAllItems() as $productItem) {
            if ($productItem->getProduct() === null) {
                continue;
            }

            $isBundle = $isChildOfBundle = false;

            /**
             * We store data for configurable and bundle products, to be output alongside their children.
             * Configurable parents are not output in schema,
             * but bundle parents are.
             */
            if (in_array($productItem->getProduct()->getTypeId(), ['configurable', 'bundle'])) {
                unset($parentProductModel, $parentLineItem);
                $parentProductModel = $productItem->getProduct();
                $parentLineItem = $productItem;

                // Custom options stored against parent order items
                $customOptions = ($syncCustomOption) ? $this->_getOrderItemOptions($productItem) : [];

                // Define parent types for easy reference
                $isBundle = $productItem->getProduct()->getTypeId() === 'bundle';
                $isConfigurable = $productItem->getProduct()->getTypeId() === 'configurable';

                if ($isConfigurable) {
                    continue;
                }
            }

            if (empty($customOptions)) {
                $customOptions = ($syncCustomOption) ? $this->_getOrderItemOptions($productItem) : [];
            }

            if (isset($parentProductModel) &&
                isset($parentLineItem) &&
                $parentLineItem->getId() === $productItem->getParentItemId()) {
                $isChildOfBundle = $parentProductModel->getTypeId() === 'bundle';
                $productModel = $parentProductModel;
                $childProductModel = $productItem->getProduct();
            } else {
                $productModel = $productItem->getProduct();
                $childProductModel = null;
            }

            /**
             * Price
             */
            if (isset($parentLineItem) &&
                $parentLineItem->getId() === $productItem->getParentItemId() &&
                $parentLineItem->getProduct()->getTypeId() === 'configurable') {
                $price = $parentLineItem->getPrice();
            } else {
                $price = $productItem->getPrice();
            }

            if ($productModel) {
                /**
                 * Categories
                 */
                $productCat = $this->getCategoriesFromProductModel($productModel);

                $childCategories = isset($childProductModel) ?
                    $this->getCategoriesFromProductModel($childProductModel) : [];

                $this->mergeChildCategories($productCat, $childCategories);

                /**
                 * Product attributes
                 */
                $configAttributes = $this->getProductAttributesToSync($orderData->getStore()->getWebsite());

                $attributeSetName = $this->attributeHandler->create()
                    ->getAttributeSetName($productModel);

                $attributes = $this->processProductAttributes($configAttributes, $productModel);
                $childAttributes = $this->processProductAttributes($configAttributes, $childProductModel);

                /**
                 * Output
                 */
                $productData = [
                    'name' => $productItem->getName(),
                    'parent_name' => $isBundle ? '' : $productModel->getName(),
                    'sku' => $isBundle ? $productModel->getSku() : $productItem->getSku(),
                    'qty' => (int) number_format(
                        $productItem->getData('qty_ordered'),
                        2
                    ),
                    'price' => (float) number_format(
                        $price,
                        2,
                        '.',
                        ''
                    ),
                    'attribute-set' => $attributeSetName,
                    'categories' => $productCat
                ];
                if ($configAttributes && $attributes && $attributes->hasValues()) {
                    $productData['product_attributes'] = $attributes;
                }
                if ($configAttributes && $childAttributes && $childAttributes->hasValues()) {
                    $productData['child_product_attributes'] = $childAttributes;
                }
                if ($customOptions) {
                    $productData['custom-options'] = $customOptions;
                }

                if ($isChildOfBundle) {
                    end($this->products);
                    $lastKey = key($this->products);
                    $this->products[$lastKey]['sub_items'][] = $productData;
                } else {
                    $this->products[] = $productData;
                }

            } else {
                // when no product information is available limit to this data
                $productData = [
                    'name' => $productItem->getName(),
                    'sku' => $productItem->getSku(),
                    'qty' => (int) number_format(
                        $productItem->getData('qty_ordered'),
                        2
                    ),
                    'price' => (float) number_format(
                        $price,
                        2,
                        '.',
                        ''
                    ),
                    'attribute-set' => '',
                    'categories' => [],
                ];
                if ($customOptions) {
                    $productData['custom-options'] = $customOptions;
                }
                $this->products[] = $productData;
            }

            unset($customOptions);
        }
    }

    /**
     * Get the street name by line number.
     *
     * @param string $street
     * @param int $line
     *
     * @return string
     */
    public function _getStreet($street, $line)
    {
        $street = explode("\n", $street);
        if ($line == 1) {
            return $street[0];
        }
        if (isset($street[$line - 1])) {
            return $street[$line - 1];
        } else {
            return '';
        }
    }

    /**
     * Get attribute value for the field.
     *
     * @param array $field
     * @param \Magento\Sales\Model\Order $orderData
     *
     * @return float|int|null|string
     */
    public function _getCustomAttributeValue($field, $orderData)
    {
        $type = $field['DATA_TYPE'];

        $function = 'get';
        $exploded = explode('_', $field['COLUMN_NAME']);
        foreach ($exploded as $one) {
            $function .= ucfirst($one);
        }

        $value = null;
        try {
            switch ($type) {
                case 'int':
                case 'smallint':
                    $value = (int)$orderData->$function();
                    break;

                case 'decimal':
                    $value = (float) number_format(
                        $orderData->$function(),
                        2,
                        '.',
                        ''
                    );
                    break;

                case 'timestamp':
                case 'datetime':
                case 'date':
                    $value = null;
                    if ($orderData->$function() !== null) {
                        $date = new \DateTime($orderData->$function());
                        $value = $date->format(\DateTime::ATOM);
                    }
                    break;

                default:
                    $value = $orderData->$function();
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                'Error processing custom attribute values in order ID: ' . $orderData->getId(),
                [(string) $e]
            );
        }

        return $value;
    }

    /**
     * Create property on runtime.
     *
     * @param string $field
     * @param mixed $value
     *
     * @return void
     */
    private function _assignCustom($field, $value)
    {
        $this->custom[$field['COLUMN_NAME']] = $value;
    }

    /**
     * Process options for each order item.
     *
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @return array
     */
    private function _getOrderItemOptions($orderItem)
    {
        $orderItemOptions = $orderItem->getProductOptions();

        //if product doesn't have options
        if (!array_key_exists('options', $orderItemOptions)) {
            return [];
        }

        $orderItemOptions = $orderItemOptions['options'];

        //if product options isn't array
        if (!is_array($orderItemOptions)) {
            return [];
        }

        $options = [];

        foreach ($orderItemOptions as $orderItemOption) {
            if (array_key_exists('value', $orderItemOption) &&
                array_key_exists('label', $orderItemOption)
            ) {
                $label = $this->validator->cleanLabel(
                    $orderItemOption['label'],
                    '-',
                    '-',
                    isset($orderItemOption['option_id']) ? $orderItemOption['option_id'] : null
                );
                if (empty($label)) {
                    continue;
                }
                $options[][$label] = $orderItemOption['value'];
            }
        }

        return $options;
    }

    /**
     * Look up selected attributes in config.
     *
     * @param \Magento\Store\Model\Website $website
     * @return array|bool
     */
    private function getProductAttributesToSync($website)
    {
        $configAttributes = $this->attributeHandler->create()
            ->getConfigAttributesForSync($website);

        if (!$configAttributes) {
            return false;
        }

        return explode(',', $configAttributes);
    }

    /**
     * Process product attributes.
     *
     * @param array $configAttributes
     * @param \Magento\Catalog\Model\Product $product
     * @return \Dotdigitalgroup\Email\Model\Product\Attribute|null
     */
    private function processProductAttributes($configAttributes, $product)
    {
        if (!$configAttributes || !$product) {
            return null;
        }
        $attributeModel = $this->attributeHandler->create();
        $attributesFromAttributeSet = $attributeModel->getAttributesArray(
            $product->getAttributeSetId()
        );

        return $attributeModel->processConfigAttributes(
            $configAttributes,
            $attributesFromAttributeSet,
            $product
        );
    }

    /**
     * Load a product's categories.
     *
     * @param \Magento\Catalog\Model\Product $productModel
     * @return array
     */
    private function getCategoriesFromProductModel($productModel)
    {
        $categoryCollection = $productModel->getCategoryCollection();
        /** @var \Magento\Eav\Model\Entity\Collection\AbstractCollection $categoryCollection */
        $categoryCollection->addAttributeToSelect('name');

        $productCat = [];
        foreach ($categoryCollection as $cat) {
            $categories = [];
            $categories[] = $cat->getName();
            $productCat[]['Name'] = mb_substr(
                implode(', ', $categories),
                0,
                \Dotdigitalgroup\Email\Helper\Data::DM_FIELD_LIMIT
            );
        }
        return $productCat;
    }

    /**
     * Merge child categories.
     *
     * @param array $productCat
     * @param array $childCategories
     */
    private function mergeChildCategories(&$productCat, $childCategories)
    {
        foreach ($childCategories as $childCategory) {
            if (!in_array($childCategory, $productCat)) {
                array_push($productCat, $childCategory);
            }
        }
    }

    /**
     * Get array of custom attributes for orders from config.
     *
     * @param int $websiteId
     *
     * @return array|bool
     */
    private function getConfigSelectedCustomOrderAttributes($websiteId)
    {
        $customAttributes = $this->helper->getWebsiteConfig(
            Config::XML_PATH_CONNECTOR_CUSTOM_ORDER_ATTRIBUTES,
            $websiteId
        );
        return $customAttributes ? explode(',', $customAttributes) : false;
    }
}
