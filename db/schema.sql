-- petStarShop EC Site Database Schema
-- PostgreSQL with UUID primary keys, ENUMs, and business constraints

-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ===== ENUM Types =====

-- User status
CREATE TYPE user_status_enum AS ENUM ('active', 'inactive', 'suspended');

-- Admin user role
CREATE TYPE admin_role_enum AS ENUM ('super_admin', 'admin', 'moderator');

-- Template status
CREATE TYPE template_status_enum AS ENUM ('active', 'inactive', 'archived');

-- Design status
CREATE TYPE design_status_enum AS ENUM ('draft', 'queued', 'rendering', 'ready', 'failed');

-- Render job status
CREATE TYPE render_job_status_enum AS ENUM ('pending', 'processing', 'completed', 'failed', 'cancelled');

-- Order status
CREATE TYPE order_status_enum AS ENUM ('draft', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded');

-- Payment status
CREATE TYPE payment_status_enum AS ENUM ('pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded');

-- Export job status
CREATE TYPE export_job_status_enum AS ENUM ('queued', 'processing', 'completed', 'failed');

-- Export job type
CREATE TYPE export_job_type_enum AS ENUM ('orders_csv', 'users_csv', 'designs_csv');

-- ===== Main Tables =====

-- Users table
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NULL,
    status user_status_enum NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Admin users table
CREATE TABLE admin_users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role admin_role_enum NOT NULL DEFAULT 'moderator',
    is_active BOOLEAN NOT NULL DEFAULT true,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Templates table
CREATE TABLE templates (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status template_status_enum NOT NULL DEFAULT 'active',
    base_unit_price INTEGER NOT NULL CHECK (base_unit_price >= 0),
    image_url VARCHAR(500) NULL,
    category VARCHAR(100) NULL,
    safe_area JSONB NULL,
    render_params JSONB NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Designs table
CREATE TABLE designs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    template_id UUID NOT NULL REFERENCES templates(id) ON DELETE RESTRICT,
    original_design_id UUID NULL REFERENCES designs(id) ON DELETE SET NULL, -- For reorders
    name VARCHAR(255) NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    params JSONB NOT NULL,
    params_crc32 INTEGER NOT NULL,
    safe_area_warning BOOLEAN NOT NULL DEFAULT false,
    status design_status_enum NOT NULL DEFAULT 'draft',
    preview_image_url VARCHAR(500) NULL,
    final_image_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Render jobs table
CREATE TABLE render_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    design_id UUID NOT NULL REFERENCES designs(id) ON DELETE CASCADE,
    status render_job_status_enum NOT NULL DEFAULT 'pending',
    attempt INTEGER NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,
    failure_reason VARCHAR(500) NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Orders table
CREATE TABLE orders (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    design_id UUID NOT NULL REFERENCES designs(id) ON DELETE RESTRICT,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    status order_status_enum NOT NULL DEFAULT 'draft',
    quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity > 0),
    base_unit_price INTEGER NOT NULL CHECK (base_unit_price >= 0),
    subtotal INTEGER NOT NULL CHECK (subtotal >= 0),
    discount_total INTEGER NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
    subtotal_after_discount INTEGER NOT NULL CHECK (subtotal_after_discount >= 0),
    shipping_fee INTEGER NOT NULL DEFAULT 0 CHECK (shipping_fee >= 0),
    amount INTEGER NOT NULL CHECK (amount >= 0),
    amount_breakdown JSONB NULL,
    payment_status payment_status_enum NOT NULL DEFAULT 'pending',
    stripe_payment_intent_id VARCHAR(255) NULL,
    stripe_checkout_session_id VARCHAR(255) NULL,
    shipping_address JSONB NOT NULL,
    notes TEXT NULL,
    shipped_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Business logic constraints
    CONSTRAINT chk_order_amounts CHECK (
        subtotal = base_unit_price * quantity AND
        subtotal_after_discount = subtotal - discount_total AND
        amount = subtotal_after_discount + shipping_fee
    ),
    
    -- Shipping address format validation
    CONSTRAINT chk_shipping_address_format CHECK (
        shipping_address ? 'postal_code' AND
        shipping_address ? 'pref_name' AND
        shipping_address ? 'city' AND
        shipping_address ? 'address_line'
    )
);

-- Stripe event log for webhook idempotency
CREATE TABLE stripe_event_log (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    stripe_event_id VARCHAR(255) NOT NULL UNIQUE,
    event_type VARCHAR(100) NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    order_id UUID NULL REFERENCES orders(id) ON DELETE SET NULL,
    raw_data JSONB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Export jobs for CSV generation
CREATE TABLE export_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    type export_job_type_enum NOT NULL,
    status export_job_status_enum NOT NULL DEFAULT 'queued',
    filters JSONB NULL,
    file_url VARCHAR(500) NULL,
    total_records INTEGER NULL,
    processed_records INTEGER NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Admin actions for audit trail
CREATE TABLE admin_actions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id UUID NULL,
    details JSONB NULL,
    ip_address INET NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Rate limit counters (optional for future rate limiting)
CREATE TABLE rate_limit_counters (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    key VARCHAR(255) NOT NULL,
    count INTEGER NOT NULL DEFAULT 1,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(key)
);

-- ===== Indexes =====

-- Users indexes
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_created_at ON users(created_at);

-- Admin users indexes
CREATE INDEX idx_admin_users_email ON admin_users(email);
CREATE INDEX idx_admin_users_role ON admin_users(role);
CREATE INDEX idx_admin_users_is_active ON admin_users(is_active);

-- Templates indexes
CREATE INDEX idx_templates_status ON templates(status);
CREATE INDEX idx_templates_category ON templates(category);
CREATE INDEX idx_templates_created_at ON templates(created_at);

-- Designs indexes
CREATE INDEX idx_designs_user_id ON designs(user_id);
CREATE INDEX idx_designs_template_id ON designs(template_id);
CREATE INDEX idx_designs_original_design_id ON designs(original_design_id);
CREATE INDEX idx_designs_status ON designs(status);
CREATE INDEX idx_designs_params_crc32 ON designs(params_crc32);
CREATE INDEX idx_designs_created_at ON designs(created_at);
CREATE GIN INDEX idx_designs_params ON designs USING gin(params);

-- Render jobs indexes
CREATE INDEX idx_render_jobs_design_id ON render_jobs(design_id);
CREATE INDEX idx_render_jobs_status ON render_jobs(status);
CREATE INDEX idx_render_jobs_idempotency_key ON render_jobs(idempotency_key);
CREATE INDEX idx_render_jobs_created_at ON render_jobs(created_at);

-- Orders indexes
CREATE INDEX idx_orders_user_id ON orders(user_id);
CREATE INDEX idx_orders_design_id ON orders(design_id);
CREATE INDEX idx_orders_order_number ON orders(order_number);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_payment_status ON orders(payment_status);
CREATE INDEX idx_orders_stripe_payment_intent_id ON orders(stripe_payment_intent_id);
CREATE INDEX idx_orders_stripe_checkout_session_id ON orders(stripe_checkout_session_id);
CREATE INDEX idx_orders_created_at ON orders(created_at);
CREATE GIN INDEX idx_orders_shipping_address ON orders USING gin(shipping_address);
CREATE GIN INDEX idx_orders_amount_breakdown ON orders USING gin(amount_breakdown);

-- Stripe event log indexes
CREATE INDEX idx_stripe_event_log_stripe_event_id ON stripe_event_log(stripe_event_id);
CREATE INDEX idx_stripe_event_log_event_type ON stripe_event_log(event_type);
CREATE INDEX idx_stripe_event_log_order_id ON stripe_event_log(order_id);
CREATE INDEX idx_stripe_event_log_created_at ON stripe_event_log(created_at);

-- Export jobs indexes
CREATE INDEX idx_export_jobs_admin_user_id ON export_jobs(admin_user_id);
CREATE INDEX idx_export_jobs_type ON export_jobs(type);
CREATE INDEX idx_export_jobs_status ON export_jobs(status);
CREATE INDEX idx_export_jobs_created_at ON export_jobs(created_at);

-- Admin actions indexes
CREATE INDEX idx_admin_actions_admin_user_id ON admin_actions(admin_user_id);
CREATE INDEX idx_admin_actions_action ON admin_actions(action);
CREATE INDEX idx_admin_actions_target_type ON admin_actions(target_type);
CREATE INDEX idx_admin_actions_target_id ON admin_actions(target_id);
CREATE INDEX idx_admin_actions_created_at ON admin_actions(created_at);

-- Rate limit counters indexes
CREATE INDEX idx_rate_limit_counters_key ON rate_limit_counters(key);
CREATE INDEX idx_rate_limit_counters_expires_at ON rate_limit_counters(expires_at);

-- ===== Postal Code Validation Function =====
CREATE OR REPLACE FUNCTION validate_postal_code(postal_code TEXT) 
RETURNS BOOLEAN AS $$
BEGIN
    -- Japanese postal code format: XXX-XXXX or XXXXXXX
    RETURN postal_code ~ '^[0-9]{3}-?[0-9]{4}$';
END;
$$ LANGUAGE plpgsql;

-- ===== Additional Constraints =====

-- Postal code validation for shipping addresses
ALTER TABLE orders ADD CONSTRAINT chk_postal_code_format 
CHECK (validate_postal_code(shipping_address->>'postal_code'));

-- ===== Comments =====

COMMENT ON TABLE users IS 'End users of the EC site';
COMMENT ON TABLE admin_users IS 'Administrative users with backend access';
COMMENT ON TABLE templates IS 'Design templates available for customization';
COMMENT ON TABLE designs IS 'User-customized designs based on templates';
COMMENT ON TABLE render_jobs IS 'Background jobs for rendering design previews and final images';
COMMENT ON TABLE orders IS 'Customer orders with payment and shipping information';
COMMENT ON TABLE stripe_event_log IS 'Stripe webhook events for payment processing idempotency';
COMMENT ON TABLE export_jobs IS 'Background jobs for CSV data exports';
COMMENT ON TABLE admin_actions IS 'Audit trail for administrative actions';
COMMENT ON TABLE rate_limit_counters IS 'Rate limiting counters for API endpoints';

COMMENT ON COLUMN designs.original_design_id IS 'Reference to original design for reorder functionality';
COMMENT ON COLUMN designs.params_crc32 IS 'CRC32 hash of params for quick comparison';
COMMENT ON COLUMN orders.amount_breakdown IS 'Detailed breakdown of price calculation';
COMMENT ON COLUMN orders.shipping_address IS 'Full shipping address with prefecture codes and kana readings';