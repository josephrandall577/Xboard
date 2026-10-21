<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This fork's Snell type targets xboard-snell v2 exclusively.
        DB::table('v2_server')->where('type', 'snell')->chunkById(100, function ($servers) {
            foreach ($servers as $server) {
                $settings = json_decode($server->protocol_settings ?? '{}', true) ?? [];
                $settings['version'] = 6;
                $settings['mode'] = 'default';

                DB::table('v2_server')->where('id', $server->id)->update([
                    'protocol_settings' => json_encode($settings),
                ]);
            }
        });
    }

    public function down(): void
    {
        // The old modes cannot be reconstructed. Restore a pre-upgrade backup
        // when rolling back the panel together with the node software.
    }
};
