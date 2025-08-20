<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable UUID extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pg_trgm"');

        // Create ENUM types
        DB::statement("CREATE TYPE user_status AS ENUM ('active', 'inactive', 'suspended')");
        DB::statement("CREATE TYPE admin_role AS ENUM ('super_admin', 'admin', 'editor', 'viewer')");
        DB::statement("CREATE TYPE template_status AS ENUM ('active', 'inactive', 'archived')");
        DB::statement("CREATE TYPE design_status AS ENUM ('draft', 'queued', 'rendering', 'ready', 'failed')");
        DB::statement("CREATE TYPE render_job_status AS ENUM ('queued', 'processing', 'completed', 'failed', 'cancelled')");
        DB::statement("CREATE TYPE order_status AS ENUM ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded')");
        DB::statement("CREATE TYPE payment_status AS ENUM ('pending', 'completed', 'failed', 'refunded', 'partially_refunded')");
        DB::statement("CREATE TYPE export_job_status AS ENUM ('queued', 'processing', 'completed', 'failed')");
        DB::statement("CREATE TYPE export_job_type AS ENUM ('orders_csv', 'users_csv', 'designs_csv')");

        // Users table
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Admin users table
        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('name');
            $table->enum('role', ['super_admin', 'admin', 'editor', 'viewer'])->default('viewer');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Templates table
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 100);
            $table->integer('base_unit_price');
            $table->jsonb('safe_area');
            $table->string('preview_image_url', 500)->nullable();
            $table->string('template_file_url', 500)->nullable();
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Designs table
        Schema::create('designs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('template_id');
            $table->uuid('original_design_id')->nullable();
            $table->string('name');
            $table->integer('version')->default(1);
            $table->jsonb('params')->default('{}');
            $table->bigInteger('params_crc32')->nullable();
            $table->boolean('safe_area_warning')->default(false);
            $table->string('preview_image_url', 500)->nullable();
            $table->enum('status', ['draft', 'queued', 'rendering', 'ready', 'failed'])->default('draft');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('templates')->onDelete('restrict');
            $table->foreign('original_design_id')->references('id')->on('designs')->onDelete('set null');
        });

        // Render jobs table
        Schema::create('render_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('design_id');
            $table->string('idempotency_key')->unique();
            $table->enum('status', ['queued', 'processing', 'completed', 'failed', 'cancelled'])->default('queued');
            $table->integer('attempt')->default(1);
            $table->string('failure_reason', 500)->nullable();
            $table->string('output_image_url', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('design_id')->references('id')->on('designs')->onDelete('cascade');
        });

        // Orders table
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('design_id');
            $table->string('order_number', 50)->unique();
            $table->integer('quantity');
            $table->integer('base_unit_price');
            $table->integer('subtotal');
            $table->integer('discount_total')->default(0);
            $table->integer('subtotal_after_discount');
            $table->integer('shipping_fee')->default(0);
            $table->integer('amount');
            $table->jsonb('amount_breakdown')->default('{}');

            // Shipping address
            $table->string('shipping_name');
            $table->string('shipping_postal_code', 10);
            $table->string('shipping_pref_code', 2);
            $table->string('shipping_pref_name', 20);
            $table->string('shipping_pref_kana', 20)->nullable();
            $table->string('shipping_city', 100);
            $table->string('shipping_city_kana', 100)->nullable();
            $table->string('shipping_address_line1');
            $table->string('shipping_address_line2')->nullable();
            $table->string('shipping_phone', 20)->nullable();
            $table->jsonb('shipping')->nullable();

            // Order status and payment
            $table->enum('status', ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'])->default('pending');
            $table->enum('payment_status', ['pending', 'completed', 'failed', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->string('stripe_payment_intent_id')->nullable();

            // Timestamps
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('design_id')->references('id')->on('designs')->onDelete('restrict');
        });

        // Stripe event log table
        Schema::create('stripe_event_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('stripe_event_id')->unique();
            $table->string('event_type', 100);
            $table->boolean('processed')->default(false);
            $table->text('error_message')->nullable();
            $table->jsonb('event_data')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('processed_at')->nullable();
        });

        // Export jobs table
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('admin_user_id');
            $table->enum('type', ['orders_csv', 'users_csv', 'designs_csv']);
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->jsonb('parameters')->default('{}');
            $table->string('file_url', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('cascade');
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

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('cascade');
        });

        // Rate limit counters table
        Schema::create('rate_limit_counters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('key');
            $table->integer('counter')->default(0);
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->timestamps();

            $table->unique(['key', 'window_start']);
        });

        // Add CHECK constraints
        DB::statement('ALTER TABLE templates ADD CONSTRAINT templates_base_unit_price_check CHECK (base_unit_price >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_base_unit_price_check CHECK (base_unit_price >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_check CHECK (subtotal >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_total_check CHECK (discount_total >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_after_discount_check CHECK (subtotal_after_discount >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_shipping_fee_check CHECK (shipping_fee >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_shipping_postal_code_check CHECK (shipping_postal_code ~ \'^\\d{3}-?\\d{4}$\')');
        
        // Business logic constraints
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_calculation_check CHECK (amount = subtotal_after_discount + shipping_fee)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_calculation_check CHECK (subtotal = base_unit_price * quantity)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_check CHECK (subtotal_after_discount = subtotal - discount_total)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_free_shipping_check CHECK ((subtotal_after_discount >= 8000 AND shipping_fee = 0) OR (subtotal_after_discount < 8000))');
        
        DB::statement('ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_progress_percentage_check CHECK (progress_percentage >= 0 AND progress_percentage <= 100)');

        // Create indexes
        $this->createIndexes();

        // Create triggers for updated_at
        $this->createTriggers();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop triggers
        DB::statement('DROP TRIGGER IF EXISTS update_users_updated_at ON users');
        DB::statement('DROP TRIGGER IF EXISTS update_admin_users_updated_at ON admin_users');
        DB::statement('DROP TRIGGER IF EXISTS update_templates_updated_at ON templates');
        DB::statement('DROP TRIGGER IF EXISTS update_designs_updated_at ON designs');
        DB::statement('DROP TRIGGER IF EXISTS update_render_jobs_updated_at ON render_jobs');
        DB::statement('DROP TRIGGER IF EXISTS update_orders_updated_at ON orders');
        DB::statement('DROP TRIGGER IF EXISTS update_export_jobs_updated_at ON export_jobs');
        DB::statement('DROP TRIGGER IF EXISTS update_rate_limit_counters_updated_at ON rate_limit_counters');
        DB::statement('DROP FUNCTION IF EXISTS update_updated_at_column()');

        // Drop tables in reverse order
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
        DB::statement('DROP TYPE IF EXISTS template_status');
        DB::statement('DROP TYPE IF EXISTS admin_role');
        DB::statement('DROP TYPE IF EXISTS user_status');
    }

    private function createIndexes(): void
    {
        // Users indexes
        DB::statement('CREATE INDEX idx_users_email ON users(email)');
        DB::statement('CREATE INDEX idx_users_status ON users(status)');
        
        // Admin users indexes
        DB::statement('CREATE INDEX idx_admin_users_email ON admin_users(email)');
        DB::statement('CREATE INDEX idx_admin_users_role ON admin_users(role)');

        // Templates indexes
        DB::statement('CREATE INDEX idx_templates_category ON templates(category)');
        DB::statement('CREATE INDEX idx_templates_status ON templates(status)');
        DB::statement('CREATE INDEX idx_templates_sort_order ON templates(sort_order)');

        // Designs indexes
        DB::statement('CREATE INDEX idx_designs_user_id ON designs(user_id)');
        DB::statement('CREATE INDEX idx_designs_template_id ON designs(template_id)');
        DB::statement('CREATE INDEX idx_designs_original_design_id ON designs(original_design_id)');
        DB::statement('CREATE INDEX idx_designs_status ON designs(status)');
        DB::statement('CREATE INDEX idx_designs_user_template ON designs(user_id, template_id)');
        DB::statement('CREATE INDEX idx_designs_params_gin ON designs USING GIN (params)');

        // Render jobs indexes
        DB::statement('CREATE INDEX idx_render_jobs_design_id ON render_jobs(design_id)');
        DB::statement('CREATE INDEX idx_render_jobs_status ON render_jobs(status)');
        DB::statement('CREATE INDEX idx_render_jobs_idempotency_key ON render_jobs(idempotency_key)');

        // Orders indexes
        DB::statement('CREATE INDEX idx_orders_user_id ON orders(user_id)');
        DB::statement('CREATE INDEX idx_orders_design_id ON orders(design_id)');
        DB::statement('CREATE INDEX idx_orders_order_number ON orders(order_number)');
        DB::statement('CREATE INDEX idx_orders_status ON orders(status)');
        DB::statement('CREATE INDEX idx_orders_payment_status ON orders(payment_status)');
        DB::statement('CREATE INDEX idx_orders_created_at ON orders(created_at)');
        DB::statement('CREATE INDEX idx_orders_shipping_postal_code ON orders(shipping_postal_code)');
        DB::statement('CREATE INDEX idx_orders_amount_breakdown_gin ON orders USING GIN (amount_breakdown)');
        DB::statement('CREATE INDEX idx_orders_shipping_gin ON orders USING GIN (shipping)');

        // Stripe event log indexes
        DB::statement('CREATE INDEX idx_stripe_event_log_stripe_event_id ON stripe_event_log(stripe_event_id)');
        DB::statement('CREATE INDEX idx_stripe_event_log_event_type ON stripe_event_log(event_type)');
        DB::statement('CREATE INDEX idx_stripe_event_log_processed ON stripe_event_log(processed)');

        // Export jobs indexes
        DB::statement('CREATE INDEX idx_export_jobs_admin_user_id ON export_jobs(admin_user_id)');
        DB::statement('CREATE INDEX idx_export_jobs_type ON export_jobs(type)');
        DB::statement('CREATE INDEX idx_export_jobs_status ON export_jobs(status)');
        DB::statement('CREATE INDEX idx_export_jobs_created_at ON export_jobs(created_at)');

        // Admin actions indexes
        DB::statement('CREATE INDEX idx_admin_actions_admin_user_id ON admin_actions(admin_user_id)');
        DB::statement('CREATE INDEX idx_admin_actions_action ON admin_actions(action)');
        DB::statement('CREATE INDEX idx_admin_actions_target_type_id ON admin_actions(target_type, target_id)');
        DB::statement('CREATE INDEX idx_admin_actions_created_at ON admin_actions(created_at)');

        // Rate limit counters indexes
        DB::statement('CREATE INDEX idx_rate_limit_counters_key ON rate_limit_counters(key)');
        DB::statement('CREATE INDEX idx_rate_limit_counters_window ON rate_limit_counters(window_start, window_end)');
    }

    private function createTriggers(): void
    {
        // Create the trigger function
        DB::statement("
            CREATE OR REPLACE FUNCTION update_updated_at_column()
            RETURNS TRIGGER AS \$\$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            \$\$ language 'plpgsql';
        ");

        // Create triggers for each table
        DB::statement('CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_admin_users_updated_at BEFORE UPDATE ON admin_users FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_templates_updated_at BEFORE UPDATE ON templates FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_designs_updated_at BEFORE UPDATE ON designs FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_render_jobs_updated_at BEFORE UPDATE ON render_jobs FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_orders_updated_at BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_export_jobs_updated_at BEFORE UPDATE ON export_jobs FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
        DB::statement('CREATE TRIGGER update_rate_limit_counters_updated_at BEFORE UPDATE ON rate_limit_counters FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()');
    }
};