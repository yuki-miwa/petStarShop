# OpenAPI Gap Analysis

This document outlines the differences between the current OpenAPI specification and the finalized database schema for petStarShop EC site.

## Missing Endpoints

### 1. Reorder Functionality
- **Endpoint**: `POST /orders/{order_id}/reorder`
- **Description**: Creates a new order based on an existing order
- **Implementation Status**: ❌ Not implemented
- **Priority**: High
- **Database Impact**: Uses `designs.original_design_id` to track reorder relationships

```yaml
/orders/{order_id}/reorder:
  post:
    summary: Create a reorder from existing order
    parameters:
      - name: order_id
        in: path
        required: true
        schema:
          type: string
          format: uuid
    responses:
      201:
        description: New order created for reorder
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Order'
      404:
        description: Original order not found
      403:
        description: Not authorized to reorder this order
```

### 2. CSV Export Jobs
- **Endpoints**: 
  - `POST /admin/exports` - Create export job
  - `GET /admin/exports` - List export jobs
  - `GET /admin/exports/{job_id}` - Get export job status
  - `DELETE /admin/exports/{job_id}` - Cancel export job
- **Description**: Async CSV export functionality for admin users
- **Implementation Status**: ❌ Not implemented
- **Priority**: Medium
- **Database Impact**: Uses `export_jobs` table for job tracking

```yaml
/admin/exports:
  post:
    summary: Create a new export job
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              type:
                type: string
                enum: [orders_csv, users_csv, designs_csv]
              filters:
                type: object
                description: Filter criteria for export
            required: [type]
    responses:
      201:
        description: Export job created
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ExportJob'
  get:
    summary: List export jobs
    parameters:
      - name: status
        in: query
        schema:
          type: string
          enum: [queued, processing, completed, failed]
    responses:
      200:
        description: List of export jobs
        content:
          application/json:
            schema:
              type: array
              items:
                $ref: '#/components/schemas/ExportJob'
```

### 3. Admin Action Audit Trail
- **Endpoints**:
  - `GET /admin/audit-log` - List admin actions
  - `GET /admin/audit-log/{action_id}` - Get specific action details
- **Description**: Audit trail for administrative actions
- **Implementation Status**: ❌ Not implemented
- **Priority**: Medium
- **Database Impact**: Uses `admin_actions` table

## Missing Schema Fields

### 1. User Schema Updates
**Current gaps in User schema:**
- `phone` field (VARCHAR(20), nullable)
- `status` field (ENUM: active, inactive, suspended)
- `last_login_at` timestamp

### 2. Design Schema Updates
**New fields needed:**
- `original_design_id` (UUID, references designs.id) - For reorder tracking
- `version` (INTEGER) - Design version number
- `params_crc32` (INTEGER) - CRC32 hash of params for quick comparison
- `safe_area_warning` (BOOLEAN) - Warning flag for safe area violations
- `status` (ENUM: draft, queued, rendering, ready, failed) - Design processing status
- `preview_image_url` (VARCHAR(500)) - Preview image URL
- `final_image_url` (VARCHAR(500)) - Final rendered image URL

### 3. Order Schema Updates
**Enhanced pricing fields:**
- `base_unit_price` (INTEGER) - Unit price before calculations
- `subtotal` (INTEGER) - base_unit_price * quantity
- `discount_total` (INTEGER) - Total discount amount
- `subtotal_after_discount` (INTEGER) - Subtotal minus discounts
- `shipping_fee` (INTEGER) - Shipping cost
- `amount` (INTEGER) - Final total amount
- `amount_breakdown` (JSONB) - Detailed price breakdown

**Additional order fields:**
- `order_number` (VARCHAR(50), unique) - Human-readable order number
- `stripe_payment_intent_id` (VARCHAR(255)) - Stripe payment intent reference
- `stripe_checkout_session_id` (VARCHAR(255)) - Stripe checkout session reference
- `shipped_at` (TIMESTAMP) - Shipping timestamp
- `delivered_at` (TIMESTAMP) - Delivery timestamp
- `notes` (TEXT) - Order notes

**Enhanced shipping address (JSONB):**
- Required fields: `postal_code`, `pref_name`, `city`, `address_line`
- Optional fields: `pref_code`, `pref_kana`, `city_kana` for address normalization

### 4. Template Schema Updates
**New fields:**
- `base_unit_price` (INTEGER) - Base pricing for template
- `category` (VARCHAR(100)) - Template category
- `safe_area` (JSONB) - Safe area definition
- `render_params` (JSONB) - Default render parameters

## New Schema Components Required

### 1. RenderJob Schema
```yaml
RenderJob:
  type: object
  properties:
    id:
      type: string
      format: uuid
    design_id:
      type: string
      format: uuid
    status:
      type: string
      enum: [pending, processing, completed, failed, cancelled]
    attempt:
      type: integer
      minimum: 1
    idempotency_key:
      type: string
    failure_reason:
      type: string
      nullable: true
    started_at:
      type: string
      format: date-time
      nullable: true
    completed_at:
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
  required: [id, design_id, status, attempt, idempotency_key, created_at, updated_at]
```

