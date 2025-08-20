-- petStarShop EC Site Initial Database Schema
-- PostgreSQL schema with UUID primary keys, ENUMs, and comprehensive constraints

-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Create ENUM types
CREATE TYPE user_status AS ENUM ('active', 'inactive', 'suspended');
CREATE TYPE design_status AS ENUM ('draft', 'queued', 'rendering', 'ready', 'failed');
CREATE TYPE render_job_status AS ENUM ('pending', 'processing', 'completed', 'failed');
CREATE TYPE order_status AS ENUM ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded');
CREATE TYPE payment_status AS ENUM ('pending', 'processing', 'paid', 'failed', 'refunded', 'partially_refunded');
CREATE TYPE export_job_status AS ENUM ('pending', 'processing', 'completed', 'failed');
CREATE TYPE export_job_type AS ENUM ('orders_csv', 'designs_csv', 'users_csv');

-- Users table
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    postal_code VARCHAR(8),
    pref_code VARCHAR(2),
    pref_name VARCHAR(20),
    pref_kana VARCHAR(40),
    city VARCHAR(100),
    city_kana VARCHAR(200),
    address_line VARCHAR(255),
    phone VARCHAR(20),
    status user_status DEFAULT 'active',
    remember_token VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT check_postal_code_format CHECK (
        postal_code IS NULL OR postal_code ~ '^[0-9]{3}-?[0-9]{4}$'
    )
);

-- Admin users table
CREATE TABLE admin_users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'admin',
    is_active BOOLEAN DEFAULT true,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Templates table (for design templates)
CREATE TABLE templates (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    image_url VARCHAR(512),
    thumbnail_url VARCHAR(512),
    base_unit_price INTEGER NOT NULL DEFAULT 0, -- Price in yen (no decimal)
    template_data JSONB NOT NULL DEFAULT '{}',
    is_active BOOLEAN DEFAULT true,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT check_base_unit_price_positive CHECK (base_unit_price >= 0)
);

-- Designs table (user customized designs)
CREATE TABLE designs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    template_id UUID NOT NULL REFERENCES templates(id) ON DELETE RESTRICT,
    original_design_id UUID REFERENCES designs(id) ON DELETE SET NULL, -- For reorder functionality
    name VARCHAR(255),
    version INTEGER DEFAULT 1,
    params JSONB NOT NULL DEFAULT '{}',
    params_crc32 VARCHAR(8), -- CRC32 hash of params for change detection
    preview_image_url VARCHAR(512),
    final_image_url VARCHAR(512),
    safe_area_warning BOOLEAN DEFAULT false,
    status design_status DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Render jobs table (for async rendering)
CREATE TABLE render_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    design_id UUID NOT NULL REFERENCES designs(id) ON DELETE CASCADE,
    idempotency_key VARCHAR(255) UNIQUE NOT NULL,
    attempt INTEGER DEFAULT 1,
    status render_job_status DEFAULT 'pending',
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    failure_reason VARCHAR(255),
    render_params JSONB,
    result_image_url VARCHAR(512),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT check_attempt_positive CHECK (attempt > 0)
);

-- Orders table
CREATE TABLE orders (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    design_id UUID NOT NULL REFERENCES designs(id) ON DELETE RESTRICT,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    
    -- Pricing breakdown
    base_unit_price INTEGER NOT NULL,
    subtotal INTEGER NOT NULL,
    discount_total INTEGER DEFAULT 0,
    subtotal_after_discount INTEGER NOT NULL,
    shipping_fee INTEGER DEFAULT 0,
    amount INTEGER NOT NULL, -- Final total amount
    amount_breakdown JSONB NOT NULL DEFAULT '{}',
    
    -- Shipping information
    shipping_name VARCHAR(255) NOT NULL,
    shipping_postal_code VARCHAR(8) NOT NULL,
    shipping_pref_code VARCHAR(2),
    shipping_pref_name VARCHAR(20) NOT NULL,
    shipping_pref_kana VARCHAR(40),
    shipping_city VARCHAR(100) NOT NULL,
    shipping_city_kana VARCHAR(200),
    shipping_address_line VARCHAR(255) NOT NULL,
    shipping_phone VARCHAR(20),
    shipping_method VARCHAR(100) DEFAULT 'standard',
    shipping_info JSONB DEFAULT '{}',
    
    -- Order status and payment
    status order_status DEFAULT 'pending',
    payment_status payment_status DEFAULT 'pending',
    payment_method VARCHAR(50),
    stripe_payment_intent_id VARCHAR(255),
    stripe_session_id VARCHAR(255),
    
    -- Timestamps
    ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP,
    shipped_at TIMESTAMP,
    delivered_at TIMESTAMP,
    cancelled_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Constraints for amount consistency
    CONSTRAINT check_amounts_positive CHECK (
        base_unit_price >= 0 AND 
        subtotal >= 0 AND 
        discount_total >= 0 AND
        subtotal_after_discount >= 0 AND
        shipping_fee >= 0 AND
        amount >= 0
    ),
    CONSTRAINT check_amount_calculation CHECK (
        amount = subtotal_after_discount + shipping_fee
    ),
    CONSTRAINT check_subtotal_after_discount CHECK (
        subtotal_after_discount = subtotal - discount_total
    ),
    CONSTRAINT check_shipping_postal_code_format CHECK (
        shipping_postal_code ~ '^[0-9]{3}-?[0-9]{4}$'
    )
);

