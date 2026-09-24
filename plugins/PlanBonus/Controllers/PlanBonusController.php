<?php

namespace Plugin\PlanBonus\Controllers;

use App\Exceptions\ApiException;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PlanBonusController extends Controller
{
    /**
     * 赠送管理页面（/plan-bonus）
     */
    public function page()
    {
        return view('PlanBonus::manage', [
            'securePath' => admin_setting('secure_path', admin_setting('frontend_admin_path', '')),
        ]);
    }

    /**
     * 套餐列表（含价格与赠送配置），供管理页渲染
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::orderBy('sort')
            ->get(['id', 'name', 'prices', 'bonuses', 'show', 'sell', 'renew'])
            ->map(fn(Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'prices' => $plan->prices ?? [],
                'bonuses' => $plan->bonuses ?? [],
                'show' => (bool) $plan->show,
                'sell' => (bool) $plan->sell,
                'renew' => (bool) $plan->renew,
            ])
            ->values();

        return response()->json([
            'data' => [
                'plans' => $plans,
                'periods' => array_intersect_key(
                    Plan::getAvailablePeriods(),
                    array_flip(Plan::getBonusablePeriods())
                ),
                'max_bonus_months' => Plan::MAX_BONUS_MONTHS,
            ],
        ]);
    }

    /**
     * 保存指定套餐的赠送配置
     * bonuses 传 null 或空数组 = 清空（活动下线）
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => 'required|integer|exists:v2_plan,id',
            'bonuses' => 'nullable|array',
            'bonuses.*' => 'nullable|integer|min:0|max:' . Plan::MAX_BONUS_MONTHS,
        ], [
            'bonuses.*.max' => '赠送月数不能超过' . Plan::MAX_BONUS_MONTHS . '个月',
        ]);

        // 合法周期白名单校验（非法周期直接报错，不静默丢弃）
        foreach (array_keys($data['bonuses'] ?? []) as $period) {
            if (!Plan::isBonusablePeriod($period)) {
                throw new ApiException("该订阅周期不支持赠送时长: {$period}");
            }
        }

        $plan = Plan::findOrFail($data['plan_id']);
        $plan->update([
            'bonuses' => Plan::cleanBonusesConfig($data['bonuses'] ?? null),
        ]);

        return response()->json([
            'data' => [
                'id' => $plan->id,
                'bonuses' => $plan->bonuses ?? [],
            ],
        ]);
    }
}
