<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->json('bonuses')->nullable()->after('prices')
                ->comment('按周期赠送时长配置(月): {"yearly": 6} 表示买年付送6个月');
        });

        Schema::table('v2_order', function (Blueprint $table) {
            $table->unsignedSmallInteger('bonus_months')->default(0)->after('period')
                ->comment('赠送月数(下单时按套餐配置快照)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn('bonuses');
        });

        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn('bonus_months');
        });
    }
};
