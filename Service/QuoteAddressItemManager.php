<?php
declare(strict_types=1);

namespace SocialApp\Customizer\Service;

use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to handle quote address item operations without duplicates
 * Direct fix for the problematic legacy observer code
 */
class QuoteAddressItemManager
{
    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        CartRepositoryInterface $quoteRepository,
        LoggerInterface $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
    }

    /**
     * Fixed version of the problematic observer execute method
     *
     * This is the direct fix for the original problem:
     * public function execute(EventObserver $observer)
     * {
     *     $order = $observer->getOrder();
     *     $quote = $this->_quoteRepository->get($order->getQuoteId());
     *     $quoteAddress = $quote->getShippingAddress();
     *     $items = $quote->getAllItems();
     *     foreach ($items as $key=>$quoteItem) {
     *         $quoteAddress->addItem($quoteItem, $quoteItem->getQty()); // PROBLEM: No duplicate check!
     *         ...
     *     }
     * }
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function processOrderQuoteItems($observer): void
    {
        $order = $observer->getOrder();
        $quote = $this->quoteRepository->get($order->getQuoteId());
        $quoteAddress = $quote->getShippingAddress();

        // getAll or getAllVisible
        $items = $quote->getAllItems();

        // Step 1: Grab all QuoteItems from the Quote and store it in an array
        $quoteItemsMap = [];
        foreach ($items as $quoteItem) {
            // Create unique key WITHOUT quantity (same product different qty = duplicate)
            $key = sprintf(
                '%s_%s',
                $quoteItem->getProductId(),
                $this->getOptionsHash($quoteItem)
            );
            $quoteItemsMap[$key] = $quoteItem;
        }

        // Get existing items in address to check for duplicates
        $existingKeys = [];
        foreach ($quoteAddress->getAllItems() as $addressItem) {
            // Get the quote item this address item was created from
            if ($addressItem->getQuoteItemId()) {
                $originalItem = $quote->getItemById($addressItem->getQuoteItemId());
                if ($originalItem) {
                    $key = sprintf(
                        '%s_%s',
                        $originalItem->getProductId(),
                        $this->getOptionsHash($originalItem)
                    );
                    $existingKeys[$key] = true;
                }
            }
        }

        // Add quote address items with duplicate prevention
        foreach ($quoteItemsMap as $key => $quoteItem) {
            // Step 2: Before $quoteAddress->addItem(), check if QuoteAddress already has that QuoteItem
            if (isset($existingKeys[$key])) {
                // Step 3: If that QuoteItem already exists, then do not add
                $this->logger->info('Prevented duplicate item addition', [
                    'product_id' => $quoteItem->getProductId(),
                    'sku' => $quoteItem->getSku(),
                    'qty' => $quoteItem->getQty()
                ]);
                continue;
            }

            // Only add if not a duplicate
            $quoteAddress->addItem($quoteItem, $quoteItem->getQty());

            // Mark as added
            $existingKeys[$key] = true;

            // Copy data from QI to QAI (as in original code)
            $quoteAddressItem = $quoteAddress->getItemByQuoteItemId($quoteItem->getId());
            if ($quoteAddressItem) {
                $quoteItemData = $quoteItem->getData();
                foreach ($quoteItemData as $k => $d) {
                    if (!empty($d) && !in_array($k, ['item_id', 'address_id', 'created_at', 'updated_at'])) {
                        $quoteAddressItem->setData($k, $d);
                    }
                }
                // Don't save individual items - causes performance issues
                // $quoteAddressItem->save();
            }
        }

        // Save once at the end instead of in the loop
        $quoteAddress->save();
    }

    /**
     * Get hash of product options for comparison
     *
     * @param mixed $item
     * @return string
     */
    private function getOptionsHash($item): string
    {
        $options = method_exists($item, 'getProductOptions') ? $item->getProductOptions() : [];

        if (empty($options) || !is_array($options)) {
            return '';
        }

        // Sort for consistent hashing
        ksort($options);

        return md5(serialize($options));
    }
}