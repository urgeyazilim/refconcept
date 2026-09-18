<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the customer chose the photograph the design is drawn from, or we did.
 *
 * The room screen was asking them to choose, three buttons beside three photographs, and the
 * product owner's answer was the one they have given all along: "sen yapay zekâsın, en iyi
 * fotoğrafı sen bulacaksın — kullanıcıyı neden yoruyoruz". So the system picks, and the
 * button becomes an override rather than a question.
 *
 * A flag rather than a comparison, because "did somebody choose this" cannot be read off the
 * id: the same photograph might be the one we would have chosen anyway, and the next upload
 * must not quietly overrule a person who did choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->boolean('primary_is_manual')->default(false)->after('primary_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('primary_is_manual');
        });
    }
};
