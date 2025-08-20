# OpenAPI Gap Notes - petStarShop EC Site

This document outlines the differences between the current OpenAPI specification and the new database schema requirements that need to be implemented.

## Overview
The current OpenAPI specification needs to be updated to reflect the comprehensive database schema and new business requirements. This document serves as a TODO list for updating the API specification.

## 1. New Endpoints to Add

### 1.1 Reorder Functionality
- **POST** `/orders/{id}/reorder`
  - Description: Create a new order by duplicating a previous order's design
  - Parameters:
    - `id` (path): Original order ID (UUID)
  - Response: New order object with duplicated design
  - Business Logic: 
    - Duplicate design with `original_design_id` reference
    - Recalculate pricing with current rates
    - Apply current promotions/discounts
    - Reset design status to 'draft' for modification

### 1.2 CSV Export Jobs (Admin)
- **POST** `/admin/export-jobs`
  - Description: Create async CSV export job
  - Request Body: Export type and filters
  - Response: Export job ID and status
  
- **GET** `/admin/export-jobs`
  - Description: List export jobs with pagination
  - Query Parameters: status, type, admin_user_id
  
- **GET** `/admin/export-jobs/{id}`
  - Description: Get export job details and download URL
  - Response: Job status, file_url when completed

- **GET** `/admin/export-jobs/{id}/download`
  - Description: Download CSV file (redirect to signed URL)

### 1.3 Preview Generation
- **POST** `/designs/{id}/preview-async`
  - Description: Queue async preview generation for complex designs
  - Response: Job status and WebSocket subscription info

- **GET** `/designs/{id}/preview-status`
  - Description: Check preview generation status
  - Response: Current status and preview URL if ready

### 1.4 Admin Actions & Audit
- **GET** `/admin/actions`
  - Description: List admin actions with filtering
  - Query Parameters: admin_user_id, action, target_type, date_range

## 2. Schema Updates for Existing Endpoints

### 2.1 User Model Updates
```yaml
User:
  type: object
  properties:
    # Existing fields...
    
    # New address normalization fields
    pref_code:
      type: string
      maxLength: 2
      description: Prefecture code (JIS X 0401)
    pref_kana:
      type: string
      maxLength: 40
      description: Prefecture name in katakana
    city_kana:
      type: string
      maxLength: 200
      description: City name in katakana
    
    # Enhanced status
    status:
      type: string
      enum: [active, inactive, suspended]
      default: active
```

### 2.2 Design Model Updates
```yaml
Design:
  type: object
  properties:
    # Existing fields...
    
    # New reorder support
    original_design_id:
      type: string
      format: uuid
      nullable: true
      description: Reference to original design for reorders
    
    # Version control
    version:
      type: integer
      minimum: 1
      default: 1
      description: Design version number
    
    # Parameter validation
    params_crc32:
      type: string
      maxLength: 8
      description: CRC32 hash of params for change detection
    
    # Enhanced preview support
    preview_image_url:
      type: string
      format: uri
      nullable: true
    final_image_url:
      type: string
      format: uri
      nullable: true
    
    # Safety warnings
    safe_area_warning:
      type: boolean
      default: false
      description: Whether design has safe area violations
    
    # Enhanced status
    status:
      type: string
      enum: [draft, queued, rendering, ready, failed]
      default: draft
```

### 2.3 Order Model Comprehensive Update
```yaml
Order:
  type: object
  properties:
    # Existing basic fields...
    
    # Enhanced pricing breakdown
    base_unit_price:
      type: integer
      minimum: 0
      description: Base price before customization (yen)
    subtotal:
      type: integer
      minimum: 0
      description: Total before discounts (yen)
    discount_total:
      type: integer
      minimum: 0
      description: Total discount amount (yen)
    subtotal_after_discount:
      type: integer
      minimum: 0
      description: Subtotal after applying discounts (yen)
    shipping_fee:
      type: integer
      minimum: 0
      description: Shipping cost (free if subtotal_after_discount >= 8000)
    amount:
      type: integer
      minimum: 0
      description: Final total amount (subtotal_after_discount + shipping_fee)
    amount_breakdown:
      type: object
      description: Detailed cost breakdown in JSONB format
      example:
        base_price: 2000
        customization_fee: 500
        rush_fee: 0
        discount_breakdown:
          coupon_discount: 200
          member_discount: 100
    
    # Enhanced shipping information with normalization
    shipping_pref_code:
      type: string
      maxLength: 2
    shipping_pref_kana:
      type: string
      maxLength: 40
    shipping_city_kana:
      type: string
      maxLength: 200
    shipping_method:
      type: string
      default: standard
      enum: [standard, express, pickup]
    shipping_info:
      type: object
      description: Additional shipping details in JSONB format
    
    # Enhanced status tracking
    status:
      type: string
      enum: [pending, confirmed, processing, shipped, delivered, cancelled, refunded]
      default: pending
    payment_status:
      type: string
      enum: [pending, processing, paid, failed, refunded, partially_refunded]
      default: pending
    
    # Stripe integration
    stripe_payment_intent_id:
      type: string
      nullable: true
    stripe_session_id:
      type: string
      nullable: true
    
    # Enhanced timestamps
    ordered_at:
      type: string
      format: date-time
    confirmed_at:
      type: string
      format: date-time
      nullable: true
    shipped_at:
      type: string
      format: date-time
      nullable: true
    delivered_at:
      type: string
      format: date-time
      nullable: true
    cancelled_at:
      type: string
      format: date-time
      nullable: true
```

