<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlanSave extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'content' => 'nullable|string',
            'reset_traffic_method' => 'integer|nullable',
            'transfer_enable' => 'integer|required|min:1',
            'prices' => 'nullable|array',
            'prices.*' => 'nullable|numeric|min:0',
            'bonuses' => 'nullable|array',
            'bonuses.*' => 'nullable|integer|min:0|max:' . Plan::MAX_BONUS_MONTHS,
            'group_id' => 'integer|nullable',
            'speed_limit' => 'integer|nullable|min:0',
            'device_limit' => 'integer|nullable|min:0',
            'capacity_limit' => 'integer|nullable|min:0',
            'tags' => 'array|nullable',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePrices($validator);
            $this->validateBonuses($validator);
        });
    }

    /**
     * 验证价格配置
     */
    protected function validatePrices(Validator $validator): void
    {
        $prices = $this->input('prices', []);
        
        if (empty($prices)) {
            return;
        }

        // 获取所有有效的周期
        $validPeriods = array_keys(Plan::getAvailablePeriods());
        
        foreach ($prices as $period => $price) {
            // 验证周期是否有效
            if (!in_array($period, $validPeriods)) {
                $validator->errors()->add(
                    "prices.{$period}", 
                    "不支持的订阅周期: {$period}"
                );
                continue;
            }

            // 价格可以为 null、空字符串或大于 0 的数字
            if ($price !== null && $price !== '') {
                // 转换为数字进行验证
                $numericPrice = is_numeric($price) ? (float) $price : null;
                
                if ($numericPrice === null) {
                    $validator->errors()->add(
                        "prices.{$period}", 
                        "价格必须是数字格式"
                    );
                } elseif ($numericPrice < 0) {
                    $validator->errors()->add(
                        "prices.{$period}", 
                        "价格必须大于等于 0（如不需要此周期请留空）"
                    );
                }
            }
        }
    }

    /**
     * 验证赠送时长配置
     */
    protected function validateBonuses(Validator $validator): void
    {
        $bonuses = $this->input('bonuses', []);

        if (empty($bonuses)) {
            return;
        }

        foreach ($bonuses as $period => $months) {
            // 验证周期是否支持赠送时长
            if (!Plan::isBonusablePeriod($period)) {
                $validator->errors()->add(
                    "bonuses.{$period}",
                    "该订阅周期不支持赠送时长: {$period}"
                );
            }
        }
    }

    /**
     * 处理验证后的数据
     */
    protected function passedValidation(): void
    {
        // 清理和格式化价格数据
        $prices = $this->input('prices', []);
        $cleanedPrices = [];

        foreach ($prices as $period => $price) {
            // 只保留有效的正数价格
            if ($price !== null && $price !== '' && is_numeric($price)) {
                $numericPrice = (float) $price;
                if ($numericPrice > 0) {
                    // 转换为浮点数并保留两位小数
                    $cleanedPrices[$period] = round($numericPrice, 2);
                }
            }
        }

        // 更新请求中的价格数据
        $this->merge(['prices' => $cleanedPrices]);
    }

    /**
     * 获取验证后的数据
     * 注意：FormRequest::validated() 返回的是校验器快照数据，
     * passedValidation 中的 merge 不会反映到 validated()，因此 bonuses 清洗必须在此进行。
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated();

        // 仅当请求携带 bonuses 键时归一化（未携带则不干预，
        // 避免尚未适配的管理面板保存套餐时静默清空赠送配置；
        // 显式传 null 或空数组才会清空赠送，即活动下线）
        if (array_key_exists('bonuses', $validated)) {
            $validated['bonuses'] = Plan::cleanBonusesConfig($validated['bonuses']);
        }

        if ($key !== null) {
            return data_get($validated, $key, $default);
        }

        return $validated;
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => '套餐名称不能为空',
            'name.max' => '套餐名称不能超过 255 个字符',
            'transfer_enable.required' => '流量配额不能为空',
            'transfer_enable.integer' => '流量配额必须是整数',
            'transfer_enable.min' => '流量配额必须大于 0',
            'prices.array' => '价格配置格式错误',
            'prices.*.numeric' => '价格必须是数字',
            'prices.*.min' => '价格不能为负数',
            'bonuses.array' => '赠送时长配置格式错误',
            'bonuses.*.integer' => '赠送月数必须是整数',
            'bonuses.*.min' => '赠送月数不能为负数',
            'bonuses.*.max' => '赠送月数不能超过' . Plan::MAX_BONUS_MONTHS . '个月',
            'group_id.integer' => '权限组ID必须是整数',
            'speed_limit.integer' => '速度限制必须是整数',
            'speed_limit.min' => '速度限制不能为负数',
            'device_limit.integer' => '设备限制必须是整数',
            'device_limit.min' => '设备限制不能为负数',
            'capacity_limit.integer' => '容量限制必须是整数',
            'capacity_limit.min' => '容量限制不能为负数',
            'tags.array' => '标签格式必须是数组',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'data' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray()
            ], 422)
        );
    }
}
