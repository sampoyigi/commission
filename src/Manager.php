<?php

namespace SamPoyigi\Commission;

class Manager
{
    protected $addOrderTotal = TRUE;

    protected $orderTotalLabel;

    public function calculate($order, array $commissionRules)
    {
        $orderTotal = $this->getOrderTotal($order);

        $commissionFee = collect($commissionRules)
            ->map(function ($rule) use ($orderTotal) {
                return $this->processRule($rule, $orderTotal);
            })
            ->filter(function ($rule) use ($orderTotal, $order) {
                return $this->evalRule($rule, $orderTotal, $order);
            })
            ->reduce(function ($commissionFee, $rule) use ($order) {
                if ($this->addOrderTotal)
                    $this->addCommission($rule, $order);

                return $commissionFee + $rule->calculatedFee;
            }, 0);

        return [$orderTotal, $commissionFee];
    }

    protected function getOrderTotal($order)
    {
        return $order->orderTotalsQuery()
            ->where('order_id', $order->getKey())
            ->where('code', 'subtotal')
            ->sum('value');
    }

    protected function processRule($rule, $orderTotal)
    {
        $rule = (object)$rule;

        $fee = $rule->fee_type === 'percent'
            ? ($rule->fee / 100) * $orderTotal
            : $rule->fee;

        $rule->calculatedFee = number_format($fee, 2, '.', '');

        return $rule;
    }

    protected function evalRule($rule, $orderTotal, $order)
    {
        if (!empty($rule->order_type) AND $order->order_type !== $rule->order_type)
            return FALSE;

        if ($rule->type === 'below')
            return $orderTotal < $rule->total;

        if ($rule->type === 'above')
            return $orderTotal >= $rule->total;

        return TRUE;
    }

    protected function addCommission($rule, $order)
    {
        $titlePrefix = $rule->fee_type === 'percent'
            ? '%'.$rule->fee
            : currency_format($rule->fee);

        $order->addOrUpdateOrderTotal([
            'code' => 'commission',
            'title' => sprintf($this->orderTotalLabel, $titlePrefix),
            'value' => 0 - $rule->calculatedFee,
            'priority' => 9999,
            'is_summable' => FALSE,
        ]);
    }

    /**
     * @param string $orderTotalLabel
     * @return self
     */
    public function setOrderTotalLabel(string $orderTotalLabel)
    {
        $this->orderTotalLabel = $orderTotalLabel;

        return $this;
    }

    /**
     * @param bool $addOrderTotal
     * @return self
     */
    public function addOrderTotal(bool $addOrderTotal = TRUE)
    {
        $this->addOrderTotal = $addOrderTotal;

        return $this;
    }
}
