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
        Schema::table(Tables::name('sync_runs'), function (Blueprint $table): void {
            $table->string('source', 16)->default('manual');
            $table->unsignedInteger('revision')->default(0);
            $table->json('checkpoint')->nullable();
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->string('key_status', 24)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
        });
        Schema::table(Tables::name('settings'), function (Blueprint $table): void {
            $table->string('key_revision', 36)->nullable();
            $table->string('key_status', 24)->default('unchecked');
            $table->timestamp('key_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
        });
        $generation = DB::table(Tables::name('settings'))->where('id', 1)->value('active_generation_id');
        if ($generation) {
            DB::table(Tables::name('settings'))->where('id', 1)->update([
                'last_success_at' => DB::table(Tables::name('generations'))->where('id', $generation)->value('updated_at'),
            ]);
        }
        Schema::create(Tables::name('sync_events'), function (Blueprint $table): void {
            $key = 'np_'.substr(sha1(Schema::getConnection()->getTablePrefix().Tables::name('sync_events')), 0, 8);
            $table->id();
            $table->unsignedBigInteger('sync_run_id');
            $table->string('level', 12);
            $table->string('code', 64);
            $table->string('stage', 40);
            $table->json('context')->nullable();
            $table->timestamp('created_at');
            $table->foreign('sync_run_id', $key.'_fr')->references('id')->on(Tables::name('sync_runs'))->cascadeOnDelete();
            $table->index(['sync_run_id', 'id'], $key.'_ri');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('sync_events'));
        Schema::table(Tables::name('sync_runs'), fn (Blueprint $table) => $table->dropColumn([
            'source', 'revision', 'checkpoint', 'request_count', 'error_count', 'retry_count', 'warning_count', 'key_status', 'next_attempt_at',
        ]));
        Schema::table(Tables::name('settings'), fn (Blueprint $table) => $table->dropColumn([
            'key_revision', 'key_status', 'key_checked_at', 'last_success_at',
        ]));
    }
};