### 2. ExportJob Schema
```yaml
ExportJob:
  type: object
  properties:
    id:
      type: string
      format: uuid
    admin_user_id:
      type: string
      format: uuid
    type:
      type: string
      enum: [orders_csv, users_csv, designs_csv]
    status:
      type: string
      enum: [queued, processing, completed, failed]
    filters:
      type: object
      nullable: true
    file_url:
      type: string
      format: uri
      nullable: true
    total_records:
      type: integer
      nullable: true
    processed_records:
      type: integer
    error_message:
      type: string
      nullable: true
    started_at:
      type: string
      format: date-time
      nullable: true
    completed_at:
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
  required: [id, admin_user_id, type, status, processed_records, created_at, updated_at]
```

### 3. AdminUser Schema
```yaml
AdminUser:
  type: object
  properties:
    id:
      type: string
      format: uuid
    email:
      type: string
      format: email
    name:
      type: string
    role:
      type: string
      enum: [super_admin, admin, moderator]
    is_active:
      type: boolean
    last_login_at:
      type: string
      format: date-time
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
  required: [id, email, name, role, is_active, created_at, updated_at]
```

### 4. AdminAction Schema
```yaml
AdminAction:
  type: object
  properties:
    id:
      type: string
      format: uuid
    admin_user_id:
      type: string
      format: uuid
    action:
      type: string
    target_type:
      type: string
    target_id:
      type: string
      format: uuid
      nullable: true
    details:
      type: object
      nullable: true
    ip_address:
      type: string
      nullable: true
    user_agent:
      type: string
      nullable: true
    created_at:
      type: string
      format: date-time
  required: [id, admin_user_id, action, target_type, created_at]
```

## Status Enum Updates

### 1. Design Status
Update design status enum to include render pipeline states:
```yaml
DesignStatus:
  type: string
  enum: [draft, queued, rendering, ready, failed]
```

### 2. Order Status
Expand order status to include fulfillment states:
```yaml
OrderStatus:
  type: string
  enum: [draft, pending_payment, paid, processing, shipped, delivered, cancelled, refunded]
```

### 3. Payment Status
Add payment status enum:
```yaml
PaymentStatus:
  type: string
  enum: [pending, processing, succeeded, failed, cancelled, refunded]
```

## Validation Rules Updates

### 1. Postal Code Validation
Add validation for Japanese postal codes:
- Pattern: `^[0-9]{3}-?[0-9]{4}$`
- Examples: `123-4567`, `1234567`

### 2. Price Calculation Constraints
Add validation for order amount calculations:
- `subtotal = base_unit_price * quantity`
- `subtotal_after_discount = subtotal - discount_total`
- `amount = subtotal_after_discount + shipping_fee`
- All amounts must be non-negative integers (yen in minor units)

### 3. Shipping Address Requirements
Required fields in shipping address JSONB:
- `postal_code` (with postal code validation)
- `pref_name` (prefecture name)
- `city` (city name)
- `address_line` (detailed address)

## API Response Updates

### 1. Error Response Enhancements
Add specific error codes for business logic violations:
```yaml
ErrorResponse:
  type: object
  properties:
    error:
      type: object
      properties:
        code:
          type: string
          enum: [
            validation_error,
            business_rule_violation,
            insufficient_funds,
            design_not_ready,
            order_not_found,
            unauthorized_reorder
          ]
        message:
          type: string
        details:
          type: object
          nullable: true
  required: [error]
```

### 2. Success Response Metadata
Add metadata to list responses:
```yaml
PaginatedResponse:
  type: object
  properties:
    data:
      type: array
    meta:
      type: object
      properties:
        total:
          type: integer
        per_page:
          type: integer
        current_page:
          type: integer
        last_page:
          type: integer
        from:
          type: integer
        to:
          type: integer
  required: [data, meta]
```

## Implementation Priority

### High Priority (Sprint 1)
1. ✅ Database schema implementation
2. 🔄 Update existing API schemas with new fields
3. 🔄 Implement reorder endpoint
4. 🔄 Add design status management

### Medium Priority (Sprint 2)
1. 🔄 Admin export functionality
2. 🔄 Admin audit trail endpoints
3. 🔄 Enhanced error responses
4. 🔄 Validation rule implementation

### Low Priority (Sprint 3)
1. 🔄 Rate limiting endpoints
2. 🔄 Advanced filtering options
3. 🔄 Webhook status endpoints
4. 🔄 Performance monitoring endpoints

## Notes

- ✅ Completed
- 🔄 In Progress / Planned
- ❌ Not Started
- 🚫 Blocked/Cancelled

This gap analysis should be updated as the OpenAPI specification is revised to match the implemented database schema.