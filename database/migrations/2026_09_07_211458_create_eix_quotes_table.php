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
            ->create('eix_quotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('import_id')
                    ->constrained('eix_imports')
                    ->cascadeOnDelete();
                $table->char('isin', 12)->unique();
                $table->decimal('bid', 20, 6);
                $table->decimal('ask', 20, 6);
                $table->decimal('price', 21, 7);
                $table->string('status', 8);
                $table->timestampTz('quoted_at', 3);
                $table->unsignedBigInteger('source_timestamp');
                $table->unsignedBigInteger('source_row');
                $table->timestampTz('imported_at', 3);

                $table->index('import_id');
            });
    }

    public function down(): void
    {
        Schema::connection(config('eix-pricing.database.connection'))
            ->dropIfExists('eix_quotes');
    }
};
