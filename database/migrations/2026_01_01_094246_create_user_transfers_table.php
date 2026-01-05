<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_transfers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');

            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('receiver_wallet_id')->nullable();

            $table->string('coin_type', 20);
            $table->decimal('amount', 20, 8);
            $table->decimal('fees', 20, 8)->default(0);

            $table->tinyInteger('status')->default(1)
                ->comment('0 = failed, 1 = success, 2 = pending');

            $table->timestamps();

            // Foreign Keys
            $table->foreign('from_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('to_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_transfers');
    }
};
