<?php

namespace Dotdigitalgroup\Email\Model;

use Dotdigitalgroup\Email\Model\ResourceModel\Contact\CollectionFactory as ContactCollectionFactory;

class Contact extends \Magento\Framework\Model\AbstractModel
{
    const EMAIL_CONTACT_IMPORTED = 1;
    const EMAIL_CONTACT_NOT_IMPORTED = 0;
    const EMAIL_SUBSCRIBER_NOT_IMPORTED = 0;

    /**
     * @var ContactCollectionFactory
     */
    private $contactCollectionFactory;

    /**
     * @var ResourceModel\Contact
     */
    private $contactResource;

    /**
     * @var \Magento\Framework\Stdlib\DateTime
     */
    private $dateTime;

    /**
     * Contact constructor.
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param ResourceModel\Contact $contactResource
     * @param ContactCollectionFactory $contactCollectionFactory
     * @param \Magento\Framework\Stdlib\DateTime $dateTime
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Dotdigitalgroup\Email\Model\ResourceModel\Contact $contactResource,
        ContactCollectionFactory $contactCollectionFactory,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->dateTime = $dateTime;
        $this->contactCollectionFactory = $contactCollectionFactory;
        $this->contactResource = $contactResource;

        parent::__construct(
            $context,
            $registry,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Constructor.
     *
     * @return null
     */
    public function _construct()
    {
        $this->_init(\Dotdigitalgroup\Email\Model\ResourceModel\Contact::class);
    }

    /**
     * Prepare data to be saved to database.
     *
     * @return $this
     */
    public function beforeSave()
    {
        parent::beforeSave();
        if ($this->isObjectNew() && !$this->getCreatedAt()) {
            $this->setCreatedAt($this->dateTime->formatDate(true));
        }
        $this->setUpdatedAt($this->dateTime->formatDate(true));

        return $this;
    }

    /**
     * Load contact by customer id.
     *
     * @param int $customerId
     *
     * @return $this
     */
    public function loadByCustomerId($customerId)
    {
        $contact = $this->getCollection()
            ->loadByCustomerId($customerId);

        if ($contact) {
            return $contact;
        }

        return $this;
    }

    /**
     * Get all customer contacts not imported for a website.
     *
     * @param int $websiteId
     * @param int $pageSize
     *
     * @return \Dotdigitalgroup\Email\Model\ResourceModel\Contact\Collection
     */
    public function getContactsToImportForWebsite($websiteId, $pageSize = 100)
    {
        return $this->getCollection()
            ->getContactsToImportForWebsite($websiteId, $pageSize);
    }

    /**
     * Get missing contacts.
     *
     * @param int $websiteId
     * @param int $pageSize
     *
     * @return \Dotdigitalgroup\Email\Model\ResourceModel\Contact\Collection
     */
    public function getMissingContacts($websiteId, $pageSize = 100)
    {
        return $this->getCollection()
            ->getMissingContacts($websiteId, $pageSize);
    }

    /**
     * Load Contact by Email.
     *
     * @param string $email
     * @param int $websiteId
     *
     * @return $this
     */
    public function loadByCustomerEmail($email, $websiteId)
    {
        $customer = $this->getCollection()
            ->loadByCustomerEmail($email, $websiteId);

        if ($customer) {
            return $customer;
        } else {
            return $this->setEmail($email)
                ->setWebsiteId($websiteId);
        }
    }

    /**
     * Get all not imported guests for a website.
     *
     * @param \Magento\Store\Model\Website $website
     * @param boolean $onlySubscriber
     *
     * @return \Dotdigitalgroup\Email\Model\ResourceModel\Contact\Collection
     */
    public function getGuests($website, $onlySubscriber = false)
    {
        return $this->getCollection()
            ->getGuests($website->getId(), $onlySubscriber);
    }

    /**
     * Number contacts marked as imported.
     *
     * @return int
     */
    public function getNumberOfImportedContacts()
    {
        return $this->getCollection()
            ->getNumberOfImportedContacts();
    }

    /**
     * Get the number of customers for a website.
     *
     * @param int $websiteId
     *
     * @return int
     */
    public function getNumberCustomerContacts($websiteId = 0)
    {
        return $this->getCollection()
            ->getNumberCustomerContacts($websiteId);
    }

    /**
     * Get number of suppressed contacts as customer.
     *
     * @param int $websiteId
     *
     * @return int
     */
    public function getNumberCustomerSuppressed($websiteId = 0)
    {
        return $this->getCollection()
            ->getNumberCustomerSuppressed($websiteId);
    }

    /**
     * Get number of synced customers.
     *
     * @param int $websiteId
     *
     * @return int
     */
    public function getNumberCustomerSynced($websiteId = 0)
    {
        return $this->getCollection()
            ->getNumberCustomerSynced($websiteId);
    }

    /**
     * Get number of subscribers synced.
     *
     * @param int $websiteId
     *
     * @return int
     */
    public function getNumberSubscribersSynced($websiteId = 0)
    {
        return $this->getCollection()
            ->getNumberSubscribersSynced($websiteId);
    }

    /**
     * Get number of subscribers.
     *
     * @param int $websiteId
     *
     * @return int
     */
    public function getNumberSubscribers($websiteId = 0)
    {
        return $this->getCollection()
            ->getNumberSubscribers($websiteId);
    }

    /**
     * Mark contact for reimport.
     *
     * @param $customerId
     * @param $websiteId
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function setConnectorContactToReImport($customerId, $websiteId)
    {
        $contactModel = $this->contactCollectionFactory->create()
            ->loadByCustomerIdAndWebsiteId($customerId, $websiteId);

        if ($contactModel) {
            $contactModel->setEmailImported(
                \Dotdigitalgroup\Email\Model\Contact::EMAIL_CONTACT_NOT_IMPORTED
            );
            $this->contactResource->save($contactModel);
        }
    }

    /**
     * @param string|null $from
     * @param string|null $to
     * @return int
     */
    public function reset(string $from = null, string $to = null)
    {
        return $this->contactResource->resetAllContacts($from, $to);
    }
}
