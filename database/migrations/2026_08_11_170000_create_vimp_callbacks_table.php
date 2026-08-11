<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVimpCallbacksTable extends Migration
{
    public function up()
    {
        Schema::create('vimp_callbacks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('download_id')->nullable()->index();
            $table->unsignedInteger('video_id')->nullable()->index();
            $table->string('mediakey', 32)->index();
            $table->string('type', 32)->index();
            $table->string('status', 32)->index();
            $table->string('dedupe_key', 64)->unique();
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->text('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vimp_callbacks');
    }
}