-- Stripe event log table (for webhook idempotency)
CREATE TABLE stripe_event_log (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    stripe_event_id VARCHAR(255) UNIQUE NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payload JSONB,
    processing_result JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Export jobs table (for async CSV exports)
CREATE TABLE export_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE RESTRICT,
    type export_job_type NOT NULL,
    status export_job_status DEFAULT 'pending',
    filters JSONB DEFAULT '{}',
    file_url VARCHAR(512),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Admin actions table (for audit trail)
CREATE TABLE admin_actions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE RESTRICT,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id UUID,
    details JSONB DEFAULT '{}',
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rate limit counters table (optional, for rate limiting)
CREATE TABLE rate_limit_counters (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    identifier VARCHAR(255) NOT NULL, -- IP address or user ID
    action VARCHAR(100) NOT NULL,
    counter INTEGER DEFAULT 1,
    window_start TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT unique_rate_limit_window UNIQUE (identifier, action, window_start)
);

-- Create indexes for performance

-- Users indexes
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_status ON users(status);

-- Designs indexes
CREATE INDEX idx_designs_user_id ON designs(user_id);
CREATE INDEX idx_designs_template_id ON designs(template_id);
CREATE INDEX idx_designs_status ON designs(status);
CREATE INDEX idx_designs_original_design_id ON designs(original_design_id);
CREATE INDEX idx_designs_created_at ON designs(created_at);

-- Render jobs indexes
CREATE INDEX idx_render_jobs_design_id ON render_jobs(design_id);
CREATE INDEX idx_render_jobs_status ON render_jobs(status);
CREATE INDEX idx_render_jobs_idempotency_key ON render_jobs(idempotency_key);

-- Orders indexes
CREATE INDEX idx_orders_user_id ON orders(user_id);
CREATE INDEX idx_orders_design_id ON orders(design_id);
CREATE INDEX idx_orders_order_number ON orders(order_number);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_payment_status ON orders(payment_status);
CREATE INDEX idx_orders_ordered_at ON orders(ordered_at);
CREATE INDEX idx_orders_stripe_payment_intent_id ON orders(stripe_payment_intent_id);

-- Stripe event log indexes
CREATE INDEX idx_stripe_event_log_stripe_event_id ON stripe_event_log(stripe_event_id);
CREATE INDEX idx_stripe_event_log_event_type ON stripe_event_log(event_type);

-- Export jobs indexes
CREATE INDEX idx_export_jobs_admin_user_id ON export_jobs(admin_user_id);
CREATE INDEX idx_export_jobs_status ON export_jobs(status);
CREATE INDEX idx_export_jobs_type ON export_jobs(type);

-- Admin actions indexes
CREATE INDEX idx_admin_actions_admin_user_id ON admin_actions(admin_user_id);
CREATE INDEX idx_admin_actions_action ON admin_actions(action);
CREATE INDEX idx_admin_actions_target ON admin_actions(target_type, target_id);
CREATE INDEX idx_admin_actions_created_at ON admin_actions(created_at);

-- Rate limit counters indexes
CREATE INDEX idx_rate_limit_identifier_action ON rate_limit_counters(identifier, action);
CREATE INDEX idx_rate_limit_expires_at ON rate_limit_counters(expires_at);

-- GIN indexes for JSONB columns
CREATE INDEX idx_templates_template_data ON templates USING GIN (template_data);
CREATE INDEX idx_designs_params ON designs USING GIN (params);
CREATE INDEX idx_orders_amount_breakdown ON orders USING GIN (amount_breakdown);
CREATE INDEX idx_orders_shipping_info ON orders USING GIN (shipping_info);
CREATE INDEX idx_stripe_event_log_payload ON stripe_event_log USING GIN (payload);
CREATE INDEX idx_export_jobs_filters ON export_jobs USING GIN (filters);
CREATE INDEX idx_admin_actions_details ON admin_actions USING GIN (details);