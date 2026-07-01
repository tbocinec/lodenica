<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Expedície" — a world map of places club members have paddled (rivers,
 * lakes, seas). Member-generated content: anyone vetted can pin a spot and
 * tell the story. Only `title`, `place` and the map coordinates are required;
 * everything else is optional colour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expeditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->string('place', 200);          // human-readable location name
            $table->double('latitude');            // map pin
            $table->double('longitude');
            $table->integer('year')->nullable();
            $table->string('waterType', 16)->nullable();   // river | lake | sea | other
            $table->json('countries')->nullable();          // list of country names
            $table->string('participants', 500)->nullable();
            $table->decimal('distanceKm', 8, 1)->nullable();
            // Optional route polyline: ordered [lat, lng] pairs (traced on the
            // map or imported from GPX). For rivers/seas — "from here to there".
            $table->json('route')->nullable();
            $table->text('detail')->nullable();
            // The submitter confirmed (on create) that the entry — including any
            // named participants, given voluntarily + with their consent — may be
            // published and seen by all club members.
            $table->boolean('publishConsent')->default(false);
            $table->uuid('createdById')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();

            $table->index('createdById');
            $table->index('year');
            $table->foreign('createdById')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expeditions');
    }
};
