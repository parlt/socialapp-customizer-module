<?php
declare(strict_types=1);

namespace SocialApp\Customizer\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use SocialApp\Customizer\Service\QuoteAddressItemManager;

/**
 * Observer to prevent duplicate items in quote address
 * Handles the problematic legacy code scenario
 */
class PreventQuoteAddressDuplicates implements ObserverInterface
{
    /**
     * @var QuoteAddressItemManager
     */
    private QuoteAddressItemManager $quoteAddressItemManager;

    /**
     * @param QuoteAddressItemManager $quoteAddressItemManager
     */
    public function __construct(
        QuoteAddressItemManager $quoteAddressItemManager
    ) {
        $this->quoteAddressItemManager = $quoteAddressItemManager;
    }

    /**
     * Execute observer
     * Delegates to service to handle the deduplication
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // Use the service to process items without duplicates
        $this->quoteAddressItemManager->processOrderQuoteItems($observer);
    }
}