<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sylius\Component\Core\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Cart\Model\Cart;
use Sylius\Component\Channel\Model\ChannelInterface as BaseChannelInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Inventory\Model\InventoryUnitInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Promotion\Model\CouponInterface as BaseCouponInterface;
use Sylius\Component\Promotion\Model\PromotionInterface as BasePromotionInterface;
use Sylius\Component\Customer\Model\CustomerInterface as BaseCustomerInterface;
use Webmozart\Assert\Assert;

/**
 * @author Paweł Jędrzejewski <pawel@sylius.org>
 * @author Michał Marcinkowski <michal.marcinkowski@lakion.com>
 */
class Order extends Cart implements OrderInterface
{
    /**
     * @var BaseCustomerInterface
     */
    protected $customer;

    /**
     * @var ChannelInterface
     */
    protected $channel;

    /**
     * @var AddressInterface
     */
    protected $shippingAddress;

    /**
     * @var AddressInterface
     */
    protected $billingAddress;

    /**
     * @var Collection|BasePaymentInterface[]
     */
    protected $payments;

    /**
     * @var Collection|ShipmentInterface[]
     */
    protected $shipments;

    /**
     * @var string
     */
    protected $currencyCode;

    /**
     * @var float
     */
    protected $exchangeRate = 1.0;

    /**
     * @var BaseCouponInterface
     */
    protected $promotionCoupon;

    /**
     * @var string
     */
    protected $checkoutState = OrderCheckoutStates::STATE_CART;

    /**
     * @var string
     */
    protected $paymentState = OrderPaymentStates::STATE_CART;

    /**
     * It depends on the status of all order shipments.
     *
     * @var string
     */
    protected $shippingState = OrderShippingStates::STATE_CART;

    /**
     * @var Collection|BasePromotionInterface[]
     */
    protected $promotions;

