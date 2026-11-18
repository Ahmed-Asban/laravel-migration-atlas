<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('orders', function ($bp) {
            $bp->id();
            $bp->uuid('reference');
            $bp->decimal('amount', 10, 2);
            $bp->enum('status', ['draft', 'ready']);
            $bp->bigIncrements('legacy_key');
            $bp->softDeletes('purged_at', 3);
            $bp->rememberToken();
            $bp->foreignId('user_id')->constrained()->cascadeOnDelete();
            $bp->foreignIdFor(Category::class)->constrained();
            $bp->foreign('coupon_id')->references('code')->on('coupons')->onDelete('set null');
            $bp->unique(['reference', 'status']);
            $bp->index('amount', 'orders_amount_idx');
            $bp->foreignIdFor(User::class, 'buyer_id');
        });
        Schema::table('orders', function ($bp) {
            $bp->string('note', 40)->comment('free text')->change();
            $bp->dateTime('seen', 3)->nullable()->useCurrent();
            $bp->dropUnique(['reference', 'status']);
            $bp->dropForeign('orders_coupon_id_foreign');
            $bp->dropPrimary();
        });
    }
};
