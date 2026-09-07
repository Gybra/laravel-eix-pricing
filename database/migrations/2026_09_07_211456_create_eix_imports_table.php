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
            ->create('eix_imports', function (Blueprint $table): void {
                $table->id();
                $table->string('source')->unique();
                $table->string('status', 16)->index();
                $table->unsignedBigInteger('rows_imported')->default(0);
                $table->timestampTz('started_at', 3);
                $table->timestampTz('finished_at', 3)->nullable();
                $table->text('failure')->nullable();
                $table->timestampsTz(3);
            });
    }

    public function down(): void
    {
        Schema::connection(config('eix-pricing.database.connection'))
            ->dropIfExists('eix_imports');
    }
};