    public function __construct()
    {
        parent::__construct();

        $this->payments = new ArrayCollection();
        $this->shipments = new ArrayCollection();
        $this->promotions = new ArrayCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function getCustomer()
    {
        return $this->customer;
    }

    /**
     * {@inheritdoc}
     */
    public function setCustomer(BaseCustomerInterface $customer = null)
    {
        $this->customer = $customer;
    }

    /**
     * {@inheritdoc}
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * {@inheritdoc}
     */
    public function setChannel(BaseChannelInterface $channel = null)
    {
        $this->channel = $channel;
    }

    /**
     * {@inheritdoc}
     */
    public function getUser()
    {
        if (null === $this->customer) {
            return null;
        }

        return $this->customer->getUser();
    }

    /**
     * {@inheritdoc}
     */
    public function getShippingAddress()
    {
        return $this->shippingAddress;
    }

    /**
     * {@inheritdoc}
     */
    public function setShippingAddress(AddressInterface $address)
    {
        $this->shippingAddress = $address;
    }

    /**
     * {@inheritdoc}
     */
    public function getBillingAddress()
    {
        return $this->billingAddress;
    }

    /**
     * {@inheritdoc}
     */
    public function setBillingAddress(AddressInterface $address)
    {
        $this->billingAddress = $address;
    }

    /**
     * {@inheritdoc}
     */
    public function getCheckoutState()
    {
        return $this->checkoutState;
    }

    /**
     * {@inheritdoc}
     */
    public function setCheckoutState($checkoutState)
    {
        $this->checkoutState = $checkoutState;
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentState()
    {
        return $this->paymentState;
    }

    /**
     * {@inheritdoc}
     */
    public function setPaymentState($paymentState)
    {
        $this->paymentState = $paymentState;
    }

    /**
     * {@inheritdoc}
     */
    public function getItemUnits()
    {
        $units = new ArrayCollection();

        /** @var $item OrderItem */
        foreach ($this->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                $units->add($unit);
            }
        }

        return $units;
    }

    /**
     * {@inheritdoc}
     */
    public function getItemUnitsByVariant(ProductVariantInterface $variant)
    {
        return $this->getItemUnits()->filter(function (OrderItemUnitInterface $itemUnit) use ($variant) {
            return $variant === $itemUnit->getStockable();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getPayments()
    {
        return $this->payments;
    }

    /**
     * {@inheritdoc}
     */
    public function hasPayments()
    {
        return !$this->payments->isEmpty();
    }

    /**
     * {@inheritdoc}
     */
    public function addPayment(BasePaymentInterface $payment)
    {
        /** @var $payment PaymentInterface */
        if (!$this->hasPayment($payment)) {
            $this->payments->add($payment);
            $payment->setOrder($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removePayment(BasePaymentInterface $payment)
    {
        /** @var $payment PaymentInterface */
        if ($this->hasPayment($payment)) {
            $this->payments->removeElement($payment);
            $payment->setOrder(null);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasPayment(BasePaymentInterface $payment)
    {
        return $this->payments->contains($payment);
    }

    /**
     * {@inheritdoc}
     */
    public function getLastPayment($state = BasePaymentInterface::STATE_NEW)
    {
        if ($this->payments->isEmpty()) {
            return null;
        }

        $payment = $this->payments->filter(function (BasePaymentInterface $payment) use ($state) {
            return $payment->getState() === $state;
        })->last();

        return $payment !== false ? $payment : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getShipments()
    {
        return $this->shipments;
    }

    /**
     * {@inheritdoc}
     */
    public function hasShipments()
    {
        return !$this->shipments->isEmpty();
    }

    /**
     * {@inheritdoc}
     */
    public function addShipment(ShipmentInterface $shipment)
    {
        if (!$this->hasShipment($shipment)) {
            $shipment->setOrder($this);
            $this->shipments->add($shipment);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeShipment(ShipmentInterface $shipment)
    {
        if ($this->hasShipment($shipment)) {
            $shipment->setOrder(null);
            $this->shipments->removeElement($shipment);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasShipment(ShipmentInterface $shipment)
    {
        return $this->shipments->contains($shipment);
    }

    /**
     * @return null|BaseCouponInterface
     */
    public function getPromotionCoupon()
    {
        return $this->promotionCoupon;
    }

    /**
     * {@inheritdoc}
     */
    public function setPromotionCoupon(BaseCouponInterface $coupon = null)
    {
        $this->promotionCoupon = $coupon;
    }

    /**
     * {@inheritdoc}
     */
    public function getPromotionSubjectTotal()
    {
        return $this->getItemsTotal();
    }

    /**
     * {@inheritdoc}
     */
    public function getPromotionSubjectCount()
    {
        return $this->getTotalQuantity();
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrencyCode()
    {
        return $this->currencyCode;
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrencyCode($currencyCode)
    {
        Assert::string($currencyCode);

        $this->currencyCode = $currencyCode;
    }

    /**
     * {@inheritdoc}
     */
    public function getExchangeRate()
    {
        return $this->exchangeRate;
    }

    /**
     * {@inheritdoc}
     */
    public function setExchangeRate($exchangeRate)
    {
        $this->exchangeRate = (float) $exchangeRate;
    }

    /**
     * {@inheritdoc}
     */
    public function getShippingState()
    {
        return $this->shippingState;
    }

    /**
     * {@inheritdoc}
     */
    public function setShippingState($state)
    {
        $this->shippingState = $state;
    }

    /**
     * {@inheritdoc}
     */
    public function isBackorder()
    {
        foreach ($this->getItemUnits() as $itemUnit) {
            if (InventoryUnitInterface::STATE_BACKORDERED === $itemUnit->getInventoryState()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the last updated shipment of the order
     *
     * @return false|ShipmentInterface
     */
    public function getLastShipment()
    {
        if ($this->shipments->isEmpty()) {
            return false;
        }

        $last = $this->shipments->first();
        foreach ($this->shipments as $shipment) {
            if ($shipment->getUpdatedAt() > $last->getUpdatedAt()) {
                $last = $shipment;
            }
        }

        return $last;
    }

    /**
     * {@inheritdoc}
     */
    public function isInvoiceAvailable()
    {
        if (false !== $lastShipment = $this->getLastShipment()) {
            return in_array($lastShipment->getState(), [ShipmentInterface::STATE_RETURNED, ShipmentInterface::STATE_SHIPPED]);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function hasPromotion(BasePromotionInterface $promotion)
    {
        return $this->promotions->contains($promotion);
    }

    /**
     * {@inheritdoc}
     */
    public function addPromotion(BasePromotionInterface $promotion)
    {
        if (!$this->hasPromotion($promotion)) {
            $this->promotions->add($promotion);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removePromotion(BasePromotionInterface $promotion)
    {
        if ($this->hasPromotion($promotion)) {
            $this->promotions->removeElement($promotion);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPromotions()
    {
        return $this->promotions;
    }

    /**
     * Returns sum of neutral and non neutral tax adjustments on order and total tax of order items.
     *
     * {@inheritdoc}
     */
    public function getTaxTotal()
    {
        $taxTotal = 0;

        foreach ($this->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT) as $taxAdjustment) {
            $taxTotal += $taxAdjustment->getAmount();
        }
        foreach ($this->items as $item) {
            $taxTotal += $item->getTaxTotal();
        }

        return $taxTotal;
    }

    /**
     * Returns shipping fee together with taxes decreased by shipping discount.
     *
     * @return int
     */
    public function getShippingTotal()
    {
        $shippingTotal = $this->getAdjustmentsTotal(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        $shippingTotal += $this->getAdjustmentsTotal(AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT);
        $shippingTotal += $this->getAdjustmentsTotal(AdjustmentInterface::TAX_ADJUSTMENT);

        return $shippingTotal;
    }

    /**
     * Returns amount of order discount. Does not include order item and shipping discounts.
     *
     * @return int
     */
    public function getOrderPromotionTotal()
    {
        $orderPromotionTotal = 0;

        foreach ($this->items as $item) {
            $orderPromotionTotal += $item->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT);
        }

        return $orderPromotionTotal;
    }
}
