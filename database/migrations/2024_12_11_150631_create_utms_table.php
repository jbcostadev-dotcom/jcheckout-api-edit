<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUtmsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('utms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cart_id');
            $table->string('source')->nullable();
            $table->string('campaign')->nullable();
            $table->string('medium')->nullable();
            $table->string('content')->nullable();
            $table->string('term')->nullable();
            $table->string('xcod')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('utms');
    }
}
