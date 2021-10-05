<?php

namespace SamPoyigi\Commission;

use Closure;
use Illuminate\Database\Eloquent\Model;

class Manager
{
    public $orderTotal;

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $order;

    protected $rules;

    protected $orderTotalScope;

    protected $whenMatched;

    protected $beforeFilter;

    public static function on(Model $order)
    {
        $instance = new static;
        $instance->order = $order;

        return $instance;
    }

    public function withRules(array $rules)
    {
        $this->rules = $rules;

        return $this;
    }

    public function applyOrderTotalScope(Closure $callback)
    {
        $this->orderTotalScope = $callback;

        return $this;
    }

    public function whenMatched(Closure $callback)
    {
        $this->whenMatched = $callback;

        return $this;
    }

    public function beforeFilter(Closure $callback)
    {
        $this->beforeFilter = $callback;

        return $this;
    }

    public function calculate()
    {
        $this->calculateOrderTotal();

        return collect($this->rules)
            ->map(function ($rule) {
                return $this->processRule($rule);
            })
            ->filter(function ($rule) {
                return $this->filterRule($rule);
            })
            ->reduce(function ($commissionFee, $rule) {
                if ($this->whenMatched)
                    call_user_func($this->whenMatched, $rule, $this->orderTotal);

                return $commissionFee + $rule->calculatedFee;
            }, 0);
    }

    public function getOrderTotal()
    {
        return $this->orderTotal;
    }

    protected function calculateOrderTotal()
    {
        $query = $this->order->orderTotalsQuery()
            ->where('order_id', $this->order->getKey())
            ->where('code', 'subtotal');

        if ($this->orderTotalScope)
            call_user_func($this->orderTotalScope, $query);

        $this->orderTotal = $query->sum('value');
    }

    protected function processRule($rule)
    {
        $rule = (object)$rule;

        $fee = $rule->fee_type === 'percent'
            ? ($rule->fee / 100) * $this->orderTotal
            : $rule->fee;

        $rule->calculatedFee = number_format($fee, 2, '.', '');

        return $rule;
    }

    protected function filterRule($rule)
    {
        if ($this->beforeFilter AND call_user_func($this->beforeFilter, $rule, $this->orderTotal))
            return FALSE;

        if ($rule->type === 'below')
            return $this->orderTotal < $rule->total;

        if ($rule->type === 'above')
            return $this->orderTotal >= $rule->total;

        return TRUE;
    }
}
