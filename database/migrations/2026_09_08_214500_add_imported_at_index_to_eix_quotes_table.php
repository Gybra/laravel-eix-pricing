<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('eix-pricing.database.connection'))
            ->table('eix_quotes', function (Blueprint $table): void {
                $table->index('imported_at');
            });
    }

    public function down(): void
    {
        Schema::connection(config('eix-pricing.database.connection'))
            ->table('eix_quotes', function (Blueprint $table): void {
                $table->dropIndex(['imported_at']);
            });
    }
};
