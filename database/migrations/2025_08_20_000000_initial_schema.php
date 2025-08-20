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

        // Create ENUM types
        DB::statement("CREATE TYPE user_status_enum AS ENUM ('active', 'inactive', 'suspended')");
        DB::statement("CREATE TYPE admin_role_enum AS ENUM ('super_admin', 'admin', 'moderator')");
        DB::statement("CREATE TYPE template_status_enum AS ENUM ('active', 'inactive', 'archived')");
        DB::statement("CREATE TYPE design_status_enum AS ENUM ('draft', 'queued', 'rendering', 'ready', 'failed')");
        DB::statement("CREATE TYPE render_job_status_enum AS ENUM ('pending', 'processing', 'completed', 'failed', 'cancelled')");
        DB::statement("CREATE TYPE order_status_enum AS ENUM ('draft', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded')");
        DB::statement("CREATE TYPE payment_status_enum AS ENUM ('pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded')");
        DB::statement("CREATE TYPE export_job_status_enum AS ENUM ('queued', 'processing', 'completed', 'failed')");
        DB::statement("CREATE TYPE export_job_type_enum AS ENUM ('orders_csv', 'users_csv', 'designs_csv')");

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

            $table->index('email');
            $table->index('status');
            $table->index('created_at');
        });

        // Admin users table
        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email')->unique();
            $table->string('password');
            $table->string('name');
            $table->enum('role', ['super_admin', 'admin', 'moderator'])->default('moderator');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('role');
            $table->index('is_active');
        });

        // Templates table
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->integer('base_unit_price')->unsigned();
            $table->string('image_url', 500)->nullable();
            $table->string('category', 100)->nullable();
            $table->jsonb('safe_area')->nullable();
            $table->jsonb('render_params')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('category');
            $table->index('created_at');
        });

        // Designs table
        Schema::create('designs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('template_id');
            $table->uuid('original_design_id')->nullable();
            $table->string('name');
            $table->integer('version')->default(1);
            $table->jsonb('params');
            $table->integer('params_crc32');
            $table->boolean('safe_area_warning')->default(false);
            $table->enum('status', ['draft', 'queued', 'rendering', 'ready', 'failed'])->default('draft');
            $table->string('preview_image_url', 500)->nullable();
            $table->string('final_image_url', 500)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('templates')->onDelete('restrict');
            $table->foreign('original_design_id')->references('id')->on('designs')->onDelete('set null');

            $table->index('user_id');
            $table->index('template_id');
            $table->index('original_design_id');
            $table->index('status');
            $table->index('params_crc32');
            $table->index('created_at');
        });

        // Add GIN index for designs params
        DB::statement('CREATE INDEX idx_designs_params ON designs USING gin(params)');

        // Render jobs table
        Schema::create('render_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('design_id');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->integer('attempt')->default(1);
            $table->string('idempotency_key')->unique();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('design_id')->references('id')->on('designs')->onDelete('cascade');

            $table->index('design_id');
            $table->index('status');
            $table->index('idempotency_key');
            $table->index('created_at');
        });

        // Orders table
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('design_id');
            $table->string('order_number', 50)->unique();
            $table->enum('status', ['draft', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'])->default('draft');
            $table->integer('quantity')->default(1)->unsigned();
            $table->integer('base_unit_price')->unsigned();
            $table->integer('subtotal')->unsigned();
            $table->integer('discount_total')->default(0)->unsigned();
            $table->integer('subtotal_after_discount')->unsigned();
            $table->integer('shipping_fee')->default(0)->unsigned();
            $table->integer('amount')->unsigned();
            $table->jsonb('amount_breakdown')->nullable();
            $table->enum('payment_status', ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->jsonb('shipping_address');
            $table->text('notes')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('design_id')->references('id')->on('designs')->onDelete('restrict');

            $table->index('user_id');
            $table->index('design_id');
            $table->index('order_number');
            $table->index('status');
            $table->index('payment_status');
            $table->index('stripe_payment_intent_id');
            $table->index('stripe_checkout_session_id');
            $table->index('created_at');
        });

        // Add GIN indexes for orders JSONB columns
        DB::statement('CREATE INDEX idx_orders_shipping_address ON orders USING gin(shipping_address)');
        DB::statement('CREATE INDEX idx_orders_amount_breakdown ON orders USING gin(amount_breakdown)');

        // Stripe event log table
        Schema::create('stripe_event_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('stripe_event_id')->unique();
            $table->string('event_type', 100);
            $table->timestamp('processed_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->uuid('order_id')->nullable();
            $table->jsonb('raw_data');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');

            $table->index('stripe_event_id');
            $table->index('event_type');
            $table->index('order_id');
            $table->index('created_at');
        });

        // Export jobs table
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('admin_user_id');
            $table->enum('type', ['orders_csv', 'users_csv', 'designs_csv']);
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->jsonb('filters')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->integer('total_records')->nullable();
            $table->integer('processed_records')->default(0)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('cascade');

            $table->index('admin_user_id');
            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });

        // Admin actions table
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('admin_user_id');
            $table->string('action', 100);
            $table->string('target_type', 50);
            $table->uuid('target_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('cascade');

            $table->index('admin_user_id');
            $table->index('action');
            $table->index('target_type');
            $table->index('target_id');
            $table->index('created_at');
        });

        // Rate limit counters table (optional)
        Schema::create('rate_limit_counters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('key')->unique();
            $table->integer('count')->default(1);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('key');
            $table->index('expires_at');
        });

        // Create postal code validation function
        DB::statement("
            CREATE OR REPLACE FUNCTION validate_postal_code(postal_code TEXT) 
            RETURNS BOOLEAN AS \$\$
            BEGIN
                -- Japanese postal code format: XXX-XXXX or XXXXXXX
                RETURN postal_code ~ '^[0-9]{3}-?[0-9]{4}\$';
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Add business logic constraints
        DB::statement("
            ALTER TABLE templates ADD CONSTRAINT chk_templates_base_unit_price 
            CHECK (base_unit_price >= 0)
        ");

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT chk_order_amounts 
            CHECK (
                subtotal = base_unit_price * quantity AND
                subtotal_after_discount = subtotal - discount_total AND
                amount = subtotal_after_discount + shipping_fee
            )
        ");

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT chk_shipping_address_format 
            CHECK (
                shipping_address ? 'postal_code' AND
                shipping_address ? 'pref_name' AND
                shipping_address ? 'city' AND
                shipping_address ? 'address_line'
            )
        ");

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT chk_postal_code_format 
            CHECK (validate_postal_code(shipping_address->>'postal_code'))
        ");

        // Add table comments
        DB::statement("COMMENT ON TABLE users IS 'End users of the EC site'");
        DB::statement("COMMENT ON TABLE admin_users IS 'Administrative users with backend access'");
        DB::statement("COMMENT ON TABLE templates IS 'Design templates available for customization'");
        DB::statement("COMMENT ON TABLE designs IS 'User-customized designs based on templates'");
        DB::statement("COMMENT ON TABLE render_jobs IS 'Background jobs for rendering design previews and final images'");
        DB::statement("COMMENT ON TABLE orders IS 'Customer orders with payment and shipping information'");
        DB::statement("COMMENT ON TABLE stripe_event_log IS 'Stripe webhook events for payment processing idempotency'");
        DB::statement("COMMENT ON TABLE export_jobs IS 'Background jobs for CSV data exports'");
        DB::statement("COMMENT ON TABLE admin_actions IS 'Audit trail for administrative actions'");
        DB::statement("COMMENT ON TABLE rate_limit_counters IS 'Rate limiting counters for API endpoints'");

        // Add column comments
        DB::statement("COMMENT ON COLUMN designs.original_design_id IS 'Reference to original design for reorder functionality'");
        DB::statement("COMMENT ON COLUMN designs.params_crc32 IS 'CRC32 hash of params for quick comparison'");
        DB::statement("COMMENT ON COLUMN orders.amount_breakdown IS 'Detailed breakdown of price calculation'");
        DB::statement("COMMENT ON COLUMN orders.shipping_address IS 'Full shipping address with prefecture codes and kana readings'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop tables in reverse order of dependencies
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

        // Drop functions
        DB::statement('DROP FUNCTION IF EXISTS validate_postal_code(TEXT)');

        // Drop ENUM types
        DB::statement('DROP TYPE IF EXISTS export_job_type_enum');
        DB::statement('DROP TYPE IF EXISTS export_job_status_enum');
        DB::statement('DROP TYPE IF EXISTS payment_status_enum');
        DB::statement('DROP TYPE IF EXISTS order_status_enum');
        DB::statement('DROP TYPE IF EXISTS render_job_status_enum');
        DB::statement('DROP TYPE IF EXISTS design_status_enum');
        DB::statement('DROP TYPE IF EXISTS template_status_enum');
        DB::statement('DROP TYPE IF EXISTS admin_role_enum');
        DB::statement('DROP TYPE IF EXISTS user_status_enum');
    }
};