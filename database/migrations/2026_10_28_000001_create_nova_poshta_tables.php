<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NovaPoshta\Filament\Support\Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('generations'), function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->default('building');
            $table->timestamps();
        });
        Schema::create(Tables::name('settings'), function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('api_key')->nullable();
            $table->unsignedBigInteger('active_generation_id')->nullable();
            $table->foreign('active_generation_id', 'np_'.substr(sha1(Schema::getConnection()->getTablePrefix().Tables::name('settings')), 0, 8).'_fg')->references('id')->on(Tables::name('generations'))->restrictOnDelete();
            $table->json('schedule')->nullable();
            $table->timestamp('last_scheduled_at')->nullable();
            $table->timestamp('scheduler_seen_at')->nullable();
            $table->timestamp('worker_seen_at')->nullable();
            $table->timestamp('api_seen_at')->nullable();
            $table->timestamps();
        });
        foreach (Tables::directories() as $suffix) {
            Schema::create(Tables::name($suffix), function (Blueprint $table) use ($suffix): void {
                $table->id();
                $table->unsignedBigInteger('generation_id');
                $table->string('ref', 64);
                $table->string('name')->default('');
                $table->string('name_ru')->nullable();
                $table->text('search_text');
                $table->string('area_ref', 64)->nullable();
                $table->string('district_ref', 64)->nullable();
                $table->string('settlement_ref', 64)->nullable();
                $table->string('city_ref', 64)->nullable();
                $table->string('type_ref', 64)->nullable();
                $table->string('type', 20)->nullable();
                $table->json('payload');
                // Prefix hash also permits two installations in one PostgreSQL schema.
                $key = 'np_'.substr(sha1(Schema::getConnection()->getTablePrefix().Tables::name($suffix)), 0, 8);
                $table->foreign('generation_id', $key.'_fg')->references('id')->on(Tables::name('generations'))->cascadeOnDelete();
                $table->unique(['generation_id', 'ref'], $key.'_gr');
                $table->index(['generation_id', 'name'], $key.'_gn');
                $table->index(['generation_id', 'settlement_ref'], $key.'_gs');
                $table->index(['generation_id', 'area_ref', 'district_ref'], $key.'_gad');
            });
        }
        Schema::create(Tables::name('sync_runs'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('stage', 40)->default('queued');
            $table->json('counts')->nullable();
            $table->string('error', 80)->nullable();
            $table->timestamp('heartbeat_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create(Tables::name('street_cache'), function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('generation_id');
            $table->string('settlement_ref', 64);
            $table->json('payload');
            $table->timestamp('expires_at');
        });
        DB::table(Tables::name('settings'))->insert([
            'id' => 1,
            'schedule' => json_encode(['enabled' => false, 'frequency' => 'daily', 'time' => '03:00', 'timezone' => 'Europe/Kyiv', 'hours' => 24, 'weekday' => 1, 'day' => 1]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (array_merge(['street_cache', 'sync_runs'], array_reverse(Tables::directories()), ['settings', 'generations']) as $suffix) {
            Schema::dropIfExists(Tables::name($suffix));
        }
    }
};
