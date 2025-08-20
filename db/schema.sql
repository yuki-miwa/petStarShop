-- petStarShop EC Site Database Schema
-- PostgreSQL with UUID primary keys, ENUMs, and business constraints

-- Enable required extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Create ENUM types
CREATE TYPE user_status AS ENUM ('active', 'inactive', 'suspended');
CREATE TYPE admin_role AS ENUM ('super_admin', 'admin', 'editor', 'viewer');
CREATE TYPE template_status AS ENUM ('active', 'inactive', 'archived');
CREATE TYPE design_status AS ENUM ('draft', 'queued', 'rendering', 'ready', 'failed');
CREATE TYPE render_job_status AS ENUM ('queued', 'processing', 'completed', 'failed', 'cancelled');
CREATE TYPE order_status AS ENUM ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded');
CREATE TYPE payment_status AS ENUM ('pending', 'completed', 'failed', 'refunded', 'partially_refunded');
CREATE TYPE export_job_status AS ENUM ('queued', 'processing', 'completed', 'failed');
CREATE TYPE export_job_type AS ENUM ('orders_csv', 'users_csv', 'designs_csv');

-- Users table
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    status user_status NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Admin users table
CREATE TABLE admin_users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role admin_role NOT NULL DEFAULT 'viewer',
    is_active BOOLEAN NOT NULL DEFAULT true,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Templates table
CREATE TABLE templates (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100) NOT NULL,
    base_unit_price INTEGER NOT NULL CHECK (base_unit_price >= 0),
    safe_area JSONB NOT NULL,
    preview_image_url VARCHAR(500),
    template_file_url VARCHAR(500),
    status template_status NOT NULL DEFAULT 'active',
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Designs table
CREATE TABLE designs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    template_id UUID NOT NULL REFERENCES templates(id) ON DELETE RESTRICT,
    original_design_id UUID REFERENCES designs(id) ON DELETE SET NULL, -- for reorder functionality
    name VARCHAR(255) NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    params JSONB NOT NULL DEFAULT '{}',
    params_crc32 BIGINT,
    safe_area_warning BOOLEAN NOT NULL DEFAULT false,
    preview_image_url VARCHAR(500),
    status design_status NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Render jobs table
CREATE TABLE render_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    design_id UUID NOT NULL REFERENCES designs(id) ON DELETE CASCADE,
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,
    status render_job_status NOT NULL DEFAULT 'queued',
    attempt INTEGER NOT NULL DEFAULT 1,
    failure_reason VARCHAR(500),
    output_image_url VARCHAR(500),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Orders table
CREATE TABLE orders (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    design_id UUID NOT NULL REFERENCES designs(id) ON DELETE RESTRICT,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    base_unit_price INTEGER NOT NULL CHECK (base_unit_price >= 0),
    subtotal INTEGER NOT NULL CHECK (subtotal >= 0),
    discount_total INTEGER NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
    subtotal_after_discount INTEGER NOT NULL CHECK (subtotal_after_discount >= 0),
    shipping_fee INTEGER NOT NULL DEFAULT 0 CHECK (shipping_fee >= 0),
    amount INTEGER NOT NULL CHECK (amount >= 0),
    amount_breakdown JSONB NOT NULL DEFAULT '{}',
    
    -- Shipping address
    shipping_name VARCHAR(255) NOT NULL,
    shipping_postal_code VARCHAR(10) NOT NULL CHECK (shipping_postal_code ~ '^\d{3}-?\d{4}$'),
    shipping_pref_code VARCHAR(2) NOT NULL,
    shipping_pref_name VARCHAR(20) NOT NULL,
    shipping_pref_kana VARCHAR(20),
    shipping_city VARCHAR(100) NOT NULL,
    shipping_city_kana VARCHAR(100),
    shipping_address_line1 VARCHAR(255) NOT NULL,
    shipping_address_line2 VARCHAR(255),
    shipping_phone VARCHAR(20),
    shipping JSONB,
    
    -- Order status and payment
    status order_status NOT NULL DEFAULT 'pending',
    payment_status payment_status NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(50),
    stripe_payment_intent_id VARCHAR(255),
    
    -- Timestamps
    confirmed_at TIMESTAMP,
    shipped_at TIMESTAMP,
    delivered_at TIMESTAMP,
    cancelled_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Business logic constraints
    CONSTRAINT amount_calculation_check CHECK (
        amount = subtotal_after_discount + shipping_fee
    ),
    CONSTRAINT subtotal_calculation_check CHECK (
        subtotal = base_unit_price * quantity
    ),
    CONSTRAINT discount_check CHECK (
        subtotal_after_discount = subtotal - discount_total
    ),
    CONSTRAINT free_shipping_check CHECK (
        -- Free shipping when subtotal_after_discount >= 8000
        (subtotal_after_discount >= 8000 AND shipping_fee = 0) OR 
        (subtotal_after_discount < 8000)
    )
);

