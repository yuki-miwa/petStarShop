<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable UUID extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // Create ENUM types
        DB::statement("CREATE TYPE user_status AS ENUM ('active', 'inactive', 'suspended')");
        DB::statement("CREATE TYPE design_status AS ENUM ('draft', 'queued', 'rendering', 'ready', 'failed')");
        DB::statement("CREATE TYPE render_job_status AS ENUM ('pending', 'processing', 'completed', 'failed')");
        DB::statement("CREATE TYPE order_status AS ENUM ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded')");
        DB::statement("CREATE TYPE payment_status AS ENUM ('pending', 'processing', 'paid', 'failed', 'refunded', 'partially_refunded')");
        DB::statement("CREATE TYPE export_job_status AS ENUM ('pending', 'processing', 'completed', 'failed')");
        DB::statement("CREATE TYPE export_job_type AS ENUM ('orders_csv', 'designs_csv', 'users_csv')");

        // Users table
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email', 255)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->string('name', 255);
            $table->string('postal_code', 8)->nullable();
            $table->string('pref_code', 2)->nullable();
            $table->string('pref_name', 20)->nullable();
            $table->string('pref_kana', 40)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('city_kana', 200)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->rememberToken();
            $table->timestamps();

            $table->index('email');
            $table->index('status');
        });

        // Add postal code format constraint
        DB::statement("ALTER TABLE users ADD CONSTRAINT check_postal_code_format CHECK (postal_code IS NULL OR postal_code ~ '^[0-9]{3}-?[0-9]{4}$')");

        // Admin users table
        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('name', 255);
            $table->string('role', 50)->default('admin');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Templates table
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('image_url', 512)->nullable();
            $table->string('thumbnail_url', 512)->nullable();
            $table->integer('base_unit_price')->default(0);
            $table->jsonb('template_data')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Add base_unit_price constraint
        DB::statement("ALTER TABLE templates ADD CONSTRAINT check_base_unit_price_positive CHECK (base_unit_price >= 0)");

        // Designs table
        Schema::create('designs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('template_id');
            $table->uuid('original_design_id')->nullable();
            $table->string('name', 255)->nullable();
            $table->integer('version')->default(1);
            $table->jsonb('params')->default('{}');
            $table->string('params_crc32', 8)->nullable();
            $table->string('preview_image_url', 512)->nullable();
            $table->string('final_image_url', 512)->nullable();
            $table->boolean('safe_area_warning')->default(false);
            $table->enum('status', ['draft', 'queued', 'rendering', 'ready', 'failed'])->default('draft');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('templates')->onDelete('restrict');
            $table->foreign('original_design_id')->references('id')->on('designs')->onDelete('set null');

            $table->index('user_id');
            $table->index('template_id');
            $table->index('status');
            $table->index('original_design_id');
            $table->index('created_at');
        });

        // Render jobs table
        Schema::create('render_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('design_id');
            $table->string('idempotency_key', 255)->unique();
            $table->integer('attempt')->default(1);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->jsonb('render_params')->nullable();
            $table->string('result_image_url', 512)->nullable();
            $table->timestamps();

            $table->foreign('design_id')->references('id')->on('designs')->onDelete('cascade');

            $table->index('design_id');
            $table->index('status');
            $table->index('idempotency_key');
        });

        // Add attempt constraint
        DB::statement("ALTER TABLE render_jobs ADD CONSTRAINT check_attempt_positive CHECK (attempt > 0)");

        // Orders table
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('design_id');
            $table->string('order_number', 50)->unique();

            // Pricing breakdown
            $table->integer('base_unit_price');
            $table->integer('subtotal');
            $table->integer('discount_total')->default(0);
            $table->integer('subtotal_after_discount');
            $table->integer('shipping_fee')->default(0);
            $table->integer('amount');
            $table->jsonb('amount_breakdown')->default('{}');

            // Shipping information
            $table->string('shipping_name', 255);
            $table->string('shipping_postal_code', 8);
            $table->string('shipping_pref_code', 2)->nullable();
            $table->string('shipping_pref_name', 20);
            $table->string('shipping_pref_kana', 40)->nullable();
            $table->string('shipping_city', 100);
            $table->string('shipping_city_kana', 200)->nullable();
            $table->string('shipping_address_line', 255);
            $table->string('shipping_phone', 20)->nullable();
            $table->string('shipping_method', 100)->default('standard');
            $table->jsonb('shipping_info')->default('{}');

            // Order status and payment
            $table->enum('status', ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'])->default('pending');
            $table->enum('payment_status', ['pending', 'processing', 'paid', 'failed', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->string('stripe_session_id', 255)->nullable();

            // Timestamps
            $table->timestamp('ordered_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('design_id')->references('id')->on('designs')->onDelete('restrict');

            $table->index('user_id');
            $table->index('design_id');
            $table->index('order_number');
            $table->index('status');
            $table->index('payment_status');
            $table->index('ordered_at');
            $table->index('stripe_payment_intent_id');
        });

        // Add constraints for orders table
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_amounts_positive CHECK (base_unit_price >= 0 AND subtotal >= 0 AND discount_total >= 0 AND subtotal_after_discount >= 0 AND shipping_fee >= 0 AND amount >= 0)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_amount_calculation CHECK (amount = subtotal_after_discount + shipping_fee)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_subtotal_after_discount CHECK (subtotal_after_discount = subtotal - discount_total)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT check_shipping_postal_code_format CHECK (shipping_postal_code ~ '^[0-9]{3}-?[0-9]{4}$')");

        // Stripe event log table
        Schema::create('stripe_event_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('stripe_event_id', 255)->unique();
            $table->string('event_type', 100);
            $table->timestamp('processed_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->jsonb('payload')->nullable();
            $table->jsonb('processing_result')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->index('stripe_event_id');
            $table->index('event_type');
        });

        // Export jobs table
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('admin_user_id');
            $table->enum('type', ['orders_csv', 'designs_csv', 'users_csv']);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->jsonb('filters')->default('{}');
            $table->string('file_url', 512)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('restrict');

            $table->index('admin_user_id');
            $table->index('status');
            $table->index('type');
        });

        // Admin actions table
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('admin_user_id');
            $table->string('action', 100);
            $table->string('target_type', 50)->nullable();
            $table->uuid('target_id')->nullable();
            $table->jsonb('details')->default('{}');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('restrict');

            $table->index('admin_user_id');
            $table->index('action');
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        // Rate limit counters table
        Schema::create('rate_limit_counters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('identifier', 255);
            $table->string('action', 100);
            $table->integer('counter')->default(1);
            $table->timestamp('window_start');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['identifier', 'action', 'window_start'], 'unique_rate_limit_window');
            $table->index(['identifier', 'action']);
            $table->index('expires_at');
        });

        // Create GIN indexes for JSONB columns
        DB::statement('CREATE INDEX idx_templates_template_data ON templates USING GIN (template_data)');
        DB::statement('CREATE INDEX idx_designs_params ON designs USING GIN (params)');
        DB::statement('CREATE INDEX idx_orders_amount_breakdown ON orders USING GIN (amount_breakdown)');
        DB::statement('CREATE INDEX idx_orders_shipping_info ON orders USING GIN (shipping_info)');
        DB::statement('CREATE INDEX idx_stripe_event_log_payload ON stripe_event_log USING GIN (payload)');
        DB::statement('CREATE INDEX idx_export_jobs_filters ON export_jobs USING GIN (filters)');
        DB::statement('CREATE INDEX idx_admin_actions_details ON admin_actions USING GIN (details)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop GIN indexes first
        DB::statement('DROP INDEX IF EXISTS idx_admin_actions_details');
        DB::statement('DROP INDEX IF EXISTS idx_export_jobs_filters');
        DB::statement('DROP INDEX IF EXISTS idx_stripe_event_log_payload');
        DB::statement('DROP INDEX IF EXISTS idx_orders_shipping_info');
        DB::statement('DROP INDEX IF EXISTS idx_orders_amount_breakdown');
        DB::statement('DROP INDEX IF EXISTS idx_designs_params');
        DB::statement('DROP INDEX IF EXISTS idx_templates_template_data');

        // Drop tables in reverse order (respecting foreign key constraints)
        Schema::dropIfExists('rate_limit_counters');
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('stripe_event_log');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('render_jobs');
        Schema::dropIfExists('designs');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('users');

        // Drop ENUM types
        DB::statement('DROP TYPE IF EXISTS export_job_type');
        DB::statement('DROP TYPE IF EXISTS export_job_status');
        DB::statement('DROP TYPE IF EXISTS payment_status');
        DB::statement('DROP TYPE IF EXISTS order_status');
        DB::statement('DROP TYPE IF EXISTS render_job_status');
        DB::statement('DROP TYPE IF EXISTS design_status');
        DB::statement('DROP TYPE IF EXISTS user_status');
    }
};