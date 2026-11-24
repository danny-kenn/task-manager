<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // IANA name such as Africa/Nairobi. Null = not known yet, so the app default is used.
            $t->string('timezone', 64)->nullable()->after('avatar_color');
            // true = the person picked a zone in their profile, so device detection must never override it.
            $t->boolean('timezone_manual')->default(false)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['timezone', 'timezone_manual']));
    }
};
