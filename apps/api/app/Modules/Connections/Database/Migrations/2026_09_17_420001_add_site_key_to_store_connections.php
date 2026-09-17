<?php

use App\Modules\Connections\Support\SiteKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            // Public: the storefront widget sends it with every request. Derived from the token.
            $table->string('site_key', 32)->nullable()->unique();
        });

        foreach (DB::table('store_connections')->get(['id', 'access_token']) as $row) {
            try {
                $token = Crypt::decryptString($row->access_token);
            } catch (Throwable) {
                continue;
            }

            DB::table('store_connections')->where('id', $row->id)->update(['site_key' => SiteKeys::site($token)]);
        }
    }

    public function down(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropUnique(['site_key']);
            $table->dropColumn('site_key');
        });
    }
};
