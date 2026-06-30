<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos attached to an expedition (a small gallery). Files live in the
 * protected storage/app/expeditions/ dir and are streamed via the API, like
 * damage/resource photos. Cascade-deletes with the expedition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedition_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expeditionId');
            $table->string('path');
            $table->timestamp('createdAt')->useCurrent();

            $table->index('expeditionId');
            $table->foreign('expeditionId')->references('id')->on('expeditions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_photos');
    }
};