-- Stripe event log for webhook idempotency
CREATE TABLE stripe_event_log (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    stripe_event_id VARCHAR(255) NOT NULL UNIQUE,
    event_type VARCHAR(100) NOT NULL,
    processed BOOLEAN NOT NULL DEFAULT false,
    error_message TEXT,
    event_data JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP
);

-- Export jobs for async CSV exports
CREATE TABLE export_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    type export_job_type NOT NULL,
    status export_job_status NOT NULL DEFAULT 'queued',
    parameters JSONB DEFAULT '{}',
    file_url VARCHAR(500),
    error_message TEXT,
    progress_percentage INTEGER DEFAULT 0 CHECK (progress_percentage >= 0 AND progress_percentage <= 100),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Admin actions for audit trail
CREATE TABLE admin_actions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id UUID,
    details JSONB DEFAULT '{}',
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Rate limit counters (optional for future rate limiting)
CREATE TABLE rate_limit_counters (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    key VARCHAR(255) NOT NULL,
    counter INTEGER NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL,
    window_end TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(key, window_start)
);

-- Indexes for performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_admin_users_email ON admin_users(email);
CREATE INDEX idx_admin_users_role ON admin_users(role);

CREATE INDEX idx_templates_category ON templates(category);
CREATE INDEX idx_templates_status ON templates(status);
CREATE INDEX idx_templates_sort_order ON templates(sort_order);

CREATE INDEX idx_designs_user_id ON designs(user_id);
CREATE INDEX idx_designs_template_id ON designs(template_id);
CREATE INDEX idx_designs_original_design_id ON designs(original_design_id);
CREATE INDEX idx_designs_status ON designs(status);
CREATE INDEX idx_designs_user_template ON designs(user_id, template_id);
-- GIN index for JSONB params search
CREATE INDEX idx_designs_params_gin ON designs USING GIN (params);

CREATE INDEX idx_render_jobs_design_id ON render_jobs(design_id);
CREATE INDEX idx_render_jobs_status ON render_jobs(status);
CREATE INDEX idx_render_jobs_idempotency_key ON render_jobs(idempotency_key);

CREATE INDEX idx_orders_user_id ON orders(user_id);
CREATE INDEX idx_orders_design_id ON orders(design_id);
CREATE INDEX idx_orders_order_number ON orders(order_number);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_payment_status ON orders(payment_status);
CREATE INDEX idx_orders_created_at ON orders(created_at);
CREATE INDEX idx_orders_shipping_postal_code ON orders(shipping_postal_code);
-- GIN index for JSONB amount_breakdown and shipping search
CREATE INDEX idx_orders_amount_breakdown_gin ON orders USING GIN (amount_breakdown);
CREATE INDEX idx_orders_shipping_gin ON orders USING GIN (shipping);

CREATE INDEX idx_stripe_event_log_stripe_event_id ON stripe_event_log(stripe_event_id);
CREATE INDEX idx_stripe_event_log_event_type ON stripe_event_log(event_type);
CREATE INDEX idx_stripe_event_log_processed ON stripe_event_log(processed);

CREATE INDEX idx_export_jobs_admin_user_id ON export_jobs(admin_user_id);
CREATE INDEX idx_export_jobs_type ON export_jobs(type);
CREATE INDEX idx_export_jobs_status ON export_jobs(status);
CREATE INDEX idx_export_jobs_created_at ON export_jobs(created_at);

CREATE INDEX idx_admin_actions_admin_user_id ON admin_actions(admin_user_id);
CREATE INDEX idx_admin_actions_action ON admin_actions(action);
CREATE INDEX idx_admin_actions_target_type_id ON admin_actions(target_type, target_id);
CREATE INDEX idx_admin_actions_created_at ON admin_actions(created_at);

CREATE INDEX idx_rate_limit_counters_key ON rate_limit_counters(key);
CREATE INDEX idx_rate_limit_counters_window ON rate_limit_counters(window_start, window_end);

-- Triggers for updated_at timestamps
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_admin_users_updated_at BEFORE UPDATE ON admin_users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_templates_updated_at BEFORE UPDATE ON templates
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_designs_updated_at BEFORE UPDATE ON designs
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_render_jobs_updated_at BEFORE UPDATE ON render_jobs
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_orders_updated_at BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_export_jobs_updated_at BEFORE UPDATE ON export_jobs
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_rate_limit_counters_updated_at BEFORE UPDATE ON rate_limit_counters
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();