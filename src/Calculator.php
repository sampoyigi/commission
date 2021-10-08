<?php

namespace SamPoyigi\Commission;

use Closure;
use Illuminate\Database\Eloquent\Model;

class Calculator
{
    public $orderTotal;

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $order;

    /**
     * @var array|\Illuminate\Support\Collection
     */
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

    public function withRules($rules)
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
        if (is_array($rule))
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

        if (isset($rule->conditions))
            return $this->evalConditions($rule->conditions);

        if ($rule->type === 'below')
            return $this->orderTotal < $rule->total;

        if ($rule->type === 'above')
            return $this->orderTotal >= $rule->total;

        return TRUE;
    }

    protected function evalConditions($conditions)
    {
        return collect($conditions)
            ->sortBy('priority')
            ->every(function ($condition) {
                $attribute = array_get($condition, 'attribute');
                $operator = array_get($condition, 'operator');
                $conditionValue = mb_strtolower(trim(array_get($condition, 'value')));
                $modelValue = $this->getOrderAttribute($attribute);

                if ($operator == 'is')
                    return $modelValue == $conditionValue;

                if ($operator == 'is_not')
                    return $modelValue != $conditionValue;

                if ($operator == 'greater')
                    return $modelValue > $conditionValue;

                if ($operator == 'less')
                    return $modelValue < $conditionValue;

                if ($operator == 'contains')
                    return mb_strpos($modelValue, $conditionValue) !== FALSE;

                if ($operator == 'does_not_contain')
                    return mb_strpos($modelValue, $conditionValue) === FALSE;

                if ($operator == 'equals_or_greater')
                    return $modelValue >= $conditionValue;

                if ($operator == 'equals_or_less')
                    return $modelValue <= $conditionValue;

                return FALSE;
            });
    }

    protected function getOrderAttribute($attribute)
    {
        return mb_strtolower(trim($this->order->{$attribute}));
    }
}