### 2.4 Template Model Updates
```yaml
Template:
  type: object
  properties:
    # Existing fields...
    
    # Enhanced pricing
    base_unit_price:
      type: integer
      minimum: 0
      description: Base price for this template (yen)
    
    # Enhanced metadata
    category:
      type: string
      maxLength: 100
      nullable: true
    thumbnail_url:
      type: string
      format: uri
      nullable: true
    sort_order:
      type: integer
      default: 0
      description: Display order for template listing
    
    # Template configuration
    template_data:
      type: object
      description: Template configuration and constraints in JSONB format
```

## 3. New Response Models

### 3.1 Export Job Model
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
      enum: [orders_csv, designs_csv, users_csv]
    status:
      type: string
      enum: [pending, processing, completed, failed]
    filters:
      type: object
      description: Export filters applied
    file_url:
      type: string
      format: uri
      nullable: true
      description: Download URL when completed
    started_at:
      type: string
      format: date-time
      nullable: true
    completed_at:
      type: string
      format: date-time
      nullable: true
    error_message:
      type: string
      nullable: true
    created_at:
      type: string
      format: date-time
    updated_at:
      type: string
      format: date-time
```

### 3.2 Render Job Model
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
      enum: [pending, processing, completed, failed]
    attempt:
      type: integer
      minimum: 1
      maximum: 3
    started_at:
      type: string
      format: date-time
      nullable: true
    completed_at:
      type: string
      format: date-time
      nullable: true
    failure_reason:
      type: string
      nullable: true
      enum: [template_error, params_invalid, render_timeout, storage_error, system_error]
    result_image_url:
      type: string
      format: uri
      nullable: true
```

### 3.3 Admin Action Model
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
      maxLength: 100
    target_type:
      type: string
      maxLength: 50
      nullable: true
    target_id:
      type: string
      format: uuid
      nullable: true
    details:
      type: object
      description: Action details in JSONB format
    ip_address:
      type: string
      format: ipv4
      nullable: true
    user_agent:
      type: string
      nullable: true
    created_at:
      type: string
      format: date-time
```

## 4. Query Parameter Updates

### 4.1 Enhanced Order Listing
- **GET** `/orders` query parameters:
  - `status`: Filter by order status
  - `payment_status`: Filter by payment status
  - `date_from`, `date_to`: Date range filtering
  - `amount_min`, `amount_max`: Amount range filtering
  - `search`: Search in order_number, shipping_name

### 4.2 Enhanced Design Listing
- **GET** `/designs` query parameters:
  - `status`: Filter by design status
  - `template_id`: Filter by template
  - `has_original`: Filter reordered designs
  - `version`: Filter by version number

## 5. WebSocket Events

### 5.1 Design Status Updates
```yaml
DesignStatusUpdate:
  type: object
  properties:
    event_type:
      type: string
      enum: [design_status_changed, render_progress, render_completed, render_failed]
    design_id:
      type: string
      format: uuid
    status:
      type: string
    preview_image_url:
      type: string
      format: uri
      nullable: true
    progress_percentage:
      type: integer
      minimum: 0
      maximum: 100
      nullable: true
```

## 6. Error Response Updates

### 6.1 Business Logic Errors
- `REORDER_ORIGINAL_NOT_FOUND`: Original order/design not found for reorder
- `DESIGN_RENDER_IN_PROGRESS`: Cannot modify design while rendering
- `EXPORT_JOB_LIMIT_EXCEEDED`: Too many concurrent export jobs
- `SAFE_AREA_VIOLATION`: Design violates safe printing area

### 6.2 Validation Errors
- `AMOUNT_CALCULATION_MISMATCH`: Price calculation validation failed
- `POSTAL_CODE_INVALID_FORMAT`: Japanese postal code format validation
- `PARAMS_CRC32_MISMATCH`: Design parameters integrity check failed

## 7. Rate Limiting Headers

Add rate limiting headers to all endpoints:
- `X-RateLimit-Limit`: Request limit per window
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Window reset timestamp

## 8. Implementation Priority

### High Priority (Next Sprint)
1. Reorder endpoint (`/orders/{id}/reorder`)
2. Enhanced Order model with pricing breakdown
3. Design model updates for reorder support
4. Preview async generation endpoint

### Medium Priority
1. Admin export job endpoints
2. Enhanced filtering for listing endpoints
3. WebSocket event definitions
4. Rate limiting headers

### Low Priority (Future Sprints)
1. Admin action audit endpoints
2. Advanced search capabilities
3. Comprehensive error response updates
4. WebSocket implementation

## Notes
- All monetary values are in Japanese yen (integer, no decimals)
- UUIDs are used for all primary keys
- JSONB fields should have proper schema validation
- Consider API versioning strategy for major changes
- Implement proper pagination for all list endpoints
- Add OpenAPI examples for complex JSONB fields